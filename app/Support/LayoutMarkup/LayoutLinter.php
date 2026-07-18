<?php

namespace App\Support\LayoutMarkup;

use App\Enums\LayoutMarkupLintCode;

/**
 * 컴파일 산출 JSON 에 대한 정적 lint (S6).
 *
 * lint 은 컴파일을 **막지 않는다** — 산출 JSON 은 정상 생성되고, 저작자·리뷰어에게 "런타임에
 * 문제가 될 수 있다" 를 알린다. 강검증(`LayoutValidator`)이 "틀렸으니 못 만든다" 라면, lint 은
 * "만들 수는 있으나 권하지 않는다" 다. 그래서 예외가 아니라 `LintWarning` 목록을 모아 돌려준다.
 *
 * 다섯 가지를 본다:
 *  ① **미등록 핸들러** — 액션 handler 가 코어 44종·템플릿 등록·확장(`.`) 어디에도 없음(D30⑦).
 *  ② **플러그인 완전 레이아웃** — 플러그인은 layout_extensions·설정 화면만 두는 관례이나 완전
 *     레이아웃을 정의함(gdpr_consent_log·plugin_settings 예외, D15).
 *  ③ **nesting 위반** — 부모 컨테이너의 accepts 가 허용하지 않는 자식(빈 accepts 부모는 제외 —
 *     편집기 정책이라 Table→Thead 같은 오검출을 부른다는 계획서 경고를 반영).
 *  ④ **responsive breakpoint 혼용** — 한 문서에 portable 프리셋과 mobile/tablet 프리셋 병용.
 *  ⑤ **독립 레이아웃의 글로벌 호스트 누락** — extends 없는 레이아웃이 toast·openModal 을 발화하나
 *     Toast·Modal 호스트를 마운트하지 않음(CLAUDE.md 의 silent-failure 회귀 규정).
 *
 * lint 은 산출 JSON 을 대상으로 하므로 소스 좌표를 특정하기 어렵다 — 경고는 문서 전체(라인 0)로
 * 낸다. 지어낸 좌표로 저작자를 엉뚱한 자리로 보내지 않는다.
 */
final class LayoutLinter
{
    /** toast·모달을 화면에 띄우려면 마운트돼야 하는 호스트 컴포넌트 name */
    private const GLOBAL_HOST_COMPONENTS = ['Toast', 'ModalRoot', 'Modal'];

    /** 독립 레이아웃에서 호스트를 요구하는 글로벌 상태 기반 핸들러 */
    private const GLOBAL_HOST_HANDLERS = ['toast', 'openModal', 'showAlert'];

    private readonly ActionHandlerManifest $handlers;

    public function __construct()
    {
        $this->handlers = new ActionHandlerManifest;
    }

    /**
     * 산출 JSON 을 lint 하고 경고 목록을 반환합니다.
     *
     * @param  array<string, mixed>  $json  컴파일 산출 레이아웃 JSON
     * @param  string  $rootKind  문서 봉투 (`layout`·`partial`·`extension`·`override`)
     * @param  CompileContext  $context  lint 문맥
     * @param  string|null  $path  소스 파일 경로 (경고 메시지용)
     * @return array<int, LintWarning> 경고 목록
     */
    public function lint(array $json, string $rootKind, CompileContext $context, ?string $path = null): array
    {
        $warnings = [];

        $this->lintUnregisteredHandlers($json, $context, $path, $warnings);
        $this->lintPluginCompleteLayout($json, $rootKind, $context, $path, $warnings);
        $this->lintNesting($json, $context, $path, $warnings);
        $this->lintResponsiveMix($json, $path, $warnings);
        $this->lintStandaloneGlobalHost($json, $rootKind, $path, $warnings);

        return $warnings;
    }

    /**
     * ① 산출된 모든 액션의 handler 가 알려진 핸들러인지 확인합니다.
     *
     * @param  array<string, mixed>  $json  산출 JSON
     * @param  CompileContext  $context  문맥 (템플릿 등록 핸들러)
     * @param  string|null  $path  소스 파일 경로
     * @param  array<int, LintWarning>  $warnings  경고 목록 (참조)
     */
    private function lintUnregisteredHandlers(array $json, CompileContext $context, ?string $path, array &$warnings): void
    {
        $seen = [];

        foreach ($this->collectHandlers($json) as $handler) {
            if ($this->isKnownHandler($handler, $context) || isset($seen[$handler])) {
                continue;
            }

            $seen[$handler] = true;
            $warnings[] = new LintWarning(LayoutMarkupLintCode::UnregisteredHandler, 0, 0, ['handler' => $handler], $path);
        }
    }

