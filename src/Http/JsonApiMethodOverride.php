<?php

namespace ChrisKelemba\ResponseApi\Http;

use Closure;
use Illuminate\Http\Request;

class JsonApiMethodOverride
{
    public function handle(Request $request, Closure $next)
    {
        $config = config('jsonapi.method_override', []);

        if (($config['enabled'] ?? false) === true) {
            $header = $config['header'] ?? 'X-HTTP-Method-Override';
            $from = strtoupper($config['from'] ?? 'POST');
            $to = strtoupper($config['to'] ?? 'PATCH');

            if ($request->getMethod() === $from && $request->headers->has($header)) {
                $override = strtoupper((string) $request->headers->get($header));
                if ($override === $to) {
                    $request->setMethod($to);
                }
            }
        }

        return $next($request);
    }
}
