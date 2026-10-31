<?php

namespace App\Support\LayoutMarkup;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * `.g7.html` 파일 경로에서 컴파일 **문맥**과, 원할 때 **검사용 레지스트리**를 만드는 해석기.
 *
 * 컴파일 산출은 템플릿과 무관하다 — 태그 이름·`data-type` 만으로 정한다(JSON 시절 작성자가 `name`·`type`
 * 을 직접 적던 것과 같다). 그래서 설치·업데이트·조각 주입·설정 화면 경로는 {@see installContextFor()}
 * (소속 정보만)로 컴파일하고 레지스트리를 주지 않는다.
 *
 * 레지스트리·템플릿 문맥(핸들러·nesting 규칙)은 **특정 템플릿 기준 검사**가 필요한 자리 — 편집기와
 * 템플릿 자신의 레이아웃을 점검하는 개발 커맨드 — 에서만 쓴다. 대상 템플릿은 호출자가 지정하거나(템플릿
 * 자신의 레이아웃이면) 경로에서 읽으며, 코어는 어떤 템플릿도 이름으로 고르지 않는다. 검사 목록에는 그 템플릿과
 * 함께 **설치된 확장 전부**(활성 여부 무관)의 `components.json` 이 실린다 — 설치 목록은 호출자가 넘긴다.
 */
final class MarkupCompileContextResolver
{
    /**
     * 파일 경로에서 대상 템플릿 컴포넌트 레지스트리를 만듭니다.
     *
     * 검사 대상은 **대상 템플릿 + 넘겨받은 확장 매니페스트**(설치된 모듈·플러그인 전부 — 활성 여부 무관)다.
     * 확장 목록은 호출자(서비스·커맨드)가 설치 목록에서 골라 {@see extensionManifests()} 로 읽어 넘긴다 —
     * 이 해석기는 DB 를 보지 않는다.
     *
     * @param  string  $file  `.g7.html` 경로
     * @param  string|null  $templateOverride  대상 템플릿 지정 (편집기·설치본 점검)
     * @param  array<int, ComponentManifest>  $extensions  함께 실을 설치 확장 매니페스트
     * @return ComponentRegistry|null 레지스트리, 대상 템플릿이 정해지지 않거나 못 찾으면 null
     */
    public function registryFor(string $file, ?string $templateOverride = null, array $extensions = []): ?ComponentRegistry
    {
        $templateId = $this->targetTemplateId($file, $templateOverride);

        if ($templateId === null) {
            return null;
        }

        $manifest = base_path($this->templateRoot($templateId, $file).'/components.json');

        if (! File::exists($manifest)) {
            return null;
        }

        return $this->mergeExtensions(ComponentManifest::fromFile($manifest), $extensions);
    }

    /**
     * 검사 대상 템플릿의 디렉토리를 문서와 **같은 트리**에서 고릅니다.
     *
     * 편집기·관리 서비스(파일 경로 없음)와 설치 디렉토리의 문서는 설치본(`templates/{id}`)만 본다 — 없으면
     * `_bundled` 로 물러서지 않고 부재로 둔다(결정 D1: 설치본만 읽고 폴백하지 않는다). 번들 트리 안의
     * 문서(`layout:decompile --all` 이 `templates/_bundled/x/layouts/…` 를 점검할 때)는 그 같은 트리의 템플릿을
     * 본다 — 번들 소스를 번들 소스로 점검하는 것이라 설치 상태와 무관해야 한다(`declaredScriptHosts` 와 같은 방식).
     *
     * @param  string  $templateId  템플릿 식별자
     * @param  string  $file  문서 경로 (편집기·설치본처럼 파일이 없으면 빈 문자열)
     * @return string base_path 기준 템플릿 디렉토리
     */
    private function templateRoot(string $templateId, string $file): string
    {
        // audit:allow install-path-reads-bundled-or-template-id reason: 문서 경로가 번들 트리인지 판별만 한다(읽지 않음)
        $bundledTree = preg_match('#/(?:templates|modules|plugins)/_bundled/#', str_replace('\\', '/', $file)) === 1;

        // audit:allow install-path-reads-bundled-or-template-id reason: 번들 트리 안의 문서만 같은 번들 트리를 본다 — 설치 경로·편집기는 설치본만(D1)
        return $bundledTree ? "templates/_bundled/{$templateId}" : "templates/{$templateId}";
    }

