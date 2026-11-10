<?php

namespace Modules\Sirsoft\Ecommerce\Models;

use App\Enums\ContentMediaKind;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Modules\Sirsoft\Ecommerce\Enums\ContentMediaOwnerType;

/**
 * 이커머스 본문 미디어 모델 — 상품 설명·공통 정보 편집기 본문의 이미지·동영상·오디오 (#883 4단계)
 *
 * 저장은 이 모듈의 범용 첨부 표 `ecommerce_attachments` 의 `collection = content_media` 행이다(#887 — 개발 중 전용 표
 * `ecommerce_content_media` 를 옮김). 이 모델은 그 컬렉션만 보는 범위 모델이다 — 조회·생성 모두 전역 범위로 묶여 같은 표의
 * 다른 쓰임새 행을 건드리지 않는다.
 *
 * 열 대응:
 *
 * | 본문 미디어 | `ecommerce_attachments` |
 * | --- | --- |
 * | 소유 종류 `owner_type` | `attachmentable_type` (상품 / 상품 공통 정보 모델의 morph 값, 임시 행도 가짐) |
 * | 소유자 `owner_id` | `attachmentable_id` (null: 저장 전 임시) |
 * | 종류 `kind` | `mime_type` 으로 판정 (`ContentMediaKind::fromMime`) |
 * | 파일 위치 | `disk` + `path` (모듈 저장소 카테고리 `content-media` 기준 `{owner_type}/Y/m/d/{uuid}.ext`) |
 *
 * @property-read ContentMediaOwnerType|null $owner_type
 * @property-read int|null $owner_id
 * @property-read string $kind
 */
class ContentMedia extends EcommerceAttachment
{
    /** 첨부 컬렉션 */
    public const COLLECTION = 'content_media';

    /** 모듈 저장소 카테고리 (`ContentMediaService::CATEGORY` 와 같은 값) */
    public const STORAGE_CATEGORY = 'content-media';

    /**
     * 테이블명 — 범용 첨부 표 (부모와 같은 값, 클래스 이름에서 추론하면 `content_media` 가 되므로 명시)
     */
    protected $table = 'ecommerce_attachments';

    /**
     * 컬렉션 범위·고정값을 겁니다(부모의 해시 생성은 그대로 돈다).
     *
     * @return void 반환값 없음
     */
    protected static function booted(): void
    {
        parent::booted();

        static::addGlobalScope('ecommerce_content_media', function (Builder $query) {
            $query->where($query->getModel()->qualifyColumn('collection'), self::COLLECTION);
        });

        static::creating(function (ContentMedia $media) {
            $media->collection = self::COLLECTION;
        });
    }

    /**
     * 소유 종류 (`attachmentable_type` 에서).
     *
     * @return Attribute<ContentMediaOwnerType|null, never> 소유 종류
     */
    protected function ownerType(): Attribute
    {
        return Attribute::get(fn () => ContentMediaOwnerType::fromMorphType($this->attachmentable_type));
    }

    /**
     * 소유자 ID (`attachmentable_id` — null 이면 저장 전 임시).
     *
     * @return Attribute<int|null, never> 소유자 ID
     */
    protected function ownerId(): Attribute
    {
        return Attribute::get(fn () => $this->attachmentable_id !== null ? (int) $this->attachmentable_id : null);
    }

    /**
     * 종류(image·video·audio) — 내용으로 판정해 저장한 MIME 에서 정한다. 판정되지 않으면 image(업로드 때와 같은 기본).
     *
     * @return Attribute<string, never> 종류
     */
    protected function kind(): Attribute
    {
        return Attribute::get(fn () => (ContentMediaKind::fromMime((string) $this->mime_type) ?? ContentMediaKind::Image)->value);
    }

    /**
     * 모듈 저장소 카테고리(`content-media`) 기준 경로 — 행 `path` 그대로다.
     *
     * @return string 카테고리 기준 상대 경로
     */
    public function storagePath(): string
    {
        return (string) $this->path;
    }
}
