<?php

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

    // Dispatch modes
    'dispatch_mode' => [
        'queue' => 'Queued',
        'sync' => 'Immediate',
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
];
