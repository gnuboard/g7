<?php

namespace App\Services;

use App\Contracts\Repositories\LayoutRepositoryInterface;
use App\Extension\HookManager;
use App\Extension\ModuleManager;
use App\Models\TemplateLayout;
use Composer\Semver\Semver;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * 레이아웃 우선순위 해석 서비스
 *
 * 레이아웃 이름으로 실제 로드할 레이아웃을 결정합니다.
 * 우선순위: 템플릿 오버라이드 > 모듈 기본 레이아웃
 */
class LayoutResolverService
{
    /**
     * 캐시 히트/미스 카운터
     */
    private array $cacheStats = [
        'hits' => 0,
        'misses' => 0,
    ];

    /**
     * LayoutRepository, ModuleManager 주입
     */
    public function __construct(
        private LayoutRepositoryInterface $layoutRepository,
        private ModuleManager $moduleManager
    ) {}

    /**
     * 레이아웃 해석 결과 캐시 TTL을 반환합니다.
     */
    private function getCacheTtl(): int
    {
        return config('template.layout.cache_ttl', 3600);
    }

    /**
     * 레이아웃 이름으로 실제 로드할 레이아웃 결정
     *
     * 우선순위:
     * 1. 템플릿 오버라이드 (source_type = 'template', 오버라이드 경로)
     * 2. 모듈 기본 레이아웃 (source_type = 'module')
     *
     * @param  string  $layoutName  예: sirsoft-ecommerce_admin_products_index
     * @param  int  $templateId  현재 활성 템플릿 ID
     */
    public function resolve(string $layoutName, int $templateId): ?TemplateLayout
    {
        // Before 훅 - 레이아웃 해석 전
        HookManager::doAction('core.layout_resolver.before_resolve', $layoutName, $templateId);

        // 캐시 확인
        $cacheKey = $this->getResolutionCacheKey($layoutName, $templateId);
        $cachedId = Cache::get($cacheKey);

        if ($cachedId !== null) {
            $this->cacheStats['hits']++;

            Log::debug('레이아웃 해석 캐시 히트', [
                'layout_name' => $layoutName,
                'template_id' => $templateId,
                'cache_key' => $cacheKey,
            ]);

            // 캐시된 ID로 레이아웃 조회
            if ($cachedId === 0) {
                // 이전에 해석 결과가 없었던 경우
                HookManager::doAction('core.layout_resolver.after_resolve', null, $layoutName, $templateId, true);

                return null;
            }

            $layout = $this->layoutRepository->findById($cachedId);

            // After 훅 - 캐시에서 해석 완료
            HookManager::doAction('core.layout_resolver.after_resolve', $layout, $layoutName, $templateId, true);

            return $layout;
        }

        // 캐시 미스 - 실제 해석 수행
        $this->cacheStats['misses']++;

        Log::debug('레이아웃 해석 캐시 미스', [
            'layout_name' => $layoutName,
            'template_id' => $templateId,
            'cache_key' => $cacheKey,
        ]);

        $layout = $this->resolveInternal($layoutName, $templateId);

        // 캐시에 결과 저장 (없는 경우 0으로 저장하여 null 구분)
        $cacheValue = $layout ? $layout->id : 0;
        $cacheTtl = $this->getCacheTtl();
        Cache::put($cacheKey, $cacheValue, $cacheTtl);

        Log::info('레이아웃 해석 완료 및 캐싱', [
            'layout_name' => $layoutName,
            'template_id' => $templateId,
            'resolved_id' => $layout?->id,
            'source_type' => $layout?->source_type?->value,
            'cache_key' => $cacheKey,
            'ttl' => $cacheTtl,
        ]);

        // After 훅 - DB에서 해석 완료
        HookManager::doAction('core.layout_resolver.after_resolve', $layout, $layoutName, $templateId, false);

        return $layout;
    }

