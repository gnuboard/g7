<?php

namespace Tests\Support\TestingSchema;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\TestingDatabaseGuard;
use Tests\TestCase;
use Throwable;

/**
 * 테스트 DB 스키마 재사용
 *
 * 종전에는 PHP 프로세스마다 테스트 DB 를 마이그레이션 300여 개로 처음부터 다시 만들었다(`migrate:fresh`, 약 50초 —
 * 대부분이 ALTER TABLE). 마이그레이션이 바뀌지 않았으면 결과 스키마도 같으므로, 지난번에 만든 스키마를 그대로
 * 쓰고 남은 행만 정리한다. 재사용 조건은 {@see Decision} 에 있다 — 마이그레이션 지문, 환경 키, `migrations`
 * 기록 집합, DB 실물 구조 지문이 모두 기록과 같아야 한다. 하나라도 다르면 지금처럼 전체 재생한다.
 *
 * 지키는 것(2026-11-02 정리):
 *  - 실제 MySQL·실제 마이그레이션 결과만 재사용한다(SQLite·스키마 덤프를 쓰지 않는다).
 *  - 전체 재생은 정규 마이그레이션 집합(코어 + 모든 번들 확장)으로만 한다 — 실행 순서와 무관한 같은 스키마.
 *  - 재생 시작 전에 기록을 지운다 — 중간에 끊긴 반쪽 스키마를 다음 실행이 재사용하지 않는다.
 *  - 같은 테스트 DB 를 두 프로세스가 동시에 쓰지 못하게 잠근다({@see ProcessLock}).
 *  - 파괴적 SQL 전에 접속 DB 가 테스트 전용인지 다시 확인한다(read·write 양쪽).
 *
 * 강제 재생: 환경 변수 `G7_TEST_FRESH_SCHEMA=1` 이면 프로세스마다 처음 한 번 재생하고, 그 밖의 값(실행 ID)이면
 * 그 ID 로 아직 재생하지 않았을 때만 한 번 재생한다. 진행 기록은 `storage/logs/testing-schema-cache.log` 에 남긴다
 * (PHPUnit 은 테스트 중 표준 출력을 오류로 본다).
 */
final class TestingSchemaCache
{
    /** 재사용 로직 판 — 판정·기록 규칙을 바꾸면 올린다(올리면 모든 기록이 무효가 되어 한 번 재생한다) */
    public const LOGIC_VERSION = 2;

    /** 강제 재생 환경 변수 */
    public const FORCE_ENV = 'G7_TEST_FRESH_SCHEMA';

    /** 이 프로세스에서 스키마를 판정해 쓸 수 있는 상태로 맞췄는지 */
    private static bool $verified = false;

    /** 판정 뒤 스키마나 데이터가 바뀌었을 수 있는지(트랜잭션 안 DDL 의 암묵 커밋 등) */
    private static bool $dirty = false;

    /** 현재 데이터 프로필 — 'none'(잔여 행 정리만) 또는 'seed:<시더>' */
    private static ?string $profile = null;

    /** 전체 재생에 실패한 예외 — 같은 프로세스에서는 다시 시도하지 않고 바로 실패시킨다 */
    private static ?Throwable $fatal = null;

    /** `G7_TEST_FRESH_SCHEMA=1` 로 이미 재생했는지 */
    private static bool $forcedOnce = false;

    /** 판정한 테스트 DB 이름 */
    private static ?string $database = null;

    /** 마지막으로 맞춘 상태 토큰 */
    private static string $stateToken = '';

    /** 마지막으로 SKIP 을 기록한 테스트 클래스(로그를 클래스당 한 줄로 줄임) */
    private static ?string $lastSkippedClass = null;

