<?php

namespace Tests\Support\TestingSchema;

use Composer\Autoload\ClassLoader;
use Illuminate\Database\Connection;

/**
 * 테스트 스키마의 출처 지문
 *
 * 마이그레이션 지문은 "같은 마이그레이션을 다시 돌리면 같은 스키마가 나온다" 를 보증하는 입력 전부를 묶는다 —
 * 파일 이름·내용, 마이그레이션이 `use` 로 가져오는 클래스 파일, 의존성 잠금 파일(`composer.lock`), 재사용 로직
 * 판(`TestingSchemaCache::LOGIC_VERSION`). 하나라도 바뀌면 지문이 달라져 전체 재생으로 간다.
 * 환경 키는 같은 마이그레이션이라도 결과가 달라질 수 있는 DB 서버·연결 설정을 묶는다.
 */
final class Fingerprint
{
    /**
     * 마이그레이션 지문을 계산합니다.
     *
     * @param  array<string, string>  $files  마이그레이션 이름 → 절대 경로(CanonicalMigrationFiles::resolve() 결과)
     * @param  callable(string): (string|null)|null  $classFile  클래스 이름 → 파일 경로(생략하면 오토로더로 찾음)
     * @param  string|null  $composerLock  `composer.lock` 경로(생략하면 프로젝트 루트의 것)
     * @param  int|null  $logicVersion  판정 로직 판(생략하면 TestingSchemaCache::LOGIC_VERSION)
     * @return string sha1 지문
     */
    public static function of(array $files, ?callable $classFile = null, ?string $composerLock = null, ?int $logicVersion = null): string
    {
        ksort($files, SORT_STRING);
        $classFile ??= self::classFileFromAutoloaders(...);
        $composerLock ??= dirname(__DIR__, 3).'/composer.lock';

        $entries = [];
        $classes = [];

        foreach ($files as $name => $path) {
            $contents = (string) @file_get_contents($path);
            $entries[] = [$name, sha1($contents)];

            foreach (self::importedClasses($contents) as $class) {
                $classes[$class] = true;
            }
        }

        ksort($classes, SORT_STRING);

        $classEntries = [];
        foreach (array_keys($classes) as $class) {
            $file = $classFile($class);
            $classEntries[] = [$class, $file !== null && is_file($file) ? sha1_file($file) : 'missing'];
        }

        return sha1(json_encode([
            'migrations' => $entries,
            'classes' => $classEntries,
            'composerLock' => is_file($composerLock) ? sha1_file($composerLock) : 'missing',
            'logic' => $logicVersion ?? TestingSchemaCache::LOGIC_VERSION,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * 마이그레이션 파일이 `use` 로 가져오는 비-Illuminate 클래스 목록을 돌려줍니다(1단계만).
     *
     * @param  string  $contents  마이그레이션 파일 내용
     * @return array<int, string> 클래스 전체 이름 목록
     */
    public static function importedClasses(string $contents): array
    {
        preg_match_all('/^use\s+([A-Za-z_][A-Za-z0-9_\\\\]*)(?:\s+as\s+\w+)?\s*;/m', $contents, $matches);

        return array_values(array_filter(
            array_unique($matches[1]),
            static fn (string $class): bool => ! str_starts_with(ltrim($class, '\\'), 'Illuminate\\')
        ));
    }

    /**
     * 환경 키를 계산합니다 — DB 서버 판·문자셋과 연결 설정, 기본 로케일.
     *
     * @param  Connection  $connection  테스트 DB 연결(write)
     * @param  string|null  $fallbackLocale  `app.fallback_locale` 값
     * @return string sha1 환경 키
     */
    public static function envKey(Connection $connection, ?string $fallbackLocale): string
    {
        $server = (array) $connection->selectOne(
            'SELECT VERSION() AS v, @@character_set_database AS cs, @@collation_database AS co',
            [],
            false
        );

        return sha1(json_encode([
            'server' => [$server['v'] ?? null, $server['cs'] ?? null, $server['co'] ?? null],
            'charset' => $connection->getConfig('charset'),
            'collation' => $connection->getConfig('collation'),
            'prefix' => $connection->getConfig('prefix'),
            'engine' => $connection->getConfig('engine'),
            'strict' => $connection->getConfig('strict'),
            'fallbackLocale' => $fallbackLocale,
        ]));
    }

    /**
     * 등록된 Composer 오토로더에서 클래스 파일을 찾습니다.
     *
     * 확장 소스는 테스트 부트스트랩이 따로 만든 ClassLoader 로 싣는데, 그 로더는 Composer 의 정적 등록 목록에
     * 들어가지 않는다. 그래서 spl 오토로드 체인의 ClassLoader 까지 훑고, 그래도 없으면 클래스를 실어 위치를 묻는다.
     *
     * @param  string  $class  클래스 전체 이름
     * @return string|null 파일 경로(찾지 못하면 null)
     */
    private static function classFileFromAutoloaders(string $class): ?string
    {
        $loaders = array_values(ClassLoader::getRegisteredLoaders());
        foreach (spl_autoload_functions() ?: [] as $function) {
            if (is_array($function) && ($function[0] ?? null) instanceof ClassLoader) {
                $loaders[] = $function[0];
            }
        }

        foreach ($loaders as $loader) {
            $file = $loader->findFile($class);
            if (is_string($file)) {
                return $file;
            }
        }

        if (class_exists($class) || interface_exists($class) || enum_exists($class) || trait_exists($class)) {
            $file = (new \ReflectionClass($class))->getFileName();

            return $file === false ? null : $file;
        }

        return null;
    }
}
