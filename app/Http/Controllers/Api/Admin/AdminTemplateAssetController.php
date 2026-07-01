<?php

namespace App\Http\Controllers\Api\Admin;

use App\Contracts\Extension\CacheInterface;
use App\Extension\Cache\CoreCacheDriver;
use App\Extension\Helpers\EditorSpecAssembler;
use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Services\PermissionService;
use App\Services\TemplateService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * 레이아웃 편집기용 어드민 템플릿 자산 서빙 컨트롤러
 *
 * 설치돼 있으나 비활성 상태인 템플릿도 편집할 수 있어야 한다.
 * `PublicTemplateController` 의 모든 서빙 메서드는 비활성 템플릿을 차단하므로,
 * 편집기 부팅용 admin 경로를 별도 제공한다.
 *
 * 5개 엔드포인트 — 모두 admin 권한 가드 (`core.templates.layouts.edit`):
 *  - getEditorAssets: 자산 매니페스트 (IIFE/CSS URL) — bootstrap.ts 가 조건부 로드
 *  - serveComponents: components.json (정상)
 *  - serveRoutes: routes.json (활성 상태 무관)
 *  - serveEditorSpec: editor-spec.json
 *  - serveLanguage: 다국어 데이터 (활성 상태 무관)
 *
 * 모두 활성/비활성 무관 200 응답. public 경로(`PublicTemplateController`) 는
 * 종전대로 활성만 허용 (일반 사이트 보안 유지).
 */
class AdminTemplateAssetController extends AdminBaseController
{
    public function __construct(
        private TemplateService $templateService,
        private PermissionService $permissionService,
    ) {
        parent::__construct();
    }

    /**
     * 편집 자산 매니페스트 — IIFE / CSS URL 목록 반환.
     *
     * bootstrap.ts 가 비활성 템플릿 부팅 시 본 응답의 js/css 를 동적으로 주입.
     * 활성 템플릿은 코어 일반 부팅이 이미 자산을 로드하므로 본 엔드포인트는
     * 비활성 템플릿 케이스에서 주로 사용된다.
     *
     * @param  string  $identifier  템플릿 식별자
     * @return JsonResponse 편집기 자산 매니페스트 응답
     */
    public function getEditorAssets(string $identifier): JsonResponse
    {
        // 빌드 결과물 위치 — 활성 디렉토리 우선, _bundled 폴백
        // 실제 IIFE/CSS 는 `php artisan template:build` 가 `dist/js/components.iife.js` /
        // `dist/css/components.css` 에 산출하며, `/api/templates/assets/{id}/...` 라우트가
        // 활성/_bundled 자동 폴백 + 권한 가드를 거쳐 서빙한다 (resources/views/admin.blade.php
        // 의 자산 URL 패턴과 동일).
        $iifeCandidates = [
            base_path("templates/{$identifier}/dist/js/components.iife.js"),
            base_path("templates/_bundled/{$identifier}/dist/js/components.iife.js"),
        ];
        $cssCandidates = [
            base_path("templates/{$identifier}/dist/css/components.css"),
            base_path("templates/_bundled/{$identifier}/dist/css/components.css"),
        ];

        $jsAvailable = false;
        $jsSource = null;
        foreach ($iifeCandidates as $candidate) {
            if (file_exists($candidate)) {
                $jsAvailable = true;
                $jsSource = str_contains($candidate, '/_bundled/') ? 'bundled' : 'active';
                break;
            }
        }
        $cssAvailable = false;
        foreach ($cssCandidates as $candidate) {
            if (file_exists($candidate)) {
                $cssAvailable = true;
                break;
            }
        }

        if (! $jsAvailable) {
            return $this->success(
                __('templates.messages.editor_assets_missing'),
                ['identifier' => $identifier, 'js' => [], 'css' => [], 'manifest_present' => false],
            );
        }

        $extensionCacheVersion = (int) app(CacheInterface::class)->get('ext.cache_version', 0);
        $version = $extensionCacheVersion > 0 ? "?v={$extensionCacheVersion}" : '';

        return $this->success(
            __('templates.messages.editor_assets_retrieved'),
            [
                'identifier' => $identifier,
                'js' => ["/api/templates/assets/{$identifier}/js/components.iife.js{$version}"],
                // CSS 는 편집기 전용 엔드포인트로 — 다크 셀렉터를 프리뷰 마커로 치환해 서빙
                // 일반 자산 서빙은 원본.
                'css' => $cssAvailable
                    ? ["/api/admin/templates/{$identifier}/editor/components.css{$version}"]
                    : [],
                'manifest_present' => true,
                'manifest_source' => $jsSource,
            ],
        );
    }

