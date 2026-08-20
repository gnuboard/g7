<?php

namespace Tests\Feature\DevTools;

use Tests\TestCase;

/**
 * 라우트 캐시(`bootstrap/cache/routes-v7.php`)가 걸린 상태에서도 DevTools 덤프와
 * boost browser-logs 엔드포인트가 동작함을 실증한다.
 *
 * 배경:
 *   `route:cache` 가 걸리면 `RouteServiceProvider::boot()` 이 `loadCachedRoutes()` 로 분기해
 *   **라우트 파일 자체가 로드되지 않는다**. 클로저는 `SerializableClosure` 로 복원되지만
 *   그 클로저가 참조하는 전역 함수는 정의되지 않는다 (오토로드 대상이 아니므로).
 *   또한 `Router::setCompiledRoutes()` 가 라우트 컬렉션을 통째로 교체하므로,
 *   벤더 프로바이더가 `boot()` 에서 등록한 라우트는 조건 충족 여부와 무관하게 폐기된다.
 *
 * 왜 서브프로세스인가:
 *   같은 프로세스 안에서는 재현 자체가 불가능하다 — 상세 근거는
 *   `tests/Fixtures/DevTools/route-cache-harness.php` 헤더 주석 참조.
 *
 * 선례: tests/Feature/Upgrade/CoreUpgradeStepSpawnTest.php (PHP_BINARY + escapeshellarg + proc_open)
 */
class DevtoolsRouteCacheTest extends TestCase
{
    /** 자식 프로세스가 사용할 캐시 임시 디렉토리 (base_path 상대 POSIX 경로) */
    private static ?string $cacheRelDir = null;

    /** 클래스당 1회만 굽는다 (bake 는 수 초가 걸린다) */
    private static ?array $bakeResult = null;

    /** 실 라우트 캐시의 최초 md5 (미존재 시 null) */
    private static ?string $realCacheDigest = null;

    private static bool $realCacheExisted = false;

    /**
     * 덤프 위임이 실제로 수행됐는지 확인할 때 쓰는 섹션.
     *
     * 하네스 자식 프로세스는 실 `storage/debug-dump` 에 쓴다 (경로가 `storage_path()` 고정).
     * 개발자가 브라우저에서 받아 둔 덤프를 테스트가 덮어쓰지 않도록 이 파일만 스냅샷·복원한다.
     */
    private const PROBE_SECTION = 'logs';

    private const PROBE_FILE = 'logs-latest.json';

    /** 덤프 파일의 최초 내용 (미존재 시 null) */
    private static ?string $probeBackup = null;

    private static bool $probeExisted = false;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $repoRoot = dirname(__DIR__, 3);
        $testingDir = $repoRoot.'/storage/framework/testing';

        // 이전 실행이 비정상 종료해 남긴 잔여물 정리 (1시간 이상 된 것만).
        if (is_dir($testingDir)) {
            foreach (glob($testingDir.'/route-cache-*') ?: [] as $stale) {
                if (is_dir($stale) && filemtime($stale) < time() - 3600) {
                    self::removeDirectory($stale);
                }
            }
        }

        self::$cacheRelDir = 'storage/framework/testing/route-cache-'.uniqid();

        $realCachePath = $repoRoot.'/bootstrap/cache/routes-v7.php';
        self::$realCacheExisted = file_exists($realCachePath);
        self::$realCacheDigest = self::$realCacheExisted ? md5_file($realCachePath) : null;

