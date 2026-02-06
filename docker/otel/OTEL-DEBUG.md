# OTEL Debug Setup Guide

## Quick Start

### 1. Start the local OTEL collector for debugging

```powershell
docker-compose up -d
```

This will start a local OTEL collector with these ports:

- **24317**: OTLP gRPC receiver
- **24318**: OTLP HTTP receiver
- **9888**: Prometheus metrics
- **9889**: Prometheus exporter metrics
- **23133**: Health check

### 2. Update .env to use local collector

```env
OTEL_EXPORTER_OTLP_ENDPOINT=http://localhost:24318
```

### 3. Restart Laravel

```powershell
php artisan serve --port=9043
```

## Debug Endpoints

### Check Collector Status

```bash
curl http://localhost:9043/api/otel/check
```

### Send Test Trace

```bash
curl http://localhost:9043/api/otel/test-trace
```

### View Configuration

```bash
curl http://localhost:9043/api/otel/config
```

## Debugging Steps

### Step 1: Verify Local Collector is Running

```powershell
# Check container status
docker ps | findstr otel-collector-debug

# Check health endpoint
curl http://localhost:23133

# View collector logs
docker logs otel-collector-debug
```

### Step 2: Send Test Trace

```bash
curl http://localhost:9043/api/otel/test-trace
```

You should see output with `trace_id` and `span_id`.

### Step 3: Verify Trace Reception

```powershell
# Check collector logs - you should see debug output
docker logs otel-collector-debug -f

# Check trace file
docker exec otel-collector-debug cat /tmp/otel-traces.json
```

### Step 4: Test Regular Endpoints

```bash
# These should also generate traces
curl http://localhost:9043/api/health
curl http://localhost:9043/api/test
```

## If Traces Are Received Locally

**The Laravel app is working!** The issue is with connecting to your main collector.

### Forward to Main Collector

Edit `otel-collector-config.yaml` and uncomment the OTLP exporter:

```yaml
exporters:
    otlp:
        endpoint: "host.docker.internal:14318" # Your main collector
        tls:
            insecure: true

service:
    pipelines:
        traces:
            receivers: [otlp]
            processors: [memory_limiter, batch, resource]
            exporters: [logging, file, otlp] # Added otlp
```

Then restart:

```powershell
docker-compose restart
```

## If Traces Are NOT Received Locally

Check these issues:

1. **Laravel Configuration**

    ```bash
    curl http://localhost:9043/api/otel/config
    ```

    Verify `otel_enabled` and `traces_enabled` are `true`.

2. **PHP Extensions**

    ```powershell
    php -m | findstr grpc
    php -m | findstr protobuf
    ```

3. **Laravel Logs**

    ```powershell
    Get-Content storage\logs\laravel.log -Tail 50
    ```

4. **Network Issues**
    ```powershell
    curl -v http://localhost:24318
    ```

## Useful Commands

### View All OTEL Collector Logs

```powershell
docker logs otel-collector-debug -f
```

### View Traces File

```powershell
docker exec otel-collector-debug cat /tmp/otel-traces.json | Select-String "trace_id"
```

### Check Prometheus Metrics

```bash
curl http://localhost:9889/metrics
```

### Check ZPages (Collector Internal State)

Open in browser: http://localhost:55679/debug/tracez

### Stop Collector

```powershell
docker-compose down
```

## Connecting to Main Collector

Once local debugging confirms traces are working:

### Option 1: Direct Connection (Update Laravel)

```env
# .env
OTEL_EXPORTER_OTLP_ENDPOINT=http://your-main-server-ip:14318
```

### Option 2: Forward via Local Collector

Keep using local collector but enable forwarding in `otel-collector-config.yaml`:

```yaml
exporters:
    otlp:
        endpoint: "your-main-server-ip:14318"
        tls:
            insecure: true
```

## Troubleshooting Matrix

| Symptom                      | Possible Cause      | Solution                                         |
| ---------------------------- | ------------------- | ------------------------------------------------ |
| Debug collector not starting | Port conflict       | Change ports in docker-compose.yml               |
| Traces not in logs           | Laravel not sending | Check `/api/otel/config` endpoint                |
| Traces in logs but not file  | File exporter issue | Check collector logs                             |
| Can't forward to main        | Network issue       | Test: `curl http://main-server:14318`            |
| High memory usage            | Too many traces     | Adjust sampling: `OTEL_TRACES_SAMPLER_RATIO=0.1` |

## Architecture

```
Laravel App (port 9043)
    ↓ OTLP HTTP
Local Debug Collector (port 24318)
    ├── Console Logs (debug)
    ├── File Export (/tmp/otel-traces.json)
    └── Forward to Main Collector (port 14318)
            ↓
        Main Collector (port 14318)
            ├── Tempo (traces)
            ├── Prometheus (metrics)
            └── Loki (logs)
                ↓
            Grafana
```
