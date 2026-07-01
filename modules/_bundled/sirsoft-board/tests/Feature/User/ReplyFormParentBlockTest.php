<?php

namespace Modules\Sirsoft\Board\Tests\Feature\User;

// 테스트 베이스 클래스 수동 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 답글 작성 폼 진입 단계 부모글 차단 테스트 (#413-44)
 *
 * 블라인드/삭제된 부모글에 답글 작성 폼(form-meta/form-data)으로 직접 진입할 때
 * 폼이 열리며 부모글 원문(제목/본문)이 노출되는지 검증합니다.
 *
 * 기존 차단은 제출(ParentPostValidationRule) 단계에만 존재하여,
 * 폼 진입 단계에서는 부모글 정보가 그대로 응답되는 결함이 있었습니다.
 *
 * @scenario viewer=regular
 * @effects form_meta_blocks_blinded_parent_and_hides_original, form_data_blocks_blinded_parent_and_hides_original, form_meta_blocks_deleted_parent_and_hides_original, form_data_blocks_deleted_parent_and_hides_original, form_meta_allows_published_parent
 */
class ReplyFormParentBlockTest extends BoardTestCase
{
    private User $writer;

    protected function getTestBoardSlug(): string
    {
        return 'reply-block';
    }

    protected function getDefaultBoardAttributes(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => ['ko' => '답글 차단 테스트 게시판', 'en' => 'Reply Block Test Board'],
            'is_active' => true,
            'use_comment' => true,
            'use_reply' => true,
            'max_reply_depth' => 3,
            'secret_mode' => 'disabled',
            'blocked_keywords' => [],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $slug = $this->board->slug;

        // posts.write 권한 보유 사용자 (답글 작성 폼 진입 권한)
        $this->writer = User::factory()->create();
        $userRole = Role::where('identifier', 'user')->first();
        if ($userRole) {
            $writePerm = Permission::firstOrCreate(
                ['identifier' => "sirsoft-board.{$slug}.posts.write"],
                ['name' => ['ko' => '게시글 작성', 'en' => 'Write Posts'], 'type' => 'user']
            );
            $userRole->permissions()->syncWithoutDetaching([$writePerm->id]);
            $this->writer->roles()->attach($userRole->id);
        }
    }

    private function formMetaUrl(int $parentId): string
    {
        return "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/form-meta?parent_id={$parentId}";
    }

    private function formDataUrl(int $parentId): string
    {
        return "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/form-data?parent_id={$parentId}";
    }

    // ==========================================
    // 블라인드 부모글
    // ==========================================

    public function test_form_meta_blocks_blinded_parent_and_hides_original(): void
    {
        $parentId = $this->createTestPost([
            'title' => '블라인드 부모 제목 SECRET',
            'content' => '블라인드 부모 본문 SECRET',
            'status' => 'blinded',
            'trigger_type' => 'report',
        ]);

        $response = $this->actingAs($this->writer, 'sanctum')
            ->getJson($this->formMetaUrl($parentId));

        $response->assertNotFound();
        $this->assertStringNotContainsString('블라인드 부모 제목 SECRET', $this->decodedContent($response));
        $this->assertStringNotContainsString('블라인드 부모 본문 SECRET', $this->decodedContent($response));
    }

    public function test_form_data_blocks_blinded_parent_and_hides_original(): void
    {
        $parentId = $this->createTestPost([
            'title' => '블라인드 부모 제목 SECRET',
            'content' => '블라인드 부모 본문 SECRET',
            'status' => 'blinded',
            'trigger_type' => 'report',
        ]);

        $response = $this->actingAs($this->writer, 'sanctum')
            ->getJson($this->formDataUrl($parentId));

        $response->assertNotFound();
        $this->assertStringNotContainsString('블라인드 부모 제목 SECRET', $this->decodedContent($response));
        $this->assertStringNotContainsString('블라인드 부모 본문 SECRET', $this->decodedContent($response));
    }

    // ==========================================
    // 삭제된 부모글
    // ==========================================

    public function test_form_meta_blocks_deleted_parent_and_hides_original(): void
    {
        $parentId = $this->createTestPost([
            'title' => '삭제 부모 제목 SECRET',
            'content' => '삭제 부모 본문 SECRET',
            'status' => 'deleted',
            'deleted_at' => now(),
        ]);

        $response = $this->actingAs($this->writer, 'sanctum')
            ->getJson($this->formMetaUrl($parentId));

        $response->assertNotFound();
        $this->assertStringNotContainsString('삭제 부모 제목 SECRET', $this->decodedContent($response));
        $this->assertStringNotContainsString('삭제 부모 본문 SECRET', $this->decodedContent($response));
    }

    public function test_form_data_blocks_deleted_parent_and_hides_original(): void
    {
        $parentId = $this->createTestPost([
            'title' => '삭제 부모 제목 SECRET',
            'content' => '삭제 부모 본문 SECRET',
            'status' => 'deleted',
            'deleted_at' => now(),
        ]);

        $response = $this->actingAs($this->writer, 'sanctum')
            ->getJson($this->formDataUrl($parentId));

        $response->assertNotFound();
        $this->assertStringNotContainsString('삭제 부모 제목 SECRET', $this->decodedContent($response));
        $this->assertStringNotContainsString('삭제 부모 본문 SECRET', $this->decodedContent($response));
    }

    // ==========================================
    // 정상 부모글 (회귀 방지 — 차단되면 안 됨)
    // ==========================================

    public function test_form_meta_allows_published_parent(): void
    {
        $parentId = $this->createTestPost([
            'title' => '정상 부모 제목',
            'content' => '정상 부모 본문',
            'status' => 'published',
        ]);

        $response = $this->actingAs($this->writer, 'sanctum')
            ->getJson($this->formMetaUrl($parentId));

        $response->assertOk();
        $response->assertJsonPath('data.parent_post.title', '정상 부모 제목');
    }

    /**
     * JSON 응답 본문을 유니코드 이스케이프 해제하여 반환합니다.
     *
     * Laravel JSON 응답은 한글을 \uXXXX로 직렬화하므로, 원문 노출 여부 검증 시
     * 디코딩 후 비교해야 합니다.
     *
     * @param  \Illuminate\Testing\TestResponse  $response  테스트 응답
     * @return string 디코딩된 본문 문자열
     */
    private function decodedContent($response): string
    {
        return json_encode($response->json(), JSON_UNESCAPED_UNICODE) ?: '';
    }
}
