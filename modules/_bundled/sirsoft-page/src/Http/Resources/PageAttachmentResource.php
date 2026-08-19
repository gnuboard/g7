<?php

namespace Modules\Sirsoft\Page\Http\Resources;

use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Sirsoft\Page\Models\PageAttachment;

/**
 * 페이지 첨부파일 API 리소스
 */
class PageAttachmentResource extends BaseApiResource
{
    /**
     * 미발행 첨부의 <img> 렌더용 한시 서명 preview URL 발급 여부.
     *
     * 게이트를 통과한 응답(관리자 상세 등)의 직렬화 시점에만 참이 된다 —
     * 발행 페이지는 무서명 공개 URL 을 유지한다 (SEO·캐시 안정성).
     */
    private bool $signedPreview = false;

    /**
     * 첨부파일 목록을 리소스 배열로 변환합니다.
     *
     * @param  iterable<int, PageAttachment>|null  $attachments  첨부파일 목록
     * @param  bool  $signedPreview  한시 서명 preview URL 발급 여부 (미발행 페이지 전용)
     * @return array<int, self>
     */
    public static function collectionFor($attachments, bool $signedPreview = false): array
    {
        return Collection::make($attachments ?? [])
            ->map(function ($attachment) use ($signedPreview) {
                $resource = new self($attachment);
                $resource->signedPreview = $signedPreview;

                return $resource;
            })
            ->all();
    }

    /**
     * 리소스를 배열로 변환합니다.
     *
     * @param  Request  $request  HTTP 요청 객체
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'hash' => $this->hash,
            'original_filename' => $this->original_filename,
            'mime_type' => $this->mime_type,
            'size' => $this->size,
            'collection' => $this->collection,
            'order' => $this->order,
            'is_image' => $this->isImage(),
            'download_url' => $this->resource->downloadUrl(),
            'preview_url' => $this->resource->previewUrl(signed: $this->signedPreview),
            'created_at' => $this->created_at
                ? $this->formatDateTimeStringForUser($this->created_at)
                : null,
        ];
    }
}
