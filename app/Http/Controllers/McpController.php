<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Mcp\Server;
use Mcp\Server\Session\FileSessionStore;
use Mcp\Server\Transport\StreamableHttpTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Stringable;

class McpController
{
    private function createLogger(): LoggerInterface
    {
        return new class extends AbstractLogger {
            public function log($level, string|Stringable $message, array $context = []): void
            {
                $timestamp = date('Y-m-d H:i:s');
                $contextStr = !empty($context) ? ' '.json_encode($context) : '';
                error_log("[{$timestamp}] [{$level}] {$message}{$contextStr}");
            }
        };
    }

    public function post(): void
    {
        $psr17Factory = new Psr17Factory();
        $creator = new ServerRequestCreator($psr17Factory, $psr17Factory, $psr17Factory, $psr17Factory);
        $request = $creator->fromGlobals();

        $sessions_dir = __DIR__.'/../../../storage/mcp-sessions';
        if (!is_dir($sessions_dir) && !mkdir($sessions_dir, 0755, true) && !is_dir($sessions_dir)) {
            throw new RuntimeException(sprintf('Directory "%s" was not created', $sessions_dir));
        }

        $builder = Server::builder()
            ->setServerInfo(':server_name', ':server_version')
            ->setDiscovery(__DIR__.'/../../..', ['/app/Http/Controllers'])
            ->setSession(new FileSessionStore($sessions_dir));

        if (($_ENV['MCP_DEBUG'] ?? 'false') === 'true') {
            $builder->setLogger($this->createLogger());
        }

        $server = $builder->build();
        $transport = new StreamableHttpTransport($request);
        $response = $server->run($transport);

        http_response_code($response->getStatusCode());

        if ($response->getStatusCode() < 400) {
            header('Cache-Control: no-cache, private');
            header('Vary: Mcp-Session-Id, Accept-Encoding');
        } else {
            header('Cache-Control: no-store, must-revalidate');
            header('Pragma: no-cache');
        }

        header('Content-Type: application/json; charset=utf-8');

        foreach ($response->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                header(sprintf('%s: %s', $name, $value), false);
            }
        }

        $body = $response->getBody();
        $bodyString = (string)$body;

        if (extension_loaded('zlib') && (str_contains($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '', 'gzip'))) {
            if (!headers_sent()) {
                header('Content-Encoding: gzip');
                echo gzencode($bodyString, 6);
            } else {
                echo $bodyString;
            }
        } else {
            echo $bodyString;
        }
    }

    public function options(): void
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Mcp-Session-Id, Mcp-Protocol-Version, Last-Event-ID, Authorization, Accept');
        header('Access-Control-Max-Age: 86400');
        http_response_code(204);
    }
}