# Multi-stage build → small distroless image. rustls (no OpenSSL) keeps the runtime minimal.
FROM rust:1-slim AS build
WORKDIR /app
COPY Cargo.toml ./
# Cache deps: build a stub first, then the real sources.
RUN mkdir src && echo 'fn main() {}' > src/main.rs && cargo build --release || true
COPY src ./src
RUN touch src/main.rs && cargo build --release

FROM gcr.io/distroless/cc-debian12
COPY --from=build /app/target/release/djinn-proxy /djinn-proxy
ENV PORT=8080
EXPOSE 8080
CMD ["/djinn-proxy"]
