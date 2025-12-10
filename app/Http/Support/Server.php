<?php

namespace App\Http\Support;

use App\Helpers\DataModel;
use Zerotoprod\DataModel\Describe;

readonly class Server
{
    use DataModel;

    /** @see $HOST */
    public const string HOST = 'HOST';
    /**
     * The "Host" header field in a request provides the host and port information from
     * the target URI, enabling the origin server to distinguish among resources while
     * servicing requests for multiple host names.
     *
     * @see https://datatracker.ietf.org/doc/html/rfc9110#name-host-and-authority
     */
    #[Describe(['nullable'])]
    public ?string $HOST;

    /** @see $CONTENT_TYPE */
    public const string CONTENT_TYPE = 'CONTENT_TYPE';
    /**
     * The "Content-Type" header field indicates the media type of the associated
     * representation: either the representation enclosed in the message content
     * or the selected representation, as determined by the message semantics.
     *
     * @see https://datatracker.ietf.org/doc/html/rfc9110#name-content-type
     */
    #[Describe(['nullable', 'cast' => [self::class, 'strtolower']])]
    public ?string $CONTENT_TYPE;

    /** @see $REQUEST_METHOD */
    public const string REQUEST_METHOD = 'REQUEST_METHOD';
    /**
     * The request method is the HTTP method used to make the request.
     *
     * @see https://datatracker.ietf.org/doc/html/rfc9110
     */
    #[Describe([
        'nullable',
        'cast' => [self::class, 'setRequestMethod']
    ])]
    public ?RequestMethod $REQUEST_METHOD;

    /** @see $REQUEST_METHOD */
    public static function setRequestMethod(?string $method): ?RequestMethod
    {
        return RequestMethod::tryFrom(strtoupper($method)) ?? RequestMethod::UNKNOWN;
    }

    /** @see $REQUEST_URI */
    public const string REQUEST_URI = 'REQUEST_URI';
    /**
     * The request URI is the URI of the resource being requested.
     *
     * @see https://datatracker.ietf.org/doc/html/rfc9110#name-request-target
     */
    #[Describe(['nullable'])]
    public ?string $REQUEST_URI;

    /** @see $HTTP_AUTHORIZATION */
    public const string HTTP_AUTHORIZATION = 'HTTP_AUTHORIZATION';
    /**
     * The "Authorization" header field allows a user agent to authenticate
     * itself with an origin server, usually as part of an HTTP request.
     *
     * @see https://datatracker.ietf.org/doc/html/rfc9110#name-authorization
     */
    #[Describe([
        'nullable',
        'cast' => [self::class, 'sanitize']
    ])]
    public ?string $HTTP_AUTHORIZATION;

    public static function sanitize(?string $value): ?string
    {
        return is_null($value)
            ? null
            : trim($value);
    }

    public static function strtolower(?string $value): ?string
    {
        return is_null($value)
            ? null
            : strtolower($value);
    }
}