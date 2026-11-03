<?php

namespace Tests\Support\TestingSchema;

/**
 * 테스트 스키마 기록 파일 저장소
 *
 * 기록은 DB 이름마다 하나다(`<디렉터리>/<DB이름>.json`) — 세션 전용 DB(`g7_testing_s1` 등)도 따로 재사용한다.
 * 쓰기는 임시 파일에 쓴 뒤 rename 으로 바꿔치기해, 읽는 쪽이 반쯤 쓴 파일을 보지 않게 한다. 읽을 수 없거나
 * 형식·로직 판이 다르거나 키가 빠진 기록은 "기록 없음" 으로 본다(→ 전체 재생).
 *
 * 상태 토큰(`<DB이름>.state`)은 데이터 상태를 바꾼 프로세스가 남기는 표식이다. 별도 프로세스로 도는 테스트가
 * 데이터를 바꾸면 부모 프로세스는 다음 테스트에서 토큰이 달라진 것을 보고 다시 판정한다.
 */
final class RecordStore
{
    /** 기록 형식 판 */
    public const FORMAT = 1;

    /** 기록에 반드시 있어야 하는 키 */
    private const REQUIRED_KEYS = ['format', 'logic', 'fingerprint', 'envKey', 'checksum', 'migrationNames', 'baseline', 'freshRunId', 'createdAt'];

    /**
     * 기록 디렉터리를 돌려줍니다.
     *
     * @param  string|null  $directory  지정한 디렉터리(생략하면 `storage/framework/testing/schema-cache`)
     * @return string 디렉터리 경로
     */
    public static function directory(?string $directory = null): string
    {
        return rtrim(str_replace('\\', '/', $directory ?? dirname(__DIR__, 3).'/storage/framework/testing/schema-cache'), '/');
    }

    /**
     * 새 기록을 조립합니다.
     *
     * @param  string  $fingerprint  마이그레이션 지문
     * @param  string  $envKey  환경 키
     * @param  string  $checksum  구조 지문
     * @param  array<int, string>  $migrationNames  DB `migrations` 이름 목록
     * @param  array<string, mixed>  $baseline  기준 행 스냅숏
     * @param  string|null  $freshRunId  강제 재생 실행 ID
     * @return array<string, mixed> 기록
     */
    public static function compose(string $fingerprint, string $envKey, string $checksum, array $migrationNames, array $baseline, ?string $freshRunId): array
    {
        sort($migrationNames, SORT_STRING);

        return [
            'format' => self::FORMAT,
            'logic' => TestingSchemaCache::LOGIC_VERSION,
            'fingerprint' => $fingerprint,
            'envKey' => $envKey,
            'checksum' => $checksum,
            'migrationNames' => array_values($migrationNames),
            'baseline' => $baseline,
            'freshRunId' => $freshRunId,
            'createdAt' => date(DATE_ATOM),
        ];
    }

    /**
     * 기록을 읽습니다.
     *
     * @param  string  $database  DB 이름
     * @param  string|null  $directory  기록 디렉터리
     * @return array<string, mixed>|null 기록(없거나 쓸 수 없으면 null)
     */
    public static function read(string $database, ?string $directory = null): ?array
    {
        $file = self::path($database, $directory);
        if (! is_file($file)) {
            return null;
        }

        $data = json_decode((string) @file_get_contents($file), true);
        if (! is_array($data)) {
            return null;
        }

        foreach (self::REQUIRED_KEYS as $key) {
            if (! array_key_exists($key, $data)) {
                return null;
            }
        }

        if ($data['format'] !== self::FORMAT || $data['logic'] !== TestingSchemaCache::LOGIC_VERSION) {
            return null;
        }

        return $data;
    }

    /**
     * 기록을 원자적으로 씁니다.
     *
     * @param  string  $database  DB 이름
     * @param  array<string, mixed>  $record  기록
     * @param  string|null  $directory  기록 디렉터리
     * @return void 반환값 없음
     *
     * @throws \RuntimeException 쓰기에 실패했을 때
     */
    public static function write(string $database, array $record, ?string $directory = null): void
    {
        $json = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new \RuntimeException('테스트 스키마 기록을 JSON 으로 만들지 못했다: '.json_last_error_msg());
        }

