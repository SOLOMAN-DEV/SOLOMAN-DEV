'use strict';

const os = require('node:os');

/**
 * Transactional outbox: the storefront writes CRM events into its own database in the same
 * transaction as the business change (e.g. the order row), and OutboxWorker delivers them to
 * the CRM in the background, in order, at least once. Combined with the CRM's per-event
 * idempotency this gives exactly-once processing, and a CRM outage never blocks checkout.
 *
 * Ordering: events are delivered strictly in enqueue order. If the oldest pending event has
 * to wait for a retry, everything behind it waits too (an "order.shipped" must never overtake
 * its "order.placed"). Events the CRM rejects permanently are moved to status "dead" so they
 * stop blocking the queue; inspect them with stats() / your SQL client and re-enqueue if needed.
 */

const DEFAULT_TABLE = 'storefront_crm_outbox';

// --- Stores -----------------------------------------------------------------

/** In-process store for development and tests. Not durable. */
class MemoryOutboxStore {
  constructor() {
    this.rows = [];
    this.seq = 0;
    this.locked = false;
  }

  async enqueue(events) {
    for (const e of events) {
      if (this.rows.some((r) => r.id === e.id)) continue; // same id = same event
      this.rows.push({ seq: ++this.seq, id: e.id, type: e.type, data: e.data, status: 'pending',
        attempts: 0, nextAttemptAt: 0, lastError: null, sentAt: null });
    }
  }

  async withLock(fn) {
    if (this.locked) return null;
    this.locked = true;
    try { return await fn(); } finally { this.locked = false; }
  }

  async nextBatch(limit, now = Date.now()) {
    const pending = this.rows.filter((r) => r.status === 'pending').sort((a, b) => a.seq - b.seq);
    if (!pending.length || pending[0].nextAttemptAt > now) return [];
    return pending.slice(0, limit).map(({ id, type, data, attempts }) => ({ id, type, data, attempts }));
  }

  async markSent(ids) {
    for (const r of this.rows) if (ids.includes(r.id)) Object.assign(r, { status: 'sent', sentAt: Date.now() });
  }

  async markRetry(ids, error, delayMs) {
    for (const r of this.rows) {
      if (ids.includes(r.id)) Object.assign(r, { attempts: r.attempts + 1, lastError: error, nextAttemptAt: Date.now() + delayMs });
    }
  }

  async markDead(id, error) {
    const r = this.rows.find((x) => x.id === id);
    if (r) Object.assign(r, { status: 'dead', attempts: r.attempts + 1, lastError: error });
  }

  async stats() {
    const out = { pending: 0, sent: 0, dead: 0 };
    for (const r of this.rows) out[r.status] += 1;
    return out;
  }

  async purgeSent(olderThanMs) {
    const cutoff = Date.now() - olderThanMs;
    const before = this.rows.length;
    this.rows = this.rows.filter((r) => !(r.status === 'sent' && r.sentAt < cutoff));
    return before - this.rows.length;
  }
}

/**
 * MySQL/MariaDB store using a mysql2/promise pool. Create the table with sql/outbox.mysql.sql.
 * Works on MariaDB 10.3+ and MySQL 5.7+ (no SKIP LOCKED needed: a named lock ensures a
 * single active sender across all Node processes).
 */
class MysqlOutboxStore {
  constructor(pool, { table = DEFAULT_TABLE, lockName } = {}) {
    if (!/^[A-Za-z0-9_]+$/.test(table)) throw new TypeError('invalid table name');
    this.pool = pool;
    this.table = table;
    this.lockName = lockName || `${table}_sender`;
    this.lockConn = null;
  }

  /**
   * Insert events. Pass the connection of your open transaction as `conn` so the events are
   * committed (or rolled back) together with the business change.
   */
  async enqueue(events, conn = this.pool) {
    if (!events.length) return;
    const rows = events.map((e) => [e.id, e.type, JSON.stringify(e.data)]);
    await conn.query(`INSERT IGNORE INTO \`${this.table}\` (event_id, type, data) VALUES ?`, [rows]);
  }

  async withLock(fn) {
    const conn = await this.pool.getConnection();
    try {
      const [[{ got }]] = await conn.query('SELECT GET_LOCK(?, 0) AS got', [this.lockName]);
      if (got !== 1) return null; // another process is sending
      try {
        return await fn();
      } finally {
        await conn.query('SELECT RELEASE_LOCK(?)', [this.lockName]);
      }
    } finally {
      conn.release();
    }
  }

  async nextBatch(limit) {
    const [rows] = await this.pool.query(
      `SELECT event_id, type, data, attempts, next_attempt_at <= CURRENT_TIMESTAMP(3) AS ready
         FROM \`${this.table}\` WHERE status = 'pending' ORDER BY seq LIMIT ?`, [limit]);
    if (!rows.length || !Number(rows[0].ready)) return [];
    return rows.map((r) => ({
      id: r.event_id, type: r.type, attempts: r.attempts,
      data: typeof r.data === 'string' ? JSON.parse(r.data) : r.data,
    }));
  }