    /**
     * 테스트가 요구하는 스키마·데이터 상태를 맞춥니다.
     *
     * 요구 프로필:
     *  - `any`        현재 데이터 프로필을 그대로 받아들인다(코어, 시드 없는 확장)
     *  - `none-exact` 시드 없이 잔여 행만 정리한 상태여야 한다(게시판·페이지 등 첫 사용)
     *  - `seed:<FQCN>` 그 시더를 돌린 상태여야 한다(이커머스·결제 플러그인 등)
     *
     * @param  TestCase  $test  현재 테스트
     * @param  string  $requirement  요구 프로필
     * @return void 반환값 없음
     *
     * @throws RuntimeException 테스트 DB 확인·잠금·재생에 실패했을 때
     */
    public static function ensure(TestCase $test, string $requirement): void
    {
        if (self::$fatal !== null) {
            throw new RuntimeException('이 프로세스에서 테스트 스키마 구성에 실패했다 — 첫 실패 원인을 본다.', 0, self::$fatal);
        }

        $diskState = self::$database !== null ? RecordStore::readState(self::$database) : null;
        if (! self::needsDecision($requirement, RefreshDatabaseState::$migrated, $diskState)) {
            RefreshDatabaseState::$migrated = true;
            self::logSkip($test);

            return;
        }

        $started = hrtime(true);
        $connection = DB::connection();
        $database = (string) ((array) $connection->selectOne('SELECT DATABASE() AS d', [], false))['d'];
        TestingDatabaseGuard::assertTestingDatabaseName($database, $connection);

        if (ProcessLock::acquireOrSkipIfChild($database)) {
            RecordStore::cleanupTemporaryFiles($database);
            if ($test->killStaleTestingConnectionsOnce()) {
                // 정리는 자기 read 연결까지 끊을 수 있다(자기 것으로 빼는 건 조회에 쓴 연결 하나뿐) — 새 연결로 바꾼다
                DB::reconnect();
                $connection = DB::connection();
            }
        }

        $connection->statement('SET SESSION lock_wait_timeout=30');

        $files = CanonicalMigrationFiles::resolve(base_path());
        $fingerprint = Fingerprint::of($files);
        $envKey = Fingerprint::envKey($connection, config('app.fallback_locale'));
        $record = RecordStore::read($database);
        $force = self::forceValue();

        $decision = Decision::decide(
            $record,
            $fingerprint,
            $envKey,
            $force,
            self::$forcedOnce,
            static fn (): array => self::migrationNames($connection),
            static fn (): string => StructureChecksum::compute($connection, $database),
            array_keys($files),
        );

        try {
            if ($decision->action === Decision::FRESH) {
                RecordStore::delete($database);
                self::call('migrate:fresh', [
                    '--path' => CanonicalMigrationFiles::dirs(base_path()),
                    '--realpath' => true,
                    '--force' => true,
                ]);
                if ($force === '1') {
                    self::$forcedOnce = true;
                }

                $connection = DB::connection();
                $record = RecordStore::compose(
                    $fingerprint,
                    $envKey,
                    StructureChecksum::compute($connection, $database),
                    self::migrationNames($connection),
                    Baseline::snapshot($connection, $database),
                    $force !== null && $force !== '' && $force !== '1' ? $force : null,
                );
                RecordStore::write($database, $record);
            } else {
                DataReset::run($connection, $database, (array) $record['baseline']);
            }

            $profile = 'none';
            if (str_starts_with($requirement, 'seed:')) {
                self::call('db:seed', ['--class' => substr($requirement, 5), '--force' => true]);
                $profile = $requirement;
            }

            app(Kernel::class)->setArtisan(null);

            self::$verified = true;
            self::$dirty = false;
            self::$profile = $profile;
            self::$database = $database;
            self::$stateToken = RecordStore::touchState($database);
            self::$lastSkippedClass = $test::class;
            RefreshDatabaseState::$migrated = true;
        } catch (Throwable $e) {
            if ($decision->action === Decision::FRESH) {
                self::$fatal = $e;
            }
            self::log($database, $decision->action, $decision->reason.' failed: '.$e->getMessage(), $started, $test);

            throw $e;
        }

        self::log($database, $decision->action, $decision->reason.' profile='.$profile, $started, $test);
    }

    /**
     * 이번 테스트 전에 판정이 필요한지 계산합니다.
     *
     * @param  string  $requirement  요구 프로필
     * @param  bool  $refreshMigrated  `RefreshDatabaseState::$migrated` — 트랜잭션 밖으로 커밋이 새면 프레임워크가 false 로 내린다
     * @param  string|null  $diskState  디스크의 상태 토큰(아직 판정한 DB 가 없으면 null)
     * @return bool 판정이 필요하면 true
     */
    public static function needsDecision(string $requirement, bool $refreshMigrated, ?string $diskState): bool
    {
        if (! self::$verified || self::$dirty || ! $refreshMigrated) {
            return true;
        }

        if ($diskState !== null && $diskState !== self::$stateToken) {
            return true;
        }

        if ($requirement === 'none-exact') {
            return self::$profile !== 'none';
        }

        if (str_starts_with($requirement, 'seed:')) {
            return self::$profile !== $requirement;
        }

        return false;
    }

