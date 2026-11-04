# Built with the repository root as context, so the shared contract in
# proto/ can be copied next to the service.
FROM node:20-alpine

WORKDIR /app

COPY inventory-service/package.json inventory-service/package-lock.json ./
RUN npm install --omit=dev

COPY inventory-service/src ./src
COPY inventory-service/migrations ./migrations
COPY inventory-service/healthcheck.js ./healthcheck.js
COPY proto /proto

USER node

EXPOSE 50051

HEALTHCHECK --interval=10s --timeout=5s --retries=5 CMD ["node", "healthcheck.js"]

CMD ["node", "src/server.js"]
