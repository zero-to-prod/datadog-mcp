# datadog-mcp

![](art/logo.png)

[![Repo](https://img.shields.io/badge/github-gray?logo=github)](https://github.com/davidsmith3/datadog-mcp)
[![GitHub Actions Workflow Status](https://img.shields.io/github/actions/workflow/status/davidsmith3/datadog-mcp/test.yml?label=test)](https://github.com/davidsmith3/datadog-mcp/actions)
[![GitHub Actions Workflow Status](https://img.shields.io/github/actions/workflow/status/davidsmith3/datadog-mcp/backwards_compatibility.yml?label=backwards_compatibility)](https://github.com/davidsmith3/datadog-mcp/actions)
[![GitHub Actions Workflow Status](https://img.shields.io/github/actions/workflow/status/davidsmith3/datadog-mcp/build_docker_image.yml?label=build_docker_image)](https://github.com/davidsmith3/datadog-mcp/actions)
[![GitHub License](https://img.shields.io/badge/license-MIT-blue?style=flat-square)](https://github.com/zero-to-prod/datadog-mcp/blob/main/LICENSE.md)
[![wakatime](https://wakatime.com/badge/github/davidsmith3/datadog-mcp.svg)](https://wakatime.com/badge/github/davidsmith3/datadog-mcp)
[![Hits-of-Code](https://hitsofcode.com/github/davidsmith3/datadog-mcp?branch=main)](https://hitsofcode.com/github/davidsmith3/datadog-mcp/view?branch=main)

## Contents

- [Introduction](#introduction)
- [Requirements](#requirements)
- [Installation](#installation)
- [Quick Start](#quick-start)
- [Usage](#usage)
- [Docker Image](#docker)
- [Local Development](./LOCAL_DEVELOPMENT.md)
- [Image Development](./IMAGE_DEVELOPMENT.md)
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

Run the Docker image:

```shell
docker run -d -p 8091:80 \
  -e MCP_DEBUG=true \
  davidsmith3/datadog-mcp:latest
```

Add the server to Claude:

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

```shell
vendor/bin/datadog-mcp list
```

## Docker

Run using the [Docker image](https://hub.docker.com/repository/docker/davidsmith3/datadog-mcp):

```shell
docker run -d -p 8091:80 davidsmith3/datadog-mcp:latest
```

### Environment Variables

- `MCP_DEBUG=false` - Enable debug mode

Example:

```shell
docker run -d -p 8091:80 \
  -e MCP_DEBUG=true \
  davidsmith3/datadog-mcp:latest
```

### Persistent Sessions

```shell
docker run -d -p 8091:80 \
  -v mcp-sessions:/app/storage/mcp-sessions \
  davidsmith3/datadog-mcp:latest
```

## Contributing

See [CONTRIBUTING.md](./CONTRIBUTING.md)

## Links

- [Local Development](./LOCAL_DEVELOPMENT.md)
- [Image Development](./IMAGE_DEVELOPMENT.md)