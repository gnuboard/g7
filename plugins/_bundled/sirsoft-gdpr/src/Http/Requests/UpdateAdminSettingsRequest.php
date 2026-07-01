<?php

namespace Plugins\Sirsoft\Gdpr\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 관리자 GDPR 설정 저장 요청 검증
 *
 * PUT /api/plugins/sirsoft-gdpr/admin/settings
 *
 * 운영자가 설정 화면에서 정책 메타데이터·쿠키 배너·마이페이지 카드 토글을
 * 저장할 때 사용. plugin.php::getSettingsSchema() 와 키 일치를 보장합니다.
 */
class UpdateAdminSettingsRequest extends FormRequest
{
    /**
     * 배너 위치 허용 값
     */
    private const BANNER_POSITIONS = [
        'bottom_bar',
        'bottom_left_popup',
        'bottom_right_popup',
        'centered_modal',
    ];

    /**
     * 차단 도메인 정규식
     *
     * - FQDN 만 허용 (점 1개 이상 — RFC 1035 기반)
     * - 와일드카드 prefix `*.` 허용 (예: *.hotjar.com)
     * - 하이픈은 라벨 시작/끝 금지 (RFC 1035)
     * - 대소문자 무관
     * - 숫자만으로 구성된 라벨 허용 (예: 1.2.3.4 는 IPv4 처럼 보이지만 정규식상 통과 — 실제 DNS 동작은 CDN/PaaS 에 의존)
     * - punycode·localhost·단일 라벨 도메인 미지원 (운영자 안내 박스 hint 명시)
     */
    private const DOMAIN_REGEX = '/^(\*\.)?[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/i';

