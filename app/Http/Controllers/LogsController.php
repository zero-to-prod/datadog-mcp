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
            Search Datadog logs with time-based filtering, full-text queries, and pagination.

            ## Time Range Options
            **EASY MODE**: Use time_range parameter with simple strings: "1h", "24h", "7d"
            **ADVANCED**: Use from/to parameters with millisecond timestamps for precise control

            Examples with time_range:
            - time_range="1h" → Last 1 hour (recommended for most queries)
            - time_range="24h" → Last 24 hours
            - time_range="7d" → Last 7 days
            - time_range="15m" → Last 15 minutes

            ## Critical Rules
            - Reserved attributes (NO @): service, env, status, host, source, version, trace_id
            - Custom attributes (@ REQUIRED): @http.status_code, @user.id, @duration, @error.message, etc.
            - Timestamps: MUST be milliseconds for current year (multiply Unix seconds × 1000)
            - Boolean operators: UPPERCASE only (AND, OR, NOT)
            - Wildcards: * (multi-char), ? (single-char)
            - Attribute names are case-sensitive

            ## Severity Levels (status: attribute)
            Filter logs by severity using status:LEVEL (from highest to lowest priority):
            - status:emergency - System unusable, immediate action required
            - status:alert - Action must be taken immediately
            - status:critical - Critical conditions
            - status:error - Error conditions (most common for troubleshooting)
            - status:warn - Warning conditions
            - status:notice - Normal but significant condition
            - status:info - Informational messages
            - status:debug - Debug-level messages

            Combine severities: status:(error OR warn) or status:>=error

            ## Common Query Patterns
            "Show errors in production" → env:production status:error (last 1h)
            "Find slow API requests" → service:api @duration:>3000 (last 1h)
            "500 errors" → @http.status_code:>=500 (last 1h)
            "User errors" → @user.id:12345 status:error (last 24h)
            "Database issues" → service:database status:error (last 1h)
            "Payment failures yesterday" → service:payment status:error (yesterday)

            ## Syntax Quick Reference

            Reserved (no @):
            service:api-gateway | env:production | status:error | host:web-01 | source:docker | version:1.2.3

            Custom (need @):
            @http.status_code:500 | @user.email:user@example.com | @duration:>3000 | @error.message:"timeout"

            Boolean:
            service:api AND status:error
            env:prod OR env:staging
            status:error -service:health-check (exclude)
            (service:api OR service:worker) AND status:error

            Numerical:
            @http.status_code:>=500 | @duration:>5000 | @http.status_code:[400 TO 499]

            Wildcards:
            service:web-* | @user.email:*@example.com

            ## Common Attributes (all need @)
            HTTP: @http.status_code, @http.method, @http.url, @http.request_id
            User: @user.id, @user.email, @user.name, @user.country
            Performance: @duration (ms), @response_time, @db.statement.duration
            Error: @error.message, @error.kind, @error.stack, @error.code
            Transaction: @transaction.id, @transaction.amount, @transaction.status
            Deployment: @deployment.version, @deployment.canary, @container.name

            ## Query Examples by Complexity

            Basic:
            service:api status:error
            env:production status:warn
            status:error "timeout"

            With Attributes:
            service:api @http.status_code:500
            service:checkout @duration:>5000
            @user.email:*@example.com status:error

            Complex:
            (service:api OR service:worker) AND status:error
            service:payment env:prod status:error @transaction.amount:>1000
            env:prod AND -@deployment.canary:true AND @http.status_code:>=500

            ## Response Structure
            {
              "data": [{"id": "...", "attributes": {"timestamp": "...", "message": "...", "service": "..."}}],
              "meta": {"page": {"after": "cursor_or_null"}}
            }

            ## Timestamps (milliseconds)
            now = Date.now()
            1h_ago = now - 3600000
            24h_ago = now - 86400000
            7d_ago = now - 604800000
            15min_ago = now - 900000

            Conversions: 1sec=1000ms, 1min=60000ms, 1hr=3600000ms, 1day=86400000ms

            ## Pagination
            First request: cursor = null
            Next pages: cursor = response.meta.page.after
            Last page: response.meta.page.after is null
            Reuse all params (from, to, query, limit) with new cursor

            ## Workflow (Recommended)
            1. Use time_range: time_range="1h" (much easier!)
            2. Build query: "service:api status:error"
            3. Call with limit=10
            4. Check response.data[] for logs
            5. If response.meta.page.after exists, more data available

            Alternative (Advanced):
            1. Calculate timestamps: from = now() - 3600000, to = now()
            2-5. Same as above

            ## MCP Usage Notes
            YOU (the LLM) should:
            - PREFER time_range parameter ("1h", "24h", "7d") over calculating timestamps
            - Only use from/to for precise time windows (e.g., specific incident times)
            - Translate user intent to Datadog syntax
            - Summarize patterns, not raw JSON dumps
            - Present insights in human-readable format

            ## Troubleshooting Empty Results
            If query returns no data (data=[]):
            1. Wrong year? Verify timestamps are for current year
            2. Seconds not milliseconds? Ensure 13 digits (e.g., 1765461420000 not 1765461420)
            3. Time range too narrow? Try expanding from 1h → 24h → 7d
            4. Wrong query syntax? Verify @ prefix on custom attributes
            5. Service/env names wrong? Double-check spelling and case

            Timestamp validation:
            - 2025: 1735689600000 (Jan 1) to 1767225599999 (Dec 31)
            - 2024: 1704067200000 (Jan 1) to 1735689599999 (Dec 31)
            If your timestamp is outside current year range, recalculate using Date.now()

            ## Other Common Errors
            "from must be less than to" → Verify timestamp order
            "HTTP 400" → Check @ prefix on custom attrs, UPPERCASE operators, query syntax
            Wrong logs returned → Verify @ prefix usage (custom=@, reserved=no @)

            ## Performance Tips
            - Start with service/env filters to narrow scope
            - Use status:error or status:warn for issue-focused queries
            - Start with limit=10, increase if needed
            - Keep includeTags=false unless needed (tags add 100+ items per log)
            - Avoid time ranges >24h without filters

            ## Result Interpretation
            When presenting to users:
            - Count: "Found 47 errors"
            - Time range: "10:30-11:00 UTC"
            - Services: "Affected: payment-api, checkout"
            - Patterns: "Most common: Database timeout (23x)"
            - Show 2-3 sample log entries with key fields
            TEXT,
        annotations: new ToolAnnotations(
            title: 'Datadog Logs Search',
            readOnlyHint: true
        )
    )]
    public function logs(
        #[Schema(
            type: 'string',
            description: <<<TEXT
                Log search query using Datadog search syntax. Required.

                SYNTAX REFERENCE:

                Reserved Attributes (NO @ prefix):
                - service:value - Service name (e.g., service:api-gateway)
                - env:value - Environment (e.g., env:production, env:staging)
                - status:value - Log level (e.g., status:error, status:warn, status:info, status:debug)
                - host:value - Hostname (e.g., host:web-01)
                - source:value - Log source (e.g., source:docker, source:nginx)
                - version:value - App version (e.g., version:2.1.0)

                Custom Attributes (@ prefix REQUIRED):
                - @attribute:value - Any custom attribute (e.g., @http.status_code:500)
                - @nested.path:value - Nested attributes use dots (e.g., @user.profile.age:25)
                - Attribute searches are CASE-SENSITIVE

                Wildcards:
                - * matches multiple chars (e.g., service:web-* matches web-api, web-app)
                - ? matches single char (e.g., host:server-? matches server-1, server-a)

                Boolean Operators (UPPERCASE required):
                - AND - Both conditions (e.g., service:api AND status:error)
                - OR - Either condition (e.g., env:prod OR env:staging)
                - NOT or - prefix - Exclude (e.g., NOT status:debug OR -status:debug)
                - ( ) - Group conditions (e.g., (service:api OR service:worker) AND status:error)
                - Implicit AND: Spaces act as AND (service:api status:error = service:api AND status:error)

                Numerical Operators (for faceted numeric attributes):
                - < > <= >= (e.g., @http.status_code:>=500, @duration:<1000)
                - Range: [min TO max] (e.g., @http.status_code:[400 TO 499])

                Special Characters:
                - Use quotes for values with spaces or special chars: @message:"connection: timeout"
                - Or escape special chars: @message:connection\:\ timeout
                - Free-text phrases in quotes: "database connection error"

                QUERY CONSTRUCTION EXAMPLES:

                Simple queries:
                - "service:api-gateway" - All logs from api-gateway
                - "status:error" - All error logs
                - "env:production" - All production logs

                Combined filters:
                - "service:api status:error" - API errors (implicit AND)
                - "service:api AND status:error" - API errors (explicit AND)
                - "service:checkout env:prod status:warn" - Checkout warnings in production

                Boolean logic:
                - "service:api AND (status:error OR status:warn)" - API errors or warnings
                - "env:prod AND NOT service:health-check" - Production excluding health checks
                - "(service:api OR service:worker) status:error" - Errors from either service

                Custom attributes:
                - "service:api @http.status_code:500" - API with HTTP 500
                - "@user.id:12345 service:auth" - User 12345 in auth service
                - "@transaction.amount:>1000 status:error" - Failed high-value transactions

                Wildcards:
                - "service:web-* env:prod" - All web-* services in prod
                - "@user.email:*@example.com" - All example.com emails
                - "host:server-?? env:prod" - Two-char server IDs in prod

                Text search:
                - "\"database timeout\"" - Exact phrase anywhere in logs
                - "service:api \"connection refused\"" - API logs with specific error text
                - "timeout error service:payment" - Logs containing timeout OR error in payment service

                Complex real-world queries:
                - "service:payment env:prod status:error @http.status_code:>=500" - Payment server errors in prod
                - "(service:api OR service:worker) AND env:prod AND -@deployment.canary:true" - Non-canary prod errors
                - "@user.country:US service:checkout status:error \"payment declined\"" - US checkout payment failures
                - "service:database @query.duration:>5000 status:warn" - Slow database queries (>5s)

                Best practices:
                - Start with most restrictive filters (service, env) to narrow results quickly
                - Use status:error/warn to focus on issues
                - Add custom attributes (@...) for specific field filtering
                - Use quotes for exact phrase matching in free-text
                - Test with narrow time ranges first to verify query correctness
                TEXT,
            minLength: 1
        )]
        string $query,
        #[Schema(
            type: 'string',
            description: <<<TEXT
                Relative time range (alternative to from/to). Optional.
                Automatically calculates timestamps from now going back in time.
                Default: "1h" (last 1 hour) if no time parameters provided.

                Supported formats:
                - "15m" or "15min" - Last 15 minutes
                - "1h" or "1hr" - Last 1 hour (default)
                - "24h" - Last 24 hours
                - "7d" or "7day" - Last 7 days
                - "30d" - Last 30 days

                Examples: "1h", "24h", "7d"

                Note: Use either time_range OR (from + to), not both.
                TEXT,
            pattern: '^\\d+[mhdMHD](?:in|hr|ay)?$'
        )]
        ?string $time_range = '1h',
        #[Schema(
            type: 'integer',
            description: <<<TEXT
                Start timestamp in milliseconds (epoch time). Optional if time_range provided.
                Defines the minimum timestamp for logs to retrieve.
                Example: 1764696580317 (represents 2025-01-02 12:03:00 UTC)
                Tip: Generate with: (new DateTime('2025-01-02 12:03:00'))->getTimestamp() * 1000

                Note: Use either time_range OR (from + to), not both.
                TEXT,
            minimum: 0
        )]
        ?int $from = null,
        #[Schema(
            type: 'integer',
            description: <<<TEXT
                End timestamp in milliseconds (epoch time). Optional if time_range provided.
                Defines the maximum timestamp for logs to retrieve. Must be greater than \$from parameter.
                Example: 1765301380317 (represents 2025-01-09 12:03:00 UTC)
                Maximum time range: Limited by your Datadog plan (typically 15 minutes to 7 days)

                Note: Use either time_range OR (from + to), not both.
                TEXT,
            minimum: 0
        )]
        ?int $to = null,
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
                Default: 10 (if not specified)
                Maximum: 1000 (API enforced limit)
                Use smaller values (10-20) for faster responses
                Use larger values (100-1000) to reduce number of pagination requests
                Example: 10, 50, 1000
                TEXT,
            minimum: 1,
            maximum: 1000
        )]
        int $limit = 10,
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
        ?string $sort = null,
        #[Schema(
            type: 'string',
            description: <<<TEXT
                Output format. Optional.
                Determines what data is returned in the response.

                - "full" (default): Returns complete log entries with all attributes
                - "count": Returns only the total count of matching logs (no log data)
                  Perfect for: dashboards, health checks, "how many errors?" queries
                  Response: {"count": 47, "query": "...", "time_range": "..."}

                - "summary": Returns aggregated statistics without individual log entries
                  Includes: count, time range, top services, top error messages
                  Response: {"count": ..., "services": {...}, "top_errors": [...]}

                Use "count" when you only need volume metrics.
                Use "summary" for quick insights without full log data.
                Use "full" when you need to analyze individual log entries.
                TEXT,
            enum: ['full', 'count', 'summary']
        )]
        ?string $format = 'full'
    ): array {
        // Ensure time_range has a default value (handle null from MCP)
        $time_range = $time_range ?? '1h';

        // Validate parameter combinations
        $using_from_to = $from !== null || $to !== null;
        $using_time_range = $time_range !== '1h' || !$using_from_to;  // Using non-default time_range or default when from/to not provided

        if ($using_from_to && $time_range !== '1h') {
            throw new RuntimeException('Cannot use both time_range and from/to parameters. Use either time_range OR (from + to).');
        }

        // If using from/to, validate both are provided
        if ($using_from_to) {
            if ($from === null || $to === null) {
                throw new RuntimeException('Must provide both from and to parameters when using explicit timestamps.');
            }
            // Don't parse time_range when using from/to
        } else {
            // Parse time_range (defaults to '1h')
            [$from, $to] = $this->parseTimeRange($time_range);
        }

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

        $response = $this->response($url, $body);

        // Handle different output formats
        if ($format === 'count') {
            return $this->formatCount($response, $query, $time_range, $from, $to);
        }

        if ($format === 'summary') {
            return $this->formatSummary($response, $query, $time_range, $from, $to);
        }

        // Default: full format
        return $this->filterTags($response, $includeTags ?? false);
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
     * Parses relative time range string and returns [from, to] timestamps in milliseconds.
     *
     * @param  string  $time_range
     *
     * @return array{int, int}
     */
    protected function parseTimeRange(string $time_range): array
    {
        // Parse the time range format: digits + unit (m/h/d) + optional suffix (in/hr/ay)
        if (!preg_match('/^(\d+)([mhdMHD])(?:in|hr|ay)?$/', $time_range, $matches)) {
            throw new RuntimeException('Invalid time_range format. Expected format: "1h", "24h", "7d", "15m", etc.');
        }

        $value = (int) $matches[1];
        $unit = strtolower($matches[2]);

        // Convert to milliseconds
        $milliseconds_map = [
            'm' => 60_000,      // 1 minute = 60,000ms
            'h' => 3_600_000,   // 1 hour = 3,600,000ms
            'd' => 86_400_000,  // 1 day = 86,400,000ms
        ];

        if (!isset($milliseconds_map[$unit])) {
            throw new RuntimeException('Invalid time unit. Supported units: m (minutes), h (hours), d (days)');
        }

        $offset_ms = $value * $milliseconds_map[$unit];

        // Calculate timestamps: from = now - offset, to = now
        $now_ms = (int) (microtime(true) * 1000);
        $from_ms = $now_ms - $offset_ms;

        return [$from_ms, $now_ms];
    }

    /**
     * Formats response as count only.
     *
     * @param  array  $response
     * @param  string  $query
     * @param  string  $time_range
     * @param  int  $from
     * @param  int  $to
     *
     * @return array
     */
    protected function formatCount(array $response, string $query, string $time_range, int $from, int $to): array
    {
        $count = isset($response['data']) && is_array($response['data']) ? count($response['data']) : 0;

        return [
            'format' => 'count',
            'count' => $count,
            'query' => $query,
            'time_range' => $time_range,
            'from_ms' => $from,
            'to_ms' => $to,
            'has_more' => isset($response['meta']['page']['after']) && $response['meta']['page']['after'] !== null,
        ];
    }

    /**
     * Formats response as summary with aggregated statistics.
     *
     * @param  array  $response
     * @param  string  $query
     * @param  string  $time_range
     * @param  int  $from
     * @param  int  $to
     *
     * @return array
     */
    protected function formatSummary(array $response, string $query, string $time_range, int $from, int $to): array
    {
        $count = isset($response['data']) && is_array($response['data']) ? count($response['data']) : 0;

        // Aggregate statistics from log entries
        $services = [];
        $statuses = [];
        $messages = [];

        if (isset($response['data']) && is_array($response['data'])) {
            foreach ($response['data'] as $log) {
                $attrs = $log['attributes'] ?? [];

                // Count services
                if (isset($attrs['service'])) {
                    $services[$attrs['service']] = ($services[$attrs['service']] ?? 0) + 1;
                }

                // Count statuses
                if (isset($attrs['status'])) {
                    $statuses[$attrs['status']] = ($statuses[$attrs['status']] ?? 0) + 1;
                }

                // Collect top error messages
                if (isset($attrs['message'])) {
                    $msg = substr($attrs['message'], 0, 100); // Truncate long messages
                    $messages[$msg] = ($messages[$msg] ?? 0) + 1;
                }
            }
        }

        // Sort and limit
        arsort($services);
        arsort($statuses);
        arsort($messages);

        return [
            'format' => 'summary',
            'count' => $count,
            'query' => $query,
            'time_range' => $time_range,
            'from_ms' => $from,
            'to_ms' => $to,
            'services' => array_slice($services, 0, 10, true), // Top 10 services
            'statuses' => $statuses,
            'top_messages' => array_slice($messages, 0, 10, true), // Top 10 messages
            'has_more' => isset($response['meta']['page']['after']) && $response['meta']['page']['after'] !== null,
        ];
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
