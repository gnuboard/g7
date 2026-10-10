<?php

namespace Modules\Sirsoft\Board\Tests\Feature;

// 테스트 베이스 클래스 수동 require (autoload 전에 로드 필요)
require_once __DIR__.'/../ModuleTestCase.php';

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Http\Controllers\Admin\ReportController as AdminReportController;
use Modules\Sirsoft\Board\Http\Controllers\User\BoardController as UserBoardController;
use Modules\Sirsoft\Board\Http\Resources\ReportDetailResource;
use Modules\Sirsoft\Board\Tests\BoardTestCase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;

/**
 * 공개 응답의 회원 이메일 노출 회귀 테스트
 *
 * 게시판의 공개 경로(목록·상세·댓글·게시판 상세)는 인증 없이 열람된다.
 * 그 응답에 회원 이메일이 실리면 회원 주소록이 그대로 수집되므로,
 * 이메일은 관리자 화면 응답과 본인 열람에만 실려야 한다.
 *
 * 인기글 목록은 Resource 를 거치지 않고 Repository 가 조립한 배열을 캐시에 저장해 그대로
 * 내보낸다 — 응답 시점 게이트가 닿지 않으므로 캐시에 이메일이 애초에 들어가지 않는지까지 본다.
 */
class MemberEmailExposureTest extends BoardTestCase
{
    private User $author;

    private User $adminUser;

    /**
     * 테스트 게시판 슬러그를 돌려줍니다.
     *
     * @return string 테스트 게시판 슬러그
     */
    protected function getTestBoardSlug(): string
    {
        return 'email-exposure';
    }

