<?php

namespace ChrisKelemba\ResponseApi;

use ChrisKelemba\ResponseApi\Http\JsonApiMethodOverride;
use Illuminate\Support\Facades\Response;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

class JsonApiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/jsonapi.php', 'jsonapi');
    }

    public function boot(): void
    {
        Response::macro('jsonApi', function (array $payload, int $status = 200, array $headers = []) {
            $contentType = config('jsonapi.content_type', 'application/vnd.api+json');
            $headers = array_merge(['Content-Type' => $contentType], $headers);

            return $this->json($payload, $status, $headers);
        });

        $this->registerMethodOverrideMiddleware();

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/jsonapi.php' => config_path('jsonapi.php'),
            ], 'jsonapi-config');
        }
    }

    protected function registerMethodOverrideMiddleware(): void
    {
        $config = config('jsonapi.method_override', []);

        if (($config['enabled'] ?? false) !== true) {
            return;
        }

        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('jsonapi.method_override', JsonApiMethodOverride::class);

        $groups = $config['apply_to_groups'] ?? ['api'];
        foreach ((array) $groups as $group) {
            $router->pushMiddlewareToGroup($group, JsonApiMethodOverride::class);
        }
    }
}
