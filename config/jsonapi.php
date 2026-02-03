<?php

return [
    'content_type' => 'application/vnd.api+json',

    'jsonapi' => [
        'version' => '1.1',
        'meta' => [],
    ],

    'transform_keys' => true,
    'transform_recursive' => true,

    'resource_links' => true,
    'relationship_links' => true,

    'method_override' => [
        'enabled' => true,
        'header' => 'X-HTTP-Method-Override',
        'from' => 'POST',
        'to' => 'PATCH',
        'apply_to_groups' => ['api'],
    ],

    'pagination' => [
        'meta_key' => 'page',
        'include_total' => true,
        'include_last_page' => true,
    ],

    'query' => [
        'sort_param' => 'sort',
        'filter_param' => 'filter',
        'include_param' => 'include',
        'fields_param' => 'fields',
        'allowed_sorts' => [],
        'allowed_filters' => [],
        'allowed_includes' => [],
        'allowed_fields' => [],
    ],
];
