<?php

namespace App\Http\Requests\Admin\Analytics\Concerns;

use App\Enums\Analytics\CountryFilterMode;
use App\Enums\Analytics\DispatchMode;
use App\Enums\Analytics\PrivacyMode;
use App\Enums\Analytics\ReferrerAnonymization;
use Illuminate\Validation\Rule;

/**
 * 방문자 통계 설정 검증 규칙
 *
 * 설정은 두 길로 저장됩니다 — 통계 화면의 전용 API 와, 코어 설정 API 입니다. 규칙을
 * 한쪽에만 두면 다른 길로 들어온 값이 검증 없이 저장되고, 그 값이 수집·정리·집계를
 * 조용히 망가뜨립니다. 그래서 규칙을 한 곳에 두고 둘이 같이 씁니다.
 */
trait HasAnalyticsSettingsRules
{
    /**
     * 방문자 통계 설정 검증 규칙을 반환합니다.
     *
     * @param  string  $prefix  규칙 키 앞에 붙일 접두 (빈 문자열이면 키 이름 그대로)
     * @return array<string, mixed> 검증 규칙
     */
    protected function analyticsSettingsRules(string $prefix = ''): array
    {
        $key = static fn (string $name): string => $prefix === '' ? $name : $prefix.'.'.$name;

        return [
            // 수집 동작
            $key('enabled') => ['sometimes', 'boolean'],
            $key('privacy_mode') => ['sometimes', Rule::in(PrivacyMode::values())],
            $key('respect_dnt') => ['sometimes', 'boolean'],
            $key('dispatch_mode') => ['sometimes', Rule::in(DispatchMode::values())],
            $key('visit_timeout_minutes') => ['sometimes', 'integer', 'min:5', 'max:120'],
            $key('max_actions_per_visit') => ['sometimes', 'integer', 'min:50', 'max:10000'],

            // 개인정보 장치
            $key('ip_mask_octets') => ['sometimes', 'integer', 'min:0', 'max:3'],
            $key('geo_before_mask') => ['sometimes', 'boolean'],
            $key('anonymize_referrer') => ['sometimes', Rule::in(ReferrerAnonymization::values())],
            $key('pseudonymize_user_id') => ['sometimes', 'boolean'],
            $key('visitor_cookie_days') => ['sometimes', 'integer', 'min:1', 'max:730'],

            // 제외 규칙
            $key('excluded_url_patterns') => ['sometimes', 'array'],
            $key('excluded_url_patterns.*') => ['string', 'max:255'],
            $key('excluded_ips') => ['sometimes', 'array'],
            $key('excluded_ips.*') => ['string', 'max:64'],
            $key('excluded_user_agents') => ['sometimes', 'array'],
            $key('excluded_user_agents.*') => ['string', 'max:255'],
            $key('ip_allowlist') => ['sometimes', 'array'],
            $key('ip_allowlist.*') => ['string', 'max:64'],
            $key('block_headless') => ['sometimes', 'boolean'],
            $key('block_cloud_ip_ranges') => ['sometimes', 'boolean'],
            $key('cloud_ip_ranges_updated_at') => ['sometimes', 'nullable', 'string', 'max:64'],
            $key('country_filter_mode') => ['sometimes', Rule::in(CountryFilterMode::values())],
            $key('country_filter_codes') => ['sometimes', 'array'],
            $key('country_filter_codes.*') => ['string', 'size:2'],
            $key('exclude_prefetch') => ['sometimes', 'boolean'],
            $key('bot_detector_enabled') => ['sometimes', 'boolean'],
            $key('bot_tracking_enabled') => ['sometimes', 'boolean'],

            // 무엇을 셀지
            $key('site_search_enabled') => ['sometimes', 'boolean'],
            $key('track_downloads') => ['sometimes', 'boolean'],
            $key('track_outlinks') => ['sometimes', 'boolean'],
            $key('download_extensions') => ['sometimes', 'array'],
            $key('download_extensions.*') => ['string', 'max:16'],

            // 보존 — 하한 아래로 내려가면 오늘 방문까지 지워진다
            $key('retention_days') => ['sometimes', 'integer', 'min:7', 'max:3650'],
            $key('hourly_retention_days') => ['sometimes', 'integer', 'min:7', 'max:3650'],
            $key('bot_hits_retention_days') => ['sometimes', 'integer', 'min:1', 'max:365'],
            $key('failure_log_retention_days') => ['sometimes', 'integer', 'min:1', 'max:365'],
            $key('ledger_warning_actions') => ['sometimes', 'integer', 'min:1000'],
            $key('ledger_warning_visits') => ['sometimes', 'integer', 'min:1000'],
            $key('archive_chunk_size') => ['sometimes', 'integer', 'min:500', 'max:50000'],

            // 화면 동작
            $key('realtime_poll_seconds') => ['sometimes', 'integer', 'min:10', 'max:300'],
            $key('realtime_window_minutes') => ['sometimes', 'integer', 'min:1', 'max:60'],
            $key('top_n_overview') => ['sometimes', 'integer', 'min:3', 'max:100'],
            $key('top_n_dashboard') => ['sometimes', 'integer', 'min:3', 'max:100'],
            $key('top_n_detail') => ['sometimes', 'integer', 'min:3', 'max:100'],

            // 리포트·공개 요약
            $key('report_weekly_enabled') => ['sometimes', 'boolean'],
            $key('report_monthly_enabled') => ['sometimes', 'boolean'],
            $key('public_summary_enabled') => ['sometimes', 'boolean'],
        ];
    }
}
