<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Helpers\DataModel;
use Zerotoprod\DataModel\Describe;

readonly class LogsSearchRequest
{
    use DataModel;

    /** @see $cursor */
    public const string cursor = 'cursor';
    /** Pagination cursor for next page */
    #[Describe(['nullable'])]
    public ?string $cursor;

    /** @see $limit */
    public const string limit = 'limit';
    /** Maximum number of logs per request (1-1000) */
    #[Describe(['nullable'])]
    public ?int $limit;

    /** @see $sort */
    public const string sort = 'sort';
    /** Sort order: timestamp or -timestamp */
    #[Describe(['nullable'])]
    public ?string $sort;
}
