<?php

namespace Plugins\Sirsoft\VerificationNhnkcp\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Illuminate\Support\Facades\Lang;
use Plugins\Sirsoft\VerificationNhnkcp\Identity\KcpIdentityProvider;

/**
 * KCP 본인확인 설정 저장 시 라이브 모드 조건부 검증을 주입하는 filter 훅 listener.
 *
 * 코어 UpdatePluginSettingsRequest 의 정적 스키마는 `required`/타입 규칙만 표현할 수 있어
 * "is_test_mode=false 일 때만 live_site_cd / live_enc_key 필수" 같은 조건부 검증을 담을 수 없다.
 * 본 listener 가 `core.plugin_settings.update_validation_rules` filter 로 라이브 모드 진입 시 라이브
 * 자격증명에 required 규칙을 동적 부여한다.
 *
 * 검증 기준은 KcpIdentityProvider::isAvailable() 의 라이브 운영 가능 조건과 일치시킨다.
 * 사이트코드의 SM 프리픽스는 buildLiveSiteCd() 가 자동 부착하므로 형식 검증을 두지 않는다.
 *
 * @since 1.0.0
 */
class ValidateKcpSettingsListener implements HookListenerInterface
{
    /** 본 플러그인 식별자 */
    private const IDENTIFIER = 'sirsoft-verification_nhnkcp';

    /**
     * 구독 훅 메타데이터.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.plugin_settings.update_validation_rules' => [
                'method' => 'addLiveModeRules',
                'priority' => 10,
                'type' => 'filter',
                'sync' => true,
            ],
            'core.plugin_settings.filter_save_data' => [
                'method' => 'normalizeLiveSiteCd',
                'priority' => 10,
                'type' => 'filter',
                'sync' => true,
            ],
        ];
    }

    /**
     * 인터페이스 표준 진입점 — getSubscribedHooks 가 method='addLiveModeRules' 를 명시하므로 미사용.
     *
     * @param  mixed  ...$args  사용 안 함
     */
    public function handle(...$args): void
    {
        // no-op — 실제 진입점은 addLiveModeRules() 메서드 (filter 훅)
    }

    /**
     * 라이브 모드 진입 시 라이브 자격증명에 required 규칙을 부여한다.
     *
     * @param  array<string, mixed>  $rules  코어가 스키마로 생성한 검증 규칙
     * @param  string  $identifier  검증 대상 플러그인 식별자
     * @param  array<string, mixed>  $input  이번 요청의 입력값 (코어가 전달)
     * @return array<string, mixed> 조정된 검증 규칙
     */
    public function addLiveModeRules(array $rules, string $identifier, array $input = []): array
    {
        if ($identifier !== self::IDENTIFIER) {
            return $rules;
        }

        // is_test_mode 가 명시적으로 false 일 때만 라이브 모드로 간주한다.
        // 입력값은 코어 UpdatePluginSettingsRequest 가 본 필터의 3번째 인자로 넘겨준다 —
        // "현재 입력한 모드에 따라 필수 항목이 달라지는" 조건부 규칙을 만들기 위한 값이며,
        // 확장이 request() 를 직접 들여다볼 필요가 없다.
        $isTestMode = filter_var(
            $input['is_test_mode'] ?? true,
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE
        );

        if ($isTestMode !== false) {
            return $rules;
        }

        // 라이브 모드 검증이 확정된 이 시점(HTTP 요청 처리 중)에 현재 로케일로 항목 라벨을
        // 등록한다. 요청 처리 시점에는 플러그인 lang 네임스페이스가 준비돼 있어 폴백 없이
        // 정확한 로케일 라벨이 잡힌다.
        $this->registerLiveCredentialAttributeLabels();

        foreach (['live_site_cd', 'live_enc_key'] as $field) {
            $fieldRules = (array) ($rules[$field] ?? []);
            // 코어가 부여한 nullable 을 제거하고 required 로 강제한다.
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
     * 저장 직전 운영 사이트코드에서 SM 프리픽스를 떼어 정규화한다.
     *
     * 설정 화면은 입력칸 왼쪽에 `SM` 배지를 따로 두고 프리픽스를 제외한 값만 받는다. 프리픽스가
     * 포함된 채로 보관되면 화면이 `SM` + `SMA1B2C` 로 그려져 운영자에게는 프리픽스가 두 번 붙은
     * 것처럼 보인다. 보관 형태를 프리픽스 미포함으로 통일해 화면 표시와 일치시킨다.
     *
     * 런타임 부착은 KcpIdentityProvider::buildLiveSiteCd() 가 계속 담당하므로 최종 사이트코드는
     * 정규화 전후가 동일하다.
     *
     * @param  array<string, mixed>  $settings  저장 요청 설정 배열
     * @param  string  $identifier  저장 대상 플러그인 식별자
     * @return array<string, mixed> 사이트코드가 정규화된 설정 배열
     */
    public function normalizeLiveSiteCd(array $settings, string $identifier): array
    {
        if ($identifier !== self::IDENTIFIER || ! isset($settings['live_site_cd'])) {
            return $settings;
        }

        if (! is_string($settings['live_site_cd'])) {
            return $settings;
        }

        $value = trim($settings['live_site_cd']);
        $prefix = KcpIdentityProvider::LIVE_SITE_CD_PREFIX;

        // 프리픽스 판정은 buildLiveSiteCd() 와 같은 기준(대소문자 무시)을 쓴다 — 두 곳이 어긋나면
        // 한쪽은 떼고 다른 쪽은 붙이지 않아 사이트코드가 조용히 달라진다.
        if (strncasecmp($value, $prefix, strlen($prefix)) === 0) {
            $value = substr($value, strlen($prefix));
        }

        $settings['live_site_cd'] = $value;

        return $settings;
    }

    /**
     * 라이브 자격증명 필드의 검증 에러 메시지 항목 라벨을 현재 요청 로케일로 등록한다.
     *
     * 코어 검증 에러 메시지에서 항목 이름이 영문 키(`live site cd`)로 노출되지 않도록 전역
     * validation.attributes 에 표시 이름을 런타임 병합한다. 코어 검증기를 수정하지 않고
     * Laravel 의 attribute 해석 메커니즘만 활용한다.
     */
    private function registerLiveCredentialAttributeLabels(): void
    {
        $locale = app()->getLocale();

        // addLines 가 validation 그룹을 attributes 만 든 빈 껍데기로 조기 캐시하면, 표준
        // Translator 의 isLoaded 가드로 인해 validation.php 전체(required 등)가 로드되지 않는
        // 회귀가 발생한다. 따라서 addLines 전에 validation 그룹을 먼저 로드시킨다.
        Lang::get('validation.required', [], $locale);

        Lang::addLines([
            'validation.attributes.live_site_cd' => __(self::IDENTIFIER.'::messages.settings.live_site_cd_attribute', [], $locale),
            'validation.attributes.live_enc_key' => __(self::IDENTIFIER.'::messages.settings.live_enc_key_attribute', [], $locale),
        ], $locale);
    }
}
