<?php

namespace Modules\Sirsoft\Board\Tests\Unit;

use Illuminate\Http\Request;
use Modules\Sirsoft\Board\Http\Resources\AttachmentResource;
use Modules\Sirsoft\Board\Models\Attachment;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 게시판 첨부 응답 `meta` 의 빈 값 모양 회귀 테스트
 *
 * 코어 첨부(`App\Http\Resources\AttachmentResource`)와 같은 약속 — 비어 있으면 `{}`,
 * 없으면 null — 을 게시판 첨부 응답도 지킨다. 빈 배열이 `[]` 로 나가면 화면이
 * 키 접근을 하는 객체 자리에 목록이 와서 코어 첨부와 모양이 갈린다.
 */
class AttachmentResourceMetaShapeTest extends ModuleTestCase
{
    /**
     * 저장된 meta 가 빈 배열이면 응답에 `[]` 가 아니라 `{}` 로 나가는지 확인합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario meta=empty
     *
     * @effects board_attachment_meta_empty_is_object
     */
    #[Test]
    public function empty_meta_is_serialized_as_object(): void
    {
        $this->assertSame('{}', json_encode($this->metaOf([])));
    }

    /**
     * 저장된 meta 가 없으면(null) 응답도 null 그대로인지 확인합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario meta=missing
     *
     * @effects board_attachment_meta_missing_is_null
     */
    #[Test]
    public function missing_meta_stays_null(): void
    {
        $this->assertNull($this->metaOf(null));
    }

    /**
     * 이미지 크기처럼 값이 있는 meta 는 바뀌지 않고 나가는지 확인합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario meta=image
     *
     * @effects board_attachment_meta_value_kept
     */
    #[Test]
    public function image_meta_is_kept_as_is(): void
    {
        $this->assertSame('{"width":800,"height":600}', json_encode($this->metaOf(['width' => 800, 'height' => 600])));
    }

    /**
     * 가짜 첨부 하나를 직렬화해 `meta` 값을 돌려줍니다(DB 저장 없음).
     *
     * @param  array<string, mixed>|null  $meta  저장된 메타데이터
     * @return mixed 응답의 meta 값
     */
    private function metaOf(?array $meta): mixed
    {
        $attachment = new Attachment;
        $attachment->id = 999001;
        $attachment->hash = 'b1r13metashape';
        $attachment->mime_type = 'text/plain';
        $attachment->board_id = 0;
        $attachment->meta = $meta;

        return (new AttachmentResource($attachment))->toArray(Request::create('/'))['meta'];
    }
}
