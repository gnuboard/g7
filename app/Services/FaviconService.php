<?php

namespace App\Services;

use App\Contracts\Repositories\AttachmentRepositoryInterface;
use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Models\Attachment;
use App\Support\Favicon\BundledFavicon;
use App\Support\Favicon\FaviconPolicy;

/**
 * 파비콘 우선순위 해석 — 단일 지점 (#434)
 *
 * 우선순위: ① 그 화면 종류(사용자·관리자)의 활성 템플릿 설정 `favicon` > ② 코어 일반 탭 `site_favicon` > ③ 번들 기본
 * (운영자가 직접 바꾼 `public/favicon.ico` 가 있으면 그것). ①·② 의 「유효 첨부」는 행이 있고 그 계층의 컬렉션 소속이며
 * MIME 이 파비콘 목록 안인 첨부다 — 첨부 행이 지워졌거나 형식이 맞지 않으면 깨진 링크 대신 다음 계층으로 내려간다.
 *
 * 결과는 요청 안에서 메모이즈한다(SPA 셸·SEO 렌더가 한 요청에서 한 번씩만 부른다).
 */
class FaviconService
{
    /**
     * 요청 내 해석 캐시 ("target|template" => 결과)
     *
     * @var array<string, array<string, mixed>>
     */
    private array $resolved = [];

    /**
     * @param  TemplateSettingsService  $templateSettingsService  템플릿 설정 서비스
     * @param  TemplateRepositoryInterface  $templateRepository  템플릿 저장소
     * @param  AttachmentRepositoryInterface  $attachmentRepository  첨부 저장소
     */
    public function __construct(
        private TemplateSettingsService $templateSettingsService,
        private TemplateRepositoryInterface $templateRepository,
        private AttachmentRepositoryInterface $attachmentRepository,
    ) {}

    /**
     * 화면 종류별 파비콘을 해석합니다.
     *
     * @param  string  $target  화면 종류 (`user`|`admin`)
     * @param  string|null  $templateIdentifier  템플릿 식별자 (생략하면 그 종류의 활성 템플릿)
     * @return array{source: string, links: array<int, array<string, string>>, template_identifier: string|null, attachment_id: int|null} 결과
     */
    public function resolve(string $target, ?string $templateIdentifier = null): array
    {
        $templateIdentifier ??= $this->activeTemplateIdentifier($target);
        $cacheKey = $target.'|'.($templateIdentifier ?? '');

        if (isset($this->resolved[$cacheKey])) {
            return $this->resolved[$cacheKey];
        }

        if ($templateIdentifier !== null) {
            $attachment = $this->templateSettingsService->faviconAttachment($templateIdentifier);

            if ($attachment !== null) {
                return $this->resolved[$cacheKey] = $this->attachmentResult('template', $attachment, $templateIdentifier);
            }
        }

        $attachment = $this->coreFaviconAttachment();

        if ($attachment !== null) {
            return $this->resolved[$cacheKey] = $this->attachmentResult('core', $attachment, $templateIdentifier);
        }

        return $this->resolved[$cacheKey] = [
            'source' => BundledFavicon::isOperatorIco(public_path('favicon.ico')) ? 'custom_ico' : 'bundled',
            'links' => BundledFavicon::links(),
            'template_identifier' => $templateIdentifier,
            'attachment_id' => null,
        ];
    }

    /**
     * 관리자 안내용 요약 — 일반 탭 파비콘 칸 아래 「○○ 템플릿 설정의 파비콘이 우선 적용됩니다」.
     *
     * @return array{user: array<string, mixed>, admin: array<string, mixed>} 화면 종류별 요약
     */
    public function describe(): array
    {
        $summary = [];

        foreach (['user', 'admin'] as $target) {
            $resolved = $this->resolve($target);
            $identifier = $resolved['template_identifier'];
            $template = $identifier !== null ? $this->templateRepository->findByIdentifier($identifier) : null;

            $summary[$target] = [
                'source' => $resolved['source'],
                'template_identifier' => $identifier,
                'template_name' => $template?->getLocalizedName(),
                'settings_route' => $identifier !== null && $this->templateSettingsService->hasSettings($identifier)
                    ? '/admin/templates/'.$identifier.'/settings'
                    : null,
            ];
        }

        return $summary;
    }

    /**
     * 요청 내 캐시를 비웁니다 (저장 직후 같은 요청에서 다시 해석할 때).
     *
     * @return void 반환값 없음
     */
    public function flush(): void
    {
        $this->resolved = [];
        BundledFavicon::flush();
    }

    /**
     * 화면 종류의 활성 템플릿 식별자.
     *
     * @param  string  $target  화면 종류
     * @return string|null 식별자
     */
    private function activeTemplateIdentifier(string $target): ?string
    {
        try {
            $template = $this->templateRepository->findActiveByType($target);
        } catch (\Throwable $e) {
            return null;
        }

        return $template !== null ? (string) $template->identifier : null;
    }

    /**
     * 코어 일반 탭 `site_favicon` 의 첫 유효 첨부.
     *
     * @return Attachment|null 첨부
     */
    private function coreFaviconAttachment(): ?Attachment
    {
        $value = g7_core_settings('general.site_favicon', []);

        if (! is_array($value) || $value === []) {
            return null;
        }

        $ids = array_values(array_filter(array_map(
            fn ($item) => is_array($item) ? ($item['id'] ?? null) : $item,
            $value
        ), 'is_numeric'));

        if ($ids === []) {
            return null;
        }

        return $this->attachmentRepository->findByIds(array_map('intval', $ids))->first(
            fn ($attachment) => $attachment->collection === FaviconPolicy::CORE_COLLECTION
                && FaviconPolicy::responseMime($attachment->mime_type) !== null
        );
    }

    /**
     * 업로드 첨부 계층의 결과를 만듭니다 — png 면 apple-touch-icon 도 같은 파일로 낸다.
     *
     * @param  string  $source  계층 (`template`|`core`)
     * @param  Attachment  $attachment  첨부
     * @param  string|null  $templateIdentifier  템플릿 식별자
     * @return array{source: string, links: array<int, array<string, string>>, template_identifier: string|null, attachment_id: int|null} 결과
     */
    private function attachmentResult(string $source, Attachment $attachment, ?string $templateIdentifier): array
    {
        $mime = (string) FaviconPolicy::responseMime($attachment->mime_type);
        $href = FaviconPolicy::url((string) $attachment->hash);
        $links = [['rel' => 'icon', 'type' => $mime, 'href' => $href]];

        if ($mime === 'image/png') {
            $links[] = ['rel' => 'apple-touch-icon', 'href' => $href];
        }

        return [
            'source' => $source,
            'links' => $links,
            'template_identifier' => $templateIdentifier,
            'attachment_id' => (int) $attachment->id,
        ];
    }
}
