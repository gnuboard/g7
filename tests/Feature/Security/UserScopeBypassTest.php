<?php

namespace Tests\Feature\Security;

use App\Contracts\Repositories\UserRepositoryInterface;
use App\Helpers\PermissionHelper;
use App\Models\Role;
use App\Models\User;
use App\Services\UserService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * 회원 조회 보조 경로의 self 스코프 우회 회귀 테스트 (KVE-2026-2368 + 형제)
 *
 * 배포 기본 역할 `manager` 는 `core.users.read` 를 self 스코프로 가진다 — 회원 목록에는 자기
 * 자신만 보여야 한다. 목록은 그렇게 동작했지만 같은 권한 하나로 열리는 보조 경로(최근 가입·
 * uuid 검색·통계·대시보드 회원 수)는 스코프를 걸지 않아 전체 회원의 개인정보와 회원 수를
 * 내보냈다. 각 테스트는 「주 경로(목록) = 본인만」 대조군과 같은 사용자로 보조 경로를 찔러
 * 결과가 목록과 같은 범위인지 본다. 슈퍼관리자는 전 경로에서 전체를 그대로 본다(무회귀).
 *
 * 시나리오 축·효과는 매니페스트 tests/scenarios/manager-scope-and-ceiling-bypass.yaml 참조.
 * 각 test 메서드의 `@scenario` 가 축 조합을, `@effects` 가 효과를 커버한다(메서드당 단일 조합).
 *
 * 로컬 MySQL 이 migrate:fresh DDL 에서 죽는 전례가 있어 RefreshDatabase 대신 트랜잭션으로
 * 격리하고, 코어 역할·권한은 테스트마다 시더로 심는다.
 */
class UserScopeBypassTest extends TestCase
{
    use DatabaseTransactions;

    private User $manager;

    private User $superAdmin;

    /** @var array<int, User> */
    private array $victims = [];

    /**
     * 코어 역할·권한을 심고 매니저·슈퍼관리자·피해 회원을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->clearPermissionHelperCache();

        $this->manager = $this->createUserWithRole('manager');
        $this->superAdmin = $this->createUserWithRole('admin', ['is_super' => true]);

        // 피해 회원 — 매니저보다 나중에 가입해 「최근 가입」 상단에 오도록 한다.
        foreach (range(1, 3) as $i) {
            $this->victims[] = User::factory()->create([
                'email' => "kve2368-victim-{$i}-".uniqid().'@example.test',
                'created_at' => now()->addMinutes($i),
            ]);
        }
    }

    /**
     * `PermissionHelper` 의 권한 메타 정적 캐시를 비웁니다 — 시더가 권한 행을 새로 만들므로
     * 앞 테스트의 id 가 남으면 판정이 어긋난다.
     *
     * @return void 반환값 없음
     */
    private function clearPermissionHelperCache(): void
    {
        $reflection = new \ReflectionClass(PermissionHelper::class);
        $prop = $reflection->getProperty('permissionCache');
        $prop->setAccessible(true);
        $prop->setValue(null, []);
    }

    /**
     * 시드된 역할을 가진 사용자를 만듭니다.
     *
     * @param  string  $roleIdentifier  역할 식별자
     * @param  array<string, mixed>  $attributes  사용자 속성
     * @return User 역할이 붙은 사용자
     */
    private function createUserWithRole(string $roleIdentifier, array $attributes = []): User
    {
        $user = User::factory()->create(array_merge(['status' => 'active'], $attributes));
        $role = Role::where('identifier', $roleIdentifier)->firstOrFail();
        $user->roles()->attach($role->id, ['assigned_at' => now(), 'assigned_by' => null]);

        return $user->fresh();
    }

