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

You do not need to publish config to use the package.

## Quick Start

### Collection + Pagination

```php
use ChrisKelemba\ResponseApi\JsonApi;

$books = Book::query()->paginate(15);

return JsonApi::response($books, 'books', request());
```

### Drop-in Response Replacement

Keep your existing controller logic and only replace the return line:

```php
$books = Book::all();

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

### Minimal Controller Setup

For low-boilerplate controllers, use model-aware helpers:

```php
use App\Models\User;
use ChrisKelemba\ResponseApi\JsonApi;
use Illuminate\Http\Request;

public function index(Request $request)
{
    $query = JsonApi::applyModelQuery(User::query(), $request, 'users', [
        'allowed_includes' => ['tasks', 'roles', 'permissions'],
    ]);

    $users = JsonApi::paginateQuery($query, $request);

    return JsonApi::response($users, 'users', $request);
}
```

`applyModelQuery()` auto-derives allow-lists from the model and excludes hidden attributes.

Supported query params:
- `?sort=created_at` or `?sort=-created_at`
- `?filter[author]=Jane`
- `?include=author,comments`
- `?max_include=100`
- `?max_include[comments]=50`
- `?fields[books]=title,author`
- `?page[number]=2&page[size]=25`

## Relationships + Included

If a relationship is **loaded**, the package will add a JSON:API `relationships` object automatically.

To load and include related resources without controller code, use `?include=`:

```
GET /api/book-authors?include=books
```

This will:
- Load `books` automatically.
- Add `relationships.books` to each author.
- Add `included` resources for the related books (unless `include_compound_documents` is disabled).
- If `relationships.links_for_includes` is enabled, relationship links are emitted even when the relation isn't loaded.

### Limit Included Size

If a relationship is large, you can cap how many related resources are serialized in `relationships` and `included`:

```
GET /api/book-authors/51?include=books&max_include=100
```

Notes:
- This limits the **response size** only. It does not change how many related rows are loaded from the database.
- For large related sets, prefer paging the related collection via a filtered endpoint.
- There is no default include cap unless you set `query.max_include` or pass `max_include` in the request.

### Relationship Pagination (JSON:API standard)

`include` is for sideloading, not true paging of related collections.

Use the related endpoint for paging:

```
GET /api/book-authors/51/books?page[number]=1&page[size]=10
```

### Pagination Links Preserve Query

Pagination links (`first`, `next`, `last`) keep your current query parameters (e.g. `filter`, `include`, `fields`).

Example (controller stays simple):

```php
public function index(Request $request)
{
    $authors = BookAuthor::query()->paginate(15);

    return JsonApi::response($authors, 'book-authors', $request);
}
```

## Error Responses

```php
use ChrisKelemba\ResponseApi\JsonApi;

$errors = [
    JsonApi::error(
        422,
        'Validation Error',
        'The title field is required.',
        'VALIDATION_ERROR',
        ['pointer' => '/data/attributes/title'],
        ['field' => 'title'],
        'err_123',
        ['about' => 'https://api.example.com/errors/err_123']
    ),
];

return JsonApi::responseErrors($errors, 422);
```

Supported error members include `id`, `links`, `status`, `code`, `title`, `detail`, `source`, and `meta`.

### Validation Errors

```php
use ChrisKelemba\ResponseApi\JsonApi;

return JsonApi::responseValidationErrors($validator->errors());
```

## Configuration

All defaults are in `config/jsonapi.php`.

Key options:
- `transform_keys`: Enforce JSON:API member naming recommendations (camelCase + ASCII)
- `resource_links` / `relationship_links`: Include resource/relationship links
- `method_override`: Enable `X-HTTP-Method-Override: PATCH`
- `include_jsonapi`: Include or suppress the top-level `jsonapi` object
- `include_compound_documents`: Include or suppress top-level `included`
- `eager_load_includes`: Toggle eager loading for `?include=`
- `relationships.links_for_includes`: Emit relationship links for `?include=` even if not loaded
- `query.*`: Allow‑lists for sort/filter/include/fields
- `query.allow_all_filters`: Allow any `filter[...]` key without an allow‑list (useful for dynamic APIs)
- `query.allow_all_sorts`: Allow any `sort` field without an allow‑list
- `query.allow_all_includes`: Allow any `include` without an allow‑list
- `query.allow_all_fields`: Allow any `fields[...]` without an allow‑list
- `errors.include_all_members`: Force error objects to include all standard members

## Notes

- Content-Type is set to `application/vnd.api+json`.
- ISO‑8601 timestamps are recommended.
- Eloquent hidden attributes are respected in serialized `attributes` (e.g. `password`, `remember_token`).

## License

MIT
