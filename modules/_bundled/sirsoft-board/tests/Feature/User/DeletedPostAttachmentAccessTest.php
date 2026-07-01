<?php

namespace Modules\Sirsoft\Board\Tests\Feature\User;

// 테스트 베이스 클래스 수동 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 삭제된 게시글 첨부파일 접근 차단 테스트 (#413-50-4)
 *
 * 첨부파일은 본문 권한(getFilteredContent)과 별개 경로라, 본문이 삭제 마스킹되어도
 * 첨부 목록/다운로드/미리보기는 글 상태를 검사하지 않아 그대로 노출되는 결함이 있었습니다.
 *
 * 정책: 삭제된 게시글의 첨부파일은 관리 권한(manager/admin.manage) 보유자만 접근.
 *   - 상세 응답 첨부 목록: 비권한자에게 빈 배열
 *   - 다운로드: 비권한자 차단(404)
 *   범위: 삭제글만. 블라인드글 첨부는 본 항목 범위 외(별도 추적).
 *
 * @scenario viewer=regular
 * @effects manager_sees_deleted_post_attachments_in_detail, normal_post_attachments_still_visible, regular_user_cannot_download_deleted_post_attachment, regular_user_can_download_normal_post_attachment_passes_gate, regular_user_cannot_preview_deleted_post_attachment
 */
class DeletedPostAttachmentAccessTest extends BoardTestCase
{
    private User $regularUser;

    private User $managerUser;

    protected function getTestBoardSlug(): string
    {
        return 'del-attach';
    }

    protected function getDefaultBoardAttributes(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => ['ko' => '삭제 첨부 테스트 게시판', 'en' => 'Deleted Attachment Test Board'],
            'is_active' => true,
            'use_comment' => true,
            'secret_mode' => 'disabled',
            'blocked_keywords' => [],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $slug = $this->board->slug;

        // 일반 사용자 (posts.read + attachments.download)
        $this->regularUser = User::factory()->create();
        $userRole = Role::where('identifier', 'user')->first();
        if ($userRole) {
            foreach (['posts.read', 'attachments.download'] as $key) {
                $perm = Permission::firstOrCreate(
                    ['identifier' => "sirsoft-board.{$slug}.{$key}"],
                    ['name' => ['ko' => $key, 'en' => $key], 'type' => 'user']
                );
                $userRole->permissions()->syncWithoutDetaching([$perm->id]);
            }
            $this->regularUser->roles()->attach($userRole->id);
        }

        // manager 권한 사용자
        $this->managerUser = User::factory()->create();
        $managerRole = Role::firstOrCreate(
            ['identifier' => "{$slug}-manager"],
            ['name' => ['ko' => '게시판 매니저', 'en' => 'Board Manager']]
        );
        foreach (['posts.read', 'attachments.download', 'manager'] as $key) {
            $perm = Permission::firstOrCreate(
                ['identifier' => "sirsoft-board.{$slug}.{$key}"],
                ['name' => ['ko' => $key, 'en' => $key], 'type' => 'user']
            );
            $managerRole->permissions()->syncWithoutDetaching([$perm->id]);
        }
        $this->managerUser->roles()->attach($managerRole->id);
    }

