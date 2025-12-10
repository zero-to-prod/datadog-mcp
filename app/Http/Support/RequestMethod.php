<?php

namespace App\Http\Support;

enum RequestMethod: string
{
    /**
     * The GET method requests a representation of the specified resource.
     *
     * @see https://datatracker.ietf.org/doc/html/rfc9110#name-get
     */
    case GET = 'GET';

    /**
     * The POST method requests that the server accept the entity enclosed in the request
     * as a new subordinate of the resource identified by the URI.
     *
     * @see https://datatracker.ietf.org/doc/html/rfc9110#name-post
     */
    case POST = 'POST';

    /**
     * The PUT method requests that the enclosed entity be stored under the supplied URI.
     *
     * @see https://datatracker.ietf.org/doc/html/rfc9110#name-put
     */
    case PUT = 'PUT';

    /**
     * The PATCH method requests that a set of changes described in the
     * request entity be applied to the resource identified by the Request-URI.
     *
     * @see https://datatracker.ietf.org/doc/html/rfc9110#name-patch
     */
    case PATCH = 'PATCH';

    /**
     * The DELETE method requests that the origin server remove the
     * association between the target resource and its current
     * functionality.
     *
     * @see https://datatracker.ietf.org/doc/html/rfc9110#name-delete
     */
    case DELETE = 'DELETE';

    /**
     * The OPTIONS method requests information about the communication
     * options available for the target resource.
     *
     * @see https://datatracker.ietf.org/doc/html/rfc9110#name-options
     */
    case OPTIONS = 'OPTIONS';

    /**
     * The HEAD method is identical to GET except that the server MUST NOT
     * return a message-body in the response.
     *
     * @see https://datatracker.ietf.org/doc/html/rfc9110#name-head
     */
    case HEAD = 'HEAD';

    /**
     * The UNKNOWN method is used when the request method is not recognized.
     */
    case UNKNOWN = 'UNKNOWN';
}