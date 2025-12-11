<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';

use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use Mcp\Server;
use Mcp\Server\Session\FileSessionStore;
use Mcp\Server\Transport\StreamableHttpTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\Log\AbstractLogger;

$logger = new class() extends AbstractLogger {
    public function __construct()
    {
    }

    public function log($level, string|Stringable $message, array $context = []): void
    {
        if (in_array($level, ['error', 'warning', 'critical', 'alert', 'emergency'])) {
            $timestamp = date('Y-m-d H:i:s');
            $contextStr = !empty($context) ? ' '.json_encode($context) : '';
            error_log("[{$timestamp}] [{$level}] {$message}{$contextStr}");
        }
    }
};

$sessions_dir = __DIR__.'/storage/mcp-sessions';
if (!is_dir($sessions_dir) && !mkdir($sessions_dir, 0755, true) && !is_dir($sessions_dir)) {
    throw new RuntimeException(sprintf('Directory "%s" was not created', $sessions_dir));
}

$psr17Factory = new Psr17Factory();
new SapiEmitter()->emit(
    Server::builder()
        ->setServerInfo('Datadog MCP Server', $_ENV['MCP_VERSION'] ?? '1.0.0')
        ->setDiscovery(__DIR__, ['app/Http/Controllers'])
        ->setSession(new FileSessionStore($sessions_dir))
        ->setLogger($logger)
        ->build()->run(
            new StreamableHttpTransport(
                new ServerRequestCreator($psr17Factory, $psr17Factory, $psr17Factory, $psr17Factory)->fromGlobals(),
                logger: $logger
            )
        )
);
