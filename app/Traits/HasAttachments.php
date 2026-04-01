<?php

namespace App\Traits;

use App\Models\Attachment;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * 첨부파일 관계를 제공하는 Trait
 *
 * 모델에서 use HasAttachments; 하여 첨부파일 기능을 사용할 수 있습니다.
 */
trait HasAttachments
{
    /**
     * 모든 첨부파일 관계
     *
     * @return MorphMany
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachmentable')
            ->ordered();
    }

    /**
     * 특정 컬렉션의 첨부파일 조회
     *
     * @param string $collection 컬렉션명
     * @return Collection<int, Attachment>
     */
    public function getAttachments(string $collection = 'default'): Collection
    {
        return $this->attachments()
            ->collection($collection)
            ->get();
    }

    /**
     * 첫 번째 첨부파일 조회
     *
     * @param string $collection 컬렉션명
     * @return Attachment|null 첫 번째 첨부파일 또는 null
     */
    public function getFirstAttachment(string $collection = 'default'): ?Attachment
    {
        return $this->attachments()
            ->collection($collection)
            ->first();
    }

    /**
     * 특정 컬렉션의 이미지만 조회
     *
     * @param string $collection 컬렉션명
     * @return Collection<int, Attachment>
     */
    public function getImages(string $collection = 'default'): Collection
    {
        return $this->attachments()
            ->collection($collection)
            ->images()
            ->get();
    }

    /**
     * 첨부파일 개수 조회
     *
     * @param string|null $collection 컬렉션명 (null이면 전체)
     * @return int 첨부파일 개수
     */
    public function getAttachmentsCount(?string $collection = null): int
    {
        $query = $this->attachments();

        if ($collection !== null) {
            $query->collection($collection);
        }

        return $query->count();
    }

    /**
     * 첨부파일이 있는지 확인
     *
     * @param string|null $collection 컬렉션명 (null이면 전체)
     * @return bool 첨부파일 존재 여부
     */
    public function hasAttachments(?string $collection = null): bool
    {
        return $this->getAttachmentsCount($collection) > 0;
    }
}
