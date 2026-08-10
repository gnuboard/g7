<?php

namespace Plugins\Sirsoft\VerificationNhnkcp;

use App\Extension\AbstractPlugin;
use App\Services\SettingsService;
use Plugins\Sirsoft\VerificationNhnkcp\Listeners\AssertNoDuplicateKcpIdentity;
use Plugins\Sirsoft\VerificationNhnkcp\Listeners\CleanKcpRecordOnUserDelete;
use Plugins\Sirsoft\VerificationNhnkcp\Listeners\CleanKcpRecordOnUserWithdraw;
use Plugins\Sirsoft\VerificationNhnkcp\Listeners\CompleteKcpRecordAfterRegister;
use Plugins\Sirsoft\VerificationNhnkcp\Listeners\RegisterKcpProviderListener;
use Plugins\Sirsoft\VerificationNhnkcp\Listeners\ValidateKcpSettingsListener;

/**
 * NHN KCP 휴대폰 본인확인 플러그인.
 *
 * G7 코어 IDV 인프라의 IdentityVerificationInterface 를 구현하여 NHN KCP 의 휴대폰 본인확인
 * (V2 REST) 을 Provider 로 등록한다.
 *
 * @since 1.0.0
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
            'keywords' => ['verification', 'identity', 'kcp', 'nhnkcp', 'phone'],
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
     * 테스트 자격증명은 KCP 가 공개한 공용 테스트 값으로, 설치 직후 별도 입력 없이 테스트
     * 모드에서 본인확인 흐름 전체를 확인할 수 있도록 프리필한다.
     *
     * @return array 기본 설정값
     */
    public function getConfigValues(): array
    {
        return [
            'is_test_mode' => true,
            'test_site_cd' => 'AO7F3',
            'test_enc_key' => 'c2a22fa3ebe4698075bcac6b433d52e351c881b02fb83488d4283a43385b1f8e',
            'live_site_cd' => '',
            'live_enc_key' => '',
            'web_siteid' => '',
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
     * 라이브 모드(is_test_mode=false) 진입 시 live_site_cd / live_enc_key 를 required 로
     * 강제하는 조건부 검증은 정적 스키마로 표현할 수 없어 ValidateKcpSettingsListener 가
     * `core.plugin_settings.update_validation_rules` 필터로 동적 부여한다.
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
            'test_site_cd' => [
                'type' => 'string',
                'default' => 'AO7F3',
                'label' => ['ko' => '테스트 사이트코드', 'en' => 'Test Site Code'],
            ],
            'test_enc_key' => [
                'type' => 'string',
                'default' => 'c2a22fa3ebe4698075bcac6b433d52e351c881b02fb83488d4283a43385b1f8e',
                'sensitive' => true,
                'label' => ['ko' => '테스트 암호화 키', 'en' => 'Test Encryption Key'],
            ],
            'live_site_cd' => [
                'type' => 'string',
                'default' => '',
                'label' => ['ko' => '운영 사이트코드', 'en' => 'Live Site Code'],
            ],
            'live_enc_key' => [
                'type' => 'string',
                'default' => '',
                'sensitive' => true,
                'label' => ['ko' => '운영 암호화 키', 'en' => 'Live Encryption Key'],
            ],
            'web_siteid' => [
                'type' => 'string',
                'default' => '',
                // KCP 거래등록 규격상 12자 — 초과 입력은 저장 시점에 막는다.
                // 규칙이 없으면 실제 인증을 시도하는 순간에야 KCP 거절로 드러난다.
                'max' => 12,
                'label' => ['ko' => '웹사이트 ID', 'en' => 'Web Site ID'],
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
     *  - RegisterKcpProviderListener: Provider 레지스트리 filter
     *  - CompleteKcpRecordAfterRegister: 비로그인 가입 PII 흡수
     *  - CleanKcpRecordOnUserWithdraw / CleanKcpRecordOnUserDelete: PII 파기
     *  - AssertNoDuplicateKcpIdentity: 가입 직전 동일인 차단
     *  - ValidateKcpSettingsListener: 라이브 모드 조건부 required filter
     *
     * @return array<class-string>
     */
    public function getHookListeners(): array
    {
        return [
            RegisterKcpProviderListener::class,
            CompleteKcpRecordAfterRegister::class,
            CleanKcpRecordOnUserWithdraw::class,
            CleanKcpRecordOnUserDelete::class,
            AssertNoDuplicateKcpIdentity::class,
            ValidateKcpSettingsListener::class,
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
     * 코어 4종 (signup / password_reset / self_update / sensitive_action) 외에 본 plugin 이
     * 추가하는 purpose. label/description 은 운영자 매핑 실수 방어 목적으로 "본인확인 provider
     * 만 매핑" 안내를 명시한다.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getIdentityPurposes(): array
    {
        return [
            'nhnkcp.adult_verification' => [
                'label' => 'sirsoft-verification_nhnkcp::messages.purposes.adult_verification.label',
                'description' => 'sirsoft-verification_nhnkcp::messages.purposes.adult_verification.description',
                'default_provider' => 'nhnkcp',
                'allowed_channels' => ['phone'],
            ],
        ];
    }

    /**
     * 코어 IDV 메시지 템플릿 시스템에 등록할 메시지 정의.
     *
     * KCP 는 인증 문자를 KCP 표준창이 직접 발송하므로 코어 메시지 템플릿 시스템을 사용하지
     * 않는다. 빈 배열 반환.
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
     * 설치 시 성인인증(nhnkcp.adult_verification) 목적의 프로바이더 매핑이 비어 있으면 KCP 로
     * 1회 세팅한다. 코어 IDV 의 목적별 프로바이더 해석(resolveForPurpose)은 환경설정
     * `settings.identity.purpose_providers.{purpose}` 를 참조하므로, 이 초기값이 있으면
     * 운영자가 별도 설정 없이도 설치 직후부터 성인인증이 KCP 로 분기된다.
     *
     * 이미 값이 지정된 경우(재설치 등)는 덮어쓰지 않아 운영자 선택을 보존한다 — "초기값" 성격.
     *
     * purpose 키 / provider id 는 리터럴을 사용한다 — install() 은 코어 PluginManager 가
     * 플러그인 PSR-4 오토로드를 등록하기 전에 호출되므로 src/ 클래스 상수를 참조할 수 없다.
     *
     * @return bool 성공 여부
     */
    public function install(): bool
    {
        $settings = app(SettingsService::class);
        $key = 'identity.purpose_providers.nhnkcp.adult_verification';

        if (! $settings->getSetting($key)) {
            $settings->setSetting($key, 'nhnkcp');
        }

        return true;
    }

    /**
     * 플러그인 삭제
     *
     * install() 이 심어 둔 성인인증 목적의 프로바이더 매핑을 거둔다. 이 목적(nhnkcp.adult_verification)
     * 자체가 본 플러그인 소유라 플러그인이 사라지면 매핑은 존재하지 않는 provider 를 가리키는
     * 고아 설정이 된다. 코어는 IDV 정책/메시지만 정리하고 목적별 프로바이더 매핑은 정리하지 않으므로
     * 심은 쪽이 거둔다.
     *
     * 운영자가 다른 provider 로 바꿔 두었다면 그 선택은 건드리지 않는다 — 우리가 심은 값일 때만 거둔다.
     *
     * @return bool 성공 여부
     */
    public function uninstall(): bool
    {
        $settings = app(SettingsService::class);
        $key = 'identity.purpose_providers.nhnkcp.adult_verification';

        if ($settings->getSetting($key) === 'nhnkcp') {
            $settings->setSetting($key, null);
        }

        return true;
    }
}