    /**
     * 토큰 인증 헤더를 붙인 요청 빌더를 돌려줍니다.
     *
     * @param  User  $user  요청 주체
     * @return static 헤더가 붙은 테스트 인스턴스
     */
    private function asUser(User $user): static
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.$user->createToken('test-token')->plainTextToken,
            'Accept' => 'application/json',
        ]);
    }

    /**
     * 응답 본문에 든 회원 이메일 목록을 뽑습니다.
     *
     * @param  array  $rows  응답의 회원 행 목록
     * @return array<int, string> 이메일 목록
     */
    private function emailsIn(array $rows): array
    {
        return array_values(array_filter(array_map(static fn ($row) => $row['email'] ?? null, $rows)));
    }

    /**
     * 대조군 — 주 경로(회원 목록)는 self 스코프 매니저에게 본인만 준다.
     *
     * @scenario case=user_list_control
     *
     * @effects user_list_is_self_only_for_scoped_manager
     *
     * @return void 반환값 없음
     */
    public function test_user_list_shows_only_self_to_scoped_manager(): void
    {
        $response = $this->asUser($this->manager)->getJson('/api/admin/users')->assertOk();

        $this->assertSame([$this->manager->email], $this->emailsIn($response->json('data.data')));
    }

    /**
     * 최근 가입 회원 경로가 목록과 같은 범위(본인만)를 준다 — 타 회원 개인정보 0건.
     *
     * @scenario case=recent_users_scoped
     *
     * @effects recent_users_follow_list_scope
     *
     * @return void 반환값 없음
     */
    public function test_recent_users_follow_list_scope_for_scoped_manager(): void
    {
        $response = $this->asUser($this->manager)->getJson('/api/admin/users/recent')->assertOk();

        $emails = $this->emailsIn($response->json('data'));
        $this->assertSame([$this->manager->email], $emails);
        foreach ($this->victims as $victim) {
            $this->assertStringNotContainsString($victim->email, $response->getContent());
        }
    }

    /**
     * uuid 로 집은 회원 검색이 범위 밖 회원을 「없음」 으로 답한다.
     *
     * @scenario case=user_search_uuid_scoped
     *
     * @effects uuid_search_hides_out_of_scope_user
     *
     * @return void 반환값 없음
     */
    public function test_uuid_search_hides_out_of_scope_user(): void
    {
        $victim = $this->victims[0];

        $response = $this->asUser($this->manager)
            ->getJson('/api/admin/users/search?uuid='.$victim->uuid)
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->assertStringNotContainsString($victim->email, $response->getContent());
    }

    /**
     * 과차단 회귀 축 — 자기 자신의 uuid 검색은 그대로 된다.
     *
     * @scenario case=user_search_uuid_self_allowed
     *
     * @effects uuid_search_still_returns_in_scope_user
     *
     * @return void 반환값 없음
     */
    public function test_uuid_search_still_returns_self_for_scoped_manager(): void
    {
        $this->asUser($this->manager)
            ->getJson('/api/admin/users/search?uuid='.$this->manager->uuid)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', $this->manager->email);
    }

    /**
     * id 로 집는 서비스 경로도 같은 판정을 받는다 — 검색 요청 검증에 id 가 없어도 컨트롤러가
     * 받는 분기라, 확장이 검증 규칙을 훅으로 넓히면 바로 열린다.
     *
     * @scenario case=user_lookup_id_scoped
     *
     * @effects id_lookup_hides_out_of_scope_user
     *
     * @return void 반환값 없음
     */
    public function test_id_lookup_hides_out_of_scope_user(): void
    {
        $this->actingAs($this->manager);
        $service = app(UserService::class);

        $this->assertNull($service->getUserById($this->victims[0]->id));
        $this->assertNull($service->getUserByUuid($this->victims[0]->uuid));
        $this->assertSame($this->manager->id, $service->getUserById($this->manager->id)?->id);
    }

    /**
     * 회원 통계가 목록 범위(본인 1명)로 센다 — 전체 회원 수·언어 분포를 흘리지 않는다.
     *
     * @scenario case=user_statistics_scoped
     *
     * @effects user_statistics_follow_list_scope
     *
     * @return void 반환값 없음
     */
    public function test_user_statistics_follow_list_scope_for_scoped_manager(): void
    {
        $response = $this->asUser($this->manager)->getJson('/api/admin/users/statistics')->assertOk();

        $this->assertSame(1, $response->json('data.total_users'));
        $this->assertSame(1, array_sum($response->json('data.language_distribution') ?? []));
    }

    /**
     * 대시보드 회원 수 카드도 같은 통계를 읽으므로 같은 범위다.
     *
     * @scenario case=dashboard_user_count_scoped
     *
     * @effects dashboard_user_count_follows_list_scope
     *
     * @return void 반환값 없음
     */
    public function test_dashboard_user_count_follows_list_scope_for_scoped_manager(): void
    {
        $this->asUser($this->manager)
            ->getJson('/api/admin/dashboard/stats')
            ->assertOk()
            ->assertJsonPath('data.total_users.count', 1);
    }

    /**
     * 슈퍼관리자는 전 경로에서 전체 회원을 그대로 본다(무회귀).
     *
     * @scenario case=super_admin_all_user_paths
     *
     * @effects super_admin_sees_all_users_on_every_path
     *
     * @return void 반환값 없음
     */
    public function test_super_admin_still_sees_all_users_on_every_path(): void
    {
        $total = User::count();
        $victim = $this->victims[2];

        $recent = $this->asUser($this->superAdmin)->getJson('/api/admin/users/recent')->assertOk();
        $this->assertContains($victim->email, $this->emailsIn($recent->json('data')));

        $this->asUser($this->superAdmin)
            ->getJson('/api/admin/users/search?uuid='.$victim->uuid)
            ->assertOk()
            ->assertJsonPath('data.0.email', $victim->email);

        $this->asUser($this->superAdmin)
            ->getJson('/api/admin/users/statistics')
            ->assertOk()
            ->assertJsonPath('data.total_users', $total);

        $this->asUser($this->superAdmin)
            ->getJson('/api/admin/dashboard/stats')
            ->assertOk()
            ->assertJsonPath('data.total_users.count', $total);
    }

    /**
     * 사용자와 무관하게 캐시하는 사이트 전체 집계(게시판 통계 캐시)는 스코프를 끄고 센다 —
     * 스코프를 건 숫자가 공용 캐시에 앉으면 다른 방문자에게 그 매니저 기준 숫자가 나간다.
     *
     * @scenario case=global_user_count_unscoped
     *
     * @effects unscoped_global_count_for_shared_cache
     *
     * @return void 반환값 없음
     */
    public function test_unscoped_statistics_count_all_users_even_for_scoped_actor(): void
    {
        $this->actingAs($this->manager);
        $repository = app(UserRepositoryInterface::class);

        $this->assertSame(1, $repository->getStatistics()['total_users']);
        $this->assertSame(User::count(), $repository->getStatistics(false)['total_users']);
    }
}
