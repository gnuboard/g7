<?php

namespace Plugins\Sirsoft\Ckeditor5\Http\Resources;

use App\DisplayName\DisplayNameAreaRegistry;
use App\DisplayName\MemberNamePresenter;
use App\Http\Resources\BaseApiResource;
use App\Models\User;
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
            ...$this->uploaderNameFields($request),
            'created_at' => $this->formatDateTimeStringForUser($this->created_at),
            // 원본(공개 자산 디스크면 CDN 직접 주소) / 썸네일 기준(항상 API 서빙 주소)
            'download_url' => $this->download_url,
            'preview_url' => $this->preview_url,
            'referenced' => (bool) ($this->referenced ?? true),
        ];
    }

    /**
     * 올린 사람의 이름 묶음을 반환합니다.
     *
     * 이름은 관리자 영역(`core:admin`) 표시 이름이다(이 리소스는 관리 화면 전용이다). 실명은 보는 관리자에게 회원 조회 권한이
     * 있고 실명이 표시 이름과 다를 때만 `uploader_real_name` 으로 더해진다. 관계를 읽지 않았거나 올린 사람이 없으면 이름은 null 이다.
     *
     * @param  Request  $request  HTTP 요청
     * @return array<string, string|null> `['uploader_name' => 표시 이름]` 또는 여기에 `uploader_real_name` 이 더해진 배열
     */
    private function uploaderNameFields(Request $request): array
    {
        $uploader = $this->resource->relationLoaded('uploader') ? $this->resource->getRelation('uploader') : null;
        if (! $uploader instanceof User) {
            return ['uploader_name' => null];
        }

        $presenter = app(MemberNamePresenter::class);
        $shown = $presenter->name($uploader, $request, DisplayNameAreaRegistry::AREA_ADMIN);
        $real = $presenter->realName($uploader, $shown, $request);

        return $real === null
            ? ['uploader_name' => $shown]
            : ['uploader_name' => $shown, 'uploader_real_name' => $real];
    }
}
