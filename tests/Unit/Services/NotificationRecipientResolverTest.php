<?php

namespace Tests\Unit\Services;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Notifications\GuestNotifiable;
use App\Services\NotificationRecipientResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    /**
     * 컨테이너에서 수신자 해석기를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = app(NotificationRecipientResolver::class);
    }

    // ──────────────────────────────────────────────
    // trigger_user 타입
    // ──────────────────────────────────────────────

    /**
     * 컨텍스트가 실은 유발자 모델을 그대로 수신자로 쓴다.
     *
     * @effects trigger_user_resolves_from_context
     *
     * @return void 반환값 없음
     */
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

    /**
     * 유발자 ID 만 있어도 사용자를 조회해 수신자로 쓴다.
     *
     * @effects trigger_user_resolves_from_id_only
     *
     * @return void 반환값 없음
     */
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

    /**
     * 유발자 정보가 없으면 수신자가 없다.
     *
     * @effects trigger_user_returns_empty_without_context
     *
     * @return void 반환값 없음
     */
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

    /**
     * 관련 사용자 관계가 가리키는 회원을 수신자로 쓴다.
     *
     * @effects related_user_resolves_from_context
     *
     * @return void 반환값 없음
     */
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

    /**
     * 규칙이 가리킨 관계가 컨텍스트에 없으면 수신자가 없다.
     *
     * @effects related_user_returns_empty_when_relation_missing
     *
     * @return void 반환값 없음
     */
    public function test_related_user_returns_empty_when_relation_missing(): void
    {
        $result = $this->resolver->resolve(
            [['type' => 'related_user', 'relation' => 'author']],
            ['related_users' => []]
        );

        $this->assertCount(0, $result);
    }

    /**
     * 관련 사용자 관계가 여러 명(컬렉션)이어도 모두 수신자로 담긴다.
     *
     * @effects related_user_supports_collection
     *
     * @return void 반환값 없음
     */
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

    /**
     * 역할 식별자로 그 역할의 사용자를 수신자로 모은다.
     *
     * @effects role_resolves_users_by_role_identifier
     *
     * @return void 반환값 없음
     */
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

    /**
     * 수신자 해석이 권한 판정 재료까지 함께 싣는가 (R23)
     *
     * 링크 승격은 수신자 한 사람마다 "이 사람이 관리자 화면을 열 수 있는가" 를 묻는다.
     * 그 판정이 각자 역할·권한을 다시 조회하면 쿼리가 **수신자 수에 비례해** 늘어난다 —
     * 느려질 뿐 오류가 없어 어디에도 드러나지 않는다.
     *
     * @effects role_recipients_arrive_with_their_permissions_loaded
     *
     * @return void 반환값 없음
     */
    public function test_role_recipients_arrive_with_their_permissions_loaded(): void
    {
        $role = Role::factory()->create(['identifier' => 'eager_admin']);
        $permission = Permission::factory()->create(['identifier' => 'eager.read']);
        $role->permissions()->attach($permission->id, ['granted_at' => now()]);

        $users = User::factory()->count(3)->create();
        $role->users()->attach($users->pluck('id')->all());

        $recipients = $this->resolver->resolve(
            [['type' => 'role', 'value' => 'eager_admin']],
            []
        );

        $this->assertCount(3, $recipients);

        DB::flushQueryLog();
        DB::enableQueryLog();

        foreach ($recipients as $recipient) {
            $this->assertTrue($recipient->hasPermission('eager.read'));
        }

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame(
            [],
            $queries,
            '권한 판정이 수신자마다 쿼리를 새로 냈다 — 수신자 수에 비례해 늘어난다'
        );
    }

    /**
     * 역할 다중 선택 — 값이 배열이어도 해석된다.
     *
     * 배열을 받지 못하면 `TypeError` 가 나고 `resolve()` 의 try/catch 가 그것을 삼켜
     * **수신자 0명**이 된다. 화면에도 응답에도 흔적이 없다(로그 한 줄뿐).
     *
     * @effects role_resolves_users_from_multiple_identifiers
     *
     * @return void 반환값 없음
     */
    public function test_role_resolves_users_from_multiple_identifiers(): void
    {
        $first = Role::factory()->create(['identifier' => 'multi_role_a']);
        $second = Role::factory()->create(['identifier' => 'multi_role_b']);
        $admin1 = User::factory()->create();
        $admin2 = User::factory()->create();
        $first->users()->attach([$admin1->id]);
        $second->users()->attach([$admin2->id]);

        $result = $this->resolver->resolve(
            [['type' => 'role', 'value' => ['multi_role_a', 'multi_role_b']]],
            []
        );

        $this->assertCount(2, $result);
        $this->assertTrue($result->contains('id', $admin1->id));
        $this->assertTrue($result->contains('id', $admin2->id));
    }

    /**
     * 두 역할에 함께 속한 사용자는 한 번만 담긴다 — 그대로 두면 같은 사람에게 두 통이 간다.
     *
     * @effects role_multiple_identifiers_deduplicate_shared_users
     *
     * @return void 반환값 없음
     */
    public function test_role_multiple_identifiers_deduplicate_shared_users(): void
    {
        $first = Role::factory()->create(['identifier' => 'dedup_role_a']);
        $second = Role::factory()->create(['identifier' => 'dedup_role_b']);
        $admin = User::factory()->create();
        $first->users()->attach([$admin->id]);
        $second->users()->attach([$admin->id]);

        $result = $this->resolver->resolve(
            [['type' => 'role', 'value' => ['dedup_role_a', 'dedup_role_b']]],
            []
        );

        $this->assertCount(1, $result);
        $this->assertEquals($admin->id, $result->first()->id);
    }

    /**
     * 기존 저장값(문자열 8행)은 그대로 동작해야 한다 — 마이그레이션 없이 읽기에서 수용한다.
     *
     * @effects role_scalar_value_still_resolves
     *
     * @return void 반환값 없음
     */
    public function test_role_scalar_value_still_resolves(): void
    {
        $role = Role::factory()->create(['identifier' => 'legacy_scalar_role']);
        $admin = User::factory()->create();
        $role->users()->attach([$admin->id]);

        $result = $this->resolver->resolve(
            [['type' => 'role', 'value' => 'legacy_scalar_role']],
            []
        );

        $this->assertCount(1, $result);
        $this->assertEquals($admin->id, $result->first()->id);
    }

    /**
     * 역할에 사용자가 없으면 최고관리자에게 보낸다.
     *
     * @effects role_falls_back_to_super_admin
     *
     * @return void 반환값 없음
     */
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

    /**
     * 존재하지 않는 역할이면 최고관리자에게 보낸다.
     *
     * @effects role_nonexistent_falls_back_to_super_admin
     *
     * @return void 반환값 없음
     */
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

    /**
     * 지정 사용자 UUID 목록으로 수신자를 모은다.
     *
     * @effects specific_users_resolves_by_uuid
     *
     * @return void 반환값 없음
     */
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

    /**
     * 지정 사용자 목록이 비면 수신자가 없다.
     *
     * @effects specific_users_returns_empty_for_empty_ids
     *
     * @return void 반환값 없음
     */
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

    /**
     * 유발자 제외 규칙이면 유발자를 수신자에서 뺀다.
     *
     * @effects exclude_trigger_user_filters_out_trigger
     *
     * @return void 반환값 없음
     */
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

    /**
     * 유발자 제외가 없으면 유발자도 수신자로 남는다.
     *
     * @effects no_exclude_keeps_trigger_user
     *
     * @return void 반환값 없음
     */
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

    /**
     * 규칙 여러 개의 수신자를 합친다.
     *
     * @effects multiple_rules_combine_recipients
     *
     * @return void 반환값 없음
     */
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

    /**
     * 여러 규칙이 같은 회원을 뽑아도 한 번만 담는다.
     *
     * @effects duplicate_users_are_deduplicated
     *
     * @return void 반환값 없음
     */
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

    /**
     * 규칙이 비면 수신자가 없다.
     *
     * @effects empty_rules_returns_empty
     *
     * @return void 반환값 없음
     */
    public function test_empty_rules_returns_empty(): void
    {
        $result = $this->resolver->resolve([], []);
        $this->assertCount(0, $result);
    }

    /**
     * 알 수 없는 수신자 종류는 오류 없이 건너뛴다.
     *
     * @effects unknown_type_is_skipped_gracefully
     *
     * @return void 반환값 없음
     */
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

    // ──────────────────────────────────────────────
    // 비회원(guest) trigger_user 폴백
    // ──────────────────────────────────────────────

    /**
     * 회원이 지목되지 않은 사건(비회원)은 게스트 재료로 수신자를 만든다.
     *
     * @effects trigger_user_falls_back_to_guest_recipient_when_no_user_id
     *
     * @return void 반환값 없음
     */
    public function test_trigger_user_falls_back_to_guest_recipient_when_no_user_id(): void
    {
        $result = $this->resolver->resolve(
            [['type' => 'trigger_user']],
            ['guest_recipient' => ['email' => 'guest@example.com', 'name' => '홍길동', 'locale' => 'ko']]
        );

        $this->assertCount(1, $result);
        $this->assertInstanceOf(GuestNotifiable::class, $result->first());
        $this->assertSame('guest@example.com', $result->first()->email);
    }

    /**
     * 회원이 지목되면 게스트 재료가 있어도 회원에게 보낸다.
     *
     * @effects trigger_user_prefers_member_over_guest_recipient
     *
     * @return void 반환값 없음
     */
    public function test_trigger_user_prefers_member_over_guest_recipient(): void
    {
        $user = User::factory()->create();

        $result = $this->resolver->resolve(
            [['type' => 'trigger_user']],
            [
                'trigger_user_id' => $user->id,
                'trigger_user' => $user,
                'guest_recipient' => ['email' => 'guest@example.com'],
            ]
        );

        // 회원이 있으면 게스트 폴백을 타지 않는다
        $this->assertCount(1, $result);
        $this->assertInstanceOf(User::class, $result->first());
        $this->assertEquals($user->id, $result->first()->id);
    }

    /**
     * 게스트 재료의 이메일이 비면 수신자가 없다.
     *
     * @effects invalid_guest_recipient_yields_no_recipient
     *
     * @return void 반환값 없음
     */
    public function test_invalid_guest_recipient_yields_no_recipient(): void
    {
        $result = $this->resolver->resolve(
            [['type' => 'trigger_user']],
            ['guest_recipient' => ['email' => '']]
        );

        $this->assertCount(0, $result);
    }

    /**
     * 같은 게스트 이메일은 한 명으로 합친다.
     *
     * @effects duplicate_guest_email_dedupes_to_one
     *
     * @return void 반환값 없음
     */
    public function test_duplicate_guest_email_dedupes_to_one(): void
    {
        // 같은 이메일 게스트가 두 규칙(둘 다 trigger_user)으로 들어와도 1명으로 dedupe
        $result = $this->resolver->resolve(
            [['type' => 'trigger_user'], ['type' => 'trigger_user']],
            ['guest_recipient' => ['email' => 'guest@example.com']]
        );

        $this->assertCount(1, $result);
    }

    /**
     * 회원과 게스트 수신자가 중복 제거 키 충돌 없이 함께 담긴다.
     *
     * @effects member_and_guest_coexist_without_collapsing
     *
     * @return void 반환값 없음
     */
    public function test_member_and_guest_coexist_without_collapsing(): void
    {
        $user = User::factory()->create();

        // role 규칙으로 회원, trigger_user 규칙으로 게스트 — null-id 게스트가 회원과 뭉개지지 않아야 함
        $role = Role::factory()->create(['identifier' => 'guest-coexist-role']);
        $user->roles()->attach($role->id);

        $result = $this->resolver->resolve(
            [
                ['type' => 'role', 'value' => 'guest-coexist-role'],
                ['type' => 'trigger_user'],
            ],
            ['guest_recipient' => ['email' => 'guest@example.com']]
        );

        $this->assertCount(2, $result);
    }

    /**
     * 유발자 제외는 게스트 수신자에서 오류를 내지 않는다.
     *
     * @effects exclude_trigger_user_does_not_break_on_guest
     *
     * @return void 반환값 없음
     */
    public function test_exclude_trigger_user_does_not_break_on_guest(): void
    {
        // 게스트 수신자 + exclude_trigger_user 규칙이 함께 있어도 예외 없이 통과
        $result = $this->resolver->resolve(
            [
                ['type' => 'trigger_user'],
                ['type' => 'role', 'value' => 'nonexistent-role', 'exclude_trigger_user' => true],
            ],
            ['guest_recipient' => ['email' => 'guest@example.com']]
        );

        // 게스트는 user_id 가 없어 exclude 대상이 아니므로 그대로 유지
        $this->assertGreaterThanOrEqual(1, $result->count());
        $this->assertTrue(
            $result->contains(fn ($r) => $r instanceof GuestNotifiable)
        );
    }

    // ──────────────────────────────────────────────
    // exclude_trigger_user 는 규칙 단위로 적용된다
    // ──────────────────────────────────────────────

    /**
     * 운영자가 혼합 규칙을 만들면(관리자 제외 + 고객 포함) 고객까지 지워지던 결함의 회귀 그물.
     *
     * @effects exclude_trigger_user_applies_only_to_its_own_rule
     *
     * @return void 반환값 없음
     */
    public function test_exclude_trigger_user_applies_only_to_the_rule_that_declares_it(): void
    {
        $triggerUser = User::factory()->create();
        $otherAdmin = User::factory()->create();
        $role = Role::factory()->create(['identifier' => 'admin_rule_scope']);
        $role->users()->attach([$triggerUser->id, $otherAdmin->id]);

        $result = $this->resolver->resolve(
            [
                ['type' => 'role', 'value' => 'admin_rule_scope', 'exclude_trigger_user' => true],
                ['type' => 'trigger_user'],
            ],
            ['trigger_user_id' => $triggerUser->id, 'trigger_user' => $triggerUser]
        );

        $ids = $result->pluck('id')->all();

        $this->assertContains(
            $triggerUser->id,
            $ids,
            'trigger_user 규칙은 유발자를 명시적으로 부르는 규칙이므로, 다른 규칙의 제외가 그것까지 지우면 안 됩니다.'
        );
        $this->assertContains($otherAdmin->id, $ids);
        $this->assertCount(2, $result, '중복 제거 후 두 명이어야 합니다.');
    }

    /**
     * 같은 규칙에 붙은 제외는 종전대로 그 규칙의 수집분에 적용된다.
     *
     * @effects exclude_trigger_user_still_filters_its_own_rule
     *
     * @return void 반환값 없음
     */
    public function test_exclude_trigger_user_still_filters_recipients_of_its_own_rule(): void
    {
        $triggerUser = User::factory()->create();
        $otherAdmin = User::factory()->create();
        $role = Role::factory()->create(['identifier' => 'admin_rule_scope_only']);
        $role->users()->attach([$triggerUser->id, $otherAdmin->id]);

        $result = $this->resolver->resolve(
            [['type' => 'role', 'value' => 'admin_rule_scope_only', 'exclude_trigger_user' => true]],
            ['trigger_user_id' => $triggerUser->id]
        );

        $this->assertCount(1, $result);
        $this->assertSame($otherAdmin->id, $result->first()->id);
    }

    // ──────────────────────────────────────────────
    // 삭제 회원: 계정이 사라지면 게스트 폴백으로 대신 보내지 않는다
    // ──────────────────────────────────────────────

    /**
     * 삭제 회원은 확장이 게스트 재료를 실어도 그 주소로 대신 보내지 않는다.
     *
     * @effects missing_trigger_user_does_not_fall_back_to_guest_recipient
     *
     * @return void 반환값 없음
     */
    public function test_missing_trigger_user_does_not_fall_back_to_the_guest_recipient(): void
    {
        $result = $this->resolver->resolve(
            [['type' => 'trigger_user']],
            [
                // 계정이 이미 삭제된 id — findById 가 null 을 준다
                'trigger_user_id' => 999999999,
                'guest_recipient' => ['email' => 'withdrawn@example.com', 'name' => '탈퇴회원'],
            ]
        );

        $this->assertCount(0, $result, '삭제 회원을 주문서 연락처 등 다른 주소로 대신 부르면 안 됩니다.');
    }

    /**
     * 게스트 재료가 없어도 0명이다.
     *
     * @effects missing_trigger_user_without_guest_material_stays_empty
     *
     * @return void 반환값 없음
     */
    public function test_missing_trigger_user_without_guest_material_resolves_to_nobody(): void
    {
        $result = $this->resolver->resolve(
            [['type' => 'trigger_user']],
            ['trigger_user_id' => 999999999]
        );

        $this->assertCount(0, $result);
    }
}
