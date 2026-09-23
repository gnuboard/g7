<?php

// changelog:allow 미출시 7.1.0 방문자 통계의 누락 키 보충 — 배포된 적이 없어 운영자가
// 원문 키를 본 화면 자체가 없다. 기능 자체는 같은 절의 Added 항목이 이미 서술한다.

return [
    // Privacy collection modes
    'privacy_mode' => [
        'full' => 'Store IP',
        'consent' => 'Consent-based',
        'anonymous' => 'Anonymous',
    ],

    // Visitor types
    'visitor_type' => [
        'guest' => 'Guest',
        'member' => 'Member',
        'admin' => 'Administrator',
        'bot' => 'Bot / Crawler',
    ],

    // Collection sources
    'visit_source' => [
        'beacon' => 'Browser',
        'server' => 'Server',
    ],

    // Action types
    'action_type' => [
        'pageview' => 'Pageview',
        'download' => 'Download',
        'outlink' => 'Outbound link',
        'site_search' => 'Site search',
        'event' => 'Event',
        'goal' => 'Conversion',
    ],

    // Referrer types
    'referrer_type' => [
        'direct' => 'Direct',
        'search' => 'Search engine',
        'website' => 'Website',
        'campaign' => 'Campaign',
    ],

    // Device types
    'device_type' => [
        'desktop' => 'Desktop',
        'mobile' => 'Mobile',
        'tablet' => 'Tablet',
        'other' => 'Other',
    ],

    // Batch run types
    'archive_run_type' => [
        'hour' => 'Hourly archiving',
        'day' => 'Daily archiving',
        'reaggregate' => 'Range re-archiving',
        'anonymize' => 'Retroactive anonymization',
        'purge' => 'Purge all data',
        'extension_purge' => 'Extension data cleanup',
    ],

    // Batch run statuses
    'archive_run_status' => [
        'running' => 'Running',
        'success' => 'Succeeded',
        'failed' => 'Failed',
        'skipped' => 'Skipped',
    ],

    // Collection failure reasons
    'collect_failure_reason' => [
        'validation' => 'Invalid request format',
        'max_actions' => 'Action limit per visit exceeded',
        'dictionary' => 'Name dictionary write failed',
        'db_error' => 'Database error',
        'listener_exception' => 'Extension listener error',
        'unknown_goal' => 'Unregistered goal',
        'unknown_dimension' => 'Unregistered dimension',
    ],

    // Exclusion reasons
    'exclusion_reason' => [
        'disabled' => 'Collection disabled',
        'prefetch' => 'Prefetch request',
        'dnt' => 'Do Not Track',
        'opt_out' => 'Visitor opted out',
        'url_pattern' => 'Excluded path',
        'ip_rule' => 'Excluded IP',
        'ua_rule' => 'Excluded User-Agent',
        'headless' => 'Automated browser',
        'cloud_ip' => 'Cloud IP range',
        'country' => 'Country filter',
        'bot' => 'Bot',
        'consent' => 'No consent',
    ],

    // Country filter modes
    'country_filter_mode' => [
        'none' => 'Disabled',
        'include' => 'Include listed countries only',
        'exclude' => 'Exclude listed countries',
    ],

    // Referrer anonymization
    'referrer_anonymization' => [
        'none' => 'Keep full URL',
        'domain_only' => 'Domain only',
    ],

    // Statistics dimensions
    'dimension' => [
        'page_url' => 'Page URL',
        'page_title' => 'Page title',
        'entry_page' => 'Entry page',
        'exit_page' => 'Exit page',
        'referrer_type' => 'Referrer type',
        'referrer_name' => 'Referrer',
        'referrer_keyword' => 'Search keyword',
        'campaign' => 'Campaign',
        'campaign_source' => 'Campaign source',
        'browser' => 'Browser',
        'browser_version' => 'Browser version',
        'os' => 'Operating system',
        'device_type' => 'Device',
        'resolution' => 'Screen resolution',
        'browser_language' => 'Browser language',
        'locale' => 'Site language',
        'country' => 'Country',
        'region' => 'Region',
        'city' => 'City',
        'site_search' => 'Site search term',
        'download' => 'Downloaded file',
        'outlink' => 'Outbound link',
        'goal' => 'Goal',
        'bot_name' => 'Bot name',
        'bot_page' => 'Page crawled by bot',
    ],

    // Archiving
    'archive' => [
        'skipped_locked' => 'Skipped because the same period is already being archived.',
        'skipped_beyond_retention' => 'Skipped because the raw records for this day are past the retention period. Existing aggregates are kept as they are.',
        'skipped_finalized' => 'Skipped because this day is already finalized. Turn on "Re-archive finalized days" to recalculate it.',
        'range_invalid' => 'The re-aggregation period is invalid.',
        'stale_run' => 'Closed as failed because the run stopped without reporting its end (worker stopped, timed out, etc.). Run it again if needed.',
        'job_failed' => 'The job failed, so the run was closed: :reason',
    ],

    // Dashboard alerts
    'alerts' => [
        'ledger_limit_title' => 'Visitor statistics have grown large',
        'ledger_limit_message' => ':visits visits and :actions actions are stored. Retention is currently :days days — shortening it removes older records on the next cleanup.',
        'ledger_limit_action' => 'Open retention settings',
    ],

    // Data subject requests
    'privacy' => [
        'ip_lookup_limited' => 'Records collected in anonymous mode can only be looked up by IP for the last :days days — older entries cannot be matched back to an address.',
        'subjects_fetch_success' => 'Loaded the data subject records.',
        'subject_delete_success' => 'Deleted the data subject records.',
        'anonymize_queued' => 'Started the retroactive anonymization.',
    ],

    // API response messages
    'overview' => [
        'fetch_success' => 'Loaded the analytics overview.',
    ],
    'report' => [
        'fetch_success' => 'Loaded the report.',
    ],
    'series' => [
        'fetch_success' => 'Loaded the trend series.',
    ],
    'patterns' => [
        'fetch_success' => 'Loaded the usage patterns.',
    ],
    'realtime' => [
        'fetch_success' => 'Loaded the current visitors.',
    ],
    'diagnostics' => [
        'fetch_success' => 'Loaded the collection diagnostics.',
    ],
    'collect_failures' => [
        'fetch_success' => 'Loaded the collection failure log.',
    ],
    'archive_runs' => [
        'fetch_success' => 'Loaded the archive run history.',
        'not_found' => 'That archive run was not found.',
        'queued' => 'Archiving has started.',
    ],
    'settings' => [
        'fetch_success' => 'Loaded the analytics settings.',
        'save_success' => 'Saved the analytics settings.',
    ],
    'opt_out' => [
        'fetch_success' => 'Loaded the opt-out state.',
        'enabled' => 'This browser is now excluded from analytics.',
        'disabled' => 'This browser is no longer excluded from analytics.',
    ],
    'data' => [
        'fetch_success' => 'Loaded the storage overview.',
        'purge_success' => 'Deleted all analytics records.',
    ],
    'cloud_ranges' => [
        'refresh_success' => 'Refreshed the cloud IP range list.',
        'refresh_failed' => 'Could not fetch the ranges, so the existing list was kept.',
    ],
    'visitors' => [
        'fetch_success' => 'Loaded the visitor records.',
        'index_success' => 'Loaded the visitor list.',
        'anonymous_mode_notice' => 'In anonymous mode the visitor key rotates daily — visits by the same person are only grouped within a single day.',
    ],
    'summary' => [
        'fetch_success' => 'Loaded the visitor summary.',
        'disabled' => 'The public visitor summary is turned off.',
    ],
];
