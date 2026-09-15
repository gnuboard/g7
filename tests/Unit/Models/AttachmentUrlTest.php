<?php

namespace Tests\Unit\Models;

use App\Models\Attachment;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * 코어 Attachment URL 조립 단일 지점(urlForHash/download_url) 회귀 테스트
 *
 * URL 중복 조립 정리(#100) 후에도 응답 문자열이 기존과 동일함을 고정합니다.
 */
class AttachmentUrlTest extends TestCase
{
    /**
     * @effects board_response_strings_unchanged
     */
    #[Test]
    public function url_for_hash_builds_core_attachment_url(): void
    {
        $this->assertSame('/api/attachment/abc123def456', Attachment::urlForHash('abc123def456'));
    }

    #[Test]
    public function download_url_accessor_delegates_to_url_for_hash(): void
    {
        $attachment = new Attachment;
        $attachment->hash = 'abc123def456';

        $this->assertSame('/api/attachment/abc123def456', $attachment->download_url);
    }
}