    /**
     * 권한 확인 (permission 미들웨어에서 처리)
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙 정의
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // 정책 메타데이터 — cookie_policy_version 은 gdpr_policy_versions 테이블이 SSoT.
            // 운영자가 직접 입력하지 않으므로 본 FormRequest 에서 검증하지 않음 (전달되어도 stripped).
            'privacy_policy_slug' => ['nullable', 'string', 'max:100', 'regex:/^[a-z0-9\-]*$/'],
            'legal_entity_name' => ['nullable', 'string', 'max:200'],
            // 데이터 저장 위치 — GDPR Art.13(1)(f) / PIPA 28조의8 은 "국가 단위" 표기를 요구.
            // 운영자가 실수로 보안 민감 식별자 (IP / CIDR / AWS 리전 코드) 를 입력하지 않도록
            // not_regex 3종으로 차단. 통과한 일반 텍스트는 운영자 자유 입력 허용.
            'data_storage_location' => [
                'nullable',
                'string',
                'max:200',
                'not_regex:/\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}(\/\d{1,2})?\b/',                  // IPv4 / CIDR
                'not_regex:/\b[a-z]{2,}-[a-z]+(-\d+[a-z]?|\d+)\b/i',                                 // 리전 코드: AWS ap-northeast-2, GCP asia-northeast3, Azure eastus2 패턴
                'not_regex:/\b(us|eu|ap|me|af|sa|ca)-(east|west|north|south|central|northeast|southeast|northwest|southwest)\b/i',  // 리전 prefix 만 입력해도 차단 (예: us-east, ap-northeast)
            ],

            // 쿠키 배너 + 자동 차단 (F-01 / F-02) — banner_enabled 단일 토글로 통합 제어.
            // auto_blocking_enabled 별도 검증 규칙 없음: 운영자가 PUT 으로 키를 보내도 stripped.
            'banner_enabled' => ['nullable', 'boolean'],
            'banner_position' => ['nullable', 'string', 'in:'.implode(',', self::BANNER_POSITIONS)],
            // cookie_categories.*.required 는 운영자 입력 경로가 없으므로 검증하지 않음 (CookieCategory enum 이 SSoT).
            'cookie_categories' => ['nullable'],
            'cookie_categories.*.key' => ['required_with:cookie_categories', 'string', 'in:necessary,functional,analytics,marketing'],
            'cookie_categories.*.label' => ['required_with:cookie_categories', 'array'],
            'cookie_categories.*.label.ko' => ['required_with:cookie_categories', 'string', 'max:50'],
            'cookie_categories.*.label.en' => ['required_with:cookie_categories', 'string', 'max:50'],
            'cookie_categories.*.description' => ['nullable', 'array'],

            // F-02 도메인 기반 차단
            'blocked_domains' => ['nullable', 'array'],
            'blocked_domains.*' => ['array'],
            'blocked_domains.*.*' => ['string', 'max:253', 'regex:'.self::DOMAIN_REGEX],
        ];
    }

    /**
     * 검증 메시지 정의
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'data_storage_location.not_regex' => __('sirsoft-gdpr::messages.settings.fields.data_storage_location.sensitive_format'),
            'cookie_categories.*.key.in' => __('sirsoft-gdpr::messages.consent.invalid_key'),
            // 카테고리별 메시지 — 운영자가 어떤 카테고리의 어느 도메인이 잘못됐는지 즉시 식별 가능.
            // GDPR 4카테고리 중 necessary 는 차단 대상 아님 → functional / analytics / marketing 만 정의.
            'blocked_domains.functional.*.regex' => __('sirsoft-gdpr::messages.blocked_domains.invalid_format_functional'),
            'blocked_domains.functional.*.max' => __('sirsoft-gdpr::messages.blocked_domains.too_long_functional'),
            'blocked_domains.analytics.*.regex' => __('sirsoft-gdpr::messages.blocked_domains.invalid_format_analytics'),
            'blocked_domains.analytics.*.max' => __('sirsoft-gdpr::messages.blocked_domains.too_long_analytics'),
            'blocked_domains.marketing.*.regex' => __('sirsoft-gdpr::messages.blocked_domains.invalid_format_marketing'),
            'blocked_domains.marketing.*.max' => __('sirsoft-gdpr::messages.blocked_domains.too_long_marketing'),
            'blocked_domains.*.array' => __('sirsoft-gdpr::messages.blocked_domains.must_be_array'),
        ];
    }

    /**
     * 검증 메시지의 :attribute placeholder 를 사용자 친화 한국어/영어 라벨로 치환.
     *
     * Laravel 기본 :attribute 는 underscore 만 공백으로 치환한 영문 표현 (예: "privacy policy slug")
     * 노출 → 사용자 친화도 낮음. 본 메서드로 각 필드에 한국어/영어 라벨 매핑.
     *
     * 라벨 출처는 lang/{locale}/messages.php 의 settings.fields.{field}.label 키.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            // cookie_policy_version 은 자동 발행이므로 FormRequest 가 검증하지 않음 (attributes 도 불필요).
            'privacy_policy_slug' => __('sirsoft-gdpr::messages.settings.fields.privacy_policy_slug.label'),
            'legal_entity_name' => __('sirsoft-gdpr::messages.settings.fields.legal_entity_name.label'),
            'data_storage_location' => __('sirsoft-gdpr::messages.settings.fields.data_storage_location.label'),
            'banner_enabled' => __('sirsoft-gdpr::messages.settings.fields.banner_enabled.label'),
            'banner_position' => __('sirsoft-gdpr::messages.settings.fields.banner_position.label'),
            'cookie_categories' => __('sirsoft-gdpr::messages.settings.section.cookie_categories'),
            'blocked_domains' => __('sirsoft-gdpr::messages.settings.section.auto_blocking'),
        ];
    }

    /**
     * 사전 정규화:
     * 1. cookie_categories 가 JSON 문자열로 들어와도 array 로 변환
     * 2. blocked_domains 의 카테고리별 값이 textarea 줄바꿈 문자열이면 배열로 변환
     *    (한 줄당 도메인 하나, 빈 줄 제거, trim)
     * 3. cookie_categories 의 키 중 necessary 제외한 모든 키가 blocked_domains 에
     *    존재하도록 빈 배열로 자동 보충 (운영자가 새 카테고리 추가 시 UI iteration
     *    미렌더·검증 실패 방지)
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        $cookieCategories = $this->normalizeJsonArray($this->input('cookie_categories'));

        $blockedDomains = $this->normalizeBlockedDomains(
            $this->input('blocked_domains'),
            is_array($cookieCategories) ? $cookieCategories : [],
        );

        $merge = ['cookie_categories' => $cookieCategories];
        if ($blockedDomains !== null) {
            $merge['blocked_domains'] = $blockedDomains;
        }

        $this->merge($merge);
    }

    /**
     * blocked_domains 입력을 정규화합니다.
     *
     * 카테고리별 값이 string 이면 줄바꿈으로 split → trim → 빈 항목 제거.
     * 이미 array 면 그대로 보존. cookie_categories 의 키 (necessary 제외) 가
     * blocked_domains 에 없으면 빈 배열로 보충하여 UI iteration·검증 실패를 방지합니다.
     *
     * @param  mixed  $input  blocked_domains 입력값
     * @param  array  $cookieCategories  cookie_categories 정규화된 배열
     * @return array<string, array<int, string>>|null
     */
    private function normalizeBlockedDomains(mixed $input, array $cookieCategories): ?array
    {
        if ($input === null) {
            return null;
        }

        if (! is_array($input)) {
            return null;
        }

        $normalized = [];
        foreach ($input as $category => $value) {
            if (is_string($value)) {
                $normalized[$category] = collect(preg_split('/\R/', $value))
                    ->map(fn ($line) => is_string($line) ? trim($line) : '')
                    ->filter(fn ($line) => $line !== '')
                    ->values()
                    ->all();
            } elseif (is_array($value)) {
                $normalized[$category] = array_values(array_filter(
                    array_map(fn ($line) => is_string($line) ? trim($line) : '', $value),
                    fn ($line) => $line !== '',
                ));
            } else {
                $normalized[$category] = [];
            }
        }

        // cookie_categories 의 키 (necessary 제외) 가 blocked_domains 에 없으면 빈 배열로 보충
        foreach ($cookieCategories as $category) {
            $key = is_array($category) ? ($category['key'] ?? null) : null;
            if (! is_string($key) || $key === '' || $key === 'necessary') {
                continue;
            }
            if (! array_key_exists($key, $normalized)) {
                $normalized[$key] = [];
            }
        }

        return $normalized;
    }

    /**
     * 검증 후 cookie_categories 내 key 중복 차단.
     *
     * @param  \Illuminate\Validation\Validator  $validator
     * @return void
     */
    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function (\Illuminate\Validation\Validator $validator) {
            $categories = (array) $this->input('cookie_categories', []);
            $keys = array_column($categories, 'key');
            if (count($keys) !== count(array_unique($keys))) {
                $validator->errors()->add(
                    'cookie_categories',
                    __('sirsoft-gdpr::messages.consent.invalid_key')
                );
            }

        });
    }

    /**
     * JSON 문자열 또는 배열을 array 로 정규화합니다.
     *
     * @param  mixed  $value  입력 값
     * @return array|null
     */
    private function normalizeJsonArray(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            return $value;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }
}
