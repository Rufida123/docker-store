// Loads KEY=VALUE pairs from a local .env file into process.env, without
// overriding variables that are already set. In Docker everything comes from
// real environment variables and the file simply doesn't exist.
const fs = require('fs');
const path = require('path');

const file = path.join(__dirname, '..', '.env');

if (fs.existsSync(file)) {
  for (const line of fs.readFileSync(file, 'utf8').split(/\r?\n/)) {
    const match = /^([A-Z0-9_]+)=(.*)$/.exec(line.trim());
    if (match && process.env[match[1]] === undefined) {
      process.env[match[1]] = match[2];
    }
  }
}
