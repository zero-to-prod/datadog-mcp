# Template Testing Guide

This document outlines the testing procedures for validating the MCP server template before release.

## Pre-Release Checklist

Before making this template publicly available, verify that all hardcoded references have been replaced with placeholders.

### 1. Search for Hardcoded References

Run these commands to find any remaining hardcoded values:

```bash
# Search for the original vendor name
grep -r "zero-to-prod" --exclude-dir={vendor,.git,node_modules} --exclude="*.md" .

# Search for Docker username
grep -r "davidsmith3" --exclude-dir={vendor,.git,node_modules} .

# Search for Cronitor references (case-insensitive)
grep -ri "cronitor" --exclude-dir={vendor,.git,node_modules} --exclude="TEMPLATE_TESTING.md" .

# Search for author email
grep -r "dave0016@gmail.com" --exclude-dir={vendor,.git,node_modules} .

# Search for author name
grep -r "David Smith" --exclude-dir={vendor,.git,node_modules} --exclude="TEMPLATE_TESTING.md" .
```

Expected results:
- These searches should only return matches in documentation files that explain what will be replaced
- No matches should appear in code files (PHP, YAML, JSON) except as placeholder values

### 2. Verify Placeholder Format

Ensure all placeholders use the correct colon-prefixed format:

```bash
# List all placeholders used
grep -roh ":[a-z_]*" --exclude-dir={vendor,.git,node_modules} . | sort | uniq

# Expected placeholders:
# :vendor_name
# :package_name
# :package_slug
# :package_description
# :package_classname
# :server_name
# :server_version
# :author_name
# :author_email
# :author_username
# :author_homepage
# :docker_registry_username
# :docker_image_name
# :github_org
# :github_repo
```

### 3. Test Configuration Script

#### Syntax Check

```bash
# Verify PHP syntax
php -l configure.php
```

#### Dry Run Test

Create a test directory to verify the configuration script:

```bash
# Clone to test directory
git clone /path/to/template /tmp/mcp-template-test
cd /tmp/mcp-template-test

# Run configuration with test values
php configure.php <<EOF
test-vendor
test-server
test-server
Test MCP Server
TestApp
Test Server
1.0.0
Test Author
test@example.com
testuser
https://example.com
testuser
test-image
testuser
test-server
y
EOF

# Verify placeholders were replaced
grep -r ":vendor_name" --exclude-dir=vendor . && echo "FAIL: Placeholders remain" || echo "PASS: No placeholders found"
```

### 4. Validate Generated Files

After running the configuration script with test data:

```bash
# Validate composer.json structure
composer validate

# Check that composer.json has correct values
grep -q "test-vendor/test-server" composer.json && echo "PASS" || echo "FAIL"

# Verify binary was renamed
test -f bin/test-server && echo "PASS: Binary renamed" || echo "FAIL: Binary not found"

# Verify binary is executable
test -x bin/test-server && echo "PASS: Binary is executable" || echo "FAIL: Binary not executable"
```

## Post-Configuration Testing

After configuration is complete, verify the project works correctly.

### 1. Dependency Installation

```bash
# Install dependencies
composer install

# Verify no errors
echo $? # Should output 0
```

### 2. Static Analysis

```bash
# Check autoload is working
composer dump-autoload

# Verify PSR-4 autoload
php -r "require 'vendor/autoload.php'; var_dump(class_exists('App\Http\Controllers\McpController'));"
# Should output: bool(true)
```

### 3. Run Test Suite

```bash
# Run PHPUnit tests
vendor/bin/phpunit

# Or using dock helper
sh dock test
```

Expected: All tests should pass.

### 4. Test CLI Binary

```bash
# Test help command
vendor/bin/test-server help

# Test version command
vendor/bin/test-server version

# Test list command
vendor/bin/test-server list
```

Expected: All commands should execute without errors and display appropriate output.

### 5. Docker Build Test

```bash
# Build the Docker image
docker build -t test-server:test .

# Verify build succeeded
docker images | grep test-server

# Run the container
docker run -d -p 8080:80 --name test-server test-server:test

# Check container is running
docker ps | grep test-server

# Test HTTP endpoint
curl http://localhost:8080/

# Stop and remove container
docker stop test-server && docker rm test-server
```

Expected: Docker image builds successfully and runs without errors.

### 6. GitHub Actions Validation

```bash
# Lint workflow files
yamllint .github/workflows/*.yml

# Or manually check syntax
for file in .github/workflows/*.yml; do
  echo "Checking $file"
  cat "$file" | python3 -c "import yaml, sys; yaml.safe_load(sys.stdin)" && echo "✓ Valid" || echo "✗ Invalid"
done
```

Expected: All workflow files should have valid YAML syntax.

