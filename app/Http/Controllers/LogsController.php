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

            ## RECOMMENDED USAGE PATTERN

            ⚠️ IMPORTANT: Always start small and iterate. Never request >20 logs without jq filtering.

            ### Investigation Workflow:
            1. **Scope check**: Use `format: "count"` to validate query and understand data volume
            2. **Sample**: Get 5-10 logs with simple jq to preview structure and fields
            3. **Aggregate**: Use jq group_by/map for patterns (limit: 20-50 when grouping)
            4. **Extract**: Use json_path or simple jq for specific values (limit: 1-5)

            ### Token Optimization:
            - ✅ Small limits (5-20) + jq filtering = ~1,000-5,000 tokens per query
            - ❌ Large limits (50+) without filtering = 50,000-100,000 tokens (truncation risk!)

            Real-world example: Investigating an error went from 80,000+ tokens (full logs)
            to ~5,000 tokens (94% reduction) using this pattern with jq filters.

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

            ## COMMON JQ FILTER RECIPES

            **Get Timeline Summary** (avoid token bloat):
            {
              "limit": 20,
              "jq_filter": ".data | map({time: .attributes.timestamp, status: .attributes.status, service: .attributes.service})"
            }

            **Preview Messages** (truncate long text):
            {
              "limit": 10,
              "jq_filter": ".data[] | {time: .attributes.timestamp, message: .attributes.message[0:200]}",
              "jq_streaming": true
            }

            **Group by Status**:
            {
              "limit": 50,
              "jq_filter": ".data | group_by(.attributes.status) | map({status: .[0].attributes.status, count: length, first_seen: .[0].attributes.timestamp, last_seen: .[-1].attributes.timestamp})"
            }

            **Group by Service**:
            {
              "limit": 50,
              "jq_filter": ".data | group_by(.attributes.service) | map({service: .[0].attributes.service, count: length})"
            }

            **Extract Error Details** (simple fields):
            {
              "limit": 1,
              "json_path": "data.0.attributes.context.exception.file"
            }

            **Get Unique Values**:
            {
              "limit": 100,
              "jq_filter": "[.data[].attributes.service] | unique"
            }

            **Time-based Bucketing** (hourly aggregation):
            {
              "limit": 50,
              "jq_filter": ".data | group_by(.attributes.timestamp[0:13]) | map({hour: .[0].attributes.timestamp[0:13], count: length})"
            }

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

            ## REAL-WORLD INVESTIGATION EXAMPLES

            **Example 1: Investigate Error by Order ID**

            Step 1 - Scope check (how many logs exist?):
            {
              "query": "112-9358172-3374603",
              "time": "24h",
              "format": "count"
            }
            // Response: {"count": 10} ← Confirms data exists

            Step 2 - Sample structure (what do these logs look like?):
            {
              "query": "112-9358172-3374603",
              "time": "24h",
              "limit": 5,
              "jq_filter": ".data[] | {time: .attributes.timestamp, service: .attributes.service, status: .attributes.status}",
              "jq_streaming": true
            }

            Step 3 - Get the error (specific investigation):
            {
              "query": "112-9358172-3374603 status:error",
              "time": "24h",
              "limit": 1
            }

            Step 4 - Extract error details (specific field):
            {
              "query": "112-9358172-3374603 status:error",
              "time": "24h",
              "limit": 1,
              "json_path": "data.0.attributes.message"
            }

            **Example 2: Analyze Service Error Rate**

            Step 1 - Get error count by service:
            {
              "query": "status:error",
              "time": "1h",
              "limit": 100,
              "jq_filter": ".data | group_by(.attributes.service) | map({service: .[0].attributes.service, errors: length}) | sort_by(.errors) | reverse"
            }

            Step 2 - Drill into top service:
            {
              "query": "service:api-gateway status:error",
              "time": "1h",
              "limit": 5,
              "jq_filter": ".data[] | {time: .attributes.timestamp, message: .attributes.message[0:150]}",
              "jq_streaming": true
            }

            **Example 3: Build Timeline of Events**
            {
              "query": "transaction_id:ABC123",
              "time": "24h",
              "limit": 20,
              "jq_filter": ".data | map({time: .attributes.timestamp, service: .attributes.service, event: (if .attributes.message | contains(\"started\") then \"start\" elif .attributes.message | contains(\"completed\") then \"complete\" elif .attributes.status == \"error\" then \"error\" else \"processing\" end)}) | sort_by(.time)"
            }

            ## Response Structure
            {
              "data": [{"id": "...", "attributes": {"timestamp": "...", "message": "...", "service": "..."}}],
              "meta": {"page": {"after": "cursor_or_null"}}
            }

            ## PERFORMANCE & TOKEN OPTIMIZATION

            ### Token Usage by Strategy:

            | Approach | Token Cost | Use Case |
            |----------|-----------|----------|
            | format: "count" | ~100 | Validate query scope |
            | limit: 5 + simple jq | ~1,000 | Preview structure |
            | limit: 20 + group_by | ~3,000 | Aggregate patterns |
            | limit: 50 + full data | ~10,000 | Get comprehensive view |
            | limit: 100 + no filter | ~40,000+ | ⚠️ Truncation risk |

            ### Best Practices:
            1. **Always start with format: "count"** to validate query scope
            2. **Use includeTags: false** (default) - tags are huge and rarely needed
            3. **Extract only needed fields** with jq map to reduce payload size
            4. **Slice long messages**: `.attributes.message[0:200]` to preview without bloat
            5. **Use pagination** for >50 results via cursor parameter

            ### Anti-Patterns:
            - ❌ Starting with limit: 100 and no jq filter
            - ❌ Including full message bodies when you only need counts
            - ❌ Not using format: "count" to validate scope first
            - ❌ Requesting tags when not analyzing tag-based issues

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

                ⚠️ RECOMMENDATION: Keep this false (default) unless you specifically need tag analysis.

                ## TOKEN IMPACT:
                - false (default): ~1,000-5,000 tokens per query ✅
                - true: Adds 5,000-20,000+ tokens (100+ tags per log) ❌

                Tags are huge arrays of metadata that dramatically increase token usage:
                - Each log entry has 100+ tag items
                - Most investigations don't need tags
                - Use only for tag-based debugging or analysis

                Set to false (default): Strips tags array from each log entry
                Set to true: Includes full tags array (rarely needed)
                TEXT
        )]
        ?bool $includeTags = false,
        #[Schema(
            type: 'integer',
            description: <<<TEXT
                Maximum number of logs to return per request. Optional.

                ## RECOMMENDED VALUES:
                - **5-20** with jq filtering (most queries) ← START HERE
                - **50-100** ONLY when aggregating with group_by
                - ⚠️ WARNING: Values >50 without jq filtering will likely hit token limits

                ## TOKEN IMPACT:
                - limit: 5 + jq = ~1,000 tokens ✅
                - limit: 20 + jq = ~3,000 tokens ✅
                - limit: 50 full data = ~10,000 tokens ⚠️
                - limit: 100 no filter = ~40,000+ tokens ❌ (truncation risk!)

                ## VALIDATION RULES:
                ✓ MUST be 1-1000
                ✓ Start with 5-10 to preview structure
                ✓ Increase to 20-50 if needed with jq filtering

                Default: 10 (if not specified)
                Maximum: 1000 (API enforced limit)

                Pro tip: Start with limit=5 to preview structure, then increase if needed

                Example: 5, 10, 20
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

                ## BASIC FORMATS:

                format="full" (default):
                  WHEN: Need to examine individual log messages
                  RESPONSE: Complete log entries with all attributes

                format="count":
                  WHEN: User asks "how many"
                  RESPONSE: {"count": N, "query": "...", "time_range": "..."}

                format="summary":
                  WHEN: User asks for "overview"
                  RESPONSE: {count, services, top_errors}

                ## TIER 1 AGGREGATIONS (LLM-Optimized, 90-99% Token Reduction):

                format="scope_analysis":
                  WHEN: Starting investigation, "what's the scope?", "how bad is it?"
                  RETURNS: Metrics, breakdown by status/service, confidence, actionable suggestions
                  TOKEN COST: ~500 (vs 10,000+ for raw logs)
                  USE CASE: First step of every investigation to understand data volume and nature

                format="event_timeline":
                  WHEN: "What happened?", "timeline of events", chronological investigation
                  RETURNS: Timeline with categorized events, confidence scores, patterns, suggested actions
                  TOKEN COST: ~1,000 (vs 10,000+ for raw logs)
                  USE CASE: Understanding sequence of events, identifying cascading failures

                format="error_signatures":
                  WHEN: "Group similar errors", "error patterns", investigating error trends
                  RETURNS: Grouped error patterns with severity, trends, affected services/users, recommendations
                  TOKEN COST: ~300 (vs 15,000+ for raw logs)
                  USE CASE: Identifying common error patterns and their root causes

                format="field_stats":
                  WHEN: "Analyze duration", "performance stats", numeric field analysis
                  REQUIRES: field parameter (e.g., field="duration")
                  RETURNS: Min/max/mean/median/p95/p99/stddev, distribution, outliers, interpretation
                  TOKEN COST: ~200 (vs 20,000+ for raw logs)
                  USE CASE: Performance analysis, identifying slow requests, capacity planning

                ## RECOMMENDED INVESTIGATION WORKFLOW:

                Step 1: format="scope_analysis" → Understand volume and breakdown
                Step 2: format="error_signatures" → Identify common error patterns (if errors exist)
                Step 3: format="event_timeline" → Understand chronological sequence
                Step 4: format="field_stats" → Analyze performance metrics (if needed)

                This workflow reduces investigation tokens by 90%+ compared to fetching raw logs.

                DEFAULT: Use format="full" unless user explicitly needs aggregation
                TEXT,
            enum: ['full', 'count', 'summary', 'scope_analysis', 'event_timeline', 'error_signatures', 'field_stats', 'compare_batch_outcomes', 'causal_chain', 'auto']
        )]
        ?string $format = 'full',
        #[Schema(
            type: 'string',
            description: <<<TEXT
                jq filter expression to transform the response data. Optional.

                ## JQ FILTER GUIDELINES

                ✅ SAFE patterns (recommended):
                - Simple field extraction: `.data[0].attributes.timestamp`
                - Mapping: `.data | map({field1, field2})`
                - Grouping: `.data | group_by(.attributes.service)`
                - Filtering: `.data[] | select(.attributes.status == "error")`
                - String slicing: `.attributes.message[0:200]`

                ⚠️ RISKY patterns (may fail):
                - `fromjson` on nested JSON strings (use smaller queries instead)
                - Deep nested paths with multiple conditionals
                - Complex string parsing with regex

                💡 Alternative: Use `json_path` for simple nested field extraction instead of complex jq.

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

                ## TROUBLESHOOTING JQ ERRORS

                If you get `MCP error -32603: Error while executing tool`:

                1. **Simplify your jq filter** - try without `fromjson`, complex conditionals
                2. **Use json_path instead** - for simple field extraction like `data.0.attributes.field`
                3. **Check for null values** - add `select(. != null)` guards
                4. **Test incrementally** - start with `.data[0]`, then add transformations
                5. **Verify field names** - use a simple query first to see available fields

                Common failures:
                - ❌ `.attributes.context.exception.file | split(":")` → Use json_path or simpler query
                - ❌ `.attributes.message | fromjson` → Parse in multiple queries instead
                - ✅ `.attributes.timestamp` → Simple paths work great

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
        ?string $json_path = null,
        #[Schema(
            type: 'string',
            description: <<<TEXT
                Field name for statistical analysis. Required when format="field_stats", ignored for other formats.

                Must be a numeric field in log attributes (e.g., "duration", "response_time").
                The @ prefix is added automatically for custom attributes.

                Examples:
                - field="duration" → Analyzes @duration field
                - field="http.response_time" → Analyzes @http.response_time field
                - field="db.query_duration" → Analyzes @db.query_duration field

                Reserved attributes (no @ prefix): service, env, status, host, source, version, trace_id
                Custom attributes (@ added automatically): All other fields

                Use with format="field_stats" to get:
                - Statistics: min, max, mean, median, p95, p99, stddev
                - Distribution: bucketed histogram
                - Outliers: Values beyond IQR thresholds
                - Interpretation: Human-readable analysis

                Example request:
                {
                  "query": "service:api-gateway",
                  "time": "1h",
                  "format": "field_stats",
                  "field": "duration",
                  "limit": 500
                }
                TEXT,
            minLength: 1
        )]
        ?string $field = null,
        #[Schema(
            type: 'string',
            description: <<<TEXT
                Field to identify batches for compare_batch_outcomes format.

                Examples: "batch_id", "transaction_id", "feed_id", "correlation_id"

                If not specified, will auto-detect common batch identifiers.
                Only used when format="compare_batch_outcomes".
                TEXT
        )]
        ?string $batch_field = null,
        #[Schema(
            type: 'string',
            description: <<<TEXT
                Field to correlate events for causal_chain format.

                Examples: "order_id", "trace_id", "transaction_id", "request_id"

                If not specified, will auto-detect common correlation fields.
                Only used when format="causal_chain".
                TEXT
        )]
        ?string $correlation_field = null,
        #[Schema(
            type: 'integer',
            description: 'Minutes to look back before error for causal_chain format (default: 60). Only used when format="causal_chain".',
            minimum: 1,
            maximum: 1440
        )]
        int $lookback_minutes = 60
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

        // Validate field parameter for field_stats
        if ($format === 'field_stats' && ($field === null || trim($field) === '')) {
            throw new RuntimeException('Parameter "field" is required when format="field_stats"');
        }

        $response = $this->response($url, $body);

        // Always enrich with parsed messages (JSON/XML extraction)
        $response = $this->enrichWithParsedMessages($response);

        // Handle different output formats
        if ($format === 'count') {
            $response = $this->formatCount($response, $query, $time_display, $from, $to);
        } elseif ($format === 'summary') {
            $response = $this->formatSummary($response, $query, $time_display, $from, $to);
        } elseif ($format === 'scope_analysis') {
            $response = $this->formatScopeAnalysis($response, $query, $time_display, $from, $to);
        } elseif ($format === 'event_timeline') {
            $response = $this->formatEventTimeline($response, $query, $time_display, $from, $to);
        } elseif ($format === 'error_signatures') {
            $response = $this->formatErrorSignatures($response, $query, $time_display, $from, $to);
        } elseif ($format === 'field_stats') {
            // Normalize field name (add @ prefix if needed)
            $normalized_field = $field;
            if (!str_starts_with($field, '@') && !in_array($field, ['service', 'env', 'status', 'host', 'source', 'version', 'trace_id'], true)) {
                $normalized_field = '@' . $field;
            }
            $response = $this->formatFieldStats($response, $query, $time_display, $from, $to, $normalized_field);
        } elseif ($format === 'compare_batch_outcomes') {
            $response = $this->formatCompareBatchOutcomes($response, $query, $time_display, $from, $to, $batch_field);
        } elseif ($format === 'causal_chain') {
            $response = $this->formatCausalChain($response, $query, $time_display, $from, $to, $correlation_field, $lookback_minutes);
        } elseif ($format === 'auto') {
            $response = $this->formatAuto($response, $query, $time_display, $from, $to, $batch_field, $correlation_field, $lookback_minutes);
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
     * Formats response as scope analysis with metrics and suggestions.
     *
     * @param  array  $response
     * @param  string  $query
     * @param  string  $time_range
     * @param  int  $from
     * @param  int  $to
     *
     * @return array
     */
    protected function formatScopeAnalysis(array $response, string $query, string $time_range, int $from, int $to): array
    {
        $count = isset($response['data']) && is_array($response['data']) ? count($response['data']) : 0;
        $time_span_ms = $to - $from;
        $density_per_minute = $time_span_ms > 0 ? ($count / ($time_span_ms / 60000)) : 0;

        // Aggregate by status and service
        $by_status = [];
        $by_service = [];

        if (isset($response['data']) && is_array($response['data'])) {
            foreach ($response['data'] as $log) {
                $attrs = $log['attributes'] ?? [];

                if (isset($attrs['status'])) {
                    $by_status[$attrs['status']] = ($by_status[$attrs['status']] ?? 0) + 1;
                }

                if (isset($attrs['service'])) {
                    $by_service[$attrs['service']] = ($by_service[$attrs['service']] ?? 0) + 1;
                }
            }
        }

        // Sort services by count and limit to top 5
        arsort($by_service);
        $by_service = array_slice($by_service, 0, 5, true);

        // Calculate confidence score
        $confidence = 0.5; // Base confidence

        if ($count > 100) {
            $confidence += 0.2;
        } elseif ($count > 20) {
            $confidence += 0.1;
        }

        if (count($by_service) > 1) {
            $confidence += 0.2;
        }

        if ($time_span_ms > 3600000) { // > 1 hour
            $confidence += 0.1;
        }

        $confidence = min(1.0, $confidence);

        // Generate suggestion
        $suggestion = $this->generateScopeSuggestion($count, $by_status, $by_service, $density_per_minute, $query);

        return [
            'format' => 'scope_analysis',
            'scope' => [
                'count' => $count,
                'time_span_ms' => $time_span_ms,
                'density_per_minute' => round($density_per_minute, 2),
                'has_more' => isset($response['meta']['page']['after']) && $response['meta']['page']['after'] !== null,
            ],
            'breakdown' => [
                'by_status' => $by_status,
                'by_service' => $by_service,
            ],
            'confidence' => round($confidence, 2),
            'suggestion' => $suggestion,
            'query' => $query,
            'time_range' => $time_range,
        ];
    }

    /**
     * Generates actionable suggestion for scope analysis.
     *
     * @param  int  $count
     * @param  array  $by_status
     * @param  array  $by_service
     * @param  float  $density_per_minute
     * @param  string  $query
     *
     * @return array
     */
    protected function generateScopeSuggestion(int $count, array $by_status, array $by_service, float $density_per_minute, string $query): array
    {
        $error_count = $by_status['error'] ?? 0;
        $error_percentage = $count > 0 ? ($error_count / $count) * 100 : 0;
        $service_count = count($by_service);

        // Determine interpretation
        if ($count === 0) {
            return [
                'interpretation' => 'No logs found for this query',
                'next_action' => 'Try expanding time range or simplifying query',
                'next_query' => '',
            ];
        }

        if ($count < 5) {
            return [
                'interpretation' => sprintf('Limited dataset (%d logs) - may need broader query', $count),
                'next_action' => 'Expand time range or reduce query specificity',
                'next_query' => '',
            ];
        }

        // High error rate
        if ($error_percentage > 50) {
            if ($service_count === 1) {
                $service = array_key_first($by_service);
                return [
                    'interpretation' => sprintf('Critical issue in %s service - %d%% error rate (%.1f errors/min)', $service, (int) $error_percentage, $density_per_minute),
                    'next_action' => 'Investigate recent deployments or infrastructure changes',
                    'next_query' => sprintf('%s | error_signatures for pattern analysis', $query),
                ];
            }

            return [
                'interpretation' => sprintf('Widespread errors across %d services - %d%% error rate', $service_count, (int) $error_percentage),
                'next_action' => 'Check for infrastructure issues or cross-service dependencies',
                'next_query' => sprintf('%s | error_signatures to identify common patterns', $query),
            ];
        }

        // Isolated service
        if ($service_count === 1) {
            $service = array_key_first($by_service);
            return [
                'interpretation' => sprintf('Isolated to %s service with consistent pattern (%.1f logs/min)', $service, $density_per_minute),
                'next_action' => 'Investigate service-specific changes or health',
                'next_query' => sprintf('%s | event_timeline for chronological view', $query),
            ];
        }

        // Multiple services
        if ($service_count > 3) {
            return [
                'interpretation' => sprintf('Distributed across %d services - may indicate system-wide event', $service_count),
                'next_action' => 'Look for common timestamps or error patterns',
                'next_query' => sprintf('%s | event_timeline to identify cascade', $query),
            ];
        }

        // Normal case
        return [
            'interpretation' => sprintf('%d logs across %d services (%.1f logs/min) - moderate activity', $count, $service_count, $density_per_minute),
            'next_action' => 'Review for specific patterns or investigate individual services',
            'next_query' => sprintf('%s | error_signatures if errors present', $query),
        ];
    }

    /**
     * Formats response as event timeline with semantic categorization.
     *
     * @param  array  $response
     * @param  string  $query
     * @param  string  $time_range
     * @param  int  $from
     * @param  int  $to
     *
     * @return array
     */
    protected function formatEventTimeline(array $response, string $query, string $time_range, int $from, int $to): array
    {
        $data = $response['data'] ?? [];
        $timeline = [];
        $categories = [];

        // Sort logs by timestamp ascending
        usort($data, function ($a, $b) {
            $ts_a = $a['attributes']['timestamp'] ?? '';
            $ts_b = $b['attributes']['timestamp'] ?? '';
            return strcmp($ts_a, $ts_b);
        });

        // Build timeline
        foreach ($data as $log) {
            $attrs = $log['attributes'] ?? [];

            // Categorize event
            $categorization = $this->categorizeEvent($attrs);

            // Extract related entities
            $entities = $this->extractRelatedEntities($attrs);

            $event = [
                'timestamp' => $attrs['timestamp'] ?? '',
                'category' => $categorization['category'],
                'event_type' => $categorization['event_type'],
                'confidence' => $categorization['confidence'],
                'details' => [
                    'service' => $attrs['service'] ?? null,
                    'status' => $attrs['status'] ?? null,
                    'message' => isset($attrs['message']) ? substr($attrs['message'], 0, 200) : null,
                ],
                'related_entities' => $entities,
            ];

            $timeline[] = $event;
            $categories[$categorization['category']] = ($categories[$categorization['category']] ?? 0) + 1;
        }

        // Identify patterns
        $patterns = $this->identifyTimelinePatterns($timeline);

        // Generate suggested actions
        $suggested_actions = $this->generateTimelineSuggestions($timeline, $categories, $patterns);

        return [
            'format' => 'event_timeline',
            'timeline' => $timeline,
            'summary' => [
                'total_events' => count($timeline),
                'categories' => $categories,
                'key_patterns' => $patterns,
            ],
            'suggested_actions' => $suggested_actions,
            'query' => $query,
            'time_range' => $time_range,
        ];
    }

    /**
     * Identifies patterns in the timeline.
     *
     * @param  array  $timeline
     *
     * @return array
     */
    protected function identifyTimelinePatterns(array $timeline): array
    {
        $patterns = [];

        // Pattern 1: Error cascade (error in one service followed by errors in others)
        $error_services = [];
        foreach ($timeline as $event) {
            if ($event['category'] === 'error') {
                $service = $event['details']['service'] ?? 'unknown';
                $error_services[] = $service;
            }
        }

        if (count(array_unique($error_services)) > 1) {
            $patterns[] = sprintf('Error cascade across %d services', count(array_unique($error_services)));
        }

        // Pattern 2: Deployment followed by errors
        $deployment_followed_by_error = false;
        for ($i = 0; $i < count($timeline) - 1; $i++) {
            if ($timeline[$i]['category'] === 'deployment' && $timeline[$i + 1]['category'] === 'error') {
                $deployment_followed_by_error = true;
                break;
            }
        }

        if ($deployment_followed_by_error) {
            $patterns[] = 'Deployment event preceded error occurrence';
        }

        // Pattern 3: Repeated errors from same service
        $service_error_counts = [];
        foreach ($timeline as $event) {
            if ($event['category'] === 'error') {
                $service = $event['details']['service'] ?? 'unknown';
                $service_error_counts[$service] = ($service_error_counts[$service] ?? 0) + 1;
            }
        }

        foreach ($service_error_counts as $service => $count) {
            if ($count >= 3) {
                $patterns[] = sprintf('Repeated errors in %s service (%d occurrences)', $service, $count);
            }
        }

        return $patterns;
    }

    /**
     * Generates suggested actions for timeline analysis.
     *
     * @param  array  $timeline
     * @param  array  $categories
     * @param  array  $patterns
     *
     * @return array
     */
    protected function generateTimelineSuggestions(array $timeline, array $categories, array $patterns): array
    {
        $suggestions = [];

        $error_count = $categories['error'] ?? 0;
        $deployment_count = $categories['deployment'] ?? 0;

        // Suggestion based on patterns
        if (in_array('Deployment event preceded error occurrence', $patterns, true)) {
            $suggestions[] = 'Review recent deployment logs for potential issues';
            $suggestions[] = 'Consider rollback if error rate is critical';
        }

        if ($error_count > 0) {
            $suggestions[] = sprintf('Investigate %d error events for root cause', $error_count);

            // Check for specific error types
            $timeout_errors = 0;
            $connection_errors = 0;
            foreach ($timeline as $event) {
                if ($event['category'] === 'error') {
                    if (str_contains($event['event_type'], 'timeout')) {
                        $timeout_errors++;
                    }
                    if (str_contains($event['event_type'], 'Connection')) {
                        $connection_errors++;
                    }
                }
            }

            if ($timeout_errors > 2) {
                $suggestions[] = 'High frequency of timeout errors - check service health and response times';
            }

            if ($connection_errors > 2) {
                $suggestions[] = 'Multiple connection errors detected - verify network and service availability';
            }
        }

        // Check for trace_id to suggest distributed tracing
        $has_trace = false;
        foreach ($timeline as $event) {
            if (!empty($event['related_entities']['trace_id'])) {
                $has_trace = true;
                break;
            }
        }

        if ($has_trace && $error_count > 0) {
            $suggestions[] = 'Use trace_id to analyze full distributed request flow';
        }

        if (empty($suggestions)) {
            $suggestions[] = 'Timeline shows normal operation - no immediate actions required';
        }

        return $suggestions;
    }

    /**
     * Formats response as error signatures with grouped patterns.
     *
     * @param  array  $response
     * @param  string  $query
     * @param  string  $time_range
     * @param  int  $from
     * @param  int  $to
     *
     * @return array
     */
    protected function formatErrorSignatures(array $response, string $query, string $time_range, int $from, int $to): array
    {
        $data = $response['data'] ?? [];
        $error_logs = [];

        // Filter to error logs only
        foreach ($data as $log) {
            $attrs = $log['attributes'] ?? [];
            $status = $attrs['status'] ?? '';

            if ($status === 'error' || isset($attrs['error'])) {
                $error_logs[] = $log;
            }
        }

        if (empty($error_logs)) {
            return [
                'format' => 'error_signatures',
                'signatures' => [],
                'summary' => [
                    'total_signatures' => 0,
                    'total_occurrences' => 0,
                ],
                'query' => $query,
                'time_range' => $time_range,
            ];
        }

        // Group by normalized message
        $signature_groups = [];

        foreach ($error_logs as $log) {
            $attrs = $log['attributes'] ?? [];
            $message = $attrs['message'] ?? '';

            // Normalize message
            $normalized = $this->normalizeErrorMessage($message);
            $hash = md5($normalized);

            if (!isset($signature_groups[$hash])) {
                $signature_groups[$hash] = [
                    'normalized' => $normalized,
                    'original_message' => $message,
                    'logs' => [],
                    'timestamps' => [],
                    'services' => [],
                    'users' => [],
                ];
            }

            $signature_groups[$hash]['logs'][] = $log;
            $signature_groups[$hash]['timestamps'][] = $attrs['timestamp'] ?? '';

            if (isset($attrs['service'])) {
                $signature_groups[$hash]['services'][$attrs['service']] = true;
            }

            // Extract user ID
            if (isset($attrs['user_id'])) {
                $signature_groups[$hash]['users'][$attrs['user_id']] = true;
            } elseif (isset($attrs['user']['id'])) {
                $signature_groups[$hash]['users'][$attrs['user']['id']] = true;
            }
        }

        // Build signatures
        $signatures = [];
        $total_errors = count($error_logs);

        foreach ($signature_groups as $hash => $group) {
            $count = count($group['logs']);
            $service_count = count($group['services']);
            $user_count = count($group['users']);

            // Generate pattern name from normalized message
            $pattern_name = $this->generatePatternName($group['normalized']);

            // Calculate trend
            $trend = $this->calculateTrend($group['timestamps']);

            // Assign severity
            $severity = $this->assignSeverity($count, $total_errors, $service_count);

            // Calculate confidence
            $confidence = $count > 20 ? 0.9 : ($count > 5 ? 0.7 : 0.5);

            // Generate recommendation
            $recommendation = $this->generateErrorRecommendation($pattern_name, $severity, $service_count);

            $signatures[] = [
                'signature_id' => substr($hash, 0, 12),
                'pattern_name' => $pattern_name,
                'pattern' => $group['normalized'],
                'count' => $count,
                'severity' => $severity,
                'trend' => $trend,
                'confidence' => $confidence,
                'first_seen' => min($group['timestamps']),
                'last_seen' => max($group['timestamps']),
                'affected_services' => array_keys($group['services']),
                'affected_users' => $user_count,
                'example_message' => substr($group['original_message'], 0, 200),
                'recommendation' => $recommendation,
            ];
        }

        // Sort by count descending
        usort($signatures, fn ($a, $b) => $b['count'] <=> $a['count']);

        return [
            'format' => 'error_signatures',
            'signatures' => $signatures,
            'summary' => [
                'total_signatures' => count($signatures),
                'total_occurrences' => $total_errors,
            ],
            'query' => $query,
            'time_range' => $time_range,
        ];
    }

    /**
     * Generates a pattern name from normalized error message.
     *
     * @param  string  $normalized
     *
     * @return string
     */
    protected function generatePatternName(string $normalized): string
    {
        $lower = strtolower($normalized);

        // Database errors
        if (preg_match('/database|db|mysql|postgres/', $lower)) {
            if (preg_match('/timeout/', $lower)) {
                return 'Database Connection Timeout';
            }
            if (preg_match('/connection|connect/', $lower)) {
                return 'Database Connection Failure';
            }
            return 'Database Error';
        }

        // HTTP errors
        if (preg_match('/http|https/', $lower)) {
            if (preg_match('/timeout/', $lower)) {
                return 'HTTP Timeout Error';
            }
            if (preg_match('/500/', $lower)) {
                return 'HTTP 500 Internal Server Error';
            }
            if (preg_match('/404/', $lower)) {
                return 'HTTP 404 Not Found';
            }
            return 'HTTP Error';
        }

        // Authentication errors
        if (preg_match('/auth|unauthorized|forbidden/', $lower)) {
            return 'Authentication Failure';
        }

        // Connection errors
        if (preg_match('/connection|connect|refused/', $lower)) {
            return 'Connection Error';
        }

        // Timeout errors
        if (preg_match('/timeout|timed out/', $lower)) {
            return 'Timeout Error';
        }

        // Not found errors
        if (preg_match('/not found|notfound/', $lower)) {
            return 'Resource Not Found';
        }

        // Generic
        return 'Error Pattern';
    }

    /**
     * Generates recommendation for error signature.
     *
     * @param  string  $pattern_name
     * @param  string  $severity
     * @param  int  $service_count
     *
     * @return string
     */
    protected function generateErrorRecommendation(string $pattern_name, string $severity, int $service_count): string
    {
        $recommendations = [
            'Database Connection Timeout' => 'Review database connection pool settings and query performance. Consider increasing timeout threshold or optimizing slow queries.',
            'Database Connection Failure' => 'Check database server health and network connectivity. Verify connection pool configuration.',
            'HTTP Timeout Error' => 'Investigate service response times. Check for downstream dependencies or network issues.',
            'HTTP 500 Internal Server Error' => 'Review application logs for stack traces. Check for recent deployments or configuration changes.',
            'Authentication Failure' => 'Verify authentication service health. Check for expired tokens or misconfigured credentials.',
            'Connection Error' => 'Verify network connectivity and service availability. Check firewall rules and DNS resolution.',
            'Timeout Error' => 'Investigate service performance and response times. Consider increasing timeout thresholds if appropriate.',
            'Resource Not Found' => 'Verify resource IDs and API endpoints. Check for data consistency issues.',
        ];

        $base_recommendation = $recommendations[$pattern_name] ?? 'Investigate error logs for root cause and common patterns.';

        if ($severity === 'critical') {
            return 'CRITICAL: ' . $base_recommendation . ' Immediate action required.';
        }

        if ($service_count > 3) {
            return $base_recommendation . ' Affects multiple services - check for shared dependencies.';
        }

        return $base_recommendation;
    }

    /**
     * Formats response as field statistics with outlier detection.
     *
     * @param  array  $response
     * @param  string  $query
     * @param  string  $time_range
     * @param  int  $from
     * @param  int  $to
     * @param  string  $field
     *
     * @return array
     */
    protected function formatFieldStats(array $response, string $query, string $time_range, int $from, int $to, string $field): array
    {
        // Extract numeric values
        $values = $this->extractNumericValues($response, $field);

        if (empty($values)) {
            return [
                'format' => 'field_stats',
                'field' => $field,
                'stats' => null,
                'interpretation' => sprintf('No numeric values found for field "%s"', $field),
                'anomalies_detected' => false,
                'confidence' => 0.0,
                'query' => $query,
                'time_range' => $time_range,
            ];
        }

        // Extract just the numeric values for calculations
        $numeric_values = array_map(fn ($v) => $v['value'], $values);
        sort($numeric_values);

        // Calculate statistics
        $count = count($numeric_values);
        $min = min($numeric_values);
        $max = max($numeric_values);
        $sum = array_sum($numeric_values);
        $mean = $sum / $count;
        $median = $this->calculatePercentile($numeric_values, 50);
        $p95 = $this->calculatePercentile($numeric_values, 95);
        $p99 = $this->calculatePercentile($numeric_values, 99);

        // Calculate standard deviation
        $variance = 0;
        foreach ($numeric_values as $value) {
            $variance += ($value - $mean) ** 2;
        }
        $stddev = sqrt($variance / $count);

        // Calculate percentiles for outlier detection
        $q1 = $this->calculatePercentile($numeric_values, 25);
        $q3 = $this->calculatePercentile($numeric_values, 75);

        // Detect outliers
        $outliers = $this->detectOutliers($values, $q1, $q3);

        // Generate distribution buckets
        $distribution = $this->generateDistributionBuckets($numeric_values, $min, $max);

        // Generate interpretation
        $interpretation = $this->generateFieldStatsInterpretation($min, $max, $mean, $median, $p99, $stddev, count($outliers));

        // Detect anomalies
        $anomalies_detected = $max > $mean * 10 || $stddev > $mean || count($outliers) > 0;

        // Calculate confidence
        $confidence = $count > 100 ? 0.9 : ($count > 20 ? 0.7 : ($count > 5 ? 0.5 : 0.3));

        return [
            'format' => 'field_stats',
            'field' => $field,
            'stats' => [
                'count' => $count,
                'min' => round($min, 2),
                'max' => round($max, 2),
                'mean' => round($mean, 2),
                'median' => round($median, 2),
                'p95' => round($p95, 2),
                'p99' => round($p99, 2),
                'stddev' => round($stddev, 2),
            ],
            'distribution' => $distribution,
            'outliers' => $outliers,
            'interpretation' => $interpretation,
            'anomalies_detected' => $anomalies_detected,
            'confidence' => round($confidence, 2),
            'query' => $query,
            'time_range' => $time_range,
        ];
    }

    /**
     * Generates distribution buckets for numeric values.
     *
     * @param  array  $sorted_values
     * @param  float  $min
     * @param  float  $max
     *
     * @return array
     */
    protected function generateDistributionBuckets(array $sorted_values, float $min, float $max): array
    {
        $range = $max - $min;

        if ($range === 0.0) {
            return [
                ['range' => (string) $min, 'count' => count($sorted_values)],
            ];
        }

        // Determine bucket size (aim for 10 buckets)
        $bucket_count = min(10, count($sorted_values));
        $bucket_size = $range / $bucket_count;

        // Create buckets
        $buckets = [];
        for ($i = 0; $i < $bucket_count; $i++) {
            $bucket_start = $min + ($i * $bucket_size);
            $bucket_end = $i === $bucket_count - 1 ? $max : $min + (($i + 1) * $bucket_size);

            $count = 0;
            foreach ($sorted_values as $value) {
                if ($value >= $bucket_start && ($i === $bucket_count - 1 ? $value <= $bucket_end : $value < $bucket_end)) {
                    $count++;
                }
            }

            if ($count > 0) {
                $buckets[] = [
                    'range' => sprintf('%.0f-%.0f', $bucket_start, $bucket_end),
                    'count' => $count,
                ];
            }
        }

        return $buckets;
    }

    /**
     * Generates human-readable interpretation of field statistics.
     *
     * @param  float  $min
     * @param  float  $max
     * @param  float  $mean
     * @param  float  $median
     * @param  float  $p99
     * @param  float  $stddev
     * @param  int  $outlier_count
     *
     * @return string
     */
    protected function generateFieldStatsInterpretation(float $min, float $max, float $mean, float $median, float $p99, float $stddev, int $outlier_count): string
    {
        $parts = [];

        // Overall summary
        $parts[] = sprintf('Most values fall within %.0f-%.0f range (median: %.0f)', $min, $median * 2, $median);

        // Variance analysis
        if ($stddev > $mean) {
            $parts[] = sprintf('High variance detected (stddev: %.0f > mean: %.0f) indicating inconsistent performance', $stddev, $mean);
        } elseif ($stddev > $mean * 0.5) {
            $parts[] = sprintf('Moderate variance (stddev: %.0f)', $stddev);
        }

        // P99 analysis
        if ($p99 > $median * 2) {
            $parts[] = sprintf('P99 (%.0f) significantly higher than median - some requests experience delays', $p99);
        }

        // Outlier analysis
        if ($outlier_count > 0) {
            $parts[] = sprintf('%d outlier(s) detected with values significantly above normal range', $outlier_count);
        }

        // Max analysis
        if ($max > $mean * 10) {
            $parts[] = sprintf('Extreme outlier detected: max value (%.0f) is %.0fx the mean', $max, $max / $mean);
        }

        return implode('. ', $parts) . '.';
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

    /**
     * Categorizes a log event based on its attributes.
     *
     * @param  array  $log_attributes  Log attributes array
     *
     * @return array{category: string, event_type: string, confidence: float}
     */
    protected function categorizeEvent(array $log_attributes): array
    {
        $message = $log_attributes['message'] ?? '';
        $status = $log_attributes['status'] ?? '';
        $message_lower = strtolower($message);

        // Deployment detection
        if (preg_match('/\b(deploy|deployment|release|rollback|rollout)\b/i', $message)) {
            if (preg_match('/\b(start|begin|initiat)/i', $message)) {
                return ['category' => 'deployment', 'event_type' => 'Deployment started', 'confidence' => 0.9];
            }
            if (preg_match('/\b(complet|finish|success|done)/i', $message)) {
                return ['category' => 'deployment', 'event_type' => 'Deployment completed', 'confidence' => 0.9];
            }
            if (preg_match('/\b(fail|error)/i', $message)) {
                return ['category' => 'deployment', 'event_type' => 'Deployment failed', 'confidence' => 0.9];
            }
            return ['category' => 'deployment', 'event_type' => 'Deployment event', 'confidence' => 0.7];
        }

        // Error detection
        if ($status === 'error' || preg_match('/\b(error|exception|fatal|critical)\b/i', $message)) {
            if (preg_match('/\b(timeout|timed out)\b/i', $message)) {
                if (preg_match('/\b(database|db|mysql|postgres)\b/i', $message)) {
                    return ['category' => 'error', 'event_type' => 'Database timeout', 'confidence' => 0.9];
                }
                return ['category' => 'error', 'event_type' => 'Timeout error', 'confidence' => 0.9];
            }
            if (preg_match('/\b(connection|connect)\b/i', $message)) {
                return ['category' => 'error', 'event_type' => 'Connection error', 'confidence' => 0.9];
            }
            if (preg_match('/\b(authentication|auth|unauthorized)\b/i', $message)) {
                return ['category' => 'error', 'event_type' => 'Authentication error', 'confidence' => 0.9];
            }
            if (preg_match('/\b(not found|404)\b/i', $message)) {
                return ['category' => 'error', 'event_type' => 'Resource not found', 'confidence' => 0.9];
            }
            return ['category' => 'error', 'event_type' => 'Error occurred', 'confidence' => 0.7];
        }

        // Warning detection
        if ($status === 'warn' || preg_match('/\b(warn|warning|deprecated)\b/i', $message)) {
            return ['category' => 'warning', 'event_type' => 'Warning logged', 'confidence' => 0.8];
        }

        // Info/default
        if (preg_match('/\b(start|begin|initiat)/i', $message)) {
            return ['category' => 'info', 'event_type' => 'Process started', 'confidence' => 0.7];
        }
        if (preg_match('/\b(complet|finish|success|done)\b/i', $message)) {
            return ['category' => 'info', 'event_type' => 'Process completed', 'confidence' => 0.7];
        }

        return ['category' => 'info', 'event_type' => 'Event logged', 'confidence' => 0.5];
    }

    /**
     * Extracts related entities from log attributes.
     *
     * @param  array  $log_attributes  Log attributes array
     *
     * @return array
     */
    protected function extractRelatedEntities(array $log_attributes): array
    {
        $entities = [];

        // Common entity fields
        $entity_fields = ['service', 'host', 'trace_id', 'user_id', 'transaction_id', 'request_id'];

        foreach ($entity_fields as $field) {
            if (isset($log_attributes[$field])) {
                $entities[$field] = $log_attributes[$field];
            }
        }

        // Check for nested user info
        if (isset($log_attributes['user']) && is_array($log_attributes['user'])) {
            if (isset($log_attributes['user']['id'])) {
                $entities['user_id'] = $log_attributes['user']['id'];
            }
        }

        return $entities;
    }

    /**
     * Normalizes an error message by replacing variable parts with placeholders.
     *
     * @param  string  $message  The error message
     *
     * @return string  The normalized message
     */
    protected function normalizeErrorMessage(string $message): string
    {
        // Replace UUIDs
        $normalized = preg_replace('/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i', '[UUID]', $message);

        // Replace numbers (but preserve common error codes like 404, 500)
        $normalized = preg_replace('/\b(?<!HTTP |error |code |status )\d{5,}\b/', '[NUM]', $normalized);

        // Replace URLs
        $normalized = preg_replace('#\bhttps?://[^\s]+#i', '[URL]', $normalized);

        // Replace file paths
        $normalized = preg_replace('#/[a-z0-9_\-/]+\.(php|js|py|rb|java|go|rs)\b#i', '[PATH]', $normalized);

        // Replace IP addresses
        $normalized = preg_replace('/\b(?:\d{1,3}\.){3}\d{1,3}\b/', '[IP]', $normalized);

        // Replace timestamps (ISO 8601)
        $normalized = preg_replace('/\b\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', '[TIMESTAMP]', $normalized);

        return $normalized;
    }

    /**
     * Calculates the trend of events based on timestamps.
     *
     * @param  array  $timestamps  Array of ISO 8601 timestamps
     *
     * @return string  'increasing', 'stable', or 'decreasing'
     */
    protected function calculateTrend(array $timestamps): string
    {
        if (count($timestamps) < 2) {
            return 'stable';
        }

        // Convert timestamps to Unix timestamps
        $unix_timestamps = array_map(fn ($ts) => strtotime($ts), $timestamps);
        sort($unix_timestamps);

        // Calculate midpoint
        $midpoint_index = (int) (count($unix_timestamps) / 2);

        // Compare first half vs second half density
        $first_half = array_slice($unix_timestamps, 0, $midpoint_index);
        $second_half = array_slice($unix_timestamps, $midpoint_index);

        if (empty($first_half) || empty($second_half)) {
            return 'stable';
        }

        $first_half_count = count($first_half);
        $second_half_count = count($second_half);

        // Calculate density (events per minute)
        $first_half_span = max($first_half) - min($first_half) ?: 1;
        $second_half_span = max($second_half) - min($second_half) ?: 1;

        $first_density = $first_half_count / $first_half_span;
        $second_density = $second_half_count / $second_half_span;

        // Compare densities (with 20% threshold to avoid noise)
        if ($second_density > $first_density * 1.2) {
            return 'increasing';
        }
        if ($second_density < $first_density * 0.8) {
            return 'decreasing';
        }

        return 'stable';
    }

    /**
     * Assigns severity level based on error metrics.
     *
     * @param  int  $count  Number of occurrences
     * @param  int  $total_count  Total number of errors
     * @param  int  $service_count  Number of affected services
     *
     * @return string  'critical', 'high', 'medium', or 'low'
     */
    protected function assignSeverity(int $count, int $total_count, int $service_count): string
    {
        $percentage = $total_count > 0 ? ($count / $total_count) * 100 : 0;

        // Critical: >100 occurrences OR >50% of all errors
        if ($count > 100 || $percentage > 50) {
            return 'critical';
        }

        // High: >50 occurrences OR affects >3 services
        if ($count > 50 || $service_count > 3) {
            return 'high';
        }

        // Medium: >10 occurrences
        if ($count > 10) {
            return 'medium';
        }

        return 'low';
    }

    /**
     * Extracts numeric values from a specified field in the response.
     *
     * @param  array  $response  The Datadog API response
     * @param  string  $field  The field name (e.g., "@duration")
     *
     * @return array  Array of ['value' => float, 'timestamp' => string, 'log' => array]
     */
    protected function extractNumericValues(array $response, string $field): array
    {
        $values = [];
        $data = $response['data'] ?? [];

        // Remove @ prefix for attribute access
        $field_key = ltrim($field, '@');

        foreach ($data as $log) {
            $attrs = $log['attributes'] ?? [];

            // Try direct attribute access
            $value = $attrs[$field_key] ?? null;

            // Try nested attribute access (e.g., "http.response_time")
            if ($value === null && str_contains($field_key, '.')) {
                $parts = explode('.', $field_key);
                $value = $attrs;
                foreach ($parts as $part) {
                    if (is_array($value) && isset($value[$part])) {
                        $value = $value[$part];
                    } else {
                        $value = null;
                        break;
                    }
                }
            }

            // Only include numeric values
            if (is_numeric($value)) {
                $values[] = [
                    'value' => (float) $value,
                    'timestamp' => $attrs['timestamp'] ?? '',
                    'log' => $log,
                ];
            }
        }

        return $values;
    }

    /**
     * Calculates the value at a given percentile.
     *
     * @param  array  $sorted_values  Sorted array of numeric values
     * @param  float  $percentile  Percentile (0-100)
     *
     * @return float  The value at the percentile
     */
    protected function calculatePercentile(array $sorted_values, float $percentile): float
    {
        if (empty($sorted_values)) {
            return 0.0;
        }

        $index = ($percentile / 100) * (count($sorted_values) - 1);
        $lower = floor($index);
        $upper = ceil($index);

        if ($lower === $upper) {
            return $sorted_values[(int) $index];
        }

        // Linear interpolation between two values
        $weight = $index - $lower;
        return $sorted_values[(int) $lower] * (1 - $weight) + $sorted_values[(int) $upper] * $weight;
    }

    /**
     * Detects outliers using the IQR method.
     *
     * @param  array  $values  Array of ['value' => float, 'timestamp' => string, 'log' => array]
     * @param  float  $q1  First quartile value
     * @param  float  $q3  Third quartile value
     *
     * @return array  Array of outliers with context
     */
    protected function detectOutliers(array $values, float $q1, float $q3): array
    {
        $iqr = $q3 - $q1;
        $lower_bound = $q1 - (1.5 * $iqr);
        $upper_bound = $q3 + (1.5 * $iqr);

        $outliers = [];

        foreach ($values as $item) {
            $value = $item['value'];
            if ($value < $lower_bound || $value > $upper_bound) {
                $log = $item['log'];
                $attrs = $log['attributes'] ?? [];

                $outliers[] = [
                    'value' => $value,
                    'timestamp' => $item['timestamp'],
                    'context' => [
                        'service' => $attrs['service'] ?? null,
                        'host' => $attrs['host'] ?? null,
                        'message' => isset($attrs['message']) ? substr($attrs['message'], 0, 100) : null,
                    ],
                ];
            }
        }

        // Sort by value descending and limit to top 10
        usort($outliers, fn ($a, $b) => $b['value'] <=> $a['value']);

        return array_slice($outliers, 0, 10);
    }

    /**
     * Enriches response with parsed message fields (JSON/XML extraction).
     *
     * Automatically detects and parses structured data in message fields,
     * making previously opaque JSON/XML queryable via dot notation.
     *
     * @param  array  $response  The Datadog API response
     *
     * @return array  Enriched response with message_parsed.* fields
     */
    protected function enrichWithParsedMessages(array $response): array
    {
        if (!isset($response['data'])) {
            return $response;
        }

        foreach ($response['data'] as &$log) {
            $attrs = $log['attributes'] ?? [];
            $parsed = $this->parseMessageField($attrs);

            if ($parsed !== null) {
                // Merge parsed fields into attributes
                $log['attributes'] = array_merge($attrs, $parsed);
            }
        }
        unset($log); // Break reference

        return $response;
    }

    /**
     * Parses structured data (JSON/XML) from message field.
     *
     * Detects and extracts JSON or XML embedded in log messages,
     * enabling rich querying of previously unstructured data.
     *
     * @param  array  $log_attributes  Log attributes array
     *
     * @return array|null  Flattened parsed data or null if no structure found
     */
    protected function parseMessageField(array $log_attributes): ?array
    {
        $message = $log_attributes['message'] ?? '';

        // Try JSON parsing (most common case)
        if (str_contains($message, '{') || str_contains($message, '[')) {
            // Extract JSON substring (handles text before/after JSON)
            if (preg_match('/\{.*\}|\[.*\]/s', $message, $matches)) {
                $json_str = $matches[0];
                $parsed = json_decode($json_str, true);

                if (json_last_error() === JSON_ERROR_NONE) {
                    return $this->flattenParsedData($parsed, 'message_parsed');
                }
            }
        }

        // Try XML parsing
        if (str_contains($message, '<') && str_contains($message, '>')) {
            libxml_use_internal_errors(true);
            $xml = simplexml_load_string($message);

            if ($xml !== false) {
                $parsed = json_decode(json_encode($xml), true);
                return $this->flattenParsedData($parsed, 'message_parsed');
            }
        }

        return null;
    }

    /**
     * Flattens nested array into dot-notation keys.
     *
     * Converts nested structures like ['error' => ['code' => 18028]]
     * into flat keys like 'message_parsed.error.code' => 18028
     *
     * @param  array  $data  Nested array to flatten
     * @param  string  $prefix  Key prefix (e.g., 'message_parsed')
     *
     * @return array  Flattened array with dot-notation keys
     */
    protected function flattenParsedData(array $data, string $prefix = ''): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            $full_key = $prefix ? $prefix . '.' . $key : $key;

            if (is_array($value) && !empty($value) && array_values($value) === $value) {
                // Indexed array - serialize as JSON
                $result[$full_key] = json_encode($value);
            } elseif (is_array($value) && !empty($value)) {
                // Associative array - recursive flatten
                $result = array_merge($result, $this->flattenParsedData($value, $full_key));
            } else {
                // Scalar value
                $result[$full_key] = $value;
            }
        }

        return $result;
    }

    /**
     * Formats response as batch comparison showing differences between successes and failures.
     *
     * @param  array  $response
     * @param  string  $query
     * @param  string  $time_range
     * @param  int  $from
     * @param  int  $to
     * @param  string|null  $batch_field
     *
     * @return array
     */
    protected function formatCompareBatchOutcomes(
        array $response,
        string $query,
        string $time_range,
        int $from,
        int $to,
        ?string $batch_field = null
    ): array {
        $data = $response['data'] ?? [];

        // Auto-detect batch field if not specified
        if ($batch_field === null) {
            $batch_field = $this->detectBatchField($data);
        }

        if ($batch_field === null) {
            return [
                'format' => 'compare_batch_outcomes',
                'error' => 'No batch field detected in logs',
                'suggestion' => 'Specify batch_field parameter or ensure logs contain batch_id/transaction_id/feed_id'
            ];
        }

        // Group logs by batch
        $batches = $this->groupByField($data, $batch_field);

        // Find batches with mixed outcomes (successes + failures)
        $mixed_batches = array_filter($batches, fn($logs) => $this->hasMixedOutcomes($logs));

        if (empty($mixed_batches)) {
            return [
                'format' => 'compare_batch_outcomes',
                'error' => 'No batches with mixed outcomes found',
                'suggestion' => 'Try expanding time range or adjusting query'
            ];
        }

        // Analyze largest mixed batch
        $largest_batch = array_reduce($mixed_batches, fn($a, $b) => count($a) > count($b) ? $a : $b, []);
        $batch_id = $this->extractFieldValueFromLog($largest_batch[0], $batch_field) ?? 'unknown';

        // Partition into success/failure
        [$successes, $failures] = $this->partitionByOutcome($largest_batch);

        // Aggregate metrics
        $success_metrics = $this->aggregateBatchMetrics($successes);
        $failure_metrics = $this->aggregateBatchMetrics($failures);

        // Identify differences
        $differences = $this->identifyKeyDifferences($success_metrics, $failure_metrics);

        // Generate hypothesis
        $hypothesis = $this->generateBatchHypothesis($differences);

        // Calculate confidence
        $confidence = $this->calculateBatchConfidence(count($successes), count($failures), $differences);

        return [
            'format' => 'compare_batch_outcomes',
            'batch_id' => $batch_id,
            'successful_orders' => count($successes),
            'failed_orders' => count($failures),
            'comparison' => [
                'successful_orders_attributes' => $success_metrics,
                'failed_orders_attributes' => $failure_metrics,
                'key_differences' => $differences,
            ],
            'hypothesis' => $hypothesis,
            'confidence' => round($confidence, 2),
            'recommendation' => $this->generateBatchRecommendation($hypothesis, $differences),
            'query' => $query,
            'time_range' => $time_range,
        ];
    }

    /**
     * Auto-detects batch field from logs.
     *
     * @param  array  $data
     *
     * @return string|null
     */
    protected function detectBatchField(array $data): ?string
    {
        $candidates = ['batch_id', 'transaction_id', 'feed_id', 'correlation_id'];

        foreach ($candidates as $field) {
            if ($this->fieldExistsInLogs($data, $field)) {
                return $field;
            }
        }

        return null;
    }

    /**
     * Checks if field exists in any log entry.
     *
     * @param  array  $data
     * @param  string  $field
     *
     * @return bool
     */
    protected function fieldExistsInLogs(array $data, string $field): bool
    {
        foreach ($data as $log) {
            if ($this->extractFieldValueFromLog($log, $field) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extracts field value from log entry.
     *
     * @param  array  $log
     * @param  string  $field
     *
     * @return mixed
     */
    protected function extractFieldValueFromLog(array $log, string $field): mixed
    {
        $attrs = $log['attributes'] ?? [];

        // Try direct access
        if (isset($attrs[$field])) {
            return $attrs[$field];
        }

        // Try nested access (dot notation)
        if (str_contains($field, '.')) {
            $parts = explode('.', $field);
            $value = $attrs;
            foreach ($parts as $part) {
                if (is_array($value) && isset($value[$part])) {
                    $value = $value[$part];
                } else {
                    return null;
                }
            }
            return $value;
        }

        return null;
    }

    /**
     * Groups logs by field value.
     *
     * @param  array  $data
     * @param  string  $field
     *
     * @return array
     */
    protected function groupByField(array $data, string $field): array
    {
        $groups = [];

        foreach ($data as $log) {
            $value = $this->extractFieldValueFromLog($log, $field);
            if ($value !== null) {
                $key = is_scalar($value) ? (string) $value : json_encode($value);
                $groups[$key][] = $log;
            }
        }

        return $groups;
    }

    /**
     * Checks if logs contain both successes and failures.
     *
     * @param  array  $logs
     *
     * @return bool
     */
    protected function hasMixedOutcomes(array $logs): bool
    {
        $has_success = false;
        $has_failure = false;

        foreach ($logs as $log) {
            $status = $log['attributes']['status'] ?? '';
            if ($status === 'error') {
                $has_failure = true;
            } else {
                $has_success = true;
            }

            if ($has_success && $has_failure) {
                return true;
            }
        }

        return false;
    }

    /**
     * Partitions logs into successes and failures.
     *
     * @param  array  $logs
     *
     * @return array
     */
    protected function partitionByOutcome(array $logs): array
    {
        $successes = [];
        $failures = [];

        foreach ($logs as $log) {
            $status = $log['attributes']['status'] ?? '';
            if ($status === 'error') {
                $failures[] = $log;
            } else {
                $successes[] = $log;
            }
        }

        return [$successes, $failures];
    }

    /**
     * Aggregates metrics from batch logs.
     *
     * @param  array  $logs
     *
     * @return array
     */
    protected function aggregateBatchMetrics(array $logs): array
    {
        $metrics = [
            'count' => count($logs),
            'services' => [],
            'avg_timing' => null,
            'common_attributes' => [],
        ];

        // Count services
        foreach ($logs as $log) {
            $service = $log['attributes']['service'] ?? 'unknown';
            $metrics['services'][$service] = ($metrics['services'][$service] ?? 0) + 1;
        }

        // Calculate timing if timestamps present
        $timestamps = array_map(fn($log) => strtotime($log['attributes']['timestamp'] ?? ''), $logs);
        $timestamps = array_filter($timestamps, fn($ts) => $ts !== false);

        if (!empty($timestamps)) {
            $metrics['avg_timing'] = (max($timestamps) - min($timestamps)) / 60; // minutes
        }

        return $metrics;
    }

    /**
     * Identifies key differences between success and failure metrics.
     *
     * @param  array  $success_metrics
     * @param  array  $failure_metrics
     *
     * @return array
     */
    protected function identifyKeyDifferences(array $success_metrics, array $failure_metrics): array
    {
        $differences = [];

        // Compare timing
        if (isset($success_metrics['avg_timing']) && isset($failure_metrics['avg_timing'])) {
            $timing_diff = abs($success_metrics['avg_timing'] - $failure_metrics['avg_timing']);

            if ($timing_diff > 5) { // More than 5 minutes difference
                $differences[] = [
                    'attribute' => 'timing',
                    'success_value' => sprintf('%.0f minutes average', $success_metrics['avg_timing']),
                    'failure_value' => sprintf('%.0f minutes', $failure_metrics['avg_timing']),
                    'interpretation' => sprintf(
                        'Failed orders processed %.0f minutes %s than successful',
                        $timing_diff,
                        $failure_metrics['avg_timing'] < $success_metrics['avg_timing'] ? 'earlier' : 'later'
                    ),
                    'significance' => 'high'
                ];
            }
        }

        // Compare services
        $success_services = array_keys($success_metrics['services'] ?? []);
        $failure_services = array_keys($failure_metrics['services'] ?? []);

        $service_diff = array_diff($failure_services, $success_services);
        if (!empty($service_diff)) {
            $differences[] = [
                'attribute' => 'services',
                'success_value' => implode(', ', $success_services),
                'failure_value' => implode(', ', $failure_services),
                'interpretation' => 'Failed orders involved different services: ' . implode(', ', $service_diff),
                'significance' => 'medium'
            ];
        }

        return $differences;
    }

    /**
     * Generates hypothesis from differences.
     *
     * @param  array  $differences
     *
     * @return string
     */
    protected function generateBatchHypothesis(array $differences): string
    {
        if (empty($differences)) {
            return 'No significant differences detected';
        }

        // Generate hypothesis based on most significant difference
        $primary = $differences[0];

        if ($primary['attribute'] === 'timing') {
            return 'Timing race condition may be causing failures';
        }

        if ($primary['attribute'] === 'services') {
            return 'Service involvement differs between success and failure cases';
        }

        return 'Detected pattern differences between success and failure cases';
    }

    /**
     * Calculates confidence for batch comparison.
     *
     * @param  int  $success_count
     * @param  int  $failure_count
     * @param  array  $differences
     *
     * @return float
     */
    protected function calculateBatchConfidence(int $success_count, int $failure_count, array $differences): float
    {
        // Base confidence on sample size and clarity of differences
        $min_count = min($success_count, $failure_count);
        $sample_confidence = $min_count > 20 ? 0.9 : ($min_count > 5 ? 0.7 : 0.5);

        $diff_confidence = count($differences) > 2 ? 0.9 : (count($differences) > 0 ? 0.7 : 0.3);

        return ($sample_confidence + $diff_confidence) / 2;
    }

    /**
     * Generates recommendation based on hypothesis.
     *
     * @param  string  $hypothesis
     * @param  array  $differences
     *
     * @return string
     */
    protected function generateBatchRecommendation(string $hypothesis, array $differences): string
    {
        if (str_contains($hypothesis, 'timing')) {
            return 'Add validation delays or pre-checks before processing';
        }

        if (str_contains($hypothesis, 'service')) {
            return 'Investigate service dependency differences';
        }

        return 'Investigate differences in attributes between success and failure cases';
    }

    /**
     * Formats response as causal chain showing event timeline leading to errors.
     *
     * @param  array  $response
     * @param  string  $query
     * @param  string  $time_range
     * @param  int  $from
     * @param  int  $to
     * @param  string|null  $correlation_field
     * @param  int  $lookback_minutes
     *
     * @return array
     */
    protected function formatCausalChain(
        array $response,
        string $query,
        string $time_range,
        int $from,
        int $to,
        ?string $correlation_field = null,
        int $lookback_minutes = 60
    ): array {
        $data = $response['data'] ?? [];

        // Find error events
        $errors = array_filter($data, fn($log) =>
            ($log['attributes']['status'] ?? '') === 'error'
        );

        if (empty($errors)) {
            return [
                'format' => 'causal_chain',
                'error' => 'No error events found in query results',
                'suggestion' => 'Try adding status:error to query'
            ];
        }

        // Take first error as target
        $target_error = reset($errors);

        // Auto-detect correlation field if not specified
        if ($correlation_field === null) {
            $correlation_field = $this->detectCorrelationField($target_error);
        }

        if ($correlation_field === null) {
            return [
                'format' => 'causal_chain',
                'error' => 'No correlation field detected in error log',
                'suggestion' => 'Specify correlation_field parameter or ensure logs contain order_id/trace_id/transaction_id'
            ];
        }

        // Extract correlation ID
        $correlation_id = $this->extractFieldValueFromLog($target_error, $correlation_field);

        if ($correlation_id === null) {
            return [
                'format' => 'causal_chain',
                'error' => "No correlation field '{$correlation_field}' found in error log"
            ];
        }

        // Build causal chain
        $chain = $this->buildCausalChain(
            $data,
            $target_error,
            $correlation_field,
            $correlation_id,
            $lookback_minutes
        );

        // Detect anomalies
        $anomalies = $this->detectAnomalies($chain);

        // Generate recommendations
        $recommendations = $this->generateCausalRecommendations($chain, $anomalies);

        return [
            'format' => 'causal_chain',
            'entity_id' => $correlation_id,
            'correlation_field' => $correlation_field,
            'causal_chain' => $chain,
            'anomalies_detected' => count($anomalies),
            'conclusion' => $this->generateConclusion($chain, $anomalies),
            'recommendations' => $recommendations,
            'query' => $query,
            'time_range' => $time_range,
        ];
    }

    /**
     * Auto-detects correlation field from log.
     *
     * @param  array  $log
     *
     * @return string|null
     */
    protected function detectCorrelationField(array $log): ?string
    {
        $candidates = ['order_id', 'trace_id', 'transaction_id', 'request_id', 'correlation_id'];

        foreach ($candidates as $field) {
            if ($this->extractFieldValueFromLog($log, $field) !== null) {
                return $field;
            }
        }

        return null;
    }

    /**
     * Builds causal chain of events leading to error.
     *
     * @param  array  $all_logs
     * @param  array  $target_error
     * @param  string  $correlation_field
     * @param  string  $correlation_id
     * @param  int  $lookback_minutes
     *
     * @return array
     */
    protected function buildCausalChain(
        array $all_logs,
        array $target_error,
        string $correlation_field,
        string $correlation_id,
        int $lookback_minutes
    ): array {
        $error_timestamp = strtotime($target_error['attributes']['timestamp'] ?? '');
        $lookback_seconds = $lookback_minutes * 60;
        $cutoff_timestamp = $error_timestamp - $lookback_seconds;

        // Filter to relevant logs
        $relevant_logs = array_filter($all_logs, function($log) use ($correlation_field, $correlation_id, $cutoff_timestamp, $error_timestamp) {
            $log_correlation_id = $this->extractFieldValueFromLog($log, $correlation_field);
            $log_timestamp = strtotime($log['attributes']['timestamp'] ?? '');

            return $log_correlation_id === $correlation_id
                && $log_timestamp !== false
                && $log_timestamp >= $cutoff_timestamp
                && $log_timestamp <= $error_timestamp;
        });

        // Sort chronologically
        usort($relevant_logs, fn($a, $b) =>
            strcmp($a['attributes']['timestamp'] ?? '', $b['attributes']['timestamp'] ?? '')
        );

        // Build chain entries
        $chain = [];
        $step = 1;

        foreach ($relevant_logs as $log) {
            $log_timestamp = strtotime($log['attributes']['timestamp'] ?? '');
            $delta_seconds = $error_timestamp - $log_timestamp;
            $delta_minutes = round($delta_seconds / 60);

            $categorization = $this->categorizeEvent($log['attributes'] ?? []);

            $chain[] = [
                'step' => $step++,
                'event' => $categorization['event_type'],
                'timestamp' => $log['attributes']['timestamp'] ?? '',
                'delta_to_error' => sprintf('-%d minutes', $delta_minutes),
                'category' => $categorization['category'],
                'service' => $log['attributes']['service'] ?? 'unknown',
            ];
        }

        return $chain;
    }

    /**
     * Detects anomalies in causal chain.
     *
     * @param  array  $chain
     *
     * @return array
     */
    protected function detectAnomalies(array $chain): array
    {
        $anomalies = [];

        // Check for expected event patterns
        $event_types = array_column($chain, 'event');

        // Example: Check for missing order details fetch
        // This is a simplified check - in production you'd have more sophisticated pattern matching
        $has_acknowledgment = false;
        $has_details_fetch = false;

        foreach ($event_types as $event) {
            if (str_contains(strtolower($event), 'acknow')) {
                $has_acknowledgment = true;
            }
            if (str_contains(strtolower($event), 'fetch') || str_contains(strtolower($event), 'details')) {
                $has_details_fetch = true;
            }
        }

        if ($has_acknowledgment && !$has_details_fetch) {
            $anomalies[] = [
                'type' => 'MISSING_EVENT',
                'expected' => 'Order details fetch',
                'impact' => 'Cannot verify order items before acknowledgment',
                'significance' => 'high'
            ];
        }

        // Check for timing anomalies (events too close together)
        if (count($chain) >= 2) {
            for ($i = 0; $i < count($chain) - 1; $i++) {
                $current_time = strtotime($chain[$i]['timestamp']);
                $next_time = strtotime($chain[$i + 1]['timestamp']);

                if ($next_time - $current_time < 1) { // Less than 1 second apart
                    $anomalies[] = [
                        'type' => 'TIMING_ANOMALY',
                        'expected' => 'Events should be spaced reasonably',
                        'impact' => 'Events occurred too quickly, may indicate race condition',
                        'significance' => 'medium'
                    ];
                    break; // Only report once
                }
            }
        }

        return $anomalies;
    }

    /**
     * Generates conclusion from chain and anomalies.
     *
     * @param  array  $chain
     * @param  array  $anomalies
     *
     * @return string
     */
    protected function generateConclusion(array $chain, array $anomalies): string
    {
        if (!empty($anomalies)) {
            $first = $anomalies[0];
            return $first['expected'] . ' - ' . $first['impact'];
        }

        if (count($chain) === 1) {
            return 'Single event in chain - no context available';
        }

        return 'Event sequence analyzed - no anomalies detected';
    }

    /**
     * Generates recommendations from causal analysis.
     *
     * @param  array  $chain
     * @param  array  $anomalies
     *
     * @return array
     */
    protected function generateCausalRecommendations(array $chain, array $anomalies): array
    {
        $recommendations = [];

        foreach ($anomalies as $anomaly) {
            if ($anomaly['type'] === 'MISSING_EVENT') {
                $recommendations[] = 'Add ' . $anomaly['expected'] . ' step to workflow';
            } elseif ($anomaly['type'] === 'TIMING_ANOMALY') {
                $recommendations[] = 'Add timing validation or delays between critical operations';
            }
        }

        if (empty($recommendations)) {
            $recommendations[] = 'Review event sequence for optimization opportunities';
        }

        return $recommendations;
    }

    /**
     * Formats response with automatic format selection and combined insights.
     *
     * @param  array  $response
     * @param  string  $query
     * @param  string  $time_range
     * @param  int  $from
     * @param  int  $to
     * @param  string|null  $batch_field
     * @param  string|null  $correlation_field
     * @param  int  $lookback_minutes
     *
     * @return array
     */
    protected function formatAuto(
        array $response,
        string $query,
        string $time_range,
        int $from,
        int $to,
        ?string $batch_field = null,
        ?string $correlation_field = null,
        int $lookback_minutes = 60
    ): array {
        $data = $response['data'] ?? [];

        // Analyze query results
        $has_errors = $this->containsErrors($data);
        $has_batches = $this->hasBatchIdentifiers($data);
        $has_correlation_ids = $this->hasCorrelationIds($data);

        $combined_analysis = [
            'format' => 'auto',
            'analyses' => [],
            'data_characteristics' => [
                'has_errors' => $has_errors,
                'has_batches' => $has_batches,
                'has_correlation_ids' => $has_correlation_ids,
                'log_count' => count($data),
            ],
        ];

        // Run error signatures if errors present
        if ($has_errors) {
            $combined_analysis['analyses']['error_signatures'] =
                $this->formatErrorSignatures($response, $query, $time_range, $from, $to);
        }

        // Run batch comparison if mixed outcomes in batches
        if ($has_batches && $has_errors) {
            $batch_analysis = $this->formatCompareBatchOutcomes($response, $query, $time_range, $from, $to, $batch_field);
            // Only include if successful (no error key)
            if (!isset($batch_analysis['error'])) {
                $combined_analysis['analyses']['batch_comparison'] = $batch_analysis;
            }
        }

        // Run causal chain if correlation IDs present
        if ($has_correlation_ids && $has_errors) {
            $causal_analysis = $this->formatCausalChain($response, $query, $time_range, $from, $to, $correlation_field, $lookback_minutes);
            // Only include if successful (no error key)
            if (!isset($causal_analysis['error'])) {
                $combined_analysis['analyses']['causal_chain'] = $causal_analysis;
            }
        }

        // Generate combined insights
        $combined_analysis['insights'] = $this->synthesizeInsights($combined_analysis['analyses']);

        // Add usage hint
        $combined_analysis['usage_hint'] = $this->generateUsageHint($combined_analysis['analyses']);

        return $combined_analysis;
    }

    /**
     * Checks if logs contain error events.
     *
     * @param  array  $data
     *
     * @return bool
     */
    protected function containsErrors(array $data): bool
    {
        foreach ($data as $log) {
            if (($log['attributes']['status'] ?? '') === 'error') {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks if logs contain batch identifiers.
     *
     * @param  array  $data
     *
     * @return bool
     */
    protected function hasBatchIdentifiers(array $data): bool
    {
        return $this->detectBatchField($data) !== null;
    }

    /**
     * Checks if logs contain correlation IDs.
     *
     * @param  array  $data
     *
     * @return bool
     */
    protected function hasCorrelationIds(array $data): bool
    {
        if (empty($data)) {
            return false;
        }

        // Check first log for correlation fields
        return $this->detectCorrelationField($data[0]) !== null;
    }

    /**
     * Synthesizes insights from multiple analyses.
     *
     * @param  array  $analyses
     *
     * @return array
     */
    protected function synthesizeInsights(array $analyses): array
    {
        $insights = [];

        // Extract key findings from batch comparison
        if (isset($analyses['batch_comparison']['hypothesis'])) {
            $insights[] = [
                'source' => 'batch_comparison',
                'insight' => $analyses['batch_comparison']['hypothesis'],
                'confidence' => $analyses['batch_comparison']['confidence'] ?? 0,
                'recommendation' => $analyses['batch_comparison']['recommendation'] ?? null,
            ];
        }

        // Extract key findings from causal chain
        if (isset($analyses['causal_chain']['conclusion'])) {
            $insights[] = [
                'source' => 'causal_chain',
                'insight' => $analyses['causal_chain']['conclusion'],
                'anomalies' => $analyses['causal_chain']['anomalies_detected'] ?? 0,
                'recommendations' => $analyses['causal_chain']['recommendations'] ?? [],
            ];
        }

        // Extract key findings from error signatures
        if (isset($analyses['error_signatures']['signatures'])) {
            $signature_count = count($analyses['error_signatures']['signatures']);
            if ($signature_count > 0) {
                $top_signature = $analyses['error_signatures']['signatures'][0] ?? null;
                if ($top_signature) {
                    $insights[] = [
                        'source' => 'error_signatures',
                        'insight' => sprintf('Most common error: %s (%d occurrences)', $top_signature['pattern_name'], $top_signature['count']),
                        'severity' => $top_signature['severity'] ?? 'unknown',
                        'trend' => $top_signature['trend'] ?? 'unknown',
                    ];
                }
            }
        }

        return $insights;
    }

    /**
     * Generates usage hint based on available analyses.
     *
     * @param  array  $analyses
     *
     * @return string
     */
    protected function generateUsageHint(array $analyses): string
    {
        $available = array_keys($analyses);

        if (count($available) === 0) {
            return 'No analysis performed - query returned data without errors';
        }

        if (count($available) === 1) {
            return 'Single analysis performed: ' . $available[0] . '. For more insights, ensure logs contain batch_id and correlation_id fields.';
        }

        return sprintf('Multiple analyses performed: %s. Check each section for detailed insights.', implode(', ', $available));
    }
}
