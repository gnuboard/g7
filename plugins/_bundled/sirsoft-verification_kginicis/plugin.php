<?php

namespace Plugins\Sirsoft\VerificationKginicis;

use App\Extension\AbstractPlugin;
use App\Services\SettingsService;
use Plugins\Sirsoft\VerificationKginicis\Listeners\AssertNoDuplicateInicisIdentity;
use Plugins\Sirsoft\VerificationKginicis\Listeners\CleanInicisRecordOnUserDelete;
use Plugins\Sirsoft\VerificationKginicis\Listeners\CleanInicisRecordOnUserWithdraw;
use Plugins\Sirsoft\VerificationKginicis\Listeners\CompleteInicisRecordAfterRegister;
use Plugins\Sirsoft\VerificationKginicis\Listeners\RegisterInicisProviderListener;
use Plugins\Sirsoft\VerificationKginicis\Listeners\ValidateInicisSettingsListener;

/**
 * KG이니시스 본인인증 플러그인
 *
 * G7 코어 IDV 인프라 (>=7.0.0-beta.4) 의 IdentityVerificationInterface 를 구현하여
 * KG이니시스 통합인증 서비스의 본인확인 (reqSvcCd=03) 을 Provider 로 등록한다.
 *
 * @since 1.0.0-beta.1
 */
class Plugin extends AbstractPlugin
{
    /**
     * 플러그인 메타데이터 반환
     *
     * @return array 메타데이터 배열
     */
    public function getMetadata(): array
    {
        return [
            'author' => 'Sirsoft',
            'license' => 'MIT',
            'homepage' => 'https://sir.kr',
            'keywords' => ['verification', 'identity', 'inicis', 'kginicis', 'ipin'],
        ];
    }

    /**
     * 플러그인 의존성 반환
     *
     * @return array 의존성 배열
     */
    public function getDependencies(): array
    {
        return [
            'modules' => [],
            'plugins' => [],
        ];
    }

    /**
     * 플러그인 설정 기본값 반환
     *
     * @return array 기본 설정값
     */
    public function getConfigValues(): array
    {
        return [
            'is_test_mode' => true,
            'test_mid' => 'INIiasTest',
            'test_api_key' => 'TGdxb2l3enJDWFRTbTgvREU3MGYwUT09',
            'live_mid' => '',
            'live_api_key' => '',
            'duplicate_field' => 'di',
            'duplicate_block_enabled' => true,
        ];
    }

    /**
     * 플러그인 설정 스키마 반환.
     *
     * 코어 PluginSettingsController(PUT /api/admin/plugins/{id}/settings)의
     * UpdatePluginSettingsRequest 가 본 스키마로 검증 규칙을 자동 생성하고,
     * PluginSettingsService 가 `sensitive: true` 필드를 저장 시 암호화 / 조회 시 복호화한다.
     *
     * 라이브 모드(is_test_mode=false) 진입 시 live_mid / live_api_key 를 required 로 강제하는
     * 조건부 검증은 본 정적 스키마로 표현할 수 없어 ValidateInicisSettingsListener 가
     * core.plugin_settings.update_rules 필터로 동적 부여한다.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getSettingsSchema(): array
    {
        return [
            'is_test_mode' => [
                'type' => 'boolean',
                'default' => true,
                'label' => ['ko' => '테스트 모드', 'en' => 'Test Mode'],
            ],
            'test_mid' => [
                'type' => 'string',
                'default' => 'INIiasTest',
                'label' => ['ko' => '테스트 MID', 'en' => 'Test MID'],
            ],
            'test_api_key' => [
                'type' => 'string',
                'default' => 'TGdxb2l3enJDWFRTbTgvREU3MGYwUT09',
                'sensitive' => true,
                'label' => ['ko' => '테스트 API 키', 'en' => 'Test API Key'],
            ],
            'live_mid' => [
                'type' => 'string',
                'default' => '',
                'label' => ['ko' => '라이브 MID', 'en' => 'Live MID'],
            ],
            'live_api_key' => [
                'type' => 'string',
                'default' => '',
                'sensitive' => true,
                'label' => ['ko' => '라이브 API 키', 'en' => 'Live API Key'],
            ],
            'duplicate_field' => [
                'type' => 'enum',
                'default' => 'di',
                'options' => ['di', 'ci'],
                'label' => ['ko' => '중복 판정 필드', 'en' => 'Duplicate Field'],
            ],
            'duplicate_block_enabled' => [
                'type' => 'boolean',
                'default' => true,
                'label' => ['ko' => '중복 가입 차단', 'en' => 'Block Duplicate Signup'],
            ],
        ];
    }

    /**
     * 훅 리스너 목록 반환.
     *
     * 단계별 listener:
     *  - Phase C: RegisterInicisProviderListener (Provider 레지스트리 filter)
     *  - Phase D: CompleteInicisRecordAfterRegister (비로그인 가입 PII 흡수)
     *  - Phase F: CleanInicisRecordOnUserWithdraw / CleanInicisRecordOnUserDelete (PII 파기)
     *  - Phase G: AssertNoDuplicateInicisIdentity (가입 직전 동일인 차단)
     *  - 설정 검증: ValidateInicisSettingsListener (라이브 모드 조건부 required filter)
     *
     * @return array<class-string>
     */
    public function getHookListeners(): array
    {
        return [
            RegisterInicisProviderListener::class,
            CompleteInicisRecordAfterRegister::class,
            CleanInicisRecordOnUserWithdraw::class,
            CleanInicisRecordOnUserDelete::class,
            AssertNoDuplicateInicisIdentity::class,
            ValidateInicisSettingsListener::class,
        ];
    }

