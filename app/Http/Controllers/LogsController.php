<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;
use RuntimeException;

/**
 * Datadog Logs Search API Tools
 *
 * This controller provides MCP tools for interacting with the Datadog Logs API v2.
 * See: https://docs.datadoghq.com/api/latest/logs/
 */
class LogsController
{
    #[McpTool(
        name: 'logs',
        description: <<<TEXT
            Searches Datadog logs with comprehensive filtering and pagination support.
            This tool retrieves logs from your Datadog account using the Logs API v2. It supports time-based filtering,
            full-text search queries, pagination, and optional tag filtering. The API uses cursor-based pagination for
            efficient traversal of large result sets.

            FILTERING BEHAVIOR:
            - Time range (from/to) uses epoch timestamps in milliseconds
            - Query parameter supports full Datadog log search syntax including:
              * Service filters: service:my-service
              * Environment filters: env:production
              * Status filters: status:error
              * Free-text search: "error message"
              * Boolean operators: AND, OR, NOT
              * Wildcards: service:web-*
            - Tags are excluded by default to reduce payload size (can be large)
            - Results are limited to 1000 logs per request (API maximum)

            PAGINATION:
            - Uses cursor-based pagination (not offset-based)
            - Initial request: omit cursor parameter
            - Subsequent requests: use cursor value from previous response's meta.page.after
            - When meta.page.after is null or empty, you've reached the last page

            COMMON PATTERNS:
            - Error dashboard: from: last_hour, query: "status:error", limit: 100
            - Service health: from: last_15min, query: "service:my-service env:prod"
            - Incident investigation: from: incident_time-30m, to: incident_time+30m, query: "service:affected-service"
            - Bulk export: Use limit: 1000 and pagination to retrieve all matching logs
            - Quick search: Use limit: 50 and includeTags: false for fastest responses
            
