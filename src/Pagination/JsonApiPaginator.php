<?php

namespace ChrisKelemba\ResponseApi\Pagination;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;

class JsonApiPaginator
{
    public static function from(LengthAwarePaginator|Paginator $paginator, array $options = []): array
    {
        $links = [
            'self' => $paginator->url($paginator->currentPage()),
            'first' => $paginator->url(1),
            'prev' => $paginator->previousPageUrl(),
            'next' => $paginator->nextPageUrl(),
        ];

        if ($paginator instanceof LengthAwarePaginator) {
            $links['last'] = $paginator->url($paginator->lastPage());
        }

        $metaKey = $options['meta_key'] ?? 'page';
        $meta = [
            $metaKey => [
                'current' => $paginator->currentPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
                'per_page' => $paginator->perPage(),
            ],
        ];

        if ($paginator instanceof LengthAwarePaginator) {
            if (($options['include_total'] ?? true) === true) {
                $meta[$metaKey]['total'] = $paginator->total();
            }

            if (($options['include_last_page'] ?? true) === true) {
                $meta[$metaKey]['last_page'] = $paginator->lastPage();
            }
        }

        return [
            'links' => $links,
            'meta' => $meta,
        ];
    }
}
