<?php

use App\Http\Support\Server;
use Zerotoprod\Container\Container;
use Zerotoprod\HttpRouter\HttpRouter;

$Server = Server::from(array_change_key_case($_SERVER, CASE_UPPER));
Container::getInstance()->instance(Server::class, $Server);

$Router = Container::getInstance()->instance(
    HttpRouter::class,
    HttpRouter::for(
        $Server->REQUEST_METHOD->value,
        $Server->REQUEST_URI ?? '/',
    )
)->fallback(function () {
    http_response_code(404);
    echo '404';
});

require __DIR__.'/../routes/routes.php';

try {
    $Router->dispatch();
} catch (Throwable $e) {
    echo $e->getMessage();
}
