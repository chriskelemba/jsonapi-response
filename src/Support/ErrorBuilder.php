<?php

namespace ChrisKelemba\ResponseApi\Support;

class ErrorBuilder
{
    public static function make(
        string|int $status,
        string $title,
        ?string $detail = null,
        ?string $code = null,
        ?array $source = null,
        array $meta = []
    ): array {
        $error = [
            'status' => (string) $status,
            'title' => $title,
        ];

        if ($detail !== null) {
            $error['detail'] = $detail;
        }

        if ($code !== null) {
            $error['code'] = $code;
        }

        if ($source !== null) {
            $error['source'] = $source;
        }

        if ($meta !== []) {
            $error['meta'] = $meta;
        }

        return $error;
    }
}