    /**
     * 플러그인이 제공하는 훅 정보 반환.
     *
     * @return array 훅 정의 배열
     */
    public function getHooks(): array
    {
        return [];
    }

    /**
     * 코어 IDV 인프라에 등록할 커스텀 purpose 목록.
     *
     * 코어 4종 (signup / password_reset / self_update / sensitive_action) 외에
     * 본 plugin 이 추가하는 purpose. label/description 은 운영자 매핑 실수 방어 목적으로
     * "본인확인 provider 만 매핑" 안내를 명시한다.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getIdentityPurposes(): array
    {
        return [
            'inicis.adult_verification' => [
                'label' => 'sirsoft-verification_kginicis::messages.purposes.adult_verification.label',
                'description' => 'sirsoft-verification_kginicis::messages.purposes.adult_verification.description',
                'default_provider' => 'inicis',
                'allowed_channels' => ['ipin'],
            ],
        ];
    }

    /**
     * 코어 IDV 메시지 템플릿 시스템에 등록할 메시지 정의.
     *
     * 이니시스는 SMS 가 이니시스 SDK 자체에서 자동 발송되므로 코어 메시지 템플릿 시스템을
     * 사용하지 않는다. 빈 배열 반환.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getIdentityMessages(): array
    {
        return [];
    }

    /**
     * 플러그인 설치
     *
     * 설치 시 성인인증(inicis.adult_verification) 목적의 프로바이더 매핑이 비어 있으면
     * 이니시스로 1회 세팅한다. 코어 IDV 의 목적별 프로바이더 해석
     * (IdentityVerificationManager::resolveForPurpose) 은 환경설정
     * `settings.identity.purpose_providers.{purpose}` 를 참조하므로, 이 초기값이 있으면
     * 운영자가 별도 설정 없이도 설치 직후부터 성인인증이 이니시스로 분기된다.
     *
     * 이미 값이 지정된 경우(재설치 등)는 덮어쓰지 않아 운영자 선택을 보존한다 — "초기값" 성격.
     *
     * purpose 키('inicis.adult_verification') / provider id('inicis') 는 getIdentityPurposes()
     * 와 동일한 리터럴을 사용한다 — InicisIdentityProvider 상수를 참조하지 않는 이유: install()
     * 은 코어 PluginManager 가 플러그인 PSR-4 오토로드를 등록하기 전에 호출되므로, 이 시점에
     * src/ 클래스(InicisIdentityProvider)는 아직 로드되지 않아 "Class not found" 가 발생한다.
     *
     * @return bool 성공 여부
     */
    public function install(): bool
    {
        $settings = app(SettingsService::class);
        $key = 'identity.purpose_providers.inicis.adult_verification';

        if (! $settings->getSetting($key)) {
            $settings->setSetting($key, 'inicis');
        }

        return true;
    }
}