    /**
     * components.json 서빙 (활성/비활성 무관).
     *
     * @param  string  $identifier  템플릿 식별자
     * @return JsonResponse 컴포넌트 정의 응답
     */
    public function serveComponents(string $identifier): JsonResponse
    {
        $candidates = [
            base_path("templates/{$identifier}/components.json"),
            base_path("templates/_bundled/{$identifier}/components.json"),
        ];

        foreach ($candidates as $path) {
            if (file_exists($path)) {
                $data = json_decode((string) file_get_contents($path), true);
                if (is_array($data)) {
                    return $this->success(__('templates.messages.config_retrieved'), $data);
                }
            }
        }

        return $this->error(__('templates.layout_not_found'), 404);
    }

    /**
     * routes.json 서빙 (활성/비활성 무관) — 편집기 라우트 트리용.
     *
     * @param  string  $identifier  템플릿 식별자
     * @return JsonResponse 라우트 정의 응답
     */
    public function serveRoutes(string $identifier): JsonResponse
    {
        // 편집기 라우트 트리는 각 라우트의 `source`(`{kind, identifier}`) 태깅에 의존한다
        // (useRouteTree.buildRouteTree 가 `route.source.kind` 로 그룹핑). raw routes.json
        // 을 그대로 반환하면 source 가 없어 클라이언트가 `undefined.kind` 접근에서 throw →
        // 라우트 트리 전체가 network 에러로 무너진다. public getRoutes 와 동일한 source
        // 태깅 + 모듈/플러그인 병합을 활성/비활성 무관 + _bundled 폴백으로 수행한다.
        $result = $this->templateService->getEditorRoutesDataWithModules($identifier);

        if (! $result['success']) {
            return match ($result['error']) {
                'routes_not_found' => $this->error(__('templates.messages.routes_not_found'), 404),
                'invalid_json' => $this->error(__('templates.errors.invalid_json'), 422),
                default => $this->error(__('templates.messages.routes_not_found'), 404),
            };
        }

        return $this->success(__('templates.messages.routes_retrieved'), $result['data']);
    }

    /**
     * 편집기 스펙 서빙 — editor-spec.json 파일 반환 (활성/비활성 무관).
     *
     * 활성 디렉토리 → _bundled 폴백 순으로 editor-spec.json 을 읽어 반환한다.
     * Phase 4 S6-1 부터 응답에 sampleData/sampleGlobal/states 등 전 블록이 포함된다
     * ("골격 → 정식 응답" 이행). 파일 미작성 시 spec=null 폴백.
     *
     * @param  string  $identifier  템플릿 식별자
     * @return JsonResponse 편집기 스펙 응답
     */
    public function serveEditorSpec(string $identifier): JsonResponse
    {
        // 분할 editor-spec.json 은 manifest + `$include` 블록으로 구성된다.
        // 활성 디렉토리만 기준으로 합본한 단일 spec 을 반환한다(_bundled 폴백 없음).
        // 비활성 템플릿도 편집 가능하므로 status 가드는 없다. 미분할 파일은
        // 원본 반환(하위 호환), 미존재 시 null.
        $spec = EditorSpecAssembler::assemble(
            base_path("templates/{$identifier}/editor-spec.json")
        );

        $message = $spec === null
            ? __('templates.messages.editor_spec_empty')
            : __('templates.messages.editor_spec_retrieved');

        return $this->success($message, ['identifier' => $identifier, 'spec' => $spec]);
    }

