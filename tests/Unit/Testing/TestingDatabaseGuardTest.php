<?php

namespace Tests\Unit\Testing;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\TestingDatabaseGuard;

/**
 * 테스트 앱이 테스트 전용 DB 가 아닌 DB 에 붙으면 스키마를 지우기 전에 멈추는지 검증한다.
 *
 * 2026-10-07 실측(O-D2): 개발 DB `g7_2` 권한 트리에 코어 테스트가 만드는 권한 행(`admin.access`, 이름이 식별자 그대로)이
 * 남아 있었다. 같은 클론에서 확장을 반영하면 config 캐시가 다시 구워지고, 그 뒤 부팅한 테스트 앱이 `.env.testing` 대신
 * 개발 DB 설정으로 붙는다. 다른 클론에서는 같은 경로로 개발 DB 전체가 RefreshDatabase 에 초기화됐다(2026-10-08).
 * 판정은 앱 없이 연결 설정 배열만으로 하므로 이 테스트는 DB 를 열지 않는다.
 *
 * @scenario-manifest tests/scenarios/testing-database-guard.yaml
 */
class TestingDatabaseGuardTest extends TestCase
{
    /**
     * 테스트 전용 이름(`testing` 포함)의 MySQL 쓰기·읽기 DB 는 통과한다.
     *
     * @scenario database=testing_name
     *
     * @effects testing_database_guard_allows_testing_db
     *
     * @return void 반환값 없음
     */
    public function test_allows_testing_database_names(): void
    {
        TestingDatabaseGuard::assertConnectionIsTesting('mysql', [
            'driver' => 'mysql',
            'write' => ['database' => 'g7_2_testing'],
            'read' => ['database' => 'g7_2_testing'],
        ]);

        // 병렬 실행은 이름 끝에 토큰을 붙인다(g7_testing_test_1)
        TestingDatabaseGuard::assertConnectionIsTesting('mysql', ['driver' => 'mysql', 'database' => 'g7_testing_test_1']);
        TestingDatabaseGuard::assertConnectionIsTesting('sqlite', ['driver' => 'sqlite', 'database' => ':memory:']);

        $this->addToAssertionCount(3);
    }

    /**
     * 개발 DB 이름(쓰기 쪽)이면 멈춘다 — config 캐시로 개발 설정이 실린 경우.
     *
     * @scenario database=development_write
     *
     * @effects testing_database_guard_blocks_development_db
     *
     * @return void 반환값 없음
     */
    public function test_blocks_development_write_database(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('g7_2');

        TestingDatabaseGuard::assertConnectionIsTesting('mysql', [
            'driver' => 'mysql',
            'write' => ['database' => 'g7_2'],
            'read' => ['database' => 'g7_2_testing'],
        ]);
    }

    /**
     * 읽기 쪽만 개발 DB 여도 멈춘다(읽기 연결로 쓰는 정리 쿼리가 있다).
     *
     * @scenario database=development_read
     *
     * @effects testing_database_guard_blocks_development_db
     *
     * @return void 반환값 없음
     */
    public function test_blocks_development_read_database(): void
    {
        $this->expectException(RuntimeException::class);

        TestingDatabaseGuard::assertConnectionIsTesting('mysql', [
            'driver' => 'mysql',
            'write' => ['database' => 'g7_2_testing'],
            'read' => ['database' => 'g7_2'],
        ]);
    }

    /**
     * DB 이름을 하나도 찾지 못하면(설정 누락) 통과시키지 않는다.
     *
     * @scenario database=missing
     *
     * @effects testing_database_guard_blocks_unknown_db
     *
     * @return void 반환값 없음
     */
    public function test_blocks_when_database_name_missing(): void
    {
        $this->expectException(RuntimeException::class);

        TestingDatabaseGuard::assertConnectionIsTesting('mysql', ['driver' => 'mysql']);
    }

    /**
     * SQLite 파일 DB 는 경로에 testing 이 들어가야 통과한다.
     *
     * @scenario database=sqlite_file
     *
     * @effects testing_database_guard_blocks_development_db
     *
     * @return void 반환값 없음
     */
    public function test_blocks_sqlite_file_outside_testing(): void
    {
        TestingDatabaseGuard::assertConnectionIsTesting('sqlite', ['driver' => 'sqlite', 'database' => '/tmp/g7_testing.sqlite']);
        $this->addToAssertionCount(1);

        $this->expectException(RuntimeException::class);
        TestingDatabaseGuard::assertConnectionIsTesting('sqlite', ['driver' => 'sqlite', 'database' => '/var/data/database.sqlite']);
    }
}
