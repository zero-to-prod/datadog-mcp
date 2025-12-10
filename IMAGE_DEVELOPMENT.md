# Image Development

## Prerequisites

- Docker with [multi-platform builds](https://docs.docker.com/build/building/multi-platform/#prerequisites) enabled

## Configuration

Set these in your `.env` file:

```dotenv
DOCKER_IMAGE=:docker_registry_username/:docker_image_name
DOCKER_IMAGE_TAG=latest
```

## Commands

```shell
# Build multi-platform image
sh dh build

# Run the image
sh dh run

# Push to Docker Hub
sh dh push
```