    /**
     * 편집기 프리뷰 전용 CSS 서빙 — 다크 셀렉터를 프리뷰 마커로 치환.
     *
     * PO admin 환경이 다크 테마면 `<html class="dark">` 가 조상으로 남아, 프리뷰
     * 프레임에서 `.dark` 를 빼도(라이트 토글) Tailwind `.dark &` 가 활성돼 프리뷰가
     * 라이트로 격리되지 않는다. 이를 해소하기 위해, 편집기 진입 시에만 components.css 의
     * 다크 조상 셀렉터(`.dark`)를 프리뷰 전용 마커(`.g7le-preview-dark`)로 치환해 서빙한다.
     * 일반 사용자 페이지 CSS(public serveAsset)는 원본 그대로 → 사용자 페이지 100% 무영향.
     *
     * 치환 규칙은 템플릿 editor-spec 의 `darkMode.previewIsolation`(rewriteSelector →
     * replaceWith)에서 가져온다(라이브러리 중립). 미선언/`strategy:none` 이면 원본 그대로.
     * 변환 결과는 템플릿+확장 캐시 버전 키로 캐시(대용량 CSS 매요청 재가공 방지). 치환 실패
     * 또는 CSS 부재 시 원본/빈 응답으로 안전 폴백한다.
     *
     * @param  string  $identifier  템플릿 식별자
     * @return Response 변환된 CSS 응답 (text/css)
     */
    public function serveEditorCss(string $identifier)
    {
        $cssCandidates = [
            base_path("templates/{$identifier}/dist/css/components.css"),
            base_path("templates/_bundled/{$identifier}/dist/css/components.css"),
        ];

        $cssPath = null;
        foreach ($cssCandidates as $candidate) {
            if (file_exists($candidate)) {
                $cssPath = $candidate;
                break;
            }
        }

        if ($cssPath === null) {
            // CSS 부재 — 빈 CSS 폴백(편집기 부팅이 실패하지 않도록 200).
            return response('', 200)->header('Content-Type', 'text/css; charset=UTF-8');
        }

        // 코어 소유 키(`template.*`)는 CoreCacheDriver 직접 생성으로 다룬다 — `app(CacheInterface)`
        // 바인딩은 확장 컨텍스트로 누수될 수 있어(메모리 feedback_core_cache_no_container_binding)
        // 코어 캐시 store 를 빗나갈 수 있다.
        $cache = new CoreCacheDriver;
        $cacheVersion = (int) $cache->get('ext.cache_version', 0);
        // 캐시 키 — 템플릿 + 확장 캐시 버전 + 파일 mtime(빌드 변경 즉시 무효화).
        $mtime = (int) @filemtime($cssPath);
        $cacheKey = "template.editor_css.{$identifier}.v{$cacheVersion}.m{$mtime}";

        $cached = $cache->get($cacheKey);
        if (is_string($cached)) {
            return response($cached, 200)->header('Content-Type', 'text/css; charset=UTF-8');
        }

        $css = (string) file_get_contents($cssPath);

        // editor-spec 의 darkMode.previewIsolation 로 치환 규칙 해석.
        $isolation = $this->resolveDarkPreviewIsolation($identifier);
        if ($isolation !== null) {
            $css = $this->rewriteDarkSelectors($css, $isolation['rewrite'], $isolation['replace']);
            // @layer 평탄화 — CSS cascade-layer 를 쓰는 라이브러리(예 Tailwind v4)에서, 편집기
            // 프리뷰 CSS 가 어드민 호스트 CSS 와 같은 `@layer` 이름을 공유하면 cross-build 레이어
            // 우선순위 충돌로 프리뷰의 다크 규칙이 적용되지 않는다(브라우저 실측 확인). 편집기 CSS 는
            // 프리뷰 전용 + 마지막 로드이므로, 레이어 래퍼를 제거해 전부 unlayered(최고 우선순위,
            // 파일 내 소스 순서 보존)로 만들어 호스트 레이어드 규칙을 확실히 이긴다. 라이브러리
            // 특성이므로 템플릿이 `previewIsolation.flattenLayers: true` 로 명시 옵트인할 때만 수행한다
            // (라이브러리 중립 — @layer 비사용 CSS 는 평탄화 불필요).
            if ($isolation['flattenLayers']) {
                $css = $this->flattenCssLayers($css);
            }
        }

        // 변환 결과 캐시(실패해도 원본을 그대로 캐시 — 매요청 재가공 방지).
        $cache->put($cacheKey, $css, 3600);

        return response($css, 200)->header('Content-Type', 'text/css; charset=UTF-8');
    }

