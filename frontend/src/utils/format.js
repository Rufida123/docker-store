// Status values accepted by PATCH /orders/:id/status.
export const ORDER_STATUSES = ['pending', 'confirmed', 'shipped', 'delivered', 'cancelled'];

// The happy path of an order.
export const STATUS_FLOW = ['pending', 'confirmed', 'shipped', 'delivered'];

// Allowed next statuses (the server enforces this too: skipping a step or changing
// a delivered/cancelled order returns 409 INVALID_STATUS_TRANSITION).
// If your backend allows different cancellations, adjust this map only.
export const TRANSITIONS = {
  pending: ['confirmed', 'cancelled'],
  confirmed: ['shipped', 'cancelled'],
  shipped: ['delivered', 'cancelled'],
  delivered: [],
  cancelled: [],
};

export const STATUS_COLORS = {
  pending: 'warning',
  confirmed: 'info',
  shipped: 'secondary',
  delivered: 'success',
  cancelled: 'error',
};

export const UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
// Prices have at most 2 decimals ("12.345" is rejected by the API).
export const PRICE_RE = /^\d+(\.\d{1,2})?$/;

export const capitalize = (s) => (s ? s.charAt(0).toUpperCase() + s.slice(1) : '');

export function formatMoney(value) {
  const n = Number(value);
  if (value === undefined || value === null || value === '' || Number.isNaN(n)) return '—';
  return new Intl.NumberFormat(undefined, { style: 'currency', currency: 'USD' }).format(n);
}

// price x quantity without floating point drift (works in cents).
export const lineTotal = (price, quantity) => (Math.round(Number(price) * 100) * Number(quantity)) / 100;

export function formatDate(value) {
  if (!value) return '—';
  const d = new Date(value);
  if (Number.isNaN(d.getTime())) return '—';
  return d.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
}

export const shortId = (id) => (id ? String(id).slice(0, 8) : '—');

// Accepts an array or an object like { data|items|orders|results, pagination: { total, total_pages } }.
export function normalizeList(res, keys = ['data', 'items', 'orders', 'results']) {
  if (Array.isArray(res)) return { items: res, total: res.length, page: 1, totalPages: 1 };
  const items = keys.map((k) => res?.[k]).find(Array.isArray) || [];
  const pg = res?.pagination || res?.meta || {};
  const total = Number(res?.total ?? res?.count ?? pg.total ?? items.length);
  return {
    items,
    total,
    page: Number(pg.page) || 1,
    totalPages: Number(pg.total_pages ?? pg.totalPages) || 1,
  };
}

export function initials(user) {
  const src = user?.name || user?.email || '?';
  return src.slice(0, 2).toUpperCase();
}

export const userLabel = (u) => u?.name || u?.email || shortId(u?.id);

export function stockInfo(stock) {
  const n = Number(stock);
  if (!(n > 0)) return { label: 'Out of stock', color: 'error' };
  if (n <= 5) return { label: `Only ${n} left`, color: 'warning' };
  return { label: `${n} in stock`, color: 'success' };
}

// Timestamp of a status-history entry (tolerates different field names).
export const historyTime = (h) => h?.created_at || h?.changed_at || h?.createdAt || h?.at;
