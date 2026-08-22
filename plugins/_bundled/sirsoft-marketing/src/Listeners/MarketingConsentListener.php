<?php

namespace Plugins\Sirsoft\Marketing\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Models\User;
use App\Services\PluginSettingsService;
use Plugins\Sirsoft\Marketing\Models\MarketingConsent;
use Plugins\Sirsoft\Marketing\Services\MarketingConsentService;

/**
 * 마케팅 동의 훅 리스너
 *
 * 사용자 생성/수정/조회 시 마케팅 동의 데이터를 처리합니다.
 */
class MarketingConsentListener implements HookListenerInterface
{
    /**
     * 회원가입 폼 필드 접두사 (agree_ + consent_key)
     */
    private const REGISTER_FIELD_PREFIX = 'agree_';

    /**
     * 플러그인 식별자
     */
    private const PLUGIN_ID = 'sirsoft-marketing';

    /**
     * MarketingConsentListener 생성자
     *
     * @param MarketingConsentService $service 마케팅 동의 서비스
     * @param PluginSettingsService $pluginSettings 플러그인 설정 서비스
     */
    public function __construct(
        private MarketingConsentService $service,
        private PluginSettingsService $pluginSettings
    ) {}

    /**
     * 구독할 훅 목록 반환
     *
     * @return array
     */
    public static function getSubscribedHooks(): array
    {
        return [
            // Filter 훅: FormRequest validation rules 확장
            'core.auth.register_validation_rules' => [
                'method' => 'addRegisterValidationRules',
                'priority' => 10,
                'type' => 'filter',
            ],
            'core.user.create_validation_rules' => [
                'method' => 'addValidationRules',
                'priority' => 10,
                'type' => 'filter',
            ],
            'core.user.update_validation_rules' => [
                'method' => 'addValidationRules',
                'priority' => 10,
                'type' => 'filter',
            ],
            'core.user.update_profile_validation_rules' => [
                'method' => 'addValidationRules',
                'priority' => 10,
                'type' => 'filter',
            ],

            // Filter 훅: 요청 데이터에서 마케팅 동의 필드 분리 (DB 저장 없음)
            'core.user.filter_update_data' => [
                'method' => 'filterUpdateData',
                'priority' => 10,
                'type' => 'filter',
            ],

            // Action 훅: 생성 후 마케팅 동의 저장 ($originalData 직접 사용)
            'core.user.after_create' => [
                'method' => 'afterCreate',
                'priority' => 10,
            ],

            // Action 훅: 수정 후 마케팅 동의 저장 (filter_update_data에서 분리)
            'core.user.after_update' => [
                'method' => 'afterUpdate',
                'priority' => 10,
            ],

            // Action 훅: 회원가입 후 마케팅 동의 저장
            // (코어에서 정의된 훅명 그대로 사용 — 타이밍 접미사 없는 것이 코어 규칙)
            'core.auth.register' => [
                'method' => 'afterRegister',
                'priority' => 10,
            ],

            // Action 훅: 사용자 삭제 전 마케팅 동의 데이터 명시적 삭제
            'core.user.before_delete' => [
                'method' => 'beforeDelete',
                'priority' => 10,
            ],

            // Filter 훅: API 응답에 마케팅 동의 데이터 병합
            'core.user.filter_resource_data' => [
                'method' => 'filterResourceData',
                'priority' => 10,
                'type' => 'filter',
            ],

            // Filter 훅: 설정 저장 시 channels 배열을 JSON 문자열로 변환
            'core.plugin_settings.filter_save_data' => [
                'method' => 'normalizeChannelsSaveData',
                'priority' => 10,
                'type' => 'filter',
            ],
        ];
    }

    /**
     * 기본 훅 핸들러 (HookListenerInterface 필수 메서드)
     *
     * getSubscribedHooks()에서 개별 메서드를 지정하므로,
     * 이 메서드는 호출되지 않지만 인터페이스 준수를 위해 구현
     *
     * @param mixed ...$args 훅 인자
     * @return void
     */
    public function handle(...$args): void
    {
        // 개별 메서드에서 처리하므로 빈 구현
    }

    /**
     * 회원가입 FormRequest validation rules에 마케팅 동의 필드 추가
     *
     * 등록된 채널(동적) + 법적 필수 항목 + 마스터 키를 모두 추가합니다.
     *
     * @param array $rules 기존 validation rules
     * @return array 마케팅 동의 필드가 추가된 rules
     */
    public function addRegisterValidationRules(array $rules): array
    {
        foreach ($this->getAllConsentKeys() as $key) {
            $rules[self::REGISTER_FIELD_PREFIX.$key] = 'nullable|boolean';
        }

        return $rules;
    }

