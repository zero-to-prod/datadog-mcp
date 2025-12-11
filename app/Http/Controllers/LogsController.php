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
              → time_range: "1h"
              → limit: 10

            "How many errors":
              → query: "status:error"
              → format: "count"
              → time_range: "1h"

            "Find [SERVICE] errors":
              → query: "service:SERVICE status:error"
              → time_range: "1h"

            "Show me 500 errors":
              → query: "@http.status_code:500"
              → time_range: "1h"

            "Errors for user [ID]":
              → query: "@user.id:ID status:error"
              → time_range: "24h"

            "Slow requests":
              → query: "@duration:>3000"  (>3 seconds)
              → time_range: "1h"

            "Errors in production":
              → query: "env:production status:error"
              → time_range: "1h"

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
            | data=[] | Query too restrictive | Try broader time_range: 1h→24h→7d |
            | data=[] | Wrong @ prefix | Reserved attrs: remove @. Custom attrs: add @ |
            | data=[] | Service name wrong | Verify with broader query: status:error |
            | HTTP 400 | Lowercase operators | Change and→AND, or→OR, not→NOT |
            | HTTP 400 | Malformed query | Check: quotes, @prefix, operator case |
            | "from must be < to" | Timestamps reversed | Swap from/to values |
            | Timestamp out of range | Used seconds not ms | Multiply by 1000 |
            | Timestamp year wrong | Calculation error | Use: Date.now() - (hours * 3600000) |

            RECOVERY PROTOCOL:
            1. IF data=[] AND time_range="1h" → Retry with time_range="24h"
            2. IF still data=[] → Retry with simpler query (just "status:error")
            3. IF HTTP 400 → Check @ prefix rules and operator case
            4. IF timestamp error → Switch to time_range parameter

            ## Critical Rules
            - Reserved attributes (NO @): service, env, status, host, source, version, trace_id
            - Custom attributes (@ REQUIRED): @http.status_code, @user.id, @duration, @error.message, etc.
            - Timestamps: MUST be milliseconds for current year (multiply Unix seconds × 1000)
            - Boolean operators: UPPERCASE only (AND, OR, NOT)
            - Wildcards: * (multi-char), ? (single-char)
            - Attribute names are case-sensitive

            ## Time Range Options
            **EASY MODE**: Use time_range parameter with simple strings: "1h", "24h", "7d"
            **ADVANCED**: Use from/to parameters with millisecond timestamps for precise control

            Examples with time_range:
            - time_range="1h" → Last 1 hour (recommended for most queries)
            - time_range="24h" → Last 24 hours
            - time_range="7d" → Last 7 days

            ## Severity Levels (status: attribute)
            Filter logs by severity using status:LEVEL:
            - status:error - Error conditions (most common for troubleshooting)
            - status:warn - Warning conditions
            - status:info - Informational messages
            - status:debug - Debug-level messages

            Combine severities: status:(error OR warn) or status:>=error

            ## Common Query Patterns
            "Show errors in production" → env:production status:error
            "Find slow API requests" → service:api @duration:>3000
            "500 errors" → @http.status_code:>=500
            "User errors" → @user.id:12345 status:error

            ## Response Structure
            {
              "data": [{"id": "...", "attributes": {"timestamp": "...", "message": "...", "service": "..."}}],
              "meta": {"page": {"after": "cursor_or_null"}}
            }

            ## MCP Usage Notes
            YOU (the LLM) should:
            - PREFER time_range parameter ("1h", "24h", "7d") over calculating timestamps
            - Only use from/to for precise time windows (e.g., specific incident times)
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
                Log search query using Datadog search syntax. Required.

                ## QUERY CONSTRUCTION ALGORITHM:

                STEP 1: Identify attribute types
                  FOR EACH attribute in user request:
                    IF attribute IN [service, env, status, host, source, version, trace_id]:
                      → Use WITHOUT @ prefix
                    ELSE:
                      → Use WITH @ prefix (e.g., @http.status_code)

                STEP 2: Apply operators
                  - Numeric comparisons: attribute:>value, attribute:>=value, attribute:[min TO max]
                  - Wildcards: attribute:prefix-*
                  - Exact match: attribute:value
                  - Multiple values: attribute:(value1 OR value2)

                STEP 3: Combine conditions
                  - Use AND between different filter types (implicit with spaces)
                  - Use OR for alternatives
                  - Use NOT or - prefix to exclude
                  - Use ( ) to group logic

                STEP 4: Validate
                  - Check @ prefix usage is correct
                  - Verify operators are UPPERCASE
                  - Ensure wildcards are valid (*, ?)
                  - Confirm quotes around spaces: @message:"error message"

                ## ATTRIBUTE PREFIX REFERENCE:

                NO @ PREFIX (Reserved Attributes):
                  service, env, status, host, source, version, trace_id

                REQUIRES @ PREFIX (Custom Attributes):

                  HTTP: @http.status_code, @http.method, @http.url, @http.request_id
                  User: @user.id, @user.email, @user.name, @user.country
                  Performance: @duration, @response_time, @db.statement.duration
                  Error: @error.message, @error.kind, @error.stack, @error.code
                  Transaction: @transaction.id, @transaction.amount, @transaction.status
                  Deployment: @deployment.version, @deployment.canary, @container.name

                DECISION RULE:
                  IF unsure whether attribute needs @:
                    → Assume it needs @ (custom attributes are more common)
                    → Exception: Only the 7 reserved attributes above don't need @

                ## VALIDATION RULES:

                query:
                  ✓ MUST be non-empty string
                  ✓ Boolean operators MUST be UPPERCASE (AND/OR/NOT)
                  ✓ Custom attributes MUST have @ prefix
                  ✓ Reserved attributes MUST NOT have @ prefix

                ## SYNTAX REFERENCE:

                Boolean Operators (UPPERCASE required):
                - AND - Both conditions (e.g., service:api AND status:error)
                - OR - Either condition (e.g., env:prod OR env:staging)
                - NOT or - prefix - Exclude (e.g., NOT status:debug OR -status:debug)
                - ( ) - Group conditions (e.g., (service:api OR service:worker) AND status:error)
                - Implicit AND: Spaces act as AND (service:api status:error = service:api AND status:error)

                Numerical Operators:
                - < > <= >= (e.g., @http.status_code:>=500, @duration:<1000)
                - Range: [min TO max] (e.g., @http.status_code:[400 TO 499])

                Wildcards:
                - * matches multiple chars (e.g., service:web-* matches web-api, web-app)
                - ? matches single char (e.g., host:server-? matches server-1, server-a)

                Special Characters:
                - Use quotes for values with spaces: @message:"connection: timeout"
                - Or escape special chars: @message:connection\:\ timeout
                - Free-text phrases in quotes: "database connection error"

                ## EXAMPLE EXECUTION:

                User: "Find API errors with status code 500 in production"

                Step 1: Identify attributes
                  - API → service:api (reserved, no @)
                  - errors → status:error (reserved, no @)
                  - status code 500 → @http.status_code:500 (custom, needs @)
                  - production → env:production (reserved, no @)

                Step 2-3: Combine
                  service:api status:error @http.status_code:500 env:production

                Step 4: Validate ✓

                ## QUERY EXAMPLES:

                Simple:
                - "status:error" - All error logs
                - "service:api status:error" - API errors (implicit AND)

                With Attributes:
                - "service:api @http.status_code:500" - API with HTTP 500
                - "@user.id:12345 service:auth" - User 12345 in auth service

                Complex:
                - "(service:api OR service:worker) AND status:error" - Errors from either service
                - "service:payment env:prod status:error @http.status_code:>=500" - Payment server errors
                - "@user.country:US service:checkout status:error \"payment declined\"" - US checkout payment failures
                TEXT,
            minLength: 1
        )]
        string $query,
        #[Schema(
            type: 'string',
            description: <<<TEXT
                Relative time range (alternative to from/to). Optional.

                ## TIME PARAMETER DECISION TREE

                IF user specifies exact timestamps:
                  → Use from + to parameters (milliseconds)
                  → DO NOT set time_range (or use default "1h")

                ELSE IF user says "last N hours/days" OR no time specified:
                  → Use time_range parameter
                  → DO NOT set from + to
                  → DEFAULT: time_range="1h"

                VALIDATION:
                  ✗ NEVER set both time_range AND (from/to)
                  ✓ ALWAYS use milliseconds (13 digits)
                  ✓ Pattern: \d+[mhd] (e.g., "1h", "24h", "7d")

                ## Supported formats:
                - "15m" or "15min" - Last 15 minutes
                - "1h" or "1hr" - Last 1 hour (default)
                - "24h" - Last 24 hours
                - "7d" or "7day" - Last 7 days
                - "30d" - Last 30 days

                ## Timestamp Calculations:
                from = Date.now() - (HOURS × 3600000)
                from = Date.now() - (DAYS × 86400000)

                Conversion factors:
                - 1 minute = 60000 ms
                - 1 hour = 3600000 ms
                - 1 day = 86400000 ms
                - 1 week = 604800000 ms

                Examples: "1h", "24h", "7d"
                TEXT,
            pattern: '^\\d+[mhdMHD](?:in|hr|ay)?$'
        )]
        ?string $time_range = '1h',
        #[Schema(
            type: 'integer',
            description: <<<TEXT
                Start timestamp in milliseconds (epoch time). Optional if time_range provided.

                ## VALIDATION RULES:
                ✓ MUST be 13-digit milliseconds (not 10-digit seconds)
                ✓ MUST be < to parameter
                ✓ MUST be in current year range: 1735689600000-1767225599999 (2025)
                ✗ If calculated timestamp < 1735689600000, recalculate using Date.now()

                Example: 1764696580317 (represents 2025-01-02 12:03:00 UTC)

                Generate with: (new DateTime('2025-01-02 12:03:00'))->getTimestamp() * 1000

                Timestamp validation:
                - 2025: 1735689600000 (Jan 1) to 1767225599999 (Dec 31)
                - If timestamp < 10000000000: likely seconds not milliseconds → multiply by 1000
                TEXT,
            minimum: 0
        )]
        ?int $from = null,
        #[Schema(
            type: 'integer',
            description: <<<TEXT
                End timestamp in milliseconds (epoch time). Optional if time_range provided.

                ## VALIDATION RULES:
                ✓ MUST be 13-digit milliseconds (not 10-digit seconds)
                ✓ MUST be > from parameter
                ✓ MUST be in current year range: 1735689600000-1767225599999 (2025)

                Example: 1765301380317 (represents 2025-01-09 12:03:00 UTC)

                Maximum time range: Limited by your Datadog plan (typically 15 minutes to 7 days)
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
            $response = $this->formatCount($response, $query, $time_range, $from, $to);
        } elseif ($format === 'summary') {
            $response = $this->formatSummary($response, $query, $time_range, $from, $to);
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
