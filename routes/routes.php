<?php

use App\Http\Controllers\IndexController;
use App\Http\Controllers\McpController;
use App\Http\Routes\Routes;
use Zerotoprod\HttpRouter\HttpRouter;

$Route = app(HttpRouter::class);

$Route->get(Routes::index, IndexController::class);

$Route->any(Routes::mcp, [McpController::class, 'post'], ['POST', 'DELETE'])
    ->options(Routes::mcp, [McpController::class, 'options']);