    /**
     * 관리자/마이페이지 FormRequest validation rules에 마케팅 동의 필드 추가
     *
     * 등록된 채널(동적) + 법적 필수 항목 + 마스터 키를 모두 추가합니다.
     *
     * @param array $rules 기존 validation rules
     * @return array 마케팅 동의 필드가 추가된 rules
     */
    public function addValidationRules(array $rules): array
    {
        foreach ($this->getAllConsentKeys() as $key) {
            $rules[$key] = 'nullable|boolean';
        }

        return $rules;
    }

    /**
     * 수정 데이터 필터: 마케팅 동의 필드 추출 후 반환 (DB 저장 없음)
     *
     * filter_update_data는 Filter 훅이므로 DB 저장 등 부작용 금지.
     * 실제 저장은 core.user.after_update 액션 훅(afterUpdate)에서 처리합니다.
     *
     * @param array $data 요청 데이터
     * @param User $user 수정 대상 사용자
     * @return array 마케팅 필드가 제거된 데이터
     */
    public function filterUpdateData(array $data, User $user): array
    {
        return $this->removeMarketingFields($data);
    }

    /**
     * 생성 후 액션: $originalData에서 마케팅 동의 필드 추출하여 저장
     *
     * @param User $user 생성된 사용자
     * @param array $originalData 원본 요청 데이터
     * @return void
     */
    public function afterCreate(User $user, array $originalData): void
    {
        $marketingData = $this->extractMarketingData($originalData);

        if (! empty($marketingData)) {
            $this->service->updateConsents($user->id, $marketingData, 'admin');
        }
    }

    /**
     * 수정 후 액션: $originalData에서 마케팅 동의 필드 추출하여 저장
     *
     * filter_update_data에서 분리된 부작용(DB 저장)을 처리합니다.
     *
     * @param User $user 수정된 사용자
     * @param array $originalData 원본 요청 데이터
     * @return void
     */
    public function afterUpdate(User $user, array $originalData): void
    {
        $marketingData = $this->extractMarketingData($originalData);

        if (empty($marketingData)) {
            return;
        }

        $source = $this->detectSource();

        $this->service->updateConsents($user->id, $marketingData, $source);
    }

    /**
     * 회원가입 후 액션: request()에서 마케팅 동의 필드 추출하여 저장
     *
     * AuthService.register()에는 filter_create_data 훅이 없으므로,
     * core.auth.register 액션 훅에서 request()로 직접 추출합니다.
     *
     * @param User $user 가입된 사용자
     * @param array $context 회원가입 컨텍스트 정보
     * @return void
     */
    public function afterRegister(User $user, array $context): void
    {
        $request = request();
        $marketingData = [];

        foreach ($this->getAllConsentKeys() as $key) {
            $registerField = self::REGISTER_FIELD_PREFIX.$key;
            if ($request->has($registerField)) {
                $marketingData[$key] = ! empty($request->input($registerField));
            }
        }

        if (! empty($marketingData)) {
            $this->service->updateConsents($user->id, $marketingData, 'register');
        }
    }

    /**
     * 사용자 삭제 전 액션: 마케팅 동의 데이터 명시적 삭제
     *
     * DB CASCADE에 의존하지 않고 명시적으로 삭제합니다.
     *
     * @param User $user 삭제될 사용자
     * @return void
     */
    public function beforeDelete(User $user): void
    {
        $this->service->deleteByUserId($user->id);
    }

