<?php

namespace Tests\Unit\Enums;

use App\Enums\IdentityMessageScopeType;
use App\Enums\IdentityOriginType;
use App\Enums\IdentityPolicyAppliesTo;
use App\Enums\IdentityPolicyFailMode;
use App\Enums\IdentityPolicyScope;
use App\Enums\IdentityPolicySourceType;
use App\Enums\IdentityVerificationChannel;
use App\Enums\IdentityVerificationPurpose;
use Tests\TestCase;

/**
 * IDV 도메인 Enum 8종 단위 테스트.
 *
 * 케이스 누락/추가 회귀, tryFrom 안전성, label 다국어 키 존재를 검증합니다.
 */
class IdentityDomainEnumsTest extends TestCase
{
    // ========================================================================
    // IdentityVerificationPurpose
    // ========================================================================

    public function test_verification_purpose_has_four_core_cases(): void
    {
        $this->assertCount(4, IdentityVerificationPurpose::cases());
        $this->assertSame(
            ['signup', 'password_reset', 'self_update', 'sensitive_action'],
            IdentityVerificationPurpose::values(),
        );
    }

    public function test_verification_purpose_is_core_check(): void
    {
        $this->assertTrue(IdentityVerificationPurpose::isCore('signup'));
        $this->assertFalse(IdentityVerificationPurpose::isCore('module_declared'));
    }

    public function test_verification_purpose_try_from_safety(): void
    {
        $this->assertSame(IdentityVerificationPurpose::Signup, IdentityVerificationPurpose::tryFrom('signup'));
        $this->assertNull(IdentityVerificationPurpose::tryFrom('invalid'));
    }

    // ========================================================================
    // IdentityVerificationChannel
    // ========================================================================

    public function test_verification_channel_has_email(): void
    {
        $this->assertContains(IdentityVerificationChannel::Email, IdentityVerificationChannel::cases());
        $this->assertSame('email', IdentityVerificationChannel::Email->value);
    }

    public function test_verification_channel_is_core(): void
    {
        $this->assertTrue(IdentityVerificationChannel::isCore('email'));
        $this->assertFalse(IdentityVerificationChannel::isCore('sms'));
    }

    // ========================================================================
    // IdentityOriginType
    // ========================================================================

    public function test_origin_type_has_seven_cases(): void
    {
        $this->assertCount(7, IdentityOriginType::cases());
        $this->assertSame(
            ['route', 'hook', 'policy', 'middleware', 'api', 'custom', 'system'],
            IdentityOriginType::values(),
        );
    }

    public function test_origin_type_try_from_safety(): void
    {
        $this->assertSame(IdentityOriginType::Route, IdentityOriginType::tryFrom('route'));
        $this->assertNull(IdentityOriginType::tryFrom('not_a_type'));
    }

    // ========================================================================
    // IdentityPolicyScope
    // ========================================================================

    public function test_policy_scope_has_three_cases(): void
    {
        $this->assertCount(3, IdentityPolicyScope::cases());
        $this->assertSame(['route', 'hook', 'custom'], IdentityPolicyScope::values());
    }

    // ========================================================================
    // IdentityPolicyFailMode
    // ========================================================================

    public function test_policy_fail_mode_has_two_cases(): void
    {
        $this->assertCount(2, IdentityPolicyFailMode::cases());
        $this->assertSame(['block', 'log_only'], IdentityPolicyFailMode::values());
    }

    // ========================================================================
    // IdentityPolicyAppliesTo
    // ========================================================================

    public function test_policy_applies_to_has_three_cases(): void
    {
        $this->assertCount(3, IdentityPolicyAppliesTo::cases());
        $this->assertSame(['self', 'admin', 'both'], IdentityPolicyAppliesTo::values());
        // 'self' 는 PHP 예약어이므로 case 이름은 Self_ 로 정의됨.
        $this->assertSame('self', IdentityPolicyAppliesTo::Self_->value);
    }

    // ========================================================================
    // IdentityPolicySourceType
    // ========================================================================

    public function test_policy_source_type_has_four_cases(): void
    {
        $this->assertCount(4, IdentityPolicySourceType::cases());
        $this->assertSame(['core', 'module', 'plugin', 'admin'], IdentityPolicySourceType::values());
    }

    // ========================================================================
    // IdentityMessageScopeType
    // ========================================================================

    public function test_message_scope_type_has_three_cases(): void
    {
        $this->assertCount(3, IdentityMessageScopeType::cases());
        $this->assertSame(
            ['provider_default', 'purpose', 'policy'],
            IdentityMessageScopeType::values(),
        );
    }

    // ========================================================================
    // 다국어 라벨 — 모든 enum 의 label() 이 다국어 키로부터 비어있지 않은 문자열을 반환해야 함
    // ========================================================================

    public function test_all_enum_labels_are_translated(): void
    {
        app()->setLocale('ko');

        $samples = [
            IdentityVerificationPurpose::Signup,
            IdentityVerificationChannel::Email,
            IdentityOriginType::Route,
            IdentityPolicyScope::Route,
            IdentityPolicyFailMode::Block,
            IdentityPolicyAppliesTo::Self_,
            IdentityPolicySourceType::Core,
            IdentityMessageScopeType::Purpose,
        ];

        foreach ($samples as $case) {
            $label = $case->label();
            $this->assertIsString($label);
            $this->assertNotSame('', $label);
            // 다국어 키 미정의 시 Laravel 은 키 자체를 반환하므로 키 prefix 가 그대로면 fail.
            $this->assertStringNotContainsString('identity.', $label, sprintf(
                '%s::%s label has untranslated key: %s',
                $case::class,
                $case->name,
                $label,
            ));
        }
    }
}
