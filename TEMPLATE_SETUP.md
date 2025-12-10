# Template Setup Guide

This repository is a GitHub template for creating MCP (Model Context Protocol) servers in PHP.

## Quick Start

1. Click the "Use this template" button on GitHub
2. Create your new repository
3. Clone your new repository locally
4. Run the configuration script:
   ```bash
   php configure.php
   ```
5. Follow the prompts to customize your MCP server
6. Review the changes: `git diff`
7. Install dependencies: `composer install`
8. Run tests: `sh dock test`
9. Commit your changes: `git add . && git commit -m "Configure from template"`

## What Gets Configured

The configuration script will replace placeholders throughout the repository with your custom values:

### Package Identity
- **Composer Package Name**: Your vendor and package name (e.g., `acme-corp/my-mcp-server`)
- **Package Description**: A one-line description of what your MCP server does
- **CLI Binary Name**: The name of the command-line executable (in `bin/` directory)
- **PSR-4 Namespace**: The root PHP namespace for your classes (default: `App`)
- **MCP Server Name**: The display name shown to MCP clients
- **Server Version**: Initial version number (default: `1.0.0`)

### Author Information
- **Name and Email**: Your name and contact email
- **GitHub Username**: Your GitHub username for sponsorship links
- **Homepage**: Your personal website (optional)

### Docker Configuration
- **Registry Username**: Your Docker Hub or container registry username
- **Image Name**: The name for your Docker image
- **Image Tags**: Version tags for the Docker image

### GitHub Integration
- **Repository URLs**: Updates all badge URLs, issue templates, and documentation links
- **CI/CD Workflows**: Configures GitHub Actions for your repository
- **Funding Configuration**: Sets up GitHub Sponsors integration

## Files Modified by Configuration

The script automatically updates:

- `composer.json` - Package metadata, dependencies, and autoloading
- `README.md` - Documentation, badges, and usage examples
- `app/Http/Controllers/McpController.php` - MCP server information
- `LICENSE.md` - Copyright holder information
- `.env.example` - Docker environment defaults
- `.github/workflows/build_docker_image.yml` - Docker CI/CD pipeline
- `.github/FUNDING.yml` - GitHub Sponsors configuration
- `IMAGE_DEVELOPMENT.md` - Docker development documentation
- `bin/[binary]` - CLI executable name

## Manual Customization

After running the configuration script, you should customize these areas for your specific use case:

### 1. Implement Your MCP Tools

Replace the example calculator in `app/Http/Controllers/CalculatorElements.php` with your own MCP tools:

```php
<?php

namespace App\Http\Controllers;

use Mcp\Capability\Attribute\McpTool;

class MyCustomTools
{
    /**
     * Example MCP tool implementation.
     */
    #[McpTool(description: "Process data and return a result")]
    public function process(string $input): string
    {
        // Your implementation here
        return "Processed: " . $input;
    }
}
```

The MCP server will automatically discover and expose any methods marked with the `#[McpTool]` attribute.

### 2. Update Routes (Optional)

If you need custom HTTP routes beyond the default MCP endpoints, modify `routes/routes.php`:

```php
<?php

use App\Http\Support\RequestMethod;

return [
    [RequestMethod::Get, '/', \App\Http\Controllers\IndexController::class, 'index'],
    // Add your custom routes here
];
```

### 3. Customize Tests

Update the test suite in `tests/` to cover your MCP tools and business logic:

```php
<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class MyToolsTest extends TestCase
{
    public function test_example(): void
    {
        $this->assertTrue(true);
    }
}
```

### 4. Update Documentation

Tailor the README.md sections to describe your specific MCP server:
- What it does and why
- Configuration options unique to your implementation
- Usage examples specific to your tools
- Any special requirements or dependencies

### 5. Configure Docker (Optional)

Adjust `Dockerfile` and `docker-compose.yml` if you need:
- Additional PHP extensions
- Custom environment variables
- Different port mappings
- Volume mounts for your data

## Architecture Overview

This template provides a complete MCP server infrastructure:

### Core Components

- **MCP Server**: HTTP-based server using `mcp/sdk` for Model Context Protocol
- **HTTP Framework**: Lightweight routing with `zero-to-prod/http-router`
- **Dependency Injection**: Container management for service resolution
- **Session Storage**: File-based session persistence for MCP clients
- **FrankenPHP**: Modern PHP runtime with HTTP/2 and HTTP/3 support

### Project Structure

