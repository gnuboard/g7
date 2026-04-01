<?php

namespace App\Services\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * 메일 템플릿 서비스 공통 Trait.
 *
 * 코어/모듈 메일 템플릿 서비스에서 공통으로 사용하는 조회, 렌더링, 리셋, 캐시 로직을 제공합니다.
 * 사용하는 서비스는 $cachePrefix와 $modelClass 속성을 정의해야 합니다.
 */
trait HandlesMailTemplates
{
    /**
     * 활성 템플릿을 캐시 포함으로 조회합니다.
     *
     * @param string $type 템플릿 유형
     * @return Model|null 활성 템플릿 또는 null
     */
    public function resolveTemplate(string $type): ?Model
    {
        $cacheKey = $this->getCacheKey($type);

        return Cache::remember($cacheKey, 3600, function () use ($type) {
            return $this->modelClass::query()->active()->byType($type)->first();
        });
    }

    /**
     * 템플릿의 변수를 치환하여 제목과 본문을 렌더링합니다.
     *
     * @param Model $template 메일 템플릿 모델
     * @param array $variables key => value 변수 맵
     * @param string|null $locale 로케일
     * @return array{subject: string, body: string} 치환된 결과
     */
    public function renderTemplate(Model $template, array $variables, ?string $locale = null): array
    {
        return $template->replaceVariables($variables, $locale);
    }

    /**
     * 템플릿을 시더 기본값으로 복원합니다.
     *
     * @param Model $template 복원 대상 템플릿
     * @param array $defaultData 기본 데이터 (subject, body, variables)
     * @return Model 복원된 템플릿
     */
    public function resetToDefault(Model $template, array $defaultData): Model
    {
        $template->update([
            'subject' => $defaultData['subject'],
            'body' => $defaultData['body'],
            'variables' => $defaultData['variables'] ?? $template->variables,
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->invalidateCache($template->type);

        return $template->fresh();
    }

    /**
     * 특정 유형의 캐시를 무효화합니다.
     *
     * @param string $type 템플릿 유형
     * @return void
     */
    public function invalidateCache(string $type): void
    {
        Cache::forget($this->getCacheKey($type));
    }

    /**
     * 캐시 키를 생성합니다.
     *
     * @param string $type 템플릿 유형
     * @return string 캐시 키
     */
    private function getCacheKey(string $type): string
    {
        return $this->cachePrefix . $type;
    }
}
