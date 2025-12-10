# Docker Usage Guide - Cronitor MCP Server

## Quick Start - Standalone HTTP Server

### Pull and Run from DockerHub

```bash
# Run the latest version
docker run -d \
  --name cronitor-mcp \
  -p 8080:80 \
  davidsmith3/cronitor-mcp:latest

# Access the MCP server
curl http://localhost:8080/mcp
```

### Build and Run Locally

```bash
# Build the production image
docker build --target production -t cronitor-mcp:local .

# Run the container
docker run -d \
  --name cronitor-mcp \
  -p 8080:80 \
  cronitor-mcp:local
```

## Container Configuration

### Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `MCP_DEBUG` | `false` | Enable debug logging |
| `PHP_MEMORY_LIMIT` | `256M` | PHP memory limit |

**Example with environment variables:**

```bash
docker run -d \
  --name cronitor-mcp \
  -p 8080:80 \
  -e MCP_DEBUG=true \
  -e PHP_MEMORY_LIMIT=512M \
  davidsmith3/cronitor-mcp:latest
```

### Persistent Sessions

MCP sessions are stored in `/var/www/html/storage/mcp-sessions`. To persist sessions across container restarts:

```bash
docker run -d \
  --name cronitor-mcp \
  -p 8080:80 \
  -v cronitor-sessions:/var/www/html/storage/mcp-sessions \
  davidsmith3/cronitor-mcp:latest
```

## Testing the Server

### Health Check

The container includes a health check that verifies the `/mcp` endpoint:

```bash
# Check container health
docker ps --filter "name=cronitor-mcp"

# Manual health check
curl -X OPTIONS http://localhost:8080/mcp
```

### Initialize MCP Session

```bash
curl -X POST http://localhost:8080/mcp \
  -H "Content-Type: application/json" \
  -d '{
    "jsonrpc": "2.0",
    "id": 1,
    "method": "initialize",
    "params": {
      "protocolVersion": "2024-11-05",
      "capabilities": {},
      "clientInfo": {
        "name": "test-client",
        "version": "1.0.0"
      }
    }
  }'
```

**Expected response:**

```json
{
  "jsonrpc": "2.0",
  "id": 1,
  "result": {
    "protocolVersion": "2025-06-18",
    "capabilities": {
      "completions": {}
    },
    "serverInfo": {
      "name": "Cronitor MCP Server",
      "version": "1.0.0"
    }
  }
}
```

**Response headers will include:**

```
Mcp-Session-Id: <uuid>
Access-Control-Allow-Origin: *
Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS
```

### List Available Tools

```bash
# Use the session ID from the initialize response
SESSION_ID="<your-session-id>"

curl -X POST http://localhost:8080/mcp \
  -H "Content-Type: application/json" \
  -H "Mcp-Session-Id: $SESSION_ID" \
  -d '{
    "jsonrpc": "2.0",
    "id": 2,
    "method": "tools/list"
  }'
```

### Call a Tool

```bash
curl -X POST http://localhost:8080/mcp \
  -H "Content-Type: application/json" \
  -H "Mcp-Session-Id: $SESSION_ID" \
  -d '{
    "jsonrpc": "2.0",
    "id": 3,
    "method": "tools/call",
    "params": {
      "name": "add",
      "arguments": {
        "a": 5,
        "b": 3
      }
    }
  }'
```

## Docker Compose

For easier management, use docker-compose:

```yaml
services:
  mcp:
    image: davidsmith3/cronitor-mcp:latest
    ports:
      - "8080:80"
    environment:
      - MCP_DEBUG=false
    volumes:
      - mcp-sessions:/var/www/html/storage/mcp-sessions
    restart: unless-stopped
    healthcheck:
      test: ["CMD-SHELL", "curl -f http://localhost:80/mcp -X OPTIONS || exit 1"]
      interval: 30s
      timeout: 3s
      retries: 3
      start_period: 5s

volumes:
  mcp-sessions:
```

Run with:

```bash
docker-compose up -d
```

## Multi-Architecture Support

The image supports both AMD64 and ARM64 architectures:

