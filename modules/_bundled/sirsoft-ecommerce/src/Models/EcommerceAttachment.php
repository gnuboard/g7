<?php

namespace Modules\Sirsoft\Ecommerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * 이커머스 범용 첨부 모델 — `ecommerce_attachments` (#887)
 *
 * 게시판 `board_attachments`·페이지 `page_attachments` 와 같은 모양의 이 모듈 첨부 표다. 쓰임새는 `collection` 으로 나누고,
 * 쓰임새별 규칙(전역 범위·소유 종류·저장 카테고리)은 범위 모델이 갖는다 — 상품 설명·공통 정보 편집기 본문 미디어는
 * `ContentMedia`(collection = `content_media`). 다른 쓰임새의 행을 건드리지 않도록 조회는 범위 모델로 한다.
 *
 * 소유자는 `attachmentable_type`(소유 모델 morph 값)·`attachmentable_id`(null 이면 저장 전 임시)이고, `path` 는 그 쓰임새 저장소
 * 카테고리 기준 상대 경로다.
 *
 * @property int $id
 * @property string $attachmentable_type
 * @property int|null $attachmentable_id
 * @property string $collection
 * @property string|null $temp_key
 * @property string $hash
 * @property string $original_filename
 * @property string $stored_filename
 * @property string $disk
 * @property string $path
 * @property string $mime_type
 * @property int $size
 * @property array|null $meta
 * @property int $order
 * @property int|null $created_by
 */
class EcommerceAttachment extends Model
{
    use SoftDeletes;

    /**
     * 테이블명
     */
    protected $table = 'ecommerce_attachments';

    /**
     * 대량 할당 가능한 속성
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'attachmentable_type', 'attachmentable_id', 'collection', 'temp_key', 'hash', 'original_filename', 'stored_filename',
        'disk', 'path', 'mime_type', 'size', 'meta', 'order', 'created_by',
    ];

    /**
     * 직렬화에서 숨길 속성 — 임시 키는 연결 권한이라 응답에 싣지 않는다
     *
     * @var array<int, string>
     */
    protected $hidden = ['temp_key'];

    /**
     * 속성 캐스팅
     *
     * @return array<string, string> 속성 => 캐스트 타입
     */
    protected function casts(): array
    {
        return [
            'attachmentable_id' => 'integer',
            'size' => 'integer',
            'meta' => 'array',
            'order' => 'integer',
            'created_by' => 'integer',
        ];
    }

    /**
     * 만들 때 표 전체에서 고유한 해시를 붙입니다(소프트 삭제 행 포함).
     *
     * @return void 반환값 없음
     */
    protected static function booted(): void
    {
        static::creating(function (EcommerceAttachment $attachment) {
            if (empty($attachment->hash)) {
                do {
                    $hash = Str::random(12);
                } while (EcommerceAttachment::withoutGlobalScopes()->withTrashed()->where('hash', $hash)->exists());
                $attachment->hash = $hash;
            }
        });
    }
}
