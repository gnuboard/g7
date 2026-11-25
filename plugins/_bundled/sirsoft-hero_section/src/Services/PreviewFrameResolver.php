<?php

namespace Plugins\Sirsoft\HeroSection\Services;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Repositories\LayoutRepositoryInterface;
use App\Exceptions\TemplateNotFoundException;
use App\Extension\Traits\ClearsTemplateCaches;
use App\Services\LayoutService;
use App\Services\TemplateService;
use App\Support\AssetUrl;
use App\Support\Style\ClassMerger;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 관리자 미리보기의 구좌 틀 — 활성 사용자 템플릿에서 히어로 확장점을 감싼 상자 사슬과 그 템플릿 CSS 주소를 꺼낸다.
 *
 * 실제 사이트에서 배너는 기기 화면 전체가 아니라 템플릿 본문 상자(여백·최대 폭) 안에 그려진다. 미리보기가 그 차이를
 * 재현하려면 폭을 하드코딩하지 않고 템플릿이 실제로 쓰는 레이아웃을 읽어야 한다 — 확장점을 가진 레이아웃을 찾아 병합본에서
 * 조상 노드의 정적 클래스와 반응형 덮어쓰기(구간 className 과 구간 classMerge 를 엔진 규칙으로 합친 실효 클래스)를
 * 위에서부터 순서대로 낸다. 화면은 이 사슬을 같은 템플릿 CSS 로
 * 기기 viewport 안에 그려 안쪽 구좌를 얻는다. 템플릿·확장점이 없으면 그 위치는 null 이고 미리보기는 기기 폭 전체를 쓴다.
 * 확장점이 넘기는 본문 상자 클래스(`contentClass`·`contentClassDesktop`)도 `content` 로 함께 낸다 — 표시 폭이 본문 폭이면
 * 슬라이더 바깥, 화면 전체면 문구 층에 같은 상자를 써서 실제 사이트와 같은 폭·정렬을 미리 본다.
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
     * @return array{template: string|null, stylesheets: array<int, string>, positions: array<string, array{layout: string, path: string|null, chain: array<int, array{className: string, responsive: array<string, string>}>, content: array{className: string, responsive: array<string, string>}|null}|null>} 구좌 틀 (`path` 는 그 자리가 있는 사이트 주소, `content` 는 확장점이 넘긴 본문 상자)
     */
    public function resolve(): array
    {
        try {
            $identifier = $this->templates->getActiveTemplateIdentifier('user');
        } catch (TemplateNotFoundException) {
            return ['template' => null, 'stylesheets' => [], 'positions' => array_fill_keys($this->registry()->keys(), null)];
        }

        $version = self::getExtensionCacheVersion();
        $keys = $this->registry()->keys();
        // 위치 목록이 바뀌면(모듈·확장을 켜고 끔) 키가 바뀐다 — 새 위치의 틀을 캐시 주기까지 기다리지 않게
        $positionsHash = substr(md5(implode(',', $keys)), 0, 12);

        $frame = $this->cache->remember("preview_frame.v4.{$identifier}.v{$version}.{$positionsHash}", function () use ($identifier, $version, $keys) {
            $template = $this->templates->findByIdentifier($identifier);
            $positions = [];
            foreach ($keys as $key) {
                $positions[$key] = $template ? $this->frameFor((int) $template->id, $identifier, $key) : null;
            }

            return [
                'template' => $identifier,
                'stylesheets' => [AssetUrl::templateAsset($identifier, 'css/components.css', $version)],
                'positions' => $positions,
            ];
        }, self::TTL);

        // 사이트 주소는 캐시 밖에서 붙인다 — 이커머스 주소 접두어 같은 모듈 설정이 바뀌어도 레이아웃 캐시 주기를 기다리지 않게
        $paths = $this->routePaths($identifier);
        foreach ($frame['positions'] as $point => $entry) {
            if (is_array($entry)) {
                $frame['positions'][$point]['path'] = $paths[$entry['layout']] ?? null;
            }
        }

        return $frame;
    }

    /**
     * 레이아웃 이름 → 한 주소로 정해지는 사이트 경로를 반환합니다.
     *
     * 매개변수(`:slug`)·와일드카드가 든 주소는 어느 페이지인지 정할 수 없어 뺀다. 라우트 목록을 읽지 못하면 주소만 비우고 틀은
     * 그대로 낸다 — 운영자가 「사이트에서 보기」가 사라진 원인을 찾을 수 있게 오류 로그를 남긴다.
     *
     * @param  string  $identifier  템플릿 식별자
     * @return array<string, string> 레이아웃 이름 → 경로
     */
    private function routePaths(string $identifier): array
    {
        try {
            $map = $this->templates->getLayoutRoutePathMap($identifier);
        } catch (Throwable $e) {
            Log::error('히어로섹션 미리보기 사이트 주소 읽기 실패 — 주소 없이 냄', [
                'template' => $identifier,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        return array_filter($map, fn ($path) => is_string($path) && str_starts_with($path, '/') && ! preg_match('/[:*{]/', $path));
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

            $found = $this->ancestors($merged['components'] ?? [], $point, []);
            if ($found !== null) {
                return ['layout' => $name, 'chain' => $found['chain'], 'content' => $this->contentBox($found['props'])];
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
     * @return array{chain: array<int, array{className: string, responsive: array<string, string>}>, props: array<string, mixed>}|null 확장점까지의 조상과 확장점 속성 (없으면 null)
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
                return ['chain' => $path, 'props' => is_array($node['props'] ?? null) ? $node['props'] : []];
            }

            $baseClass = $this->staticClasses((string) ($node['props']['className'] ?? ''));
            $responsive = [];
            foreach ((array) ($node['responsive'] ?? []) as $key => $override) {
                if (! is_array($override)) {
                    continue;
                }
                $class = $override['props']['className'] ?? null;
                $merge = is_string($override['classMerge'] ?? null) ? $this->staticClasses($override['classMerge']) : '';
                if (! is_string($class) && $merge === '') {
                    continue;
                }
                // 구간 className(통째 교체) 위에 구간 classMerge(바뀐 클래스만 덧붙이기)를 엔진과 같은 규칙으로 합친
                // 실효 클래스를 보낸다 — 화면(editor.ts frameClass)은 구간 값을 그대로 고르므로 미리보기와 실화면이 같다.
                $responsive[(string) $key] = ClassMerger::applyResponsive(
                    is_string($class) ? $this->staticClasses($class) : $baseClass,
                    $merge,
                );
            }

            $found = $this->ancestors($node['children'] ?? null, $point, [...$path, [
                'className' => $baseClass,
                'responsive' => $responsive,
            ]]);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * 확장점이 넘긴 본문 상자 클래스를 구좌 사슬 상자와 같은 모양으로 바꿉니다 — 데스크톱 클래스는 `desktop` 반응형 덮어쓰기다.
     *
     * @param  array<string, mixed>  $props  확장점 속성
     * @return array{className: string, responsive: array<string, string>}|null 본문 상자 (템플릿이 넘기지 않았으면 null)
     */
    private function contentBox(array $props): ?array
    {
        $base = is_string($props['contentClass'] ?? null) ? $this->staticClasses($props['contentClass']) : '';
        $desktop = is_string($props['contentClassDesktop'] ?? null) ? $this->staticClasses($props['contentClassDesktop']) : '';
        if ($base === '' && $desktop === '') {
            return null;
        }

        return ['className' => $base, 'responsive' => $desktop === '' ? [] : ['desktop' => $desktop]];
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

    /**
     * 위치 레지스트리를 꺼냅니다 — 생성자에서 쥐지 않는다. 레지스트리는 요청 단위로 공유되므로, 오래 사는 객체(라우트가 기억한 컨트롤러·
     * 작업자)가 옛 위치 목록을 붙들지 않게 쓸 때마다 컨테이너에서 받는다.
     *
     * @return PositionRegistry 위치 레지스트리
     */
    private function registry(): PositionRegistry
    {
        return app(PositionRegistry::class);
    }
}
