<?php

namespace Tests\Feature\Console;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `playwright:issue-token` 커맨드의 회귀 테스트.
 *
 * 배경(Chrome MCP 정밀 점검에서 실측):
 * - D10: `Role`/`Permission` 의 `name`·`description` 은 모델에서 `array` 로 캐스팅되는데
 *   커맨드가 `json_encode(...)` 문자열을 넣어 Eloquent 가 한 번 더 인코딩했다. 그 결과
 *   관리자 회원 수정 화면의 역할 선택 목록에 `{"ko":"Playwright \uXXXX…"}` JSON 원문이
 *   그대로 노출됐다.
 * - D11: 커맨드가 호출마다 유저 1명 + 역할 1개를 만들고 회수하지 않아 역할·유저가
 *   각각 2974건 누적됐다(전체의 99%). `--gc-hours` 정리가 추가됐고, 그 정리는
 *   콜백이 순회 대상을 삭제하므로 `chunkById`(키셋) 여야 한다 — OFFSET 기반이면
 *   청크 크기(100)를 넘는 순간부터 조용히 건너뛴다.
 *
 * 소스 철자가 아니라 **DB 에 실제로 저장/삭제된 결과**로 계약을 고정한다.
 */
class PlaywrightIssueTokenTest extends TestCase
{
    use RefreshDatabase;

    /** 정리 대상 시드 규모 — GC 의 청크 크기(100)를 넘겨야 OFFSET 누락이 재현된다 */
    private const STALE_COUNT = 250;

    /** 커맨드가 붙이는 테스트 역할 접두사 */
    private const TEST_ROLE_PREFIX = 'playwright_test_';

    protected function setUp(): void
    {
        parent::setUp();

        // 커맨드의 옵트인 가드(② G7_PLAYWRIGHT_BYPASS) 통과. CLI 가드(①)는 PHPUnit 이 이미 cli.
        putenv('G7_PLAYWRIGHT_BYPASS=1');
        $_ENV['G7_PLAYWRIGHT_BYPASS'] = '1';
        $_SERVER['G7_PLAYWRIGHT_BYPASS'] = '1';
    }

    protected function tearDown(): void
    {
        putenv('G7_PLAYWRIGHT_BYPASS');
        unset($_ENV['G7_PLAYWRIGHT_BYPASS'], $_SERVER['G7_PLAYWRIGHT_BYPASS']);

        parent::tearDown();
    }

    /**
     * 테스트 역할 1건과 그 역할을 가진 유저를 생성합니다.
     *
     * @param  string  $identifier  역할 식별자
     * @param  Carbon|null  $createdAt  생성 시각 (GC 임계값 판정용)
     * @param  int  $userCount  이 역할에 붙일 유저 수
     * @return Role 생성된 역할
     */
    private function seedRoleWithUsers(string $identifier, $createdAt = null, int $userCount = 1): Role
    {
        $role = Role::create([
            'identifier' => $identifier,
            'name' => ['ko' => '시드 역할', 'en' => 'Seeded Role'],
            'description' => ['ko' => '테스트용', 'en' => 'For testing'],
            'is_active' => true,
        ]);

        if ($createdAt) {
            Role::where('id', $role->id)->update(['created_at' => $createdAt]);
        }

        for ($i = 0; $i < $userCount; $i++) {
            $user = User::factory()->create();
            $user->roles()->attach($role->id, ['assigned_at' => now(), 'assigned_by' => null]);
        }

        return $role;
    }

    // ========================================================================
    // D10 — array 캐스팅 컬럼 이중 인코딩
    // ========================================================================

    /**
     * 커맨드가 만든 역할/권한의 name·description 이 이중 인코딩되지 않아야 한다.
     */
    public function test_created_role_and_permission_are_not_double_encoded(): void
    {
        $this->artisan('playwright:issue-token', [
            '--permissions' => ['core.audit.doubleencode'],
            '--gc-hours' => 0,
        ])->assertSuccessful();

        $permission = Permission::where('identifier', 'core.audit.doubleencode')->firstOrFail();
        $role = Role::where('identifier', 'like', self::TEST_ROLE_PREFIX.'%')->firstOrFail();

        foreach ([
            ['permissions', $permission->id, $permission],
            ['roles', $role->id, $role],
        ] as [$table, $id, $model]) {
            foreach (['name', 'description'] as $column) {
                // 모델 캐스팅을 거친 값은 배열이어야 한다
                $this->assertIsArray(
                    $model->{$column},
                    "{$table}.{$column} 이 배열로 캐스팅되지 않았습니다 (이중 인코딩 의심)."
                );
                $this->assertArrayHasKey('ko', $model->{$column});

                // 원시 저장값을 1회 디코딩하면 배열이어야 한다.
                // 이중 인코딩이면 1회 디코딩 결과가 문자열(JSON) 이 된다.
                $raw = DB::table($table)->where('id', $id)->value($column);
                $decoded = json_decode((string) $raw, true);

                $this->assertIsArray(
                    $decoded,
                    "{$table}.{$column} 원시값이 이중 인코딩되었습니다 — 화면에 JSON 원문이 노출됩니다: {$raw}"
                );
                $this->assertArrayHasKey('ko', $decoded);
            }
        }
    }