            WARNING:
            - The responses from this tool can be very large. 
            - Keep your requests focused and use pagination to manage the data.
            - Avoid broad queries that return large result sets.
            - Be judicious in your use of this tool.
            TEXT,
        annotations: new ToolAnnotations(
            title: 'Datadog Logs Search',
            readOnlyHint: true
        )
    )]
    public function logs(
        #[Schema(
            type: 'integer',
            description: <<<TEXT
                Start timestamp in milliseconds (epoch time). Required.
                Defines the minimum timestamp for logs to retrieve.
                Example: 1764696580317 (represents 2025-01-02 12:03:00 UTC)
                Tip: Generate with: (new DateTime('2025-01-02 12:03:00'))->getTimestamp() * 1000
                TEXT,
            minimum: 0
        )]
        int $from,
        #[Schema(
            type: 'integer',
            description: <<<TEXT
                End timestamp in milliseconds (epoch time). Required.
                Defines the maximum timestamp for logs to retrieve. Must be greater than \$from parameter.
                Example: 1765301380317 (represents 2025-01-09 12:03:00 UTC)
                Maximum time range: Limited by your Datadog plan (typically 15 minutes to 7 days)
                TEXT,
            minimum: 0
        )]
        int $to,
        #[Schema(
            type: 'string',
            description: <<<TEXT
                Log search query using Datadog search syntax. Required.
                Supports complex queries with multiple filters and operators.
                Common patterns:
                  - Single service: "service:api-gateway"
                  - Service + environment: "service:api-gateway env:production"
                  - Error logs: "status:error service:payment-processor"
                  - Text search: "service:api env:prod \"database timeout\""
                  - Multiple services: "service:(api-gateway OR payment-processor)"
                  - Wildcard: "service:web-* env:production"
                Case-insensitive for most fields. Quoted strings for exact phrase matching.
                TEXT,
            minLength: 1
        )]
        string $query,
        #[Schema(
            type: 'boolean',
            description: <<<TEXT
                Whether to include the tags array in log entries. Optional, defaults to false.
                Tags can be very large (100+ items per log) and increase response size significantly.
                Set to false (default): Strips tags array from each log entry for faster responses
                Set to true: Includes full tags array with all log metadata
                Recommendation: Use false unless you specifically need tag analysis
                TEXT
        )]
        ?bool $includeTags = false,
        #[Schema(
            type: 'integer',
            description: <<<TEXT
                Maximum number of logs to return per request. Optional.
                Default: 50 (if not specified)
                Maximum: 1000 (API enforced limit)
                Use smaller values (10-50) for faster responses
                Use larger values (100-1000) to reduce number of pagination requests
                Example: 100, 500, 1000
                TEXT,
            minimum: 1,
            maximum: 1000
        )]
        int $limit = 50,
        #[Schema(
            type: 'string',
            description: <<<TEXT
                Pagination cursor for retrieving next page of results. Optional.
                Leave null/empty for the first request.
                For subsequent pages: use the value from previous response's meta.page.after field.
                When cursor is provided, continues from where the previous request ended.
                Cursor expires after a short time period (typically 1-5 minutes).
                Example: "eyJhZnRlciI6IkFRQUFBWE1rLWc4d..." (base64-encoded string)
                TEXT,
            minLength: 1
        )]
        ?string $cursor = null,
        #[Schema(
            type: 'string',
            description: <<<TEXT
                Sort order for results. Optional.
                Valid values:
                  - "timestamp" or "timestamp:asc": Oldest logs first (ascending by timestamp)
                  - "-timestamp" or "timestamp:desc": Newest logs first (descending, default)
                Default: "-timestamp" (newest first)
                Note: Only timestamp-based sorting is supported by Logs API v2
                TEXT,
            enum: ['timestamp', '-timestamp', 'timestamp:asc', 'timestamp:desc']
        )]
        ?string $sort = null
    ): array {
        if ($from >= $to) {
            throw new RuntimeException('Parameter "from" must be less than "to"');
        }

        if ($limit !== null && ($limit < 1 || $limit > 1000)) {
            throw new RuntimeException('Parameter "limit" must be between 1 and 1000');
        }

        if ($sort !== null && !in_array($sort, ['timestamp', '-timestamp', 'timestamp:asc', 'timestamp:desc'], true)) {
            throw new RuntimeException('Parameter "sort" must be "timestamp", "-timestamp", "timestamp:asc", or "timestamp:desc"');
        }

        $body = [
            'filter' => [
                'from' => $from,
                'to' => $to,
                'query' => $query,
            ],
        ];

        $page_params = array_filter([
            'limit' => $limit,
            'cursor' => $cursor,
        ], static fn($v) => $v !== null);

        if (!empty($page_params)) {
            $body['page'] = $page_params;
        }

        if ($sort !== null) {
            $body['sort'] = $sort;
        }

        $url = 'https://api.datadoghq.com/api/v2/logs/events/search';

        return $this->filterTags($this->response($url, $body), $includeTags ?? false);
    }

    /**
     * Makes HTTP POST request to Datadog API.
     *
     * @param  string  $url
     * @param  array   $body
     *
     * @return array|mixed
     */
    protected function response(string $url, array $body): mixed
    {
        $api_key = $_ENV['DD_API_KEY'] ?? throw new RuntimeException('DD_API_KEY environment variable is not set');
        $app_key = $_ENV['DD_APPLICATION_KEY'] ?? throw new RuntimeException('DD_APPLICATION_KEY environment variable is not set');

        $json_body = json_encode($body);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json_body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'DD-API-KEY: '.$api_key,
            'DD-APPLICATION-KEY: '.$app_key,
            'Accept: application/json',
            'User-Agent: Datadog-MCP/1.0',
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException('cURL request failed: '.$curl_error);
        }

        if ($http_code !== 200) {
            $error_message = sprintf('Datadog API returned HTTP %d', $http_code);

            $error_body = json_decode($response, true);
            if (isset($error_body['errors']) && is_array($error_body['errors'])) {
                $error_details = implode(
                    '; ',
                    array_map(
                        static fn($error) => $error['detail'] ?? $error['title'] ?? 'Unknown error',
                        $error_body['errors']
                    )
                );
                $error_message .= ': '.$error_details;
            } else {
                $error_message .= ': '.$response;
            }

            throw new RuntimeException($error_message);
        }

        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Failed to decode JSON response: '.json_last_error_msg());
        }

        return $decoded ?? [];
    }

    /**
     * Filters tags array from log entries based on includeTags parameter.
     *
     * @param  array  $response
     * @param  bool   $includeTags
     *
     * @return array
     */
    protected function filterTags(array $response, bool $includeTags): array
    {
        if ($includeTags) {
            return $response;
        }

        if (isset($response['data']) && is_array($response['data'])) {
            foreach ($response['data'] as &$log) {
                if (isset($log['attributes']['tags'])) {
                    unset($log['attributes']['tags']);
                }
            }
            unset($log);
        }

        return $response;
    }
}