  async markSent(ids) {
    if (!ids.length) return;
    await this.pool.query(
      `UPDATE \`${this.table}\` SET status = 'sent', sent_at = CURRENT_TIMESTAMP(3), last_error = NULL
        WHERE event_id IN (?)`, [ids]);
  }

  async markRetry(ids, error, delayMs) {
    if (!ids.length) return;
    await this.pool.query(
      `UPDATE \`${this.table}\` SET attempts = attempts + 1, last_error = ?,
              next_attempt_at = CURRENT_TIMESTAMP(3) + INTERVAL ? MICROSECOND
        WHERE event_id IN (?)`, [String(error).slice(0, 2000), Math.round(delayMs * 1000), ids]);
  }

  async markDead(id, error) {
    await this.pool.query(
      `UPDATE \`${this.table}\` SET status = 'dead', attempts = attempts + 1, last_error = ? WHERE event_id = ?`,
      [String(error).slice(0, 2000), id]);
  }

  async stats() {
    const [rows] = await this.pool.query(`SELECT status, COUNT(*) AS n FROM \`${this.table}\` GROUP BY status`);
    const out = { pending: 0, sent: 0, dead: 0 };
    for (const r of rows) out[r.status] = Number(r.n);
    return out;
  }

  async purgeSent(olderThanMs) {
    const [res] = await this.pool.query(
      `DELETE FROM \`${this.table}\` WHERE status = 'sent'
          AND sent_at < CURRENT_TIMESTAMP(3) - INTERVAL ? MICROSECOND`, [Math.round(olderThanMs * 1000)]);
    return res.affectedRows;
  }
}

// --- Worker -----------------------------------------------------------------

class OutboxWorker {
  constructor({
    client,
    store,
    batchSize = 50,
    intervalMs = 2000,
    maxAttempts = 15,
    maxDelayMs = 15 * 60 * 1000,
    logger = console,
    onDeadLetter,
  }) {
    if (!client || !store) throw new TypeError('client and store are required');
    Object.assign(this, { client, store, batchSize, intervalMs, maxAttempts, maxDelayMs, logger, onDeadLetter });
    this.timer = null;
    this.running = false;
    this.workerId = `${os.hostname()}:${process.pid}`;
  }

  delayFor(attempts) {
    return Math.min(this.maxDelayMs, 1000 * 2 ** attempts);
  }

  /** Deliver one batch. Returns the number of events handled, or null if another process holds the lock. */
  async runOnce() {
    return this.store.withLock(async () => {
      const batch = await this.store.nextBatch(this.batchSize);
      if (!batch.length) return 0;
      const attemptsById = new Map(batch.map((e) => [e.id, e.attempts]));
      let results;
      try {
        results = await this.client.sendEvents(batch.map(({ id, type, data }) => ({ id, type, data })));
      } catch (err) {
        // Whole request failed (CRM down, bad API key, ...): never dead-letter on this, just back off.
        const level = err.retryable ? 'warn' : 'error';
        this.logger[level](`[crm-outbox] delivery failed, will retry: ${err.message}`);
        await this.store.markRetry(batch.map((e) => e.id), err.message, this.delayFor(batch[0].attempts));
        return 0;
      }

      const sent = [];
      const retry = [];
      for (const r of results) {
        if (r.status === 'ok' || r.status === 'replayed') {
          sent.push(r.id);
        } else if (r.status === 'error' && !r.retryable) {
          const error = `${r.http_status}: ${typeof r.detail === 'string' ? r.detail : JSON.stringify(r.detail)}`;
          await this.store.markDead(r.id, error);
          this.logger.error(`[crm-outbox] event ${r.id} rejected permanently (${error})`);
          if (this.onDeadLetter) await this.onDeadLetter({ id: r.id, error });
        } else {
          retry.push(r);
        }
      }
      await this.store.markSent(sent);
      for (const r of retry) {
        const attempts = (attemptsById.get(r.id) || 0) + 1;
        const error = r.detail ? String(r.detail) : r.status;
        if (attempts >= this.maxAttempts && r.status === 'error') {
          await this.store.markDead(r.id, `gave up after ${attempts} attempts: ${error}`);
          if (this.onDeadLetter) await this.onDeadLetter({ id: r.id, error });
        } else {
          await this.store.markRetry([r.id], error, this.delayFor(attempts - 1));
        }
      }
      return results.length;
    });
  }

  /** Poll continuously until stop(). Drains quickly when there is a backlog. */
  start() {
    if (this.running) return this;
    this.running = true;
    const tick = async () => {
      let handled = 0;
      try {
        handled = await this.runOnce();
      } catch (err) {
        this.logger.error(`[crm-outbox] worker error: ${err.stack || err}`);
      }
      if (this.running) this.timer = setTimeout(tick, handled ? 0 : this.intervalMs);
    };
    this.timer = setTimeout(tick, 0);
    return this;
  }

  stop() {
    this.running = false;
    if (this.timer) clearTimeout(this.timer);
    this.timer = null;
  }
}

module.exports = { MemoryOutboxStore, MysqlOutboxStore, OutboxWorker, DEFAULT_TABLE };
