<?php

namespace App\Routing;

use App\Support\OpaqueId;
use App\Support\OpaqueUrl;
use Illuminate\Contracts\Routing\UrlRoutable;
use Illuminate\Routing\Route;
use Illuminate\Routing\UrlGenerator;

/**
 * Every generated route URL (route(), redirect()->route(), url()->route()) carries opaque
 * codes instead of raw household / member / resident / request ids.
 */
class OpaqueUrlGenerator extends UrlGenerator
{
    public function toRoute($route, $parameters, $absolute)
    {
        if ($route instanceof Route && OpaqueId::enabled()) {
            $parameters = $this->opaqueParameters($route, is_array($parameters) ? $parameters : [$parameters]);
        }

        return parent::toRoute($route, $parameters, $absolute);
    }

    /**
     * @param  array<int|string, mixed>  $parameters
     * @return array<int|string, mixed>
     */
    private function opaqueParameters(Route $route, array $parameters): array
    {
        $uri = $route->uri();
        $positional = array_values(array_filter(array_keys($parameters), 'is_int'));
        $next = 0;

        foreach ($route->parameterNames() as $name) {
            $kind = OpaqueUrl::kind($name, $uri);

            if (array_key_exists($name, $parameters)) {
                $key = $name;
            } elseif (isset($positional[$next])) {
                $key = $positional[$next++];
            } else {
                continue;
            }

            if ($kind !== null) {
                $parameters[$key] = $this->encodeValue($kind, $parameters[$key]);
            }
        }

        if ($uri === OpaqueUrl::EH_QUERY_ROUTE && array_key_exists('household', $parameters)) {
            $parameters['household'] = $this->encodeValue('h', $parameters['household']);
        }

        return $parameters;
    }

    private function encodeValue(string $kind, mixed $value): mixed
    {
        if ($value instanceof UrlRoutable) {
            $value = $value->getRouteKey();
        }
        if (! is_scalar($value) || (string) $value === '') {
            return $value;
        }

        $text = (string) $value;
        if (OpaqueId::decodeKind($kind, $text) !== null) {
            return $text;
        }

        return OpaqueId::encode($kind, $text);
    }
}