        self::atomicPut(self::path($database, $directory), $json);
    }

    /**
     * 기록을 지웁니다(전체 재생을 시작하기 전에 — 중간에 끊겨도 낡은 기록으로 재사용하지 않게).
     *
     * @param  string  $database  DB 이름
     * @param  string|null  $directory  기록 디렉터리
     * @return void 반환값 없음
     */
    public static function delete(string $database, ?string $directory = null): void
    {
        $file = self::path($database, $directory);
        if (is_file($file)) {
            @unlink($file);
        }
    }

    /**
     * 상태 토큰을 읽습니다.
     *
     * @param  string  $database  DB 이름
     * @param  string|null  $directory  기록 디렉터리
     * @return string 토큰(없으면 빈 문자열)
     */
    public static function readState(string $database, ?string $directory = null): string
    {
        $file = self::directory($directory).'/'.self::safeName($database).'.state';

        return is_file($file) ? trim((string) @file_get_contents($file)) : '';
    }

    /**
     * 새 상태 토큰을 남기고 돌려줍니다.
     *
     * @param  string  $database  DB 이름
     * @param  string|null  $directory  기록 디렉터리
     * @return string 새 토큰
     */
    public static function touchState(string $database, ?string $directory = null): string
    {
        $token = getmypid().'-'.bin2hex(random_bytes(8));
        self::atomicPut(self::directory($directory).'/'.self::safeName($database).'.state', $token);

        return $token;
    }

    /**
     * 기록 파일 경로를 돌려줍니다.
     *
     * @param  string  $database  DB 이름
     * @param  string|null  $directory  기록 디렉터리
     * @return string 파일 경로
     */
    public static function path(string $database, ?string $directory = null): string
    {
        return self::directory($directory).'/'.self::safeName($database).'.json';
    }

    /**
     * 남은 임시 파일을 지웁니다(이전 프로세스가 쓰다 끊긴 잔재).
     *
     * @param  string  $database  DB 이름
     * @param  string|null  $directory  기록 디렉터리
     * @return void 반환값 없음
     */
    public static function cleanupTemporaryFiles(string $database, ?string $directory = null): void
    {
        foreach (glob(self::directory($directory).'/'.self::safeName($database).'.*.tmp') ?: [] as $tmp) {
            @unlink($tmp);
        }
    }

    /**
     * 임시 파일에 쓴 뒤 rename 으로 바꿔치기합니다.
     *
     * Windows 는 대상이 열려 있으면 rename 이 잠깐 실패할 수 있어 50ms 간격으로 세 번 다시 시도하고, 그래도 안 되면
     * 대상을 지운 뒤 한 번 더 바꾼다.
     *
     * @param  string  $file  대상 파일
     * @param  string  $contents  내용
     * @return void 반환값 없음
     *
     * @throws \RuntimeException 쓰기에 실패했을 때
     */
    private static function atomicPut(string $file, string $contents): void
    {
        $dir = dirname($file);
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new \RuntimeException("테스트 스키마 기록 디렉터리를 만들지 못했다: {$dir}");
        }

        $tmp = $file.'.'.getmypid().'.tmp';
        $handle = @fopen($tmp, 'wb');
        if ($handle === false) {
            throw new \RuntimeException("테스트 스키마 임시 파일을 열지 못했다: {$tmp}");
        }
        fwrite($handle, $contents);
        fflush($handle);
        fclose($handle);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            if (@rename($tmp, $file)) {
                return;
            }
            usleep(50_000);
        }

        @unlink($file);
        if (! @rename($tmp, $file)) {
            @unlink($tmp);
            throw new \RuntimeException("테스트 스키마 기록을 쓰지 못했다: {$file}");
        }
    }

    /**
     * DB 이름을 파일 이름으로 쓸 수 있게 정리합니다.
     *
     * @param  string  $database  DB 이름
     * @return string 파일 이름에 쓸 문자열
     */
    private static function safeName(string $database): string
    {
        return preg_replace('/[^A-Za-z0-9_.-]/', '_', $database) ?? $database;
    }
}