    /**
     * 템플릿 editor-spec 의 `darkMode.previewIsolation` 치환 규칙 해석.
     *
     * @param  string  $identifier  템플릿 식별자
     * @return array{rewrite: string, replace: string, flattenLayers: bool}|null 치환 규칙, 미선언/none 이면 null
     */
    private function resolveDarkPreviewIsolation(string $identifier): ?array
    {
        $candidates = [
            base_path("templates/{$identifier}/editor-spec.json"),
            base_path("templates/_bundled/{$identifier}/editor-spec.json"),
        ];

        foreach ($candidates as $path) {
            if (! file_exists($path)) {
                continue;
            }
            $spec = json_decode((string) file_get_contents($path), true);
            if (! is_array($spec)) {
                continue;

            }
            $dark = $spec['darkMode'] ?? null;
            if (! is_array($dark)) {
                return null;
            }
            $strategy = $dark['strategy'] ?? null;
            if ($strategy === 'none') {
                return null;
            }
            $iso = $dark['previewIsolation'] ?? null;
            if (! is_array($iso)) {
                return null;
            }
            $rewrite = $iso['rewriteSelector'] ?? null;
            $replace = $iso['replaceWith'] ?? null;
            if (is_string($rewrite) && $rewrite !== '' && is_string($replace) && $replace !== '') {
                return [
                    'rewrite' => $rewrite,
                    'replace' => $replace,
                    // @layer 평탄화는 라이브러리 특성(CSS @layer cascade-layer 사용 시 cross-build
                    // 우선순위 충돌)이라 템플릿이 명시 옵트인할 때만 수행한다(라이브러리 중립).
                    'flattenLayers' => ($iso['flattenLayers'] ?? false) === true,
                ];
            }

            return null;
        }

        return null;
    }

    /**
     * CSS 텍스트의 다크 조상 셀렉터를 프리뷰 마커로 안전 치환.
     *
     * 조상 셀렉터(`.dark` 가 후손 결합자/그룹 경계 앞)만 치환하고, 유틸리티 클래스
     * 자체(`.dark\:bg-x` — 이스케이프 콜론)는 건드리지 않는다. 경계 = 공백/`,`/`)`/`{`/`>`/`~`/`+`.
     *
     * @param  string  $css  원본 CSS
     * @param  string  $rewrite  원본 다크 셀렉터(예 `.dark`)
     * @param  string  $replace  치환 마커(예 `.g7le-preview-dark`)
     * @return string 치환된 CSS (치환 실패 시 원본)
     */
    private function rewriteDarkSelectors(string $css, string $rewrite, string $replace): string
    {
        // `.dark` 다음에 셀렉터 경계 문자(공백/조합자/그룹 경계)가 오는 경우만 치환.
        // `.dark\:` (유틸리티 클래스 — 이스케이프된 콜론)는 lookahead 가 `\` 라 매칭 안 됨.
        $pattern = '/'.preg_quote($rewrite, '/').'(?=[\s,){>~+])/';
        $result = preg_replace($pattern, $replace, $css);

        // preg 실패(null) 시 원본 폴백 — 프리뷰가 깨지지 않도록.
        return is_string($result) ? $result : $css;
    }

    /**
     * CSS 의 `@layer NAME { ... }` 블록 래퍼를 제거해 내부 규칙을 unlayered 로 평탄화한다.
     *
     * Tailwind v4 는 규칙을 `@layer theme/base/components/utilities` 로 감싼다. 편집기
     * 프리뷰 CSS 와 어드민 호스트 CSS 가 같은 레이어 이름을 쓰면 cross-build 레이어 우선순위
     * 충돌로 프리뷰 규칙이 적용되지 않을 수 있다. 편집기 CSS 는 프리뷰 전용 + 마지막 로드라
     * 권위를 가져야 하므로, 레이어 블록 래퍼만 벗겨 내부 규칙을 unlayered 로 만든다(unlayered >
     * layered). 규칙 자체와 소스 순서는 보존하므로 파일 내 cascade 는 불변.
     *
     * 중괄호 균형 스캐너로 `@layer <names> {` 의 짝 `}` 만 찾아 제거한다(정규식은 중첩 중괄호를
     * 안전히 매칭 못 함). `@layer <names>;`(선언만, 블록 없음)은 그대로 둔다(레이어 순서 선언).
     *
     * @param  string  $css  치환 완료된 CSS
     * @return string 레이어 래퍼가 제거된 CSS
     */
    private function flattenCssLayers(string $css): string
    {
        // 중첩 `@layer` 대비 — 더 이상 블록 오프너가 없을 때까지 반복(최대 8회, 폭주 방지).
        for ($pass = 0; $pass < 8; $pass++) {
            if (! preg_match('/@layer\s+[^;{]*\{/', $css)) {
                break;
            }
            $css = $this->flattenCssLayersOnce($css);
        }

        return $css;
    }

