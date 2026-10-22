# PHP >= 8.4.27 required: fixes PHP2Shell (CVE-2026-109618, CVE-2026-109619)
FROM php:8.5.0-cli-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends curl \
  && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY exporter.php config.json ./

RUN mkdir -p /app/logs /app/cache

# Defence in depth for PHP2Shell; the exporter does not use sessions
RUN echo "session.upload_progress.enabled=0" > /usr/local/etc/php/conf.d/zz-hardening.ini

HEALTHCHECK CMD curl --fail http://localhost:9000/metrics || exit 1

EXPOSE 9000

CMD ["php", "-S", "0.0.0.0:9000", "exporter.php"]

