#!/usr/bin/env php
<?php

/**
 * MCP Server Template Configuration Script
 *
 * This script configures the MCP server template by replacing placeholders
 * throughout the repository with your project-specific values.
 */

function run(string $command): string
{
    return trim((string)shell_exec($command));
}

function replace_in_file(string $file, array $replacements): void
{
    if (!file_exists($file)) {
        return;
    }

    $contents = file_get_contents($file);

    file_put_contents(
        $file,
        str_replace(
            array_keys($replacements),
            array_values($replacements),
            $contents
        )
    );
}

function ask(string $question, string $default = ''): string
{
    $answer = readline($question.($default ? " ($default)" : null).': ');

    if (!$answer) {
        return $default;
    }

    return $answer;
}

function confirm(string $question, bool $default = false): bool
{
    $answer = ask($question.' ('.($default ? 'Y/n' : 'y/N').')');

    if (!$answer) {
        return $default;
    }

    return strtolower($answer) === 'y';
}

function writeln(string $line): void
{
    echo $line.PHP_EOL;
}

function replaceForWindows(): array
{
    return preg_split('/\\r\\n|\\r|\\n/', run('dir /S /B /A-D-H * | findstr /v /i .git\\ | findstr /v /i vendor | findstr /v /i '.basename(__FILE__).' | findstr /r /i /M /F:/ \":vendor_name :package_name :package_slug :package_description :package_classname :server_name :author_name :author_email :author_username :docker_registry_username :docker_image_name :github_org :github_repo\"'));
}

function replaceForAllOtherOSes(): array
{
    return explode(PHP_EOL, run('grep -r -l -i ":vendor_name\|:package_name\|:package_slug\|:package_description\|:package_classname\|:server_name\|:author_name\|:author_email\|:author_username\|:docker_registry_username\|:docker_image_name\|:github_org\|:github_repo" --exclude-dir=vendor --exclude-dir=.git --exclude="'.basename(__FILE__).'" .'));
}

function determineSeparator(string $path): string
{
    return str_replace('/', DIRECTORY_SEPARATOR, $path);
}

writeln('');
writeln('===========================================');
writeln('   MCP Server Template Configuration');
writeln('===========================================');
writeln('');
writeln('This script will help you customize this MCP server template.');
writeln('');

// Gather Package Identity
writeln('--- Package Identity ---');
$vendor_name = ask('Composer vendor name (e.g., acme-corp)', 'your-vendor');
$package_name = ask('Package name (e.g., my-mcp-server)', 'mcp-server');
$package_slug = ask('CLI binary name', $package_name);
$package_description = ask('Package description', 'A Model Context Protocol Server');
$package_classname = ask('PSR-4 namespace prefix', 'App');
$server_name = ask('MCP Server display name', ucwords(str_replace(['-', '_'], ' ', $package_name)) . ' MCP Server');
$server_version = ask('Initial version', '1.0.0');

writeln('');
writeln('--- Author Information ---');
$git_name = run('git config user.name');
$git_email = run('git config user.email');
$author_name = ask('Author name', $git_name ?: 'Your Name');
$author_email = ask('Author email', $git_email ?: 'you@example.com');
$author_username = ask('GitHub username', strtolower($vendor_name));
$author_homepage = ask('Author homepage (optional)', '');

writeln('');
writeln('--- Docker Configuration ---');
$docker_registry_username = ask('Docker registry username (optional)', strtolower($vendor_name));
$docker_image_name = ask('Docker image name', $package_name);

writeln('');
writeln('--- GitHub Repository ---');
$github_org = ask('GitHub organization/username', $author_username);
$github_repo = ask('Repository name', $package_name);

// Display configuration summary
writeln('');
writeln('===========================================');
writeln('   Configuration Summary');
writeln('===========================================');
writeln("Package:     {$vendor_name}/{$package_name}");
writeln("Description: {$package_description}");
writeln("Namespace:   {$package_classname}");
writeln("Binary:      {$package_slug}");
writeln("Server:      {$server_name} v{$server_version}");
writeln('');
writeln("Author:      {$author_name} <{$author_email}>");
writeln("GitHub:      https://github.com/{$github_org}/{$github_repo}");
if ($author_homepage) {
    writeln("Homepage:    {$author_homepage}");
}
if ($docker_registry_username) {
    writeln("Docker:      {$docker_registry_username}/{$docker_image_name}");
}
writeln('===========================================');
writeln('');

if (!confirm('Apply this configuration?', true)) {
    writeln('Configuration cancelled.');
    exit(1);
}

writeln('');
writeln('Finding files to update...');

// Find files to replace (exclude vendor, .git, this script)
$files = (str_starts_with(strtoupper(PHP_OS), 'WIN') ? replaceForWindows() : replaceForAllOtherOSes());
$files = array_filter($files); // Remove empty entries

writeln('Found ' . count($files) . ' files to update.');
writeln('');

$replacements = [
    ':vendor_name' => $vendor_name,
    ':package_name' => $package_name,
    ':package_slug' => $package_slug,
    ':package_description' => $package_description,
    ':package_classname' => $package_classname,
    ':server_name' => $server_name,
    ':server_version' => $server_version,
    ':author_name' => $author_name,
    ':author_email' => $author_email,
    ':author_username' => $author_username,
    ':author_homepage' => $author_homepage,
    ':docker_registry_username' => $docker_registry_username,
    ':docker_image_name' => $docker_image_name,
    ':github_org' => $github_org,
    ':github_repo' => $github_repo,
];

// Perform replacements
foreach ($files as $file) {
    $file = trim($file);
    if ($file && file_exists($file) && !is_dir($file)) {
        replace_in_file($file, $replacements);
        writeln("✓ Updated: {$file}");
    }
}

// Handle special cases
writeln('');
writeln('Handling special file operations...');

// 1. Rename bin file if it exists
if (file_exists('bin/mcp-server')) {
    $new_bin_path = 'bin/' . $package_slug;
    rename('bin/mcp-server', $new_bin_path);
    writeln("✓ Renamed: bin/mcp-server → {$new_bin_path}");

    // Make it executable
    chmod($new_bin_path, 0755);
    writeln("✓ Made executable: {$new_bin_path}");
}

writeln('');
writeln('===========================================');
writeln('   Configuration Complete!');
writeln('===========================================');
writeln('');
writeln('Next steps:');
writeln('  1. Review the changes: git diff');
writeln('  2. Update example controller: app/Http/Controllers/CalculatorElements.php');
writeln('  3. Install dependencies: composer install');
writeln('  4. Run tests: vendor/bin/phpunit');
writeln('  5. Test Docker: docker build .');
writeln('  6. Commit your changes: git add . && git commit -m "Configure from template"');
writeln('');
writeln('Optional cleanup:');
writeln('  - Remove template files: rm configure.php config TEMPLATE_SETUP.md TEMPLATE_TESTING.md');
writeln('  - Update .github/workflows/template-cleanup.yml or remove it');
writeln('');
writeln('For more information, see TEMPLATE_SETUP.md');
writeln('');
