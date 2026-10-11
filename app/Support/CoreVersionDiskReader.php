<?php

namespace App\Support;

/**
 * 디스크 `config/app.php` 에 적힌 코어 버전 기본값(이 코드가 출시된 버전)을 읽습니다.
 *
 * 메모리의 `config('app.version')` 은 옛 부모가 물려준 env 나 오염된 config 캐시를 따라갈 수 있다. 그 값을 믿을 수
 * 없는 판정(자동 비활성화 안전망, beta.7 전용 복구 가드)은 디스크 파일을 직접 읽는다. 정규식을 이 한 곳에 둔다.
 *
 * 주의: `'version' => env('APP_VERSION', 'X'),` 줄 모양을 바꾸면 안 된다. 옛 업데이터의 `CoreUpdateCommand` 와
 * 버전 동기 검사도 같은 줄을 정규식으로 읽어 대상 버전을 판단한다.
 */
final class CoreVersionDiskReader
{
    /**
     * config/app.php 의 version 기본값을 반환합니다.
     *
     * @param  string|null  $configPath  읽을 파일 경로 (기본 `config_path('app.php')`)
     * @return string|null 버전 문자열, 읽지 못하면 null
     */
    public static function shippedVersion(?string $configPath = null): ?string
    {
        $configPath ??= config_path('app.php');
        if (! is_file($configPath)) {
            return null;
        }

        $content = @file_get_contents($configPath);
        if ($content === false) {
            return null;
        }

        // 'version' => env('APP_VERSION', '7.1.1'),
        if (preg_match("/'version'\s*=>\s*env\(\s*'APP_VERSION'\s*,\s*'([^']+)'\s*\)/", $content, $m)) {
            return $m[1];
        }

        // fallback: 'version' => '7.0.0-beta.7',
        if (preg_match("/'version'\s*=>\s*'([^']+)'/", $content, $m)) {
            return $m[1];
        }

        return null;
    }
}
