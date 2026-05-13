<?php

namespace Modules\Sirsoft\Board\Http\Resources;

use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;

/**
 * 첨부파일 API 리소스
 *
 * 게시판 모듈의 Attachment 모델을 위한 리소스입니다.
 * preview_url을 포함하여 이미지 미리보기를 지원합니다.
 */
class AttachmentResource extends BaseApiResource
{
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
            'preview_url' => $this->preview_url,
            'is_image' => $this->is_image,
            'meta' => $this->meta,
        ];
    }
}
