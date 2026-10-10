<?php

namespace Tests\Feature\Console;

use App\Enums\ExtensionOwnerType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * `playwright:issue-token` 의 범위 접미사(`식별자:self`·`식별자:role`) 회귀 테스트.
 *
 * 위임 관리자 스코프 우회(KVE-2026-2368·2374) 를 E2E 로 잠그려면 「본인만」 범위로 권한을 가진
 * 세션이 필요하다. 접미사 없이 부여한 권한은 전체 범위라 보조 경로가 범위를 무시해도 결과가 같아
 * 결함을 재현할 수 없다. 저장된 피벗 값과 그 계정의 실효 범위로 계약을 고정한다.
 *
 * 시나리오 축·효과는 매니페스트 tests/scenarios/manager-scope-and-ceiling-bypass.yaml 참조.
 */
class PlaywrightIssueTokenScopeTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 커맨드의 옵트인 가드를 통과시킵니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        putenv('G7_PLAYWRIGHT_BYPASS=1');
        $_ENV['G7_PLAYWRIGHT_BYPASS'] = '1';
        $_SERVER['G7_PLAYWRIGHT_BYPASS'] = '1';
    }

    /**
     * 옵트인 가드 환경변수를 되돌립니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        putenv('G7_PLAYWRIGHT_BYPASS');
        unset($_ENV['G7_PLAYWRIGHT_BYPASS'], $_SERVER['G7_PLAYWRIGHT_BYPASS']);

        parent::tearDown();
    }

    /**
     * 방금 커맨드가 만든 테스트 역할을 돌려줍니다.
     *
     * @return Role 테스트 역할
     */
    private function latestTestRole(): Role
    {
        return Role::where('identifier', 'like', 'playwright_test_%')->orderByDesc('id')->firstOrFail();
    }

    /**
     * `:self` 접미사는 그 권한을 self 범위로 부여하고, 접미사가 없는 권한은 전체 범위로 남는다.
     *
     * @scenario case=e2e_token_scope_suffix
     *
     * @effects e2e_token_grants_requested_scope
     *
     * @return void 반환값 없음
     */
    public function test_scope_suffix_grants_permission_with_that_scope(): void
    {
        // 발급 커맨드는 없는 권한을 거절하고 만들지 않는다(7.1.0 O-D2) — 코어 정의 권한 두 개가 DB 에 있게 한다.
        foreach (['core.users.read', 'core.dashboard.read'] as $identifier) {
            Permission::firstOrCreate(['identifier' => $identifier], [
                'name' => ['ko' => $identifier, 'en' => $identifier],
                'description' => ['ko' => $identifier, 'en' => $identifier],
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'type' => 'admin',
            ]);
        }

        $this->artisan('playwright:issue-token', [
            '--permissions' => ['core.users.read:self', 'core.dashboard.read'],
            '--no-admin-role' => true,
            '--gc-hours' => 0,
        ])->assertSuccessful();

        $role = $this->latestTestRole();
        $scopes = $role->permissions->mapWithKeys(fn ($p) => [
            $p->identifier => $p->pivot->scope_type?->value ?? $p->pivot->scope_type,
        ])->all();

        $this->assertSame('self', $scopes['core.users.read'] ?? 'missing');
        $this->assertArrayHasKey('core.dashboard.read', $scopes);
        $this->assertNull($scopes['core.dashboard.read']);

        $user = $role->users()->firstOrFail();
        $this->assertSame('self', $user->getEffectiveScopeForPermission('core.users.read'));
        $this->assertNull($user->getEffectiveScopeForPermission('core.dashboard.read'));
    }

    /**
     * 알 수 없는 범위는 전체 범위로 조용히 넘어가지 않고 거부된다 — 오타가 전체 권한 계정을
     * 만들면 스코프 검증 spec 이 결함을 놓친다.
     *
     * @scenario case=e2e_token_scope_suffix_invalid
     *
     * @effects e2e_token_rejects_unknown_scope
     *
     * @return void 반환값 없음
     */
    public function test_unknown_scope_suffix_is_rejected(): void
    {
        $rolesBefore = Role::where('identifier', 'like', 'playwright_test_%')->count();
        $usersBefore = User::count();

        $this->expectException(\InvalidArgumentException::class);

        try {
            $this->artisan('playwright:issue-token', [
                '--permissions' => ['core.users.read:mine'],
                '--no-admin-role' => true,
                '--gc-hours' => 0,
            ])->run();
        } finally {
            // 거부는 아무것도 만들기 전에 일어난다 — 테스트 역할 없는 고아 계정이 남지 않는다.
            $this->assertSame($rolesBefore, Role::where('identifier', 'like', 'playwright_test_%')->count());
            $this->assertSame($usersBefore, User::count());
        }
    }
}
