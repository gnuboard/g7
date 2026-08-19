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
     * 미발행 페이지 관리자 미리보기 여부.
     *
     * true 이면 첨부 URL을 발행 가드가 없는 'admin' 컨텍스트로 생성하여
     * 관리자가 미발행 페이지의 첨부까지 미리볼 수 있도록 합니다.
     */
    protected bool $preview = false;

    /**
     * 미리보기(미발행 관리자 조회) 여부를 설정합니다.
     *
     * @param  bool  $preview  미리보기 여부
     * @return $this
     */
    public function withPreview(bool $preview): self
    {
        $this->preview = $preview;

        return $this;
    }

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
            'is_preview' => $this->preview,
            'published_at' => $this->published_at
                ? $this->formatDateTimeStringForUser($this->published_at)
                : null,
            'seo_meta' => $this->seo_meta,
            'current_version' => $this->current_version,
            // 미발행 페이지는 pages.read 관리자의 미리보기(is_preview)로만 이 리소스에
            // 도달하므로(공개 게이트가 게스트를 404 차단), 그 화면의 <img> 썸네일용으로
            // 한시 서명 preview URL 을 직렬화한다. 발행 페이지는 무서명 공개 URL 유지.
            'attachments' => $this->whenLoaded(
                'attachments',
                fn () => PageAttachmentResource::collectionFor(
                    $this->attachments,
                    signedPreview: ! $this->published
                )
            ),
            'created_at' => $this->created_at
                ? $this->formatDateTimeStringForUser($this->created_at)
                : null,
            'updated_at' => $this->updated_at
                ? $this->formatDateTimeStringForUser($this->updated_at)
                : null,
        ];
    }
}