```bash
# Explicitly specify platform (optional)
docker run -d \
  --platform linux/amd64 \
  --name cronitor-mcp \
  -p 8080:80 \
  davidsmith3/cronitor-mcp:latest

# For ARM64 (e.g., Apple Silicon, Raspberry Pi)
docker run -d \
  --platform linux/arm64 \
  --name cronitor-mcp \
  -p 8080:80 \
  davidsmith3/cronitor-mcp:latest
```

## Container Management

### View Logs

```bash
# Follow logs
docker logs -f cronitor-mcp

# View last 100 lines
docker logs --tail 100 cronitor-mcp
```

### Restart Container

```bash
docker restart cronitor-mcp
```

### Stop and Remove

```bash
docker stop cronitor-mcp
docker rm cronitor-mcp
```

### Execute Commands Inside Container

```bash
# Interactive shell
docker exec -it cronitor-mcp sh

# Run single command
docker exec cronitor-mcp php -v
```

## Production Deployment

### Using Docker Swarm

```bash
# Create a stack file
cat > mcp-stack.yml <<EOF
version: '3.8'
services:
  mcp:
    image: davidsmith3/cronitor-mcp:latest
    ports:
      - "8080:80"
    deploy:
      replicas: 3
      restart_policy:
        condition: on-failure
    environment:
      - MCP_DEBUG=false
    volumes:
      - mcp-sessions:/var/www/html/storage/mcp-sessions

volumes:
  mcp-sessions:
EOF

# Deploy the stack
docker stack deploy -c mcp-stack.yml cronitor
```

### Using Kubernetes

```yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: cronitor-mcp
spec:
  replicas: 3
  selector:
    matchLabels:
      app: cronitor-mcp
  template:
    metadata:
      labels:
        app: cronitor-mcp
    spec:
      containers:
      - name: mcp
        image: davidsmith3/cronitor-mcp:latest
        ports:
        - containerPort: 80
        env:
        - name: MCP_DEBUG
          value: "false"
        volumeMounts:
        - name: sessions
          mountPath: /var/www/html/storage/mcp-sessions
        livenessProbe:
          httpGet:
            path: /mcp
            port: 80
            httpHeaders:
            - name: X-Custom-Header
              value: Health-Check
          initialDelaySeconds: 5
          periodSeconds: 30
      volumes:
      - name: sessions
        emptyDir: {}
---
apiVersion: v1
kind: Service
metadata:
  name: cronitor-mcp
spec:
  selector:
    app: cronitor-mcp
  ports:
  - port: 80
    targetPort: 80
  type: LoadBalancer
```

## Troubleshooting

### Container Won't Start

```bash
# Check logs
docker logs cronitor-mcp

# Verify image
docker inspect davidsmith3/cronitor-mcp:latest
```

### Permission Issues

```bash
# Check storage directory permissions
docker exec cronitor-mcp ls -la storage/mcp-sessions
```

### Network Issues

```bash
# Test from inside container
docker exec cronitor-mcp curl -v http://localhost:80/mcp -X OPTIONS

# Check port binding
docker port cronitor-mcp
```

### Health Check Failing

```bash
# Manual health check
docker exec cronitor-mcp curl -f http://localhost:80/mcp -X OPTIONS

# Disable health check temporarily
docker run -d \
  --name cronitor-mcp \
  --no-healthcheck \
  -p 8080:80 \
  davidsmith3/cronitor-mcp:latest
```

## Image Details

- **Base Image**: `php:8.4-alpine`
- **Image Size**: ~124MB
- **PHP Version**: 8.4
- **Extensions**: mysqli, standard PHP extensions
- **Web Server**: PHP built-in server
- **Protocol**: MCP over HTTP
- **Endpoint**: `/mcp`
- **Port**: 80 (exposed)

## Security Considerations

1. **Not Production-Ready for Internet Exposure**: The PHP built-in server is for development and should be behind a reverse proxy in production
2. **Use HTTPS**: Place behind nginx/traefik with TLS termination
3. **Authentication**: Consider adding authentication layer for production use
4. **CORS**: Default CORS is permissive (`*`) - restrict in production
5. **Session Storage**: Use persistent volume or external session store for multi-replica deployments

## Related Documentation

- [MCP Protocol Documentation](https://github.com/modelcontextprotocol/php-sdk)
- [Production Setup Guide](./PRODUCTION_SETUP.md)
- [Development Guide](./LOCAL_DEVELOPMENT.md)