    /**
     * 게시글에 첨부파일을 직접 생성합니다.
     *
     * @param  int  $postId  게시글 ID
     * @param  string  $hash  첨부파일 해시
     * @return int 생성된 첨부파일 ID
     */
    private function createAttachment(int $postId, string $hash, bool $image = false): int
    {
        $ext = $image ? 'jpg' : 'pdf';
        $mime = $image ? 'image/jpeg' : 'application/pdf';

        return DB::table('board_attachments')->insertGetId([
            'board_id' => $this->board->id,
            'post_id' => $postId,
            'original_filename' => "doc.{$ext}",
            'stored_filename' => "{$hash}.{$ext}",
            'hash' => $hash,
            'mime_type' => $mime,
            'size' => 1024,
            'path' => "attachments/doc.{$ext}",
            'collection' => 'attachments',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function showUrl(int $postId): string
    {
        return "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}";
    }

    private function downloadUrl(string $hash): string
    {
        return "/api/modules/sirsoft-board/boards/{$this->board->slug}/attachment/{$hash}";
    }

    private function previewUrl(string $hash): string
    {
        return "/api/modules/sirsoft-board/boards/{$this->board->slug}/attachment/{$hash}/preview";
    }

    // ==========================================
    // 상세 응답 첨부 목록
    // ==========================================

    public function test_manager_sees_deleted_post_attachments_in_detail(): void
    {
        $postId = $this->createTestPost([
            'title' => '삭제글 첨부',
            'status' => 'deleted',
            'deleted_at' => now(),
            'attachments_count' => 1,
        ]);
        $this->createAttachment($postId, 'delatchmgrAA');

        $response = $this->actingAs($this->managerUser, 'sanctum')
            ->getJson($this->showUrl($postId));

        $response->assertStatus(200);
        $this->assertNotEmpty($response->json('data.attachments'), 'manager 에게는 삭제글 첨부 목록이 노출되어야 합니다');
    }

    public function test_normal_post_attachments_still_visible(): void
    {
        // 회귀 방지: 정상글 첨부는 일반 사용자에게도 그대로 노출
        $postId = $this->createTestPost([
            'title' => '정상글 첨부',
            'status' => 'published',
            'attachments_count' => 1,
        ]);
        $this->createAttachment($postId, 'normatchAAAA');

        $response = $this->actingAs($this->regularUser, 'sanctum')
            ->getJson($this->showUrl($postId));

        $response->assertStatus(200);
        $this->assertNotEmpty($response->json('data.attachments'), '정상글 첨부는 노출되어야 합니다');
    }

    // ==========================================
    // 다운로드 (해시 직접 접근)
    // ==========================================

    public function test_regular_user_cannot_download_deleted_post_attachment(): void
    {
        $postId = $this->createTestPost([
            'title' => '삭제글 첨부',
            'status' => 'deleted',
            'deleted_at' => now(),
            'attachments_count' => 1,
        ]);
        $this->createAttachment($postId, 'delatchregAA');

        $response = $this->actingAs($this->regularUser, 'sanctum')
            ->get($this->downloadUrl('delatchregAA'));

        // 권한 게이트로 차단(403) — "파일 없음(404)"이 아니라 권한 차단임을 명확히 검증
        $response->assertStatus(403);
    }

    public function test_regular_user_can_download_normal_post_attachment_passes_gate(): void
    {
        // 회귀 방지: 정상글 첨부는 권한 게이트를 통과해야 한다.
        // (실제 파일이 없어 최종 404 가 나더라도, 403 권한 차단은 아니어야 함)
        $postId = $this->createTestPost([
            'title' => '정상글 첨부',
            'status' => 'published',
            'attachments_count' => 1,
        ]);
        $this->createAttachment($postId, 'normdlAAAAAA');

        $response = $this->actingAs($this->regularUser, 'sanctum')
            ->get($this->downloadUrl('normdlAAAAAA'));

        $this->assertNotSame(403, $response->getStatusCode(), '정상글 첨부는 권한 차단되면 안 됩니다');
    }

    // ==========================================
    // 미리보기 (이미지 직접 접근)
    // ==========================================

    public function test_regular_user_cannot_preview_deleted_post_attachment(): void
    {
        // 삭제글 이미지 첨부 미리보기는 비권한자에게 403 으로 차단되어야 한다.
        // (회귀 방지: preview 컨트롤러가 AccessDeniedHttpException 을 500 이 아닌 403 으로 변환)
        $postId = $this->createTestPost([
            'title' => '삭제글 이미지첨부',
            'status' => 'deleted',
            'deleted_at' => now(),
            'attachments_count' => 1,
        ]);
        $this->createAttachment($postId, 'delprevimgAA', image: true);

        $response = $this->actingAs($this->regularUser, 'sanctum')
            ->get($this->previewUrl('delprevimgAA'));

        $response->assertStatus(403);
    }
}
