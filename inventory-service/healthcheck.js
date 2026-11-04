// Container healthcheck: one GetStock call with an empty list. Exits 0 when
// the server answers before the deadline, 1 otherwise.
const grpc = require('@grpc/grpc-js');
const { loadDefinition } = require('./src/proto');

const address = process.env.INVENTORY_LISTEN_ADDR || '127.0.0.1:50051';
const definition = loadDefinition();
const client = new definition.Inventory(address.replace('0.0.0.0', '127.0.0.1'), grpc.credentials.createInsecure());

client.GetStock({ product_ids: [] }, { deadline: new Date(Date.now() + 2000) }, (err) => {
  process.exit(err ? 1 : 0);
});
