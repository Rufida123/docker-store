// Builds the gRPC server: handler wiring, per-call logging, the demo delay,
// and the mapping of thrown errors onto gRPC status codes.
const grpc = require('@grpc/grpc-js');
const { loadDefinition } = require('./proto');
const handlers = require('./handlers');

// Demo setting only: delays every call to show client deadlines in action.
const SIMULATE_DELAY_MS = Number(process.env.SIMULATE_DELAY_MS || 0);

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

function unary(name, handler) {
  return async (call, callback) => {
    const startedAt = Date.now();
    let outcome = 'ok';
    try {
      if (SIMULATE_DELAY_MS > 0) {
        await sleep(SIMULATE_DELAY_MS);
      }
      const response = await handler(call.request);
      outcome = response.status || 'ok';
      callback(null, response);
    } catch (err) {
      outcome = `error(${err.code ?? grpc.status.INTERNAL}): ${err.message}`;
      callback({
        code: err.code ?? grpc.status.INTERNAL,
        message: err.code ? err.message : 'internal error',
      });
    } finally {
      console.log(`${name} ${Date.now() - startedAt}ms ${outcome}`);
    }
  };
}

function buildServer() {
  const definition = loadDefinition();
  const server = new grpc.Server();

  server.addService(definition.Inventory.service, {
    GetStock: unary('GetStock', handlers.getStock),
    ReserveStock: unary('ReserveStock', handlers.reserveStock),
    ReleaseStock: unary('ReleaseStock', handlers.releaseStock),
  });
  server.addService(definition.InventoryAdmin.service, {
    AdjustStock: unary('AdjustStock', handlers.adjustStock),
  });

  return server;
}

module.exports = { buildServer };
