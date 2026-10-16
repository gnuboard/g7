<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Security;

require_once __DIR__.'/../../ModuleTestCase.php';

use App\Helpers\PermissionHelper;
use App\Models\Permission;
use App\Support\ScopedPermissionEnforcement;
use Illuminate\Support\Facades\Route;
use Modules\Sirsoft\Board\Listeners\BoardScopeLedgerListener;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Module;
use Modules\Sirsoft\Board\Services\BoardPermissionService;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * 게시판 스코프 강제 지점 원장 감사 + 래칫 (#789 §6.4)
 *
 * 스코프 메타(`resource_route_key`·`owner_key`)가 있는 권한을 거는 라우트가 그 모델을 바인딩하지 않으면
 * 미들웨어의 스코프 검사가 돌지 않는다. 그런 라우트는 원장(`BoardScopeLedgerListener`)에 등록하고,
 * 원장에 적은 강제 메서드가 실제로 스코프를 판정해야 한다. 새 라우트·이름 바뀐 메서드는 이 테스트가 잡는다.
 *
 * 래칫: 게시판 관리 권한을 `PermissionHelper::check()` 로 직접 보는 코드는 0건이어야 한다(게시판 스코프를
 * 건너뛴다 — `ChecksBoardPermission` 을 거친다). 비밀글 조회 권한을 보유만으로 보는 판정은 화면 표시용
 * 권한 맵 두 곳에서만 허용한다(본문 판정은 `SecretContentGate` 의 `checkBoardPermissionFor`).
 */
#[Group('board')]
#[Group('security')]
class BoardScopedRouteLedgerTest extends ModuleTestCase
{
    /**
     * 비밀글 조회 권한을 보유만으로 판정해도 되는 위치(화면 표시용 권한 맵) — 본문 열람 판정이 아니다.
     */
    private const READ_SECRET_HOLD_ONLY_ALLOWLIST = [
        'src/Http/Resources/PostCollection.php',
    ];

    /**
     * 게시판 모듈의 라우트 중 스코프 대상 권한을 거는데 바인딩도 원장 등록도 없는 라우트가 없다.
     *
     * @scenario case=board_routes_have_no_unledgered_scope_gap
     *
     * @effects board_routes_have_no_unledgered_scope_gap
     *
     * @return void 반환값 없음
     */
    public function test_board_routes_have_no_unbound_unledgered_scoped_permission(): void
    {
        $board = $this->seedScopedPermissions();

        $violations = ScopedPermissionEnforcement::auditRoutes(Route::getRoutes(), 'sirsoft-board.', ['slug' => [$board->slug]]);

        $this->assertSame([], $violations, json_encode($violations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * 원장에 적은 강제 메서드가 모두 존재하고 스코프를 실제로 판정한다.
     *
     * @scenario case=board_ledger_enforcers_exist_and_check_scope
     *
     * @effects board_ledger_enforcers_exist_and_check_scope
     *
     * @return void 반환값 없음
     */
    public function test_board_ledger_enforcers_exist_and_check_scope(): void
    {
        $violations = ScopedPermissionEnforcement::auditEnforcers('sirsoft-board.');

        $this->assertSame([], $violations, json_encode($violations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * 원장 리스너가 모듈 훅 리스너로 등록돼 있고 필터 훅으로 게시판 항목을 더한다.
     *
     * @scenario case=board_ledger_listener_registered
     *
     * @effects board_ledger_listener_registered
     *
     * @return void 반환값 없음
     */
    public function test_ledger_listener_is_registered_and_contributes_entries(): void
    {
        $this->assertContains(BoardScopeLedgerListener::class, (new Module)->getHookListeners());
        $this->assertNotNull(ScopedPermissionEnforcement::ledgerEntry('sirsoft-board.{slug}.admin.manage'));
        $this->assertNotNull(ScopedPermissionEnforcement::ledgerEntry('sirsoft-board.notice.posts.read'));
    }

    /**
     * 래칫 — 게시판 관리 권한(manager·admin.manage)을 PermissionHelper::check 로 직접 보는 코드가 없다.
     *
     * @scenario case=board_manage_permission_never_checked_without_board_scope
     *
     * @effects board_manage_permission_never_checked_without_board_scope
     *
     * @return void 반환값 없음
     */
    public function test_ratchet_no_direct_manage_permission_check(): void
    {
        $offenders = [];

        foreach ($this->sourceFiles() as $relative => $source) {
            if (preg_match_all('/PermissionHelper::check\(\s*["\']sirsoft-board\.\{?\$?[\w>-]+\}?\.(manager|admin\.manage)["\']/', $source, $m) > 0) {
                $offenders[] = $relative;
            }
        }

        $this->assertSame([], $offenders, '게시판 관리 권한은 ChecksBoardPermission(게시판 스코프 포함)으로 판정한다');
    }

    /**
     * 래칫 — 비밀글 조회 권한을 보유만으로 보는 checkBoardPermission 호출은 허용 목록에서만 쓴다.
     *
     * @scenario case=read_secret_hold_only_check_confined_to_allowlist
     *
     * @effects read_secret_hold_only_check_confined_to_allowlist
     *
     * @return void 반환값 없음
     */
    public function test_ratchet_read_secret_hold_only_check_is_confined(): void
    {
        $offenders = [];

        foreach ($this->sourceFiles() as $relative => $source) {
            if (in_array($relative, self::READ_SECRET_HOLD_ONLY_ALLOWLIST, true)) {
                continue;
            }

            if (preg_match('/checkBoardPermission\(\s*\$\w+\s*,\s*[\'"](admin\.)?posts\.read-secret[\'"]/', $source) === 1) {
                $offenders[] = $relative;
            }
        }

        $this->assertSame([], $offenders, '비밀글 본문 판정은 SecretContentGate 의 checkBoardPermissionFor(스코프 포함)로 한다');
    }

    /**
     * 게시판 하나와 그 게시판 권한·모듈 단위 권한을 스코프 메타와 함께 만듭니다 (감사가 DB 메타를 읽는다).
     *
     * @return Board 만든 게시판
     */
    private function seedScopedPermissions(): Board
    {
        PermissionHelper::clearPermissionScopeCache();

        $board = Board::updateOrCreate(
            ['slug' => 'ledger-audit'],
            ['name' => ['ko' => '원장 감사', 'en' => 'Ledger Audit'], 'is_active' => true, 'blocked_keywords' => []]
        );
        app(BoardPermissionService::class)->ensureBoardPermissions($board);

        foreach ((new Module)->getPermissions()['categories'] ?? [] as $category) {
            foreach ($category['permissions'] ?? [] as $permission) {
                Permission::updateOrCreate(
                    ['identifier' => 'sirsoft-board.'.$category['identifier'].'.'.$permission['action']],
                    [
                        'name' => $permission['name'] ?? ['ko' => $permission['action'], 'en' => $permission['action']],
                        'type' => $permission['type'] ?? 'admin',
                        'resource_route_key' => $category['resource_route_key'] ?? null,
                        'owner_key' => $category['owner_key'] ?? null,
                    ]
                );
            }
        }

        PermissionHelper::clearPermissionScopeCache();

        return $board;
    }

    /**
     * 게시판 모듈 src 의 PHP 소스를 상대 경로 ⇒ 본문으로 돌려줍니다.
     *
     * @return array<string, string> 상대 경로 ⇒ 소스
     */
    private function sourceFiles(): array
    {
        $base = $this->getModuleBasePath();
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base.'/src', \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php' || str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'lang'.DIRECTORY_SEPARATOR)) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($base) + 1));
            $files[$relative] = (string) file_get_contents($file->getPathname());
        }

        return $files;
    }
}
