FROM php:8.4.5-cli-bullseye

RUN apt-get update && apt-get install -y --no-install-recommends curl \
  && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY exporter.php config.json ./

RUN mkdir -p /app/logs /app/cache

HEALTHCHECK CMD curl --fail http://localhost:9000/metrics || exit 1

EXPOSE 9000

CMD ["php", "-S", "0.0.0.0:9000", "exporter.php"]

