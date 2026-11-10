<?php

namespace Tests\Support\TestingSchema;

/**
 * 테스트 DB 단위 프로세스 잠금
 *
 * 같은 테스트 DB 를 두 phpunit 프로세스가 동시에 쓰면 한쪽이 다른 쪽의 스키마를 지우고 다시 만든다(대량 오류,
 * 2026-09 사례). 잠금은 DB 이름마다 파일 하나에 `flock` 을 건다 — 프로세스가 끝나면 OS 가 풀어 주므로
 * 강제 종료 뒤에도 남지 않는다. 잠금을 얻지 못하면 기다리지도 상대를 끊지도 않고 바로 멈춘다.
 *
 * 별도 프로세스로 도는 테스트(`#[RunInSeparateProcess]`)는 부모가 이미 잠금을 쥐고 있다. 부모가 잠근 뒤
 * 환경 변수(`G7_TEST_SCHEMA_LOCK_OWNER`)에 자기 pid 를 남기면, 자식은 그 값을 보고 잠금과 좀비 연결 정리를
 * 건너뛴다(부모 연결을 좀비로 보고 끊지 않도록).
 */
final class ProcessLock
{
    /** 부모 프로세스 pid 를 자식에게 넘기는 환경 변수 */
    public const OWNER_ENV = 'G7_TEST_SCHEMA_LOCK_OWNER';

    /**
     * DB 이름 → 잠금 파일 핸들(프로세스가 끝날 때까지 쥔다)
     *
     * @var array<string, resource>
     */
    private static array $handles = [];

    /**
     * 잠금을 얻습니다. 자식 프로세스면 건너뜁니다.
     *
     * @param  string  $database  DB 이름
     * @param  string|null  $directory  잠금 파일 디렉터리(생략하면 기록 디렉터리)
     * @return bool 이 호출로 잠금을 쥐고 있으면 true, 자식이라 건너뛰었으면 false
     *
     * @throws \RuntimeException 다른 프로세스가 잠금을 쥐고 있을 때
     */
    public static function acquireOrSkipIfChild(string $database, ?string $directory = null): bool
    {
        if (self::isChildProcess()) {
            return false;
        }

        if (isset(self::$handles[$database])) {
            return true;
        }

        $dir = RecordStore::directory($directory);
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new \RuntimeException("테스트 스키마 잠금 디렉터리를 만들지 못했다: {$dir}");
        }

        $file = $dir.'/'.(preg_replace('/[^A-Za-z0-9_.-]/', '_', $database) ?? $database).'.lock';
        $handle = @fopen($file, 'c+');
        if ($handle === false) {
            throw new \RuntimeException("테스트 스키마 잠금 파일을 열지 못했다: {$file}");
        }

        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            // Windows 의 flock 은 강제 잠금이라 잠긴 파일 자체는 읽을 수 없다 — pid 는 옆 파일에 둔다
            $owner = trim((string) @file_get_contents($file.'.pid'));
            fclose($handle);

            throw new \RuntimeException(sprintf(
                '테스트 DB %s 는 PID %s 이(가) 사용 중 — 그 실행이 끝난 뒤 다시 실행하거나 다른 테스트 DB 를 지정한다.',
                $database,
                $owner !== '' ? $owner : '?'
            ));
        }

        @file_put_contents($file.'.pid', (string) getmypid());

        self::$handles[$database] = $handle;
        putenv(self::OWNER_ENV.'='.getmypid());

        return true;
    }

    /**
     * 부모가 잠금을 쥔 자식 프로세스인지 판정합니다.
     *
     * @return bool 자식이면 true
     */
    public static function isChildProcess(): bool
    {
        $owner = getenv(self::OWNER_ENV);

        return is_string($owner) && $owner !== '' && $owner !== (string) getmypid();
    }

    /**
     * 쥔 잠금을 풉니다(테스트 전용 — 실제 실행은 프로세스 종료로 풀린다).
     *
     * @param  string  $database  DB 이름
     * @return void 반환값 없음
     */
    public static function release(string $database): void
    {
        if (! isset(self::$handles[$database])) {
            return;
        }

        flock(self::$handles[$database], LOCK_UN);
        fclose(self::$handles[$database]);
        unset(self::$handles[$database]);

        if (self::$handles === []) {
            putenv(self::OWNER_ENV);
        }
    }
}
