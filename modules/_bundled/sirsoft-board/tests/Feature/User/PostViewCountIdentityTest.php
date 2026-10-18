<?php

namespace Modules\Sirsoft\Board\Tests\Feature\User;

// ModuleTestCase 수동 로드 (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use App\Models\Role;
use App\Models\User;
use App\Seo\SeoRenderRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 사용자 게시글 상세 조회수 1회 판정 키 테스트
 *
 * GET /api/modules/sirsoft-board/boards/{slug}/posts/{id}
 *
 * 판정 기준: 로그인 회원은 회원 번호, 비로그인은 IP 로 한 번만 센다.
 * 서버가 SEO 화면을 만들려고 자기 자신에게 보내는 내부 호출(글 작성·수정 직후 캐시 재생성,
 * 검색 봇 방문)은 열람이 아니므로 세지 않는다 — 세면 글을 쓰자마자 조회수 1 이 되고,
 * 서버 IP 키가 먼저 잡혀 같은 IP 의 비로그인 방문이 「이미 봄」이 된다(7.1.0 실측 O-K3-1).
 */
class PostViewCountIdentityTest extends BoardTestCase
{
    /**
     * 테스트 게시판 slug 를 반환합니다.
     *
     * @return string 게시판 slug
     */
    protected function getTestBoardSlug(): string
    {
        return 'post-view-count-identity';
    }

    /**
     * 회원 역할에도 읽기 권한을 줍니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->grantUserRolePermissions(['posts.read']);
    }

    /**
     * 게시글 상세를 지정한 IP 에서 엽니다.
     *
     * @param  int  $postId  게시글 ID
     * @param  string  $ip  요청 IP
     * @param  array<string, string>  $headers  추가 헤더
     * @return TestResponse 응답
     */
    private function openPost(int $postId, string $ip = '127.0.0.1', array $headers = [])
    {
        // 헤더는 요청마다 넘긴다 — withHeaders() 는 다음 요청까지 남는다
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->getJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}", $headers);
    }

    /**
     * DB 의 조회수를 읽습니다.
     *
     * @param  int  $postId  게시글 ID
     * @return int 조회수
     */
    private function viewCount(int $postId): int
    {
        return (int) DB::table('board_posts')->where('id', $postId)->value('view_count');
    }

    /**
     * SEO 렌더러의 내부 호출은 조회수를 올리지 않고, 같은 IP 의 비로그인 첫 방문을 막지 않는다.
     *
     * @scenario viewer=seo_render
     *
     * @effects view_count_skips_seo_render, seo_render_does_not_preempt_guest_ip
     *
     * @return void 반환값 없음
     */
    public function test_seo_render_request_does_not_count_or_preempt_guest_view(): void
    {
        $postId = $this->createTestPost();

        $this->openPost($postId, '127.0.0.1', SeoRenderRequest::headers())->assertOk();
        $this->assertSame(0, $this->viewCount($postId), 'SEO 내부 호출이 조회수를 올렸다');

        $this->openPost($postId, '127.0.0.1')->assertOk()->assertJsonPath('data.view_count', 1);
        $this->assertSame(1, $this->viewCount($postId), '같은 IP 의 비로그인 첫 방문이 세지지 않았다');
    }

    /**
     * 서명이 맞지 않는 내부 호출 헤더는 일반 열람으로 센다.
     *
     * @scenario viewer=forged_header
     *
     * @return void 반환값 없음
     */
    public function test_forged_seo_render_header_is_counted_as_normal_view(): void
    {
        $postId = $this->createTestPost();

        $this->openPost($postId, '10.0.0.9', [SeoRenderRequest::HEADER => 'forged'])->assertOk();

        $this->assertSame(1, $this->viewCount($postId));
    }

    /**
     * 같은 IP 를 쓰는 서로 다른 회원은 각각 한 번씩 센다.
     *
     * @scenario viewer=member
     *
     * @effects view_count_member_key
     *
     * @return void 반환값 없음
     */
    public function test_members_sharing_one_ip_are_counted_separately(): void
    {
        $postId = $this->createTestPost();
        $first = User::factory()->create();
        $second = User::factory()->create();
        $first->roles()->attach(Role::where('identifier', 'user')->value('id'));
        $second->roles()->attach(Role::where('identifier', 'user')->value('id'));

        $this->actingAs($first, 'sanctum');
        $this->openPost($postId, '10.0.0.1')->assertOk();
        $this->openPost($postId, '10.0.0.1')->assertOk();

        $this->actingAs($second, 'sanctum');
        $this->openPost($postId, '10.0.0.1')->assertOk();

        $this->assertSame(2, $this->viewCount($postId));
    }

    /**
     * 같은 회원이 IP 를 바꿔 다시 열어도 한 번만 센다.
     *
     * @scenario viewer=member
     *
     * @effects view_count_member_key
     *
     * @return void 반환값 없음
     */
    public function test_same_member_from_different_ips_is_counted_once(): void
    {
        $postId = $this->createTestPost();
        $member = User::factory()->create();
        $member->roles()->attach(Role::where('identifier', 'user')->value('id'));

        $this->actingAs($member, 'sanctum');
        $this->openPost($postId, '10.0.0.1')->assertOk();
        $this->openPost($postId, '10.0.0.2')->assertOk();

        $this->assertSame(1, $this->viewCount($postId));
    }

    /**
     * 비로그인은 IP 마다 한 번씩 센다.
     *
     * @scenario viewer=guest
     *
     * @effects view_count_guest_ip_key
     *
     * @return void 반환값 없음
     */
    public function test_guests_are_counted_once_per_ip(): void
    {
        $postId = $this->createTestPost();

        $this->openPost($postId, '10.0.0.1')->assertOk();
        $this->openPost($postId, '10.0.0.1')->assertOk();
        $this->openPost($postId, '10.0.0.2')->assertOk();

        $this->assertSame(2, $this->viewCount($postId));
    }
}
