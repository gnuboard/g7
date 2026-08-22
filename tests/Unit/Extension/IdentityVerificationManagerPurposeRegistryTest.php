<?php

namespace Tests\Unit\Extension;

use App\Extension\IdentityVerification\IdentityVerificationManager;
use Tests\TestCase;

/**
 * IdentityVerificationManager purpose 레지스트리 계약 테스트.
 *
 * 2026-04-24 설계 변경 — AbstractModule/AbstractPlugin::getIdentityPurposes()
 * getter 로 선언된 purpose 가 Manager 레지스트리에 병합되는지 검증합니다.
 */
class IdentityVerificationManagerPurposeRegistryTest extends TestCase
{
    public function test_core_default_purposes_are_returned(): void
    {
        $manager = new IdentityVerificationManager();
        $purposes = $manager->getAllPurposes();

        $this->assertArrayHasKey('signup', $purposes);
        $this->assertArrayHasKey('password_reset', $purposes);
        $this->assertArrayHasKey('self_update', $purposes);
        $this->assertArrayHasKey('sensitive_action', $purposes);
    }

    public function test_registerDeclaredPurposes_merges_extension_purposes(): void
    {
        $manager = new IdentityVerificationManager();

        $manager->registerDeclaredPurposes([
            'checkout_verification' => [
                'label' => ['ko' => '결제 인증', 'en' => 'Checkout Verification'],
                'default_provider' => null,
                'allowed_channels' => ['email', 'sms'],
            ],
        ]);

        $purposes = $manager->getAllPurposes();

        $this->assertArrayHasKey('checkout_verification', $purposes);
        $this->assertArrayHasKey('signup', $purposes, '코어 기본 purpose 도 함께 유지되어야 한다');
    }

    public function test_registerPurpose_single_key(): void
    {
        $manager = new IdentityVerificationManager();
        $manager->registerPurpose('adult_verification', [
            'label' => 'Adult Verification',
            'allowed_channels' => ['ipin'],
        ]);

        $this->assertTrue($manager->hasPurpose('adult_verification'));
    }

    public function test_declared_purposes_can_override_core_entries(): void
    {
        $manager = new IdentityVerificationManager();
        $manager->registerPurpose('signup', [
            'label' => '커스텀 signup',
            'allowed_channels' => ['sms'],
        ]);

        $purposes = $manager->getAllPurposes();

        $this->assertSame('커스텀 signup', $purposes['signup']['label']);
    }

    public function test_invalid_payload_entries_are_ignored(): void
    {
        $manager = new IdentityVerificationManager();
        $manager->registerDeclaredPurposes([
            '' => ['label' => 'empty key'],
            'valid_key' => ['label' => 'OK'],
            'broken_key' => 'not-an-array',
        ]);

        $purposes = $manager->getAllPurposes();

        $this->assertArrayHasKey('valid_key', $purposes);
        $this->assertArrayNotHasKey('', $purposes);
        $this->assertArrayNotHasKey('broken_key', $purposes);
    }

    public function test_hasPurpose_reflects_core_and_registered_entries(): void
    {
        $manager = new IdentityVerificationManager();

        $this->assertTrue($manager->hasPurpose('signup'));
        $this->assertFalse($manager->hasPurpose('checkout_verification'));

        $manager->registerPurpose('checkout_verification', ['label' => 'test']);

        $this->assertTrue($manager->hasPurpose('checkout_verification'));
    }
}