    /**
     * 설치된 확장의 컴포넌트 매니페스트를 설치 디렉토리에서 읽습니다 (`_bundled` 는 보지 않는다).
     *
     * 편집 도중 확장이 꺼져도 편집이 이어져야 하므로 활성 여부로 거르지 않는다(2026-09-28 결정). 매니페스트가
     * 없는 확장은 제공 컴포넌트가 없는 것이고, 깨진 매니페스트는 그 확장만 빼고 로그를 남긴다 — 확장 하나의
     * 결함이 다른 레이아웃의 편집을 막지 않게 한다(매니페스트 자체의 정합성은 그 확장의 빌드·설치가 검사한다).
     *
     * @param  array<int, string>  $moduleIdentifiers  설치된 모듈 식별자
     * @param  array<int, string>  $pluginIdentifiers  설치된 플러그인 식별자
     * @return array<int, ComponentManifest> 읽은 확장 매니페스트
     */
    public function extensionManifests(array $moduleIdentifiers, array $pluginIdentifiers): array
    {
        $paths = array_merge(
            array_map(static fn (string $id): string => "modules/{$id}/components.json", $moduleIdentifiers),
            array_map(static fn (string $id): string => "plugins/{$id}/components.json", $pluginIdentifiers),
        );

        $manifests = [];

        foreach ($paths as $relative) {
            $path = base_path($relative);

            if (! File::exists($path)) {
                continue;
            }

            try {
                $manifests[] = ComponentManifest::fromFile($path);
            } catch (CompileError $error) {
                Log::warning('확장 컴포넌트 매니페스트를 읽지 못해 검사 목록에서 뺍니다', [
                    'path' => $relative,
                    'error' => $error->getMessage(),
                ]);
            }
        }

        return $manifests;
    }

    /**
     * 템플릿 매니페스트에 확장 매니페스트를 병합해 검사용 레지스트리를 만듭니다.
     *
     * 확장끼리 이름이 겹치거나 템플릿 오버라이드가 맞지 않아 병합이 거부되면 템플릿만으로 물러선다 —
     * 검사 목록이 좁아질 뿐(확장 컴포넌트는 경고로 남는다) 편집 자체는 막지 않는다.
     *
     * @param  ComponentManifest  $template  대상 템플릿 매니페스트
     * @param  array<int, ComponentManifest>  $extensions  확장 매니페스트
     * @return ComponentRegistry 레지스트리
     *
     * @throws CompileError 템플릿 매니페스트 자체가 레지스트리를 만들 수 없는 경우
     */
    private function mergeExtensions(ComponentManifest $template, array $extensions): ComponentRegistry
    {
        if ($extensions === []) {
            return ComponentRegistry::fromManifests($template);
        }

        try {
            return ComponentRegistry::fromManifests($template, $extensions);
        } catch (CompileError $error) {
            Log::warning('확장 컴포넌트를 검사 목록에 합치지 못해 템플릿 컴포넌트만으로 검사합니다', [
                'template' => $template->ownerId,
                'error' => $error->getMessage(),
            ]);

            return ComponentRegistry::fromManifests($template);
        }
    }

    /**
     * 파일 경로에서 검증·lint 문맥을 만듭니다.
     *
     * @param  string  $file  `.g7.html` 경로
     * @param  string|null  $templateOverride  대상 템플릿 지정 (편집기·설치본 점검)
     * @param  string|null  $identifierOverride  소유 확장·템플릿 식별자 강제 지정 — 경로에서 뽑을 수 없는 위치(업데이트 백업 `{id}_{Ymd_His}`)용
     * @return CompileContext 컴파일 문맥
     */
    public function contextFor(string $file, ?string $templateOverride = null, ?string $identifierOverride = null): CompileContext
    {
        $normalized = str_replace('\\', '/', $file);
        $sourceKind = match (true) {
            str_contains($normalized, '/plugins/') => 'plugin',
            str_contains($normalized, '/modules/') => 'module',
            str_contains($normalized, '/templates/') => 'template',
            default => null,
        };

        $templateId = $this->targetTemplateId($file, $templateOverride);

        return new CompileContext(
            sourceKind: $sourceKind,
            identifier: $identifierOverride ?? $this->extensionIdentifier($normalized),
            templateHandlers: $templateId !== null ? $this->scanTemplateHandlers($templateId, $file) : [],
            nestingRules: $templateId !== null ? $this->loadNestingRules($templateId, $file) : [],
            declaredScriptHosts: $this->declaredScriptHosts($normalized),
        );
    }

