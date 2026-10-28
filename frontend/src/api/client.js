// Thin fetch wrapper around the backend API.
// Base URL: "/api/v1" (the versioned backend API, proxied in dev and Docker).
const BASE = import.meta.env.VITE_API_BASE || '/api/v1';
// Product images are served by the backend at /uploads/... (outside /api/v1).
const ASSET_BASE = import.meta.env.VITE_ASSET_BASE || '';
export const STORAGE_KEY = 'orders.auth';

const TIMEOUT_MS = 20_000;
const UPLOAD_TIMEOUT_MS = 60_000;

export function getStoredAuth() {
  try {
    return JSON.parse(localStorage.getItem(STORAGE_KEY));
  } catch {
    return null;
  }
}

export function storeAuth(auth) {
  try {
    if (auth) localStorage.setItem(STORAGE_KEY, JSON.stringify(auth));
    else localStorage.removeItem(STORAGE_KEY);
  } catch {
    /* storage unavailable (private mode / quota): the session just won't persist */
  }
}

export const assetUrl = (url) => (!url ? '' : /^https?:\/\//i.test(url) ? url : `${ASSET_BASE}${url}`);

export class ApiError extends Error {
  constructor(status, code, message, details, retryAfter) {
    super(message);
    this.status = status;
    this.code = code;
    this.details = details;
    this.retryAfter = retryAfter; // seconds, from the Retry-After header (429)
  }
}

function buildUrl(path, params) {
  const qs = new URLSearchParams();
  Object.entries(params || {}).forEach(([k, v]) => {
    if (v !== undefined && v !== null && v !== '') qs.set(k, v);
  });
  const q = qs.toString();
  return `${BASE}${path}${q ? `?${q}` : ''}`;
}

async function request(path, { method = 'GET', body, params, auth = true } = {}) {
  const isForm = body instanceof FormData;
  const headers = { Accept: 'application/json' };
  if (body !== undefined && !isForm) headers['Content-Type'] = 'application/json';
  const sentToken = auth ? getStoredAuth()?.token : null;
  if (sentToken) headers.Authorization = `Bearer ${sentToken}`;

  // A request can never hang forever: abort after a timeout (longer for uploads).
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), isForm ? UPLOAD_TIMEOUT_MS : TIMEOUT_MS);

  let res;
  let text;
  try {
    res = await fetch(buildUrl(path, params), {
      method,
      headers,
      signal: controller.signal,
      body: isForm ? body : body !== undefined ? JSON.stringify(body) : undefined,
    });
    text = await res.text();
  } catch (e) {
    throw e?.name === 'AbortError'
      ? new ApiError(0, 'TIMEOUT', 'The server took too long to respond. Please try again.')
      : new ApiError(0, 'NETWORK_ERROR', 'Cannot reach the server. Check your connection.');
  } finally {
    clearTimeout(timer);
  }

  let data = null;
  try {
    data = text ? JSON.parse(text) : null;
  } catch {
    data = null;
  }

  if (!res.ok) {
    const err = data?.error || {};
    // Only drop the session if the rejected token is still the current one
    // (avoids logging out a fresh login because of a slow, older request).
    if (res.status === 401 && sentToken && getStoredAuth()?.token === sentToken) {
      storeAuth(null);
      window.dispatchEvent(new Event('auth:expired'));
    }
    throw new ApiError(
      res.status,
      err.code || 'ERROR',
      err.message || `Request failed (${res.status})`,
      err.details,
      Number(res.headers.get('Retry-After')) || undefined
    );
  }
  return data;
}

export const api = {
  // --- auth
  register: (body) => request('/auth/register', { method: 'POST', body, auth: false }),
  login: (body) => request('/auth/login', { method: 'POST', body, auth: false }),
  me: () => request('/auth/me'),
  // --- users (admin)
  users: (params) => request('/users', { params }),
  // --- products (browsing is public)
  listProducts: (params) => request('/products', { params, auth: false }),
  getProduct: (id) => request(`/products/${id}`, { auth: false }),
  createProduct: (formData) => request('/products', { method: 'POST', body: formData }), // multipart
  updateProduct: (id, body) => request(`/products/${id}`, { method: 'PATCH', body }),
  changeProductStock: (id, change) =>
    request(`/products/${id}/stock`, { method: 'PATCH', body: { change } }),
  // --- orders
  listOrders: (params) => request('/orders', { params }),
  getOrder: (id) => request(`/orders/${id}`),
  createOrder: (body) => request('/orders', { method: 'POST', body }),
  changeStatus: (id, status) =>
    request(`/orders/${id}/status`, { method: 'PATCH', body: { status } }),
};

// ---------------------------------------------------------------------------
// Error helpers. All API errors use { error: { code, message, details } }.
// ---------------------------------------------------------------------------

// VALIDATION_ERROR details -> { fieldName: message }, for inline form errors.
export function fieldErrors(err) {
  const out = {};
  if (err?.code === 'VALIDATION_ERROR' && Array.isArray(err.details)) {
    err.details.forEach((d) => {
      if (d?.field && !out[d.field]) out[d.field] = d.message || 'Invalid value';
    });
  }
  return out;
}

function waitText(seconds) {
  if (!seconds) return 'Please wait a few minutes before trying again.';
  const mins = Math.ceil(seconds / 60);
  return mins > 1 ? `Please try again in about ${mins} minutes.` : 'Please try again in a minute.';
}

// Turns any error into { title, lines, severity } that people can understand.
export function describeError(err) {
  const details = Array.isArray(err?.details) ? err.details : [];
  switch (err?.code) {
    case 'TOO_MANY_REQUESTS':
      return {
        title: 'Too many attempts',
        lines: [waitText(err.retryAfter)],
        severity: 'warning',
      };
    case 'INSUFFICIENT_STOCK':
      return {
        title: 'Not enough stock',
        lines: details.map((d) => {
          const who = d.name || d.product_name || 'This product';
          return d.requested !== undefined
            ? `${who}: ${d.requested} requested, only ${d.available} available.`
            : `Only ${d.available} in stock.`;
        }),
        severity: 'error',
      };
    case 'VALIDATION_ERROR':
      return {
        title: 'Please check your input',
        lines: details.map((d) => (d?.field ? `${d.field}: ${d.message}` : d?.message)).filter(Boolean),
        severity: 'error',
      };
    case 'FORBIDDEN':
      return { title: 'You do not have permission to do that.', lines: [], severity: 'error' };
    default: {
      const lines = details
        .map((d) => (typeof d === 'string' ? d : d?.message))
        .filter((m) => m && m !== err?.message);
      return { title: err?.message || 'Something went wrong', lines, severity: 'error' };
    }
  }
}

// Kept for backwards compatibility.
export function errorMessages(err) {
  const { title, lines } = describeError(err);
  return [title, ...lines];
}
