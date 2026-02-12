<?php

namespace ChrisKelemba\ResponseApi;

use ChrisKelemba\ResponseApi\Pagination\JsonApiPaginator;
use ChrisKelemba\ResponseApi\Support\ErrorBuilder;
use ChrisKelemba\ResponseApi\Support\KeyTransform;
use ChrisKelemba\ResponseApi\Support\QueryApplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Schema;
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

            if ($request) {
                $payload->appends($request->query());
            }

            $document = self::data($resources)
                ->withPagination($payload)
                ->withLinks(['self' => self::requestUrl($request)])
                ->toArray();

            if (config('jsonapi.include_compound_documents', true) === true) {
                $included = self::includedFromCollection($collection, $request);
                if ($included !== []) {
                    $document['included'] = $included;
                }
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

            if (config('jsonapi.include_compound_documents', true) === true) {
                $included = self::includedFromCollection($collection, $request);
                if ($included !== []) {
                    $document['included'] = $included;
                }
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

            if (config('jsonapi.include_compound_documents', true) === true) {
                $included = self::includedFromModel($payload, $request);
                if ($included !== []) {
                    $document['included'] = $included;
                }
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
        $document = self::errors(self::normalizeErrors($errors))->toArray();

        return Response::jsonApi($document, $status, $headers);
    }

    public static function error(
        string|int $status,
        string $title,
        ?string $detail = null,
        ?string $code = null,
        ?array $source = null,
        array $meta = [],
        ?string $id = null,
        ?array $links = null
    ): array {
        return ErrorBuilder::make($status, $title, $detail, $code, $source, $meta, $id, $links);
    }

    public static function applyQuery(
        Builder $query,
        Request $request,
        ?string $type = null,
        array $options = []
    ): Builder {
        return QueryApplier::apply($query, $request, $type, $options);
    }

    public static function applyModelQuery(
        Builder $query,
        Request $request,
        ?string $type = null,
        array $options = []
    ): Builder {
        $defaults = self::modelQueryOptions($query);
        $resolved = array_replace($defaults, $options);

        return self::applyQuery($query, $request, $type, $resolved);
    }

    public static function paginateQuery(
        Builder $query,
        Request $request,
        int $defaultPerPage = 15,
        int $maxPerPage = 100
    ): LengthAwarePaginator {
        $perPage = (int) $request->input('page.size', $defaultPerPage);
        $perPage = max(1, min($perPage, $maxPerPage));

        return $query->paginate($perPage);
    }

    public static function modelQueryOptions(Builder $query): array
    {
        $model = $query->getModel();
        $columns = self::modelColumns($model);
        $relations = self::modelRelations($model);

        return [
            'allowed_sorts' => $columns,
            'allowed_filters' => $columns,
            'allowed_fields' => $columns,
            'allowed_includes' => $relations,
        ];
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

        if ($attributes === null) {
            if (method_exists($model, 'attributesToArray')) {
                $attributes = $model->attributesToArray();
            } elseif (method_exists($model, 'getAttributes')) {
                $attributes = $model->getAttributes();
            }
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
        if (! array_key_exists('jsonapi', $this->document) && config('jsonapi.include_jsonapi', true) === true) {
            $this->withJsonApi();
        }

        if (config('jsonapi.include_jsonapi', true) !== true) {
            unset($this->document['jsonapi']);
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

    protected static function modelColumns(Model $model): array
    {
        $columns = [];

        try {
            $columns = Schema::connection($model->getConnectionName())
                ->getColumnListing($model->getTable());
        } catch (\Throwable $e) {
            $columns = [];
        }

        if ($columns === []) {
            $columns = $model->getFillable();
        }

        $keyName = $model->getKeyName();
        if (! in_array($keyName, $columns, true)) {
            $columns[] = $keyName;
        }

        if (method_exists($model, 'usesTimestamps') && $model->usesTimestamps()) {
            $createdAt = $model->getCreatedAtColumn();
            $updatedAt = $model->getUpdatedAtColumn();

            if ($createdAt && ! in_array($createdAt, $columns, true)) {
                $columns[] = $createdAt;
            }

            if ($updatedAt && ! in_array($updatedAt, $columns, true)) {
                $columns[] = $updatedAt;
            }
        }

        $hidden = $model->getHidden();
        if ($hidden !== []) {
            $columns = array_values(array_filter($columns, fn ($column) => ! in_array($column, $hidden, true)));
        }

        return array_values(array_unique(array_filter($columns, fn ($column) => is_string($column) && $column !== '')));
    }

    protected static function modelRelations(Model $model): array
    {
        $relations = [];
        $reflection = new \ReflectionClass($model);

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic()) {
                continue;
            }

            if ($method->class !== $reflection->getName()) {
                continue;
            }

            if ($method->getNumberOfRequiredParameters() > 0) {
                continue;
            }

            $name = $method->getName();
            if (in_array($name, ['boot', 'booted', 'initializeTraits'], true)) {
                continue;
            }

            if (str_starts_with($name, '__')) {
                continue;
            }

            try {
                $result = $model->{$name}();
            } catch (\Throwable $e) {
                continue;
            }

            if ($result instanceof \Illuminate\Database\Eloquent\Relations\Relation) {
                $relations[] = $name;
            }
        }

        return array_values(array_unique($relations));
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

    protected static function normalizeErrors(array $errors): array
    {
        $includeAll = (bool) config('jsonapi.errors.include_all_members', false);
        if (! $includeAll) {
            return $errors;
        }

        return array_map(function ($error) {
            if (! is_array($error)) {
                return $error;
            }

            $defaults = config('jsonapi.errors', []);

            $normalized = $error;

            if (! array_key_exists('id', $normalized) || $normalized['id'] === null || $normalized['id'] === '') {
                $normalized['id'] = (string) Str::uuid();
            }

            if (! array_key_exists('status', $normalized) || $normalized['status'] === null || $normalized['status'] === '') {
                $normalized['status'] = (string) ($defaults['default_status'] ?? '500');
            } else {
                $normalized['status'] = (string) $normalized['status'];
            }

            if (! array_key_exists('title', $normalized) || $normalized['title'] === null || $normalized['title'] === '') {
                $normalized['title'] = (string) ($defaults['default_title'] ?? 'Error');
            }

            if (! array_key_exists('code', $normalized)) {
                $normalized['code'] = $defaults['default_code'] ?? null;
            }

            if (! array_key_exists('detail', $normalized)) {
                $normalized['detail'] = $defaults['default_detail'] ?? null;
            }

            if (! array_key_exists('links', $normalized)) {
                $normalized['links'] = $defaults['default_links'] ?? null;
            }

            if (! array_key_exists('source', $normalized)) {
                $normalized['source'] = $defaults['default_source'] ?? null;
            }

            if (! array_key_exists('meta', $normalized)) {
                $normalized['meta'] = $defaults['default_meta'] ?? null;
            }

            return $normalized;
        }, $errors);
    }

    public static function validationErrors(
        array|\Illuminate\Support\MessageBag $errors,
        string $title = 'Validation Error',
        string $code = 'VALIDATION_ERROR',
        int $status = 422
    ): array {
        if ($errors instanceof \Illuminate\Support\MessageBag) {
            $errors = $errors->toArray();
        }

        $result = [];
        foreach ($errors as $field => $messages) {
            $messages = is_array($messages) ? $messages : [$messages];
            foreach ($messages as $message) {
                $result[] = self::error(
                    $status,
                    $title,
                    is_string($message) ? $message : null,
                    $code,
                    ['pointer' => '/data/attributes/' . $field],
                    ['field' => $field]
                );
            }
        }

        return $result;
    }

    public static function responseValidationErrors(
        array|\Illuminate\Support\MessageBag $errors,
        int $status = 422,
        string $title = 'Validation Error',
        string $code = 'VALIDATION_ERROR',
        array $headers = []
    ): JsonResponse {
        $payload = self::validationErrors($errors, $title, $code, $status);

        return self::responseErrors($payload, $status, $headers);
    }

    protected static function relationshipsFromModel(object $model, ?string $type, ?Request $request): array
    {
        if (! method_exists($model, 'getRelations')) {
            return [];
        }

        $relations = $model->getRelations();
        $includes = self::parseIncludes($request);
        if ($relations === [] && $includes === []) {
            return [];
        }

        $result = [];
        foreach ($relations as $name => $related) {
            $limit = null;
            if ($includes !== [] && in_array($name, $includes, true)) {
                $limit = self::includeLimit($request, $name);
            }

            $data = self::relationshipData($related, $limit);
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

        if (
            $includes !== []
            && config('jsonapi.relationships.links_for_includes', true) === true
            && config('jsonapi.relationship_links', true) === true
        ) {
            foreach ($includes as $name) {
                if (array_key_exists($name, $result)) {
                    continue;
                }
                if (! method_exists($model, $name)) {
                    continue;
                }

                $result[$name] = [
                    'links' => self::relationshipLinks(
                        $type ?? self::inferType($model),
                        method_exists($model, 'getKey') ? $model->getKey() : null,
                        (string) $name
                    ),
                ];
            }
        }

        return $result;
    }

    protected static function relationshipData(mixed $related, ?int $limit = null): mixed
    {
        if ($related instanceof \Illuminate\Support\Collection) {
            $items = $limit !== null ? $related->take($limit) : $related;
            return $items->map(function ($item) {
                return self::resourceIdentifier($item);
            })->values()->all();
        }

        if (is_iterable($related)) {
            $items = collect($related);
            if ($limit !== null) {
                $items = $items->take($limit);
            }
            return $items->map(function ($item) {
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

    protected static function includeLimit(?Request $request, ?string $relation = null): ?int
    {
        $default = config('jsonapi.query.max_include', null);
        if (! $request) {
            return self::normalizeIncludeLimit($default);
        }

        $param = config('jsonapi.query.max_include_param', 'max_include');
        $raw = $request->query($param, null);

        if (is_array($raw)) {
            if ($relation !== null && array_key_exists($relation, $raw)) {
                $raw = $raw[$relation];
            } else {
                return self::normalizeIncludeLimit($default);
            }
        }

        if ($raw === null) {
            return self::normalizeIncludeLimit($default);
        }

        return self::normalizeIncludeLimit($raw);
    }

    protected static function normalizeIncludeLimit(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value) && $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            $limit = (int) $value;
            return $limit > 0 ? $limit : null;
        }

        return null;
    }

    protected static function eagerLoadIncludes(mixed $target, ?Request $request): void
    {
        if (config('jsonapi.eager_load_includes', true) !== true) {
            return;
        }

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
            $limit = self::includeLimit($request, $name);
            $included = array_merge($included, self::includedFromRelated($related, $limit));
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

                $limit = self::includeLimit($request, $name);
                $included = array_merge($included, self::includedFromRelated($relations[$name], $limit));
            }
        }

        return self::uniqueIncluded($included);
    }

    protected static function includedFromRelated(mixed $related, ?int $limit = null): array
    {
        if ($related instanceof \Illuminate\Support\Collection) {
            $items = $limit !== null ? $related->take($limit) : $related;
            return $items->map(fn ($item) => self::fromModel($item))->values()->all();
        }

        if (is_iterable($related)) {
            $items = collect($related);
            if ($limit !== null) {
                $items = $items->take($limit);
            }
            return $items->map(fn ($item) => self::fromModel($item))->values()->all();
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
