<?php

return [
    /*
    |--------------------------------------------------------------------------
    | List pagination
    |--------------------------------------------------------------------------
    | Total count accuracy labels and large-result-set notices.
    */

    'total_relation' => [
        'exact' => 'exact',
        'at_least' => 'at least',
    ],

    'total_exact' => ':count results',
    'total_at_least' => 'more than :count results',

    'result_cap_notice' => 'More than :cap items matched, so the exact total was not counted. You can still move to the next page.',
    'refine_query_hint' => 'Narrow your search terms to see the exact total and jump to the last page.',
];