## Template Repository Testing

Test the actual "Use this template" workflow on GitHub.

### 1. Create Test Repository from Template

1. Go to the template repository on GitHub
2. Click "Use this template"
3. Create a new test repository (e.g., `mcp-template-test`)
4. Clone the new repository locally

### 2. Verify Template Setup

```bash
# Clone your test repository
git clone https://github.com/your-username/mcp-template-test.git
cd mcp-template-test

# Check that template files are present
test -f configure.php && echo "✓ configure.php exists" || echo "✗ Missing"
test -f TEMPLATE_SETUP.md && echo "✓ TEMPLATE_SETUP.md exists" || echo "✗ Missing"
test -f TEMPLATE_TESTING.md && echo "✓ TEMPLATE_TESTING.md exists" || echo "✗ Missing"

# Verify placeholders are present
grep -q ":vendor_name" composer.json && echo "✓ Placeholders present" || echo "✗ Already configured"
```

### 3. Run Configuration

```bash
# Run the configure script
php configure.php

# Follow prompts and enter test data
# Verify all prompts appear correctly
# Check configuration summary
```

### 4. Test GitHub Actions

```bash
# Push changes to trigger workflows
git add .
git commit -m "Test configuration"
git push

# Check GitHub Actions:
# 1. Go to repository on GitHub
# 2. Click "Actions" tab
# 3. Verify template-cleanup workflow ran
# 4. Check if reminder issue was created (if not configured)
```

### 5. Verify Final State

```bash
# Check all placeholders replaced
grep -r ":" composer.json | grep -E ":vendor_name|:package_name" && echo "FAIL" || echo "PASS"

# Verify project works
composer install
vendor/bin/phpunit
docker build -t test .
```

## Regression Testing

After any changes to the template, run this quick validation:

```bash
# Quick validation script
#!/bin/bash

echo "=== Template Validation ==="

# 1. Check for hardcoded values
echo "Checking for hardcoded values..."
if grep -rq "zero-to-prod\|davidsmith3\|dave0016@gmail.com" --exclude-dir={vendor,.git} --exclude="*.md" .; then
    echo "✗ Found hardcoded values"
    exit 1
else
    echo "✓ No hardcoded values"
fi

# 2. Verify configure.php syntax
echo "Checking configure.php syntax..."
if php -l configure.php > /dev/null 2>&1; then
    echo "✓ configure.php syntax valid"
else
    echo "✗ configure.php syntax error"
    exit 1
fi

# 3. Verify placeholder format
echo "Checking placeholder format..."
PLACEHOLDERS=$(grep -roh ":[a-z_]*" --exclude-dir={vendor,.git} . | sort | uniq)
echo "Found placeholders:"
echo "$PLACEHOLDERS"

echo ""
echo "=== Validation Complete ==="
```

Save as `validate-template.sh`, make executable, and run before each release.

## Common Issues and Solutions

### Issue: Placeholders remain after configuration

**Solution:**
- Check that files weren't excluded from replacement in configure.php
- Verify the file isn't in vendor/ or .git/
- Manually update the file with correct values

### Issue: Binary file not found after configuration

**Solution:**
```bash
# Check if binary exists in bin/
ls -la bin/

# If not renamed, manually rename:
mv bin/mcp-server bin/your-package-slug

# Verify it's executable:
chmod +x bin/your-package-slug
```

### Issue: Composer validation fails

**Solution:**
```bash
# Check composer.json for syntax errors
composer validate

# Common issues:
# - Missing comma in JSON
# - Invalid package name format (must be vendor/package)
# - Empty author_homepage placeholder (use empty string "" if not provided)
```

### Issue: Docker build fails

**Solution:**
```bash
# Check Dockerfile syntax
docker build --no-cache -t test .

# Common issues:
# - Invalid placeholder in Dockerfile (shouldn't have any)
# - Missing dependencies in composer.json
# - PHP version mismatch
```

## Release Checklist

Before creating a new release of the template:

- [ ] Run all pre-release validation scripts
- [ ] Test configuration script with various inputs
- [ ] Verify Docker build succeeds
- [ ] Test "Use this template" workflow
- [ ] Confirm GitHub Actions workflows work
- [ ] Update VERSION or CHANGELOG if present
- [ ] Tag the release: `git tag -a v1.0.0 -m "Release v1.0.0"`
- [ ] Push tags: `git push --tags`

## Documentation Updates

When modifying the template, update:

- [ ] README.md - If usage instructions change
- [ ] TEMPLATE_SETUP.md - If configuration process changes
- [ ] TEMPLATE_TESTING.md - If new tests are needed
- [ ] configure.php comments - If new placeholders added
- [ ] This file - If testing procedures change
