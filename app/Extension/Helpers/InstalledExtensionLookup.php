<?php

namespace App\Extension\Helpers;

use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\TemplateManager;

/**
 * 확장이 「설치됨」 인지 판정하는 단일 기준
 *
 * 설치됨 = 확장 테이블(modules·plugins·templates)에 active 또는 inactive 행이 있는 것이다.
 * 설치 소스(번들·마켓·업로드·GitHub)는 따지지 않는다. 관리자 목록이 설치/미설치 행을 가르는
 * 기준(`CachesModuleStatus::getInstalledModuleIdentifiers()` 등)과 같은 캐시 목록을 쓰므로,
 * 목록 화면과 아이콘·라이선스·내장 번역 화면이 같은 확장을 서로 다르게 판정하지 않는다.
 *
 * 설치된 확장은 설치본(`modules/{id}` 등)만 읽는다 — 동봉 원본(`_bundled`)이나 `_pending` 은
 * 아직 설치되지 않은 확장을 보여 줄 때만 쓴다.
 */
final class InstalledExtensionLookup
{
    /**
     * 유형별 설치된 확장 식별자 목록을 반환합니다.
     *
     * @param  string  $type  확장 유형 (module|plugin|template, 복수형 modules|plugins|templates 도 허용)
     * @return array<int, string> 설치된(active·inactive) 확장 식별자 목록, 알 수 없는 유형이면 빈 배열
     */
    public static function identifiers(string $type): array
    {
        return match (self::normalizeType($type)) {
            'module' => ModuleManager::getInstalledModuleIdentifiers(),
            'plugin' => PluginManager::getInstalledPluginIdentifiers(),
            'template' => TemplateManager::getInstalledTemplateIdentifiers(),
            default => [],
        };
    }

    /**
     * 확장 하나가 설치되어 있는지 확인합니다.
     *
     * @param  string  $type  확장 유형 (module|plugin|template, 복수형도 허용)
     * @param  string  $identifier  확장 식별자
     * @return bool 설치된(active·inactive) 확장이면 true
     */
    public static function isInstalled(string $type, string $identifier): bool
    {
        return in_array($identifier, self::identifiers($type), true);
    }

    /**
     * 유형 이름을 단수형으로 맞춥니다.
     *
     * @param  string  $type  확장 유형 (단수형 또는 복수형)
     * @return string module|plugin|template, 알 수 없으면 입력값 그대로
     */
    private static function normalizeType(string $type): string
    {
        return match ($type) {
            'modules' => 'module',
            'plugins' => 'plugin',
            'templates' => 'template',
            default => $type,
        };
    }
}