    /**
     * 레이아웃 해석 내부 로직 (캐싱 없음)
     */
    private function resolveInternal(string $layoutName, int $templateId): ?TemplateLayout
    {
        // 1. 템플릿 오버라이드 확인 (최우선)
        $override = $this->layoutRepository->findTemplateOverride($templateId, $layoutName);

        if ($override) {
            // 버전 호환성 검사
            if ($this->checkOverrideVersionCompatibility($override)) {
                Log::debug('템플릿 오버라이드 발견', [
                    'layout_name' => $layoutName,
                    'template_id' => $templateId,
                    'override_id' => $override->id,
                ]);

                return $override;
            }

            Log::warning('템플릿 오버라이드 버전 비호환, 모듈 기본 레이아웃으로 폴백', [
                'layout_name' => $layoutName,
                'template_id' => $templateId,
                'override_id' => $override->id,
            ]);
        }

        // 2. 모듈 기본 레이아웃 반환 (폴백)
        $moduleLayout = $this->layoutRepository->findModuleLayout($templateId, $layoutName);

        if ($moduleLayout) {
            Log::debug('모듈 기본 레이아웃 사용', [
                'layout_name' => $layoutName,
                'template_id' => $templateId,
                'layout_id' => $moduleLayout->id,
                'source_identifier' => $moduleLayout->source_identifier,
            ]);
        }

        return $moduleLayout;
    }

    /**
     * 레이아웃 오버라이드의 버전 호환성을 검사합니다.
     *
     * @param  TemplateLayout  $override  오버라이드 레이아웃
     * @return bool 호환 여부
     */
    private function checkOverrideVersionCompatibility(TemplateLayout $override): bool
    {
        $content = is_string($override->content)
            ? json_decode($override->content, true)
            : $override->content;

        // version_constraint가 없으면 항상 호환
        if (! isset($content['version_constraint'])) {
            return true;
        }

        $constraint = $content['version_constraint'];
        $layoutName = $override->name;

        // 레이아웃 이름에서 모듈 식별자 추출 (DOT 앞 부분)
        // 예: sirsoft-ecommerce.admin_products_index → sirsoft-ecommerce
        $dotPos = strpos($layoutName, '.');
        if ($dotPos === false) {
            // DOT가 없으면 UNDERSCORE 포맷 시도
            // 예: sirsoft-ecommerce_admin_products_index → sirsoft-ecommerce
            if (preg_match('/^([a-z0-9]+-[a-z0-9]+)[_.]/', $layoutName, $matches)) {
                $moduleIdentifier = $matches[1];
            } else {
                return true;
            }
        } else {
            $moduleIdentifier = substr($layoutName, 0, $dotPos);
        }

        // 모듈 버전 조회
        $version = $this->moduleManager->getModuleVersion($moduleIdentifier);

        if (! $version) {
            Log::warning('오버라이드 버전 검사: 모듈 버전 정보 없음', [
                'override_id' => $override->id,
                'module' => $moduleIdentifier,
                'constraint' => $constraint,
            ]);

            // 버전 정보 없으면 호환으로 처리 (하위 호환성)
            return true;
        }

        // Composer Semver로 검증
        try {
            $compatible = Semver::satisfies($version, $constraint);
        } catch (\Exception $e) {
            Log::warning('오버라이드 버전 제약 조건 파싱 실패', [
                'override_id' => $override->id,
                'constraint' => $constraint,
                'error' => $e->getMessage(),
            ]);

            // 파싱 실패 시 호환으로 처리 (안전 폴백)
            return true;
        }

        if (! $compatible) {
            Log::warning('오버라이드 버전 비호환', [
                'override_id' => $override->id,
                'module' => $moduleIdentifier,
                'constraint' => $constraint,
                'current_version' => $version,
            ]);
        }

        return $compatible;
    }

    /**
     * 레이아웃 해석 캐시 키 생성
     *
     * 소스 해시를 포함하여 오버라이드 정보를 캐시 키에 반영합니다.
     */
    private function getResolutionCacheKey(string $layoutName, int $templateId): string
    {
        return "layout_resolver.{$templateId}.{$layoutName}";
    }

