<?php

namespace App\Rules;

use App\Contracts\Extension\CacheInterface;
use App\Extension\Cache\CoreCacheDriver;
use App\Models\Template;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Log;

/**
 * 컴포넌트 존재 여부 검증 Rule
 *
 * 레이아웃 JSON에서 참조하는 컴포넌트가 템플릿의 components.json 매니페스트에
 * 실제로 존재하는지 검증합니다. 존재하지 않는 컴포넌트 참조를 차단하여
 * 런타임 에러를 방지합니다.
 */
class ComponentExists implements DataAwareRule, ValidationRule
{
    /**
     * 검증 데이터 전체
     */
    protected array $data = [];

    /**
     * 컴포넌트 매니페스트 캐시 TTL (초)
     */
    private const CACHE_TTL = 3600; // 1시간

    /**
     * 검증 수행
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            return;
        }

        // template_id가 없으면 검증 불가
        if (! isset($this->data['template_id'])) {
            $fail(__('validation.component.template_id_required'));

            return;
        }

        $templateId = $this->data['template_id'];

        // 컴포넌트 매니페스트 로드
        $manifest = $this->loadComponentManifest($templateId);

        if ($manifest === null) {
            $fail(__('validation.component.manifest_not_found', ['templateId' => $templateId]));

            return;
        }

        // components 배열을 재귀적으로 검증
        if (isset($value['components']) && is_array($value['components'])) {
            $this->validateComponents($value['components'], $manifest, $fail);
        }
    }

    /**
     * 검증 데이터 설정
     */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    /**
     * 컴포넌트 매니페스트 로드 (캐싱 적용)
     */
    private function loadComponentManifest(int|string $templateId): ?array
    {
        $cacheKey = "template.{$templateId}.components_manifest";

        $cache = $this->resolveCache();

        return $cache->remember($cacheKey, function () use ($templateId) {
            // 템플릿 identifier 조회 (numeric ID인 경우)
            $templateIdentifier = $templateId;
            if (is_numeric($templateId)) {
                $template = Template::find($templateId);
                if (! $template) {
                    Log::warning("Template not found: {$templateId}");

                    return null;
                }
                $templateIdentifier = $template->identifier;
            }

            // 템플릿 디렉토리에서 components.json 로드
            $manifestPath = base_path("templates/{$templateIdentifier}/components.json");

            if (! file_exists($manifestPath)) {
                Log::warning("Component manifest not found for template: {$templateIdentifier}", [
                    'path' => $manifestPath,
                    'template_id' => $templateId,
                ]);

                return null;
            }

            $content = file_get_contents($manifestPath);
            $manifest = json_decode($content, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error("Invalid JSON in component manifest: {$templateIdentifier}", [
                    'path' => $manifestPath,
                    'error' => json_last_error_msg(),
                ]);

                return null;
            }

            // 컴포넌트 목록을 빠른 조회를 위해 Set으로 변환
            return $this->buildComponentSet($manifest);
        }, self::CACHE_TTL);
    }

    /**
     * CacheInterface 인스턴스를 lazy 조회합니다.
     */
    private function resolveCache(): CacheInterface
    {
        try {
            return app(CacheInterface::class);
        } catch (\Throwable $e) {
            return new CoreCacheDriver(config('cache.default', 'array'));
        }
    }

    /**
     * 매니페스트에서 컴포넌트 Set 생성
     */
    private function buildComponentSet(array $manifest): array
    {
        $components = [];

        // basic 컴포넌트
        if (isset($manifest['basic']) && is_array($manifest['basic'])) {
            foreach ($manifest['basic'] as $component) {
                if (is_string($component)) {
                    $components[$component] = true;
                }
            }
        }

        // composite 컴포넌트
        if (isset($manifest['composite']) && is_array($manifest['composite'])) {
            foreach ($manifest['composite'] as $component) {
                if (is_string($component)) {
                    $components[$component] = true;
                }
            }
        }

        // layout 컴포넌트
        if (isset($manifest['layout']) && is_array($manifest['layout'])) {
            foreach ($manifest['layout'] as $component) {
                if (is_string($component)) {
                    $components[$component] = true;
                }
            }
        }

        return $components;
    }

    /**
     * components 배열을 재귀적으로 검증
     */
    private function validateComponents(array $components, array $manifest, Closure $fail): void
    {
        foreach ($components as $index => $component) {
            if (! is_array($component)) {
                continue;
            }

            // name 필드 검증 (컴포넌트 식별자)
            if (isset($component['name'])) {
                $componentName = $component['name'];

                if (! is_string($componentName)) {
                    continue;
                }

                // 빈 문자열 체크
                if (empty(trim($componentName))) {
                    $fail(__('validation.component.name_empty', ['index' => $index]));

                    return;
                }

                // 컴포넌트 존재 여부 확인
                if (! isset($manifest[$componentName])) {
                    $fail(__('validation.component.not_found', [
                        'component' => $componentName,
                        'index' => $index,
                    ]));

                    return;
                }
            }

            // children 재귀 검증
            if (isset($component['children']) && is_array($component['children'])) {
                $this->validateComponents($component['children'], $manifest, $fail);
            }
        }
    }
}
