<?php

namespace App\Http\Requests\Concerns;

trait ResolvesRouteModelId
{
    protected function routeModelId(array $keys): mixed
    {
        foreach ($keys as $key) {
            $value = $this->route($key);

            if (!$value) {
                continue;
            }

            if (is_object($value) && method_exists($value, 'getKey')) {
                return $value->getKey();
            }

            if (is_object($value) && isset($value->id)) {
                return $value->id;
            }

            return $value;
        }

        return null;
    }
}