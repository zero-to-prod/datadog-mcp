# Local Development

## Prerequisites

- Docker

## Setup

Initialize the project:

```shell
sh dock init
```

Update dependencies:

```shell
sh dock composer update
```

## Testing

Run tests:

```shell
sh dock test
```

Test all PHP versions:

```shell
sh test.sh
```

## Configuration

Set PHP versions in `.env`:

```dotenv
PHP_VERSION=8.4
PHP_DEBUG=8.4
PHP_COMPOSER=8.4
```

If `.env` doesn't exist, run `sh dock init` to create it from `.env.example`.