    /**
     * 핸들러가 코어 44종·확장(`.`)·템플릿 등록·custom 디스패치 중 하나인지 판정합니다.
     *
     * @param  string  $handler  핸들러명
     * @param  CompileContext  $context  문맥
     * @return bool 알려진 핸들러이면 true
     */
    private function isKnownHandler(string $handler, CompileContext $context): bool
    {
        // 확장·템플릿 네임스페이스 핸들러는 점을 품는다(sirsoft-ecommerce.toggleArrayValue).
        if (str_contains($handler, '.')) {
            return true;
        }

        // custom = 이름(name/params.name)으로 템플릿 핸들러를 부르는 디스패처. 이름 판정은
        // 템플릿 종속이라 여기서는 알려진 것으로 둔다(오검출 회피).
        if ($handler === 'custom') {
            return true;
        }

        return $this->handlers->isCoreHandler($handler) || in_array($handler, $context->templateHandlers, true);
    }

    /**
     * ② 플러그인이 완전 레이아웃을 정의하는지 확인합니다 (allowlist 예외).
     *
     * @param  array<string, mixed>  $json  산출 JSON
     * @param  string  $rootKind  문서 봉투
     * @param  CompileContext  $context  문맥 (소스 종류·식별자·allowlist)
     * @param  string|null  $path  소스 파일 경로
     * @param  array<int, LintWarning>  $warnings  경고 목록 (참조)
     */
    private function lintPluginCompleteLayout(array $json, string $rootKind, CompileContext $context, ?string $path, array &$warnings): void
    {
        if ($context->sourceKind !== 'plugin' || $rootKind !== 'layout') {
            return;
        }

        $layoutName = $json['layout_name'] ?? null;

        if (is_string($layoutName) && in_array($layoutName, $context->pluginCompleteLayoutAllowlist(), true)) {
            return;
        }

        $warnings[] = new LintWarning(LayoutMarkupLintCode::PluginCompleteLayout, 0, 0, [
            'layout' => is_string($layoutName) ? $layoutName : '?',
        ], $path);
    }

    /**
     * ③ 부모 컨테이너의 accepts 가 허용하지 않는 자식이 있는지 확인합니다.
     *
     * accepts 가 빈 목록인 부모(leaf·composite)는 검사하지 않는다 — nesting.json 은 편집기 드래그
     * 정책이라 Table(accepts:[]) 밑의 Thead 를 오검출한다는 계획서 경고를 반영한 것이다.
     *
     * @param  array<string, mixed>  $json  산출 JSON
     * @param  CompileContext  $context  문맥 (nesting 규칙)
     * @param  string|null  $path  소스 파일 경로
     * @param  array<int, LintWarning>  $warnings  경고 목록 (참조)
     */
    private function lintNesting(array $json, CompileContext $context, ?string $path, array &$warnings): void
    {
        if ($context->nestingRules === []) {
            return;
        }

        $seen = [];

        foreach ($this->collectNodes($json) as $node) {
            $parentName = $node['name'] ?? null;
            $accepts = is_string($parentName) ? ($context->nestingRules[$parentName] ?? null) : null;

            // 규칙 없음 또는 빈 accepts(leaf·composite) → 검사 제외.
            if (! is_array($accepts) || $accepts === []) {
                continue;
            }

            foreach ($node['children'] ?? [] as $child) {
                $childName = is_array($child) ? ($child['name'] ?? null) : null;

                if (is_string($childName) && ! in_array($childName, $accepts, true)) {
                    $key = $parentName.'>'.$childName;

                    if (isset($seen[$key])) {
                        continue;
                    }

                    $seen[$key] = true;
                    $warnings[] = new LintWarning(LayoutMarkupLintCode::NestingViolation, 0, 0, [
                        'parent' => (string) $parentName,
                        'child' => $childName,
                    ], $path);
                }
            }
        }
    }

    /**
     * ④ portable 프리셋과 mobile/tablet 프리셋이 한 문서에 병용됐는지 확인합니다.
     *
     * @param  array<string, mixed>  $json  산출 JSON
     * @param  string|null  $path  소스 파일 경로
     * @param  array<int, LintWarning>  $warnings  경고 목록 (참조)
     */
    private function lintResponsiveMix(array $json, ?string $path, array &$warnings): void
    {
        $breakpoints = [];
        $this->collectResponsiveBreakpoints($json, $breakpoints);

        $hasPortable = isset($breakpoints['portable']);
        $hasSplit = isset($breakpoints['mobile']) || isset($breakpoints['tablet']);

        if ($hasPortable && $hasSplit) {
            $warnings[] = new LintWarning(LayoutMarkupLintCode::ResponsiveBreakpointMix, 0, 0, [], $path);
        }
    }

