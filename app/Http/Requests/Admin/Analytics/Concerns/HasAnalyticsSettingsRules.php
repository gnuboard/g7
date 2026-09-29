<?php

namespace App\Http\Requests\Admin\Analytics\Concerns;

use App\Enums\Analytics\CountryFilterMode;
use App\Enums\Analytics\PrivacyMode;
use App\Enums\Analytics\ReferrerAnonymization;
use App\Rules\Analytics\AscendingBounds;
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
     * 검증을 마친 설정 값을 규칙이 선언한 형으로 되돌립니다.
     *
     * 폼 입력은 숫자 칸이든 토글이든 문자열로 옵니다. `integer` 규칙은 "45" 를 통과시키므로
     * 그대로 저장하면 설정 파일과 응답에 문자열이 남고, 숫자로 알고 읽던 쪽이 문자열을 받습니다.
     * 검증이 끝난 뒤에 바꾸므로 잘못된 값이 형 변환에 묻혀 통과하는 일은 없습니다.
     *
     * @param  array<string, mixed>  $settings  검증을 마친 설정 값
     * @return array<string, mixed> 선언한 형으로 바꾼 설정 값
     */
    protected function castAnalyticsSettings(array $settings): array
    {
        foreach ($this->analyticsSettingsRules() as $key => $rules) {
            if (! array_key_exists($key, $settings) || ! is_array($rules)) {
                continue;
            }

            if (in_array('integer', $rules, true) && is_numeric($settings[$key])) {
                $settings[$key] = (int) $settings[$key];

                continue;
            }

            if (in_array('boolean', $rules, true)) {
                $settings[$key] = filter_var($settings[$key], FILTER_VALIDATE_BOOLEAN);
            }
        }

        return $settings;
    }

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

            // 집계 — 화면을 열 때 집계(1분 간격)와 그 안전장치, 구간 리포트 경계
            $key('archive_on_view_enabled') => ['sometimes', 'boolean'],
            $key('archive_on_view_max_visits') => ['sometimes', 'integer', 'min:1000', 'max:1000000'],
            $key('duration_bucket_bounds') => ['sometimes', 'string', 'max:200', 'regex:/^\d+(,\d+){0,14}$/', new AscendingBounds(1, 86400)],
            $key('pages_bucket_bounds') => ['sometimes', 'string', 'max:200', 'regex:/^\d+(,\d+){0,14}$/', new AscendingBounds(1, 1000)],

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

    /**
     * 방문자 통계 설정 필드의 검증 항목명을 반환합니다.
     *
     * 규칙 키에서 그대로 파생합니다 — 필드를 규칙에 더하고 항목명을 빠뜨리면 저장 실패 안내에
     * `analytics.retention_days` 같은 내부 키가 그대로 나가기 때문입니다. 배열 항목(`*.`)은
     * 부모 필드의 이름을 씁니다. 이름은 관리자 「방문자 통계 설정」 화면의 항목 이름과 같습니다.
     *
     * @param  string  $prefix  규칙 키 앞에 붙은 접두 (빈 문자열이면 키 이름 그대로)
     * @return array<string, string> 규칙 키 => 항목명
     */
    protected function analyticsSettingsAttributes(string $prefix = ''): array
    {
        $attributes = [];

        foreach (array_keys($this->analyticsSettingsRules($prefix)) as $key) {
            $field = $prefix === '' ? $key : substr($key, strlen($prefix) + 1);
            $attributes[$key] = __('analytics.attributes.'.preg_replace('/\.\*$/', '', $field));
        }

        return $attributes;
    }
}
