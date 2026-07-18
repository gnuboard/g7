<?php

namespace App\Support\LayoutMarkup;

use Illuminate\Support\Facades\File;

/**
 * `.g7.html` 파일 경로에서 컴파일에 필요한 **레지스트리**와 **문맥**을 만드는 해석기 (S7).
 *
 * 컴파일 자체는 소스만 있으면 되지 않는다 — 태그를 해석할 대상 템플릿의 `components.json`(레지스트리)
 * 과, 강검증·lint 이 참조하는 소속 정보(소스 종류·확장 식별자·템플릿 핸들러·nesting 규칙)를 파일
 * 경로에서 끌어와야 한다. 이 규칙(admin/·user/ 로 대상 템플릿 판별, `_bundled` 폴백, TS 핸들러 맵
 * 정적 파싱 등)은 `layout:compile` 과 `layout:install-diff` 가 **똑같이** 써야 하므로 한 곳에 둔다 —
 * 두 커맨드가 각자 해석하면 규칙이 갈려 "커맨드는 통과하는데 이관 검증은 실패" 같은 드리프트가 난다.
 */
final class MarkupCompileContextResolver
{
    /**
     * 파일 경로에서 대상 템플릿 컴포넌트 레지스트리를 만듭니다.
     *
     * @param  string  $file  `.g7.html` 경로
     * @param  string|null  $templateOverride  대상 템플릿 강제 지정 (`--template`)
     * @return ComponentRegistry|null 레지스트리, 대상 템플릿을 못 찾으면 null
     */
    public function registryFor(string $file, ?string $templateOverride = null): ?ComponentRegistry
    {
        $templateId = $this->targetTemplateId($file, $templateOverride);

        foreach (["templates/{$templateId}/components.json", "templates/_bundled/{$templateId}/components.json"] as $candidate) {
            $manifest = base_path($candidate);

            if (File::exists($manifest)) {
                return ComponentRegistry::fromFile($manifest);
            }
        }

        return null;
    }

    /**
     * 파일 경로에서 검증·lint 문맥을 만듭니다.
     *
     * @param  string  $file  `.g7.html` 경로
     * @param  string|null  $templateOverride  대상 템플릿 강제 지정 (`--template`)
     * @return CompileContext 컴파일 문맥
     */
    public function contextFor(string $file, ?string $templateOverride = null): CompileContext
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
            identifier: $this->extensionIdentifier($normalized),
            templateHandlers: $this->scanTemplateHandlers($templateId),
            nestingRules: $this->loadNestingRules($templateId),
        );
    }

    /**
     * 대상 템플릿 식별자를 결정합니다 (`--template` 우선, 없으면 admin/·user/ 디렉토리로 판별).
     *
     * @param  string  $file  `.g7.html` 경로
     * @param  string|null  $templateOverride  대상 템플릿 강제 지정
     * @return string 템플릿 식별자
     */
    public function targetTemplateId(string $file, ?string $templateOverride = null): string
    {
        if ($templateOverride !== null && $templateOverride !== '') {
            return $templateOverride;
        }

        $normalized = str_replace('\\', '/', $file);

        // 템플릿 자신의 레이아웃이면 그 템플릿.
        if (preg_match('#/templates/(?:_bundled/)?([^/]+)/#', $normalized, $m) === 1) {
            return $m[1];
        }

        // 모듈·플러그인 레이아웃은 admin/·user/ 로 대상 템플릿이 갈린다.
        return str_contains($normalized, '/user/') ? 'sirsoft-basic' : 'sirsoft-admin_basic';
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
     * @param  string  $templateId  템플릿 식별자
     * @return array<int, string> 등록 핸들러 이름 목록
     */
    public function scanTemplateHandlers(string $templateId): array
    {
        foreach (["templates/{$templateId}/src/handlers/index.ts", "templates/_bundled/{$templateId}/src/handlers/index.ts"] as $candidate) {
            $file = base_path($candidate);

            if (! File::exists($file)) {
                continue;
            }

            $source = File::get($file);
            $handlers = [];

            // `key:` / `'key':` / `"key":` 형태의 객체 리터럴 키 (핸들러 맵) 를 모은다.
            if (preg_match_all('/^\s*[\'"]?([A-Za-z][\w.$-]*)[\'"]?\s*:/m', $source, $matches) !== false) {
                $handlers = $matches[1];
            }

            return array_values(array_unique($handlers));
        }

        return [];
    }

    /**
     * 템플릿의 nesting.json 에서 컨테이너 → accepts 맵을 읽습니다.
     *
     * @param  string  $templateId  템플릿 식별자
     * @return array<string, array<int, string>> 컨테이너 name → 허용 자식 name 목록
     */
    public function loadNestingRules(string $templateId): array
    {
        foreach (["templates/{$templateId}/editor-spec/nesting.json", "templates/_bundled/{$templateId}/editor-spec/nesting.json"] as $candidate) {
            $file = base_path($candidate);

            if (! File::exists($file)) {
                continue;
            }

            $data = json_decode(File::get($file), true);
            $rules = [];

            foreach ($data['containers'] ?? [] as $name => $spec) {
                if (isset($spec['accepts']) && is_array($spec['accepts'])) {
                    $rules[$name] = $spec['accepts'];
                }
            }

            return $rules;
        }

        return [];
    }
}
