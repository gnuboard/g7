<?php

namespace App\Support\Template;

/**
 * 템플릿의 다크 모드 방식 선언(`template.json` 의 `features.color_scheme`)을 정규화합니다.
 *
 * 코어는 이 선언을 화면 설정(`G7Config.colorScheme`)으로 내려 주고, 런타임이 `<html data-g7-color-scheme>`
 * 중립 표식으로 옮긴다. 확장 CSS 는 템플릿의 다크 방식을 몰라도 이 표식만 보면 된다.
 *
 * 형식:
 *   - `{ "strategy": "ancestor-class", "selector": ".dark" }` — 루트 요소가 선택자에 맞으면 다크
 *   - `{ "strategy": "media-query" }` — 시스템 설정을 따른다
 *   - `{ "strategy": "none" }` 또는 미선언 — 항상 라이트
 */
final class ColorSchemeDeclaration
{
    public const STRATEGIES = ['ancestor-class', 'media-query', 'none'];

    /**
     * 매니페스트에서 선언을 꺼내 정규화합니다. 틀린 값은 `none` 이 됩니다.
     *
     * @param  array<string, mixed>|null  $manifest  template.json 내용
     * @return array{strategy: string, selector?: string} 정규화된 선언
     */
    public static function fromManifest(?array $manifest): array
    {
        $raw = $manifest['features']['color_scheme'] ?? null;

        if (is_array($raw) && ($raw['strategy'] ?? null) === 'ancestor-class'
            && is_string($raw['selector'] ?? null) && trim($raw['selector']) !== '') {
            return ['strategy' => 'ancestor-class', 'selector' => trim($raw['selector'])];
        }

        if (is_array($raw) && ($raw['strategy'] ?? null) === 'media-query') {
            return ['strategy' => 'media-query'];
        }

        return ['strategy' => 'none'];
    }

    /**
     * 선언의 형식 오류를 모읍니다(설치·검증용). 미선언은 유효합니다.
     *
     * @param  array<string, mixed>  $manifest  template.json 내용
     * @return array<int, string> 오류 메시지 목록
     */
    public static function validationErrors(array $manifest): array
    {
        if (! array_key_exists('color_scheme', $manifest['features'] ?? [])) {
            return [];
        }

        $raw = $manifest['features']['color_scheme'];
        $strategy = is_array($raw) ? ($raw['strategy'] ?? null) : null;

        if (! in_array($strategy, self::STRATEGIES, true)) {
            return [__('templates.errors.color_scheme_invalid_strategy')];
        }

        if ($strategy === 'ancestor-class' && (! is_string($raw['selector'] ?? null) || trim($raw['selector']) === '')) {
            return [__('templates.errors.color_scheme_selector_required')];
        }

        return [];
    }
}
