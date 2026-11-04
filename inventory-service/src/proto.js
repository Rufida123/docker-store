// Loads the shared contract. The .proto at the repository root is the single
// source of truth for this service and for the PHP client classes.
const path = require('path');
const grpc = require('@grpc/grpc-js');
const protoLoader = require('@grpc/proto-loader');

const PROTO_PATH = path.join(__dirname, '..', '..', 'proto', 'inventory', 'v1', 'inventory.proto');

function loadDefinition() {
  const packageDefinition = protoLoader.loadSync(PROTO_PATH, {
    keepCase: true,
    longs: Number,
    enums: String,
    defaults: true,
  });
  return grpc.loadPackageDefinition(packageDefinition).inventory.v1;
}

module.exports = { loadDefinition, PROTO_PATH };
