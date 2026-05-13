<?php

namespace Modules\Sirsoft\Page\Http\Resources;

use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;

/**
 * 페이지 API 리소스 (공개용)
 *
 * 발행된 페이지 공개 조회 시 사용합니다.
 * 관리자 전용 정보(creator, updater 등)는 제외됩니다.
 */
class PublicPageResource extends BaseApiResource
{
    /**
     * 리소스를 배열로 변환합니다.
     *
     * @param  Request  $request  HTTP 요청 객체
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $locale = app()->getLocale();
        $fallback = config('app.fallback_locale', 'ko');

        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'title' => $this->getLocalizedTitle(),
            'content' => is_array($this->content)
                ? ($this->content[$locale] ?? $this->content[$fallback] ?? (! empty($this->content) ? array_values($this->content)[0] : ''))
                : (string) ($this->content ?? ''),
            'content_mode' => $this->content_mode ?? 'html',
            'published_at' => $this->published_at
                ? $this->formatDateTimeStringForUser($this->published_at)
                : null,
            'seo_meta' => $this->seo_meta,
            'current_version' => $this->current_version,
            'attachments' => PageAttachmentResource::collection($this->whenLoaded('attachments')),
            'created_at' => $this->created_at
                ? $this->formatDateTimeStringForUser($this->created_at)
                : null,
            'updated_at' => $this->updated_at
                ? $this->formatDateTimeStringForUser($this->updated_at)
                : null,
        ];
    }
}