    /**
     * 다음 테스트 전에 다시 판정하게 표시합니다.
     *
     * @return void 반환값 없음
     */
    public static function markDirty(): void
    {
        self::$dirty = true;
    }

    /**
     * 테스트가 지운 정규 마이그레이션만 다시 올립니다(2026-11-03).
     *
     * 확장 「데이터까지 삭제」 제거처럼 정규 마이그레이션의 표와 기록을 지운 채 끝나는 테스트가 있다.
     * 그대로 두면 다음 판정이 migrations 이름 집합 불일치로 전체 재생(60~80초)을 한다. 빠진 것만
     * `migrate` 로 되돌리면 다음 판정은 평소처럼 구조 지문을 비교한다 — 같으면 재사용, 다르면 전체 재생.
     * 판정 규칙은 바꾸지 않는다. 되돌리기에 실패하면 기록만 남기고 다음 판정에 맡긴다.
     *
     * 디렉터리의 첫 마이그레이션까지 빠졌으면 그 확장은 통째로 제거된 것이다 — 되돌릴 수 없는 `down()`(게시판 파티션 제거 등)의
     * 기록만 남고 표는 지워졌을 수 있으니, 그 디렉터리의 기록을 모두 지우고 처음부터 다시 올린다.
     *
     * @param  TestCase  $test  방금 끝난 테스트
     * @return void 반환값 없음
     */
    public static function restoreRemovedMigrations(TestCase $test): void
    {
        if (self::$database === null) {
            return;
        }

        $started = hrtime(true);
        try {
            $connection = DB::connection();
            $database = (string) ((array) $connection->selectOne('SELECT DATABASE() AS d', [], false))['d'];
            TestingDatabaseGuard::assertTestingDatabaseName($database, $connection);

            $names = self::migrationNames($connection);
            if ($names === []) {
                return;
            }

            $files = CanonicalMigrationFiles::resolve(base_path());
            $missing = array_diff(array_keys($files), $names);
            if ($missing === []) {
                return;
            }

            $removed = self::removedDirectoryMigrations($files, $missing);
            if ($removed !== []) {
                $connection->delete(
                    'DELETE FROM '.DataReset::quote(DataReset::migrationsTable($connection))
                        .' WHERE migration IN ('.implode(',', array_fill(0, count($removed), '?')).')',
                    $removed
                );
            }

            self::call('migrate', [
                '--path' => CanonicalMigrationFiles::dirs(base_path()),
                '--realpath' => true,
                '--force' => true,
            ]);
            app(Kernel::class)->setArtisan(null);
            self::log($database, 'REPAIR', 'restored='.count($missing), $started, $test);
        } catch (Throwable $e) {
            self::log(self::$database, 'REPAIR', 'failed: '.$e->getMessage(), $started, $test);
        }
    }

    /**
     * 첫 마이그레이션이 빠진 디렉터리(통째로 제거된 확장)의 마이그레이션 이름을 모두 돌려줍니다.
     *
     * @param  array<string, string>  $files  정규 마이그레이션 이름 → 경로(이름순)
     * @param  array<int|string, string>  $missing  DB 기록에 없는 이름
     * @return array<int, string> 기록을 지우고 다시 올릴 이름
     */
    public static function removedDirectoryMigrations(array $files, array $missing): array
    {
        $byDirectory = [];
        foreach ($files as $name => $path) {
            $byDirectory[dirname($path)][] = $name;
        }

        $missing = array_flip($missing);
        $removed = [];
        foreach ($byDirectory as $names) {
            if (isset($missing[$names[0]])) {
                array_push($removed, ...$names);
            }
        }

        return $removed;
    }

