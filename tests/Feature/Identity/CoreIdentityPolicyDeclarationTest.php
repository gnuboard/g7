<?php

namespace Tests\Feature\Identity;

use App\Models\IdentityPolicy;
use Database\Seeders\IdentityPolicySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 코어 IDV 정책 선언 형상 회귀 (D1).
 *
 * `IdentityPolicySeeder` 가 `config/core.php` 의 9개 정책을 DB 로 동기화한 결과가
 * 선언과 정확히 일치하는지 검증한다. 회귀 시 — 예: purpose 오타, applies_to 변경,
 * grace_minutes 변경, conditions 누락 — 즉시 fail.
 */
class CoreIdentityPolicyDeclarationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IdentityPolicySeeder::class);
    }

    public function test_core_seeds_exactly_nine_core_policies(): void
    {
        $count = IdentityPolicy::query()
            ->where('source_type', 'core')
            ->where('source_identifier', 'core')
            ->count();

        $this->assertSame(9, $count, '코어 정책은 정확히 9건이어야 함 (config/core.php 의 identity_policies 블록)');
    }

    /**
     * @dataProvider coreDeclarationProvider
     */
    public function test_core_policy_matches_declaration(string $key, array $expected): void
    {
        $policy = IdentityPolicy::where('key', $key)->first();

        $this->assertNotNull($policy, "정책 '{$key}' 가 DB 에 없음");

        foreach ($expected as $field => $value) {
            $actual = $policy->{$field};
            // enum cast 된 분류 컬럼(scope/fail_mode/applies_to/source_type) 은 enum 객체이므로 ->value 비교
            if ($actual instanceof \BackedEnum) {
                $actual = $actual->value;
            }
            // 배열(conditions 등)은 키 순서를 강요하지 않고 의미 비교, 스칼라는 strict 비교
            if (is_array($value)) {
                $this->assertEquals(
                    $value,
                    $actual,
                    sprintf("정책 '%s' 의 %s 필드가 선언과 일치해야 함", $key, $field),
                );
            } else {
                $this->assertSame(
                    $value,
                    $actual,
                    sprintf("정책 '%s' 의 %s 필드가 선언과 일치해야 함 (선언: %s / 실제: %s)",
                        $key,
                        $field,
                        json_encode($value),
                        json_encode($actual),
                    ),
                );
            }
        }

        $this->assertSame('core', $policy->source_type->value);
        $this->assertSame('core', $policy->source_identifier);
    }

    public static function coreDeclarationProvider(): array
    {
        return [
            'signup_before_submit' => [
                'core.auth.signup_before_submit',
                [
                    'scope' => 'route',
                    'target' => 'api.auth.register',
                    'purpose' => 'signup',
                    'grace_minutes' => 0,
                    'enabled' => false,
                    'applies_to' => 'self',
                    'fail_mode' => 'block',
                    'priority' => 110,
                    'conditions' => ['signup_stage' => 'before_submit', 'http_method' => ['POST']],
                ],
            ],
            'signup_after_create' => [
                'core.auth.signup_after_create',
                [
                    'scope' => 'hook',
                    'target' => 'core.auth.after_register',
                    'purpose' => 'signup',
                    'grace_minutes' => 0,
                    'enabled' => false,
                    'applies_to' => 'self',
                    'fail_mode' => 'block',
                    'priority' => 100,
                    'conditions' => ['signup_stage' => 'after_create'],
                ],
            ],
            'password_reset' => [
                'core.auth.password_reset',
                [
                    'scope' => 'hook',
                    'target' => 'core.auth.before_reset_password',
                    'purpose' => 'password_reset',
                    'grace_minutes' => 0,
                    'enabled' => false,
                    'applies_to' => 'both',
                    'fail_mode' => 'block',
                ],
            ],
            'password_change' => [
                'core.profile.password_change',
                [
                    'scope' => 'route',
                    'target' => 'api.me.password',
                    'purpose' => 'sensitive_action',
                    'grace_minutes' => 5,
                    'enabled' => true,
                    'applies_to' => 'self',
                    'fail_mode' => 'block',
                ],
            ],
            'contact_change' => [
                'core.profile.contact_change',
                [
                    'scope' => 'hook',
                    'target' => 'core.user.before_update',
                    'purpose' => 'sensitive_action',
                    'grace_minutes' => 5,
                    'enabled' => true,
                    'applies_to' => 'self',
                    'fail_mode' => 'block',
                    'conditions' => ['changed_fields' => ['email', 'phone', 'mobile']],
                ],
            ],
            'account_withdraw' => [
                'core.account.withdraw',
                [
                    'scope' => 'route',
                    'target' => 'api.me.destroy',
                    'purpose' => 'sensitive_action',
                    'grace_minutes' => 0,
                    'enabled' => true,
                    'applies_to' => 'self',
                    'fail_mode' => 'block',
                ],
            ],
            'app_key_regenerate' => [
                'core.admin.app_key_regenerate',
                [
                    'scope' => 'route',
                    'target' => 'api.admin.settings.regenerate-app-key',
                    'purpose' => 'sensitive_action',
                    'grace_minutes' => 0,
                    'enabled' => false,
                    'applies_to' => 'admin',
                    'fail_mode' => 'block',
                ],
            ],
            'user_delete' => [
                'core.admin.user_delete',
                [
                    'scope' => 'hook',
                    'target' => 'core.user.before_delete',
                    'purpose' => 'sensitive_action',
                    'grace_minutes' => 0,
                    'enabled' => false,
                    'applies_to' => 'admin',
                    'fail_mode' => 'block',
                ],
            ],
            'extension_uninstall' => [
                'core.admin.extension_uninstall',
                [
                    'scope' => 'route',
                    'target' => 'api.admin.{modules,plugins}.uninstall',
                    'purpose' => 'sensitive_action',
                    'grace_minutes' => 0,
                    'enabled' => false,
                    'applies_to' => 'admin',
                    'fail_mode' => 'block',
                ],
            ],
        ];
    }
}