    /**
     * ⑤ 독립 레이아웃이 글로벌 호스트 없이 toast·모달을 발화하는지 확인합니다.
     *
     * @param  array<string, mixed>  $json  산출 JSON
     * @param  string  $rootKind  문서 봉투
     * @param  string|null  $path  소스 파일 경로
     * @param  array<int, LintWarning>  $warnings  경고 목록 (참조)
     */
    private function lintStandaloneGlobalHost(array $json, string $rootKind, ?string $path, array &$warnings): void
    {
        // extends 로 베이스를 상속하면 호스트는 베이스가 마운트한다 — 검사 대상 아님.
        if ($rootKind !== 'layout' || isset($json['extends'])) {
            return;
        }

        $firesGlobal = false;
        foreach ($this->collectHandlers($json) as $handler) {
            if (in_array($handler, self::GLOBAL_HOST_HANDLERS, true)) {
                $firesGlobal = true;
                break;
            }
        }

        if (! $firesGlobal) {
            return;
        }

        // 호스트 컴포넌트가 트리에 있거나 modals 섹션이 있으면 화면에 뜬다.
        if (isset($json['modals']) && $json['modals'] !== []) {
            return;
        }

        foreach ($this->collectNodes($json) as $node) {
            if (in_array($node['name'] ?? null, self::GLOBAL_HOST_COMPONENTS, true)) {
                return;
            }
        }

        $warnings[] = new LintWarning(LayoutMarkupLintCode::StandaloneMissingGlobalHost, 0, 0, [], $path);
    }

    // ---------------------------------------------------------------------
    // 수집 헬퍼
    // ---------------------------------------------------------------------

    /**
     * 문서 전역의 모든 액션 handler 문자열을 모읍니다 (노드 actions·init·named·콜백·중첩 액션).
     *
     * @param  array<string, mixed>  $json  산출 JSON
     * @return array<int, string> handler 문자열 목록
     */
    private function collectHandlers(array $json): array
    {
        $handlers = [];
        $this->walkForHandlers($json, $handlers);

        return $handlers;
    }

    /**
     * 임의 구조를 심층 순회하며 `handler` 키를 가진 액션 객체를 찾습니다.
     *
     * 자식 키를 열거하지 않고 전 구조를 훑는다 — 열거하면 새 키(콜백·케이스 등)가 생길 때 그
     * 가지가 조용히 빠진다.
     *
     * @param  mixed  $value  순회 대상
     * @param  array<int, string>  $handlers  handler 목록 (참조)
     */
    private function walkForHandlers(mixed $value, array &$handlers): void
    {
        if (! is_array($value)) {
            return;
        }

        if (isset($value['handler']) && is_string($value['handler'])) {
            $handlers[] = $value['handler'];
        }

        foreach ($value as $child) {
            $this->walkForHandlers($child, $handlers);
        }
    }

    /**
     * 컴포넌트 노드(type+name 보유)를 심층 순회로 모읍니다.
     *
     * @param  array<string, mixed>  $json  산출 JSON
     * @return array<int, array<string, mixed>> 노드 목록
     */
    private function collectNodes(array $json): array
    {
        $nodes = [];
        $this->walkForNodes($json, $nodes);

        return $nodes;
    }

    /**
     * 임의 구조를 심층 순회하며 컴포넌트 노드를 찾습니다.
     *
     * @param  mixed  $value  순회 대상
     * @param  array<int, array<string, mixed>>  $nodes  노드 목록 (참조)
     */
    private function walkForNodes(mixed $value, array &$nodes): void
    {
        if (! is_array($value)) {
            return;
        }

        if (isset($value['type'], $value['name']) && is_string($value['type']) && is_string($value['name'])) {
            $nodes[] = $value;
        }

        foreach ($value as $child) {
            $this->walkForNodes($child, $nodes);
        }
    }

    /**
     * responsive 오버라이드의 breakpoint 키를 심층 순회로 모읍니다.
     *
     * @param  mixed  $value  순회 대상
     * @param  array<string, bool>  $breakpoints  breakpoint 키 집합 (참조)
     */
    private function collectResponsiveBreakpoints(mixed $value, array &$breakpoints): void
    {
        if (! is_array($value)) {
            return;
        }

        if (isset($value['responsive']) && is_array($value['responsive'])) {
            foreach (array_keys($value['responsive']) as $breakpoint) {
                $breakpoints[(string) $breakpoint] = true;
            }
        }

        foreach ($value as $child) {
            $this->collectResponsiveBreakpoints($child, $breakpoints);
        }
    }
}
