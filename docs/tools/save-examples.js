'use strict';

// Runs the Postman collection against the live API and saves every real
// response back into the collection as a Postman example, plus an HTML
// report. Output: docs/docker-store.postman_collection.with-examples.json
// and docs/reports/api-run.html. The original collection is not modified.
//
// Usage: npm run save-examples   (from docs/tools; API must be on :3000)

const fs = require('fs');
const path = require('path');
const newman = require('newman');

const docsDir = path.resolve(__dirname, '..');
const collectionPath = path.join(docsDir, 'docker-store.postman_collection.json');
const outputPath = path.join(docsDir, 'docker-store.postman_collection.with-examples.json');
const reportPath = path.join(docsDir, 'reports', 'api-run.html');
const envPath = path.resolve(docsDir, '..', 'backend', '.env');

function readAdminPassword() {
  const env = fs.readFileSync(envPath, 'utf8');
  const match = env.match(/^ADMIN_PASSWORD=(.*)$/m);
  if (!match || !match[1].trim()) {
    throw new Error('ADMIN_PASSWORD not found in backend/.env');
  }
  return match[1].trim().replace(/^"(.*)"$/, '$1');
}

const adminPassword = readAdminPassword();

// --- redaction ----------------------------------------------------------

const JWT_RE = /\beyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]*/g;
// any JSON string value whose key contains "password", e.g. "password": "..."
const PASSWORD_FIELD_RE = /("[^"]*password[^"]*"\s*:\s*)"(?:[^"\\]|\\.)*"/gi;

function redact(text) {
  if (!text) return text;
  let out = String(text);
  while (out.includes(adminPassword)) out = out.replace(adminPassword, '<redacted>');
  out = out.replace(JWT_RE, '<redacted>');
  out = out.replace(PASSWORD_FIELD_RE, '$1"<redacted>"');
  return out;
}

// --- collection lookup ---------------------------------------------------

const collection = JSON.parse(fs.readFileSync(collectionPath, 'utf8'));

// Map "Folder / Subfolder / Request" -> raw item, and clear old examples.
const itemsByPath = new Map();
(function index(items, trail) {
  for (const item of items) {
    const p = trail.concat(item.name);
    if (item.item) {
      index(item.item, p);
    } else {
      item.response = [];
      itemsByPath.set(p.join(' / '), item);
    }
  }
})(collection.item, []);

function executionPath(item) {
  const names = [item.name];
  // walk up to, but not including, the collection itself (the root has no parent)
  let node = item.parent && item.parent();
  while (node && node.parent && node.parent()) {
    names.unshift(node.name);
    node = node.parent();
  }
  return names.join(' / ');
}

// --- example building ----------------------------------------------------

function headersToArray(headerList) {
  const out = [];
  headerList.each((h) => {
    if (!h.disabled) out.push({ key: h.key, value: redact(String(h.value)) });
  });
  return out;
}

function exampleName(response) {
  let label = response.reason();
  try {
    const body = JSON.parse(response.text());
    const code = (body && body.error && body.error.code) || (body && body.code);
    if (typeof code === 'string' && code) label = code;
  } catch (_) { /* not JSON, keep the reason phrase */ }
  return `${response.code} ${label}`;
}

function buildExample(execution) {
  const { request, response } = execution;
  const original = {
    method: request.method,
    header: headersToArray(request.headers),
    url: { raw: redact(request.url.toString()) },
  };
  if (request.body && request.body.mode === 'raw' && request.body.raw) {
    original.body = { mode: 'raw', raw: redact(String(request.body.raw)) };
  } else if (request.body && request.body.mode === 'formdata') {
    original.body = {
      mode: 'formdata',
      formdata: request.body.formdata.map((f) => ({
        key: f.key,
        type: f.type,
        ...(f.type === 'file' ? { src: f.src } : { value: redact(String(f.value || '')) }),
      })),
    };
  }

  const contentType = response.headers.get('Content-Type') || '';
  return {
    name: exampleName(response),
    originalRequest: original,
    status: response.reason(),
    code: response.code,
    _postman_previewlanguage: contentType.includes('json') ? 'json' : 'text',
    header: headersToArray(response.headers),
    cookie: [],
    body: redact(response.text()),
  };
}

// --- run -----------------------------------------------------------------

fs.mkdirSync(path.dirname(reportPath), { recursive: true });

newman.run(
  {
    collection: collectionPath,
    workingDir: path.join(docsDir, 'test-files'),
    insecureFileRead: true,
    envVar: [{ key: 'adminPassword', value: adminPassword }],
    reporters: ['cli', 'htmlextra'],
    reporter: {
      htmlextra: {
        export: reportPath,
        title: 'docker-store API run',
        browserTitle: 'docker-store API run',
        showEnvironmentData: false,
      },
    },
  },
  (err, summary) => {
    if (err) {
      console.error('newman failed to run:', err.message);
      process.exitCode = 1;
      return;
    }

    let saved = 0;
    let unmatched = 0;
    for (const execution of summary.run.executions) {
      if (!execution.response) continue; // request-level error (no response)
      const item = itemsByPath.get(executionPath(execution.item));
      if (!item) { unmatched++; continue; }
      const example = buildExample(execution);
      // same request can run more than once; keep one example per name
      item.response = item.response.filter((r) => r.name !== example.name);
      item.response.push(example);
      saved++;
    }

    fs.writeFileSync(outputPath, JSON.stringify(collection, null, '\t') + '\n');

    // the report shows full requests and responses, so scrub it the same way
    // (plus the HTML-escaped form of the password-field pattern)
    const HTML_PASSWORD_FIELD_RE = /(&quot;[^&]*password[^&]*&quot;\s*:\s*)&quot;(?:(?!&quot;).)*&quot;/gi;
    const report = redact(fs.readFileSync(reportPath, 'utf8'))
      .replace(HTML_PASSWORD_FIELD_RE, '$1&quot;&lt;redacted&gt;&quot;');
    fs.writeFileSync(reportPath, report);

    const stats = summary.run.stats;
    const failures = summary.run.failures.length;
    console.log('');
    console.log(`examples saved: ${saved}${unmatched ? ` (unmatched executions: ${unmatched})` : ''}`);
    console.log(`requests: ${stats.requests.total}, assertions: ${stats.assertions.total}, failures: ${failures}`);
    console.log(`collection with examples: ${outputPath}`);
    console.log(`html report: ${reportPath}`);
    process.exitCode = failures > 0 ? 1 : 0;
  }
);
