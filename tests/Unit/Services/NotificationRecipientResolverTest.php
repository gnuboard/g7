<?php

namespace Tests\Unit\Services;

use App\Models\Role;
use App\Models\User;
use App\Services\NotificationRecipientResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * NotificationRecipientResolver 테스트
 *
 * 수신자 타입별(trigger_user, related_user, role, specific_users)
 * 해석 로직과 exclude_trigger_user 필터를 검증합니다.
 */
class NotificationRecipientResolverTest extends TestCase
{
    use RefreshDatabase;

    private NotificationRecipientResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = app(NotificationRecipientResolver::class);
    }

    // ──────────────────────────────────────────────
    // trigger_user 타입
    // ──────────────────────────────────────────────

    public function test_trigger_user_resolves_from_context(): void
    {
        $user = User::factory()->create();

        $result = $this->resolver->resolve(
            [['type' => 'trigger_user']],
            ['trigger_user_id' => $user->id, 'trigger_user' => $user]
        );

        $this->assertCount(1, $result);
        $this->assertEquals($user->id, $result->first()->id);
    }

    public function test_trigger_user_resolves_from_id_only(): void
    {
        $user = User::factory()->create();

        $result = $this->resolver->resolve(
            [['type' => 'trigger_user']],
            ['trigger_user_id' => $user->id]
        );

        $this->assertCount(1, $result);
        $this->assertEquals($user->id, $result->first()->id);
    }

    public function test_trigger_user_returns_empty_without_context(): void
    {
        $result = $this->resolver->resolve(
            [['type' => 'trigger_user']],
            []
        );

        $this->assertCount(0, $result);
    }

    // ──────────────────────────────────────────────
    // related_user 타입
    // ──────────────────────────────────────────────

    public function test_related_user_resolves_from_context(): void
    {
        $author = User::factory()->create();

        $result = $this->resolver->resolve(
            [['type' => 'related_user', 'relation' => 'author']],
            ['related_users' => ['author' => $author]]
        );

        $this->assertCount(1, $result);
        $this->assertEquals($author->id, $result->first()->id);
    }

    public function test_related_user_returns_empty_when_relation_missing(): void
    {
        $result = $this->resolver->resolve(
            [['type' => 'related_user', 'relation' => 'author']],
            ['related_users' => []]
        );

        $this->assertCount(0, $result);
    }

    public function test_related_user_supports_collection(): void
    {
        $users = User::factory()->count(2)->create();

        $result = $this->resolver->resolve(
            [['type' => 'related_user', 'relation' => 'managers']],
            ['related_users' => ['managers' => $users]]
        );

        $this->assertCount(2, $result);
    }

    // ──────────────────────────────────────────────
    // role 타입
    // ──────────────────────────────────────────────

    public function test_role_resolves_users_by_role_identifier(): void
    {
        $role = Role::factory()->create(['identifier' => 'test_admin']);
        $admin1 = User::factory()->create();
        $admin2 = User::factory()->create();
        $role->users()->attach([$admin1->id, $admin2->id]);

        $result = $this->resolver->resolve(
            [['type' => 'role', 'value' => 'test_admin']],
            []
        );

        $this->assertCount(2, $result);
        $this->assertTrue($result->contains('id', $admin1->id));
        $this->assertTrue($result->contains('id', $admin2->id));
    }

    public function test_role_falls_back_to_super_admin(): void
    {
        Role::factory()->create(['identifier' => 'empty_role']);
        $superAdmin = User::factory()->create(['is_super' => true]);

        $result = $this->resolver->resolve(
            [['type' => 'role', 'value' => 'empty_role']],
            []
        );

        $this->assertCount(1, $result);
        $this->assertEquals($superAdmin->id, $result->first()->id);
    }

    public function test_role_nonexistent_falls_back_to_super_admin(): void
    {
        $superAdmin = User::factory()->create(['is_super' => true]);

        $result = $this->resolver->resolve(
            [['type' => 'role', 'value' => 'nonexistent_role']],
            []
        );

        $this->assertCount(1, $result);
        $this->assertEquals($superAdmin->id, $result->first()->id);
    }

    // ──────────────────────────────────────────────
    // specific_users 타입
    // ──────────────────────────────────────────────

    public function test_specific_users_resolves_by_uuid(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        User::factory()->create();

        $result = $this->resolver->resolve(
            [['type' => 'specific_users', 'value' => [$user1->uuid, $user2->uuid]]],
            []
        );

        $this->assertCount(2, $result);
        $this->assertTrue($result->contains('uuid', $user1->uuid));
        $this->assertTrue($result->contains('uuid', $user2->uuid));
    }

    public function test_specific_users_returns_empty_for_empty_ids(): void
    {
        $result = $this->resolver->resolve(
            [['type' => 'specific_users', 'value' => []]],
            []
        );

        $this->assertCount(0, $result);
    }

    // ──────────────────────────────────────────────
    // exclude_trigger_user 필터
    // ──────────────────────────────────────────────

    public function test_exclude_trigger_user_filters_out_trigger(): void
    {
        $triggerUser = User::factory()->create();
        $role = Role::factory()->create(['identifier' => 'admin_excl_test']);
        $admin = User::factory()->create();
        $role->users()->attach([$triggerUser->id, $admin->id]);

        $result = $this->resolver->resolve(
            [['type' => 'role', 'value' => 'admin_excl_test', 'exclude_trigger_user' => true]],
            ['trigger_user_id' => $triggerUser->id]
        );

        $this->assertCount(1, $result);
        $this->assertEquals($admin->id, $result->first()->id);
        $this->assertFalse($result->contains('id', $triggerUser->id));
    }

    public function test_no_exclude_keeps_trigger_user(): void
    {
        $triggerUser = User::factory()->create();
        $role = Role::factory()->create(['identifier' => 'admin_no_excl']);
        $role->users()->attach([$triggerUser->id]);

        $result = $this->resolver->resolve(
            [['type' => 'role', 'value' => 'admin_no_excl']],
            ['trigger_user_id' => $triggerUser->id]
        );

        $this->assertCount(1, $result);
        $this->assertEquals($triggerUser->id, $result->first()->id);
    }

    // ──────────────────────────────────────────────
    // 복합 규칙
    // ──────────────────────────────────────────────

    public function test_multiple_rules_combine_recipients(): void
    {
        $triggerUser = User::factory()->create();
        $relatedUser = User::factory()->create();

        $result = $this->resolver->resolve(
            [
                ['type' => 'trigger_user'],
                ['type' => 'related_user', 'relation' => 'author'],
            ],
            [
                'trigger_user_id' => $triggerUser->id,
                'trigger_user' => $triggerUser,
                'related_users' => ['author' => $relatedUser],
            ]
        );

        $this->assertCount(2, $result);
        $this->assertTrue($result->contains('id', $triggerUser->id));
        $this->assertTrue($result->contains('id', $relatedUser->id));
    }

    public function test_duplicate_users_are_deduplicated(): void
    {
        $user = User::factory()->create();

        $result = $this->resolver->resolve(
            [
                ['type' => 'trigger_user'],
                ['type' => 'specific_users', 'value' => [$user->uuid]],
            ],
            ['trigger_user_id' => $user->id, 'trigger_user' => $user]
        );

        $this->assertCount(1, $result);
    }

    public function test_empty_rules_returns_empty(): void
    {
        $result = $this->resolver->resolve([], []);
        $this->assertCount(0, $result);
    }

    public function test_unknown_type_is_skipped_gracefully(): void
    {
        $user = User::factory()->create();

        $result = $this->resolver->resolve(
            [
                ['type' => 'unknown_type'],
                ['type' => 'trigger_user'],
            ],
            ['trigger_user_id' => $user->id, 'trigger_user' => $user]
        );

        $this->assertCount(1, $result);
        $this->assertEquals($user->id, $result->first()->id);
    }
}
