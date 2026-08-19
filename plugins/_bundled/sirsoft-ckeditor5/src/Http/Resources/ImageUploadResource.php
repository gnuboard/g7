<?php

namespace Plugins\Sirsoft\Ckeditor5\Http\Resources;

use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;

/**
 * CKEditor5 업로드 이미지 관리 화면 API 리소스
 *
 * `referenced` 는 저장 컬럼이 아니라 조회 시 판정해 붙인 응답 전용 값입니다
 * (ImageUploadAdminService 참조). 판정하지 못한 행은 안전 방향인 "참조됨" 으로 봅니다.
 */
class ImageUploadResource extends BaseApiResource
{
    /**
     * 리소스를 배열로 변환합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'hash' => $this->hash,
            'original_name' => $this->original_name,
            'file_size' => (int) $this->file_size,
            'mime_type' => $this->mime_type,
            'uploaded_by' => $this->uploaded_by,
            'uploader_name' => $this->relationLoaded('uploader') ? $this->uploader?->name : null,
            'created_at' => $this->formatDateTimeStringForUser($this->created_at),
            'download_url' => $this->download_url,
            'referenced' => (bool) ($this->referenced ?? true),
        ];
    }
}
