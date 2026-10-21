<?php

namespace Modules\Sirsoft\Board\Tests\Feature;

require_once __DIR__.'/../ModuleTestCase.php';

use App\Contracts\Extension\ExtensionMiddlewareRegistryInterface;
use App\Extension\ExtensionMiddlewareRegistry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Modules\Sirsoft\Board\Http\Middleware\EnsureBoardExists;
use Modules\Sirsoft\Board\Tests\BoardTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * 없는 게시판 주소가 404 로 응답하는지 검증합니다 (7.1.1 출시 전 점검).
 *
 * 게시판 하위 API 는 `permission:…,sirsoft-board.{slug}.…` 로 게시판별 동적 권한을 본다. 없는 게시판이면
 * 그 권한도 없어서 권한 미들웨어가 비회원 401·회원/관리자 403 을 돌려, 화면에 「권한이 없습니다」·
 * 「로그인이 필요한 페이지입니다」가 떴다(`GET /boards/{slug}` 만 404). 게시판 slug 는 공개 주소라
 * 존재를 감출 이유가 없으므로 권한 판정보다 먼저 404 로 끝낸다. 대조군: 있는 게시판은 지금처럼 권한 판정을 받는다.
 * 존재 확인은 모듈 `getMiddleware()` self-gate(api 그룹 after_core)로 붙는다 — 라우트의 인증·관리자 판정보다 앞이라
 * 관리자 API 도 없는 게시판이면 비회원·관리자 아닌 회원에게 404 다(2026-10-18 결정, 있는 게시판은 401·403 유지).
 *
 * 시나리오 축·효과는 매니페스트 tests/scenarios/board-missing-board-not-found.yaml 참조.
 */
#[Group('board')]
class MissingBoardNotFoundTest extends BoardTestCase
{
    private const MISSING_SLUG = 'f22-missing-board';

    /**
     * 테스트 게시판과 「없는 게시판」 전제를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        DB::table('boards')->where('slug', self::MISSING_SLUG)->delete();
        // 비회원은 테스트 게시판을 읽지 못한다 — 있는 게시판의 대조군이 401 로 남는지 본다
        $this->setGuestPermissions([]);
    }

    /**
     * 관리자 화면에 들어올 수 있는(관리자 권한 하나를 가진) 관리자를 만듭니다. 게시판별 권한은 없다.
     *
     * 관리자 판정(isAdmin)은 관리자 유형 권한 보유로 정해진다 — 권한 없는 admin 역할만으로는
     * 관리자 미들웨어에서 403 이 먼저 나가 존재 확인까지 가지 못한다.
     *
     * @return User 관리자
     */
    private function createBoardAdmin(): User
    {
        return $this->createAdminUser(['sirsoft-board.boards.read']);
    }

    /**
     * 사용자 API 7종(목록·상세·폼 데이터·폼 메타·댓글·이전/다음·첨부 다운로드)의 주소를 돌려줍니다.
     *
     * @return array<string, array{0: string}> 사례 이름 => 하위 경로({slug} 자리표시)
     */
    public static function userEndpoints(): array
    {
        return [
            'posts.index' => ['/api/modules/sirsoft-board/boards/{slug}/posts'],
            'posts.show' => ['/api/modules/sirsoft-board/boards/{slug}/posts/1'],
            'posts.form-data' => ['/api/modules/sirsoft-board/boards/{slug}/posts/form-data'],
            'posts.form-meta' => ['/api/modules/sirsoft-board/boards/{slug}/posts/form-meta'],
            'posts.comments.index' => ['/api/modules/sirsoft-board/boards/{slug}/posts/1/comments'],
            'posts.navigation' => ['/api/modules/sirsoft-board/boards/{slug}/posts/1/navigation'],
            'attachment.download' => ['/api/modules/sirsoft-board/boards/{slug}/attachment/abcdef123456'],
        ];
    }

    /**
     * 관리자 API 3종(글 목록·상세·폼 데이터)의 주소를 돌려줍니다.
     *
     * @return array<string, array{0: string}> 사례 이름 => 하위 경로({slug} 자리표시)
     */
    public static function adminEndpoints(): array
    {
        return [
            'admin.posts.index' => ['/api/modules/sirsoft-board/admin/board/{slug}/posts'],
            'admin.posts.show' => ['/api/modules/sirsoft-board/admin/board/{slug}/posts/1'],
            'admin.posts.form-data' => ['/api/modules/sirsoft-board/admin/board/{slug}/posts/form-data'],
        ];
    }

    /**
     * 비회원·회원이 없는 게시판의 사용자 API 를 부르면 404 다.
     *
     * @scenario case=missing_board_user_api_404
     *
     * @effects missing_board_user_api_404
     *
     * @param  string  $path  하위 경로
     * @return void 반환값 없음
     */
    #[DataProvider('userEndpoints')]
    public function test_missing_board_user_api_returns_404(string $path): void
    {
        $url = str_replace('{slug}', self::MISSING_SLUG, $path);

        $this->getJson($url)->assertStatus(404);
        $this->actingAs($this->createUser(), 'sanctum')->getJson($url)->assertStatus(404);
    }

