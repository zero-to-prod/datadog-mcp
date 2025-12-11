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

            ═══════════════════════════════════════════════════════════════════════════════
            QUICK START (Read This First!)
            ═══════════════════════════════════════════════════════════════════════════════

            Basic workflow:
            1. Calculate time range in milliseconds:
               from = currentTimeMillis - 3600000  // 1 hour ago
               to = currentTimeMillis

            2. Construct query with service and status:
               query = "service:YOUR_SERVICE status:error"

            3. Call tool with moderate limit:
               logs(from, to, query, includeTags=false, limit=50)

            4. Check response:
               - response.data[] contains log entries
               - response.meta.page.after = cursor for next page (null if done)

            5. Interpret and summarize results for the user

            MOST COMMON MISTAKES:
            - Forgetting @ prefix on custom attributes (@http.status_code NOT http.status_code)
            - Using seconds instead of milliseconds for timestamps
            - Using lowercase boolean operators (must be AND/OR/NOT, not and/or/not)
            - Not quoting values with spaces or special characters

            ═══════════════════════════════════════════════════════════════════════════════
            MCP TOOL USAGE NOTES
            ═══════════════════════════════════════════════════════════════════════════════

            This tool is designed for MCP (Model Context Protocol) where YOU (the LLM) should:
            - Calculate timestamps programmatically - users say "last hour", not epoch milliseconds
            - Translate user intent into proper Datadog query syntax
            - Interpret results and present insights in human-readable format
            - Summarize patterns, not dump raw JSON
            - Handle pagination automatically when more data is needed
            - Inform users about data volume before fetching thousands of logs

            Think of yourself as a Datadog expert helping non-technical users understand their logs.

            ═══════════════════════════════════════════════════════════════════════════════
            USER INTENT → QUERY TRANSLATION
            ═══════════════════════════════════════════════════════════════════════════════

            Common requests and how to translate them:

            "Show me errors in production"
            → query: "env:production status:error"
            → time: Last 1 hour

            "Find slow API requests"
            → query: "service:api @duration:>3000"
            → time: Last 1 hour

            "What happened around 2pm today?"
            → query: Based on context (service, status if mentioned)
            → time: Calculate 1:30pm-2:30pm in milliseconds

            "Errors for user 12345"
            → query: "@user.id:12345 status:error"
            → time: Last 24 hours (or based on context)

            "Database issues in the last hour"
            → query: "service:database status:error"
            → time: Last 1 hour

            "Payment failures yesterday"
            → query: "service:payment status:error"
            → time: Yesterday (start of day to end of day)

            "All logs from checkout service"
            → query: "service:checkout"
            → time: Last 1 hour (be cautious with time range on broad queries)

            "500 errors in production"
            → query: "env:production @http.status_code:500"
            → time: Last 1 hour

            "Timeout errors across all services"
            → query: "status:error \"timeout\""
            → time: Last 1-4 hours

            DECISION TREE:

            1. Is a specific service mentioned?
               YES → Add service:SERVICE_NAME
               NO → Check if environment mentioned

            2. Is user asking about errors/problems?
               YES → Add status:error (or status:warn for warnings)
               NO → Continue

            3. Is a specific user/entity mentioned?
               YES → Add @entity.id:VALUE or @user.email:EMAIL
               NO → Continue

            4. Is performance/speed mentioned?
               YES → Add @duration:>THRESHOLD (e.g., @duration:>3000 for 3+ seconds)
               NO → Continue

            5. What time range?
               "now" / "current" → Last 15 minutes
               "recent" / "latest" → Last 1 hour
               "today" → Last 24 hours
               "yesterday" → Previous day (midnight to midnight)
               Specific time → Calculate 30min-1hr window around that time

            ═══════════════════════════════════════════════════════════════════════════════
            QUERY SYNTAX RULES
            ═══════════════════════════════════════════════════════════════════════════════

            Reserved Attributes (NO @ prefix required):
            - service: Application/service name (e.g., service:api-gateway)
            - env: Environment (e.g., env:production, env:staging)
            - status: Log level (e.g., status:error, status:warn, status:info)
            - host: Server/container hostname (e.g., host:web-server-01)
            - source: Log source (e.g., source:docker, source:nginx)
            - version: Application version (e.g., version:1.2.3)
            - trace_id: Distributed trace ID

            Custom Attributes (@ prefix REQUIRED):
            - Use @ for any non-reserved attribute (e.g., @http.status_code:500, @user.email:john@example.com)
            - Attribute searches are CASE-SENSITIVE
            - Use dot notation for nested attributes (e.g., @http.response.status_code:200)

            Wildcards:
            - * = Multi-character wildcard (e.g., service:web-* matches web-api, web-frontend)
            - ? = Single character wildcard (e.g., @my_attr:hello?world matches "hello world" or "hello_world")
            - Wildcards only work OUTSIDE double quotes

            Boolean Operators (must be UPPERCASE):
            - AND: Both conditions must match (e.g., service:api AND status:error)
            - OR: Either condition matches (e.g., env:prod OR env:staging)
            - NOT or -: Exclude logs (e.g., service:api NOT status:debug OR service:api -status:debug)
            - Parentheses for grouping (e.g., service:api AND (status:error OR status:warn))
            - Implicit AND: Space between terms acts as AND (e.g., "service:api status:error" = "service:api AND status:error")

            Numerical Operators (faceted attributes only):
            - <, >, <=, >= for numerical comparisons (e.g., @http.status_code:>=400, @duration:>1000)
            - Range syntax: @attribute:[min TO max] (e.g., @http.status_code:[400 TO 499])

            Special Characters & Escaping:
            - Special chars in values require escaping OR double quotes
            - With escaping: @my_attr:hello\:world (for value "hello:world")
            - With quotes: @my_attr:"hello:world" (for value "hello:world")
            - Quotes required for: colons, spaces, special symbols in attribute values

            Free-Text Search:
            - Unquoted text searches across all fields (case-INSENSITIVE)
            - Quoted phrases for exact matching: "database connection failed"
            - Combine with facets: service:api "timeout error"

            TIME RANGE:
            - from/to parameters use epoch timestamps in milliseconds
            - Maximum range depends on your Datadog retention plan

            TAGS:
            - Tags excluded by default to reduce response size (can be 100+ per log)
            - Set includeTags:true only when needed for tag analysis

            PAGINATION:
            - Cursor-based (not offset-based)
            - First request: omit cursor parameter
            - Next pages: use cursor from previous response's meta.page.after
            - Last page: when meta.page.after is null/empty
            - API maximum: 1000 logs per request

            QUERY EXAMPLES:

            Basic filtering:
            - "service:payment-api status:error" - Errors from payment API
            - "env:production status:warn" - Production warnings
            - "service:checkout @http.status_code:500" - HTTP 500s from checkout service

            Wildcards:
            - "service:web-* env:prod" - All web services in production
            - "@user.email:*@example.com" - Logs with example.com email addresses

            Boolean logic:
            - "service:api AND (status:error OR status:warn)" - API errors or warnings
            - "env:prod AND -service:health-check" - Production excluding health checks
            - "(service:api OR service:worker) AND status:error" - Errors from API or worker

            Numerical filtering:
            - "@http.status_code:>=500" - Server errors (5xx)
            - "@duration:>5000 service:api" - API requests taking over 5 seconds
            - "@http.status_code:[400 TO 499]" - Client errors (4xx range)

            Complex queries:
            - "service:payment env:prod status:error @transaction.amount:>1000" - High-value payment errors
            - "(service:api OR service:worker) AND env:prod AND -@deployment.canary:true" - Prod non-canary errors
            - "service:checkout @user.country:US \"payment declined\"" - US payment declines with specific text

            Special characters:
            - "@error.message:\"connection: timeout\"" - Error message with colon (quoted)
            - "@url.path:/api/v1/users" - URL paths with slashes (no quotes needed)
            - "service:my-service @key:\"value with spaces\"" - Values containing spaces

            COMMON USE CASES:
            - Error dashboard: query: "status:error", limit: 100
            - Service health: query: "service:my-service env:prod status:error"
            - Slow requests: query: "service:api @duration:>3000"
            - Failed transactions: query: "@transaction.status:failed @amount:>100"
            - User activity: query: "@user.id:12345 service:api"
            - Deployment issues: query: "env:prod @deployment.version:v2.1.0 status:error"

            ═══════════════════════════════════════════════════════════════════════════════
            COMMON ATTRIBUTE PATTERNS
            ═══════════════════════════════════════════════════════════════════════════════

            These are typical custom attributes you'll find in logs (all require @ prefix):

            HTTP/API Attributes:
            - @http.status_code: HTTP response codes (200, 404, 500, etc.)
            - @http.method: HTTP method (GET, POST, PUT, DELETE, PATCH)
            - @http.url: Full request URL
            - @http.url_details.path: URL path only (/api/v1/users)
            - @http.url_details.queryString: Query parameters
            - @http.useragent: Client user agent string
            - @http.referer: HTTP referer header
            - @http.request_id: Unique request identifier
            - @network.client.ip: Client IP address
            - @network.bytes_read: Bytes received
            - @network.bytes_written: Bytes sent

            User/Identity Attributes:
            - @user.id: User identifier (numeric or string)
            - @user.email: User email address
            - @user.name: User display name
            - @user.role: User role (admin, user, guest)
            - @user.country: User country code
            - @user.session_id: Session identifier
            - @usr.id: Alternative user ID field (some integrations)

            Performance/Timing Attributes:
            - @duration: Request/operation duration in milliseconds
            - @duration_ns: Duration in nanoseconds
            - @db.statement.duration: Database query duration
            - @response_time: Response time in milliseconds

            Database Attributes:
            - @db.statement: SQL query or statement
            - @db.operation: Operation type (SELECT, INSERT, UPDATE, DELETE)
            - @db.instance: Database instance name
            - @db.user: Database username
            - @db.row_count: Number of rows affected

            Error Attributes:
            - @error.message: Error message text
            - @error.kind: Error type/class
            - @error.stack: Stack trace
            - @error.code: Error code (numeric or string)
            - @exception.type: Exception class name
            - @exception.message: Exception message

            Transaction/Business Attributes:
            - @transaction.id: Transaction identifier
            - @transaction.amount: Transaction value
            - @transaction.currency: Currency code (USD, EUR)
            - @transaction.status: Status (success, failed, pending, cancelled)
            - @order.id: Order identifier
            - @payment.method: Payment method (credit_card, paypal)

            Deployment/Infrastructure Attributes:
            - @deployment.version: Application version
            - @deployment.environment: Deployment environment
            - @deployment.canary: Canary deployment flag (true/false)
            - @container.id: Container identifier
            - @container.name: Container name
            - @kubernetes.pod_name: Kubernetes pod name
            - @kubernetes.namespace: Kubernetes namespace

            Security Attributes:
            - @auth.user_id: Authenticated user ID
            - @auth.method: Authentication method (oauth, jwt, basic)
            - @security.threat_type: Threat classification
            - @security.blocked: Whether request was blocked (true/false)

            Custom Business Logic:
            - Any attribute not listed above requires @ prefix
            - Use dot notation for nested fields: @custom.nested.field
            - Remember: attribute names are CASE-SENSITIVE

            Example queries with common attributes:
            - "service:api @http.status_code:>=500" - Server errors
            - "@user.email:*@example.com status:error" - Errors for example.com users
            - "service:payment @transaction.amount:>1000 @transaction.status:failed" - High-value payment failures
            - "@duration:>5000 service:api" - Slow API requests (>5 seconds)
            - "@error.kind:TimeoutException" - Specific exception type
            - "@deployment.canary:true status:error" - Canary deployment errors

            RESPONSE STRUCTURE:
            {
              "data": [
                {
                  "id": "AQAAAYxL...",
                  "type": "log",
                  "attributes": {
                    "timestamp": "2025-01-09T10:30:45.123Z",
                    "service": "api-gateway",
                    "status": "error",
                    "message": "Connection timeout",
                    "host": "web-01",
                    "@http.status_code": 500,
                    "@user.id": "12345"
                  }
                }
              ],
              "meta": {
                "page": {
                  "after": "eyJhZnRlciI6..."  // Pagination cursor, null when done
                }
              }
            }

            TIMESTAMP HELPERS (all times in milliseconds):
            - Current time: Date.now() or time() * 1000
            - Last hour: from = now() - 3600000, to = now()
            - Last 24 hours: from = now() - 86400000, to = now()
            - Last 7 days: from = now() - 604800000, to = now()
            - Last 15 minutes: from = now() - 900000, to = now()
            - Conversions: 1 second = 1000ms, 1 minute = 60000ms, 1 hour = 3600000ms, 1 day = 86400000ms
            - CRITICAL: Always milliseconds (13 digits), not seconds. Multiply Unix timestamps by 1000.

            COMPLETE WORKFLOW EXAMPLE - Finding Production Errors:
            Step 1: Calculate time range
              from = currentTime - 3600000  // 1 hour ago
              to = currentTime
            Step 2: Construct query
              query = "env:production status:error"
            Step 3: Execute with moderate limit
              limit = 50, cursor = null
            Step 4: Interpret response
              - Empty data[]: No errors found in time range
              - Has data[]: Analyze patterns (check attributes.message, attributes.service)
              - Check meta.page.after: If not null, more results available
            Step 5: Pagination (if needed)
              - Extract cursor = response.meta.page.after
              - Call again with same params + cursor
              - Repeat until meta.page.after is null

            PAGINATION BEST PRACTICES:
            - Check meta.page.after: null = last page, non-null = more data exists
            - Reuse ALL original parameters (from, to, query, limit) with new cursor
            - Limit iterations (max 3-5 pages) unless user explicitly wants all data
            - Inform user before fetching large datasets
            - Cursors expire in 1-5 minutes, don't store long-term

            COMMON ERRORS & SOLUTIONS:

            "Parameter 'from' must be less than 'to'":
            - Fix: Verify from < to, check timestamp order

            "DD_API_KEY environment variable is not set":
            - This is configuration issue, inform user to set credentials

            "HTTP 400 - Bad Request":
            - Causes: Invalid query syntax, missing @ on custom attributes, lowercase boolean operators
            - Fix: Verify @ prefix on custom attributes, uppercase AND/OR/NOT, simplify query

            Empty results (data = []):
            - Query might be too restrictive
            - Try: Broader query, verify service names, expand time range
            - Debug: Start with just service:name, add filters incrementally

            Query returns wrong logs:
            - Check @ prefix: custom attributes NEED @, reserved attributes NEED NO @
            - Verify case: custom attribute names are case-sensitive
            - Check operators: Must be UPPERCASE (AND not and)

            RESULT INTERPRETATION GUIDE:

            When summarizing results for users:
            1. Key statistics: Count (data.length), time range, services affected
            2. Patterns: Common error messages, error spikes at specific times
            3. Sample logs: Show 2-3 representative entries with timestamp, service, message, key attributes
            4. Actionable insights: "47 errors in payment-api 10:30-11:00", "Most common: Database timeout (23x)"
            5. Pagination status: "Showing first 50 results, more available" if meta.page.after exists

            PERFORMANCE OPTIMIZATION:

            Query cost factors (high to low impact):
            - Time range: Wider = slower (limit to smallest range needed)
            - Filter specificity: service/env filters significantly reduce scan
            - includeTags: false = much smaller response
            - limit: Lower = faster (test with 10-50 first)

            Expensive query red flags:
            - Time range > 24h without service/env filter
            - No status filter (includes verbose debug logs)
            - Broad wildcards: service:* or missing service filter
            - limit=1000 + includeTags=true = massive response

            Optimization strategy:
            1. Always start with service and/or env filter
            2. Add status:error or status:warn to focus on issues
            3. Use smallest time range that answers question
            4. Start with limit=50, increase only if needed
            5. Keep includeTags=false unless analyzing tags

            Best practice: Start restrictive, expand gradually

            QUERY DEBUGGING CHECKLIST:

            If query returns unexpected results:
            1. Verify @ prefix usage (custom attrs need @, reserved attrs don't)
            2. Check boolean operators are UPPERCASE (AND, OR, NOT)
            3. Simplify: Start with "service:name", add filters one by one
            4. Test time range: Try last hour to verify query logic works
            5. Check special chars: Use quotes for spaces/colons in values
            6. Verify attribute names: Check case sensitivity on custom attributes

            WARNING:
            - Responses can be VERY large (especially with includeTags:true)
            - Keep queries focused to avoid overwhelming responses
            - Use pagination for large result sets
            - Avoid broad time ranges without specific filters
            - Test with small limit values first (10-50)
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
