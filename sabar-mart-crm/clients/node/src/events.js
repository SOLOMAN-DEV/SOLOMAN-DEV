'use strict';

const crypto = require('node:crypto');

/**
 * Builders for storefront events. Each returns { id, type, data } ready for the outbox or
 * CrmClient#sendEvents. The id is the event's idempotency key:
 *  - one-off facts (order placed/shipped/..., customer registered) get a deterministic id,
 *    so emitting the same fact twice is harmless;
 *  - product updates get an id derived from their content (a changed product is a new event);
 *  - repeatable activity (browsing, carts, clicks) gets a random id, fixed once enqueued.
 *
 * Money must be a string with up to 2 decimals ("1499.00") or a number; numbers are
 * converted with toFixed(2). Prices include GST.
 */

function money(value, field = 'amount') {
  if (typeof value === 'number') {
    if (!Number.isFinite(value) || value <= 0) throw new RangeError(`${field} must be a positive amount`);
    return value.toFixed(2);
  }
  if (typeof value === 'string' && /^\d+(\.\d{1,2})?$/.test(value) && Number(value) > 0) return value;
  throw new RangeError(`${field} must be a positive amount like "1499.00"`);
}

function required(obj, ...names) {
  for (const n of names) {
    if (obj[n] === undefined || obj[n] === null || obj[n] === '') throw new TypeError(`${n} is required`);
  }
}

const randomId = (type) => `${type}:${crypto.randomUUID()}`;

/** marketingConsent: true only if the customer actively opted in (DPDP). */
function customerRegistered({ customerId, name, email, phone, marketingConsent = false }) {
  required({ customerId, name, email, phone }, 'customerId', 'name', 'email', 'phone');
  return {
    id: `customer.registered:${customerId}`,
    type: 'customer.registered',
    data: { customer_id: customerId, name, email, phone, marketing_consent: Boolean(marketingConsent) },
  };
}

/** Opt-in or opt-out of marketing; source records where it happened (e.g. "unsubscribe_link"). */
function customerConsentUpdated({ customerId, marketing, source }) {
  required({ customerId, source }, 'customerId', 'source');
  if (typeof marketing !== 'boolean') throw new TypeError('marketing must be true or false');
  return {
    id: randomId('customer.consent_updated'),
    type: 'customer.consent_updated',
    data: { customer_id: customerId, marketing, source },
  };
}

function customerBrowsed({ customerId, category }) {
  required({ customerId, category }, 'customerId', 'category');
  return { id: randomId('customer.browsed'), type: 'customer.browsed', data: { customer_id: customerId, category } };
}

function cartItemAdded({ customerId, productId, price }) {
  required({ customerId, productId }, 'customerId', 'productId');
  return {
    id: randomId('cart.item_added'),
    type: 'cart.item_added',
    data: { customer_id: customerId, product_id: productId, price: money(price, 'price') },
  };
}

function cartItemRemoved({ customerId, productId }) {
  required({ customerId, productId }, 'customerId', 'productId');
  return { id: randomId('cart.item_removed'), type: 'cart.item_removed', data: { customer_id: customerId, product_id: productId } };
}

/** gstRate is the GST included in the price, e.g. "0.18" for 18%. */
function productUpserted({ productId, vendorId, name, category, gstRate }) {
  required({ productId, vendorId, name, category, gstRate }, 'productId', 'vendorId', 'name', 'category', 'gstRate');
  const data = { product_id: productId, vendor_id: vendorId, name, category, gst_rate: String(gstRate) };
  const version = crypto.createHash('sha256').update(JSON.stringify(data)).digest('hex').slice(0, 16);
  return { id: `product.upserted:${productId}:${version}`, type: 'product.upserted', data };
}

/** amount: what the customer paid for the order line, GST included. */
function orderPlaced({ orderId, customerId, productId, amount, referralCode }) {
  required({ orderId, customerId, productId }, 'orderId', 'customerId', 'productId');
  const data = { order_id: orderId, customer_id: customerId, product_id: productId, amount: money(amount) };
  if (referralCode) data.referral_code = referralCode;
  return { id: `order.placed:${orderId}`, type: 'order.placed', data };
}

function orderShipped({ orderId, late = false }) {
  required({ orderId }, 'orderId');
  return { id: `order.shipped:${orderId}`, type: 'order.shipped', data: { order_id: orderId, late: Boolean(late) } };
}

const orderTransition = (type) => ({ orderId }) => {
  required({ orderId }, 'orderId');
  return { id: `${type}:${orderId}`, type, data: { order_id: orderId } };
};

function referralClicked({ referralCode, productId, visitorFingerprint }) {
  required({ referralCode, productId, visitorFingerprint }, 'referralCode', 'productId', 'visitorFingerprint');
  return {
    id: randomId('referral.clicked'),
    type: 'referral.clicked',
    data: { referral_code: referralCode, product_id: productId, visitor_fingerprint: visitorFingerprint },
  };
}

/** reviewId: your storefront's id for the review, so the same review is never counted twice. */
function vendorReviewed({ reviewId, vendorId, score }) {
  required({ reviewId, vendorId, score }, 'reviewId', 'vendorId', 'score');
  return { id: `vendor.reviewed:${reviewId}`, type: 'vendor.reviewed', data: { vendor_id: vendorId, score: String(score) } };
}

function ticketCreated({ ticketId, customerId, subject, body, orderId }) {
  required({ ticketId, customerId, subject, body }, 'ticketId', 'customerId', 'subject', 'body');
  const data = { ticket_id: ticketId, customer_id: customerId, subject, body };
  if (orderId) data.order_id = orderId;
  return { id: `ticket.created:${ticketId}`, type: 'ticket.created', data };
}

module.exports = {
  money,
  customerRegistered,
  customerConsentUpdated,
  customerBrowsed,
  cartItemAdded,
  cartItemRemoved,
  productUpserted,
  orderPlaced,
  orderShipped,
  orderDelivered: orderTransition('order.delivered'),
  orderReturned: orderTransition('order.returned'),
  orderCancelled: orderTransition('order.cancelled'),
  referralClicked,
  vendorReviewed,
  ticketCreated,
};
