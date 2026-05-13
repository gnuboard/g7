<?php

namespace Tests\Unit\Extension\Helpers;

use App\Extension\Helpers\IdentityPolicySyncHelper;
use App\Models\IdentityPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * IdentityPolicySyncHelper 테스트.
 *
 * 알림 시스템 NotificationSyncHelper 동형 — upsert + user_overrides 보존 + cleanupStale 을 검증.
 */
class IdentityPolicySyncHelperTest extends TestCase
{
    use RefreshDatabase;

    private IdentityPolicySyncHelper $helper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->helper = $this->app->make(IdentityPolicySyncHelper::class);
    }

    public function test_sync_policy_creates_new_policy_on_first_run(): void
    {
        $policy = $this->helper->syncPolicy([
            'key' => 'test.p1',
            'scope' => 'route',
            'target' => 'api.test.one',
            'purpose' => 'sensitive_action',
            'grace_minutes' => 0,
            'enabled' => true,
            'source_type' => 'core',
            'source_identifier' => 'core',
        ]);

        $this->assertSame('test.p1', $policy->key);
        $this->assertSame('route', $policy->scope->value);
        $this->assertTrue($policy->enabled);
    }

    public function test_sync_policy_updates_fields_not_in_user_overrides(): void
    {
        $this->helper->syncPolicy([
            'key' => 'test.p2',
            'scope' => 'route',
            'target' => 'api.test.two',
            'purpose' => 'sensitive_action',
            'grace_minutes' => 0,
            'enabled' => true,
            'source_type' => 'core',
            'source_identifier' => 'core',
        ]);

        // 운영자가 enabled 를 수정했다고 가정 — user_overrides 에 기록
        $policy = IdentityPolicy::where('key', 'test.p2')->first();
        $policy->user_overrides = ['enabled'];
        $policy->enabled = false;
        $policy->save();

        // 재시딩 — enabled 는 user_overrides 에 있으므로 보존, target 은 갱신
        $this->helper->syncPolicy([
            'key' => 'test.p2',
            'scope' => 'route',
            'target' => 'api.test.two_changed',
            'purpose' => 'sensitive_action',
            'grace_minutes' => 0,
            'enabled' => true, // 여기서 true 로 재설정해도
            'source_type' => 'core',
            'source_identifier' => 'core',
        ]);

        $policy->refresh();
        $this->assertSame('api.test.two_changed', $policy->target, 'target 은 갱신되어야 함');
        $this->assertFalse($policy->enabled, 'enabled 는 user_overrides 에 있어 보존되어야 함');
    }

    /**
     * conditions 운영자 편집값 보존 (B안 리팩토링).
     *
     * 회원가입 단계 등 정책 조건은 운영자가 화면에서 수정 후 user_overrides 에 'conditions' 가 추가되며,
     * 모듈 재sync 시 declared default 가 운영자 값을 덮어쓰지 않아야 한다.
     */
    public function test_sync_policy_preserves_conditions_in_user_overrides(): void
    {
        $this->helper->syncPolicy([
            'key' => 'test.signup.before_submit',
            'scope' => 'route',
            'target' => 'api.auth.register',
            'purpose' => 'signup',
            'conditions' => ['signup_stage' => 'before_submit'],
            'source_type' => 'module',
            'source_identifier' => 'sirsoft-board',
        ]);

        $policy = IdentityPolicy::where('key', 'test.signup.before_submit')->first();
        $policy->user_overrides = ['conditions'];
        $policy->conditions = ['signup_stage' => 'after_create'];
        $policy->save();

        $this->helper->syncPolicy([
            'key' => 'test.signup.before_submit',
            'scope' => 'route',
            'target' => 'api.auth.register',
            'purpose' => 'signup',
            'conditions' => ['signup_stage' => 'before_submit'],
            'source_type' => 'module',
            'source_identifier' => 'sirsoft-board',
        ]);

        $policy->refresh();
        $this->assertSame(
            ['signup_stage' => 'after_create'],
            $policy->conditions,
            'conditions 는 user_overrides 에 등록되어 있어 declared default 로 덮어써지면 안 됨',
        );
    }

    /**
     * trackable 5필드(enabled / grace_minutes / provider_id / fail_mode / conditions) 각각에 대해
     * user_overrides 등록 시 syncPolicy 가 운영자 값을 보존하는지 매트릭스 검증.
     *
     * @dataProvider trackableFieldProvider
     */
    public function test_sync_preserves_each_trackable_field_in_user_overrides(string $field, mixed $declared, mixed $override): void
    {
        $key = "test.matrix.{$field}";
        $base = [
            'key' => $key,
            'scope' => 'route',
            'target' => "api.test.{$field}",
            'purpose' => 'sensitive_action',
            'grace_minutes' => 0,
            'enabled' => true,
            'provider_id' => null,
            'fail_mode' => 'block',
            'conditions' => null,
            'source_type' => 'module',
            'source_identifier' => 'sirsoft-board',
        ];
        $base[$field] = $declared;

        $this->helper->syncPolicy($base);

        // 운영자 변경 시뮬레이션
        $policy = IdentityPolicy::where('key', $key)->first();
        $policy->user_overrides = [$field];
        $policy->{$field} = $override;
        $policy->save();

        // 재시딩 — declared 가 운영자 값을 덮어쓰면 안 됨
        $this->helper->syncPolicy($base);

        $policy->refresh();
        $actual = $field === 'conditions' ? $policy->conditions : $policy->{$field};
        // enum cast 된 분류 컬럼은 enum 객체이므로 ->value 비교
        if ($actual instanceof \BackedEnum) {
            $actual = $actual->value;
        }
        $this->assertSame(
            $override,
            $actual,
            "trackable 필드 '{$field}' 가 user_overrides 에 등록되어 있어 declared default 로 덮어써지면 안 됨",
        );
    }

    public static function trackableFieldProvider(): array
    {
        return [
            'enabled' => ['enabled', true, false],
            'grace_minutes' => ['grace_minutes', 0, 60],
            'provider_id' => ['provider_id', null, 'g7:core.mail'],
            'fail_mode' => ['fail_mode', 'block', 'log_only'],
            'conditions' => ['conditions', null, ['signup_stage' => 'after_create']],
        ];
    }

    public function test_cleanup_stale_removes_policies_not_in_current_keys(): void
    {
        $this->helper->syncPolicy([
            'key' => 'test.stale.a',
            'scope' => 'route',
            'target' => 'a',
            'purpose' => 'sensitive_action',
            'source_type' => 'module',
            'source_identifier' => 'some-module',
        ]);
        $this->helper->syncPolicy([
            'key' => 'test.stale.b',
            'scope' => 'route',
            'target' => 'b',
            'purpose' => 'sensitive_action',
            'source_type' => 'module',
            'source_identifier' => 'some-module',
        ]);

        // b 만 현재 선언 — a 는 stale 로 삭제되어야 함
        $removed = $this->helper->cleanupStalePolicies('module', 'some-module', ['test.stale.b']);

        $this->assertSame(1, $removed);
        $this->assertNull(IdentityPolicy::where('key', 'test.stale.a')->first());
        $this->assertNotNull(IdentityPolicy::where('key', 'test.stale.b')->first());
    }

    public function test_cleanup_stale_preserves_other_sources(): void
    {
        $this->helper->syncPolicy([
            'key' => 'test.admin.one',
            'scope' => 'route',
            'target' => 'a',
            'purpose' => 'sensitive_action',
            'source_type' => 'admin',
            'source_identifier' => 'admin',
        ]);

        // core source cleanup 은 admin source 정책에 영향 없어야 함
        $this->helper->cleanupStalePolicies('core', 'core', []);

        $this->assertNotNull(IdentityPolicy::where('key', 'test.admin.one')->first());
    }
}
