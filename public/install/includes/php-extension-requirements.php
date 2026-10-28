<?php

use App\Support\PhpExtensionRequirements;

/**
 * 번들 확장(모듈·플러그인)이 요구하는 PHP 확장 판정 — 인스톨러 확장 선택 단계 공용
 *
 * 확장은 composer.json `require.ext-*`(필수 — 설치 불가)와 `suggest.ext-*`(권장 — 경고만)로 PHP 확장 요구를
 * 선언한다. 코어 `ExtensionManager::getPhpExtensionRequirementsAt()` 와 같은 규칙이며, 설치 단계의 코어 게이트가
 * 필수 미충족 확장을 막기 전에 선택 화면에서 미리 끄고(scan-extensions), 저장 단계에서 다시 걸러(save-extensions)
 * 클라이언트 우회를 막는다. 로드 판정은 `App\Support\PhpExtensionRequirements::missing()` 를 쓴다.
 */

// 판정 클래스 적재 — config.php 를 거치지 않는 라이브러리 경로(공유 해석기 단위 테스트 등)에서도 쓰므로 직접 적재한다.
// 기준점은 BASE_PATH 가 아니라 이 파일의 위치다(테스트가 BASE_PATH 를 격리 디렉터리로 바꾼다).
if (! class_exists(PhpExtensionRequirements::class)) {
    require_once dirname(__DIR__, 3).'/app/Support/PhpExtensionRequirements.php';
}

if (! function_exists('installer_extension_php_requirements')) {
    /**
     * 확장 디렉토리의 composer.json 에서 서버에 없는 PHP 확장을 찾습니다.
     *
     * @param  string  $extensionDir  확장 디렉토리 절대경로 (composer.json 이 있는 곳)
     * @param  callable|null  $extensionLoaded  확장 로드 여부 판정 콜백 (기본: extension_loaded — 테스트는 부재를 주입)
     * @return array{missing_php_extensions: list<string>, suggested_missing: array<string, string>} 없는 필수 확장 / 없는 권장 확장 => 사유
     */
    function installer_extension_php_requirements(string $extensionDir, ?callable $extensionLoaded = null): array
    {
        $result = ['missing_php_extensions' => [], 'suggested_missing' => []];
        $composerFile = $extensionDir.DIRECTORY_SEPARATOR.'composer.json';
        $composer = is_file($composerFile) ? json_decode((string) @file_get_contents($composerFile), true) : null;

        if (! is_array($composer)) {
            return $result;
        }

        $required = [];
        foreach (array_keys(is_array($composer['require'] ?? null) ? $composer['require'] : []) as $package) {
            if (str_starts_with((string) $package, 'ext-')) {
                $required[] = strtolower(substr((string) $package, 4));
            }
        }

        $suggested = [];
        foreach (is_array($composer['suggest'] ?? null) ? $composer['suggest'] : [] as $package => $reason) {
            if (str_starts_with((string) $package, 'ext-')) {
                $suggested[strtolower(substr((string) $package, 4))] = is_string($reason) ? $reason : '';
            }
        }

        $result['missing_php_extensions'] = PhpExtensionRequirements::missing($required, $extensionLoaded);
        foreach (PhpExtensionRequirements::missing(array_keys($suggested), $extensionLoaded) as $name) {
            $result['suggested_missing'][$name] = $suggested[$name];
        }

        return $result;
    }
}

if (! function_exists('installer_filter_extensions_missing_php')) {
    /**
     * 선택된 확장 중 필수 PHP 확장이 없는 것을 빼고, 뺀 것을 돌려줍니다. (서버 측 재검증)
     *
     * @param  list<string>  $identifiers  선택된 확장 식별자
     * @param  string  $bundledDir  `modules/_bundled` 또는 `plugins/_bundled` 절대경로
     * @param  callable|null  $extensionLoaded  확장 로드 여부 판정 콜백 (기본: extension_loaded)
     * @return array{kept: list<string>, skipped: array<string, list<string>>} 남은 식별자 / 뺀 식별자 => 없는 PHP 확장
     */
    function installer_filter_extensions_missing_php(array $identifiers, string $bundledDir, ?callable $extensionLoaded = null): array
    {
        $kept = [];
        $skipped = [];

        foreach ($identifiers as $identifier) {
            $missing = installer_extension_php_requirements($bundledDir.DIRECTORY_SEPARATOR.$identifier, $extensionLoaded)['missing_php_extensions'];

            if ($missing === []) {
                $kept[] = $identifier;
            } else {
                $skipped[$identifier] = $missing;
            }
        }

        return ['kept' => $kept, 'skipped' => $skipped];
    }
}
