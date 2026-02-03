<?php

namespace ChrisKelemba\ResponseApi\Support;

use Illuminate\Support\Str;

class KeyTransform
{
    public static function camelize(string $key): string
    {
        $key = Str::camel($key);
        $key = preg_replace('/[^a-zA-Z0-9]/', '', (string) $key);

        if ($key === '' || ! preg_match('/^[a-z]/', $key)) {
            $key = 'x' . $key;
        }

        if (! preg_match('/[a-z]$/', $key)) {
            $key .= 'x';
        }

        return $key;
    }

    public static function transform(array $payload, bool $recursive = true): array
    {
        $result = [];

        foreach ($payload as $key => $value) {
            $outKey = is_string($key) ? self::camelize($key) : $key;

            if ($recursive && is_array($value)) {
                $value = self::transform($value, true);
            }

            $result[$outKey] = $value;
        }

        return $result;
    }
}
