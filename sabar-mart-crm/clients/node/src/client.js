'use strict';

const crypto = require('node:crypto');

const MAX_BATCH = 100;
const RETRYABLE_STATUS = new Set([408, 425, 429, 500, 502, 503, 504]);

class CrmError extends Error {
  /**
   * @param {string} message
   * @param {{status?: number, detail?: unknown, retryable: boolean, cause?: unknown}} info
   */
  constructor(message, { status, detail, retryable, cause }) {
    super(message, cause ? { cause } : undefined);
    this.name = 'CrmError';
    this.status = status;
    this.detail = detail;
    this.retryable = retryable;
  }
}

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

/**
 * Thin client for the Sabar Mart CRM REST API.
 *
 * Every write carries an Idempotency-Key (generated if you do not pass one) that is reused
 * across automatic retries, so a retry after a timeout can never apply a change twice.
 */
class CrmClient {
  constructor({
    baseUrl,
    apiKey,
    timeoutMs = 5000,
    maxRetries = 3,
    retryBaseMs = 300,
    maxRetryDelayMs = 30000,
    fetch: fetchImpl = globalThis.fetch,
  } = {}) {
    if (!baseUrl) throw new TypeError('baseUrl is required');
    if (!apiKey) throw new TypeError('apiKey is required');
    if (typeof fetchImpl !== 'function') throw new TypeError('fetch is not available; use Node.js 18 or newer');
    this.baseUrl = baseUrl.replace(/\/+$/, '');
    this.apiKey = apiKey;
    this.timeoutMs = timeoutMs;
    this.maxRetries = maxRetries;
    this.retryBaseMs = retryBaseMs;
    this.maxRetryDelayMs = maxRetryDelayMs;
    this.fetch = fetchImpl;
  }

  /**
   * @param {string} method
   * @param {string} path
   * @param {{body?: unknown, query?: Record<string, string>, idempotencyKey?: string}} [options]
   */
  async request(method, path, { body, query, idempotencyKey } = {}) {
    const url = new URL(this.baseUrl + path);
    for (const [k, v] of Object.entries(query || {})) {
      if (v !== undefined && v !== null) url.searchParams.set(k, String(v));
    }
    const headers = { Authorization: `Bearer ${this.apiKey}`, Accept: 'application/json' };
    if (body !== undefined) headers['Content-Type'] = 'application/json';
    if (method !== 'GET') headers['Idempotency-Key'] = idempotencyKey || crypto.randomUUID();

    let lastError;
    for (let attempt = 0; attempt <= this.maxRetries; attempt += 1) {
      let retryAfterMs;
      try {
        const res = await this.fetch(url, {
          method,
          headers,
          body: body === undefined ? undefined : JSON.stringify(body),
          signal: AbortSignal.timeout(this.timeoutMs),
        });
        const text = await res.text();
        const payload = text ? safeJson(text) : null;
        if (res.ok) return payload;
        const detail = payload && typeof payload === 'object' && 'detail' in payload ? payload.detail : payload;
        const retryable = RETRYABLE_STATUS.has(res.status);
        lastError = new CrmError(`CRM ${method} ${path} failed with ${res.status}: ${describe(detail)}`, {
          status: res.status, detail, retryable,
        });
        if (!retryable) throw lastError;
        retryAfterMs = parseRetryAfter(res.headers.get('retry-after'));
      } catch (err) {
        if (err instanceof CrmError && !err.retryable) throw err;
        if (!(err instanceof CrmError)) {
          // Network failure or timeout: the request may or may not have reached the CRM,
          // which is exactly what the idempotency key makes safe to retry.
          lastError = new CrmError(`CRM ${method} ${path} did not complete: ${err.message}`, {
            retryable: true, cause: err,
          });
        }
      }
      if (attempt < this.maxRetries) await sleep(retryAfterMs ?? this.backoff(attempt));
    }
    throw lastError;
  }

  backoff(attempt) {
    const base = this.retryBaseMs * 2 ** attempt;
    return Math.min(this.maxRetryDelayMs, base + Math.floor(Math.random() * base));
  }

  /**
   * Send events to POST /events in order, in chunks of up to 100.
   * Resolves with the per-event results; stops after a chunk that hit a retryable error so that
   * later events are not applied before earlier ones.
   */
  async sendEvents(events) {
    const results = [];
    for (let i = 0; i < events.length; i += MAX_BATCH) {
      const chunk = events.slice(i, i + MAX_BATCH);
      // Each event's id is its idempotency key on the server, so re-sending a chunk is safe.
      const res = await this.request('POST', '/events', { body: { events: chunk } });
      results.push(...res.results);
      if (res.results.some((r) => r.retryable)) {
        for (const e of events.slice(i + MAX_BATCH)) results.push({ id: e.id, status: 'not_processed', retryable: true });
        break;
      }
    }
    return results;
  }

  // --- Convenience wrappers for direct calls (prefer the outbox for storefront events) -----

  health() { return this.request('GET', '/health'); }

  me() { return this.request('GET', '/me'); }

  customerProfile(customerId) { return this.request('GET', `/customers/${enc(customerId)}`); }

  requestRefund(orderId, { amount, reason }, idempotencyKey) {
    return this.request('POST', `/orders/${enc(orderId)}/refunds`, {
      body: { amount: String(amount), reason }, idempotencyKey,
    });
  }

  submitTicket(ticket, idempotencyKey) {
    return this.request('POST', '/tickets', { body: ticket, idempotencyKey: idempotencyKey || `ticket:${ticket.ticket_id}` });
  }
}

function enc(s) { return encodeURIComponent(String(s)); }

function safeJson(text) {
  try { return JSON.parse(text); } catch { return text; }
}

function describe(detail) {
  if (typeof detail === 'string') return detail;
  try { return JSON.stringify(detail); } catch { return String(detail); }
}

function parseRetryAfter(value) {
  if (!value) return undefined;
  const seconds = Number(value);
  if (Number.isFinite(seconds) && seconds >= 0) return Math.min(seconds * 1000, 30000);
  return undefined;
}

module.exports = { CrmClient, CrmError };
