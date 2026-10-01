<?php

namespace Plugins\Sirsoft\Gdpr\Http\Requests;

use App\Rules\TranslatableField;
use App\Services\PluginSettingsService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Plugins\Sirsoft\Gdpr\Plugin;
use Plugins\Sirsoft\Gdpr\Rules\PrivacyPolicyUrlRule;
use Plugins\Sirsoft\Gdpr\Support\PrivacyPolicyUrl;

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
     * 필수 저장 항목(저장소 키 / 쿠키 이름) 정규식 — 확장 제공 키 수용과 같은 규칙 (Plugin 이 단일 출처)
     */
    private const STORAGE_KEY_REGEX = Plugin::NECESSARY_STORAGE_KEY_PATTERN;

    /**
     * 필수 저장 항목 허용목록의 스코프 화이트리스트
     *
     * blocked_domains 는 카테고리 키를 검사하지 않고 남긴 빈틈이 있는데, 여기서는 남기지
     * 않는다 — 스코프가 오타나면 그 항목은 어떤 판정에도 쓰이지 않은 채 저장되고,
     * 운영자에게는 "등록했는데 안 되는" 상태로만 보인다.
     *
     * @var array<int, string>
     */
    private const ALLOWLIST_SCOPES = ['localStorage', 'sessionStorage', 'cookie'];

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
            // 자동 통지가 꺼져 있으면 하위 항목은 운영에 쓰이지 않으므로 강제하지 않는다.
            // 화면도 같은 조건으로 입력을 비활성화한다 — 끄기만 했는데 "상한은 필수" 로 막히면
            // 운영자는 끌 수가 없다. 켠 상태에서는 종전 그대로 채널 1개 이상·상한 범위를 강제한다.
            'annual_notice' => ['sometimes', 'array:enabled,channels,batch_size,introduction'],
            'annual_notice.enabled' => ['required_with:annual_notice', 'boolean'],
            'annual_notice.channels' => $this->annualNoticeEnabled()
                ? ['required_with:annual_notice', 'array', 'min:1', 'max:2']
                : ['sometimes', 'array', 'max:2'],
            'annual_notice.channels.*' => ['required', 'string', 'distinct', 'in:mail,database'],
            'annual_notice.batch_size' => $this->annualNoticeEnabled()
                ? ['required_with:annual_notice', 'integer', 'min:'.Plugin::ANNUAL_NOTICE_MIN, 'max:'.Plugin::ANNUAL_NOTICE_MAX]
                : ['sometimes', 'nullable', 'integer', 'min:'.Plugin::ANNUAL_NOTICE_MIN, 'max:'.Plugin::ANNUAL_NOTICE_MAX],
            // 머리말은 저장된 설정이다 — 언어팩을 끄면 그 언어의 머리말이 남은 채로 화면이 폼 전체를
            // 다시 보낸다. 지원 목록 밖 키를 거절(strict)하면 통지가 꺼져 입력이 잠긴 상태에서는 그 칸을
            // 지울 수도 없어 어떤 설정도 저장하지 못한다. 코어 기본(비엄격)대로 통과시켜 값을 보존하고
            // (언어팩을 다시 켜면 그대로 복구), 길이 상한은 아래 `.*` 규칙이 모든 키에 건다.
            'annual_notice.introduction' => ['sometimes', new TranslatableField(maxLength: 2000)],
            'annual_notice.introduction.*' => ['nullable', 'string', 'max:2000'],
            // 정책 메타데이터 — cookie_policy_version 은 gdpr_policy_versions 테이블이 SSoT.
            // 운영자가 직접 입력하지 않으므로 본 FormRequest 에서 검증하지 않음 (전달되어도 stripped).
            // 처리방침 주소 — 운영자가 직접 입력한다. `/` 로 시작하는 사이트 내부 경로 또는 https 절대
            // 주소만 받는다(다른 스킴·프로토콜 상대 주소·백슬래시·공백 거부). 판정 SSoT 는 PrivacyPolicyUrl.
            // 1.0.x 의 privacy_policy_slug 는 규칙에 없으므로 보내도 validated() 에서 배제된다.
            'privacy_policy_url' => ['nullable', 'string', 'max:'.PrivacyPolicyUrl::MAX_LENGTH, new PrivacyPolicyUrlRule],
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

            // 필수 저장 항목 허용목록 — 스코프(localStorage/sessionStorage/cookie) 별 문자열 배열.
            // 스코프 키 화이트리스트는 withValidator() 에서 검사한다 (규칙 문법으로는 키를 못 건다).
            // necessary_storage_locked 는 규칙에 넣지 않는다 → validated() 에서 자동 배제되어
            // 운영자가 그 키를 보내도 저장되지 않는다 (잠금 항목은 코드가 정한다).
            'necessary_storage_allowlist' => ['nullable', 'array'],
            'necessary_storage_allowlist.*' => ['array'],
            'necessary_storage_allowlist.*.*' => ['string', 'max:128', 'regex:'.self::STORAGE_KEY_REGEX],
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
            // 스코프별 메시지 — 운영자가 어느 카드의 어느 항목이 잘못됐는지 즉시 식별 가능.
            'necessary_storage_allowlist.localStorage.*.regex' => __('sirsoft-gdpr::messages.necessary_storage_allowlist.invalid_format_local_storage'),
            'necessary_storage_allowlist.localStorage.*.max' => __('sirsoft-gdpr::messages.necessary_storage_allowlist.too_long_local_storage'),
            'necessary_storage_allowlist.sessionStorage.*.regex' => __('sirsoft-gdpr::messages.necessary_storage_allowlist.invalid_format_session_storage'),
            'necessary_storage_allowlist.sessionStorage.*.max' => __('sirsoft-gdpr::messages.necessary_storage_allowlist.too_long_session_storage'),
            'necessary_storage_allowlist.cookie.*.regex' => __('sirsoft-gdpr::messages.necessary_storage_allowlist.invalid_format_cookie'),
            'necessary_storage_allowlist.cookie.*.max' => __('sirsoft-gdpr::messages.necessary_storage_allowlist.too_long_cookie'),
            'necessary_storage_allowlist.*.array' => __('sirsoft-gdpr::messages.necessary_storage_allowlist.must_be_array'),
        ];
    }

    /**
     * 검증 메시지의 :attribute placeholder 를 사용자 친화 한국어/영어 라벨로 치환.
     *
     * Laravel 기본 :attribute 는 underscore 만 공백으로 치환한 영문 표현 (예: "privacy policy url")
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
            // 연간 통지 — 라벨이 없으면 "annual notice.batch size 필드는 …" 처럼 키가 그대로 노출된다.
            'annual_notice' => __('sirsoft-gdpr::messages.settings.fields.annual_notice.label'),
            'annual_notice.enabled' => __('sirsoft-gdpr::messages.settings.fields.annual_notice_enabled.label'),
            'annual_notice.channels' => __('sirsoft-gdpr::messages.settings.fields.annual_notice_channels.label'),
            'annual_notice.channels.*' => __('sirsoft-gdpr::messages.settings.fields.annual_notice_channels.label'),
            'annual_notice.batch_size' => __('sirsoft-gdpr::messages.settings.fields.annual_notice_batch_size.label'),
            'annual_notice.introduction' => __('sirsoft-gdpr::messages.settings.fields.annual_notice_introduction.label'),
            'annual_notice.introduction.*' => __('sirsoft-gdpr::messages.settings.fields.annual_notice_introduction.label'),
            'privacy_policy_url' => __('sirsoft-gdpr::messages.settings.fields.privacy_policy_url.label'),
            'legal_entity_name' => __('sirsoft-gdpr::messages.settings.fields.legal_entity_name.label'),
            'data_storage_location' => __('sirsoft-gdpr::messages.settings.fields.data_storage_location.label'),
            'banner_enabled' => __('sirsoft-gdpr::messages.settings.fields.banner_enabled.label'),
            'banner_position' => __('sirsoft-gdpr::messages.settings.fields.banner_position.label'),
            'cookie_categories' => __('sirsoft-gdpr::messages.settings.section.cookie_categories'),
            'blocked_domains' => __('sirsoft-gdpr::messages.settings.section.auto_blocking'),
            'necessary_storage_allowlist' => __('sirsoft-gdpr::messages.settings.section.necessary_storage'),
            // 배열 항목 — 커스텀 메시지가 없는 규칙(string·required_with 등)에서 인덱스가 붙은
            // 원시 키(cookie_categories.0.label.ko)가 그대로 노출되던 자리다.
            'cookie_categories.*.key' => __('sirsoft-gdpr::messages.settings.attributes.cookie_category_key'),
            'cookie_categories.*.label' => __('sirsoft-gdpr::messages.settings.attributes.cookie_category_label'),
            'cookie_categories.*.label.ko' => __('sirsoft-gdpr::messages.settings.attributes.cookie_category_label'),
            'cookie_categories.*.label.en' => __('sirsoft-gdpr::messages.settings.attributes.cookie_category_label'),
            'cookie_categories.*.description' => __('sirsoft-gdpr::messages.settings.attributes.cookie_category_description'),
            'blocked_domains.*' => __('sirsoft-gdpr::messages.settings.section.auto_blocking'),
            'blocked_domains.*.*' => __('sirsoft-gdpr::messages.settings.attributes.blocked_domain'),
            'necessary_storage_allowlist.*' => __('sirsoft-gdpr::messages.settings.section.necessary_storage'),
            'necessary_storage_allowlist.*.*' => __('sirsoft-gdpr::messages.settings.attributes.necessary_storage_item'),
        ];
    }

    /**
     * 자동 통지 사용 여부(느슨한 참 판정).
     *
     * 저장값·전송값이 boolean 이 아닐 수 있어(문자열 '0'/'1', 체크박스 'on') 엄격 비교를 쓰지
     * 않는다 — 코어 보안 탭의 two_factor_scope 조건식과 같은 이유다. 키를 아예 보내지 않은
     * 요청은 하위 항목도 함께 안 보내므로(부분 저장) 꺼진 것으로 보고 강제하지 않는다.
     *
     * @return bool 자동 통지가 켜져 있으면 true
     */
    private function annualNoticeEnabled(): bool
    {
        return filter_var($this->input('annual_notice.enabled'), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * 자동 통지가 꺼진 상태에서 비워져 들어온 하위 항목을 기존 저장값으로 복원합니다.
     *
     * 화면이 입력을 비활성화하면 빈 값이 올 수 있는데, 그대로 저장하면 운영자가 토글만
     * 껐는데 상한·채널 설정이 사라진다. 채널이 비면 시험 발송까지 막히므로(저장된 채널을
     * 그대로 검증한다) 상한과 같은 규칙으로 되돌린다. 켠 상태의 빈 값은 복원하지 않는다 —
     * 그건 운영자가 지운 것이므로 검증이 422 로 알려야 한다.
     *
     * @return void 반환값 없음
     */
    private function normalizeAnnualNotice(): void
    {
        if (! is_array($this->input('annual_notice')) || $this->annualNoticeEnabled()) {
            return;
        }

        $settings = app(PluginSettingsService::class);
        $annualNotice = $this->input('annual_notice');
        $batchSize = $this->input('annual_notice.batch_size');
        $channels = $this->input('annual_notice.channels');

        if ($batchSize === null || $batchSize === '') {
            $annualNotice['batch_size'] = $settings->get('sirsoft-gdpr', 'annual_notice.batch_size', Plugin::ANNUAL_NOTICE_DEFAULT);
        }

        if ($channels === null || $channels === [] || $channels === '') {
            $annualNotice['channels'] = $settings->get('sirsoft-gdpr', 'annual_notice.channels', Plugin::ANNUAL_NOTICE_CHANNELS);
        }

        $this->merge(['annual_notice' => $annualNotice]);
    }

    /**
     * 사전 정규화:
     * 1. cookie_categories 가 JSON 문자열로 들어와도 array 로 변환
     * 2. blocked_domains 의 카테고리별 값이 textarea 줄바꿈 문자열이면 배열로 변환
     *    (한 줄당 도메인 하나, 빈 줄 제거, trim)
     * 3. cookie_categories 의 키 중 necessary 제외한 모든 키가 blocked_domains 에
     *    존재하도록 빈 배열로 자동 보충 (운영자가 새 카테고리 추가 시 UI iteration
     *    미렌더·검증 실패 방지)
     * 4. necessary_storage_allowlist 도 같은 방식으로 정규화 (줄바꿈 문자열 분해 · trim ·
     *    빈 항목 제거 · 세 스코프 빈 배열 보충)
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        $this->normalizeAnnualNotice();

        $cookieCategories = $this->normalizeJsonArray($this->input('cookie_categories'));

        $blockedDomains = $this->normalizeBlockedDomains(
            $this->input('blocked_domains'),
            is_array($cookieCategories) ? $cookieCategories : [],
        );

        $merge = ['cookie_categories' => $cookieCategories];
        if ($blockedDomains !== null) {
            $merge['blocked_domains'] = $blockedDomains;
        }

        $necessaryAllowlist = $this->normalizeNecessaryAllowlist($this->input('necessary_storage_allowlist'));
        if ($necessaryAllowlist !== null) {
            $merge['necessary_storage_allowlist'] = $necessaryAllowlist;
        }

        $this->merge($merge);
    }

    /**
     * necessary_storage_allowlist 입력을 정규화합니다.
     *
     * 스코프별 값이 string 이면 줄바꿈으로 split → trim → 빈 항목 제거. 이미 array 면 항목만
     * trim 합니다. 세 스코프 중 없는 것은 빈 배열로 보충해 UI 가 항상 세 카드를 그리도록
     * 합니다. **키를 아예 보내지 않았으면 null 을 돌려** 기존 저장값을 보존합니다 — 빈 배열로
     * 보충해 버리면 이 화면을 모르는 클라이언트의 저장 한 번이 허용목록을 통째로 비운다.
     *
     * 스코프 키 자체는 여기서 거르지 않습니다 — 오타 스코프를 조용히 버리면 운영자에게는
     * "저장했는데 사라지는" 상태가 되므로, withValidator() 가 422 로 알립니다.
     *
     * @param  mixed  $input  necessary_storage_allowlist 입력값
     * @return array<string, array<int, string>>|null
     */
    private function normalizeNecessaryAllowlist(mixed $input): ?array
    {
        if (! is_array($input)) {
            return null;
        }

        $normalized = [];
        foreach ($input as $scope => $value) {
            if (is_string($value)) {
                $normalized[$scope] = collect(preg_split('/\R/', $value))
                    ->map(fn ($line) => is_string($line) ? trim($line) : '')
                    ->filter(fn ($line) => $line !== '')
                    ->values()
                    ->all();
            } elseif (is_array($value)) {
                $normalized[$scope] = array_values(array_filter(
                    array_map(fn ($line) => is_string($line) ? trim($line) : '', $value),
                    fn ($line) => $line !== '',
                ));
            } else {
                $normalized[$scope] = [];
            }
        }

        foreach (self::ALLOWLIST_SCOPES as $scope) {
            if (! array_key_exists($scope, $normalized)) {
                $normalized[$scope] = [];
            }
        }

        return $normalized;
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
     * 검증 후 추가 검사:
     * 1. cookie_categories 내 key 중복 차단
     * 2. necessary_storage_allowlist 의 스코프 키가 화이트리스트에 있는지 검사
     *
     * @param  Validator  $validator
     * @return void
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $categories = (array) $this->input('cookie_categories', []);
            $keys = array_column($categories, 'key');
            if (count($keys) !== count(array_unique($keys))) {
                $validator->errors()->add(
                    'cookie_categories',
                    __('sirsoft-gdpr::messages.consent.invalid_key')
                );
            }

            $allowlist = $this->input('necessary_storage_allowlist');
            if (is_array($allowlist)) {
                foreach (array_keys($allowlist) as $scope) {
                    if (in_array($scope, self::ALLOWLIST_SCOPES, true)) {
                        continue;
                    }

                    $validator->errors()->add(
                        'necessary_storage_allowlist.'.$scope,
                        __('sirsoft-gdpr::messages.necessary_storage_allowlist.invalid_scope', [
                            'scope' => is_scalar($scope) ? (string) $scope : '',
                        ])
                    );
                }
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
