<?php

return [
    'content_type' => 'application/vnd.api+json',

    'include_jsonapi' => false,
    'include_compound_documents' => true,
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

    'errors' => [
        'include_all_members' => true,
        'default_status' => '500',
        'default_title' => 'Error',
        'default_code' => 'ERROR',
        'default_detail' => null,
        'default_links' => [
            'about' => null,
            'type' => null,
        ],
        'default_source' => [
            'pointer' => '/data',
        ],
        'default_meta' => [],
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
        'max_include_param' => 'max_include',
        'max_include' => null,
        'allow_all_sorts' => false,
        'allow_all_filters' => true,
        'allow_all_includes' => false,
        'allow_all_fields' => false,
        'allowed_sorts' => [],
        'allowed_filters' => [],
        'allowed_includes' => [],
        'allowed_fields' => [],
    ],

    'relationships' => [
        // Emit relationship links for relations requested via ?include= even if not loaded.
        'links_for_includes' => true,
    ],

    // Whether to eager-load ?include= relations during response building.
    'eager_load_includes' => true,
];
