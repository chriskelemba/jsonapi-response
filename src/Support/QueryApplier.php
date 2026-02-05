<?php

namespace ChrisKelemba\ResponseApi\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class QueryApplier
{
    public static function apply(Builder $query, Request $request, ?string $type = null, array $options = []): Builder
    {
        $config = array_merge(config('jsonapi.query', []), $options);

        self::applySort($query, $request, $config);
        self::applyFilters($query, $request, $config);
        self::applyIncludes($query, $request, $config);
        self::applyFields($query, $request, $type, $config);

        return $query;
    }

    protected static function applySort(Builder $query, Request $request, array $config): void
    {
        $param = $config['sort_param'] ?? 'sort';
        $raw = $request->query($param);

        if (! is_string($raw) || $raw === '') {
            return;
        }

        $allowed = $config['allowed_sorts'] ?? [];
        $allowAll = (bool) ($config['allow_all_sorts'] ?? false);
        $parts = array_filter(array_map('trim', explode(',', $raw)));

        foreach ($parts as $part) {
            $direction = 'asc';
            $field = $part;

            if (str_starts_with($part, '-')) {
                $direction = 'desc';
                $field = substr($part, 1);
            }

            if (! $allowAll && ! self::allowed($field, $allowed)) {
                continue;
            }

            $query->orderBy($field, $direction);
        }
    }

    protected static function applyFilters(Builder $query, Request $request, array $config): void
    {
        $param = $config['filter_param'] ?? 'filter';
        $filters = $request->query($param);

        if (! is_array($filters)) {
            return;
        }

        $allowed = $config['allowed_filters'] ?? [];
        $allowAll = (bool) ($config['allow_all_filters'] ?? false);

        foreach ($filters as $field => $value) {
            if (! $allowAll && ! self::allowed($field, $allowed)) {
                continue;
            }

            if (is_array($value)) {
                $query->whereIn($field, $value);
                continue;
            }

            if (is_string($value) && str_contains($value, ',')) {
                $query->whereIn($field, array_map('trim', explode(',', $value)));
                continue;
            }

            $query->where($field, $value);
        }
    }

    protected static function applyIncludes(Builder $query, Request $request, array $config): void
    {
        $param = $config['include_param'] ?? 'include';
        $raw = $request->query($param);

        if (! is_string($raw) || $raw === '') {
            return;
        }

        $allowed = $config['allowed_includes'] ?? [];
        $allowAll = (bool) ($config['allow_all_includes'] ?? false);
        $includes = array_filter(array_map('trim', explode(',', $raw)));

        if (! $allowAll && ! empty($allowed)) {
            $includes = array_values(array_filter($includes, fn ($item) => in_array($item, $allowed, true)));
        }

        if ($includes !== []) {
            $query->with($includes);
        }
    }

    protected static function applyFields(Builder $query, Request $request, ?string $type, array $config): void
    {
        $param = $config['fields_param'] ?? 'fields';
        $fields = $request->query($param);

        if (! is_array($fields) || $type === null) {
            return;
        }

        $typeFields = $fields[$type] ?? null;
        if (! is_string($typeFields) || $typeFields === '') {
            return;
        }

        $allowed = $config['allowed_fields'] ?? [];
        $allowAll = (bool) ($config['allow_all_fields'] ?? false);
        $columns = array_filter(array_map('trim', explode(',', $typeFields)));

        if (! $allowAll && ! empty($allowed)) {
            $columns = array_values(array_filter($columns, fn ($item) => in_array($item, $allowed, true)));
        }

        if ($columns !== []) {
            $keyName = $query->getModel()->getKeyName();
            if (! in_array($keyName, $columns, true)) {
                $columns[] = $keyName;
            }

            $query->select($columns);
        }
    }

    protected static function allowed(string $field, array $allowed): bool
    {
        if ($allowed === []) {
            return false;
        }

        return in_array($field, $allowed, true);
    }
}
