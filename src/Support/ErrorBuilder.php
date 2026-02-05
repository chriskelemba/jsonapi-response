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
        array $meta = [],
        ?string $id = null,
        ?array $links = null
    ): array {
        $error = [
            'status' => (string) $status,
            'title' => $title,
        ];

        if ($id !== null) {
            $error['id'] = $id;
        }

        if ($detail !== null) {
            $error['detail'] = $detail;
        }

        if ($code !== null) {
            $error['code'] = $code;
        }

        if ($links !== null) {
            $error['links'] = $links;
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