    /**
     * 설치·업데이트·조각 주입·설정 화면 경로의 컴파일 문맥을 만듭니다 — 소속 정보만, 템플릿 무관.
     *
     * JSON 설치가 어떤 템플릿도 보지 않았던 것과 같이, 이 경로는 템플릿 핸들러·nesting 규칙을 싣지 않는다.
     * 외부 JS 로더 호스트도 검사하지 않는다 — JSON 설치가 하지 않았고, 호스트는 그 레이아웃을 가진 확장이
     * 스스로 선언하므로 설치 시점 대조는 순환이며, 오버라이드 대상 확장이 아직 없으면 조각이 조용히 빠진다.
     *
     * @param  string  $file  `.g7.html` 경로
     * @param  string|null  $identifierOverride  소유 확장·템플릿 식별자 강제 지정 — 경로에서 뽑을 수 없는 위치(업데이트 백업 `{id}_{Ymd_His}`)용
     * @return CompileContext 컴파일 문맥
     */
    public function installContextFor(string $file, ?string $identifierOverride = null): CompileContext
    {
        $normalized = str_replace('\\', '/', $file);

        return new CompileContext(
            sourceKind: match (true) {
                str_contains($normalized, '/plugins/') => 'plugin',
                str_contains($normalized, '/modules/') => 'module',
                str_contains($normalized, '/templates/') => 'template',
                default => null,
            },
            identifier: $identifierOverride ?? $this->extensionIdentifier($normalized),
            checksScriptSrc: false,
        );
    }

    /**
     * 문서 소유 확장·템플릿(과 템플릿 오버라이드면 대상 확장)이 매니페스트로 선언한 신뢰 스크립트 호스트를 읽습니다.
     *
     * 소유 매니페스트는 문서에서 위로 올라가며 처음 만나는 `module.json`·`plugin.json`·`template.json` 이다.
     * 템플릿의 `extensions/{확장}/` 오버라이드는 대상 확장의 호스트를 싣는 것이 정상이므로, 문서와 같은
     * 트리(설치 디렉토리 또는 `_bundled`)에 있는 대상 확장 매니페스트의 선언도 더한다.
     *
     * @param  string  $normalized  슬래시 정규화된 `.g7.html` 경로
     * @return array<int, string> 선언 호스트 목록
     */
    public function declaredScriptHosts(string $normalized): array
    {
        $manifests = [];

        $dir = dirname($normalized);
        while ($dir !== '' && $dir !== '.' && $dir !== dirname($dir)) {
            $found = array_values(array_filter(
                ["{$dir}/module.json", "{$dir}/plugin.json", "{$dir}/template.json"],
                fn (string $candidate) => File::exists($candidate),
            ));

            if ($found !== []) {
                $manifests[] = $found[0];
                break;
            }

            $dir = dirname($dir);
        }

        if (preg_match('#^(.*)/templates/(_bundled/)?[^/]+/extensions/([^/]+)/#', $normalized, $m) === 1) {
            // audit:allow install-path-reads-bundled-or-template-id reason: 문서와 같은 트리의 대상 확장만 본다 — 번들 트리 문서일 때만 번들 트리(A11)
            $tree = $m[2] !== '' ? '/_bundled' : '';
            $manifests[] = "{$m[1]}/modules{$tree}/{$m[3]}/module.json";
            $manifests[] = "{$m[1]}/plugins{$tree}/{$m[3]}/plugin.json";
        }

        $hosts = [];
        foreach ($manifests as $manifest) {
            if (! File::exists($manifest)) {
                continue;
            }

            $declared = json_decode((string) File::get($manifest), true)['trusted_script_hosts'] ?? [];

            if (is_array($declared)) {
                $hosts = array_merge($hosts, array_filter($declared, 'is_string'));
            }
        }

        return array_values(array_unique($hosts));
    }

    /**
     * 템플릿 식별자로 컴포넌트 레지스트리를 만듭니다 — 설치본(DB 행)처럼 파일 경로가 없는 문서용.
     *
     * 규칙은 {@see registryFor()} 와 같다 — 파일 경로가 없으므로 설치본(`templates/{id}`)만 보고 `_bundled` 로
     * 물러서지 않는다(결정 D1). 두 메서드가 같은 판정(`templateRoot`)을 공유해 규칙이 갈리지 않게 한다.
     *
     * @param  string  $templateId  템플릿 식별자
     * @param  array<int, ComponentManifest>  $extensions  함께 실을 설치 확장 매니페스트
     * @return ComponentRegistry|null 레지스트리, 매니페스트가 없으면 null
     */
    public function registryForTemplate(string $templateId, array $extensions = []): ?ComponentRegistry
    {
        return $this->registryFor('', $templateId, $extensions);
    }

    /**
     * 설치본(DB 행) 문서의 검증·lint 문맥을 만듭니다 — 파일 경로 없이 소속 정보를 직접 받는다.
     *
     * 편집기·점검 커맨드가 `template_layouts`·`template_layout_extensions` 행을 프로덕션 컴파일할 때 쓴다.
     * 소스 종류·식별자는 행의 `source_type`·`source_identifier` 에서 오고, 핸들러·nesting 규칙은
     * {@see contextFor()} 와 같은 함수로 대상 템플릿에서 읽는다.
     *
     * @param  string  $templateId  대상 템플릿 식별자
     * @param  string|null  $sourceKind  소유 소스 종류 (`template`·`module`·`plugin`·null)
     * @param  string|null  $identifier  소유 확장·템플릿 식별자
     * @param  array<string, int>  $toleratedComponents  저장본에 이미 있던 미등록 템플릿 컴포넌트 이름 → 허용 개수 (편집기 전용, #712)
     * @return CompileContext 컴파일 문맥
     */
    public function contextForInstalled(string $templateId, ?string $sourceKind, ?string $identifier, array $toleratedComponents = []): CompileContext
    {
        return new CompileContext(
            sourceKind: $sourceKind,
            identifier: $identifier,
            templateHandlers: $this->scanTemplateHandlers($templateId),
            nestingRules: $this->loadNestingRules($templateId),
            toleratedComponents: $toleratedComponents,
        );
    }

