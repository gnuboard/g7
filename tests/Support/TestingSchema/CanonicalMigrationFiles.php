<?php

namespace Tests\Support\TestingSchema;

/**
 * 테스트 DB 스키마를 만드는 정규 마이그레이션 파일 집합
 *
 * 테스트 클래스가 무엇을 선언했든(`requiredExtensions`, 확장 베이스의 `--path`) 스키마는 항상 같은 집합으로 만든다 —
 * 코어 + 모든 번들 확장(`modules|plugins|templates/_bundled/*`). 실행 순서에 따라 어떤 클래스가 스키마를 처음
 * 만드느냐로 테이블 구성이 달라지던 문제(2026-06-12 순서 무관 번들 테이블 보장)를 구조로 막는다.
 * 활성 디렉터리(`plugins/sirsoft-gdpr` 등)는 넣지 않는다 — 활성 사본은 번들의 설치 결과라 같은 파일이다.
 */
final class CanonicalMigrationFiles
{
    /**
     * 정규 마이그레이션 디렉터리 목록을 돌려줍니다(존재하는 것만, 코어가 먼저).
     *
     * @param  string|null  $basePath  프로젝트 루트(생략하면 이 파일 위치로 계산)
     * @return array<int, string> 절대 경로 목록
     */
    public static function dirs(?string $basePath = null): array
    {
        $base = self::basePath($basePath);
        $dirs = [$base.'/database/migrations'];

        foreach (['modules', 'plugins', 'templates'] as $kind) {
            $found = glob($base.'/'.$kind.'/_bundled/*/database/migrations', GLOB_ONLYDIR) ?: [];
            sort($found, SORT_STRING);
            array_push($dirs, ...$found);
        }

        return array_values(array_filter(
            array_map(static fn (string $dir): string => str_replace('\\', '/', $dir), $dirs),
            'is_dir'
        ));
    }

    /**
     * 정규 마이그레이션 파일을 이름 → 경로로 돌려줍니다.
     *
     * Laravel `Migrator::getMigrationFiles()` 와 같은 규칙이다 — 디렉터리마다 `*_*.php` 를 모으고, 같은 이름이면
     * 뒤 경로가 이기며, 이름순으로 정렬한다. 실제 `migrate:fresh` 가 실행하는 집합과 어긋나지 않게 하려고 규칙을
     * 바꾸지 않는다.
     *
     * @param  string|null  $basePath  프로젝트 루트(생략하면 이 파일 위치로 계산)
     * @return array<string, string> 마이그레이션 이름 → 절대 경로
     */
    public static function resolve(?string $basePath = null): array
    {
        $files = [];

        foreach (self::dirs($basePath) as $dir) {
            foreach (glob($dir.'/*_*.php') ?: [] as $file) {
                $files[basename($file, '.php')] = str_replace('\\', '/', $file);
            }
        }

        ksort($files, SORT_STRING);

        return $files;
    }

    /**
     * 프로젝트 루트를 정합니다.
     *
     * @param  string|null  $basePath  지정한 루트
     * @return string 슬래시로 정규화한 루트
     */
    private static function basePath(?string $basePath): string
    {
        return rtrim(str_replace('\\', '/', $basePath ?? dirname(__DIR__, 3)), '/');
    }
}
