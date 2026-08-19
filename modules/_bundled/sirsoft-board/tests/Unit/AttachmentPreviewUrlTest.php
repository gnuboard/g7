<?php

namespace Modules\Sirsoft\Board\Tests\Unit;

use Modules\Sirsoft\Board\Models\Attachment;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 게시판 첨부 미리보기 URL 조립 단일 지점(previewUrlForSlug) 회귀 테스트
 *
 * URL 중복 조립 정리(#100) 후에도 응답 문자열이 기존과 동일함을 고정합니다.
 * board 는 직접 URL 배선 대상이 아니므로 문자열 회귀 0 이 계약입니다.
 */
class AttachmentPreviewUrlTest extends ModuleTestCase
{
    /**
     * @effects board_response_strings_unchanged
     */
    #[Test]
    public function preview_url_for_slug_builds_board_preview_url(): void
    {
        $attachment = new Attachment;
        $attachment->hash = 'abc123def456';

        $this->assertSame(
            '/api/modules/sirsoft-board/boards/notice/attachment/abc123def456/preview',
            $attachment->previewUrlForSlug('notice')
        );
    }

    #[Test]
    public function preview_url_accessor_delegates_to_single_assembly_point(): void
    {
        $attachment = new Attachment;
        $attachment->hash = 'abc123def456';
        $attachment->mime_type = 'image/png';
        $attachment->board_id = 0;

        // board 관계 미로딩 + 존재하지 않는 board_id → slug 는 빈 문자열로 조립 (기존 동작 보존)
        $this->assertSame(
            '/api/modules/sirsoft-board/boards//attachment/abc123def456/preview',
            $attachment->preview_url
        );
    }
}
