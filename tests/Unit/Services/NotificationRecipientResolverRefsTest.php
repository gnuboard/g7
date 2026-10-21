<?php

namespace Tests\Unit\Services;

use App\Enums\ExtensionOwnerType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Notifications\GuestNotifiable;
use App\Services\NotificationRecipientResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 알림 수신자 참조 해석 테스트 (#139)
 *
 * resolveRefs() 는 resolve() 와 같은 규칙·같은 중복 제거 키를 쓰되 회원 모델 대신 id 만 뽑는다 —
 * 전 회원 역할이어도 모델·권한을 한꺼번에 싣지 않고 결정적 순서로 나눌 수 있어야 한다.
 */
class NotificationRecipientResolverRefsTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 모든 규칙 유형에서 resolveRefs 의 키 집합이 resolve 와 같다(유발자 제외 포함).
     *
     * @scenario recipients=within_chunk, rerun=none
     *
     * @effects refs_match_models
     *
     * @return void 반환값 없음
     */
    public function test_resolve_refs_matches_resolve_for_every_rule_type(): void
    {
        $roleUsers = User::factory()->count(3)->create();
        $role = $this->role('qa139_refs_role', $roleUsers);
        $permUsers = User::factory()->count(2)->create();
        $permRole = $this->role('qa139_refs_perm_role', $permUsers);
        $permRole->permissions()->sync([$this->permission('qa139.refs.perm')->id]);
        $specific = User::factory()->count(2)->create();
        $trigger = $roleUsers[0];
        $related = User::factory()->create();

        $memberContext = ['trigger_user_id' => $trigger->id, 'related_users' => ['author' => $related, 'watchers' => collect([$specific[0], $related])]];
        $guestContext = ['guest_recipient' => ['email' => 'guest139@example.test', 'name' => 'G', 'locale' => 'ko']];

        $cases = [
            [[['type' => 'role', 'value' => $role->identifier]], $memberContext],
            [[['type' => 'role', 'value' => [$role->identifier, $permRole->identifier], 'exclude_trigger_user' => true]], $memberContext],
            [[['type' => 'permission', 'value' => 'qa139.refs.perm']], $memberContext],
            [[['type' => 'specific_users', 'value' => $specific->pluck('uuid')->all()]], $memberContext],
            [[['type' => 'trigger_user']], $memberContext],
            [[['type' => 'trigger_user']], $guestContext],
            [[['type' => 'related_user', 'relation' => 'author'], ['type' => 'related_user', 'relation' => 'watchers']], $memberContext],
            [[['type' => 'role', 'value' => $role->identifier, 'exclude_trigger_user' => true], ['type' => 'trigger_user'], ['type' => 'specific_users', 'value' => [$roleUsers[1]->uuid]]], $memberContext],
        ];

        $resolver = app(NotificationRecipientResolver::class);
        foreach ($cases as $i => [$rules, $context]) {
            $expected = $resolver->resolve($rules, $context)
                ->map(fn ($r) => $r instanceof GuestNotifiable ? $r->getKey() : 'user:'.$r->id)
                ->sort()->values()->all();
            $actual = collect($resolver->resolveRefs($rules, $context))->pluck('key')->sort()->values()->all();

            $this->assertSame($expected, $actual, "case {$i}");
        }
    }

    /**
     * 역할 수신자를 참조로 뽑을 때 회원 행·권한 행을 읽지 않는다.
     *
     * @scenario recipients=over_chunk, rerun=none
     *
     * @effects child_payload_carries_refs
     *
     * @return void 반환값 없음
     */
    public function test_resolve_refs_does_not_hydrate_role_members(): void
    {
        $users = User::factory()->count(5)->create();
        $role = $this->role('qa139_refs_hydrate', $users);
        $usersTable = (new User)->getTable();

        DB::enableQueryLog();
        $refs = app(NotificationRecipientResolver::class)->resolveRefs([['type' => 'role', 'value' => $role->identifier]], []);
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        $this->assertCount(5, $refs);
        $this->assertSame(0, $queries->filter(fn ($q) => str_contains($q, 'from `'.DB::getTablePrefix().$usersTable.'`') || str_contains($q, 'from `'.$usersTable.'`'))->count(), $queries->implode("\n"));
        $this->assertSame(0, $queries->filter(fn ($q) => str_contains($q, 'role_permissions'))->count());
    }

    /**
     * 모르는 규칙 타입은 참조로 바꾸지 않고 null 을 돌려 종전 resolve() 인라인 경로로 보낸다.
     *
     * @scenario recipients=within_chunk, rerun=none
     *
     * @effects refs_match_models
     *
     * @return void 반환값 없음
     */
    public function test_unknown_rule_type_falls_back_to_inline_resolve(): void
    {
        $refs = app(NotificationRecipientResolver::class)->resolveRefs([['type' => 'qa139_future_type', 'value' => 'x']], []);

        $this->assertNull($refs, '참조 해석이 모르는 규칙 타입을 빈 목록으로 삼키면 분할 발송에서 수신자가 조용히 빠진다');
    }

    /**
     * 역할에 회원이 없으면 resolve 처럼 슈퍼 관리자 참조로 폴백한다.
     *
     * @scenario recipients=within_chunk, rerun=none
     *
     * @effects refs_match_models
     *
     * @return void 반환값 없음
     */
    public function test_empty_role_falls_back_to_super_admin_ref(): void
    {
        $role = $this->role('qa139_refs_empty', collect());
        $superAdmin = User::query()->where('is_super', true)->orderBy('id')->first() ?? User::factory()->create(['is_super' => true]);

        $refs = app(NotificationRecipientResolver::class)->resolveRefs([['type' => 'role', 'value' => $role->identifier]], []);

        $this->assertSame(['user:'.$superAdmin->id], array_column($refs, 'key'));
    }

    /**
     * 참조는 수신자 키의 자연 정렬이라 같은 입력이면 같은 순서로 나뉜다.
     *
     * @scenario recipients=over_chunk, rerun=none
     *
     * @effects deterministic_child_chunks
     *
     * @return void 반환값 없음
     */
    public function test_refs_are_sorted_deterministically(): void
    {
        $users = User::factory()->count(12)->create();
        $role = $this->role('qa139_refs_sort', $users->shuffle());
        $resolver = app(NotificationRecipientResolver::class);

        $first = array_column($resolver->resolveRefs([['type' => 'role', 'value' => $role->identifier]], []), 'key');
        $second = array_column($resolver->resolveRefs([['type' => 'role', 'value' => $role->identifier]], []), 'key');
        $natural = $first;
        usort($natural, 'strnatcmp');

        $this->assertSame($first, $second);
        $this->assertSame($natural, $first);
    }

    /**
     * 역할을 만들고 회원을 붙입니다.
     *
     * @param  string  $identifier  역할 식별자
     * @param  iterable<int, User>  $users  회원
     * @return Role 역할
     */
    private function role(string $identifier, iterable $users): Role
    {
        $role = Role::create([
            'identifier' => $identifier,
            'name' => ['ko' => $identifier, 'en' => $identifier],
            'description' => ['ko' => $identifier, 'en' => $identifier],
            'is_active' => true,
        ]);
        foreach ($users as $user) {
            $user->roles()->attach($role->id, ['assigned_at' => now(), 'assigned_by' => null]);
        }

        return $role;
    }

    /**
     * 권한을 만듭니다.
     *
     * @param  string  $identifier  권한 식별자
     * @return Permission 권한
     */
    private function permission(string $identifier): Permission
    {
        return Permission::firstOrCreate(['identifier' => $identifier], [
            'name' => ['ko' => $identifier, 'en' => $identifier],
            'description' => ['ko' => $identifier, 'en' => $identifier],
            'extension_type' => ExtensionOwnerType::Core,
            'extension_identifier' => 'core',
            'type' => 'admin',
        ]);
    }
}
