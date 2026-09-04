<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Route;

/**
 * API 엔드포인트 화이트리스트 검증 Rule
 *
 * 코어가 **실제로 등록한** API 라우트 네임스페이스만 허용하고 다른 모든 엔드포인트를 차단합니다.
 * 외부 URL, 내부 API, 직접 경로 접근 등을 방지하여 보안을 강화합니다.
 *
 * 판정 근거는 라우트 표다 — 종전에는 `(admin|auth|public)` 을 하드코딩해 라우트 표의 14개
 * 네임스페이스 중 5개만 알고 있었고(`public` 은 등록 라우트가 0건인 유령 항목이었다), 그래서
 * 정상 출하 중인 코어 엔드포인트를 쓰는 레이아웃을 **편집기 저장 API 가 거부**했다. 화이트리스트를
 * 손으로 관리하는 한 라우트가 늘 때마다 같은 갭이 재발하므로 목록을 두지 않는다.
 *
 * 허용 패턴:
 * - /api/{ns}/... — `{ns}` 가 라우트 표에 등록된 코어 API 네임스페이스인 경우 (admin·auth·identity·
 *   me·user·users·search·templates·locales·layouts·attachment·broadcasting 등, 등록분 전량)
 * - /api/modules/{vendor-module}/* (ModuleRouteServiceProvider 가 등록하는 모듈 API 프리픽스)
 * - /api/plugins/{vendor-plugin}/* (PluginRouteServiceProvider 가 등록하는 플러그인 API 프리픽스)
 *
 * 차단 패턴:
 * - /api/internal/* · /api/public/* 등 미등록 네임스페이스
 * - /api/modules/* (확장 식별자 vendor-name 형식이 아닌 세그먼트)
 * - /admin/* (직접 경로)
 * - http://* (외부 URL)
 * - https://* (외부 URL)
 */
class WhitelistedEndpoint implements ValidationRule
{
    /**
     * 확장 API 프리픽스 — 확장 식별자(vendor-name — 하이픈 포함 필수) 세그먼트가 뒤따를 때만 허용한다.
     *
     * 이 둘은 라우트 표로 판정하지 않는다. 비활성 확장의 라우트는 표에 없지만 그 확장을 대상으로 한
     * 레이아웃은 정상 마크업이고, 반대로 `modules` 가 등록돼 있다는 이유로 `/api/modules/assets/`
     * (에셋 서빙 경로) 까지 열리면 안 되기 때문이다 — 식별자 **형태**가 정확한 게이트다.
     */
    private const EXTENSION_PREFIX_PATTERN = '/^\/api\/(modules|plugins)\/[a-z0-9][a-z0-9_]*(-[a-z0-9_]+)+(\/|$)/';

    /** 확장 프리픽스 — 라우트 표에서 코어 네임스페이스를 추릴 때 제외한다 (위 형태 게이트가 담당) */
    private const EXTENSION_NAMESPACES = ['modules', 'plugins'];

    /**
     * 라우트 표에서 추린 코어 API 네임스페이스 캐시 (요청 수명 내 1회 산출).
     *
     * @var array<string, true>|null
     */
    private static ?array $coreNamespaces = null;

    /**
     * 검증 규칙 실행
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // 레이아웃 JSON 구조인 경우 재귀적으로 검사
        if (is_array($value)) {
            // data_sources나 components가 있는 레이아웃 JSON 구조
            $hasLayoutStructure = isset($value['data_sources']) || isset($value['components']);

            if ($hasLayoutStructure) {
                // data_sources 배열 검증
                if (isset($value['data_sources']) && is_array($value['data_sources'])) {
                    $this->validateDataSources($value['data_sources'], $fail);
                }

                // components 배열 검증
                if (isset($value['components']) && is_array($value['components'])) {
                    $this->validateComponents($value['components'], $fail);
                }

                return;
            }

            // 레이아웃 JSON이 아닌 일반 배열은 타입 오류로 차단
            $fail(__('validation.endpoint.must_be_string'));

            return;
        }

        // 개별 endpoint 문자열 검증
        $this->validateEndpointString($value, $fail);
    }

    /**
     * data_sources 배열 검증
     */
    private function validateDataSources(array $dataSources, Closure $fail): void
    {
        foreach ($dataSources as $dataSource) {
            if (! is_array($dataSource)) {
                continue;
            }

            // endpoint 필드 검증
            if (isset($dataSource['endpoint'])) {
                $this->validateEndpointString($dataSource['endpoint'], $fail);
            }
        }
    }

