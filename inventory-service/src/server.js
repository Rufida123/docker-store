// Inventory gRPC server entrypoint. The contract lives in
// proto/inventory/v1/inventory.proto at the repository root.
require('./env');
const grpc = require('@grpc/grpc-js');
const pool = require('./db');
const { migrate } = require('./migrate');
const { buildServer } = require('./service');

const ADDRESS = process.env.INVENTORY_LISTEN_ADDR || '0.0.0.0:50051';

async function main() {
  await migrate(pool);

  const server = buildServer();

  await new Promise((resolve, reject) => {
    server.bindAsync(ADDRESS, grpc.ServerCredentials.createInsecure(), (err) =>
      err ? reject(err) : resolve()
    );
  });
  console.log(`Inventory service listening on ${ADDRESS}`);

  const shutdown = (signal) => {
    console.log(`${signal} received, shutting down...`);
    server.tryShutdown(async () => {
      await pool.end().catch(() => {});
      process.exit(0);
    });
  };
  process.on('SIGTERM', () => shutdown('SIGTERM'));
  process.on('SIGINT', () => shutdown('SIGINT'));
}

main().catch((err) => {
  console.error('Failed to start:', err);
  process.exit(1);
});
