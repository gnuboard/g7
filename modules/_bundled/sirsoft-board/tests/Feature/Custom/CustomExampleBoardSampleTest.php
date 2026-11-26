<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Custom;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Modules\Sirsoft\Board\Tests\BoardTestCase;
use Tests\Concerns\InstallsCustomExample;

/**
 * `php artisan custom:example board` 가 만든 견본 화면 테스트
 *
 * 견본은 게시판 공개 API 를 서버 안에서 그 회원 자격으로 불러 Blade 로 그린다. 그누보드7 셸(#app) 없이
 * 목록·글보기가 API 와 같은 글을 보여 주고, 비밀글·없는 글은 그 상태 코드의 견본 오류 화면,
 * 관리자 견본은 비회원 302·일반 회원 403·관리자 200, HTML+jQuery 견본은 `.html` 원문 그대로인지 잠근다.
 */
class CustomExampleBoardSampleTest extends BoardTestCase
{
    use InstallsCustomExample;

    private const PASSWORD = 'Sample!pass792';

    private string $root;

    /**
     * 견본을 임시 루트에 만들고 등록합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 요청마다 새 세션 저장소로 — 기본 array 드라이버는 한 프로세스 안에서 앞 요청의 세션이 남는다
        config(['session.driver' => 'file']);
        $this->freshRequestState();
        $this->withCredentials();

        $this->root = storage_path('framework/testing/custom_example_board_'.uniqid());
        $this->installCustomExample($this->root);
    }

    /**
     * 임시 루트를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    /**
     * 비회원 목록은 셸 없이 API 1쪽과 같은 글을 그리고, `?locale=` 에 따라 한글·영문 견본 문구입니다.
     *
     * @scenario case=list
     *
     * @effects list_matches_api, no_engine_shell, locale_switch
     *
     * @return void 반환값 없음
     */
    public function test_list_renders_api_posts_without_shell(): void
    {
        foreach (['첫 글', '둘째 글', '셋째 글'] as $title) {
            $this->createTestPost(['title' => $title]);
        }
        $slug = $this->board->slug;

        $api = $this->getJson("/api/modules/sirsoft-board/boards/{$slug}/posts")->assertOk()->json('data.data');
        $this->freshRequestState();

        $html = $this->get("/board/{$slug}?locale=ko")->assertOk()->getContent();

        $this->assertStringNotContainsString('id="app"', $html);
        $this->assertStringContainsString('<html lang="ko">', $html);
        $this->assertSame(count($api), substr_count($html, 'data-post-id='));
        foreach ($api as $post) {
            $this->assertStringContainsString('href="/board/'.$slug.'/'.$post['id'].'"', $html);
        }
        $this->assertStringContainsString('글쓰기', $html);

        $this->freshRequestState();
        $en = $this->get("/board/{$slug}?locale=en")->assertOk()->getContent();
        $this->assertStringContainsString('<html lang="en">', $en);
        $this->assertStringContainsString('>Write<', $en);
    }

