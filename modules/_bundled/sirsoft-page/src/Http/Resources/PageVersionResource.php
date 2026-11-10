<?php

namespace Modules\Sirsoft\Page\Http\Resources;

use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;
use Modules\Sirsoft\Page\Services\PageContentMediaService;

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
            // 버전 이력 미리보기도 페이지 응답과 같은 규칙 — 미발행 페이지면 이 페이지 소유 본문 미디어만 서명 주소로
            // (관리자 화면의 <img>·<video> 요청에는 인증 헤더가 없어 서명 없는 주소는 403 으로 깨진다, #887)
            'content' => app(PageContentMediaService::class)->presentContent(
                $this->content,
                (bool) ($this->page?->published ?? false),
                (int) $this->page_id,
            ),
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
