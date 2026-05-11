<?php

namespace Tests\Unit\Extension;

use App\Extension\IdentityVerification\IdentityVerificationManager;
use Tests\TestCase;

/**
 * IdentityVerificationManager — purpose source 정보 보존 회귀 테스트.
 *
 * 회귀 사례 (#297):
 *   - 본인인증 정책 화면(코어/모듈)에서 "본인인증 목적" 을 일관되게 표기하려면
 *     `getAllPurposes()` 응답에서 각 purpose 가 어느 source(코어/모듈/플러그인)에 의해
 *     선언되었는지 식별 가능해야 한다.
 *   - 이전 구현은 declaredPurposes 에 source 정보 없이 meta 만 병합해 코어/모듈 화면이
 *     동일 데이터를 클라이언트 측 하드코딩 분기로만 구분할 수 있었다.
 *
 * 계약:
 *   - 코어 기본 4종은 source_type='core', source_identifier='core'
 *   - 모듈/플러그인이 선언한 purpose 는 source_type='module'|'plugin', source_identifier=확장 식별자
 *   - source 가 미명시된 등록 (admin override / filter 훅 등) 은 source_type='admin'
 */
class IdentityVerificationManagerPurposeSourceTest extends TestCase
{
    public function test_core_purposes_are_marked_with_core_source(): void
    {
        $manager = new IdentityVerificationManager();
        $purposes = $manager->getAllPurposes();

        foreach (['signup', 'password_reset', 'self_update', 'sensitive_action'] as $key) {
            $this->assertArrayHasKey($key, $purposes);
            $this->assertSame('core', $purposes[$key]['source_type'] ?? null,
                "코어 기본 purpose {$key} 는 source_type=core 여야 함");
            $this->assertSame('core', $purposes[$key]['source_identifier'] ?? null,
                "코어 기본 purpose {$key} 는 source_identifier=core 여야 함");
        }
    }

    public function test_registerDeclaredPurposes_marks_module_source(): void
    {
        $manager = new IdentityVerificationManager();

        $manager->registerDeclaredPurposes([
            'checkout_verification' => [
                'label' => ['ko' => '결제 인증', 'en' => 'Checkout Verification'],
                'allowed_channels' => ['email', 'sms', 'ipin'],
            ],
        ], 'module', 'sirsoft-ecommerce');

        $purposes = $manager->getAllPurposes();
        $this->assertArrayHasKey('checkout_verification', $purposes);
        $this->assertSame('module', $purposes['checkout_verification']['source_type'] ?? null);
        $this->assertSame('sirsoft-ecommerce', $purposes['checkout_verification']['source_identifier'] ?? null);
    }

    public function test_registerDeclaredPurposes_marks_plugin_source(): void
    {
        $manager = new IdentityVerificationManager();

        $manager->registerDeclaredPurposes([
            'adult_verification' => [
                'label' => ['ko' => '성인 인증', 'en' => 'Adult Verification'],
                'allowed_channels' => ['ipin'],
            ],
        ], 'plugin', 'sirsoft-adult-cert');

        $purposes = $manager->getAllPurposes();
        $this->assertArrayHasKey('adult_verification', $purposes);
        $this->assertSame('plugin', $purposes['adult_verification']['source_type'] ?? null);
        $this->assertSame('sirsoft-adult-cert', $purposes['adult_verification']['source_identifier'] ?? null);
    }

    public function test_registerPurpose_without_source_defaults_to_admin(): void
    {
        $manager = new IdentityVerificationManager();
        $manager->registerPurpose('runtime_purpose', [
            'label' => 'Runtime',
            'allowed_channels' => ['email'],
        ]);

        $purposes = $manager->getAllPurposes();
        $this->assertSame('admin', $purposes['runtime_purpose']['source_type'] ?? null);
        $this->assertSame('admin', $purposes['runtime_purpose']['source_identifier'] ?? null);
    }

    public function test_module_can_override_core_purpose_and_source_changes(): void
    {
        $manager = new IdentityVerificationManager();
        $manager->registerDeclaredPurposes([
            'signup' => ['label' => '커스텀 signup', 'allowed_channels' => ['sms']],
        ], 'module', 'sirsoft-ecommerce');

        $purposes = $manager->getAllPurposes();
        $this->assertSame('module', $purposes['signup']['source_type'] ?? null);
        $this->assertSame('sirsoft-ecommerce', $purposes['signup']['source_identifier'] ?? null);
    }
}
