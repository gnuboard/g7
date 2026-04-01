<?php

namespace App\Seo;

use App\Seo\Contracts\SeoCacheManagerInterface;
use Illuminate\Support\Facades\Log;

/**
 * SEO 캐시 무효화 레지스트리
 *
 * 확장 모듈이 콘텐츠 변경 시 관련 SEO 캐시를 무효화하기 위한 코어 인프라.
 * 각 확장은 자체 리스너에서 이 레지스트리 또는 SeoCacheManagerInterface를 직접 호출.
 */
class SeoInvalidationRegistry
{
    /**
     * 등록된 무효화 규칙 목록
     *
     * @var array<string, array<int, array{layouts: array<string>, urlPattern: string|null}>>
     */
    private array $rules = [];

    /**
     * @param  SeoCacheManagerInterface  $cacheManager  SEO 캐시 매니저
     */
    public function __construct(
        private readonly SeoCacheManagerInterface $cacheManager,
    ) {}

    /**
     * 무효화 규칙을 등록합니다.
     *
     * 특정 훅 발생 시 무효화할 레이아웃 목록과 URL 패턴을 등록합니다.
     *
     * @param  string  $hookName  훅 이름 (예: 'sirsoft-ecommerce.product.after_create')
     * @param  array<string>  $layouts  무효화할 레이아웃 이름 목록
     * @param  string|null  $urlPattern  무효화할 URL 패턴 (와일드카드 지원, null이면 URL 무효화 생략)
     * @return self 메서드 체이닝을 위한 자기 자신
     */
    public function registerRule(string $hookName, array $layouts, ?string $urlPattern = null): self
    {
        $this->rules[$hookName][] = [
            'layouts' => $layouts,
            'urlPattern' => $urlPattern,
        ];

        return $this;
    }

    /**
     * 훅 발생 시 등록된 규칙에 따라 캐시를 무효화합니다.
     *
     * @param  string  $hookName  발생한 훅 이름
     * @param  mixed  ...$args  훅에서 전달된 인수들
     * @return int 무효화된 총 캐시 수
     */
    public function invalidate(string $hookName, mixed ...$args): int
    {
        $rules = $this->getRulesForHook($hookName);

        if (empty($rules)) {
            return 0;
        }

        $totalInvalidated = 0;

        foreach ($rules as $rule) {
            try {
                // URL 패턴 무효화
                if ($rule['urlPattern'] !== null) {
                    $totalInvalidated += $this->cacheManager->invalidateByUrl($rule['urlPattern']);
                }

                // 레이아웃 무효화
                foreach ($rule['layouts'] as $layout) {
                    $totalInvalidated += $this->cacheManager->invalidateByLayout($layout);
                }
            } catch (\Throwable $e) {
                Log::warning('[SEO] Registry invalidation failed', [
                    'hook' => $hookName,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $totalInvalidated;
    }

    /**
     * 등록된 규칙 목록을 반환합니다.
     *
     * @return array<string, array<int, array{layouts: array<string>, urlPattern: string|null}>>
     */
    public function getRules(): array
    {
        return $this->rules;
    }

    /**
     * 특정 훅의 규칙을 반환합니다.
     *
     * @param  string  $hookName  훅 이름
     * @return array<int, array{layouts: array<string>, urlPattern: string|null}>
     */
    public function getRulesForHook(string $hookName): array
    {
        return $this->rules[$hookName] ?? [];
    }
}