    /**
     * components 배열을 재귀적으로 검증
     */
    private function validateComponents(array $components, Closure $fail): void
    {
        foreach ($components as $component) {
            if (! is_array($component)) {
                continue;
            }

            // actions 내 endpoint 검사
            if (isset($component['actions']) && is_array($component['actions'])) {
                foreach ($component['actions'] as $action) {
                    if (is_array($action) && isset($action['endpoint'])) {
                        $this->validateEndpointString($action['endpoint'], $fail);
                    }
                }
            }

            // children 재귀 검사
            if (isset($component['children']) && is_array($component['children'])) {
                $this->validateComponents($component['children'], $fail);
            }
        }
    }

    /**
     * endpoint 문자열 검증
     */
    private function validateEndpointString(mixed $value, Closure $fail): void
    {
        // 빈 값이나 null은 다른 검증 규칙에서 처리
        if (empty($value) && $value !== '0') {
            return;
        }

        // 문자열이 아닌 경우
        if (! is_string($value)) {
            $fail(__('validation.endpoint.must_be_string'));

            return;
        }

        // 외부 URL 차단 (http://, https://)
        if (preg_match('/^https?:\/\//i', $value)) {
            $fail(__('validation.endpoint.external_url_not_allowed'));

            return;
        }

        // 화이트리스트 검증 — 등록 라우트 네임스페이스 또는 확장 프리픽스
        if (! $this->isAllowedEndpoint($value)) {
            $fail(__('validation.endpoint.not_whitelisted', [
                'pattern' => '/api/{등록 네임스페이스}/, /api/(modules|plugins)/{vendor-id}/',
            ]));

            return;
        }

        // 경로 트래버설 공격 방지 (../)
        if (str_contains($value, '../') || str_contains($value, '..\\')) {
            $fail(__('validation.endpoint.path_traversal_detected'));

            return;
        }
    }

    /**
     * 엔드포인트가 허용 네임스페이스에 속하는지 판정합니다.
     *
     * @param  string  $endpoint  엔드포인트 경로 (쿼리 스트링 포함 가능)
     * @return bool 허용되면 true
     */
    private function isAllowedEndpoint(string $endpoint): bool
    {
        if (preg_match(self::EXTENSION_PREFIX_PATTERN, $endpoint) === 1) {
            return true;
        }

        // 쿼리 스트링·프래그먼트를 떼고 두 번째 세그먼트(네임스페이스)만 본다.
        $path = strtok($endpoint, '?#');

        if (! is_string($path) || preg_match('/^\/api\/([a-z0-9_-]+)(\/|$)/', $path, $matches) !== 1) {
            return false;
        }

        return isset($this->coreApiNamespaces()[$matches[1]]);
    }

    /**
     * 라우트 표에 등록된 코어 API 네임스페이스 집합을 만듭니다.
     *
     * 확장 프리픽스(modules·plugins)는 제외한다 — 그쪽은 식별자 형태로 게이트한다.
     *
     * @return array<string, true> 네임스페이스 → true
     */
    private function coreApiNamespaces(): array
    {
        if (self::$coreNamespaces !== null) {
            return self::$coreNamespaces;
        }

        $namespaces = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $uri = $route->uri();

            if (! str_starts_with($uri, 'api/')) {
                continue;
            }

            $segment = explode('/', $uri)[1] ?? '';

            if ($segment === '' || str_contains($segment, '{') || in_array($segment, self::EXTENSION_NAMESPACES, true)) {
                continue;
            }

            $namespaces[$segment] = true;
        }

        return self::$coreNamespaces = $namespaces;
    }

    /**
     * 네임스페이스 캐시를 비웁니다 (라우트를 동적으로 등록하는 테스트용).
     */
    public static function flushNamespaceCache(): void
    {
        self::$coreNamespaces = null;
    }
}
