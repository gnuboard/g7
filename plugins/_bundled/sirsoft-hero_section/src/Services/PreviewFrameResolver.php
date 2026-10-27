<?php

namespace Plugins\Sirsoft\HeroSection\Services;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Repositories\LayoutRepositoryInterface;
use App\Exceptions\TemplateNotFoundException;
use App\Extension\Traits\ClearsTemplateCaches;
use App\Services\LayoutService;
use App\Services\TemplateService;
use App\Support\AssetUrl;
use Illuminate\Support\Facades\Log;
use Plugins\Sirsoft\HeroSection\Enums\HeroPosition;
use Throwable;

/**
 * 관리자 미리보기의 구좌 틀 — 활성 사용자 템플릿에서 히어로 확장점을 감싼 상자 사슬과 그 템플릿 CSS 주소를 꺼낸다.
 *
 * 실제 사이트에서 배너는 기기 화면 전체가 아니라 템플릿 본문 상자(여백·최대 폭) 안에 그려진다. 미리보기가 그 차이를
 * 재현하려면 폭을 하드코딩하지 않고 템플릿이 실제로 쓰는 레이아웃을 읽어야 한다 — 확장점을 가진 레이아웃을 찾아 병합본에서
 * 조상 노드의 정적 클래스와 반응형 덮어쓰기(className 만)를 위에서부터 순서대로 낸다. 화면은 이 사슬을 같은 템플릿 CSS 로
 * 기기 viewport 안에 그려 안쪽 구좌를 얻는다. 템플릿·확장점이 없으면 그 위치는 null 이고 미리보기는 기기 폭 전체를 쓴다.
 */
class PreviewFrameResolver
{
    use ClearsTemplateCaches;

    /**
     * 결과 캐시 시간(초) — 레이아웃이 바뀌면 확장 캐시 버전이 올라 키가 바뀐다.
     */
    private const TTL = 3600;

    /**
     * @param  TemplateService  $templates  템플릿 서비스
     * @param  LayoutRepositoryInterface  $layouts  레이아웃 저장소
     * @param  LayoutService  $layoutService  레이아웃 병합 서비스
     * @param  CacheInterface  $cache  플러그인 캐시
     */
    public function __construct(
        private TemplateService $templates,
        private LayoutRepositoryInterface $layouts,
        private LayoutService $layoutService,
        private CacheInterface $cache,
    ) {}

    /**
     * 활성 사용자 템플릿 기준 위치별 구좌 사슬과 템플릿 CSS 를 반환합니다.
     *
     * @return array{template: string|null, stylesheets: array<int, string>, positions: array<string, array{layout: string, chain: array<int, array{className: string, responsive: array<string, string>}>}|null>} 구좌 틀
     */
    public function resolve(): array
    {
        try {
            $identifier = $this->templates->getActiveTemplateIdentifier('user');
        } catch (TemplateNotFoundException) {
            return ['template' => null, 'stylesheets' => [], 'positions' => array_fill_keys(HeroPosition::values(), null)];
        }

        $version = self::getExtensionCacheVersion();

        return $this->cache->remember("preview_frame.{$identifier}.v{$version}", function () use ($identifier, $version) {
            $template = $this->templates->findByIdentifier($identifier);
            $positions = [];
            foreach (HeroPosition::cases() as $position) {
                $positions[$position->value] = $template ? $this->frameFor((int) $template->id, $identifier, $position->value) : null;
            }

            return [
                'template' => $identifier,
                'stylesheets' => [AssetUrl::templateAsset($identifier, 'css/components.css', $version)],
                'positions' => $positions,
            ];
        }, self::TTL);
    }

    /**
     * 확장점을 가진 첫 레이아웃의 조상 사슬을 찾습니다.
     *
     * @param  int  $templateId  템플릿 ID
     * @param  string  $identifier  템플릿 식별자
     * @param  string  $point  확장점 이름
     * @return array{layout: string, chain: array<int, array{className: string, responsive: array<string, string>}>}|null 레이아웃과 사슬 (없으면 null)
     */
    private function frameFor(int $templateId, string $identifier, string $point): ?array
    {
        foreach ($this->layouts->findLayoutNamesWithExtensionPoint($templateId, $point) as $name) {
            try {
                $merged = $this->layoutService->getLayout($identifier, $name);
            } catch (Throwable $e) {
                // 깨진 레이아웃 하나 때문에 미리보기를 막지 않는다 — 다음 레이아웃을 보되, 틀이 기기 폭으로 떨어진 원인은 남긴다.
                // 출하 기본 로그 수준(error)에서도 남도록 error 로 쓴다. 틀 결과는 캐시되므로 캐시 주기마다 한 번뿐이다
                Log::error('히어로섹션 미리보기 구좌 틀 레이아웃 읽기 실패 — 건너뜀', [
                    'template' => $identifier,
                    'layout' => $name,
                    'extension_point' => $point,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            $chain = $this->ancestors($merged['components'] ?? [], $point, []);
            if ($chain !== null) {
                return ['layout' => $name, 'chain' => $chain];
            }
        }

        return null;
    }

    /**
     * 확장점까지 내려가며 지나온 노드의 클래스를 모읍니다.
     *
     * @param  mixed  $nodes  자식 노드 목록
     * @param  string  $point  확장점 이름
     * @param  array<int, array{className: string, responsive: array<string, string>}>  $path  지나온 조상
     * @return array<int, array{className: string, responsive: array<string, string>}>|null 확장점까지의 조상 (없으면 null)
     */
    private function ancestors(mixed $nodes, string $point, array $path): ?array
    {
        if (! is_array($nodes)) {
            return null;
        }

        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            if (($node['type'] ?? null) === 'extension_point' && ($node['name'] ?? null) === $point) {
                return $path;
            }

            $responsive = [];
            foreach ((array) ($node['responsive'] ?? []) as $key => $override) {
                $class = $override['props']['className'] ?? null;
                if (is_string($class)) {
                    $responsive[(string) $key] = $this->staticClasses($class);
                }
            }

            $found = $this->ancestors($node['children'] ?? null, $point, [...$path, [
                'className' => $this->staticClasses((string) ($node['props']['className'] ?? '')),
                'responsive' => $responsive,
            ]]);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * 바인딩 식(`{{…}}`)을 지운 정적 클래스만 남깁니다 — 서버는 상태 값을 모르므로 풀지 않는다.
     *
     * @param  string  $className  클래스 문자열
     * @return string 정적 클래스
     */
    private function staticClasses(string $className): string
    {
        return trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/\{\{.*?\}\}/s', ' ', $className)));
    }
}