        $probePath = $repoRoot.'/storage/debug-dump/'.self::PROBE_FILE;
        self::$probeExisted = file_exists($probePath);
        self::$probeBackup = self::$probeExisted ? file_get_contents($probePath) : null;
    }

    public static function tearDownAfterClass(): void
    {
        // PHPUnit 은 테스트 실패·예외와 무관하게 이 훅을 호출한다.
        if (self::$cacheRelDir !== null) {
            self::removeDirectory(dirname(__DIR__, 3).'/'.self::$cacheRelDir);
        }

        // 개발자의 덤프 데이터를 원상 복구 (자식 프로세스가 실 storage 에 썼다).
        $probePath = dirname(__DIR__, 3).'/storage/debug-dump/'.self::PROBE_FILE;
        if (self::$probeExisted && self::$probeBackup !== null) {
            file_put_contents($probePath, self::$probeBackup);
        } elseif (! self::$probeExisted && file_exists($probePath)) {
            @unlink($probePath);
        }

        self::$cacheRelDir = null;
        self::$bakeResult = null;
        self::$realCacheDigest = null;
        self::$probeBackup = null;

        parent::tearDownAfterClass();
    }

    /**
     * `dump-state` 는 라우트 캐시가 걸린 상태에서도 200 을 돌려주어야 한다.
     *
     * 수정 전: HTTP 500 `Call to undefined function handleLegacyDump()`
     */
    public function test_dump_state_survives_route_cache(): void
    {
        $marker = 'harness-'.uniqid();

        $result = $this->dispatch('POST', '/_boost/g7-debug/dump-state', json_encode([
            self::PROBE_SECTION => ['marker' => $marker],
        ], JSON_UNESCAPED_UNICODE));

        $this->assertTrue(
            $result['routesAreCached'],
            '라우트 캐시가 걸리지 않은 채 통과하면 이 테스트는 아무것도 검증하지 못합니다 (가짜 green).'
        );
        $this->assertStringContainsString(
            'route-cache-',
            $result['cachedRoutesPath'],
            '자식 프로세스가 임시 캐시가 아니라 실 캐시를 사용했습니다.'
        );

        $this->assertSame(
            200,
            $result['status'],
            "라우트 캐시 상태에서 dump-state 가 200 이어야 합니다.\nbody: {$result['body']}"
        );
        $this->assertStringNotContainsString('undefined function', $result['body']);

        // 200 만으로는 부족하다 — 페이로드가 비어 있어도 legacy 경로는 200 을 돌려준다.
        // 위임(DebugDumpWriter)이 실제로 수행됐음을 파일로 확인한다.
        $probePath = base_path('storage/debug-dump/'.self::PROBE_FILE);

        $this->assertFileExists($probePath, '덤프 위임이 파일을 쓰지 않았습니다.');
        $this->assertStringContainsString(
            $marker,
            (string) file_get_contents($probePath),
            '덤프 파일에 이번 요청의 페이로드가 담기지 않았습니다 (본문이 자식 프로세스에 전달되지 않음).'
        );
    }

    /**
     * `_boost/browser-logs` 는 라우트 캐시가 걸린 상태에서도 POST 를 받아야 한다.
     *
     * 수정 전: HTTP 405 — boost 프로바이더가 boot() 에서 등록한 라우트가 캐시 교체로 폐기되고,
     * `routes/web.php` 의 SPA catch-all(GET 전용)만 남아 POST 를 튕겨낸다.
     */
    public function test_boost_browser_logs_survives_route_cache(): void
    {
        $result = $this->dispatch('POST', '/_boost/browser-logs', json_encode([
            'logs' => [[
                'type' => 'log',
                'timestamp' => '2026-01-01T00:00:00+00:00',
                'data' => ['harness'],
                'url' => 'https://example.test/harness',
                'userAgent' => 'harness',
            ]],
        ], JSON_UNESCAPED_UNICODE));

        $this->assertTrue(
            $result['routesAreCached'],
            '라우트 캐시가 걸리지 않은 채 통과하면 이 테스트는 아무것도 검증하지 못합니다 (가짜 green).'
        );
        $this->assertStringContainsString('route-cache-', $result['cachedRoutesPath']);

        $this->assertSame(
            200,
            $result['status'],
            "라우트 캐시 상태에서 browser-logs 가 200 이어야 합니다.\nbody: {$result['body']}"
        );
    }

    /**
     * 캐시된 클로저 안에서 `__()` 번역 헬퍼가 해석되어야 한다.
     *
     * 전역 함수와 달리 `__()` 는 Composer 의 `files` 오토로드로 등록되므로 라우트 파일 로드
     * 여부와 무관하다 — 다만 "라우트 파일에 있던 것은 캐시에서 사라진다" 는 이 이슈의 성질상
     * 실제로 해석되는지를 단언해 둔다. 미해석 시 Laravel 은 오류 없이 키 문자열을 그대로
     * 응답에 실으므로, 단언이 없으면 `devtools.connection_ok` 노출을 알아챌 방법이 없다.
     */
    public function test_translation_helper_resolves_inside_cached_closure(): void
    {
        $result = $this->dispatch('POST', '/_boost/g7-debug/dump-state', json_encode([
            'test' => true,
        ], JSON_UNESCAPED_UNICODE));

        $this->assertTrue($result['routesAreCached']);
        $this->assertSame(200, $result['status'], "body: {$result['body']}");

        $payload = json_decode($result['body'], true);

        $this->assertIsArray($payload, "응답 JSON 파싱 실패.\nbody: {$result['body']}");
        $this->assertNotSame(
            'devtools.connection_ok',
            $payload['message'] ?? null,
            '캐시된 클로저 안에서 번역 키가 해석되지 않았습니다 (키 문자열이 그대로 노출됨).'
        );
        $this->assertNotEmpty($payload['message'] ?? null);
    }

    /**
     * 라우트 파일은 전역 함수를 선언하지 않아야 한다 (in-process, audit 룰과 이중화).
     *
     * 라우트 캐시가 걸리면 라우트 파일이 로드되지 않으므로, 파일 안에 정의된 전역 함수는
     * 존재하지 않는다. 핸들러가 그것을 호출하면 즉사한다.
     */
    public function test_devtools_route_file_declares_no_global_functions(): void
    {
        $path = base_path('routes/devtools.php');
        $source = file_get_contents($path);

        $this->assertIsString($source);

        preg_match_all('/^\s*function\s+(\w+)\s*\(/m', $source, $matches);

        $this->assertSame(
            [],
            $matches[1],
            'routes/devtools.php 에 전역 함수가 선언되어 있습니다: '.implode(', ', $matches[1])
            .'. 라우트 캐시가 걸리면 이 파일은 로드되지 않아 핸들러가 이 함수를 찾지 못합니다 '
            .'(→ Call to undefined function). 클래스로 옮겨 오토로드 대상으로 만드세요.'
        );
    }

    /**
     * 자식 프로세스가 실 `bootstrap/cache/routes-v7.php` 를 건드리지 않아야 한다.
     *
     * 실 캐시가 testing 기준으로 재생성되면 개발 환경이 통째로 깨진다.
     */
    public function test_real_bootstrap_cache_is_untouched(): void
    {
        // 앞선 테스트들이 자식 프로세스를 이미 띄웠음을 보장한다.
        $this->bakeOnce();

        $realCachePath = base_path('bootstrap/cache/routes-v7.php');

        $this->assertSame(
            self::$realCacheExisted,
            file_exists($realCachePath),
            '자식 프로세스가 실 라우트 캐시를 생성하거나 삭제했습니다.'
        );

        if (self::$realCacheExisted) {
            $this->assertSame(
                self::$realCacheDigest,
                md5_file($realCachePath),
                '자식 프로세스가 실 라우트 캐시를 덮어썼습니다 (임시 캐시 경로 주입 실패).'
            );
        }
    }

    /**
     * 임시 경로에 라우트 캐시를 굽는다 (클래스당 1회).
     *
     * @return array<string, mixed> 하네스 bake 결과
     */
    private function bakeOnce(): array
    {
        if (self::$bakeResult !== null) {
            return self::$bakeResult;
        }

        [$exitCode, $output] = $this->runHarness(['bake', self::$cacheRelDir]);
        $payload = $this->parseHarnessOutput($output);

        // bake 실패를 markTestSkipped 로 덮으면 회귀 방지가 통째로 무력화된다.
        $this->assertSame(
            0,
            $exitCode,
            "라우트 캐시 굽기(bake)가 실패했습니다.\noutput:\n{$output}"
        );
        $this->assertTrue(
            $payload['cacheWritten'] ?? false,
            "bake 후에도 라우트 캐시 파일이 생성되지 않았습니다.\noutput:\n{$output}"
        );

        return self::$bakeResult = $payload;
    }

    /**
     * 캐시된 라우트로 부팅한 자식 프로세스에 HTTP 요청을 디스패치한다.
     *
     * @param  string  $method  HTTP 메서드
     * @param  string  $uri  요청 URI
     * @param  string  $body  JSON 본문
     * @return array<string, mixed> 하네스 dispatch 결과
     */
    private function dispatch(string $method, string $uri, string $body = ''): array
    {
        $this->bakeOnce();

        // 본문은 base64 로 넘긴다 — Windows 의 escapeshellarg() 가 큰따옴표를 공백으로
        // 치환해 JSON 이 조용히 깨지기 때문 (하네스 헤더 주석 참조).
        [, $output] = $this->runHarness([
            'dispatch',
            self::$cacheRelDir,
            $method,
            $uri,
            $body === '' ? '' : base64_encode($body),
        ]);

        return $this->parseHarnessOutput($output);
    }

    /**
     * 하네스 스크립트를 별도 PHP 프로세스로 실행한다.
     *
     * @param  array<int, string>  $args  하네스 인자
     * @return array{0: int, 1: string} [종료 코드, stdout+stderr]
     */
    private function runHarness(array $args): array
    {
        if (! function_exists('proc_open')) {
            $this->markTestSkipped('proc_open 미지원 환경');
        }

        $commandLine = implode(' ', array_map('escapeshellarg', array_merge([
            PHP_BINARY,
            base_path('tests/Fixtures/DevTools/route-cache-harness.php'),
        ], $args))).' 2>&1';

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($commandLine, $descriptors, $pipes, base_path());
        $this->assertIsResource($process, '하네스 프로세스 실행에 실패했습니다.');

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return [$exitCode, (string) $stdout];
    }

    /**
     * 하네스 출력에서 마커 뒤 JSON 을 추출한다.
     *
     * @param  string  $output  프로세스 출력 전문
     * @return array<string, mixed> 파싱된 페이로드
     */
    private function parseHarnessOutput(string $output): array
    {
        $marker = '@@G7HARNESS@@';
        $pos = strrpos($output, $marker);

        $this->assertNotFalse(
            $pos,
            "하네스 출력에서 결과 마커를 찾지 못했습니다 (자식 프로세스가 부팅 중 죽었을 가능성).\noutput:\n{$output}"
        );

        $json = trim(substr($output, $pos + strlen($marker)));
        $decoded = json_decode($json, true);

        $this->assertIsArray(
            $decoded,
            "하네스 결과 JSON 파싱에 실패했습니다.\noutput:\n{$output}"
        );

        return $decoded;
    }

    /**
     * 디렉토리를 재귀적으로 삭제한다 (Laravel 부트스트랩에 의존하지 않는 순수 구현).
     *
     * @param  string  $dir  삭제할 디렉토리 절대경로
     * @return void
     */
    private static function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir.'/'.$entry;
            is_dir($path) ? self::removeDirectory($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
