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

            ## USER INTENT MAPPING

            "Show me errors" / "Find errors":
              → query: "status:error"
              → time: "1h"
              → limit: 10

            "How many errors":
              → query: "status:error"
              → format: "count"
              → time: "1h"

            "Find [SERVICE] errors":
              → query: "service:SERVICE status:error"
              → time: "1h"

            "Show me 500 errors":
              → query: "http.status_code:500"  (@ added automatically)
              → time: "1h"

            "Errors for user [ID]":
              → query: "user.id:ID status:error"  (@ added automatically)
              → time: "24h"

            "Slow requests":
              → query: "duration:>3000"  (@ added automatically, >3 seconds)
              → time: "1h"

            "Errors in production":
              → query: "env:production status:error"
              → time: "1h"

            "Get latest error message":
              → query: "status:error"
              → limit: 1
              → sort: "-timestamp"
              → jq_filter: ".data[0].attributes.message"

            "Count errors by service":
              → query: "status:error"
              → limit: 1000
              → jq_filter: ".data | group_by(.attributes.service) | map({service: .[0].attributes.service, count: length})"

            ## ERROR RECOVERY MATRIX

            | Error Symptom | Root Cause | Solution |
            |--------------|------------|----------|
            | data=[] | Query too restrictive | Try broader time: "1h"→"24h"→"7d" |
            | data=[] | Service/attribute name wrong | Verify with broader query: "status:error" |
            | data=[] | Time range too narrow | Expand time parameter |
            | HTTP 400 | Malformed query | Check quotes around values with spaces |
            | HTTP 400 | Invalid attribute syntax | Use attribute:value format |

            RECOVERY PROTOCOL:
            1. IF data=[] AND time="1h" → Retry with time="24h"
            2. IF still data=[] → Retry with simpler query (just "status:error")
            3. IF HTTP 400 → Check query syntax (quotes, colons, parentheses)

            ## Critical Rules
            ✅ AUTO-HANDLED BY BACKEND (you don't need to worry about these):
            - @ prefixes are added automatically to custom attributes
            - Boolean operators (and/or/not) are uppercased automatically
            - Timestamps are accepted in multiple formats (relative, ISO, milliseconds)

            YOU SHOULD STILL:
            - Use quotes around values with spaces: message:"error occurred"
            - Use wildcards for pattern matching: * (multi-char), ? (single-char)
            - Remember attribute names are case-sensitive

            ## Time Parameter (Simplified)
            Just use the `time` parameter with any of these formats:
            - time="1h" → Last 1 hour (recommended)
            - time="24h" → Last 24 hours
            - time="7d" → Last 7 days
            - time="yesterday" → Last 24 hours
            - time="2024-01-15T10:00:00Z" → From that time to now
            - time="2024-01-15T10:00:00Z/2024-01-16T10:00:00Z" → Specific range

            No more timestamp calculations, no more seconds vs milliseconds confusion!

            ## Severity Levels (status: attribute)
            Filter logs by severity using status:LEVEL:
            - status:error - Error conditions (most common for troubleshooting)
            - status:warn - Warning conditions
            - status:info - Informational messages
            - status:debug - Debug-level messages

            Combine severities: status:(error OR warn) or status:>=error

            ## Common Query Patterns (Simplified Syntax)
            "Show errors in production" → env:production status:error
            "Find slow API requests" → service:api duration:>3000 (@ added automatically)
            "500 errors" → http.status_code:>=500 (@ added automatically)
            "User errors" → user.id:12345 status:error (@ added automatically)
            "Multiple conditions" → service:api and status:error (uppercased automatically)

            ## Response Structure
            {
              "data": [{"id": "...", "attributes": {"timestamp": "...", "message": "...", "service": "..."}}],
              "meta": {"page": {"after": "cursor_or_null"}}
            }

            ## MCP Usage Notes
            YOU (the LLM) should:
            - Use time parameter with natural formats: "1h", "24h", "yesterday", ISO datetimes
            - Write queries naturally - @ prefix and uppercase operators are added automatically
            - Translate user intent to Datadog syntax using USER INTENT MAPPING above
            - Follow ERROR RECOVERY MATRIX when queries fail
            - Summarize patterns, not raw JSON dumps
            - Present insights in human-readable format

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
                Log search query using natural Datadog syntax. Required.

                ✅ SIMPLIFIED SYNTAX (backend auto-normalizes):
                - Write attributes naturally: http.status_code:500 (@ added automatically)
                - Use lowercase operators: and/or/not (uppercased automatically)
                - Reserved attributes work as-is: service, env, status, host, source, version, trace_id

                ## COMMON PATTERNS:

                Basic:
                - "status:error" → All error logs
                - "service:api status:error" → API errors (implicit AND)
                - "env:production status:error" → Production errors

                With Custom Attributes (@ added automatically):
                - "http.status_code:500" → HTTP 500 errors
                - "user.id:12345" → Logs for user 12345
                - "duration:>3000" → Slow requests (>3 seconds)

                Combining Conditions:
                - "service:api and status:error" → Both conditions (AND uppercased automatically)
                - "env:prod or env:staging" → Either environment (OR uppercased automatically)
                - "status:error not service:health" → Exclude health service (NOT uppercased automatically)
                - "(service:api or service:worker) and status:error" → Group conditions with parentheses

                Numeric Operators:
                - "http.status_code:>=500" → Server errors
                - "http.status_code:[400 TO 499]" → Client errors range
                - "duration:<1000" → Fast requests

                Wildcards:
                - "service:web-*" → All web services (web-api, web-app, etc.)
                - "host:server-?" → Single character wildcard

                Special Characters:
                - "message:\"error occurred\"" → Values with spaces need quotes
                - "\"database timeout\"" → Free-text search

                ## EXAMPLES:

                User: "Find API errors with 500 status in production"
                Query: "service:api status:error http.status_code:500 env:production"
                → Backend converts to: service:api status:error @http.status_code:500 env:production

                User: "Slow checkout requests in prod or staging"
                Query: "service:checkout duration:>5000 and (env:prod or env:staging)"
                → Backend converts to: service:checkout @duration:>5000 AND (env:prod OR env:staging)

                ## RESERVED ATTRIBUTES (no @ needed):
                service, env, status, host, source, version, trace_id

                ## CUSTOM ATTRIBUTES (@ added automatically):
                Everything else: http.*, user.*, error.*, duration, transaction.*, etc.
                TEXT,
            minLength: 1
        )]
        string $query,
        #[Schema(
            type: 'string',
            description: <<<TEXT
                Smart time parameter that accepts multiple formats. Optional, defaults to "1h".

                ## ACCEPTED FORMATS (Auto-detected):

                1. **Relative time** (recommended for most queries):
                   - "1h" or "1hr" → Last 1 hour (default)
                   - "24h" → Last 24 hours
                   - "7d" or "7day" → Last 7 days
                   - "15m" or "15min" → Last 15 minutes
                   - "30d" → Last 30 days

                2. **ISO 8601 datetime** (converted to milliseconds automatically):
                   - "2024-01-15T10:00:00Z" → Single timestamp (from this time to now)
                   - "2024-01-15T10:00:00Z/2024-01-16T10:00:00Z" → Range (from/to)
                   - "2024-01-15T10:00:00+00:00" → With timezone

                3. **Milliseconds** (as string or number, passed through):
                   - "1765461420000" → Exact timestamp
                   - "1765461420000/1765547820000" → Range in milliseconds

                4. **Natural language** (parsed intelligently):
                   - "yesterday" → Last 24 hours
                   - "last hour" → Last 1 hour
                   - "today" → Since midnight today

                ## BENEFITS:
                ✅ No more timestamp calculations needed
                ✅ No more seconds vs milliseconds confusion
                ✅ No more choosing between time_range/from/to
                ✅ Accepts natural formats you'd expect

                ## EXAMPLES:
                - time="1h" → Last hour (most common)
                - time="2024-01-15T10:00:00Z" → From that time to now
                - time="2024-01-15T10:00:00Z/2024-01-16T10:00:00Z" → Specific range
                - time="24h" → Last 24 hours
                - time="yesterday" → Yesterday's logs

                Default: "1h" (last hour)
                TEXT
        )]
        ?string $time = '1h',
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

                ## VALIDATION RULES:
                ✓ MUST be 1-1000
                ✓ Start with 10 for exploratory queries
                ✓ Use 100+ for comprehensive searches

                Default: 10 (if not specified)
                Maximum: 1000 (API enforced limit)

                Performance tips:
                - 10-20: Faster responses, good for initial queries
                - 100-1000: Reduces pagination requests for large datasets

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

                ## PAGINATION STATE MACHINE:

                STATE 1: Initial Request
                  cursor: null (or omit parameter)
                  ACTION: Make first API call
                  NEXT: Go to STATE 2

                STATE 2: Process Response
                  CHECK: response.meta.page.after
                  IF null:
                    → TERMINAL STATE (no more data)
                  IF non-null string:
                    → Go to STATE 3

                STATE 3: Fetch Next Page
                  cursor: <value from response.meta.page.after>
                  PRESERVE: query, time_range/from/to, limit, format, jq_filter
                  ACTION: Make API call with same parameters + cursor
                  NEXT: Go to STATE 2

                ## VALIDATION RULES:
                ✓ MUST be from previous response.meta.page.after
                ✓ MUST be null/omitted on first request
                ✗ NEVER fabricate cursor values

                Example: "eyJhZnRlciI6IkFRQUFBWE1rLWc4d..." (base64-encoded string)

                Cursor expires after a short time period (typically 1-5 minutes).
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

                ## FORMAT PARAMETER DECISION LOGIC:

                format="full" (default):
                  WHEN: Need to examine individual log messages
                  WHEN: Analyzing error patterns
                  WHEN: Extracting specific fields with jq
                  RESPONSE: Complete log entries with all attributes

                format="count":
                  WHEN: User asks "how many"
                  WHEN: Comparing volumes across time periods
                  WHEN: Building metrics/dashboards
                  RESPONSE: {"count": N}

                format="summary":
                  WHEN: User asks for "overview" or "summary"
                  WHEN: Need quick insights without details
                  WHEN: Identifying top services/errors
                  RESPONSE: {count, services, top_errors}

                DEFAULT: Use format="full" unless user explicitly needs count/summary

                Response formats:
                - "full": Complete log entries with all attributes
                - "count": {"count": 47, "query": "...", "time_range": "..."}
                - "summary": {"count": ..., "services": {...}, "top_errors": [...]}
                TEXT,
            enum: ['full', 'count', 'summary']
        )]
        ?string $format = 'full',
        #[Schema(
            type: 'string',
            description: <<<TEXT
                jq filter expression to transform the response data. Optional.

                ## JQ FILTER TEMPLATES:

                Extract first N logs:
                  jq_filter: ".data[:N]"
                  jq_streaming: false

                Count logs:
                  jq_filter: ".data | length"
                  jq_streaming: false

                Get unique values from field:
                  jq_filter: "[.data[].attributes.FIELD] | unique"
                  jq_streaming: false

                Custom object per log:
                  jq_filter: ".data[] | {KEY: .attributes.FIELD, ...}"
                  jq_streaming: true  ← REQUIRED when using .data[]

                Filter logs by condition:
                  jq_filter: "[.data[] | select(.attributes.FIELD == VALUE)]"
                  jq_streaming: false  ← [] already creates array

                Extract single field as array:
                  jq_filter: "[.data[].attributes.FIELD]"
                  jq_streaming: false

                Group and count:
                  jq_filter: ".data | group_by(.attributes.FIELD) | map({key: .[0].attributes.FIELD, count: length})"
                  jq_streaming: false

                ## CRITICAL RULES:
                ✓ IF jq outputs multiple values (uses .data[] without []), SET jq_streaming=true
                ✓ IF jq outputs single value (wrapped in [] or uses | map), SET jq_streaming=false
                ✗ NEVER use .data[] without either [] wrapper OR jq_streaming=true

                Return types: jq filters can return any JSON value (object, array, string, number, boolean, null).

                Examples:
                - ".data[0]" - Get first log entry (returns: object)
                - "[.data[]]" - Get all logs as array (returns: array)
                - ".data | length" - Count logs (returns: number)
                - "[.data[].attributes.service] | unique" - Unique services (returns: array)

                Security: jq expressions are sandboxed (no file system access)

                jq docs: https://jqlang.github.io/jq/manual/
                TEXT,
            minLength: 1
        )]
        ?string $jq_filter = null,
        #[Schema(
            type: 'boolean',
            description: <<<TEXT
                Output raw text instead of JSON-encoded strings. Optional, defaults to false.

                When true, adds the --raw-output (-r) flag to jq.
                If the jq filter result is a string, it will be output as raw text without JSON quotes.
                Useful for extracting plain text from log messages.

                Examples:
                - Extract plain message text: jq_filter=".data[0].attributes.message", jq_raw_output=true
                - Get service names as plain text: jq_filter="[.data[].attributes.service] | unique | .[]", jq_raw_output=true, jq_streaming=true

                Note: Only affects string outputs. Numbers, booleans, objects, and arrays are unaffected.
                TEXT
        )]
        ?bool $jq_raw_output = false,
        #[Schema(
            type: 'boolean',
            description: <<<TEXT
                Collect multiple jq outputs into an array. Optional, defaults to false.

                Some jq filters produce multiple JSON values (one per line) instead of a single value.
                For example, ".data[]" outputs each log entry separately.

                When jq_streaming=true, all outputs are collected into a single array.

                Examples:
                - Stream all logs: jq_filter=".data[]", jq_streaming=true
                  Returns: [log1, log2, log3, ...]

                - Stream filtered logs: jq_filter=".data[] | select(.attributes.service == \"api\")", jq_streaming=true
                  Returns: [matching_log1, matching_log2, ...]

                - Stream field values: jq_filter=".data[].attributes.service", jq_streaming=true
                  Returns: ["service1", "service2", "service3", ...]

                Without streaming mode, these filters would produce multiple separate JSON values,
                which may cause parsing errors. Streaming mode collects them into a single array.

                Note: If the filter produces a single value, streaming mode has no effect.
                TEXT
        )]
        ?bool $jq_streaming = false,
        #[Schema(
            type: 'string',
            description: <<<TEXT
                Simplified JSON path for extracting fields without jq syntax. Optional.

                Provides a simpler alternative to jq_filter for common field extractions.
                Uses dot notation for nested fields and numbers for array indices.

                Path format:
                - Use dots (.) to separate nested fields: "data.attributes.service"
                - Use numbers for array indices: "data.0.attributes.message"
                - Supports any depth of nesting: "data.0.attributes.custom.nested.field"

                Examples:
                - "data.0" - Get first log entry
                - "data.0.attributes.service" - Get service name from first log
                - "data.0.attributes.message" - Get message from first log
                - "meta.page.after" - Get pagination cursor

                Common patterns:
                - First log: json_path="data.0"
                - First message: json_path="data.0.attributes.message"
                - Service from first log: json_path="data.0.attributes.service"
                - Pagination cursor: json_path="meta.page.after"

                This is internally converted to jq syntax:
                - "data.0.attributes.service" becomes ".data[0].attributes.service"
                - "meta.page.after" becomes ".meta.page.after"

                Works with jq_raw_output and jq_streaming parameters.

                Note: Cannot be used together with jq_filter. Use json_path for simple extractions,
                or jq_filter for complex transformations (filtering, mapping, aggregation).

                For complex queries, use jq_filter instead:
                - Filtering: jq_filter=".data[] | select(.attributes.status == \"error\")"
                - Mapping: jq_filter="[.data[].attributes.service] | unique"
                - Aggregation: jq_filter="{count: .data | length, services: [.data[].attributes.service]}"
                TEXT,
            minLength: 1
        )]
        ?string $json_path = null
    ): mixed {
        // Ensure time has a default value (handle null from MCP)
        $time = $time ?? '1h';

        // Parse the smart time parameter (handles all formats automatically)
        [$from, $to, $time_display] = $this->parseTime($time);

        if ($from >= $to) {
            throw new RuntimeException('Parsed time range is invalid: start time must be before end time');
        }

        // Auto-normalize query: add @ prefix to custom attributes, uppercase Boolean operators
        $query = $this->normalizeQuery($query);

        if ($limit !== null && ($limit < 1 || $limit > 1000)) {
            throw new RuntimeException('Parameter "limit" must be between 1 and 1000');
        }

        if ($sort !== null && !in_array($sort, ['timestamp', '-timestamp', 'timestamp:asc', 'timestamp:desc'], true)) {
            throw new RuntimeException('Parameter "sort" must be "timestamp", "-timestamp", "timestamp:asc", or "timestamp:desc"');
        }

        // Validate json_path and jq_filter are mutually exclusive
        if ($json_path !== null && trim($json_path) !== '' && $jq_filter !== null && trim($jq_filter) !== '') {
            throw new RuntimeException('Cannot use both json_path and jq_filter parameters. Use json_path for simple field extraction, or jq_filter for complex transformations.');
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
            $response = $this->formatCount($response, $query, $time_display, $from, $to);
        } elseif ($format === 'summary') {
            $response = $this->formatSummary($response, $query, $time_display, $from, $to);
        } else {
            // Default: full format
            $response = $this->filterTags($response, $includeTags ?? false);
        }

        // Convert json_path to jq_filter if provided
        if ($json_path !== null && trim($json_path) !== '') {
            $jq_filter = $this->convertJsonPathToJq($json_path);
        }

        // Apply jq filter if provided (works with any format)
        if ($jq_filter !== null && trim($jq_filter) !== '') {
            $response = $this->applyJqFilter(
                $response,
                $jq_filter,
                $jq_raw_output ?? false,
                $jq_streaming ?? false
            );
        }

        return $response;
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
     * Parses smart time parameter and returns [from, to, display] timestamps.
     *
     * Accepts multiple formats:
     * - Relative: "1h", "24h", "7d"
     * - ISO 8601: "2024-01-15T10:00:00Z" or "2024-01-15T10:00:00Z/2024-01-16T10:00:00Z"
     * - Milliseconds: "1765461420000" or "1765461420000/1765547820000"
     * - Natural language: "yesterday", "last hour", "today"
     *
     * @param  string  $time  The time parameter value
     *
     * @return array{int, int, string}  [from_ms, to_ms, display_string]
     */
    protected function parseTime(string $time): array
    {
        $now_ms = (int) (microtime(true) * 1000);

        // Format 1: Relative time (e.g., "1h", "24h", "7d")
        if (preg_match('/^(\d+)([mhdMHD])(?:in|hr|ay)?$/', $time, $matches)) {
            $value = (int) $matches[1];
            $unit = strtolower($matches[2]);

            $milliseconds_map = [
                'm' => 60_000,      // 1 minute
                'h' => 3_600_000,   // 1 hour
                'd' => 86_400_000,  // 1 day
            ];

            if (!isset($milliseconds_map[$unit])) {
                throw new RuntimeException('Invalid time unit. Supported: m (minutes), h (hours), d (days)');
            }

            $offset_ms = $value * $milliseconds_map[$unit];
            $from_ms = $now_ms - $offset_ms;

            return [$from_ms, $now_ms, $time];
        }

        // Format 2: ISO 8601 datetime or range
        if (str_contains($time, 'T') || str_contains($time, '-')) {
            // Check if it's a range (contains /)
            if (str_contains($time, '/')) {
                [$start, $end] = explode('/', $time, 2);
                $from_ms = $this->parseIsoOrMilliseconds($start, $now_ms);
                $to_ms = $this->parseIsoOrMilliseconds($end, $now_ms);
                return [$from_ms, $to_ms, $time];
            }

            // Single datetime: from that time to now
            $from_ms = $this->parseIsoOrMilliseconds($time, $now_ms);
            return [$from_ms, $now_ms, $time];
        }

        // Format 3: Milliseconds (single or range)
        if (str_contains($time, '/')) {
            [$start, $end] = explode('/', $time, 2);
            if (ctype_digit($start) && ctype_digit($end)) {
                $from_ms = (int) $start;
                $to_ms = (int) $end;
                return [$from_ms, $to_ms, $time];
            }
        } elseif (ctype_digit($time)) {
            // Single milliseconds timestamp: from that time to now
            $from_ms = (int) $time;
            return [$from_ms, $now_ms, $time];
        }

        // Format 4: Natural language
        $natural = strtolower(trim($time));
        switch ($natural) {
            case 'yesterday':
            case 'last day':
                $from_ms = $now_ms - 86_400_000;
                return [$from_ms, $now_ms, 'last 24h'];

            case 'last hour':
            case 'past hour':
                $from_ms = $now_ms - 3_600_000;
                return [$from_ms, $now_ms, 'last 1h'];

            case 'today':
                $from_ms = (int) (strtotime('today midnight') * 1000);
                return [$from_ms, $now_ms, 'today'];

            case 'last week':
            case 'past week':
                $from_ms = $now_ms - 604_800_000;
                return [$from_ms, $now_ms, 'last 7d'];
        }

        throw new RuntimeException(
            'Invalid time format. Supported: "1h", "24h", "7d", "2024-01-15T10:00:00Z", "1765461420000", "yesterday", etc.'
        );
    }

    /**
     * Parses ISO 8601 datetime string or milliseconds timestamp.
     *
     * @param  string  $value  ISO datetime or milliseconds
     * @param  int  $now_ms  Current time in milliseconds
     *
     * @return int  Timestamp in milliseconds
     */
    protected function parseIsoOrMilliseconds(string $value, int $now_ms): int
    {
        // Try parsing as milliseconds first
        if (ctype_digit($value)) {
            return (int) $value;
        }

        // Try parsing as ISO 8601 datetime
        try {
            $dt = new \DateTime($value);
            return (int) ($dt->getTimestamp() * 1000);
        } catch (\Exception $e) {
            throw new RuntimeException('Invalid datetime format: '.$value.'. Expected ISO 8601 (e.g., 2024-01-15T10:00:00Z)');
        }
    }

    /**
     * Normalizes Datadog query by auto-adding @ prefix to custom attributes
     * and uppercasing Boolean operators.
     *
     * Benefits:
     * - LLM doesn't need to memorize which attributes need @
     * - LLM doesn't need to remember to uppercase AND/OR/NOT
     * - Queries work naturally without syntax errors
     *
     * @param  string  $query  The original query
     *
     * @return string  The normalized query
     */
    protected function normalizeQuery(string $query): string
    {
        // Reserved attributes (NO @ prefix needed)
        $reserved = ['service', 'env', 'status', 'host', 'source', 'version', 'trace_id'];

        // Step 1: Auto-uppercase Boolean operators (and/or/not → AND/OR/NOT)
        // Use word boundaries to avoid matching inside words
        $query = preg_replace_callback(
            '/\b(and|or|not)\b/i',
            fn ($matches) => strtoupper($matches[1]),
            $query
        );

        // Step 2: Auto-add @ prefix to custom attributes
        // Pattern: Match "attribute:" but not "@attribute:" or "-attribute:"
        // This regex finds attribute names that are followed by : but not preceded by @ or -
        $query = preg_replace_callback(
            '/(?<![@\-])(\b[a-zA-Z_][a-zA-Z0-9_.]*):/',
            function ($matches) use ($reserved) {
                $attribute = $matches[1];

                // Check if it's a reserved attribute
                if (in_array($attribute, $reserved, true)) {
                    // Keep as-is (no @ needed)
                    return $attribute.':';
                }

                // Custom attribute: add @ prefix
                return '@'.$attribute.':';
            },
            $query
        );

        return $query;
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

    /**
     * Applies jq filter to JSON data.
     *
     * @param  array  $data  The PHP array to filter
     * @param  string  $jq_filter  The jq filter expression
     * @param  bool  $raw_output  Output raw strings without JSON encoding
     * @param  bool  $streaming  Collect multiple JSON values into an array
     *
     * @return mixed Returns any valid JSON value (array, object, string, number, boolean, or null)
     *
     * @throws RuntimeException
     */
    protected function applyJqFilter(array $data, string $jq_filter, bool $raw_output = false, bool $streaming = false): mixed
    {
        // Validate jq is available
        $jq_path = $this->findJqBinary();
        if ($jq_path === null) {
            throw new RuntimeException(
                'jq binary not found. Install with: apk add --no-cache jq'
            );
        }

        // Encode data to JSON for jq processing
        $json_input = json_encode(
            $data,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        // Prepare jq command with proper escaping (SECURITY CRITICAL)
        $descriptor_spec = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];

        // Build jq flags
        $flags = [];
        $flags[] = '--compact-output';  // Always use compact output for consistent line-by-line parsing
        $flags[] = '--exit-status';
        if ($raw_output) {
            $flags[] = '--raw-output';
        }

        $command = sprintf(
            '%s %s %s',
            escapeshellarg($jq_path),           // Prevents path injection
            implode(' ', $flags),                // Add flags
            escapeshellarg($jq_filter)           // Prevents filter injection
        );

        $process = proc_open($command, $descriptor_spec, $pipes);

        if (!is_resource($process)) {
            throw new RuntimeException('Failed to execute jq command');
        }

        // Write JSON input to jq's stdin
        fwrite($pipes[0], $json_input);
        fclose($pipes[0]);

        // Read jq's output
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exit_code = proc_close($process);

        // Handle jq errors with helpful messages
        if ($exit_code !== 0) {
            $error_message = match ($exit_code) {
                5 => 'jq filter produced no output (empty result)',
                4 => 'jq filter produced null output',
                3, 2 => 'jq compile error - invalid filter syntax',
                default => 'jq filter failed',
            };

            if (!empty($stderr)) {
                $error_message .= ': '.trim($stderr);
            }

            throw new RuntimeException($error_message.sprintf(' (exit code: %d)', $exit_code));
        }

        // Parse jq output based on mode
        if ($streaming) {
            // Streaming mode: collect all JSON values (one per line) into array
            return $this->parseStreamingOutput($stdout, $raw_output);
        } else {
            // Non-streaming mode: single value
            if ($raw_output) {
                // Raw output mode: return as-is (plain text)
                return trim($stdout);
            }

            // JSON mode: decode the output
            $result = json_decode($stdout, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new RuntimeException('Failed to decode jq output: '.json_last_error_msg());
            }

            return $result;
        }
    }

    /**
     * Parses streaming jq output (multiple JSON values, one per line).
     *
     * @param  string  $stdout  The stdout from jq
     * @param  bool  $raw_output  Whether raw output mode is enabled
     *
     * @return array|string
     *
     * @throws RuntimeException
     */
    protected function parseStreamingOutput(string $stdout, bool $raw_output): array|string
    {
        $lines = array_filter(explode("\n", trim($stdout)), fn ($line) => $line !== '');

        if (empty($lines)) {
            return [];
        }

        // If only one line, decode as single value (not wrapped in array)
        if (count($lines) === 1) {
            if ($raw_output) {
                // Raw output: return the string as-is
                return $lines[0];
            }

            $result = json_decode($lines[0], true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new RuntimeException('Failed to decode jq output: '.json_last_error_msg());
            }

            return $result;
        }

        // Multiple lines: collect into array
        if ($raw_output) {
            // Raw output: return array of strings
            return $lines;
        }

        // JSON output: decode each line
        $results = [];
        foreach ($lines as $index => $line) {
            $decoded = json_decode($line, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new RuntimeException(
                    'Failed to decode jq streaming output line '.($index + 1).': '.json_last_error_msg().' | Line: '.$line
                );
            }
            $results[] = $decoded;
        }

        return $results;
    }

    /**
     * Finds the jq binary path.
     *
     * @return string|null
     */
    protected function findJqBinary(): ?string
    {
        // Try common paths
        $possible_paths = [
            '/usr/bin/jq',           // Alpine/Debian default
            '/usr/local/bin/jq',     // macOS homebrew
            '/opt/homebrew/bin/jq',  // macOS Apple Silicon
        ];

        foreach ($possible_paths as $path) {
            if (file_exists($path) && is_executable($path)) {
                return $path;
            }
        }

        // Try PATH lookup
        $which_result = shell_exec('which jq 2>/dev/null');
        if ($which_result !== null && trim($which_result) !== '') {
            $path = trim($which_result);
            if (file_exists($path) && is_executable($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Converts simplified JSON path notation to jq filter syntax.
     *
     * Transforms dot notation paths into valid jq filter expressions:
     * - "data.0.attributes.service" → ".data[0].attributes.service"
     * - "meta.page.after" → ".meta.page.after"
     *
     * @param  string  $json_path  The simplified JSON path (e.g., "data.0.attributes.service")
     *
     * @return string The jq filter expression (e.g., ".data[0].attributes.service")
     */
    protected function convertJsonPathToJq(string $json_path): string
    {
        // Split path by dots
        $segments = explode('.', $json_path);

        // Convert each segment
        $jq_parts = [];
        foreach ($segments as $segment) {
            if ($segment === '') {
                continue; // Skip empty segments
            }

            // Check if segment is a number (array index)
            if (ctype_digit($segment)) {
                // Convert to array index syntax: [N]
                $jq_parts[] = '['.$segment.']';
            } else {
                // Regular field access
                $jq_parts[] = '.'.$segment;
            }
        }

        // Join parts and ensure it starts with a dot (if not an array index)
        $jq_filter = implode('', $jq_parts);

        // If the filter doesn't start with . or [, prepend a dot
        if ($jq_filter !== '' && $jq_filter[0] !== '.' && $jq_filter[0] !== '[') {
            $jq_filter = '.'.$jq_filter;
        }

        return $jq_filter;
    }
}