    /**
     * 테스트 게시판 기본 속성을 돌려줍니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @return array<string, mixed> 게시판 생성 속성
     */
    protected function getDefaultBoardAttributes(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => ['ko' => '이메일 노출 테스트 게시판', 'en' => 'Email Exposure Board'],
            'is_active' => true,
            'secret_mode' => 'disabled',
            'blocked_keywords' => [],
        ];
    }

    /**
     * 작성자·관리자 계정과 게시판 권한을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->author = User::factory()->create(['email' => 'author-exposure@example.test']);
        $this->adminUser = User::factory()->create(['email' => 'admin-exposure@example.test']);

        $this->setGuestPermissions(['posts.read', 'posts.write', 'comments.read', 'attachments.upload']);
        $this->setupAdminRole();
        $this->setupUserRole();
    }

    /**
     * 작성자 본인이 유저 라우트를 통과할 수 있는 역할/권한을 부여합니다.
     *
     * @return void 반환값 없음
     */
    private function setupUserRole(): void
    {
        $slug = $this->board->slug;

        $ids = [];
        foreach (['posts.read', 'comments.read'] as $action) {
            $ids[] = Permission::firstOrCreate(
                ['identifier' => "sirsoft-board.{$slug}.{$action}"],
                [
                    'name' => ['ko' => $action, 'en' => $action],
                    'slug' => "sirsoft-board.{$slug}.{$action}",
                    'type' => 'user',
                ]
            )->id;
        }

        $role = Role::firstOrCreate(
            ['identifier' => "{$slug}-user"],
            ['name' => ['ko' => '일반 사용자', 'en' => 'Regular User']]
        );
        $role->permissions()->syncWithoutDetaching($ids);
        $this->author->roles()->attach($role->id);

        $this->resetPermissionMiddlewareCache();
    }

    /**
     * 관리자 라우트를 통과할 수 있는 역할/권한을 부여합니다.
     *
     * @return void 반환값 없음
     */
    private function setupAdminRole(): void
    {
        $slug = $this->board->slug;

        $ids = [];
        foreach (['admin.posts.read', 'admin.comments.read', 'admin.manage'] as $action) {
            $ids[] = Permission::firstOrCreate(
                ['identifier' => "sirsoft-board.{$slug}.{$action}"],
                [
                    'name' => ['ko' => $action, 'en' => $action],
                    'slug' => "sirsoft-board.{$slug}.{$action}",
                    'type' => 'admin',
                ]
            )->id;
        }

        $role = Role::firstOrCreate(
            ['identifier' => "{$slug}-admin"],
            ['name' => ['ko' => '게시판 관리자', 'en' => 'Board Admin']]
        );
        $role->permissions()->syncWithoutDetaching($ids);
        $this->adminUser->roles()->attach($role->id);

        $this->resetPermissionMiddlewareCache();
    }

    /**
     * 비로그인 게시글 상세 응답의 작성자 이메일이 null 이다.
     *
     * @scenario case=post_detail_guest
     *
     * @effects author_email_absent_for_guest
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function guest_post_detail_does_not_expose_author_email(): void
    {
        $postId = $this->createTestPost([
            'user_id' => $this->author->id,
            'author_name' => null,
            'title' => '공개 게시글',
        ]);

        $response = $this->getJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}");

        $response->assertStatus(200);
        $this->assertNull(
            $response->json('data.author.email'),
            '비인증 게시글 상세 응답에 작성자 이메일이 실렸다'
        );
    }

    /**
     * 비로그인 게시글 목록 응답 원문에 작성자 이메일이 없다.
     *
     * @scenario case=post_list_guest
     *
     * @effects author_email_absent_for_guest
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function guest_post_list_does_not_expose_author_email(): void
    {
        $this->createTestPost([
            'user_id' => $this->author->id,
            'author_name' => null,
            'title' => '목록 노출 확인용',
        ]);

        $response = $this->getJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts?per_page=5");

        $response->assertStatus(200);
        $this->assertStringNotContainsString(
            $this->author->email,
            $response->getContent(),
            '비인증 게시글 목록 응답에 작성자 이메일이 실렸다'
        );
    }

    /**
     * 비로그인 작성자 검색은 회원 이메일로 글을 찾지 않는다(이메일을 한 글자씩 맞혀 보는 경로 차단).
     *
     * 2026-10-07 실측(K4-D2): 응답에서 이메일을 뺀 뒤에도 `search_field=author` 가 회원 이메일을
     * LIKE 로 찾아, 이메일 일부(`m2@exa`)로 그 회원의 글이 걸렸다. 이름 검색은 그대로 된다.
     *
     * @scenario case=author_search_guest
     *
     * @effects author_search_ignores_email_for_guest
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function guest_author_search_does_not_match_member_email(): void
    {
        $this->createTestPost([
            'user_id' => $this->author->id,
            'author_name' => null,
            'title' => '이메일 검색 차단 확인용',
        ]);
        $url = "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts";

        $byEmail = $this->getJson($url.'?search_field=author&search='.urlencode('author-exposure@exa'));
        $byEmail->assertStatus(200);
        $this->assertStringNotContainsString('이메일 검색 차단 확인용', $byEmail->getContent(), '작성자 이메일 일부로 글이 검색됐다');

        $byName = $this->getJson($url.'?search_field=author&search='.urlencode($this->author->name));
        $byName->assertStatus(200);
        $this->assertStringContainsString('이메일 검색 차단 확인용', $byName->getContent(), '작성자 이름 검색이 깨졌다');
    }

    /**
     * 관리자 게시글 목록의 작성자 검색은 종전대로 이메일로도 찾는다(관리자 화면은 이메일을 보여 준다).
     *
     * @scenario case=author_search_admin
     *
     * @effects author_search_matches_email_for_admin
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function admin_author_search_still_matches_member_email(): void
    {
        $this->createTestPost([
            'user_id' => $this->author->id,
            'author_name' => null,
            'title' => '관리자 이메일 검색 확인용',
        ]);

        $response = $this->actingAs($this->adminUser, 'sanctum')->getJson(
            "/api/modules/sirsoft-board/admin/board/{$this->board->slug}/posts?filters[0][field]=author&filters[0][value]="
                .urlencode('author-exposure@exa')
        );

        $response->assertStatus(200);
        $this->assertStringContainsString('관리자 이메일 검색 확인용', $response->getContent());
    }

    /**
     * 비로그인 댓글 목록 응답 원문에 작성자 이메일이 없다.
     *
     * @scenario case=comment_list_guest
     *
     * @effects author_email_absent_for_guest
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function guest_comment_list_does_not_expose_author_email(): void
    {
        $postId = $this->createTestPost([
            'user_id' => $this->author->id,
            'author_name' => null,
        ]);
        $this->createTestComment($postId, [
            'user_id' => $this->author->id,
            'author_name' => null,
            'content' => '댓글 내용',
        ]);

        $response = $this->getJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}/comments");

        $response->assertStatus(200);
        $this->assertStringNotContainsString(
            $this->author->email,
            $response->getContent(),
            '비인증 댓글 목록 응답에 작성자 이메일이 실렸다'
        );
    }

    /**
     * 비로그인 게시판 상세 응답 원문에 게시판 매니저 이메일이 없다.
     *
     * @scenario case=board_detail_guest
     *
     * @effects author_email_absent_for_guest
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function guest_board_detail_does_not_expose_manager_email(): void
    {
        $slug = $this->board->slug;

        $managerRole = Role::firstOrCreate(
            ['identifier' => "sirsoft-board.{$slug}.manager"],
            ['name' => ['ko' => '게시판 매니저', 'en' => 'Board Manager']]
        );
        $this->author->roles()->attach($managerRole->id);

        $response = $this->getJson("/api/modules/sirsoft-board/boards/{$slug}");

        $response->assertStatus(200);
        $this->assertStringNotContainsString(
            $this->author->email,
            $response->getContent(),
            '비인증 게시판 상세 응답에 매니저 이메일이 실렸다'
        );
    }

    /**
     * 관리자 게시글 상세에는 작성자 이메일 원문이 실린다(양성 대조).
     *
     * @scenario case=post_detail_admin
     *
     * @effects author_email_visible_to_admin
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function admin_post_detail_still_exposes_author_email(): void
    {
        $postId = $this->createTestPost([
            'user_id' => $this->author->id,
            'author_name' => null,
        ]);

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->getJson("/api/modules/sirsoft-board/admin/board/{$this->board->slug}/posts/{$postId}");

        $response->assertStatus(200);
        $this->assertSame(
            $this->author->email,
            $response->json('data.author.email'),
            '관리자 게시글 상세에서 작성자 이메일이 사라졌다 (관리자 화면이 이 값을 쓴다)'
        );
    }

    /**
     * 작성자 본인은 사용자 경로에서도 자기 이메일을 본다.
     *
     * @scenario case=post_detail_owner
     *
     * @effects author_email_visible_to_owner
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function author_sees_own_email_on_user_route(): void
    {
        $postId = $this->createTestPost([
            'user_id' => $this->author->id,
            'author_name' => null,
        ]);

        DB::table('board_posts')->where('id', $postId)->update(['user_id' => $this->author->id]);

        $response = $this->actingAs($this->author, 'sanctum')
            ->getJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}");

        $response->assertStatus(200);
        $this->assertSame(
            $this->author->email,
            $response->json('data.author.email'),
            '본인 글에서 자기 이메일이 가려졌다'
        );
    }

    /**
     * 비로그인 인기글 응답에 작성자 이메일이 실리지 않는지 검증합니다.
     *
     * 목록형이라 필드 경로가 불특정하고 캐시 경로라 키가 바뀔 수 있으므로, 필드 경로가 아니라
     * 응답 원문 전체에 이메일 문자열이 없는지 본다. 이메일만 빠지고 나머지 표시값은 그대로인지
     * (작성자명·제목) 함께 확인해 과잉 차단을 막는다.
     *
     * @scenario case=popular_guest
     *
     * @effects popular_email_absent_for_guest, popular_other_fields_intact
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function guest_popular_posts_do_not_expose_author_email(): void
    {
        $this->createTestPost([
            'user_id' => $this->author->id,
            'author_name' => null,
            'title' => '인기글 노출 확인용',
            'view_count' => 99,
        ]);

        $response = $this->getJson('/api/modules/sirsoft-board/boards/popular');

        $response->assertStatus(200);
        $this->assertStringNotContainsString(
            $this->author->email,
            $response->getContent(),
            '비인증 인기글 응답에 작성자 이메일이 실렸다'
        );

        $item = collect($response->json('data'))->firstWhere('title', '인기글 노출 확인용');
        $this->assertNotNull($item, '인기글 목록에서 게시글이 사라졌다 (과잉 차단)');
        $this->assertSame($this->author->name, $item['author']['name'], '작성자명이 사라졌다 (과잉 차단)');
        $this->assertArrayHasKey('email', $item['author'], '응답 구조가 바뀌었다 — 키는 유지하고 값만 비워야 한다');
        $this->assertNull($item['author']['email']);
    }

    /**
     * 관리자가 먼저 인기글 캐시를 채워도 비로그인 응답과 캐시 본문에 이메일이 없는지 검증합니다.
     *
     * 캐시는 호출자 권한과 무관하게 한 번 채워지면 모든 요청이 같은 값을 받는다. 게이트를 응답
     * 시점에 두면 관리자 호출이 캐시를 이메일로 오염시키므로, 캐시 본문 자체를 검사한다.
     *
     * @scenario case=popular_admin_then_guest
     *
     * @effects popular_cache_never_holds_email
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function popular_cache_filled_by_admin_never_holds_author_email(): void
    {
        Cache::flush();

        $this->createTestPost([
            'user_id' => $this->author->id,
            'author_name' => null,
            'title' => '캐시 오염 확인용',
            'view_count' => 99,
        ]);

        $this->actingAs($this->adminUser, 'sanctum')
            ->getJson('/api/modules/sirsoft-board/boards/popular')
            ->assertStatus(200);

        $cached = Cache::get('g7:module.sirsoft-board:popular_posts_v2_week_20_'.app()->getLocale());
        $this->assertIsArray($cached, '인기글 캐시가 예상 키에 채워지지 않았다');
        $this->assertStringNotContainsString(
            $this->author->email,
            json_encode($cached, JSON_UNESCAPED_UNICODE),
            '인기글 캐시에 작성자 이메일이 저장됐다 — 이후 모든 비인증 요청이 이 값을 받는다'
        );

        $this->app['auth']->forgetGuards();

        $guest = $this->getJson('/api/modules/sirsoft-board/boards/popular');
        $guest->assertStatus(200);
        $this->assertStringNotContainsString($this->author->email, $guest->getContent());
    }

    /**
     * 신고 상세의 댓글 작성자 이메일이 관리자 응답에만 실리는지 검증합니다.
     *
     * 같은 Resource 의 게시글 작성자·신고자 이메일은 게이트가 있었는데 댓글 작성자 줄만 빠져 있었다.
     * 지금은 관리자 라우트 전용이지만, 다른 경로에 붙는 순간 그대로 새므로 게이트를 고정한다.
     *
     * @scenario case=report_detail_admin
     *
     * @effects report_comment_author_email_admin_only
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function report_detail_comment_author_email_is_admin_only(): void
    {
        $resource = new ReportDetailResource([]);
        $build = fn () => (new ReflectionMethod(ReportDetailResource::class, 'buildAuthorInfo'))
            ->invoke($resource, [
                'author_id' => $this->author->id,
                'author_uuid' => $this->author->uuid,
                'author_name' => $this->author->name,
                'author_email' => $this->author->email,
            ], '');

        $this->app->instance('request', $this->routedRequest(AdminReportController::class));
        $this->assertSame($this->author->email, $build()['email'], '관리자 신고 상세에서 댓글 작성자 이메일이 사라졌다');

        $this->app->instance('request', $this->routedRequest(UserBoardController::class));
        $this->assertNull($build()['email'], '관리자 컨트롤러가 아닌 응답에 댓글 작성자 이메일이 실렸다');
    }

    /**
     * 지정한 컨트롤러로 라우팅된 요청을 만듭니다.
     *
     * @param  string  $controller  컨트롤러 FQCN
     * @return Request 라우트가 연결된 요청
     */
    private function routedRequest(string $controller): Request
    {
        $request = Request::create('/api/probe', 'GET');
        $route = new RoutingRoute(['GET'], '/api/probe', ['uses' => $controller.'@index']);
        $route->setContainer($this->app);
        $request->setRouteResolver(fn () => $route);

        return $request;
    }
}
