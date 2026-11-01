<?php

namespace App\Console\Commands\Traits;

/**
 * 활성화 경고의 미충족 의존 항목 상태 라벨 — 미설치·비활성·버전 미달 (template|module|plugin:activate 공통)
 *
 * 종전 분기는 `not_installed` 가 아니면 모두 「비활성」으로 출력해, 켜져 있지만 요구 버전에 못 미치는 의존도
 * 「비활성」으로 보였다(#152).
 */
trait LabelsMissingDependencies
{
    /**
     * 미충족 의존 항목 하나의 상태 라벨을 만듭니다.
     *
     * @param  string  $langFile  번역 파일 (templates|modules|plugins)
     * @param  array<string, mixed>  $item  {status, installed_version?, required_version?} 미충족 항목
     * @return string 라벨 (예: 「버전 미달 0.1.0 → >=9.9.9」)
     */
    protected function missingDependencyStatusLabel(string $langFile, array $item): string
    {
        return match ($item['status'] ?? null) {
            'not_installed' => __($langFile.'.commands.activate.missing_status.not_installed'),
            'version_mismatch' => __($langFile.'.commands.activate.missing_status.version_mismatch', [
                'installed' => (string) ($item['installed_version'] ?? ''),
                'required' => (string) ($item['required_version'] ?? ''),
            ]),
            default => __($langFile.'.commands.activate.missing_status.inactive'),
        };
    }
}
