// A tiny data layer (no extra dependency) that gives every screen:
//  - a shared in-memory cache  -> navigating back is instant (stale-while-revalidate)
//  - request de-duplication    -> two components asking for the same data = one request
//  - targeted invalidation     -> after a mutation only the affected data is refetched
//  - keepPrevious              -> paging/filtering never flashes an empty screen
//  - prefetch                  -> the next page is fetched before the user clicks it
import { useCallback, useEffect, useRef, useSyncExternalStore } from 'react';

const store = new Map(); // hash -> { data, error, ts, fetching, tag }
const inflight = new Map(); // hash -> Promise
const gens = new Map(); // hash -> latest request generation (ignores out-of-date responses)
const active = new Map(); // hash -> Set<fetcherRef> for mounted components
const subs = new Set();

const emit = () => subs.forEach((fn) => fn());
const subscribe = (fn) => {
  subs.add(fn);
  return () => subs.delete(fn);
};
export const hashKey = (key) => JSON.stringify(key);

function setEntry(h, patch) {
  const prev = store.get(h) || { data: undefined, error: null, ts: 0, fetching: false, tag: undefined };
  store.set(h, { ...prev, ...patch });
  emit();
}

function run(h, tag, fetcher) {
  if (inflight.has(h)) return inflight.get(h);
  const gen = (gens.get(h) || 0) + 1;
  gens.set(h, gen);
  setEntry(h, { fetching: true, tag });
  const p = Promise.resolve()
    .then(fetcher)
    .then(
      (data) => {
        if (gens.get(h) === gen) setEntry(h, { data, error: null, ts: Date.now(), fetching: false });
        return data;
      },
      (error) => {
        if (gens.get(h) === gen) setEntry(h, { error, fetching: false });
        return undefined;
      }
    )
    .finally(() => {
      if (inflight.get(h) === p) inflight.delete(h);
    });
  inflight.set(h, p);
  return p;
}

const isFresh = (entry, staleTime) => Boolean(entry && entry.ts && Date.now() - entry.ts < staleTime);

/** Mark every cached entry with these tags as stale and refetch the ones on screen. */
export function invalidate(...tags) {
  for (const [h, entry] of store) {
    if (!tags.includes(entry.tag)) continue;
    store.set(h, { ...entry, ts: 0 });
    const refs = active.get(h);
    if (refs?.size) {
      inflight.delete(h);
      run(h, entry.tag, () => [...refs][0].current());
    }
  }
  emit();
}

export function getQueryData(key) {
  return store.get(hashKey(key))?.data;
}

export function setQueryData(key, data) {
  setEntry(hashKey(key), { data, error: null, ts: Date.now(), tag: key[0] });
}

/** Rewrite cached data for every entry with this tag (e.g. patch one product in all lists). */
export function patchQueries(tag, fn) {
  for (const [h, entry] of store) {
    if (entry.tag === tag && entry.data !== undefined) store.set(h, { ...entry, data: fn(entry.data) });
  }
  emit();
}

export function prefetch(key, fetcher, staleTime = 30_000) {
  const h = hashKey(key);
  if (isFresh(store.get(h), staleTime) || inflight.has(h)) return;
  run(h, key[0], fetcher);
}

/** Forget everything (called on login/logout so one user never sees another's cached data). */
export function clearCache() {
  store.clear();
  inflight.clear();
  gens.clear();
  emit();
}

export function useQuery(key, fetcher, { enabled = true, staleTime = 30_000, keepPrevious = false } = {}) {
  const h = hashKey(key);
  const tag = key[0];
  const fetcherRef = useRef(fetcher);
  fetcherRef.current = fetcher;

  const entry = useSyncExternalStore(subscribe, () => store.get(h));

  useEffect(() => {
    const set = active.get(h) || new Set();
    set.add(fetcherRef);
    active.set(h, set);
    return () => {
      set.delete(fetcherRef);
      if (!set.size) active.delete(h);
    };
  }, [h]);

  useEffect(() => {
    if (!enabled) return;
    if (!isFresh(store.get(h), staleTime) && !inflight.has(h)) run(h, tag, () => fetcherRef.current());
  }, [h, tag, enabled, staleTime]);

  const lastData = useRef();
  if (entry?.data !== undefined) lastData.current = entry.data;
  const hasOwn = entry?.data !== undefined;
  const data = hasOwn ? entry.data : keepPrevious ? lastData.current : undefined;

  const refetch = useCallback(() => {
    inflight.delete(h);
    return run(h, tag, () => fetcherRef.current());
  }, [h, tag]);

  return {
    data,
    error: entry?.error || null,
    loading: enabled && data === undefined && !entry?.error, // first load, nothing to show yet
    fetching: Boolean(entry?.fetching), // any request in flight (incl. background refresh)
    placeholder: !hasOwn && data !== undefined, // showing the previous page while the new one loads
    refetch,
  };
}
