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
     * 첨부파일 목록을 리소스 배열로 변환합니다.
     *
     * @param  iterable<int, PageAttachment>|null  $attachments  첨부파일 목록
     * @return array<int, self>
     */
    public static function collectionFor($attachments): array
    {
        return Collection::make($attachments ?? [])
            ->map(fn ($attachment) => new self($attachment))
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
            'preview_url' => $this->resource->previewUrl(),
            'created_at' => $this->created_at
                ? $this->formatDateTimeStringForUser($this->created_at)
                : null,
        ];
    }
}