    /**
     * 특정 레이아웃의 해석 캐시를 무효화합니다.
     */
    public function clearResolutionCache(string $layoutName, int $templateId): void
    {
        // Before 훅 - 캐시 무효화 전
        HookManager::doAction('core.layout_resolver.before_cache_clear', $layoutName, $templateId);

        $cacheKey = $this->getResolutionCacheKey($layoutName, $templateId);
        Cache::forget($cacheKey);

        Log::info('레이아웃 해석 캐시 무효화', [
            'layout_name' => $layoutName,
            'template_id' => $templateId,
            'cache_key' => $cacheKey,
        ]);

        // After 훅 - 캐시 무효화 후
        HookManager::doAction('core.layout_resolver.after_cache_clear', $layoutName, $templateId, $cacheKey);
    }

    /**
     * 특정 템플릿의 모든 레이아웃 해석 캐시를 무효화합니다.
     */
    public function clearAllResolutionCacheByTemplate(int $templateId): void
    {
        // Before 훅 - 전체 캐시 무효화 전
        HookManager::doAction('core.layout_resolver.before_all_cache_clear', $templateId);

        // 해당 템플릿의 모든 레이아웃 조회
        $layouts = $this->layoutRepository->getLayoutNamesByTemplateId($templateId);

        foreach ($layouts as $layoutName) {
            $cacheKey = $this->getResolutionCacheKey($layoutName, $templateId);
            Cache::forget($cacheKey);
        }

        Log::info('템플릿의 모든 레이아웃 해석 캐시 무효화', [
            'template_id' => $templateId,
            'count' => $layouts->count(),
        ]);

        // After 훅 - 전체 캐시 무효화 후
        HookManager::doAction('core.layout_resolver.after_all_cache_clear', $templateId, $layouts->count());
    }

    /**
     * 특정 모듈의 모든 레이아웃 해석 캐시를 무효화합니다.
     */
    public function clearResolutionCacheByModule(string $moduleIdentifier): void
    {
        // Before 훅 - 모듈 캐시 무효화 전
        HookManager::doAction('core.layout_resolver.before_module_cache_clear', $moduleIdentifier);

        // 해당 모듈의 모든 레이아웃 조회
        $layouts = $this->layoutRepository->getLayoutsByModule($moduleIdentifier);

        foreach ($layouts as $layout) {
            $cacheKey = $this->getResolutionCacheKey($layout->name, $layout->template_id);
            Cache::forget($cacheKey);
        }

        Log::info('모듈의 모든 레이아웃 해석 캐시 무효화', [
            'module_identifier' => $moduleIdentifier,
            'count' => $layouts->count(),
        ]);

        // After 훅 - 모듈 캐시 무효화 후
        HookManager::doAction('core.layout_resolver.after_module_cache_clear', $moduleIdentifier, $layouts->count());
    }

    /**
     * 캐시 히트율 통계 조회
     *
     * @return array{hits: int, misses: int, total: int, hit_rate: float}
     */
    public function getCacheStats(): array
    {
        $total = $this->cacheStats['hits'] + $this->cacheStats['misses'];
        $hitRate = $total > 0 ? round(($this->cacheStats['hits'] / $total) * 100, 2) : 0.0;

        return [
            'hits' => $this->cacheStats['hits'],
            'misses' => $this->cacheStats['misses'],
            'total' => $total,
            'hit_rate' => $hitRate,
        ];
    }

    /**
     * 캐시 통계 초기화
     */
    public function resetCacheStats(): void
    {
        $this->cacheStats = [
            'hits' => 0,
            'misses' => 0,
        ];
    }

    /**
     * 특정 레이아웃이 오버라이드되었는지 확인
     */
    public function isOverridden(string $layoutName, int $templateId): bool
    {
        return $this->layoutRepository->findTemplateOverride($templateId, $layoutName) !== null;
    }

    /**
     * 특정 템플릿에서 오버라이드된 모든 레이아웃 목록 조회
     */
    public function getOverriddenLayouts(int $templateId): Collection
    {
        return $this->layoutRepository->getOverriddenLayouts($templateId);
    }

    /**
     * 특정 모듈의 레이아웃 중 오버라이드된 것들 조회
     */
    public function getModuleLayoutOverrides(string $moduleIdentifier, int $templateId): Collection
    {
        return $this->layoutRepository->getModuleLayoutOverrides($moduleIdentifier, $templateId);
    }
}
