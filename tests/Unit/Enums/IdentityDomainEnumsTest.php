<?php

namespace Tests\Unit\Enums;

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

    /**
     * 본인인증 목적 Enum 은 login 을 포함한 코어 목적 5종을 정해진 순서로 가진다.
     *
     * @scenario surface=domain_catalog
     *
     * @effects verification_purpose_lists_five_core_cases
     *
     * @return void 반환값 없음
     */
    public function test_verification_purpose_has_five_core_cases(): void
    {
        // login(2단계 인증)은 7.1.0 에서 코어 목적이 됐다 — 본인인증 알림 정의 identity.purpose.login 과 짝이다.
        $this->assertCount(5, IdentityVerificationPurpose::cases());
        $this->assertSame(
            ['signup', 'password_reset', 'self_update', 'sensitive_action', 'login'],
            IdentityVerificationPurpose::values(),
        );
    }

    /**
     * isCore 는 코어 목적(signup)에 true, 확장이 선언한 목적에 false 를 돌려준다.
     *
     * @scenario surface=domain_catalog
     *
     * @effects verification_purpose_is_core_rejects_extension_purpose
     *
     * @return void 반환값 없음
     */
    public function test_verification_purpose_is_core_check(): void
    {
        $this->assertTrue(IdentityVerificationPurpose::isCore('signup'));
        $this->assertFalse(IdentityVerificationPurpose::isCore('module_declared'));
    }

    /**
     * 본인인증 목적 Enum 의 tryFrom 은 알려진 값을 케이스로, 모르는 값을 null 로 돌려준다.
     *
     * @scenario surface=domain_catalog
     *
     * @effects enum_try_from_returns_null_for_unknown_value
     *
     * @return void 반환값 없음
     */
    public function test_verification_purpose_try_from_safety(): void
    {
        $this->assertSame(IdentityVerificationPurpose::Signup, IdentityVerificationPurpose::tryFrom('signup'));
        $this->assertNull(IdentityVerificationPurpose::tryFrom('invalid'));
    }

    // ========================================================================
    // IdentityVerificationChannel
    // ========================================================================

    /**
     * 본인인증 채널 Enum 에 값이 email 인 Email 케이스가 있다.
     *
     * @scenario surface=domain_catalog
     *
     * @effects verification_channel_includes_email
     *
     * @return void 반환값 없음
     */
    public function test_verification_channel_has_email(): void
    {
        $this->assertContains(IdentityVerificationChannel::Email, IdentityVerificationChannel::cases());
        $this->assertSame('email', IdentityVerificationChannel::Email->value);
    }

    /**
     * 채널 isCore 는 email 에 true, 코어가 아닌 sms 에 false 를 돌려준다.
     *
     * @scenario surface=domain_catalog
     *
     * @effects verification_channel_is_core_rejects_non_core_channel
     *
     * @return void 반환값 없음
     */
    public function test_verification_channel_is_core(): void
    {
        $this->assertTrue(IdentityVerificationChannel::isCore('email'));
        $this->assertFalse(IdentityVerificationChannel::isCore('sms'));
    }

    // ========================================================================
    // IdentityOriginType
    // ========================================================================

    /**
     * 발생 출처 Enum 은 route·hook·policy·middleware·api·custom·system 7종을 가진다.
     *
     * @scenario surface=domain_catalog
     *
     * @effects origin_type_lists_seven_cases
     *
     * @return void 반환값 없음
     */
    public function test_origin_type_has_seven_cases(): void
    {
        $this->assertCount(7, IdentityOriginType::cases());
        $this->assertSame(
            ['route', 'hook', 'policy', 'middleware', 'api', 'custom', 'system'],
            IdentityOriginType::values(),
        );
    }

    /**
     * 발생 출처 Enum 의 tryFrom 은 알려진 값을 케이스로, 모르는 값을 null 로 돌려준다.
     *
     * @scenario surface=domain_catalog
     *
     * @effects enum_try_from_returns_null_for_unknown_value
     *
     * @return void 반환값 없음
     */
    public function test_origin_type_try_from_safety(): void
    {
        $this->assertSame(IdentityOriginType::Route, IdentityOriginType::tryFrom('route'));
        $this->assertNull(IdentityOriginType::tryFrom('not_a_type'));
    }

    // ========================================================================
    // IdentityPolicyScope
    // ========================================================================

    /**
     * 정책 범위 Enum 은 route·hook·custom 3종을 가진다.
     *
     * @scenario surface=domain_catalog
     *
     * @effects policy_scope_lists_three_cases
     *
     * @return void 반환값 없음
     */
    public function test_policy_scope_has_three_cases(): void
    {
        $this->assertCount(3, IdentityPolicyScope::cases());
        $this->assertSame(['route', 'hook', 'custom'], IdentityPolicyScope::values());
    }

    // ========================================================================
    // IdentityPolicyFailMode
    // ========================================================================

    /**
     * 정책 실패 모드 Enum 은 block·log_only 2종을 가진다.
     *
     * @scenario surface=domain_catalog
     *
     * @effects policy_fail_mode_lists_two_cases
     *
     * @return void 반환값 없음
     */
    public function test_policy_fail_mode_has_two_cases(): void
    {
        $this->assertCount(2, IdentityPolicyFailMode::cases());
        $this->assertSame(['block', 'log_only'], IdentityPolicyFailMode::values());
    }

    // ========================================================================
    // IdentityPolicyAppliesTo
    // ========================================================================

    /**
     * 정책 적용 대상 Enum 은 self·admin·both 3종이며 예약어 회피 케이스 Self_ 의 값은 self 다.
     *
     * @scenario surface=domain_catalog
     *
     * @effects policy_applies_to_lists_three_cases_with_self_value
     *
     * @return void 반환값 없음
     */
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

    /**
     * 정책 출처 Enum 은 core·module·plugin·admin 4종을 가진다.
     *
     * @scenario surface=domain_catalog
     *
     * @effects policy_source_type_lists_four_cases
     *
     * @return void 반환값 없음
     */
    public function test_policy_source_type_has_four_cases(): void
    {
        $this->assertCount(4, IdentityPolicySourceType::cases());
        $this->assertSame(['core', 'module', 'plugin', 'admin'], IdentityPolicySourceType::values());
    }

    // IdentityMessageScopeType 은 7.1.0 에서 없어졌다 — 본인인증 메시지는 알림 정의 type 규칙(IdentityNotificationType)을 쓴다(#699).

    // ========================================================================
    // 다국어 라벨 — 모든 enum 의 label() 이 다국어 키로부터 비어있지 않은 문자열을 반환해야 함
    // ========================================================================

    /**
     * ko 로케일에서 IDV Enum 7종의 label() 이 비어 있지 않고 identity. 키를 그대로 노출하지 않는 번역 문자열이다.
     *
     * @scenario surface=domain_catalog
     *
     * @effects enum_labels_resolve_translated_text
     *
     * @return void 반환값 없음
     */
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
