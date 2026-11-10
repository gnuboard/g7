<?php

namespace Tests\Support;

use App\Extension\Support\UpgradeStepFileLoader;

/**
 * 확장 업그레이드 파일(단계·데이터 마이그레이션·Applier)을 테스트에서 직접 싣는 도우미.
 *
 * 확장은 활성 디렉터리와 `_bundled` 에 같은 업그레이드 파일을 둔다. 같은 테스트 프로세스에서 런타임(업그레이드 실행)이 한쪽 사본으로
 * 클래스를 먼저 선언한 뒤 테스트가 다른 쪽 경로를 `require_once` 하면, 경로가 달라 중복 제거가 통하지 않아 「Cannot declare class」
 * Fatal 로 스위트 전체가 중단됐다(2026-11-03 이커머스 스위트 3,292번째 시험). 런타임과 같은 규칙(`UpgradeStepFileLoader`)으로,
 * 내용이 같은 사본이 이미 선언했으면 다시 싣지 않는다.
 */
final class UpgradeFiles
{
    /**
     * 업그레이드 파일을 한 번만 싣습니다(동일 사본이 이미 선언한 클래스는 재사용).
     *
     * @param  string  $file  업그레이드 파일 경로
     * @return void 반환값 없음
     */
    public static function requireOnce(string $file): void
    {
        $class = self::declaredClass($file);

        if ($class === null) {
            require_once $file;

            return;
        }

        UpgradeStepFileLoader::load($file, $class);
    }

    /**
     * 파일이 선언하는 첫 클래스의 FQCN 을 읽습니다.
     *
     * @param  string  $file  PHP 파일 경로
     * @return string|null FQCN(클래스 선언이 없으면 null)
     */
    private static function declaredClass(string $file): ?string
    {
        $source = (string) @file_get_contents($file);

        if (! preg_match('/^\s*(?:(?:final|abstract|readonly)\s+)*class\s+([A-Za-z_]\w*)/m', $source, $class)) {
            return null;
        }

        $namespace = preg_match('/^\s*namespace\s+([^;\s]+)\s*;/m', $source, $ns) ? $ns[1].'\\' : '';

        return $namespace.$class[1];
    }
}
