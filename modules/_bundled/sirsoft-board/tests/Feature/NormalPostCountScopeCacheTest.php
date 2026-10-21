<?php

namespace Modules\Sirsoft\Board\Tests\Feature;

use App\Helpers\PermissionHelper;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Services\PostService;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 일반 글 수 캐시를 목록 맥락·권한 범위로 나눈다 (#147 같은 결함군)
 *
 * 키에 권한 범위가 없어 「본인만」·「소유역할」 회원과 전체 범위 사용자가 같은 건수를 썼다 — 먼저 목록을 연
 * 사람의 건수가 다른 사람에게 보였다. 관리자·사용자 맥락도 같은 키였다.
 *
 * 축 case 의 케이스 표시는 테스트 메서드마다 단다.
 */
class NormalPostCountScopeCacheTest extends BoardTestCase
{
    private User $selfUser;

    private User $roleUser;

    private User $roleMate;

    private User $fullUser;

    /**
     * 범위별 열람자와 원글 25개를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        PermissionHelper::clearPermissionScopeCache();

        $slug = $this->board->slug;
        $userRoleId = Role::where('identifier', 'user')->value('id');

        // posts.read 에 범위 메타(owner_key)를 단다 — 실제 게시판 권한과 같다
        $read = Permission::updateOrCreate(
            ['identifier' => "sirsoft-board.{$slug}.posts.read"],
            ['name' => ['ko' => '읽기', 'en' => 'read'], 'type' => 'user', 'resource_route_key' => 'post', 'owner_key' => 'user_id']
        );
        $adminRead = Permission::updateOrCreate(
            ['identifier' => "sirsoft-board.{$slug}.admin.posts.read"],
            ['name' => ['ko' => '관리 읽기', 'en' => 'admin read'], 'type' => 'admin', 'resource_route_key' => 'post', 'owner_key' => 'user_id']
        );

        // 본인만: 기본 회원 역할
        DB::table('role_permissions')->updateOrInsert(
            ['role_id' => $userRoleId, 'permission_id' => $read->id],
            ['scope_type' => 'self', 'granted_at' => now(), 'created_at' => now(), 'updated_at' => now()]
        );
        $this->selfUser = User::factory()->create();
        $this->selfUser->roles()->attach($userRoleId);

        // 소유역할: 같은 역할 두 사람
        $roleReader = Role::create(['identifier' => 'nc_scope_role_reader', 'name' => ['ko' => '역할 열람', 'en' => 'role reader'], 'is_active' => true]);
        $roleReader->permissions()->attach($read->id, ['scope_type' => 'role']);
        $this->roleUser = User::factory()->create();
        $this->roleMate = User::factory()->create();
        $this->roleUser->roles()->attach($roleReader->id);
        $this->roleMate->roles()->attach($roleReader->id);

        // 전체 범위: 사용자 목록은 전체, 관리자 목록은 본인만
        $fullReader = Role::create(['identifier' => 'nc_scope_full_reader', 'name' => ['ko' => '전체 열람', 'en' => 'full reader'], 'is_active' => true]);
        $fullReader->permissions()->attach($read->id, ['scope_type' => null]);
        $fullReader->permissions()->attach($adminRead->id, ['scope_type' => 'self']);
        $this->fullUser = User::factory()->create();
        $this->fullUser->roles()->attach($fullReader->id);

        // 원글 25개: 본인만 회원 3 · 역할 2(각 1) · 전체 범위 회원 1 · 그 외 19
        foreach (range(1, 25) as $i) {
            $owner = match (true) {
                $i <= 3 => $this->selfUser->id,
                $i === 4 => $this->roleUser->id,
                $i === 5 => $this->roleMate->id,
                $i === 6 => $this->fullUser->id,
                default => null,
            };
            $this->createTestPost(['title' => "글 {$i}", 'user_id' => $owner]);
        }
    }

    /**
     * 「본인만」 회원이 먼저 열어도 전체 범위 사용자·비회원은 전체 건수를 받는다.
     *
     * @scenario case=self_then_full_then_guest
     *
     * @effects scoped_viewer_gets_own_count, full_viewer_unaffected_by_order
     *
     * @return void 반환값 없음
     */
    public function test_scoped_viewer_first_does_not_leak_count_to_others(): void
    {
        $this->assertSame(3, $this->countAs($this->selfUser, 'user'), '본인만 회원은 자기 글 수');
        $this->assertSame(25, $this->countAs($this->fullUser, 'user'), '전체 범위 회원은 전체 수 — 앞 사람의 3 이 새면 안 된다');
        $this->assertSame(25, $this->countAs(null, 'user'), '비회원도 전체 수');
    }

    /**
     * 전체 범위 사용자가 먼저 열어도 범위 회원은 자기 범위 건수를 받는다.
     *
     * @scenario case=guest_then_full_then_self
     *
     * @effects scoped_viewer_gets_own_count, full_viewer_unaffected_by_order
     *
     * @return void 반환값 없음
     */
    public function test_full_viewer_first_does_not_leak_count_to_scoped_viewers(): void
    {
        $this->assertSame(25, $this->countAs(null, 'user'));
        $this->assertSame(25, $this->countAs($this->fullUser, 'user'));
        $this->assertSame(3, $this->countAs($this->selfUser, 'user'), '본인만 회원이 전체 수 25 를 받으면 안 된다');
    }

    /**
     * 전체 범위 회원이 먼저 열어도 소유역할 회원은 같은 역할 회원의 글 수를 받는다.
     *
     * @scenario case=full_then_role
     *
     * @effects scoped_viewer_gets_own_count
     *
     * @return void 반환값 없음
     */
    public function test_full_viewer_first_does_not_leak_count_to_role_viewer(): void
    {
        $this->assertSame(25, $this->countAs($this->fullUser, 'user'));
        $this->assertSame(2, $this->countAs($this->roleUser, 'user'), '소유역할 회원은 같은 역할 회원 글 수');
    }

    /**
     * 같은 「본인만」 범위라도 사람마다 키가 나뉜다.
     *
     * @scenario case=role_then_self_then_mate
     *
     * @effects scoped_viewer_gets_own_count
     *
     * @return void 반환값 없음
     */
    public function test_scoped_viewers_do_not_share_each_others_count(): void
    {
        $this->assertSame(2, $this->countAs($this->roleUser, 'user'));
        $this->assertSame(3, $this->countAs($this->selfUser, 'user'));
        $this->assertSame(2, $this->countAs($this->roleMate, 'user'), '같은 역할 회원은 같은 수');
    }

    /**
     * 관리자 맥락과 사용자 맥락은 다른 키를 쓴다.
     *
     * @scenario case=admin_then_user
     *
     * @effects admin_user_contexts_split
     *
     * @return void 반환값 없음
     */
    public function test_admin_and_user_contexts_are_cached_separately(): void
    {
        $this->assertSame(1, $this->countAs($this->fullUser, 'admin'), '관리자 목록은 본인만 범위 — 자기 글 1');
        $this->assertSame(25, $this->countAs($this->fullUser, 'user'), '사용자 목록은 전체 범위 — 관리자 값 1 이 새면 안 된다');
        $this->assertSame(1, $this->countAs($this->fullUser, 'admin'));
    }

    /**
     * 주어진 사람으로 일반 글 수 캐시를 읽습니다.
     *
     * @param  User|null  $user  열람자 (null 이면 비회원)
     * @param  string  $context  목록 맥락 (admin 또는 user)
     * @return int 일반 글 수
     */
    private function countAs(?User $user, string $context): int
    {
        Auth::forgetGuards();
        if ($user !== null) {
            Auth::setUser($user->fresh());
        }

        return app(PostService::class)
            ->getCachedNormalPostCount($this->board->slug, $this->board->id, [], false, $context)
            ->total;
    }
}