    /**
     * 리소스 데이터 필터: User API 응답에 마케팅 동의 데이터 병합
     *
     * EAV 구조 — consent_key 기준으로 Collection을 키 인덱스하여 O(1) 조회합니다.
     * 활성화된 키 목록과 *_enabled 값을 포함하여 프론트엔드 조건부 렌더링에 사용합니다.
     *
     * @param array $data API 응답 데이터
     * @param User $user 조회 대상 사용자
     * @return array 마케팅 동의 데이터가 병합된 API 응답
     */
    public function filterResourceData(array $data, User $user): array
    {
        $consents = $this->service->getAllByUserId($user->id)->keyBy('consent_key');
        $activeChannelKeys = $this->service->getActiveChannelKeys();
        $enabledLegalKeys = $this->service->getEnabledLegalKeys();
        $allActiveKeys = array_unique(array_merge(
            $activeChannelKeys,
            [MarketingConsent::MASTER_KEY],
            $enabledLegalKeys
        ));

        // 동의 상태 병합
        foreach ($allActiveKeys as $key) {
            $record = $consents->get($key);
            $data[$key] = $record?->is_consented ?? false;
            $data["{$key}_at"] = $record?->consented_at?->toIso8601String();
        }

        // 프론트엔드 조건부 렌더링용 플래그 — 마케팅 전체 동의
        $marketingSlug = $this->pluginSettings->get(self::PLUGIN_ID, 'marketing_consent_terms_slug', '');
        $data['marketing_consent_enabled']        = (bool) $this->pluginSettings->get(self::PLUGIN_ID, 'marketing_consent_enabled', true);
        $data['marketing_consent_terms_slug']     = $marketingSlug ?: null;
        $data['marketing_consent_terms_slug_set'] = ! empty($marketingSlug);

        // 프론트엔드 조건부 렌더링용 플래그 — 법적 동의 항목
        $thirdPartySlug = $this->pluginSettings->get(self::PLUGIN_ID, 'third_party_consent_terms_slug', '');
        $data['third_party_consent_enabled']        = (bool) $this->pluginSettings->get(self::PLUGIN_ID, 'third_party_consent_enabled');
        $data['third_party_consent_terms_slug']     = $thirdPartySlug ?: null;
        $data['third_party_consent_terms_slug_set'] = ! empty($thirdPartySlug);

        $infoSlug = $this->pluginSettings->get(self::PLUGIN_ID, 'info_disclosure_terms_slug', '');
        $data['info_disclosure_enabled']        = (bool) $this->pluginSettings->get(self::PLUGIN_ID, 'info_disclosure_enabled');
        $data['info_disclosure_terms_slug']     = $infoSlug ?: null;
        $data['info_disclosure_terms_slug_set'] = ! empty($infoSlug);

        // 프론트엔드 조건부 렌더링용 플래그 — 채널별 (channels JSON 기반 동적 처리)
        $locale = app()->getLocale();
        $channelsMeta = [];
        foreach ($this->service->getAllChannels() as $channel) {
            $key  = $channel['key'];
            $slug = $channel['page_slug'] ?? '';
            $data["{$key}_enabled"]        = (bool) ($channel['enabled'] ?? true);
            $data["{$key}_terms_slug"]     = $slug ?: null;
            $data["{$key}_terms_slug_set"] = ! empty($slug);

            $channelsMeta[] = [
                'key'            => $key,
                'label'          => $channel['label'][$locale] ?? $channel['label']['ko'] ?? $key,
                'enabled'        => (bool) ($channel['enabled'] ?? true),
                'terms_slug'     => $slug ?: null,
                'terms_slug_set' => ! empty($slug),
            ];
        }

        // 채널 목록 배열 — 프론트엔드 iteration용
        $data['channels'] = $channelsMeta;

        // 동의 이력 추가
        $data['consent_histories'] = $this->service->getHistories($user->id)
            ->map(fn ($history) => [
                'channel_key' => $history->channel_key,
                'action' => $history->action,
                'source' => $history->source,
                'created_at' => $history->created_at?->format('Y-m-d H:i:s'),
            ])
            ->toArray();

        return $data;
    }

    /**
     * 모든 동의 키 목록을 반환합니다.
     *
     * 채널 키(동적) + 마스터 키 + 법적 필수 항목 키를 포함합니다.
     *
     * @return array<int, string>
     */
    private function getAllConsentKeys(): array
    {
        return array_unique(array_merge(
            $this->service->getActiveChannelKeys(),
            [MarketingConsent::MASTER_KEY],
            $this->service->getEnabledLegalKeys()
        ));
    }

    /**
     * 요청 데이터에서 마케팅 동의 필드만 추출합니다.
     *
     * @param array $data 요청 데이터
     * @return array 마케팅 동의 데이터
     */
    private function extractMarketingData(array $data): array
    {
        $keys = $this->getAllConsentKeys();

        return array_filter(
            array_intersect_key($data, array_flip($keys)),
            fn ($v) => $v !== null
        );
    }

    /**
     * 요청 데이터에서 마케팅 동의 필드를 제거합니다.
     *
     * @param array $data 요청 데이터
     * @return array 마케팅 필드가 제거된 데이터
     */
    private function removeMarketingFields(array $data): array
    {
        return array_diff_key($data, array_flip($this->getAllConsentKeys()));
    }

    /**
     * 설정 저장 시 channels 배열을 JSON 문자열로 변환합니다.
     *
     * PluginSettingsService::save()에서 호출되며, sirsoft-marketing 플러그인의
     * channels 값이 배열로 전달된 경우 JSON 문자열로 직렬화합니다.
     * 이렇게 해야 getAllChannels()에서 json_decode()로 올바르게 읽을 수 있습니다.
     *
     * @param array $settings 저장할 설정 데이터
     * @param string $identifier 플러그인 식별자
     * @return array channels가 JSON 문자열로 변환된 설정 데이터
     */
    public function normalizeChannelsSaveData(array $settings, string $identifier): array
    {
        if ($identifier !== self::PLUGIN_ID) {
            return $settings;
        }

        if (isset($settings['channels']) && is_array($settings['channels'])) {
            $settings['channels'] = json_encode($settings['channels'], JSON_UNESCAPED_UNICODE);
        }

        return $settings;
    }

    /**
     * 현재 요청 경로에서 변경 출처를 판별합니다.
     *
     * @return string 변경 경로 (admin/profile)
     */
    private function detectSource(): string
    {
        return request()->routeIs('api.admin.*') ? 'admin' : 'profile';
    }
}