    /**
     * `@layer NAME { ... }` 블록 래퍼 1패스 제거(중괄호 균형 스캔). flattenCssLayers 가 반복 호출.
     *
     * @param  string  $css  CSS
     * @return string 1패스 평탄화 결과
     */
    private function flattenCssLayersOnce(string $css): string
    {
        $out = '';
        $len = strlen($css);
        $i = 0;
        // `@layer` 다음에 이름 목록 + `{` 가 오는 블록 오프너를 찾는다.
        while ($i < $len) {
            // `@layer` 리터럴 탐색
            $at = strpos($css, '@layer', $i);
            if ($at === false) {
                $out .= substr($css, $i);
                break;
            }
            // `@layer` 앞부분 그대로 출력
            $out .= substr($css, $i, $at - $i);

            // `@layer` 뒤 ~ 다음 `{` 또는 `;` 까지 — 이름 목록.
            $j = $at + 6; // strlen('@layer')
            $brace = strpos($css, '{', $j);
            $semi = strpos($css, ';', $j);

            // 블록 없는 선언(`@layer a, b;`)이면 `;` 가 먼저 — 그대로 보존.
            if ($semi !== false && ($brace === false || $semi < $brace)) {
                $out .= substr($css, $at, $semi - $at + 1);
                $i = $semi + 1;

                continue;
            }

            if ($brace === false) {
                // 형식 이상 — 남은 전체 출력 후 종료(폴백).
                $out .= substr($css, $at);
                break;
            }

            // `@layer <names> {` 블록 — 오프너(`@layer ... {`)는 버리고 내부를 평탄화.
            // 짝 `}` 를 중괄호 균형으로 찾는다.
            $depth = 1;
            $k = $brace + 1;
            while ($k < $len && $depth > 0) {
                $ch = $css[$k];
                if ($ch === '{') {
                    $depth++;
                } elseif ($ch === '}') {
                    $depth--;
                    if ($depth === 0) {
                        break;
                    }
                }
                $k++;
            }
            if ($depth !== 0) {
                // 짝 불일치 — 평탄화 포기, 원문 그대로 출력 후 종료(안전 폴백).
                $out .= substr($css, $at);
                break;
            }
            // 내부 내용(오프너 `{` 다음 ~ 짝 `}` 직전)만 채택 — 레이어 래퍼 제거.
            $out .= substr($css, $brace + 1, $k - ($brace + 1));
            $i = $k + 1; // 짝 `}` 다음부터 계속
        }

        return $out;
    }

    /**
     * 다국어 데이터 서빙 (활성/비활성 무관).
     *
     * `TemplateService::getLanguageDataWithModules` 는 활성 검증이 있으므로,
     * 편집기 admin 경로는 본 메서드에서 활성 검증을 우회한 폴백 로드를 수행한다.
     *
     * @param  string  $identifier  템플릿 식별자
     * @param  string  $locale  로케일
     * @return JsonResponse 다국어 데이터 응답
     */
    public function serveLanguage(string $identifier, string $locale): JsonResponse
    {
        $candidates = [
            base_path("templates/{$identifier}/lang/{$locale}.json"),
            base_path("templates/_bundled/{$identifier}/lang/{$locale}.json"),
        ];

        foreach ($candidates as $path) {
            if (file_exists($path)) {
                $data = json_decode((string) file_get_contents($path), true);
                if (is_array($data)) {
                    return $this->success(__('templates.messages.language_retrieved'), $data);
                }
            }
        }

        // 다국어 파일 부재 — 빈 객체 폴백
        return $this->success(__('templates.messages.language_empty'), []);
    }

    /**
     * 레이아웃 편집기 표시 권한 후보 서빙.
     *
     * 코어 + 활성 확장 권한 전체를 `{key, name}` 목록으로 반환한다. 편집기 진입 권한
     * (`core.templates.layouts.edit`) 가드 하에서만 노출되므로, 권한 카탈로그가 모든
     * admin 페이지에 상시 노출되던 종전 방식(`G7Config.permissions`)보다 노출 범위가
     * 편집기로 한정된다. 후보 미존재/조회 실패 시 빈 목록 → TagInput "+ 추가" 디그레이드.
     *
     * @param  string  $identifier  템플릿 식별자 (라우트 일관성용 — 후보는 전역 권한)
     * @return JsonResponse 권한 후보 목록 응답
     */
    public function servePermissionCandidates(string $identifier): JsonResponse
    {
        $candidates = $this->permissionService->getPermissionCandidates(app()->getLocale());

        return $this->success(
            __('templates.messages.config_retrieved'),
            ['identifier' => $identifier, 'permissions' => $candidates],
        );
    }
}
