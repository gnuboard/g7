<?php

namespace Modules\Sirsoft\Page\Http\Resources;

use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;

/**
 * 페이지 버전 API 리소스 (관리자용)
 */
class PageVersionResource extends BaseApiResource
{
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
            'page_id' => $this->page_id,
            'version' => $this->version,
            'title' => $this->title,
            'content' => $this->content,
            'content_mode' => $this->content_mode ?? 'html',
            'seo_meta' => $this->seo_meta,
            'changes_summary' => $this->changes_summary,
            'creator' => $this->whenLoaded('creator', fn () => [
                'uuid' => $this->creator->uuid,
                'name' => $this->creator->name,
            ]),
            'created_at' => $this->created_at
                ? $this->formatDateTimeStringForUser($this->created_at)
                : null,
        ];
    }
}
