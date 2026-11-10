// Helpers shared by the load test.

// Same id formula as `php artisan perf:seed` and inventory-service/src/seed-perf.js:
// n in the first group, a kind marker in the fourth (valid UUID v4).
export const PRODUCT = '8f00';
export const USER = '8f01';

export function perfUuid(kind, n) {
  return `${n.toString(16).padStart(8, '0')}-0000-4000-${kind}-000000000000`;
}

export const randInt = (min, max) => min + Math.floor(Math.random() * (max - min + 1));

export const pick = (items) => items[Math.floor(Math.random() * items.length)];

// `count` distinct integers in [min, max].
export function distinctInts(count, min, max) {
  const out = new Set();
  while (out.size < Math.min(count, max - min + 1)) out.add(randInt(min, max));
  return [...out];
}

export function chunks(items, size) {
  const out = [];
  for (let i = 0; i < items.length; i += size) out.push(items.slice(i, i + size));
  return out;
}

export function errorCode(res) {
  try {
    return res.json('error.code') || null;
  } catch (_) {
    return null;
  }
}

const fmt = (v, digits = 1) => (v === undefined || v === null || Number.isNaN(v) ? '-' : Number(v).toFixed(digits));

// Plain-text table of the per-endpoint numbers built in handleSummary.
export function renderTable(rows) {
  const header = ['endpoint', 'requests', 'p50 ms', 'p95 ms', 'p99 ms', 'err %', '409 stock', '429', 'avg queries', 'cache hit %', '304 %'];
  const lines = rows.map((r) => [
    r.endpoint,
    String(r.requests),
    fmt(r.p50),
    fmt(r.p95),
    fmt(r.p99),
    fmt(r.error_rate * 100, 2),
    String(r.status.stock_409),
    String(r.status.too_many_429),
    fmt(r.avg_queries),
    r.cache_hit_ratio === null || r.cache_hit_ratio === undefined ? '-' : fmt(r.cache_hit_ratio * 100),
    r.not_modified_ratio ? fmt(r.not_modified_ratio * 100) : '-',
  ]);
  const widths = header.map((h, i) => Math.max(h.length, ...lines.map((l) => l[i].length)));
  const row = (cells) => cells.map((c, i) => (i === 0 ? c.padEnd(widths[i]) : c.padStart(widths[i]))).join('  ');
  return [row(header), widths.map((w) => '-'.repeat(w)).join('  '), ...lines.map(row)].join('\n');
}