    /**
     * 없는 게시판의 관리자 글 API 는 관리자·권한 판정보다 먼저 404 다 — 관리자와 관리자 아닌 회원 모두.
     *
     * 존재 확인은 self-gate(api 그룹 after_core)라 라우트의 `admin`·`permission` 보다 먼저 돈다. 다만 로그인 판정
     * (`auth:sanctum`)은 라라벨 미들웨어 우선순위(AuthenticatesRequests 가 그룹의 SubstituteBindings 보다 앞)로
     * 그룹 미들웨어보다 앞으로 옮겨지므로, 비회원은 게시판이 없어도 401 이다. 게시판 slug 는 공개 주소라 어느 쪽이든
     * 노출 문제가 없다(2026-10-18 결정은 비회원 404 도 허용).
     *
     * @scenario case=missing_board_admin_api_404
     *
     * @effects missing_board_admin_api_404, admin_guest_login_first
     *
     * @param  string  $path  하위 경로
     * @return void 반환값 없음
     */
    #[DataProvider('adminEndpoints')]
    public function test_missing_board_admin_api_returns_404(string $path): void
    {
        $url = str_replace('{slug}', self::MISSING_SLUG, $path);

        // 로그인 판정은 우선순위로 존재 확인보다 앞 — 비회원은 401
        $this->getJson($url)->assertStatus(401);

        $this->actingAs($this->createBoardAdmin(), 'sanctum')->getJson($url)->assertStatus(404);
        $this->actingAs($this->createUser(), 'sanctum')->getJson($url)->assertStatus(404);
    }

    /**
     * 있는 게시판의 관리자 API 는 지금처럼 비회원 401·관리자 아닌 회원 403 이다(대조군).
     *
     * @scenario case=existing_board_admin_gate_kept
     *
     * @effects admin_gate_kept_for_existing_board
     *
     * @return void 반환값 없음
     */
    public function test_existing_board_admin_api_keeps_login_and_admin_gate(): void
    {
        $url = '/api/modules/sirsoft-board/admin/board/'.$this->board->slug.'/posts';

        $this->getJson($url)->assertStatus(401);
        $this->actingAs($this->createUser(), 'sanctum')->getJson($url)->assertStatus(403);
    }

    /**
     * 회원 전용 그룹(신고·반응)도 없는 게시판이면 404 다.
     *
     * @scenario case=missing_board_member_only_api_404
     *
     * @effects missing_board_user_api_404
     *
     * @return void 반환값 없음
     */
    public function test_missing_board_member_only_api_returns_404(): void
    {
        $this->actingAs($this->createUser(), 'sanctum')
            ->postJson('/api/modules/sirsoft-board/boards/'.self::MISSING_SLUG.'/posts/1/reports', ['reason_type' => 'spam'])
            ->assertStatus(404);
    }

    /**
     * 있는 게시판은 지금처럼 권한 판정을 받는다 — 비회원 401, 게시판 권한 없는 관리자 403 (대조군).
     *
     * @scenario case=existing_board_permission_kept
     *
     * @effects existing_board_permission_kept
     *
     * @return void 반환값 없음
     */
    public function test_existing_board_still_goes_through_permission(): void
    {
        $slug = $this->board->slug;

        $this->getJson("/api/modules/sirsoft-board/boards/{$slug}/posts")->assertStatus(401);
        $this->actingAs($this->createBoardAdmin(), 'sanctum')
            ->getJson("/api/modules/sirsoft-board/admin/board/{$slug}/posts")
            ->assertStatus(403);
    }

    /**
     * 같은 라우트명 서브트리에 있지만 `{slug}` 가 없는 라우트(최근글)는 존재 확인 없이 지나간다.
     *
     * @scenario case=slugless_route_in_subtree_passes
     *
     * @effects slugless_route_passes
     *
     * @return void 반환값 없음
     */
    public function test_slugless_route_in_target_subtree_is_not_blocked(): void
    {
        $this->getJson('/api/modules/sirsoft-board/boards/posts/recent?limit=5')->assertOk();
    }

    /**
     * 게시판별 권한(`sirsoft-board.{slug}.*`)을 보는 라우트는 모두 self-gate 가 존재 확인을 붙인다(새 라우트 래칫).
     *
     * 모집단을 라우트 테이블에서 파생한다 — 새 게시판 라우트를 targets 밖 이름으로 추가하면 손대지 않아도 실패한다.
     * 라우트 파일에 FQCN 으로 직접 붙이지 않는다(확장 미들웨어 선언 규정).
     *
     * @scenario case=slug_routes_check_existence_before_permission
     *
     * @effects slug_routes_check_existence_before_permission
     *
     * @return void 반환값 없음
     */
    public function test_every_board_permission_route_checks_existence_first(): void
    {
        ExtensionMiddlewareRegistry::flush();
        $registry = $this->app->make(ExtensionMiddlewareRegistryInterface::class);
        $checked = 0;

        foreach (Route::getRoutes() as $route) {
            $middleware = $route->gatherMiddleware();
            $boardPermission = collect($middleware)->contains(
                fn ($name) => is_string($name) && str_starts_with($name, 'permission:') && str_contains($name, 'sirsoft-board.{slug}.')
            );

            if (! $boardPermission) {
                continue;
            }

            $this->assertNotContains(EnsureBoardExists::class, $middleware, "{$route->uri()} — 라우트 파일 직접 부착 금지(getMiddleware 선언)");
            $this->assertContains(
                EnsureBoardExists::class,
                $registry->resolveForRoute((string) $route->getName(), $route->uri(), 'api', 'after_core'),
                "{$route->getName()} ({$route->uri()}) 에 존재 확인이 붙지 않는다 — getMiddleware targets 확인"
            );
            $checked++;
        }

        ExtensionMiddlewareRegistry::flush();
        $this->assertGreaterThan(30, $checked, '게시판별 권한 라우트를 찾지 못했다 — 라우트 등록 상태를 확인');
    }
}
