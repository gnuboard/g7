<?php

namespace Modules\Sirsoft\Board\Tests\Feature\User;

// 테스트 베이스 클래스 수동 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 신고 응답 본문 심층방어 테스트 (KVE-2026-2377)
 *
 * 신고 생성 응답이 대상 본문(content_preview)을 되돌려 주면, 신고 게이트에 우회로가 생기는 순간
 * 그 응답이 비밀 원문 열람 경로가 된다. 사용자 신고 응답에서는 본문을 빼고, 관리자 모더레이션
 * 화면(신고 목록 미리보기)에서만 싣는다. 마이페이지 「내 신고」 발췌는 신고자가 이미 본 내용을
 * 신고 시점에 저장한 것이라 유지한다(설계 결정).
 *
 * 시나리오 축·효과는 매니페스트 tests/scenarios/board-secret-content-bypass-paths.yaml 참조.
 */
class ReportResponseContentTest extends BoardTestCase
{
    private User $reporter;

    private User $author;

    /**
     * 이 테스트 전용 게시판 슬러그를 반환합니다.
     *
     * @return string 게시판 슬러그
     */
    protected function getTestBoardSlug(): string
    {
        return 'report-response-content';
    }

    /**
     * 신고·댓글을 켠 게시판 기본 속성을 반환합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @return array<string, mixed> 게시판 속성
     */
    protected function getDefaultBoardAttributes(string $slug): array
    {
        return array_merge(parent::getDefaultBoardAttributes($slug), [
            'use_report' => true,
            'use_comment' => true,
        ]);
    }

    /**
     * 게시판 권한·회원·게시글을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->grantUserRolePermissions(['posts.read', 'comments.read']);
        $this->reporter = $this->createUser();
        $this->author = $this->createUser();
    }

    /**
     * 신고 API 경로.
     *
     * @param  int  $postId  게시글 ID
     * @return string 요청 경로
     */
    private function reportUrl(int $postId): string
    {
        return "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}/reports";
    }

    /**
     * 사용자 신고 생성 응답에는 대상 본문(원문·미리보기)이 없다.
     *
     * @scenario viewer=reporter
     *
     * @effects user_report_response_omits_target_content
     *
     * @return void 반환값 없음
     */
    public function test_user_report_response_omits_target_content(): void
    {
        $postId = $this->createTestPost(['content' => 'REPORTED-BODY', 'user_id' => $this->author->id]);

        $response = $this->actingAs($this->reporter, 'sanctum')
            ->postJson($this->reportUrl($postId), ['reason_type' => 'spam', 'reason_detail' => '광고']);

        $response->assertCreated();
        $this->assertNull($response->json('data.content'));
        $this->assertNull($response->json('data.content_preview'));
        $this->assertStringNotContainsString('REPORTED-BODY', $response->getContent());
    }

    /**
     * 관리자 신고 목록에는 대상 본문 미리보기가 그대로 실린다.
     *
     * @scenario viewer=admin
     *
     * @effects admin_report_list_keeps_content_preview
     *
     * @return void 반환값 없음
     */
    public function test_admin_report_list_keeps_content_preview(): void
    {
        $postId = $this->createTestPost(['content' => 'REPORTED-BODY', 'user_id' => $this->author->id]);
        $this->actingAs($this->reporter, 'sanctum')
            ->postJson($this->reportUrl($postId), ['reason_type' => 'spam', 'reason_detail' => '광고'])
            ->assertCreated();

        $admin = User::factory()->create();
        $adminRole = Role::where('identifier', 'admin')->first();
        $view = Permission::firstOrCreate(
            ['identifier' => 'sirsoft-board.reports.view'],
            ['name' => ['ko' => '신고 조회', 'en' => 'View Reports'], 'type' => 'admin']
        );
        $adminRole->permissions()->syncWithoutDetaching([$view->id]);
        $admin->roles()->attach($adminRole->id);

        $list = $this->actingAs($admin, 'sanctum')->getJson('/api/modules/sirsoft-board/admin/reports');
        $list->assertOk();

        $row = collect($list->json('data.data'))->firstWhere('target_id', $postId);
        $this->assertNotNull($row);
        $this->assertSame('REPORTED-BODY', $row['content_preview']);
    }

    /**
     * 마이페이지 내 신고 목록의 본문 발췌는 유지된다.
     *
     * @scenario viewer=reporter
     *
     * @effects my_reports_excerpt_kept
     *
     * @return void 반환값 없음
     */
    public function test_my_reports_excerpt_is_kept(): void
    {
        $postId = $this->createTestPost(['content' => 'REPORTED-BODY', 'user_id' => $this->author->id]);
        $this->actingAs($this->reporter, 'sanctum')
            ->postJson($this->reportUrl($postId), ['reason_type' => 'spam', 'reason_detail' => '광고'])
            ->assertCreated();

        $mine = $this->actingAs($this->reporter, 'sanctum')->getJson('/api/modules/sirsoft-board/me/reports');
        $mine->assertOk();

        $this->assertSame('REPORTED-BODY', $mine->json('data.data.0.excerpt'));
    }

    /**
     * 열람할 수 없는 비밀글은 신고 자체가 막힌다 — 응답 정리는 이 게이트를 대신하지 않는다.
     *
     * @scenario viewer=reporter
     *
     * @effects unreadable_secret_post_report_still_403
     *
     * @return void 반환값 없음
     */
    public function test_unreadable_secret_post_report_still_forbidden(): void
    {
        $postId = $this->createTestPost(['content' => 'SECRET-BODY', 'is_secret' => true, 'user_id' => $this->author->id]);

        $response = $this->actingAs($this->reporter, 'sanctum')
            ->postJson($this->reportUrl($postId), ['reason_type' => 'spam', 'reason_detail' => '광고']);

        $response->assertForbidden();
        $this->assertStringNotContainsString('SECRET-BODY', $response->getContent());
    }
}
