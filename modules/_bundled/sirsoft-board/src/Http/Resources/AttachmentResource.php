<?php

namespace Modules\Sirsoft\Board\Http\Resources;

use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Sirsoft\Board\Models\Attachment;

/**
 * 첨부파일 API 리소스
 *
 * 게시판 모듈의 Attachment 모델을 위한 리소스입니다.
 * preview_url을 포함하여 이미지 미리보기를 지원합니다.
 */
class AttachmentResource extends BaseApiResource
{
    /**
     * 서명 preview URL 발급 대상 게시판 슬러그 (null 이면 무서명 공개 URL).
     *
     * 비밀글·삭제글처럼 무서명 preview 가 게이트에 차단되는 게시글의 첨부를,
     * 게이트를 통과한 응답(PostResource 상세 직렬화)에 한해 <img> 렌더 가능한
     * 한시 서명 URL 로 직렬화하기 위한 플래그다.
     */
    private ?string $signedPreviewSlug = null;

    /**
     * 첨부파일 목록을 리소스 배열로 변환합니다.
     *
     * @param  iterable<int, Attachment>|null  $attachments  첨부파일 목록
     * @param  string|null  $signedPreviewSlug  서명 preview URL 발급 대상 슬러그 (비밀글·삭제글 전용)
     * @return array<int, self>
     */
    public static function collectionFor($attachments, ?string $signedPreviewSlug = null): array
    {
        return Collection::make($attachments ?? [])
            ->map(function ($attachment) use ($signedPreviewSlug) {
                $resource = new self($attachment);
                $resource->signedPreviewSlug = $signedPreviewSlug;

                return $resource;
            })
            ->values()
            ->all();
    }

    /**
     * 리소스를 배열로 변환합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @return array<string, mixed> 변환된 배열 데이터
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'hash' => $this->hash,
            'original_filename' => $this->original_filename,
            'mime_type' => $this->mime_type,
            'size' => $this->size,
            'size_formatted' => $this->size_formatted,
            'collection' => $this->collection,
            'order' => $this->order,
            'download_url' => $this->download_url,
            'preview_url' => $this->signedPreviewSlug !== null && $this->resource->is_image
                ? $this->resource->previewUrlForSlug($this->signedPreviewSlug, signed: true)
                : $this->preview_url,
            'is_image' => $this->is_image,
            'meta' => $this->meta,
        ];
    }
}
