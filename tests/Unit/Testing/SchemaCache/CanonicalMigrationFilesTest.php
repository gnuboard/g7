<?php

namespace Tests\Unit\Testing\SchemaCache;

use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase as EcommerceModuleTestCase;
use Tests\Support\TestingSchema\CanonicalMigrationFiles;
use Tests\TestCase;

/**
 * 정규 마이그레이션 집합 — 테스트 스키마는 늘 코어 + 모든 번들 확장 마이그레이션으로 만든다.
 *
 * Laravel 마이그레이터가 실제로 실행할 파일 집합과 같아야 하고(같은 이름이면 뒤 경로가 이김), 이커머스 베이스가 선언한
 * `--path` 집합과도 어긋나면 안 된다(드리프트 가드). 활성 디렉터리 사본은 넣지 않는다. 이 클래스는 DB 를 쓰지 않는다.
 */
class CanonicalMigrationFilesTest extends TestCase
{
    /**
     * 정규 집합은 마이그레이터 getMigrationFiles() 결과와 이름·경로 모두 같다.
     *
     * @scenario case=migrator_parity
     *
     * @effects canonical_set_matches_migrator
     *
     * @return void 반환값 없음
     */
    public function test_matches_migrator_file_resolution(): void
    {
        $dirs = CanonicalMigrationFiles::dirs(base_path());
        $expected = array_map(
            static fn (string $path): string => str_replace('\\', '/', $path),
            app('migrator')->getMigrationFiles($dirs)
        );

        $this->assertSame($expected, CanonicalMigrationFiles::resolve(base_path()));
        $this->assertGreaterThan(250, count($expected), '코어 + 번들 마이그레이션 모집단');
    }

    /**
     * 이커머스 베이스의 migrateFreshUsing() --path 파일 집합과 같고, 활성 디렉터리 사본은 없다.
     *
     * @scenario case=extension_base_parity
     *
     * @effects canonical_set_matches_extension_base, active_copies_excluded
     *
     * @return void 반환값 없음
     */
    public function test_matches_ecommerce_base_paths_and_excludes_active_copies(): void
    {
        $probe = new class('probe') extends EcommerceModuleTestCase {};
        $paths = (fn () => $this->migrateFreshUsing()['--path'])->call($probe);
        $dirs = array_map(static fn (string $p): string => base_path($p), $paths);
        $fromBase = array_keys(app('migrator')->getMigrationFiles($dirs));
        sort($fromBase);

        $canonical = array_keys(CanonicalMigrationFiles::resolve(base_path()));
        $this->assertSame($fromBase, $canonical);

        foreach (CanonicalMigrationFiles::dirs(base_path()) as $dir) {
            $relative = substr($dir, strlen(str_replace('\\', '/', base_path())) + 1);
            $this->assertMatchesRegularExpression('#^(database/migrations|(modules|plugins|templates)/_bundled/[^/]+/database/migrations)$#', $relative);
        }
    }
}
