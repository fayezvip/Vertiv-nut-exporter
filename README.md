# Vertiv NUT Prometheus Exporter

A lightweight, dependency-free Prometheus exporter written in pure PHP for [Network UPS Tools (NUT)](https://networkupstools.org/). It supports multiple NUT servers and UPS devices, optional authentication, metric filtering, and full Prometheus integration. designed for simplicity, efficiency, and portability.

---

## 🔒 Security notice: PHP2Shell (CVE-2026-109618, CVE-2026-109619)

Images before `v1.0.2` ship PHP 8.4.5, which is vulnerable to unauthenticated remote code execution. Update now:

```bash
docker compose pull && docker compose up -d
```

---

## 🔌 Features

- 🚀 Connects to multiple NUT servers over TCP
- 🔐 Optional NUT authentication (username + password)
- 🔍 Metric filtering and renaming via `config.json`
- 📊 Prometheus `/metrics` exposition format
- ⚙️ Caching for reduced load (configurable TTL)
- 🪶 Lightweight and runs on PHP's built-in server
- 🐳 Fully containerized with CI/CD via GitHub Actions + Docker Hub

---

## 📦 Example `config.json`

```json
{
	"servers": [
		{
			"host": "127.0.0.1",
			"port": 3493,
			"username": "upsmon",
			"password": "password",
			"upses": [
				{
					"name": "ups-1",
					"labels": { "location": "Comms-Room" }
				},
				{
					"name": "ups-2",
					"labels": { "location": "Servers-Room" }
				}
			]
		}
	],
	"cache_ttl": 15,
	"metrics_path": "/metrics",
	"rename_vars": {
		"ups.status": "ups_state",
		"battery.runtime": "battery_runtime_seconds"
	}
}
```

## 📦 Example `config.json`

```json
{
	"servers": [
		{
			"host": "127.0.0.1",
			"port": 3493,
			"username": "upsmon",
			"password": "password",
			"upses": [
				{
					"name": "ups-1",
					"labels": { "location": "Comms-Room" }
				},
				{
					"name": "ups-2",
					"labels": { "location": "Servers-Room" }
				}
			]
		}
	],
	"cache_ttl": 15,
	"metrics_path": "/metrics",
	"rename_vars": {
		"ups.status": "ups_state",
		"battery.runtime": "battery_runtime_seconds"
	}
}
```

### 🐳 Docker Usage

```bash
docker run -d \
  -p 9000:9000 \
  -v $(pwd)/config.json:/app/config.json:ro \
  -v $(pwd)/logs:/app/logs \
  -v $(pwd)/cache:/app/cache \
  fayezvip/vertiv-nut-exporter:latest

```

### 🔧 Docker Compose

```bash
services:
  nut-exporter:
    image: fayezvip/vertiv-nut-exporter:latest
    ports:
      - "9000:9000"
    volumes:
      - ./config.json:/app/config.json:ro
      - ./logs:/app/logs
      - ./cache:/app/cache
    restart: unless-stopped
```

### 📈 Prometheus Scrape Config

```yaml
- job_name: 'vertiv-nut-exporter'
  static_configs:
    - targets: ['<exporter-host>:9000']
```

## 📊 Grafana Integration

Use the /metrics endpoint as a Prometheus datasource and build dashboards for:

- UPS status

- Battery charge (%)

- Load %

- Runtime remaining

- Input/output voltage & frequency

🧙‍♂️ Value mappings like "OL" → "Online" supported via Grafana overrides

## 📝 Configuration

Edit config.json to define your NUT server(s), UPS names, and optional labels.

```bash
{
  "servers": [
    {
      "host": "127.0.0.1",
      "port": 3493,
      "username": "upsmon",
      "password": "yourpassword",
      "upses": [
        {
          "name": "my-ups",
          "labels": { "location": "Comms-Room" }
        }
      ]
    }
  ],
  "cache_ttl": 15,
  "metrics_path": "/metrics"
}
```

## 🔐 Security Notes

This image is based on php:<tag> and may include inherited Debian packages. All CVEs are:

- Logged by Docker Hub’s vulnerability scanner

- Reviewed for impact (most are non-exploitable in this exporter context)

SBOM and provenance are generated automatically via GitHub Actions.

## 📄 License

This project is licensed under the <a href="LICENSE">MIT License</a>.

## Author

Built with ❤️ by <a href="https://github.com/fayezvip">@fayezvip</a>
