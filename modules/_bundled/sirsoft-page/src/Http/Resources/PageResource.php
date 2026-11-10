<?php

namespace Modules\Sirsoft\Page\Http\Resources;

use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;
use Modules\Sirsoft\Page\Services\PageContentMediaService;

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
            // 미발행 페이지 본문 미디어는 서명 주소로(관리자 화면의 <img>·<video> 는 인증 헤더가 없다), 발행 페이지는 정식 주소 (#883)
            // 서명은 이 페이지가 소유한 해시만 — 다른 페이지 소유 해시는 원본 페이지의 게이트를 따른다 (#887)
            'content' => app(PageContentMediaService::class)->presentContent($this->content, (bool) $this->published, (int) $this->id),
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
            // 미발행 페이지 첨부는 한시 서명 preview URL 로 직렬화한다.
            // 이 리소스는 게이트(pages.read 권한 미들웨어)를 통과한 관리자 응답에만 쓰이므로
            // 서명 발급이 곧 게이트 통과 자격의 위임이다. 발행 페이지는 무서명 공개 URL 유지.
            'attachments' => $this->whenLoaded(
                'attachments',
                fn () => PageAttachmentResource::collectionFor(
                    $this->attachments,
                    signedPreview: ! $this->published
                )
            ),
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
