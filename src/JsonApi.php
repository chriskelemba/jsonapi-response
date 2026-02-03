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

        if ($payload instanceof LengthAwarePaginator || $payload instanceof Paginator) {
            $resources = self::collection($payload->getCollection(), $type);

            $document = self::data($resources)
                ->withPagination($payload)
                ->withLinks(['self' => self::requestUrl($request)])
                ->toArray();

            return Response::jsonApi($document, $status, $headers);
        }

        if (is_iterable($payload) && ! is_array($payload)) {
            $resources = self::collection($payload, $type);

            $document = self::data($resources)
                ->withLinks(['self' => self::requestUrl($request)])
                ->toArray();

            return Response::jsonApi($document, $status, $headers);
        }

        if (is_object($payload)) {
            $resource = self::fromModel($payload, $type);
            if ($status === 201 && ! array_key_exists('Location', $headers)) {
                $headers['Location'] = self::resourceSelfLink($payload, $type, null);
            }

            $document = self::data($resource)
                ->withLinks(['self' => self::resourceSelfLink($payload, $type, $request)])
                ->toArray();

            return Response::jsonApi($document, $status, $headers);
        }

        if (is_array($payload) && self::looksLikeDocument($payload)) {
            return Response::jsonApi($payload, $status, $headers);
        }

        if (is_array($payload)) {
            $document = self::data($payload)
                ->withLinks(['self' => self::requestUrl($request)])
                ->toArray();

            return Response::jsonApi($document, $status, $headers);
        }

        return Response::jsonApi(['data' => $payload], $status, $headers);
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
            'attributes' => $attributes,
        ];

        if ($id !== null) {
            $resource['id'] = (string) $id;
        }

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

        if (is_string($value)) {
            return $value;
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
}
