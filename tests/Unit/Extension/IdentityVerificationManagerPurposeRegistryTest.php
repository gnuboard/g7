<?php

namespace Tests\Unit\Extension;

use App\Extension\IdentityVerification\IdentityVerificationManager;
use Tests\PureTestCase;

/**
 * IdentityVerificationManager purpose 레지스트리 계약 테스트.
 *
 * 2026-04-24 설계 변경 — AbstractModule/AbstractPlugin::getIdentityPurposes()
 * getter 로 선언된 purpose 가 Manager 레지스트리에 병합되는지 검증합니다.
 */
class IdentityVerificationManagerPurposeRegistryTest extends PureTestCase
{
    /**
     * 아무것도 등록하지 않아도 getAllPurposes() 가 코어 기본 purpose 4종을 돌려준다.
     *
     * @scenario case=core_defaults
     *
     * @effects core_default_purposes_present
     *
     * @return void 반환값 없음
     */
    public function test_core_default_purposes_are_returned(): void
    {
        $manager = new IdentityVerificationManager;
        $purposes = $manager->getAllPurposes();

        $this->assertArrayHasKey('signup', $purposes);
        $this->assertArrayHasKey('password_reset', $purposes);
        $this->assertArrayHasKey('self_update', $purposes);
        $this->assertArrayHasKey('sensitive_action', $purposes);
    }

    /**
     * 확장이 선언한 purpose 묶음을 registerDeclaredPurposes() 로 넣으면 코어 기본값과 함께 병합된다.
     *
     * @scenario case=extension_registration
     *
     * @effects declared_purposes_merged_with_core
     *
     * @return void 반환값 없음
     */
    public function test_register_declared_purposes_merges_extension_purposes(): void
    {
        $manager = new IdentityVerificationManager;

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

    /**
     * registerPurpose() 로 단일 키를 등록하면 hasPurpose() 가 그 키를 인정한다.
     *
     * @scenario case=extension_registration
     *
     * @effects single_purpose_registered
     *
     * @return void 반환값 없음
     */
    public function test_register_purpose_single_key(): void
    {
        $manager = new IdentityVerificationManager;
        $manager->registerPurpose('adult_verification', [
            'label' => 'Adult Verification',
            'allowed_channels' => ['ipin'],
        ]);

        $this->assertTrue($manager->hasPurpose('adult_verification'));
    }

    /**
     * 코어와 같은 키(signup)로 등록하면 등록한 정의가 코어 기본 정의를 덮어쓴다.
     *
     * @scenario case=core_override
     *
     * @effects registered_purpose_overrides_core_entry
     *
     * @return void 반환값 없음
     */
    public function test_declared_purposes_can_override_core_entries(): void
    {
        $manager = new IdentityVerificationManager;
        $manager->registerPurpose('signup', [
            'label' => '커스텀 signup',
            'allowed_channels' => ['sms'],
        ]);

        $purposes = $manager->getAllPurposes();

        $this->assertSame('커스텀 signup', $purposes['signup']['label']);
    }

    /**
     * 빈 키나 배열이 아닌 정의는 버리고 유효한 항목만 레지스트리에 들어간다.
     *
     * @scenario case=invalid_payload
     *
     * @effects invalid_purpose_entries_ignored
     *
     * @return void 반환값 없음
     */
    public function test_invalid_payload_entries_are_ignored(): void
    {
        $manager = new IdentityVerificationManager;
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

    /**
     * hasPurpose() 는 코어 키는 처음부터 참, 미등록 키는 거짓이다가 등록 직후 참으로 바뀐다.
     *
     * @scenario case=extension_registration
     *
     * @effects has_purpose_reflects_registry_state
     *
     * @return void 반환값 없음
     */
    public function test_has_purpose_reflects_core_and_registered_entries(): void
    {
        $manager = new IdentityVerificationManager;

        $this->assertTrue($manager->hasPurpose('signup'));
        $this->assertFalse($manager->hasPurpose('checkout_verification'));

        $manager->registerPurpose('checkout_verification', ['label' => 'test']);

        $this->assertTrue($manager->hasPurpose('checkout_verification'));
    }
}
