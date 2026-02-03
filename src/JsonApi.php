<?php

namespace ChrisKelemba\ResponseApi;

use ChrisKelemba\ResponseApi\Pagination\JsonApiPaginator;
use ChrisKelemba\ResponseApi\Support\ErrorBuilder;
use ChrisKelemba\ResponseApi\Support\KeyTransform;
use ChrisKelemba\ResponseApi\Support\QueryApplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class JsonApi
{
    protected array $document = [];

    public static function make(): self
    {
        return new self();
    }

    public static function data(mixed $data): self
    {
        return (new self())->withData($data);
    }

    public static function errors(array $errors): self
    {
        return (new self())->withErrors($errors);
    }

    public static function response(
        mixed $payload,
        ?string $type = null,
        ?Request $request = null,
        int $status = 200,
        array $headers = []
    ): JsonResponse|\Illuminate\Http\Response {
        if ($status === 204) {
            return Response::noContent();
        }

        if (is_object($payload) && $status === 201 && ! array_key_exists('Location', $headers)) {
            $headers['Location'] = self::resourceSelfLink($payload, $type, null);
        }

        $document = self::document($payload, $type, $request);

        return Response::jsonApi($document, $status, $headers);
    }

    public static function document(mixed $payload, ?string $type = null, ?Request $request = null): array
    {
        if ($payload instanceof LengthAwarePaginator || $payload instanceof Paginator) {
            $collection = $payload->getCollection();
            self::eagerLoadIncludes($collection, $request);
            $resources = $collection->map(function ($item) use ($type, $request) {
                if (is_object($item)) {
                    $relationships = self::relationshipsFromModel($item, $type, $request);
                    return self::fromModel($item, $type, null, $relationships);
                }

                return $item;
            })->values()->all();

            $document = self::data($resources)
                ->withPagination($payload)
                ->withLinks(['self' => self::requestUrl($request)])
                ->toArray();

            $included = self::includedFromCollection($collection, $request);
            if ($included !== []) {
                $document['included'] = $included;
            }

            return $document;
        }

        if (is_iterable($payload) && ! is_array($payload)) {
            $collection = collect($payload);
            self::eagerLoadIncludes($collection, $request);
            $resources = $collection->map(function ($item) use ($type, $request) {
                if (is_object($item)) {
                    $relationships = self::relationshipsFromModel($item, $type, $request);
                    return self::fromModel($item, $type, null, $relationships);
                }

                return $item;
            })->values()->all();

            $document = self::data($resources)
                ->withLinks(['self' => self::requestUrl($request)])
                ->toArray();

            $included = self::includedFromCollection($collection, $request);
            if ($included !== []) {
                $document['included'] = $included;
            }

            return $document;
        }

        if (is_object($payload)) {
            self::eagerLoadIncludes($payload, $request);
            $relationships = self::relationshipsFromModel($payload, $type, $request);
            $resource = self::fromModel($payload, $type, null, $relationships);

            $document = self::data($resource)
                ->withLinks(['self' => self::resourceSelfLink($payload, $type, $request)])
                ->toArray();

            $included = self::includedFromModel($payload, $request);
            if ($included !== []) {
                $document['included'] = $included;
            }

            return $document;
        }

        if (is_array($payload) && self::looksLikeDocument($payload)) {
            return $payload;
        }

        if (is_array($payload)) {
            return self::data($payload)
                ->withLinks(['self' => self::requestUrl($request)])
                ->toArray();
        }

        return ['data' => $payload];
    }

    public static function responseErrors(array $errors, int $status = 400, array $headers = []): JsonResponse
    {
        $document = self::errors($errors)->toArray();

        return Response::jsonApi($document, $status, $headers);
    }

    public static function error(
        string|int $status,
        string $title,
        ?string $detail = null,
        ?string $code = null,
        ?array $source = null,
        array $meta = []
    ): array {
        return ErrorBuilder::make($status, $title, $detail, $code, $source, $meta);
    }

    public static function applyQuery(
        Builder $query,
        Request $request,
        ?string $type = null,
        array $options = []
    ): Builder {
        return QueryApplier::apply($query, $request, $type, $options);
    }

    public static function fromModel(
        object $model,
        ?string $type = null,
        ?array $attributes = null,
        array $relationships = [],
        array $links = []
    ): array {
        $type ??= self::inferType($model);
        $id = method_exists($model, 'getKey') ? $model->getKey() : null;

        if ($attributes === null && method_exists($model, 'getAttributes')) {
            $attributes = $model->getAttributes();
        }

        if (is_array($attributes)) {
            $keyName = method_exists($model, 'getKeyName') ? $model->getKeyName() : 'id';
            unset($attributes[$keyName], $attributes['id']);

            foreach (['created_at', 'updated_at'] as $timestampKey) {
                if (array_key_exists($timestampKey, $attributes)) {
                    $attributes[$timestampKey] = self::formatDate($attributes[$timestampKey]);
                }
            }
        }

        return self::resource($type, $id, $attributes ?? [], $relationships, $links);
    }

    public static function collection(
        iterable $items,
        ?string $type = null,
        ?callable $mapper = null
    ): array {
        $data = [];

        foreach ($items as $item) {
            if ($mapper !== null) {
                $data[] = $mapper($item);
                continue;
            }

            if (is_object($item)) {
                $data[] = self::fromModel($item, $type);
                continue;
            }

            $data[] = $item;
        }

        return $data;
    }

    public static function resource(
        string $type,
        string|int|null $id,
        array $attributes,
        array $relationships = [],
        array $links = []
    ): array {
        $resource = [
            'type' => $type,
        ];

        if ($id !== null) {
            $resource['id'] = (string) $id;
        }

        $resource['attributes'] = $attributes;

        $resourceLinks = $links;
        if (config('jsonapi.resource_links', true) === true && $id !== null) {
            $resourceLinks = array_merge(['self' => self::resourceUrl($type, $id)], $resourceLinks);
        }

        if (! empty($resourceLinks)) {
            $resource['links'] = $resourceLinks;
        }

        if (! empty($relationships)) {
            $resource['relationships'] = self::relationshipsObject($type, $id, $relationships);
        }

        return self::transformKeysIfNeeded($resource);
    }

    public static function relationshipLinks(string $type, string|int|null $id, string $name): array
    {
        if ($id === null) {
            return [];
        }

        $base = rtrim(self::resourceUrl($type, $id), '/');

        return [
            'self' => $base . '/relationships/' . $name,
            'related' => $base . '/' . $name,
        ];
    }

    public static function formatDate(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('c');
        }

        if (is_string($value) && $value !== '') {
            try {
                return (new \DateTimeImmutable($value))->format('c');
            } catch (\Exception $e) {
                return $value;
            }
        }

        return null;
    }

    public function withData(mixed $data): self
    {
        $this->document['data'] = $data;
        unset($this->document['errors']);

        return $this;
    }

    public function withErrors(array $errors): self
    {
        $this->document['errors'] = $errors;
        unset($this->document['data']);

        return $this;
    }

    public function withMeta(array $meta): self
    {
        $this->document['meta'] = array_merge($this->document['meta'] ?? [], $meta);

        return $this;
    }

    public function withLinks(array $links): self
    {
        $this->document['links'] = array_merge($this->document['links'] ?? [], $links);

        return $this;
    }

    public function withIncluded(array $included): self
    {
        $this->document['included'] = $included;

        return $this;
    }

    public function withJsonApi(?array $jsonapi = null): self
    {
        $this->document['jsonapi'] = $jsonapi ?? $this->defaultJsonApiObject();

        return $this;
    }

    public function withPagination(LengthAwarePaginator|Paginator $paginator): self
    {
        $pagination = JsonApiPaginator::from($paginator, config('jsonapi.pagination', []));

        $this->withLinks($pagination['links']);
        $this->withMeta($pagination['meta']);

        return $this;
    }

    public function toArray(): array
    {
        if (! array_key_exists('jsonapi', $this->document)) {
            $this->withJsonApi();
        }

        return self::transformKeysIfNeeded($this->document);
    }

    protected function defaultJsonApiObject(): array
    {
        $jsonapi = config('jsonapi.jsonapi', ['version' => '1.1', 'meta' => []]);
        $default = ['version' => '1.1'];

        if (! empty($jsonapi['meta'])) {
            $default['meta'] = $jsonapi['meta'];
        }

        return array_merge($default, array_filter($jsonapi, fn ($value) => $value !== []));
    }

    protected static function relationshipsObject(
        string $type,
        string|int|null $id,
        array $relationships
    ): array {
        $result = [];

        foreach ($relationships as $name => $payload) {
            $relation = is_array($payload) ? $payload : ['data' => $payload];

            if (config('jsonapi.relationship_links', true) === true && $id !== null) {
                $relation['links'] = array_merge(
                    self::relationshipLinks($type, $id, (string) $name),
                    $relation['links'] ?? []
                );
            }

            $result[$name] = $relation;
        }

        return $result;
    }

    protected static function resourceUrl(string $type, string|int $id): string
    {
        $type = Str::kebab($type);

        return URL::to($type . '/' . $id);
    }

    protected static function transformKeysIfNeeded(array $payload): array
    {
        if (config('jsonapi.transform_keys', false) !== true) {
            return $payload;
        }

        return KeyTransform::transform($payload, (bool) config('jsonapi.transform_recursive', true));
    }

    protected static function inferType(object $model): string
    {
        $class = class_basename($model);

        return Str::plural(Str::kebab($class));
    }

    protected static function requestUrl(?Request $request): string
    {
        return $request ? $request->fullUrl() : URL::current();
    }

    protected static function resourceSelfLink(object $model, ?string $type, ?Request $request): string
    {
        if ($request) {
            return $request->fullUrl();
        }

        $type = $type ?? self::inferType($model);
        $id = method_exists($model, 'getKey') ? $model->getKey() : null;

        return $id === null ? URL::current() : self::resourceUrl($type, $id);
    }

    protected static function looksLikeDocument(array $payload): bool
    {
        return array_key_exists('data', $payload)
            || array_key_exists('errors', $payload)
            || array_key_exists('meta', $payload)
            || array_key_exists('links', $payload)
            || array_key_exists('jsonapi', $payload);
    }

    protected static function relationshipsFromModel(object $model, ?string $type, ?Request $request): array
    {
        if (! method_exists($model, 'getRelations')) {
            return [];
        }

        $relations = $model->getRelations();
        if ($relations === []) {
            return [];
        }

        $result = [];
        foreach ($relations as $name => $related) {
            $data = self::relationshipData($related);
            $relation = ['data' => $data];

            if (config('jsonapi.relationship_links', true) === true) {
                $relation['links'] = self::relationshipLinks(
                    $type ?? self::inferType($model),
                    method_exists($model, 'getKey') ? $model->getKey() : null,
                    (string) $name
                );
            }

            $result[$name] = $relation;
        }

        return $result;
    }

    protected static function relationshipData(mixed $related): mixed
    {
        if ($related instanceof \Illuminate\Support\Collection) {
            return $related->map(function ($item) {
                return self::resourceIdentifier($item);
            })->values()->all();
        }

        if (is_iterable($related)) {
            return collect($related)->map(function ($item) {
                return self::resourceIdentifier($item);
            })->values()->all();
        }

        if (is_object($related)) {
            return self::resourceIdentifier($related);
        }

        return null;
    }

    protected static function resourceIdentifier(object $model): array
    {
        $type = self::inferType($model);
        $id = method_exists($model, 'getKey') ? $model->getKey() : null;

        return [
            'type' => $type,
            'id' => $id === null ? null : (string) $id,
        ];
    }

    protected static function parseIncludes(?Request $request): array
    {
        if (! $request) {
            return [];
        }

        $raw = (string) $request->query('include', '');
        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    protected static function eagerLoadIncludes(mixed $target, ?Request $request): void
    {
        $includes = self::parseIncludes($request);
        if ($includes === []) {
            return;
        }

        if (is_object($target) && method_exists($target, 'loadMissing')) {
            $target->loadMissing($includes);
            return;
        }

        if (is_object($target) && method_exists($target, 'load')) {
            $target->load($includes);
            return;
        }

        if (is_iterable($target)) {
            $collection = collect($target);
            if (method_exists($collection, 'load')) {
                $collection->load($includes);
            } else {
                $collection->each(function ($model) use ($includes) {
                    if (is_object($model) && method_exists($model, 'loadMissing')) {
                        $model->loadMissing($includes);
                    }
                });
            }
        }
    }

    protected static function includedFromModel(object $model, ?Request $request): array
    {
        $includes = self::parseIncludes($request);
        if ($includes === []) {
            return [];
        }

        if (! method_exists($model, 'getRelations')) {
            return [];
        }

        $relations = $model->getRelations();
        $included = [];

        foreach ($includes as $name) {
            if (! array_key_exists($name, $relations)) {
                continue;
            }

            $related = $relations[$name];
            $included = array_merge($included, self::includedFromRelated($related));
        }

        return self::uniqueIncluded($included);
    }

    protected static function includedFromCollection($collection, ?Request $request): array
    {
        $includes = self::parseIncludes($request);
        if ($includes === []) {
            return [];
        }

        $included = [];
        foreach ($collection as $model) {
            if (! is_object($model) || ! method_exists($model, 'getRelations')) {
                continue;
            }

            foreach ($includes as $name) {
                $relations = $model->getRelations();
                if (! array_key_exists($name, $relations)) {
                    continue;
                }

                $included = array_merge($included, self::includedFromRelated($relations[$name]));
            }
        }

        return self::uniqueIncluded($included);
    }

    protected static function includedFromRelated(mixed $related): array
    {
        if ($related instanceof \Illuminate\Support\Collection) {
            return $related->map(fn ($item) => self::fromModel($item))->values()->all();
        }

        if (is_iterable($related)) {
            return collect($related)->map(fn ($item) => self::fromModel($item))->values()->all();
        }

        if (is_object($related)) {
            return [self::fromModel($related)];
        }

        return [];
    }

    protected static function uniqueIncluded(array $included): array
    {
        $map = [];

        foreach ($included as $resource) {
            if (! is_array($resource)) {
                continue;
            }

            $type = $resource['type'] ?? null;
            $id = $resource['id'] ?? null;
            if ($type === null || $id === null) {
                continue;
            }

            $map[$type . ':' . $id] = $resource;
        }

        return array_values($map);
    }
}
