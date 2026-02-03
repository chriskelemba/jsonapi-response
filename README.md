# JSON:API Response for Laravel

A lightweight JSON:API response helper for Laravel with pagination, links, query helpers (sort/filter/include/fields), and consistent response formatting.

## Install

```bash
composer require chriskelemba/jsonapi-response
```

Laravel package auto-discovery will register the service provider automatically.

### Publish config (optional)

```bash
php artisan vendor:publish --tag=jsonapi-config
```

## Quick Start

### Collection + Pagination

```php
use ChrisKelemba\ResponseApi\JsonApi;

$books = Book::query()->paginate(15);

return JsonApi::response($books, 'books', request());
```

### Single Resource

```php
return JsonApi::response($book, 'books', request());
```

### Create (201 + Location header)

```php
$book = Book::create($validated);

return JsonApi::response($book, 'books', null, 201);
```

### Delete (204)

```php
return JsonApi::response(null, null, null, 204);
```

## Query Helpers (JSON:API recommendations)

Apply sort, filter, include, and sparse fieldsets using a safe allow‑list.

```php
$query = JsonApi::applyQuery(Book::query(), request(), 'books', [
    'allowed_sorts' => ['created_at', 'title'],
    'allowed_filters' => ['author', 'genre'],
    'allowed_includes' => ['author'],
    'allowed_fields' => ['title', 'author', 'created_at'],
]);

$books = $query->paginate(15);

return JsonApi::response($books, 'books', request());
```

Supported query params:
- `?sort=created_at` or `?sort=-created_at`
- `?filter[author]=Jane`
- `?include=author,comments`
- `?fields[books]=title,author`

## Error Responses

```php
use ChrisKelemba\ResponseApi\JsonApi;

$errors = [
    JsonApi::error(422, 'Validation Error', 'The title field is required.', 'VALIDATION_ERROR', [
        'pointer' => '/data/attributes/title',
    ]),
];

return JsonApi::responseErrors($errors, 422);
```

## Configuration

All defaults are in `config/jsonapi.php`.

Key options:
- `transform_keys`: Enforce JSON:API member naming recommendations (camelCase + ASCII)
- `resource_links` / `relationship_links`: Include resource/relationship links
- `method_override`: Enable `X-HTTP-Method-Override: PATCH`
- `query.*`: Allow‑lists for sort/filter/include/fields

## Notes

- Content-Type is set to `application/vnd.api+json`.
- ISO‑8601 timestamps are recommended.

## License

MIT
