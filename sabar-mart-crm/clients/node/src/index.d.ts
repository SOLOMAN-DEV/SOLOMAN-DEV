export type Money = string | number;

export interface CrmEvent {
  id: string;
  type: string;
  data: Record<string, unknown>;
}

export interface EventResult {
  id: string;
  status: 'ok' | 'replayed' | 'error' | 'not_processed';
  result?: unknown;
  http_status?: number;
  detail?: unknown;
  retryable?: boolean;
}

export interface CrmClientOptions {
  baseUrl: string;
  apiKey: string;
  timeoutMs?: number;
  maxRetries?: number;
  retryBaseMs?: number;
  maxRetryDelayMs?: number;
  fetch?: typeof fetch;
}

export class CrmError extends Error {
  status?: number;
  detail?: unknown;
  retryable: boolean;
}

export class CrmClient {
  constructor(options: CrmClientOptions);
  request<T = unknown>(
    method: string,
    path: string,
    options?: { body?: unknown; query?: Record<string, string | number | undefined>; idempotencyKey?: string },
  ): Promise<T>;
  sendEvents(events: CrmEvent[]): Promise<EventResult[]>;
  health(): Promise<{ status: string }>;
  me(): Promise<{ user: string; role: string; permissions: string[] }>;
  customerProfile(customerId: string): Promise<Record<string, unknown>>;
  requestRefund(orderId: string, refund: { amount: Money; reason: string }, idempotencyKey?: string): Promise<Record<string, unknown>>;
  submitTicket(ticket: { ticket_id: string; customer_id: string; subject: string; body: string; order_id?: string }, idempotencyKey?: string): Promise<Record<string, unknown>>;
}

export const events: {
  money(value: Money, field?: string): string;
  customerRegistered(e: { customerId: string; name: string; email: string; phone: string }): CrmEvent;
  customerBrowsed(e: { customerId: string; category: string }): CrmEvent;
  cartItemAdded(e: { customerId: string; productId: string; price: Money }): CrmEvent;
  cartItemRemoved(e: { customerId: string; productId: string }): CrmEvent;
  /** gstRate: GST included in the price, e.g. "0.18". */
  productUpserted(e: { productId: string; vendorId: string; name: string; category: string; gstRate: string | number }): CrmEvent;
  /** amount: paid by the customer, GST included. */
  orderPlaced(e: { orderId: string; customerId: string; productId: string; amount: Money; referralCode?: string }): CrmEvent;
  orderShipped(e: { orderId: string; late?: boolean }): CrmEvent;
  orderDelivered(e: { orderId: string }): CrmEvent;
  orderReturned(e: { orderId: string }): CrmEvent;
  orderCancelled(e: { orderId: string }): CrmEvent;
  referralClicked(e: { referralCode: string; productId: string; visitorFingerprint: string }): CrmEvent;
  vendorReviewed(e: { reviewId: string; vendorId: string; score: number | string }): CrmEvent;
  ticketCreated(e: { ticketId: string; customerId: string; subject: string; body: string; orderId?: string }): CrmEvent;
};

export interface OutboxStore {
  enqueue(events: CrmEvent[], conn?: unknown): Promise<void>;
  withLock<T>(fn: () => Promise<T>): Promise<T | null>;
  nextBatch(limit: number): Promise<Array<CrmEvent & { attempts: number }>>;
  markSent(ids: string[]): Promise<void>;
  markRetry(ids: string[], error: string, delayMs: number): Promise<void>;
  markDead(id: string, error: string): Promise<void>;
  stats(): Promise<{ pending: number; sent: number; dead: number }>;
  purgeSent(olderThanMs: number): Promise<number>;
}

export class MemoryOutboxStore implements OutboxStore {
  enqueue(events: CrmEvent[]): Promise<void>;
  withLock<T>(fn: () => Promise<T>): Promise<T | null>;
  nextBatch(limit: number): Promise<Array<CrmEvent & { attempts: number }>>;
  markSent(ids: string[]): Promise<void>;
  markRetry(ids: string[], error: string, delayMs: number): Promise<void>;
  markDead(id: string, error: string): Promise<void>;
  stats(): Promise<{ pending: number; sent: number; dead: number }>;
  purgeSent(olderThanMs: number): Promise<number>;
}

export class MysqlOutboxStore implements OutboxStore {
  /** pool: a mysql2/promise pool. */
  constructor(pool: unknown, options?: { table?: string; lockName?: string });
  /** conn: the connection of your open transaction, so events commit with your change. */
  enqueue(events: CrmEvent[], conn?: unknown): Promise<void>;
  withLock<T>(fn: () => Promise<T>): Promise<T | null>;
  nextBatch(limit: number): Promise<Array<CrmEvent & { attempts: number }>>;
  markSent(ids: string[]): Promise<void>;
  markRetry(ids: string[], error: string, delayMs: number): Promise<void>;
  markDead(id: string, error: string): Promise<void>;
  stats(): Promise<{ pending: number; sent: number; dead: number }>;
  purgeSent(olderThanMs: number): Promise<number>;
}

export class OutboxWorker {
  constructor(options: {
    client: CrmClient;
    store: OutboxStore;
    batchSize?: number;
    intervalMs?: number;
    maxAttempts?: number;
    maxDelayMs?: number;
    logger?: Pick<Console, 'warn' | 'error'>;
    onDeadLetter?: (dead: { id: string; error: string }) => void | Promise<void>;
  });
  runOnce(): Promise<number | null>;
  start(): this;
  stop(): void;
}

export const DEFAULT_TABLE: string;
