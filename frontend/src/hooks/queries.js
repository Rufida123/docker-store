// One place that knows how each API endpoint is cached and what a mutation invalidates.
import { api } from '../api/client';
import { normalizeList } from '../utils/format';
import {
  getQueryData,
  invalidate,
  patchQueries,
  prefetch,
  setQueryData,
  useQuery,
} from './useQuery';

const USER_KEYS = ['data', 'items', 'users', 'results'];
const MAX_PAGES = 10;
const FULL_PAGE = 100;

// ---- products ------------------------------------------------------------
// Paged list; filtering by category and paging happen on the server.
export const useProducts = (params, opts) =>
  useQuery(['products', params], () => api.listProducts(params), { keepPrevious: true, ...opts });

export const prefetchProducts = (params) =>
  prefetch(['products', params], () => api.listProducts(params));

// The whole catalog (a few requests of 100). Used for category chips, search and the
// order form's product picker, so those never need extra requests of their own.
async function fetchCatalog() {
  const first = normalizeList(await api.listProducts({ page: 1, limit: FULL_PAGE }));
  const pages = Math.min(first.totalPages, MAX_PAGES);
  if (pages <= 1) return first.items;
  const rest = await Promise.all(
    Array.from({ length: pages - 1 }, (_, i) => api.listProducts({ page: i + 2, limit: FULL_PAGE }))
  );
  return rest.reduce((all, r) => all.concat(normalizeList(r).items), first.items);
}
export const useCatalog = (opts) => useQuery(['catalog'], fetchCatalog, { staleTime: 60_000, ...opts });

export const useProduct = (id, opts) => useQuery(['product', id], () => api.getProduct(id), opts);

// ---- orders --------------------------------------------------------------
export const useOrders = (params, opts) =>
  useQuery(['orders', params], () => api.listOrders(params), { keepPrevious: true, ...opts });
export const prefetchOrders = (params) => prefetch(['orders', params], () => api.listOrders(params));
export const useOrder = (id, opts) => useQuery(['order', id], () => api.getOrder(id), opts);

// ---- notifications --------------------------------------------------------
// The bell and the notifications page share the 'notifications' tag: marking one as read,
// or a push arriving, refreshes both. The response also carries unread_count.
export const useNotifications = (params, opts) =>
  useQuery(['notifications', params], () => api.listNotifications(params), {
    keepPrevious: true,
    staleTime: 15_000,
    ...opts,
  });

/** Something changed (read, read-all, push received): refetch the notification lists on screen. */
export const notificationsChanged = () => invalidate('notifications');

// ---- users (admin) -------------------------------------------------------
async function fetchAllUsers() {
  const first = normalizeList(await api.users(), USER_KEYS);
  const pages = Math.min(first.totalPages, 20);
  if (pages <= 1) return first.items;
  const rest = await Promise.all(Array.from({ length: pages - 1 }, (_, i) => api.users({ page: i + 2 })));
  return rest.reduce((all, r) => all.concat(normalizeList(r, USER_KEYS).items), first.items);
}
export const useUsers = (opts) => useQuery(['users'], fetchAllUsers, { staleTime: 60_000, ...opts });

// ---- cache updates after mutations ---------------------------------------
const mapItems = (d, fn) => {
  if (Array.isArray(d)) return d.map(fn);
  if (Array.isArray(d?.data)) return { ...d, data: d.data.map(fn) };
  return d;
};

/** A product was edited / its stock changed: patch it everywhere it is cached, no refetch. */
export function productChanged(product) {
  const merge = (p) => (p.id === product.id ? { ...p, ...product } : p);
  patchQueries('products', (d) => mapItems(d, merge));
  patchQueries('catalog', (d) => mapItems(d, merge));
  setQueryData(['product', product.id], { ...(getQueryData(['product', product.id]) || {}), ...product });
}

/** A product was created: counts and pages change, so refetch what is on screen. */
export const productCreated = () => invalidate('products', 'catalog');

/** An order was placed: remember it for the detail page; stock and lists changed. */
export function orderPlaced(order) {
  if (order?.id) setQueryData(['order', order.id], order);
  invalidate('orders', 'products', 'catalog', 'product', 'notifications');
}

/** An order's status changed (the API returns the updated order). */
export function orderUpdated(order) {
  setQueryData(['order', order.id], { ...(getQueryData(['order', order.id]) || {}), ...order });
  invalidate('orders');
  // Cancelling gives the stock back.
  if (order.status === 'cancelled') invalidate('products', 'catalog', 'product');
}
