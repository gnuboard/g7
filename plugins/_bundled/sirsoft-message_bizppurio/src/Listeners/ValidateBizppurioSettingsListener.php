<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\MessageBizppurio\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Illuminate\Support\Facades\Lang;

/**
 * 비즈뿌리오 설정 저장 시 운영(live) 환경 조건부 검증을 주입하는 filter 훅 listener.
 *
 * 코어 UpdatePluginSettingsRequest 의 정적 스키마는 `required`/타입 규칙만 표현할 수 있어
 * "검수 모드가 꺼진(운영) 상태일 때만 발송 자격증명 필수" 같은 조건부 검증을 담을 수 없다. 본
 * listener 가 `core.plugin_settings.update_rules` filter 로 운영 진입 시 발송에 필요한
 * 자격증명에 required 규칙을 동적 부여한다.
 *
 * 필수 대상은 "운영 환경에서 최소 문자 발송이 성립하는 조건"과 일치시킨다:
 *  - bizppurio_id, password : 발송 토큰 발급에 필수
 *  - sender_number          : 발송 발신번호로 필수
 * api_key(카카오 관리)·sender_key(알림톡)는 문자 발송의 필수 조건이 아니므로 제외한다.
 *
 * @since 1.0.0
 */
class ValidateBizppurioSettingsListener implements HookListenerInterface
{
    /**
     * 본 플러그인 식별자.
     */
    private const IDENTIFIER = 'sirsoft-message_bizppurio';

    /**
     * 운영 환경에서 required 로 강제할 발송 자격증명 필드.
     *
     * @var array<int, string>
     */
    private const LIVE_REQUIRED_FIELDS = ['bizppurio_id', 'password', 'sender_number'];

    /**
     * 구독 훅 메타데이터.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.plugin_settings.update_rules' => [
                'method' => 'addLiveModeRules',
                'priority' => 10,
                'type' => 'filter',
                'sync' => true,
            ],
        ];
    }

    /**
     * 인터페이스 표준 진입점 — getSubscribedHooks 가 method='addLiveModeRules' 를 명시하므로
     * 이 메서드는 미사용. HookListenerInterface 추상 메서드 충족 목적으로만 정의한다.
     *
     * @param  mixed  ...$args  사용 안 함
     */
    public function handle(...$args): void
    {
        // no-op — 실제 진입점은 addLiveModeRules() 메서드 (filter 훅)
    }

    /**
     * 운영(live) 환경 진입 시 발송 자격증명에 required 규칙을 부여한다.
     *
     * @param  array<string, mixed>  $rules  코어가 스키마로 생성한 검증 규칙
     * @param  string  $identifier  검증 대상 플러그인 식별자
     * @return array<string, mixed> 조정된 검증 규칙
     */
    public function addLiveModeRules(array $rules, string $identifier): array
    {
        if ($identifier !== self::IDENTIFIER) {
            return $rules;
        }

        // 검수 모드(is_test_mode)가 명시적으로 false(운영) 일 때만 자격증명을 강제한다.
        // 본 필터 훅(core.plugin_settings.update_rules)은 코어 UpdatePluginSettingsRequest 가
        // "현재 요청의 is_test_mode 에 따른 조건부 검증 규칙"을 만들도록 발행하는 확장점이므로,
        // 현재 입력 값 참조가 본질적으로 필요하다 (FormRequest 우회가 아님 — 코어 검증기 자체의 입력).
        $isTestMode = filter_var(
            // audit:allow listener-formrequest-bypass reason: 검증 규칙 필터 훅의 의도된 입력 모드 참조
            request()->input('is_test_mode', true),
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE
        );

        if ($isTestMode !== false) {
            return $rules;
        }

        // 운영 모드 검증이 확정된 이 시점(HTTP 요청 처리 중)에 현재 로케일로 항목 라벨을 등록한다.
        // 요청 처리 시점에는 플러그인 lang 네임스페이스가 모두 준비돼 있어 폴백 없이 정확한
        // 로케일 라벨이 잡힌다.
        $this->registerLiveCredentialAttributeLabels();

        foreach (self::LIVE_REQUIRED_FIELDS as $field) {
            $fieldRules = (array) ($rules[$field] ?? []);
            // 코어가 부여한 nullable 을 제거하고 required 로 강제.
            $fieldRules = array_values(array_filter(
                $fieldRules,
                static fn ($rule) => $rule !== 'nullable'
            ));
            if (! in_array('required', $fieldRules, true)) {
                array_unshift($fieldRules, 'required');
            }
            $rules[$field] = $fieldRules;
        }

        return $rules;
    }

    /**
     * 발송 자격증명 필드의 검증 에러 메시지 항목 라벨을 현재 요청 로케일로 등록한다.
     *
     * 코어 UpdatePluginSettingsRequest 의 검증 에러 메시지에서 항목 이름이 영문 키(`bizppurio id`)로
     * 노출되지 않도록, 전역 validation.attributes 에 표시 이름을 런타임 병합한다. 코어 검증기를
     * 수정하지 않고 Laravel 의 attribute 해석 메커니즘만 활용한다.
     */
    private function registerLiveCredentialAttributeLabels(): void
    {
        $locale = app()->getLocale();

        // addLines 가 validation 그룹을 attributes 만 든 빈 껍데기로 조기 캐시하면, 표준 Translator
        // 의 isLoaded 가드로 인해 validation.php 전체(required 등)가 로드되지 않는 회귀가 발생한다.
        // 따라서 addLines 전에 validation 그룹을 먼저 로드시켜 캐시를 정상으로 채운 뒤 attributes 만 보탠다.
        Lang::get('validation.required', [], $locale);

        Lang::addLines([
            'validation.attributes.bizppurio_id' => __(self::IDENTIFIER.'::messages.settings.bizppurio_id_attribute', [], $locale),
            'validation.attributes.password' => __(self::IDENTIFIER.'::messages.settings.password_attribute', [], $locale),
            'validation.attributes.sender_number' => __(self::IDENTIFIER.'::messages.settings.sender_number_attribute', [], $locale),
        ], $locale);
    }
}