    /**
     * 저장된 한글 값이 화면에서 그대로 읽히는지 (유니코드 이스케이프 원문 미노출).
     */
    public function test_created_role_name_reads_back_as_plain_text(): void
    {
        $this->artisan('playwright:issue-token', ['--gc-hours' => 0])->assertSuccessful();

        $role = Role::where('identifier', 'like', self::TEST_ROLE_PREFIX.'%')->firstOrFail();

        $this->assertSame('Playwright 테스트 관리자', $role->name['ko']);
        $this->assertStringNotContainsString('\\u', json_encode($role->name, JSON_UNESCAPED_UNICODE));
    }

    // ========================================================================
    // D11 — 오래된 테스트 아티팩트 정리 (--gc-hours)
    // ========================================================================

    /**
     * 청크 크기를 넘는 규모에서도 오래된 테스트 역할/유저가 하나도 남지 않아야 한다.
     *
     * 250건 시드 → OFFSET 기반 순회면 100건이 남는다(키셋 순회 필요).
     */
    public function test_gc_removes_all_stale_artifacts_beyond_chunk_size(): void
    {
        $stale = now()->subHours(24);
        for ($i = 0; $i < self::STALE_COUNT; $i++) {
            $this->seedRoleWithUsers(self::TEST_ROLE_PREFIX.'stale'.$i, $stale);
        }

        $this->assertSame(
            self::STALE_COUNT,
            Role::where('identifier', 'like', self::TEST_ROLE_PREFIX.'%')->count(),
            '시드 직후에는 정리 대상이 시드 건수만큼 존재해야 합니다.'
        );
        $seededUsers = User::count();

        $this->artisan('playwright:issue-token', ['--gc-hours' => 6])->assertSuccessful();

        // 커맨드가 방금 만든 역할 1건(발급용) 외에는 남으면 안 된다
        $remaining = Role::where('identifier', 'like', self::TEST_ROLE_PREFIX.'%')
            ->where('created_at', '<', now()->subHours(6))
            ->count();

        $this->assertSame(
            0,
            $remaining,
            'OFFSET 순회로 인해 정리되지 않고 남은 테스트 역할이 있습니다 (chunk → chunkById 필요).'
        );

        // 유저도 함께 회수되어야 한다 (시드 유저 250명 제거 + 커맨드가 만든 1명 추가)
        $this->assertSame(
            $seededUsers - self::STALE_COUNT + 1,
            User::count(),
            '오래된 테스트 유저가 회수되지 않았습니다.'
        );
    }

    /**
     * 임계 시간 이내의 아티팩트는 건드리면 안 된다 (동시 실행 중인 다른 워커 보호).
     */
    public function test_gc_preserves_recent_artifacts(): void
    {
        $recent = $this->seedRoleWithUsers(self::TEST_ROLE_PREFIX.'recent', now()->subHour());

        $this->artisan('playwright:issue-token', ['--gc-hours' => 6])->assertSuccessful();

        $this->assertDatabaseHas('roles', ['id' => $recent->id]);
        $this->assertSame(1, $recent->users()->count(), '동시 실행 중인 워커의 계정이 삭제되었습니다.');
    }

    /**
     * playwright 테스트 역할이 아닌 역할과 그 소속 회원은 절대 삭제되지 않아야 한다.
     */
    public function test_gc_never_touches_real_roles_and_members(): void
    {
        $realRole = $this->seedRoleWithUsers('shop_manager', now()->subYear());
        $realUserId = $realRole->users()->first()->id;

        $this->artisan('playwright:issue-token', ['--gc-hours' => 6])->assertSuccessful();

        $this->assertDatabaseHas('roles', ['id' => $realRole->id]);
        $this->assertDatabaseHas('users', ['id' => $realUserId]);
    }

    /**
     * 오래된 역할을 지우더라도, 아직 유효한 다른 테스트 역할을 가진 계정은 남겨야 한다.
     *
     * 병렬 워커가 같은 계정을 쓰는 상황에서 세션이 끊기는 것을 막는 분기다.
     */
    public function test_gc_keeps_user_that_still_holds_a_recent_test_role(): void
    {
        $staleRole = Role::create([
            'identifier' => self::TEST_ROLE_PREFIX.'stale_shared',
            'name' => ['ko' => '오래된 역할', 'en' => 'Stale Role'],
            'description' => ['ko' => '테스트용', 'en' => 'For testing'],
            'is_active' => true,
        ]);
        Role::where('id', $staleRole->id)->update(['created_at' => now()->subHours(24)]);

        $recentRole = Role::create([
            'identifier' => self::TEST_ROLE_PREFIX.'recent_shared',
            'name' => ['ko' => '최근 역할', 'en' => 'Recent Role'],
            'description' => ['ko' => '테스트용', 'en' => 'For testing'],
            'is_active' => true,
        ]);

        $user = User::factory()->create();
        $user->roles()->attach($staleRole->id, ['assigned_at' => now(), 'assigned_by' => null]);
        $user->roles()->attach($recentRole->id, ['assigned_at' => now(), 'assigned_by' => null]);

        $this->artisan('playwright:issue-token', ['--gc-hours' => 6])->assertSuccessful();

        $this->assertDatabaseMissing('roles', ['id' => $staleRole->id]);
        $this->assertDatabaseHas('roles', ['id' => $recentRole->id]);
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    /**
     * `--gc-hours=0` 이면 정리를 수행하지 않는다 (옵트아웃).
     */
    public function test_gc_is_skipped_when_disabled(): void
    {
        $stale = $this->seedRoleWithUsers(self::TEST_ROLE_PREFIX.'stale_disabled', now()->subHours(24));

        $this->artisan('playwright:issue-token', ['--gc-hours' => 0])->assertSuccessful();

        $this->assertDatabaseHas('roles', ['id' => $stale->id]);
    }
}
