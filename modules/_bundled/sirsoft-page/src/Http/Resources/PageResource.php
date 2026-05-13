<?php

namespace Modules\Sirsoft\Page\Http\Resources;

use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;

/**
 * 페이지 API 리소스 (관리자용)
 */
class PageResource extends BaseApiResource
{
    /**
     * 리소스를 배열로 변환합니다 (상세/폼용 - 다국어 원본 배열 포함).
     *
     * @param  Request  $request  HTTP 요청 객체
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'title' => $this->title,
            'content' => $this->content,
            'content_mode' => $this->content_mode ?? 'html',
            'published' => $this->published,
            'published_at' => $this->published_at
                ? $this->formatDateTimeStringForUser($this->published_at)
                : null,
            'seo_meta' => $this->seo_meta,
            'current_version' => $this->current_version,
            'creator' => $this->whenLoaded('creator', fn () => [
                'uuid' => $this->creator->uuid,
                'name' => $this->creator->name,
            ]),
            'updater' => $this->whenLoaded('updater', fn () => [
                'uuid' => $this->updater->uuid,
                'name' => $this->updater->name,
            ]),
            'attachments' => PageAttachmentResource::collection($this->whenLoaded('attachments')),
            ...$this->formatTimestamps(),
            ...$this->resourceMeta($request),
        ];
    }

    /**
     * 리소스별 권한 매핑을 반환합니다.
     *
     * @return array<string, string>
     */
    protected function abilityMap(): array
    {
        return [
            'can_create' => 'sirsoft-page.pages.create',
            'can_update' => 'sirsoft-page.pages.update',
            'can_delete' => 'sirsoft-page.pages.delete',
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
     * 목록용 경량 배열로 변환합니다 (다국어 필드는 현재 로케일로 변환).
     *
     * @param  Request|null  $request  HTTP 요청
     * @return array<string, mixed>
     */
    public function toListArray(?Request $request = null): array
    {
        $request = $request ?? request();

        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'title' => $this->getLocalizedField('title'),
            'published' => $this->published,
            'published_at' => $this->published_at
                ? $this->formatDateTimeStringForUser($this->published_at)
                : null,
            'current_version' => $this->current_version,
            'creator' => $this->whenLoaded('creator', fn () => [
                'uuid' => $this->creator->uuid,
                'name' => $this->creator->name,
            ]),
            ...$this->formatTimestamps(),
            ...$this->resourceMeta($request),
        ];
    }
}
