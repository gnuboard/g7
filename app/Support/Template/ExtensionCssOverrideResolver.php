<?php

namespace App\Support\Template;

use Composer\Semver\Semver;
use Illuminate\Support\Facades\Log;

/**
 * 템플릿의 확장 CSS 덮기 파일 선언을 판정합니다.
 *
 * 확장 화면 스타일은 확장이 자기 CSS 로 공급한다. 템플릿은 그 모양을 바꾸고 싶을 때 `template.json`
 * 에 덮기 파일을 선언한다:
 *
 *     "extension_css_overrides": [
 *         { "target": "gnuboard7-hello_module", "targetVersion": "^0.3", "path": "dist/css/overrides/gnuboard7-hello_module.css" }
 *     ]
 *
 * 판정 규칙은 컴포넌트 대체(`ComponentOverrideResolver`)와 같다:
 *   - 대상 활성 + 버전 호환 → 포함
 *   - 대상 활성 + 버전 비호환 → 제외 + `Log::info` (확장 원본 모양으로 폴백)
 *   - 대상 비활성·미설치 → 조용히 제외 (나중에 설치될 수 있다)
 *   - 같은 target 중복 선언 → 검증 오류(설치·검증 단계에서 거부), 런타임은 첫 선언만 쓴다
 *
 * 이 클래스는 순수 판정만 한다. 활성 확장 버전 목록은 호출자가 넘긴다.
 */
final class ExtensionCssOverrideResolver
{
    /** 덮기 파일은 템플릿 빌드 산출 디렉터리 아래에 있어야 한다(자산 라우트가 dist/ 이하만 서빙) */
    private const PATH_PREFIX = 'dist/';

    /**
     * 선언의 형식 오류를 모읍니다. 비어 있으면 유효합니다.
     *
     * @param  array<string, mixed>  $manifest  template.json 내용
     * @return array<int, string> 오류 메시지 목록
     */
    public function validationErrors(array $manifest): array
    {
        $raw = $manifest['extension_css_overrides'] ?? null;
        if ($raw === null) {
            return [];
        }

        if (! is_array($raw) || ! array_is_list($raw)) {
            return [__('templates.errors.extension_css_overrides_not_list')];
        }

        $errors = [];
        $seen = [];
        foreach ($raw as $index => $entry) {
            $target = is_array($entry) ? ($entry['target'] ?? null) : null;
            $path = is_array($entry) ? ($entry['path'] ?? null) : null;
            $constraint = is_array($entry) ? ($entry['targetVersion'] ?? null) : null;

            if (! is_string($target) || $target === '' || ! is_string($path) || ! str_starts_with($path, self::PATH_PREFIX) || str_contains($path, '..')) {
                $errors[] = __('templates.errors.extension_css_override_invalid', ['index' => $index]);

                continue;
            }

            if ($constraint !== null && ! $this->isParsableConstraint($constraint)) {
                $errors[] = __('templates.errors.extension_css_override_invalid_version', ['target' => $target]);
            }

            if (isset($seen[$target])) {
                $errors[] = __('templates.errors.extension_css_override_duplicate', ['target' => $target]);
            }
            $seen[$target] = true;
        }

        return $errors;
    }

    /**
     * 화면에 실을 덮기 파일을 고릅니다.
     *
     * @param  string  $templateIdentifier  템플릿 식별자(로그용)
     * @param  array<string, mixed>  $manifest  template.json 내용
     * @param  string  $templateRoot  템플릿 설치 디렉터리(산출물 실재 확인용)
     * @param  array<string, string>  $activeVersions  활성 확장 식별자 → 버전
     * @return array<int, array{target: string, path: string}> 선언 순서의 포함 항목(`path` 는 `dist/` 를 뗀 서빙 경로)
     */
    public function resolve(string $templateIdentifier, array $manifest, string $templateRoot, array $activeVersions): array
    {
        $raw = $manifest['extension_css_overrides'] ?? [];
        if (! is_array($raw)) {
            return [];
        }

        $selected = [];
        $seen = [];
        foreach ($raw as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $target = $entry['target'] ?? null;
            $path = $entry['path'] ?? null;
            if (! is_string($target) || ! is_string($path) || ! str_starts_with($path, self::PATH_PREFIX) || str_contains($path, '..')) {
                continue;
            }

            // 중복은 검증이 막는다. 런타임은 첫 선언만 쓴다.
            if (isset($seen[$target])) {
                continue;
            }
            $seen[$target] = true;

            // 대상 비활성·미설치 — 사문 선언
            if (! isset($activeVersions[$target])) {
                continue;
            }

            if (! $this->isCompatible($templateIdentifier, $target, $activeVersions[$target], $entry['targetVersion'] ?? null)) {
                continue;
            }

            // 선언만 있고 산출물이 없으면 요청 실패만 남는다
            if (! is_file(rtrim($templateRoot, '/\\').'/'.$path)) {
                Log::info('확장 CSS 덮기 파일이 빌드 산출물에 없습니다 — 확장 원본을 사용합니다', [
                    'template' => $templateIdentifier,
                    'target' => $target,
                    'path' => $path,
                ]);

                continue;
            }

            $selected[] = ['target' => $target, 'path' => substr($path, strlen(self::PATH_PREFIX))];
        }

        return $selected;
    }

    /**
     * 버전 제약 호환 판정. 제약이 없으면 호환이다.
     *
     * @param  string  $template  템플릿 식별자
     * @param  string  $target  대상 확장
     * @param  string  $version  대상 확장 실제 버전
     * @param  mixed  $constraint  제약
     * @return bool 호환 여부
     */
    private function isCompatible(string $template, string $target, string $version, mixed $constraint): bool
    {
        if ($constraint === null) {
            return true;
        }

        try {
            $compatible = is_string($constraint) && Semver::satisfies($version, $constraint);
        } catch (\Exception) {
            // 검증이 막는 경우다. 런타임은 덮지 않는 쪽(원본 모양)으로 안전하게 둔다.
            $compatible = false;
        }

        if (! $compatible) {
            Log::info('확장 CSS 덮기 파일의 버전 제약이 대상과 맞지 않습니다 — 확장 원본을 사용합니다', [
                'template' => $template,
                'target' => $target,
                'target_version' => $version,
                'constraint' => $constraint,
            ]);
        }

        return $compatible;
    }

    /**
     * 제약 문자열을 파싱할 수 있는지.
     *
     * @param  mixed  $constraint  제약
     * @return bool 파싱 가능 여부
     */
    private function isParsableConstraint(mixed $constraint): bool
    {
        if (! is_string($constraint) || $constraint === '') {
            return false;
        }

        try {
            Semver::satisfies('0.0.0', $constraint);

            return true;
        } catch (\Exception) {
            return false;
        }
    }
}
