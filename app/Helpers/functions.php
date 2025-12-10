<?php

use Zerotoprod\Container\Container;
use Zerotoprod\HttpRouter\HttpRouter;
use Zerotoprod\WebFramework\WebFramework;

if (!function_exists('app')) {
    /**
     * Get the application container instance.
     *
     * @template T of object
     * @param  class-string<T>|null  $id  The ID of the instance to retrieve.
     *
     * @return ($id is null ? WebFramework : T)
     */
    function app(?string $id = null): mixed
    {
        return $id
            ? Container::getInstance()->get($id)
            : Container::getInstance()->get(WebFramework::class);
    }
}

if(!function_exists('route')){
    function route(string $name, array $params = []): string
    {
        return app(HttpRouter::class)->route($name, $params);
    }
}