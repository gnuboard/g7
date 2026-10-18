<?php

namespace Tests\Unit\Notifications\Identity;

use App\Enums\NotificationDefinitionCategory;
use App\Notifications\Identity\IdentityNotificationType;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 본인인증 알림 type 규칙 테스트 (#699)
 *
 * 옛 IDV 메시지 표의 3단 폴백(policy → purpose → provider_default)을 알림 type 후보 3개로
 * 바꾼 규칙을 잠근다 — type 컬럼 길이(100자) 경계·해시 결정성·후보 순서·옛 범위 변환.
 */
class IdentityNotificationTypeTest extends TestCase
{
    /**
     * 분류 상수가 Enum 값과 같다.
     *
     * @scenario surface=type_rule
     *
     * @return void 반환값 없음
     */
    public function test_category_matches_enum(): void
    {
        $this->assertSame(NotificationDefinitionCategory::Identity->value, IdentityNotificationType::CATEGORY);
        $this->assertSame('identity', IdentityNotificationType::CATEGORY);
    }

    /**
     * 기본·목적별 type 이 규칙대로 만들어진다.
     *
     * @scenario surface=type_rule
     *
     * @effects candidates_policy_purpose_default_order
     *
     * @return void 반환값 없음
     */
    public function test_default_and_purpose_types(): void
    {
        $this->assertSame('identity.default', IdentityNotificationType::default());
        $this->assertSame('identity.purpose.login', IdentityNotificationType::forPurpose('login'));
        $this->assertSame(
            'identity.purpose.checkout_verification',
            IdentityNotificationType::forPurpose('checkout_verification')
        );
    }

    /**
     * 정책 type 길이 경계 — 접두어 'identity.policy.'(16자) + 키 길이.
     *
     * @return array<string, array{0: int, 1: bool}> 케이스명 → [만들 type 길이, 해시 여부]
     */
    public static function policyLengthProvider(): array
    {
        return [
            '99자 — 원문 유지' => [99, false],
            '100자 — 원문 유지' => [100, false],
            '101자 — 해시' => [101, true],
        ];
    }

    /**
     * 정책 type 은 100자까지 원문, 101자부터 해시로 줄어든다.
     *
     * @scenario surface=type_rule
     *
     * @effects long_policy_key_hashed_within_column
     *
     * @param  int  $typeLength  만들 type 의 길이
     * @param  bool  $hashed  해시로 줄어야 하는지
     * @return void 반환값 없음
     */
    #[DataProvider('policyLengthProvider')]
    public function test_policy_type_length_boundary(int $typeLength, bool $hashed): void
    {
        $prefix = 'identity.policy.';
        $key = str_repeat('k', $typeLength - strlen($prefix));

        $type = IdentityNotificationType::forPolicy($key);

        if ($hashed) {
            $this->assertSame('identity.policy.~'.substr(sha1($key), 0, 20), $type);
            $this->assertLessThanOrEqual(IdentityNotificationType::MAX_TYPE_LENGTH, strlen($type));
        } else {
            $this->assertSame($prefix.$key, $type);
            $this->assertSame($typeLength, strlen($type));
        }
    }

    /**
     * 해시 type 은 같은 키에 같고 다른 키에 다르다.
     *
     * @scenario surface=type_rule
     *
     * @effects long_policy_key_hashed_within_column
     *
     * @return void 반환값 없음
     */
    public function test_hashed_policy_type_is_deterministic_and_distinct(): void
    {
        $a = str_repeat('a', 120);
        $b = str_repeat('a', 119).'b';

        $this->assertSame(IdentityNotificationType::forPolicy($a), IdentityNotificationType::forPolicy($a));
        $this->assertNotSame(IdentityNotificationType::forPolicy($a), IdentityNotificationType::forPolicy($b));
    }

    /**
     * 후보 순서는 정책 → 목적 → 기본이며 정책 키가 비면 생략한다.
     *
     * @scenario surface=type_rule
     *
     * @effects candidates_policy_purpose_default_order
     *
     * @return void 반환값 없음
     */
    public function test_candidates_order_with_and_without_policy(): void
    {
        $this->assertSame(
            ['identity.policy.core.account.withdraw', 'identity.purpose.sensitive_action', 'identity.default'],
            IdentityNotificationType::candidates('sensitive_action', 'core.account.withdraw')
        );
        $this->assertSame(
            ['identity.purpose.login', 'identity.default'],
            IdentityNotificationType::candidates('login', null)
        );
        $this->assertSame(
            ['identity.purpose.login', 'identity.default'],
            IdentityNotificationType::candidates('login', '')
        );
    }

    /**
     * 옛 IDV 표의 범위 값이 type 으로 바뀐다.
     *
     * @scenario surface=type_rule
     *
     * @effects legacy_scope_converted_to_type
     *
     * @return void 반환값 없음
     */
    public function test_from_legacy_scope(): void
    {
        $this->assertSame('identity.default', IdentityNotificationType::fromLegacyScope('provider_default', ''));
        $this->assertSame('identity.default', IdentityNotificationType::fromLegacyScope('provider_default', null));
        $this->assertSame('identity.purpose.signup', IdentityNotificationType::fromLegacyScope('purpose', 'signup'));
        $this->assertSame(
            'identity.policy.admin.board.delete',
            IdentityNotificationType::fromLegacyScope('policy', 'admin.board.delete')
        );
        $this->assertNull(IdentityNotificationType::fromLegacyScope('purpose', ''));
        $this->assertNull(IdentityNotificationType::fromLegacyScope('unknown', 'x'));
    }

    /**
     * 공통 변수와 민감 변수 목록이 단일 SSoT 에서 나온다.
     *
     * @scenario surface=type_rule
     *
     * @effects sensitive_variables_declared
     *
     * @return void 반환값 없음
     */
    public function test_variables_ssot(): void
    {
        $keys = array_column(IdentityNotificationType::commonVariables(), 'key');

        $this->assertSame(
            ['code', 'action_url', 'expire_minutes', 'purpose_label', 'app_name', 'site_url', 'recipient_email'],
            $keys
        );
        $this->assertSame(['code', 'action_url'], IdentityNotificationType::sensitiveVariables());
        $this->assertEmpty(array_diff(IdentityNotificationType::sensitiveVariables(), $keys));
    }

    /**
     * type 접두어로 본인인증 알림을 판별한다.
     *
     * @scenario surface=type_rule
     *
     * @return void 반환값 없음
     */
    public function test_is_identity_type(): void
    {
        $this->assertTrue(IdentityNotificationType::isIdentityType('identity.purpose.signup'));
        $this->assertFalse(IdentityNotificationType::isIdentityType('welcome'));
    }

    /**
     * 정책별 type 에서 정책 키를 되찾는다 — 짧은 type 은 접두사만 떼고, 해시 type 은 후보 키로 맞춰 본다.
     *
     * @scenario surface=type_rule
     *
     * @effects long_policy_key_hashed_within_column
     *
     * @return void 반환값 없음
     */
    public function test_policy_key_from_type(): void
    {
        $this->assertSame('admin.short', IdentityNotificationType::policyKeyFromType('identity.policy.admin.short'));
        $this->assertNull(IdentityNotificationType::policyKeyFromType('identity.purpose.signup'));
        $this->assertNull(IdentityNotificationType::policyKeyFromType('welcome'));

        $long = 'admin.'.str_repeat('y', 100);
        $hashed = IdentityNotificationType::forPolicy($long);
        $this->assertStringStartsWith('identity.policy.~', $hashed);
        $this->assertSame($long, IdentityNotificationType::policyKeyFromType($hashed, ['admin.other', $long]));
        $this->assertNull(IdentityNotificationType::policyKeyFromType($hashed, ['admin.other']), '맞는 후보가 없으면 null');
        $this->assertNull(IdentityNotificationType::policyKeyFromType($hashed), '후보 없이 해시는 되돌릴 수 없다');
    }
}
