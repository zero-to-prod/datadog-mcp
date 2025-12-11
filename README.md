# datadog-mcp

![](art/logo.png)

[![Repo](https://img.shields.io/badge/github-gray?logo=github)](https://github.com/zero-to-prod/datadog-mcp)
[![GitHub Actions Workflow Status](https://img.shields.io/github/actions/workflow/status/zero-to-prod/datadog-mcp/test.yml?label=test)](https://github.com/zero-to-prod/datadog-mcp/actions)
[![GitHub Actions Workflow Status](https://img.shields.io/github/actions/workflow/status/zero-to-prod/datadog-mcp/backwards_compatibility.yml?label=backwards_compatibility)](https://github.com/zero-to-prod/datadog-mcp/actions)
[![GitHub Actions Workflow Status](https://img.shields.io/github/actions/workflow/status/zero-to-prod/datadog-mcp/build_docker_image.yml?label=build_docker_image)](https://github.com/zero-to-prod/datadog-mcp/actions)
[![GitHub License](https://img.shields.io/badge/license-MIT-blue?style=flat-square)](https://github.com/zero-to-prod/datadog-mcp/blob/main/LICENSE.md)
[![wakatime](https://wakatime.com/badge/github/zero-to-prod/datadog-mcp.svg)](https://wakatime.com/badge/github/zero-to-prod/datadog-mcp)
[![Hits-of-Code](https://hitsofcode.com/github/zero-to-prod/datadog-mcp?branch=main)](https://hitsofcode.com/github/zero-to-prod/datadog-mcp/view?branch=main)

## Contents

- [Introduction](#introduction)
- [Requirements](#requirements)
- [Installation](#installation)
- [Quick Start](#quick-start)
- [Usage](#usage)
- [Docker Image](#docker)
- [Contributing](#contributing)

## Introduction

MCP Server for DataDog

## Requirements

- PHP 8.1 or higher

## Installation

```bash
composer require zero-to-prod/datadog-mcp
```

## Quick Start

### 1. Get Your Datadog API Keys

Get your API keys from: https://app.datadoghq.com/organization-settings/api-keys

You'll need:
- **DD_API_KEY** - Your Datadog API key
- **DD_APPLICATION_KEY** - Your Datadog application key

### 2. Run the Docker Image

```shell
docker run -d -p 8091:80 \
  -e DD_API_KEY=your_api_key_here \
  -e DD_APPLICATION_KEY=your_app_key_here \
  davidsmith3/datadog-mcp:latest
```

### 3. Add the Server to Claude

```shell
claude mcp add --transport http datadog-mcp http://localhost:8091/mcp
```

Alternatively, add the server configuration directly:

```json
{
    "mcpServers": {
        "datadog-mcp": {
            "type": "streamable-http",
            "url": "http://localhost:8091/mcp"
        }
    }
}
```

## Usage

### Available Tools

#### `logs` - Search Datadog Logs

Search and retrieve logs from your Datadog account using the Logs API v2.

**Parameters:**
- `from` (int, required) - Start timestamp in milliseconds
- `to` (int, required) - End timestamp in milliseconds
- `query` (string, required) - Datadog search query
- `includeTags` (bool, optional) - Include tags array (default: false)
- `limit` (int, optional) - Max logs per request, 1-1000 (default: 50)
- `cursor` (string, optional) - Pagination cursor
- `sort` (string, optional) - Sort order: "timestamp" or "-timestamp"

**Example Query:**
```
service:api-gateway env:production status:error
```

**Usage Examples:**

1. Get recent error logs:
```javascript
{
  "from": 1733846400000,  // Last 24 hours
  "to": 1733932800000,
  "query": "status:error env:production"
}
```

2. Search specific service:
```javascript
{
  "from": 1733846400000,
  "to": 1733932800000,
  "query": "service:api-gateway env:production",
  "limit": 100,
  "includeTags": false
}
```

3. Paginate through results:
```javascript
// First request
{
  "from": 1733846400000,
  "to": 1733932800000,
  "query": "service:web-*",
  "limit": 1000
}
// Use cursor from response.meta.page.after for next page
```

### CLI Commands

```shell
vendor/bin/datadog-mcp list
```

## Docker

Run using the [Docker image](https://hub.docker.com/repository/docker/davidsmith3/datadog-mcp):

```shell
docker run -d -p 8091:80 \
  -e DD_API_KEY=your_api_key_here \
  -e DD_APPLICATION_KEY=your_app_key_here \
  davidsmith3/datadog-mcp:latest
```

### Environment Variables

**Required:**
- `DD_API_KEY` - Your Datadog API key (get from https://app.datadoghq.com/organization-settings/api-keys)
- `DD_APPLICATION_KEY` - Your Datadog application key

**Optional:**
- `APP_DEBUG=false` - Enable debug logging (default: false)

### Full Example with All Options

```shell
docker run -d -p 8091:80 \
  -e DD_API_KEY=your_api_key_here \
  -e DD_APPLICATION_KEY=your_app_key_here \
  -e APP_DEBUG=false \
  -v mcp-sessions:/app/storage/mcp-sessions \
  --name datadog-mcp \
  davidsmith3/datadog-mcp:latest
```

### Using Docker Compose

Create a `docker-compose.yml`:

```yaml
services:
  datadog-mcp:
    image: davidsmith3/datadog-mcp:latest
    ports:
      - "8091:80"
    environment:
      - DD_API_KEY=${DD_API_KEY}
      - DD_APPLICATION_KEY=${DD_APPLICATION_KEY}
      - APP_DEBUG=false
    volumes:
      - mcp-sessions:/app/storage/mcp-sessions
    restart: unless-stopped

volumes:
  mcp-sessions:
```

Create a `.env` file:
```bash
DD_API_KEY=your_api_key_here
DD_APPLICATION_KEY=your_app_key_here
```

Run:
```shell
docker compose up -d
```

## Contributing

See [CONTRIBUTING.md](./CONTRIBUTING.md)

## Links

- [Local Development](./LOCAL_DEVELOPMENT.md)
- [Image Development](./IMAGE_DEVELOPMENT.md)