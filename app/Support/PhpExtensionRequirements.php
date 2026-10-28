<?php

namespace App\Support;

/**
 * 코어 PHP 확장 요구 목록 판정 (인스톨러·코어·코어 업데이트 공용 SSoT).
 *
 * 목록 데이터는 옆의 `app/Support/Requirements/php-extensions.json` 한 곳에 있다. JSON 으로 두는 이유:
 *  - 인스톨러(`public/install/`)는 Laravel 오토로드 없이 동작하는 순수 PHP 이고,
 *  - audit 규칙(Node)도 같은 목록·심볼 표를 읽어야 하며,
 *  - `core:update` 는 **새 패키지의 같은 파일**을 읽어야 하는데, 새 패키지의 PHP 클래스를
 *    `require` 하면 이미 로드된 같은 이름의 클래스와 충돌한다.
 *
 * 그래서 이 클래스는 파사드·헬퍼 등 프레임워크 의존성을 일절 참조하지 않는다.
 * 인스톨러는 `require_once` 로 이 파일을 직접 로드해 사용한다(`OpcacheStatus` 와 같은 모양).
 */
final class PhpExtensionRequirements
{
    /**
     * SSoT JSON 을 읽습니다.
     *
     * @param  string|null  $jsonPath  JSON 경로 (기본: 이 파일 옆 `app/Support/Requirements/php-extensions.json`, `core:update` 는 새 패키지 경로)
     * @return array{required: list<string>, optional: list<string>, symbols: array<string, list<string>>} 필수·선택 목록과 확장별 대표 심볼
     *
     * @throws \RuntimeException 파일이 없거나 형식이 잘못된 경우
     */
    public static function load(?string $jsonPath = null): array
    {
        $jsonPath ??= self::defaultPath();

        $raw = is_file($jsonPath) ? file_get_contents($jsonPath) : false;
        $data = $raw === false ? null : json_decode($raw, true);

        if (! is_array($data) || ! is_array($data['required'] ?? null) || ! is_array($data['optional'] ?? null)) {
            throw new \RuntimeException('Invalid PHP extension requirements file: '.$jsonPath);
        }

        return [
            'required' => array_values(array_map('strval', $data['required'])),
            'optional' => array_values(array_map('strval', $data['optional'])),
            'symbols' => is_array($data['symbols'] ?? null) ? $data['symbols'] : [],
        ];
    }

    /**
     * 기본 SSoT JSON 경로를 반환합니다.
     *
     * @return string 이 파일 옆 `app/Support/Requirements/php-extensions.json` 경로
     */
    public static function defaultPath(): string
    {
        return __DIR__.DIRECTORY_SEPARATOR.'Requirements'.DIRECTORY_SEPARATOR.'php-extensions.json';
    }

    /**
     * 주어진 확장 중 로드되지 않은 것을 반환합니다.
     *
     * @param  list<string>  $extensions  확장 이름 목록
     * @param  callable|null  $extensionLoaded  확장 로드 여부 판정 콜백 (기본: extension_loaded — 테스트는 부재를 주입)
     * @return list<string> 로드되지 않은 확장 이름 (입력 순서 유지)
     */
    public static function missing(array $extensions, ?callable $extensionLoaded = null): array
    {
        $extensionLoaded ??= static fn (string $name): bool => extension_loaded($name);

        return array_values(array_filter(
            $extensions,
            static fn (string $name): bool => ! $extensionLoaded($name),
        ));
    }

    /**
     * 코어 필수 확장 중 로드되지 않은 것을 반환합니다.
     *
     * @param  callable|null  $extensionLoaded  확장 로드 여부 판정 콜백 (기본: extension_loaded)
     * @param  string|null  $jsonPath  JSON 경로 (기본: 현재 코어의 SSoT)
     * @return list<string> 로드되지 않은 필수 확장 이름
     */
    public static function requiredMissing(?callable $extensionLoaded = null, ?string $jsonPath = null): array
    {
        return self::missing(self::load($jsonPath)['required'], $extensionLoaded);
    }
}
