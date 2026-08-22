<?php

/**
 * Search related messages (English)
 */

return [
    'empty_keyword' => 'Please enter a search keyword.',
    'results_found' => ':count results found.',
    'results_found_at_least' => 'More than :count results found.',
    'result_cap_notice' => 'More than :cap items matched, so the exact total was not counted. You can still move to the next page.',
    'refine_query_hint' => 'Narrow your search terms to see the exact total and jump to the last page.',
    'no_results' => 'No results found.',
    'view_more' => 'View more',

    // Validation messages
    'validation' => [
        'q_min' => 'Please enter at least 2 characters for search.',
        'q_max' => 'Search keyword must not exceed 200 characters.',
        'page_integer' => 'Page number must be a number.',
        'page_min' => 'Page number must be at least 1.',
        'page_max' => 'Page number must not exceed :max. Please narrow your search terms.',
        'per_page_integer' => 'Items per page must be a number.',
        'per_page_min' => 'Items per page must be at least 1.',
        'per_page_max' => 'Items per page must not exceed 100.',
    ],

    // Search index inspection / rebuild (search:index) — engine neutral
    'index' => [
        'status' => [
            'healthy' => 'Healthy',
            'degraded' => 'Partial',
            'stale' => 'Not indexed',
            'skipped' => 'Not determinable',
        ],

        'no_maintainer' => 'The current search engine (:driver) does not provide index inspection. It will appear here once its provider registers one.',
        'unavailable' => 'The index of the current search engine (:driver) cannot be inspected.',
        'no_targets' => 'The search engine (:driver) has no index to inspect.',
        'driver_label' => 'Search engine: :driver',

        'col' => [
            'index' => 'Index',
            'status' => 'Status',
            'measurement' => 'Measurement',
        ],

        'counts' => 'Healthy :healthy · Partial :degraded · Not indexed :stale · Not determinable :skipped  (total :total)',
        'rebuild_targets' => ':count index(es) to rebuild',
        'rebuild_cost_warning' => 'Rebuilding locks or re-indexes the target. On a live site, run it during a maintenance window.',
        'rebuild_confirm' => 'Rebuild the indexes listed above?',
        'rebuild_after_bulk_confirm' => 'Rebuild search indexes after the bulk update? (default: no — on a live site, run it during a maintenance window)',
        'rebuild_skipped' => 'Skipping the rebuild.',
        'stale_hint' => 'Some indexes hold no indexed content. Rebuild them with `php artisan search:index --repair`.',
        'stale_after_update' => ':count search index(es) hold no indexed content, so those searches return nothing.',
        'rebuilt_item' => 'Rebuilt: :index',
        'rebuild_failed_item' => 'Rebuild failed: :index — :error',
        'still_stale_item' => 'Still not indexed after rebuild: :index',
        'degraded_hint' => '"Partial" may come from the engine tokenizer, so it is not rebuilt automatically. Inspect individually if needed.',

        'report' => [
            'nothing_to_do' => 'Inspected :count · nothing to rebuild',
            'rebuilt' => 'Inspected :inspected · rebuilt :repaired · failed :failed · still stale :remaining',
        ],

        // mysql-fulltext driver specific
        'fulltext' => [
            'unsupported_driver' => 'The current database driver does not support FULLTEXT (search falls back to LIKE; nothing to inspect).',
            'self_match' => ':found/:probed rows found themselves',
            'skip' => [
                'no_single_pk' => 'No single-column primary key, so per-row inspection is not possible',
                'no_rows' => 'No row has content in the indexed columns',
                'no_tokens' => 'Could not build a search token from the sampled rows (content too short)',
            ],
        ],
    ],
];
