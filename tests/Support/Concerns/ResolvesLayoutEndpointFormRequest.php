<?php

namespace Tests\Support\Concerns;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Route;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * 레이아웃 → 엔드포인트 → 라우트 → FormRequest 역추적 공용 도구.
 *
 * 레이아웃 JSON 이 선언한 데이터소스 엔드포인트를 라우트 목록에서 찾아, 그 컨트롤러 액션이
 * 받는 FormRequest 를 돌려준다. 화면과 서버 게이트의 정합을 보는 회귀 가드들이 함께 쓴다
 * (`AssertsLayoutSortOptionGateParity` — 정렬 옵션, `AssertsLayoutQueryParamGateParity` — 쿼리 키).
 *
 * 대상 쌍을 손으로 나열하지 않는다. 새 목록 화면이 추가돼도 자동으로 검사된다.
 *
 * 검사는 산출 JSON 을 읽는다. 이 `.json` 은 소스 SSoT `.g7.html` 에서 구워진 런타임 계약이다 —
 * 화면을 고칠 때는 짝 `.g7.html` 소스를 수정하고 다시 굽는다.
 */
trait ResolvesLayoutEndpointFormRequest
{
    /**
     * 세그먼트 수 → GET 라우트 목록 인덱스. 엔드포인트마다 전체 라우트를 재순회하지 않기 위한 것.
     *
     * @var array<int, array<int, array{parts: array<int, string>, action: string}>>|null
     */
    private ?array $routeIndex = null;

    /**
     * 레이아웃 JSON 파일 목록을 반환합니다.
     *
     * @param  array<int, string>  $roots  탐색 루트 디렉터리
     * @return array<int, string> 절대 경로 목록
     */
    private function layoutFiles(array $roots): array
    {
        $files = [];

        foreach ($roots as $root) {
            if (! is_dir($root)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveCallbackFilterIterator(
                    new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                    fn ($current) => ! in_array($current->getFilename(), ['node_modules', 'dist', 'vendor', '.git'], true)
                )
            );

            foreach ($iterator as $entry) {
                $path = str_replace('\\', '/', $entry->getPathname());

                if ($entry->isFile() && str_ends_with($path, '.json') && str_contains($path, '/layouts/')) {
                    $files[] = $entry->getPathname();
                }
            }
        }

        return $files;
    }

    /**
     * 레이아웃의 데이터소스 엔드포인트를 재귀적으로 수집합니다.
     *
     * @param  mixed  $node  탐색 노드
     * @param  array<int, string>  $endpoints  수집 결과 (참조)
     * @return void 반환값 없음
     */
    private function walkForEndpoints(mixed $node, array &$endpoints): void
    {
        if (! is_array($node)) {
            return;
        }

        if (isset($node['endpoint']) && is_string($node['endpoint'])) {
            $endpoints[] = $node['endpoint'];
        }

        foreach ($node as $child) {
            $this->walkForEndpoints($child, $endpoints);
        }
    }

    /**
     * Partial 레이아웃의 부모 레이아웃 파일 경로를 반환합니다.
     *
     * 관례상 partial 은 `layouts/**\/partials/{부모레이아웃명}/_*.json` 에 놓인다.
     *
     * @param  string  $partialFile  partial 절대 경로
     * @return string|null 부모 레이아웃 경로 (partial 이 아니거나 부모가 없으면 null)
     */
    private function parentLayoutFile(string $partialFile): ?string
    {
        $path = str_replace('\\', '/', $partialFile);

        if (! preg_match('#^(.*)/partials/([^/]+)/[^/]+\.json$#', $path, $m)) {
            return null;
        }

        $parent = $m[1].'/'.$m[2].'.json';

        return is_file($parent) ? $parent : null;
    }

    /**
     * Partial 레이아웃의 부모 레이아웃에서 엔드포인트를 가져옵니다.
     *
     * Partial 은 규정상 data_sources 를 갖지 않는다 — 부모 레이아웃의 엔드포인트를 쓴다.
     *
     * @param  string  $partialFile  partial 절대 경로
     * @return array<int, string> 부모 레이아웃의 엔드포인트 (부모를 찾지 못하면 빈 배열)
     */
    private function parentLayoutEndpoints(string $partialFile): array
    {
        $parent = $this->parentLayoutFile($partialFile);

        if ($parent === null) {
            return [];
        }

        $json = json_decode(file_get_contents($parent), true);

        if (! is_array($json)) {
            return [];
        }

        $endpoints = [];
        $this->walkForEndpoints($json, $endpoints);

        return array_values(array_unique($endpoints));
    }

