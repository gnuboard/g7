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

    // Storage table names (Settings › Retention)
    'storage_table' => [
        'analytics_visits' => 'Visit records',
        'analytics_actions' => 'Pageview and event records',
        'analytics_action_names' => 'Page and event names',
        'analytics_conversions' => 'Conversion records',
        'analytics_bot_hits' => 'Bot visit records',
        'analytics_stats_hourly' => 'Hourly aggregates',
        'analytics_stats_daily' => 'Daily aggregates',
        'analytics_dimension_stats' => 'Per-extension category aggregates',
        'analytics_salts' => 'Anonymous key material',
        'analytics_archive_runs' => 'Aggregation run history',
        'analytics_collect_failures' => 'Collection failure records',
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
        'db_error' => 'Database error',
        'listener_exception' => 'Extension listener error',
        'unknown_goal' => 'Unregistered goal',
        'unknown_dimension' => 'Unregistered breakdown',
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
        'campaign_medium' => 'Campaign medium',
        'search_tab' => 'Search tab',
        'event_name' => 'Event',
        'visit_duration' => 'Visit duration',
        'visit_pages' => 'Pages per visit',
    ],

    // Visit duration / pages-per-visit bucket labels
    'bucket' => [
        'duration_range' => ':from – :to',
        'duration_over' => 'over :from',
        'seconds' => ':ns',
        'minutes' => ':nm',
        'minutes_seconds' => ':mm :ss',
        'hours' => ':nh',
        'pages_one' => ':n page|:n pages',
        'pages_range' => ':from–:to pages',
        'pages_over' => ':from+ pages',
    ],

    // Site search tabs
    'search_tab' => [
        'all' => 'All',
        'posts' => 'Posts',
        'products' => 'Products',
        'pages' => 'Pages',
    ],

    // Archiving
    'archive' => [
        'skipped_locked' => 'Skipped because the same period is already being archived.',
        'skipped_beyond_retention' => 'Skipped because the raw records for this day are past the retention period. Existing aggregates are kept as they are.',
        'skipped_finalized' => 'Skipped because this day is already finalized. Turn on "Re-archive finalized days" to recalculate it.',
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
        'inactive_dimension' => ':key (inactive extension)',
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
    // Field names in validation messages — settings fields match the admin analytics settings screen
    'attributes' => [
        'enabled' => 'visitor analytics collection',
        'privacy_mode' => 'privacy level',
        'respect_dnt' => 'respect Do Not Track',
        'visit_timeout_minutes' => 'visit timeout (minutes)',
        'max_actions_per_visit' => 'maximum actions per visit',
        'ip_mask_octets' => 'IP masking',
        'geo_before_mask' => 'look up region before masking',
        'anonymize_referrer' => 'referrer URL anonymization',
        'pseudonymize_user_id' => 'member ID pseudonymization',
        'visitor_cookie_days' => 'visitor cookie lifetime',
        'excluded_url_patterns' => 'excluded URL patterns',
        'excluded_ips' => 'excluded IPs and ranges',
        'excluded_user_agents' => 'excluded user agent strings',
        'ip_allowlist' => 'allowed IPs',
        'block_headless' => 'exclude automated browsers',
        'block_cloud_ip_ranges' => 'block cloud IP ranges',
        'cloud_ip_ranges_updated_at' => 'cloud IP ranges updated at',
        'country_filter_mode' => 'country filter',
        'country_filter_codes' => 'country codes',
        'exclude_prefetch' => 'exclude prefetch',
        'bot_detector_enabled' => 'SEO bot detection',
        'bot_tracking_enabled' => 'record bot requests',
        'site_search_enabled' => 'record site search terms',
        'track_downloads' => 'track downloads',
        'track_outlinks' => 'track outbound link clicks',
        'download_extensions' => 'download file extensions',
        'retention_days' => 'ledger retention',
        'hourly_retention_days' => 'hourly aggregate retention',
        'bot_hits_retention_days' => 'bot record retention (days)',
        'failure_log_retention_days' => 'failure log retention (days)',
        'ledger_warning_actions' => 'action warning row count',
        'ledger_warning_visits' => 'visit warning row count',
        'archive_chunk_size' => 'archive chunk size (visits)',
        'archive_on_view_enabled' => 'archive on view',
        'archive_on_view_max_visits' => 'archive on view — daily visit limit',
        'duration_bucket_bounds' => 'visit duration bucket bounds (seconds)',
        'pages_bucket_bounds' => 'pages per visit bucket bounds',
        'realtime_poll_seconds' => 'realtime polling (seconds)',
        'realtime_window_minutes' => 'current visitors window (minutes)',
        'top_n_overview' => 'overview list size',
        'top_n_dashboard' => 'dashboard list size',
        'top_n_detail' => 'detail list size',
        'report_weekly_enabled' => 'weekly report email',
        'report_monthly_enabled' => 'monthly report email',
        'public_summary_enabled' => 'public visitor counter',
        'preset' => 'period preset',
        'from' => 'start date',
        'to' => 'end date',
        'unit' => 'time unit',
        'exclude' => 'exclusions',
        'format' => 'export format',
        'q' => 'search term',
        'sort' => 'sort order',
        'label' => 'item',
        'type' => 'visitor type',
        'minutes' => 'window (minutes)',
        'reason' => 'failure reason',
        'run_type' => 'archive run type',
        'force' => 'reaggregate finalized days',
        'ip' => 'IP address',
        'visitor_key' => 'visitor key',
        'mask_octets' => 'octets to mask',
        'hash' => 'hashing',
        'confirm' => 'irreversible action confirmation',
        't' => 'action type',
        'url' => 'page URL',
        'title' => 'page title',
        'ref' => 'referrer URL',
        'res' => 'screen resolution',
        'lang' => 'browser language',
        'locale' => 'site locale',
        'prev' => 'previous page time (seconds)',
        'init' => 'initial page flag',
        'target' => 'target URL',
        'name' => 'event name',
        'goal' => 'conversion goal',
        'attrs' => 'action attributes',
        'dims' => 'custom dimensions',
        'ch' => 'client hints',
        'wd' => 'automated browser flag',
    ],
];