```
your-mcp-server/
├── app/
│   ├── Helpers/          # Helper functions and utilities
│   └── Http/
│       ├── Controllers/  # MCP tool controllers
│       ├── Routes/       # Route constants
│       └── Support/      # HTTP support classes
├── bootstrap/
│   └── app.php          # Application bootstrap
├── public/
│   └── index.php        # HTTP entry point
├── routes/
│   └── routes.php       # Route definitions
├── storage/
│   ├── mcp-sessions/    # MCP session files
│   └── cache/           # Application cache
├── tests/               # PHPUnit test suite
├── bin/                 # CLI executable
├── docker/              # Docker configurations
└── Dockerfile           # Production image
```

### How It Works

1. **HTTP Request** arrives at `public/index.php`
2. **Router** matches the request to a controller action
3. **MCP Controller** handles MCP protocol messages
4. **Tool Discovery** finds all methods with `#[McpTool]` attributes
5. **Session Management** persists client sessions to `storage/mcp-sessions/`
6. **Response** is sent back to the MCP client (Claude, etc.)

## Development Workflow

### Local Development with Docker

```bash
# Initialize development environment
sh dock init

# Run tests
sh dock test

# Install dependencies
sh dock composer install

# Start development server
docker-compose up
```

See [LOCAL_DEVELOPMENT.md](./LOCAL_DEVELOPMENT.md) for detailed local setup instructions.

### Docker Image Development

```bash
# Build multi-platform image
sh dh build

# Test the image locally
sh dh run

# Push to Docker Hub
sh dh push
```

See [IMAGE_DEVELOPMENT.md](./IMAGE_DEVELOPMENT.md) for Docker image development details.

## Example: Creating a Custom MCP Tool

Here's a complete example of adding a new MCP tool to your server:

```php
<?php

namespace App\Http\Controllers;

use Mcp\Capability\Attribute\McpTool;

class DataProcessingTools
{
    #[McpTool(description: "Convert text to uppercase")]
    public function toUppercase(string $text): string
    {
        return strtoupper($text);
    }

    #[McpTool(description: "Count words in text")]
    public function countWords(string $text): int
    {
        return str_word_count($text);
    }

    #[McpTool(description: "Reverse a string")]
    public function reverseString(string $text): string
    {
        return strrev($text);
    }
}
```

The MCP server will automatically discover these tools and make them available to MCP clients like Claude.

## Cleanup After Configuration

Once you've successfully configured and tested your MCP server, you can optionally remove the template-specific files:

```bash
# Remove template configuration files
rm configure.php config TEMPLATE_SETUP.md TEMPLATE_TESTING.md

# Remove or customize the GitHub Actions template cleanup workflow
rm .github/workflows/template-cleanup.yml

# Commit the cleanup
git add .
git commit -m "Remove template files"
```

## Configuration Reference

### Environment Variables

- `MCP_DEBUG` - Enable debug logging (default: `false`)
- `PHP_VERSION` - PHP version for Docker (default: `8.4`)
- `DOCKER_IMAGE` - Full Docker image name with registry
- `DOCKER_IMAGE_TAG` - Docker image tag (default: `latest`)

### Composer Scripts

The `composer.json` file doesn't include custom scripts by default, but you can add them:

```json
{
  "scripts": {
    "test": "phpunit",
    "test:coverage": "phpunit --coverage-html coverage"
  }
}
```

### Helper Scripts

- `dock` - Local development helper (init, test, composer, shell)
- `dh` - Docker image management helper (build, run, push)

## Troubleshooting

### Configuration Issues

**Q: The configure script doesn't find all files**
A: Make sure you're running it from the repository root and that vendor/ and .git/ are present.

**Q: Placeholders remain after configuration**
A: Check that you ran `php configure.php` and confirmed the changes. Some files may need manual updates.

### Runtime Issues

**Q: MCP tools not discovered**
A: Ensure your tool methods have the `#[McpTool]` attribute and the class is in `app/Http/Controllers/`.

**Q: Session errors**
A: Check that `storage/mcp-sessions/` exists and is writable (permissions 755).

**Q: Docker build fails**
A: Verify PHP 8.4+ compatibility of all dependencies and check Dockerfile syntax.

## Need Help?

- Check the [MCP SDK documentation](https://github.com/modelcontextprotocol/php-sdk)
- Review [LOCAL_DEVELOPMENT.md](./LOCAL_DEVELOPMENT.md) for dev setup
- See [IMAGE_DEVELOPMENT.md](./IMAGE_DEVELOPMENT.md) for Docker builds
- Open an issue on your repository if you encounter problems

## Additional Resources

- [Model Context Protocol Specification](https://spec.modelcontextprotocol.io/)
- [PHP MCP SDK](https://github.com/modelcontextprotocol/php-sdk)
- [FrankenPHP Documentation](https://frankenphp.dev/)
- [Composer Documentation](https://getcomposer.org/doc/)
- [PHPUnit Documentation](https://phpunit.de/documentation.html)