    /**
     * 글보기는 API 의 제목·본문을 그리고, 본문은 코어 정리기를 거칩니다.
     *
     * @scenario case=show
     *
     * @effects show_matches_api, content_sanitized
     *
     * @return void 반환값 없음
     */
    public function test_show_renders_sanitized_post(): void
    {
        $id = $this->createTestPost([
            'title' => '견본 글보기',
            'content' => '<p>견본 본문</p><script>alert(1)</script>',
            'content_mode' => 'html',
        ]);

        $html = $this->get("/board/{$this->board->slug}/{$id}")->assertOk()->getContent();

        $this->assertStringContainsString('data-testid="cx-post-title">견본 글보기<', $html);
        $this->assertStringContainsString('견본 본문', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    /**
     * 볼 권한이 없는 비밀글은 403, 없는 글은 404 견본 오류 화면입니다 (일반 회원 로그인 상태).
     *
     * @scenario case=errors
     *
     * @effects secret_403_for_other_member, missing_404, member_header
     *
     * @return void 반환값 없음
     */
    public function test_secret_post_403_and_missing_404_for_member(): void
    {
        $writer = $this->member();
        $secret = $this->createTestPost(['title' => '비밀 견본', 'is_secret' => true, 'user_id' => $writer->id]);
        $viewer = $this->member();
        $this->login($viewer, '/api/auth/login');

        $response = $this->get("/board/{$this->board->slug}/{$secret}")->assertForbidden();
        $response->assertSee('data-testid="cx-error-code">403<', false);
        $response->assertSee('data-testid="cx-member"', false);
        $response->assertDontSee('비밀 견본');

        $this->freshRequestState();
        $this->get("/board/{$this->board->slug}/999999999")
            ->assertNotFound()
            ->assertSee('data-testid="cx-error-code">404<', false);
    }

    /**
     * 관리자 견본은 비회원 → 관리자 로그인, 일반 회원 → 403, 관리자 → 200(사이트 이름·custom 라우트 현황)입니다.
     *
     * @scenario case=admin
     *
     * @effects admin_guest_redirect, admin_member_403, admin_ok_with_status
     *
     * @return void 반환값 없음
     */
    public function test_admin_example_access(): void
    {
        $this->get('/admin/custom-example')
            ->assertRedirect('/admin/login?redirect='.rawurlencode('/admin/custom-example'));

        $this->freshRequestState();
        $this->login($this->member(), '/api/auth/login');
        $this->get('/admin/custom-example')->assertForbidden();

        $this->flushSession();
        $this->defaultCookies = [];
        $this->freshRequestState();
        $this->login($this->member(admin: true), '/api/auth/admin/login');

        // 라우트·패키지 현황은 custom:check 와 같은 함수(CustomStatus)가 프로젝트의 routes/custom.php 를 읽는다 — 그 값은 CustomCheckCommandTest 가 잠근다
        $html = $this->get('/admin/custom-example')->assertOk()->getContent();
        $this->assertStringContainsString('data-testid="cx-site-name">'.e((string) config('app.name')).'<', $html);
        $this->assertStringContainsString('data-testid="cx-admin-name"', $html);
    }

    /**
     * HTML+jQuery 견본은 서버가 `.html` 파일을 그대로 내보냅니다 (Blade 해석 없음).
     *
     * @scenario case=jquery
     *
     * @effects html_view_served_raw
     *
     * @return void 반환값 없음
     */
    public function test_jquery_page_is_served_as_is(): void
    {
        $response = $this->get('/custom-jquery-example')->assertOk();

        $this->assertSame(File::get($this->root.'/resources/views/custom/jquery/index.html'), $response->getContent());
    }

    /**
     * 로그인 API 로 로그인하고 세션 쿠키를 이후 요청에 싣습니다.
     *
     * @param  User  $user  회원
     * @param  string  $endpoint  로그인 API
     * @return void 반환값 없음
     */
    private function login(User $user, string $endpoint): void
    {
        $response = $this->postJson($endpoint, ['email' => $user->email, 'password' => self::PASSWORD])->assertOk();
        $cookie = $response->getCookie(config('session.cookie'));
        if ($cookie !== null && $cookie->getValue() !== null) {
            $this->withCookie(config('session.cookie'), (string) $cookie->getValue());
        }
        $this->freshRequestState();
    }

    /**
     * 다음 요청을 새 요청처럼 — 가드·세션 저장소 메모리를 비웁니다(세션 내용은 쿠키로 다시 읽힌다).
     *
     * @return void 반환값 없음
     */
    private function freshRequestState(): void
    {
        Auth::forgetGuards();
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
    }

    /**
     * 시험 회원을 만듭니다 (user 역할 — 게시판 기본 권한 포함).
     *
     * @param  bool  $admin  관리자 유형 권한도 줄지
     * @return User 회원
     */
    private function member(bool $admin = false): User
    {
        $user = User::factory()->create([
            'email' => 'sample792_'.uniqid().'@example.com',
            'password' => bcrypt(self::PASSWORD),
        ]);
        $this->grantUserRolePermissions(['posts.read']);
        $user->roles()->attach(Role::where('identifier', 'user')->value('id'));

        if ($admin) {
            // 관리자 판정(User::isAdmin)은 관리자 유형 권한 보유다
            $permission = Permission::firstOrCreate(['identifier' => 'core.settings.read'], [
                'name' => ['ko' => 'core.settings.read', 'en' => 'core.settings.read'],
                'type' => 'admin',
            ]);
            $adminRole = Role::where('identifier', 'admin')->first();
            $adminRole->permissions()->syncWithoutDetaching([$permission->id]);
            $user->roles()->attach($adminRole->id);
        }

        return $user;
    }
}
