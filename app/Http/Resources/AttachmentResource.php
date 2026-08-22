<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/**
 * 첨부파일 API 리소스
 */
class AttachmentResource extends BaseApiResource
{
    /**
     * 리소스를 배열로 변환
     *
     * @param Request $request HTTP 요청 객체
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getValue('id'),
            'hash' => $this->getValue('hash'),
            'original_filename' => $this->getValue('original_filename'),
            'mime_type' => $this->getValue('mime_type'),
            'size' => $this->getValue('size'),
            'size_formatted' => $this->size_formatted,
            'collection' => $this->getValue('collection'),
            'order' => $this->getValue('order'),
            'download_url' => $this->download_url,
            'is_image' => $this->is_image,
            'meta' => $this->getValue('meta'),
            'source_type' => $this->getValue('source_type')?->value,
            'source_identifier' => $this->getValue('source_identifier'),

            // 업로더 정보 (로드된 경우)
            'creator' => $this->whenLoaded('creator', function () {
                return [
                    'uuid' => $this->creator->uuid,
                    'name' => $this->creator->name,
                ];
            }),

            ...$this->formatTimestamps(),
            ...$this->resourceMeta($request),
        ];
    }

    /**
     * 소유자 필드명을 반환합니다.
     *
     * @return string|null 소유자 필드명
     */
    protected function ownerField(): ?string
    {
        return 'created_by';
    }

    /**
     * 리소스별 권한 매핑을 반환합니다.
     *
     * @return array<string, string>
     */
    protected function abilityMap(): array
    {
        return [
            'can_update' => 'core.attachments.update',
            'can_delete' => 'core.attachments.delete',
        ];
    }

    /**
     * 목록용 간단한 형태의 배열을 반환합니다.
     *
     * 주의: 이 메서드는 toArray() 외부에서 수동 호출되므로
     * $this->when() 대신 삼항 연산자를 사용해야 합니다.
     * $this->when()은 Laravel이 toArray()를 처리할 때만 MissingValue를 필터링합니다.
     *
     * @param  Request|null  $request  HTTP 요청
     * @return array<string, mixed>
     */
    public function toListArray(?Request $request = null): array
    {
        $request = $request ?? request();

        return [
            'id' => $this->getValue('id'),
            'hash' => $this->getValue('hash'),
            'original_filename' => $this->getValue('original_filename'),
            'mime_type' => $this->getValue('mime_type'),
            'size_formatted' => $this->size_formatted,
            'order' => $this->getValue('order'),
            'download_url' => $this->download_url,
            'is_image' => $this->is_image,
            ...$this->resourceMeta($request),
        ];
    }
}