    /**
     * 검사 대상 템플릿 식별자를 결정합니다 (호출자 지정 우선, 없으면 템플릿 자신의 레이아웃일 때만).
     *
     * 모듈·플러그인 레이아웃은 경로로 템플릿을 고르지 않는다 — 어떤 템플릿에도 붙을 수 있으므로 템플릿을
     * 정하는 것은 호출자(편집기·`--template`)의 몫이다. 정해지지 않으면 null 이고, 검사 없이 컴파일한다.
     *
     * @param  string  $file  `.g7.html` 경로
     * @param  string|null  $templateOverride  대상 템플릿 지정
     * @return string|null 템플릿 식별자, 정해지지 않으면 null
     */
    public function targetTemplateId(string $file, ?string $templateOverride = null): ?string
    {
        if ($templateOverride !== null && $templateOverride !== '') {
            return $templateOverride;
        }

        $normalized = str_replace('\\', '/', $file);

        // 템플릿 자신의 레이아웃이면 그 템플릿.
        if (preg_match('#/templates/(?:_bundled/)?([^/]+)/#', $normalized, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    /**
     * 확장 레이아웃 경로에서 소유 확장 식별자를 뽑습니다.
     *
     * @param  string  $normalized  슬래시 정규화된 경로
     * @return string|null 확장 식별자, 확장 소속이 아니면 null
     */
    public function extensionIdentifier(string $normalized): ?string
    {
        if (preg_match('#/(?:modules|plugins|templates)/(?:_bundled/)?([^/]+)/#', $normalized, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    /**
     * 템플릿이 등록한 핸들러 이름을 `src/handlers/index.ts` 에서 정적 파싱합니다.
     *
     * TS 를 실행하지 않고 객체 리터럴 키만 뽑는다 — lint 은 경고이므로 완벽한 파싱보다 흔한
     * 형태(따옴표/무따옴표 키)를 넉넉히 잡는 편이 오검출을 줄인다.
     *
     * 템플릿 디렉토리는 문서와 같은 트리에서 고른다(`templateRoot` — 설치본만, 번들 트리 문서는 번들 트리).
     *
     * @param  string  $templateId  템플릿 식별자
     * @param  string  $document  검사 대상 문서 경로 (편집기·설치본처럼 파일이 없으면 빈 문자열)
     * @return array<int, string> 등록 핸들러 이름 목록
     */
    public function scanTemplateHandlers(string $templateId, string $document = ''): array
    {
        $file = base_path($this->templateRoot($templateId, $document).'/src/handlers/index.ts');

        if (! File::exists($file)) {
            return [];
        }

        $source = File::get($file);
        $handlers = [];

        // `key:` / `'key':` / `"key":` 형태의 객체 리터럴 키 (핸들러 맵) 를 모은다.
        if (preg_match_all('/^\s*[\'"]?([A-Za-z][\w.$-]*)[\'"]?\s*:/m', $source, $matches) !== false) {
            $handlers = $matches[1];
        }

        return array_values(array_unique($handlers));
    }

    /**
     * 템플릿의 nesting.json 에서 컨테이너 → accepts 맵을 읽습니다.
     *
     * 템플릿 디렉토리는 문서와 같은 트리에서 고른다(`templateRoot` — 설치본만, 번들 트리 문서는 번들 트리).
     *
     * @param  string  $templateId  템플릿 식별자
     * @param  string  $document  검사 대상 문서 경로 (편집기·설치본처럼 파일이 없으면 빈 문자열)
     * @return array<string, array<int, string>> 컨테이너 name → 허용 자식 name 목록
     */
    public function loadNestingRules(string $templateId, string $document = ''): array
    {
        $file = base_path($this->templateRoot($templateId, $document).'/editor-spec/nesting.json');

        if (! File::exists($file)) {
            return [];
        }

        $data = json_decode(File::get($file), true);
        $rules = [];

        foreach ((is_array($data) ? $data['containers'] ?? [] : []) as $name => $spec) {
            if (isset($spec['accepts']) && is_array($spec['accepts'])) {
                $rules[$name] = $spec['accepts'];
            }
        }

        return $rules;
    }
}
