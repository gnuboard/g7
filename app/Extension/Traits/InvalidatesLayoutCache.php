<?php

namespace App\Extension\Traits;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Repositories\LayoutRepositoryInterface;
use App\Enums\LayoutSourceType;
use App\Extension\Cache\CoreCacheDriver;
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
 * 레이아웃 내용 편집 시에만 현재 버전 키를 능동 삭제합니다 — 일반 응답 키와
 * 레이아웃 편집기 응답 키(`.meta` 접미사, `with_source_meta=1`) 두 가지 모두.
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
        $cache = $this->resolveLayoutCache();

        // 1. LayoutService 내부 캐시 (버전 없음 → 능동 삭제)
        $cache->forget("template.{$layout->template_id}.layout.{$layout->name}");

        // 2. 소스 해시 포함 키 (버전 없음 → 능동 삭제)
        if ($layout->source_type && $layout->source_identifier) {
            $sourceHash = md5($layout->source_type->value.$layout->source_identifier);
            $cache->forget("template.{$layout->template_id}.layout.{$layout->name}.{$sourceHash}");
        }

        // 3. PublicLayoutController 캐시 (버전 포함) — 일반 응답 + 편집기(`.meta`) 응답 두 키 모두.
        //    PublicLayoutController::serve() 가 `with_source_meta=1`(레이아웃 편집기) 응답을 `.meta`
        //    접미사 별도 키로 캐싱하므로, 그 키를 함께 삭제하지 않으면 템플릿/레이아웃 상태 변화
        //    (refresh-layout / activate / deactivate / uninstall 등) 후에도 편집기가 stale 캐시를
        //  받는다. 레이아웃
        //    저장 경로(LayoutService::clearPublicServingCache)는 이미 두 키를 지우므로 정합을 맞춘다.
        if ($templateIdentifier) {
            $cacheVersion = (int) $cache->get('ext.cache_version', 0);
            $cache->forget("layout.{$templateIdentifier}.{$layout->name}.v{$cacheVersion}");
            $cache->forget("layout.{$templateIdentifier}.{$layout->name}.v{$cacheVersion}.meta");
        }
    }

    /**
     * 레이아웃 캐시 무효화에 사용할 코어 캐시 드라이버를 반환합니다.
     *
     * 레이아웃 캐시 키(`template.*.layout.*`, `layout.*` 등)는 모두 코어 소유
     * 키이므로 항상 `g7:core:` 접두사 네임스페이스에서 저장/삭제되어야 한다.
     * 따라서 컨테이너의 `CacheInterface` 바인딩(모듈/플러그인 테스트가 일시적으로
     * `PluginCacheDriver` 등으로 재바인딩할 수 있음)에 의존하지 않고 항상
     * CoreCacheDriver 를 직접 생성한다. 의존 시 누수된 바인딩 때문에
     * `g7:plugin.*` 네임스페이스로 forget 이 빗나가 캐시가 실제로 삭제되지 않는다.
     */
    private function resolveLayoutCache(): CacheInterface
    {
        return new CoreCacheDriver(config('cache.default', 'array'));
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
