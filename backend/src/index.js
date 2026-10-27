const app = require('./app');
const { ensureAdmin } = require('./modules/users');

if (!process.env.JWT_SECRET) {
  console.error('JWT_SECRET is not set');
  process.exit(1);
}

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

async function seedAdmin() {
  for (let attempt = 1; attempt <= 5; attempt += 1) {
    try {
      await ensureAdmin();
      return;
    } catch (err) {
      console.error(`Admin creation failed (attempt ${attempt}/5):`, err.message);
      await sleep(2000);
    }
  }
}

async function main() {
  await seedAdmin();
  const port = process.env.PORT || 3000;
  app.listen(port, () => console.log(`API listening on port ${port}`));
}

main();