    /**
     * 정적 상태를 지정합니다(재사용 판정 단위 테스트 전용).
     *
     * @param  array{verified?: bool, dirty?: bool, profile?: string|null, fatal?: Throwable|null, stateToken?: string, database?: string|null}  $state  지정할 값
     * @return void 반환값 없음
     */
    public static function setStateForTesting(array $state): void
    {
        self::$verified = $state['verified'] ?? false;
        self::$dirty = $state['dirty'] ?? false;
        self::$profile = $state['profile'] ?? null;
        self::$fatal = $state['fatal'] ?? null;
        self::$stateToken = $state['stateToken'] ?? '';
        self::$database = $state['database'] ?? null;
    }

    /**
     * 현재 정적 상태를 돌려줍니다(재사용 판정 단위 테스트 전용).
     *
     * @return array{verified: bool, dirty: bool, profile: string|null, fatal: Throwable|null, stateToken: string, database: string|null} 상태
     */
    public static function stateForTesting(): array
    {
        return [
            'verified' => self::$verified,
            'dirty' => self::$dirty,
            'profile' => self::$profile,
            'fatal' => self::$fatal,
            'stateToken' => self::$stateToken,
            'database' => self::$database,
        ];
    }

    /**
     * DB `migrations` 테이블의 이름 목록을 돌려줍니다(테이블이 없으면 빈 목록).
     *
     * @param  Connection  $connection  테스트 DB 연결
     * @return array<int, string> 마이그레이션 이름 목록
     */
    private static function migrationNames(Connection $connection): array
    {
        $table = DataReset::migrationsTable($connection);
        $exists = $connection->selectOne(
            'SELECT 1 AS x FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table],
            false
        );
        if ($exists === null) {
            return [];
        }

        return array_map(
            static fn ($row): string => (string) ((array) $row)['migration'],
            $connection->select('SELECT migration FROM '.DataReset::quote($table), [], false)
        );
    }

    /**
     * 강제 재생 환경 변수 값을 읽습니다(`phpunit.xml` 이 아니라 실행 환경에서만).
     *
     * @return string|null 값(없으면 null)
     */
    private static function forceValue(): ?string
    {
        $value = getenv(self::FORCE_ENV);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * artisan 명령을 실행하고 실패하면 출력과 함께 예외를 던집니다.
     *
     * @param  string  $command  명령 이름
     * @param  array<string, mixed>  $arguments  인자
     * @return void 반환값 없음
     *
     * @throws RuntimeException 종료 코드가 0 이 아닐 때
     */
    private static function call(string $command, array $arguments): void
    {
        $kernel = app(Kernel::class);
        $code = $kernel->call($command, $arguments);

        if ($code !== 0) {
            throw new RuntimeException("테스트 스키마 구성 중 {$command} 실패(종료 코드 {$code}): ".trim($kernel->output()));
        }
    }

    /**
     * 판정을 건너뛴 사실을 클래스당 한 줄로 남깁니다.
     *
     * @param  TestCase  $test  현재 테스트
     * @return void 반환값 없음
     */
    private static function logSkip(TestCase $test): void
    {
        if (self::$lastSkippedClass === $test::class) {
            return;
        }

        self::$lastSkippedClass = $test::class;
        self::log((string) self::$database, 'SKIP', 'verified profile='.self::$profile, hrtime(true), $test);
    }

    /**
     * 진행 기록 한 줄을 남깁니다.
     *
     * @param  string  $database  DB 이름
     * @param  string  $action  판정 종류(FRESH·RESET·SKIP·REPAIR)
     * @param  string  $reason  사유
     * @param  int|float  $started  시작 시각(hrtime 나노초 값)
     * @param  TestCase  $test  현재 테스트
     * @return void 반환값 없음
     */
    private static function log(string $database, string $action, string $reason, int|float $started, TestCase $test): void
    {
        $line = sprintf(
            "%s pid=%d db=%s action=%s ms=%d reason=%s class=%s\n",
            date('Y-m-d H:i:s'),
            getmypid(),
            $database,
            $action,
            (int) round((hrtime(true) - $started) / 1_000_000),
            str_replace(["\r", "\n"], ' ', $reason),
            $test::class
        );

        @file_put_contents(dirname(__DIR__, 3).'/storage/logs/testing-schema-cache.log', $line, FILE_APPEND | LOCK_EX);
    }
}
