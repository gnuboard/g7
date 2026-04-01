<?php

namespace App\Extension\Traits;

use App\Contracts\Repositories\LayoutRepositoryInterface;
use App\Enums\LayoutSourceType;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * 레이아웃 캐시 무효화 공통 로직을 제공하는 Trait
 *
 * 버전 없는 내부 캐시 키를 능동 삭제합니다:
 * 1. template.{templateId}.layout.{layoutName} - LayoutService에서 사용
 * 2. template.{templateId}.layout.{layoutName}.{sourceHash} - 모듈/플러그인 레이아웃
 *
 * 버전 포함 캐시 (layout.{identifier}.{name}.v{version})는
 * incrementExtensionCacheVersion() + TTL로 무효화됩니다.
 * 레이아웃 내용 편집 시에만 현재 버전 키를 능동 삭제합니다.
 *
 * 이 Trait를 사용하는 클래스는 반드시 다음 속성/메서드를 제공해야 합니다:
 * - $layoutRepository: LayoutRepositoryInterface 인스턴스
 *
 * @property LayoutRepositoryInterface $layoutRepository
 */
trait InvalidatesLayoutCache
{
    /**
     * 확장(모듈/플러그인)의 레이아웃 캐시를 무효화합니다.
     *
     * @param  string  $extensionIdentifier  확장 식별자 (모듈 또는 플러그인)
     * @param  string  $extensionType  확장 타입 ('module' 또는 'plugin')
     */
    protected function invalidateExtensionLayoutCache(string $extensionIdentifier, string $extensionType = 'module'): void
    {
        try {
            // 확장 타입에 따라 올바른 소스 타입으로 레이아웃 조회
            $sourceType = $extensionType === 'plugin' ? LayoutSourceType::Plugin : LayoutSourceType::Module;
            $extensionLayouts = $this->layoutRepository->getBySourceIdentifier($extensionIdentifier, $sourceType);

            foreach ($extensionLayouts as $layout) {
                // 레이아웃에 연결된 템플릿 식별자 조회
                $templateIdentifier = $layout->template?->identifier ?? '';
                $this->forgetLayoutCacheKeys($layout, $templateIdentifier);
            }

            Log::info("{$extensionType} 레이아웃 캐시 무효화 완료: {$extensionIdentifier}");
        } catch (\Exception $e) {
            Log::warning("레이아웃 캐시 무효화 중 오류: {$extensionIdentifier}", [
                'type' => $extensionType,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 템플릿의 레이아웃 캐시를 무효화합니다.
     *
     * @param  int  $templateId  템플릿 ID
     * @param  string  $templateIdentifier  템플릿 식별자 (PublicLayoutController 캐시 삭제에 필요)
     */
    protected function invalidateTemplateLayoutCache(int $templateId, string $templateIdentifier = ''): void
    {
        try {
            // 개별 레이아웃 캐시 삭제
            $layouts = $this->layoutRepository->getByTemplateId($templateId);

            foreach ($layouts as $layout) {
                $this->forgetLayoutCacheKeys($layout, $templateIdentifier);
            }

            if ($templateIdentifier) {
                Log::info("템플릿 레이아웃 캐시 무효화 완료: {$templateIdentifier}");
            }
        } catch (\Exception $e) {
            Log::warning('레이아웃 캐시 무효화 중 오류', [
                'template_id' => $templateId,
                'template_identifier' => $templateIdentifier,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 단일 레이아웃의 캐시 키를 삭제합니다.
     *
     * 버전 없는 내부 캐시는 능동 삭제합니다.
     * 버전 포함 PublicLayoutController 캐시는 현재 버전 키만 삭제합니다
     * (레이아웃 내용 편집 시 버전 변경 없이 내용만 바뀌는 경우에 필요).
     *
     * @param  object  $layout  레이아웃 모델 (template_id, name, source_type, source_identifier 필드 필요)
     * @param  string  $templateIdentifier  템플릿 식별자 (PublicLayoutController 캐시 삭제에 필요)
     */
    protected function forgetLayoutCacheKeys(object $layout, string $templateIdentifier = ''): void
    {
        // 1. LayoutService 내부 캐시 (버전 없음 → 능동 삭제)
        Cache::forget("template.{$layout->template_id}.layout.{$layout->name}");

        // 2. 소스 해시 포함 키 (버전 없음 → 능동 삭제)
        if ($layout->source_type && $layout->source_identifier) {
            $sourceHash = md5($layout->source_type->value.$layout->source_identifier);
            Cache::forget("template.{$layout->template_id}.layout.{$layout->name}.{$sourceHash}");
        }

        // 3. PublicLayoutController 캐시 (버전 포함)
        //    레이아웃 내용 편집 시 현재 버전 키 삭제 (버전 변경 없이 내용만 바뀜)
        if ($templateIdentifier) {
            $cacheVersion = (int) Cache::get('extension_cache_version', 0);
            Cache::forget("layout.{$templateIdentifier}.{$layout->name}.v{$cacheVersion}");
        }
    }

    /**
     * 레이아웃 캐시 키를 생성합니다.
     *
     * @param  int  $templateId  템플릿 ID
     * @param  string  $layoutName  레이아웃 이름
     * @param  string|null  $sourceType  소스 타입 (선택)
     * @param  string|null  $sourceIdentifier  소스 식별자 (선택)
     * @return string 캐시 키
     */
    protected function buildLayoutCacheKey(
        int $templateId,
        string $layoutName,
        ?string $sourceType = null,
        ?string $sourceIdentifier = null
    ): string {
        $baseKey = "template.{$templateId}.layout.{$layoutName}";

        if ($sourceType && $sourceIdentifier) {
            $sourceHash = md5($sourceType.$sourceIdentifier);

            return "{$baseKey}.{$sourceHash}";
        }

        return $baseKey;
    }
}