    /**
     * 저장소 루트 기준 상대 경로를 반환합니다 (실패 메시지용).
     *
     * @param  string  $file  절대 경로
     * @return string 상대 경로
     */
    private function relativeLayoutPath(string $file): string
    {
        return str_replace(
            str_replace('\\', '/', base_path()).'/',
            '',
            str_replace('\\', '/', $file)
        );
    }

    /**
     * 엔드포인트를 라우트 비교용 세그먼트로 바꿉니다 (`{{...}}` 는 `{param}`, 쿼리 문자열 제거).
     *
     * @param  string  $endpoint  레이아웃에 선언된 엔드포인트
     * @return array<int, string> 세그먼트 목록
     */
    private function endpointParts(string $endpoint): array
    {
        $uri = trim(preg_replace('/\{\{.*?\}\}/', '{param}', parse_url($endpoint, PHP_URL_PATH) ?? ''), '/');

        return $uri === '' ? [] : explode('/', $uri);
    }

    /**
     * 엔드포인트에 일치하는 GET 라우트의 컨트롤러 액션 목록을 반환합니다.
     *
     * 고정 세그먼트가 많은(자리표시자가 적은) 라우트가 앞에 온다 — `/schedules/owners` 와
     * `/schedules/{schedule}` 가 함께 맞으면 앞의 것이 실제로 응답한다.
     *
     * @param  string  $endpoint  레이아웃에 선언된 엔드포인트
     * @return array<int, string> `Class@method` 목록 (일치 없음이면 빈 배열)
     */
    private function matchingRouteActions(string $endpoint): array
    {
        $endpointParts = $this->endpointParts($endpoint);

        // 세그먼트 수가 같은 라우트만 후보다 — 전체 라우트 재순회를 피한다
        $matched = [];

        foreach ($this->routeIndex()[count($endpointParts)] ?? [] as $candidate) {
            if ($this->partsMatch($candidate['parts'], $endpointParts)) {
                $placeholders = count(array_filter($candidate['parts'], fn ($p) => str_starts_with($p, '{')));
                $matched[] = ['placeholders' => $placeholders, 'action' => $candidate['action']];
            }
        }

        usort($matched, fn ($a, $b) => $a['placeholders'] <=> $b['placeholders']);

        return array_column($matched, 'action');
    }

    /**
     * GET 라우트를 세그먼트 수로 색인해 반환합니다 (최초 1회만 구성).
     *
     * @return array<int, array<int, array{parts: array<int, string>, action: string}>> 세그먼트 수별 라우트 목록
     */
    private function routeIndex(): array
    {
        if ($this->routeIndex !== null) {
            return $this->routeIndex;
        }

        $index = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }

            $action = $route->getActionName();

            if (! str_contains($action, '@')) {
                continue;
            }

            $uri = trim($route->uri(), '/');
            $parts = $uri === '' ? [] : explode('/', $uri);

            $index[count($parts)][] = ['parts' => $parts, 'action' => $action];
        }

        return $this->routeIndex = $index;
    }

    /**
     * 라우트 세그먼트와 엔드포인트 세그먼트가 같은 자리를 가리키는지 판정합니다.
     *
     * @param  array<int, string>  $routeParts  라우트 세그먼트 (`{user}` 는 임의 값과 일치)
     * @param  array<int, string>  $endpointParts  엔드포인트 세그먼트
     * @return bool 일치 여부
     */
    private function partsMatch(array $routeParts, array $endpointParts): bool
    {
        foreach ($routeParts as $index => $part) {
            if (str_starts_with($part, '{')) {
                continue;
            }

            if ($part !== ($endpointParts[$index] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 컨트롤러 액션이 주입받는 FormRequest 클래스 목록을 반환합니다.
     *
     * @param  string  $actionName  `App\Http\Controllers\Foo@index` 형태
     * @return array<int, class-string<FormRequest>> FormRequest 클래스 목록
     */
    private function formRequestClassesForAction(string $actionName): array
    {
        if (! str_contains($actionName, '@')) {
            return [];
        }

        [$class, $method] = explode('@', $actionName, 2);

        if (! class_exists($class) || ! method_exists($class, $method)) {
            return [];
        }

        $classes = [];

        foreach ((new ReflectionMethod($class, $method))->getParameters() as $parameter) {
            $type = $parameter->getType();

            if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            if (is_subclass_of($type->getName(), FormRequest::class)) {
                $classes[] = $type->getName();
            }
        }

        return $classes;
    }
}
