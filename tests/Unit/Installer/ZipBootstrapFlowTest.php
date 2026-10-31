<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * ZIP 부트스트랩(`g7-setup.php`)의 실제 해제 흐름을 하위 프로세스로 실측한다 (G4 · B-7).
 *
 * ## 왜 하위 프로세스인가
 *
 * 부트스트랩은 압축을 풀 대상 디렉토리를 `__DIR__` 로 잡고, 응답을 `exit` 로 끝낸다. 그래서
 * 테스트 프로세스 안에서 그대로 부를 수 없다 — 임시 디렉토리에 사본을 두고 하네스
 * (`stubs/zip_bootstrap_probe.php`)로 띄운다. 이 방식은 「대상 디렉토리 = 파일이 놓인
 * 디렉토리」라는 계약 자체를 함께 재는 이점이 있다.
 *
 * ## 무엇이 걸려 있는가
 *
 * 이 경로는 **인증되지 않은 파일 쓰기 표면**이다(§11.15). 잘못되면 조용히 두 가지가 된다 —
 * ① 완료 후에도 진입 파일이 남아 임의 파일 쓰기 입구가 되고 ② 라우팅을 가로채는 루트 파일이
 * 먼저 놓여 재개 호출이 통째로 끊긴다. 둘 다 오류를 남기지 않으므로 여기서 잰다.
 *
 * 커버 축: entry fresh·resume·completed_site / token valid·invalid·exhausted / archive
 * g7_payload·traversal·foreign / outcome extracted·refused·self_deleted
 */
class ZipBootstrapFlowTest extends TestCase
{
    private string $workDir = '';

    /**
     * zip 확장과 PHP 실행 파일 경로가 있는지 확인하고 이 테스트 전용 임시 작업 폴더를 만든다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('zip 확장이 없어 부트스트랩 흐름을 잴 수 없다');
        }

        if (! defined('PHP_BINARY') || PHP_BINARY === '') {
            $this->markTestSkipped('PHP_BINARY 를 알 수 없어 하위 프로세스를 띄울 수 없다');
        }

        $this->workDir = $this->makeTempDir();
    }

    /**
     * 테스트가 만든 임시 작업 폴더를 통째로 지운다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        if ($this->workDir !== '') {
            $this->removeTree($this->workDir);
        }

        parent::tearDown();
    }

    /**
     * 자기 temp 밖을 절대 건드리지 않는다 (§7 R7 — 인스톨러 테스트가 실 `.env` 를 파괴한 선례).
     *
     * @return string 새로 만든 임시 폴더의 슬래시 경로
     */
    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir().'/g7-zip-bootstrap-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        return str_replace('\\', '/', $dir);
    }

    /**
     * 주어진 폴더 아래를 재귀로 비우고 폴더 자체도 지운다(심볼릭 링크는 따라가지 않는다).
     *
     * @param  string  $dir  지울 폴더 경로
     * @return void 반환값 없음
     */
    private function removeTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.'/'.$entry;
            is_dir($path) && ! is_link($path) ? $this->removeTree($path) : @unlink($path);
        }

        @rmdir($dir);
    }

    /**
     * 부트스트랩 사본을 담은 사이트 디렉토리를 만든다.
     *
     * @param  string  $name  작업 폴더 아래에 만들 사이트 폴더 이름
     * @return string 사이트 디렉토리 절대 경로
     */
    private function makeSite(string $name = 'site'): string
    {
        $site = $this->workDir.'/'.$name;
        mkdir($site, 0777, true);
        copy(dirname(__DIR__, 3).'/g7-setup.php', $site.'/g7-setup.php');

        return $site;
    }

    /**
     * G7 배포본 형태의 ZIP 을 만든다.
     *
     * @param  string  $path  만들 ZIP 경로
     * @param  int  $filler  채움 파일 개수 (분할 해제를 강제하기 위한 축)
     * @param  string  $prefix  감싼 최상위 폴더 (빈 문자열이면 감싸지 않는다)
     * @return void 반환값 없음
     */
    private function makePayloadZip(string $path, int $filler = 0, string $prefix = 'g7-9.9.9/'): void
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);

        $zip->addFromString($prefix.'artisan', "#!/usr/bin/env php\n");
        $zip->addFromString($prefix.'composer.json', "{}\n");
        $zip->addFromString($prefix.'public/index.php', "<?php // front controller\n");
        // 라우팅을 가로채는 루트 파일 — 계획상 맨 마지막에 놓여야 한다.
        $zip->addFromString($prefix.'.htaccess', "# BEGIN G7\n# END G7\n");
        $zip->addFromString($prefix.'index.php', "<?php // root shim\n");
        // 배포본에 들어 있는 부트스트랩 사본 — 풀지 않아야 한다.
        $zip->addFromString($prefix.'g7-setup.php', "<?php // payload copy\n");

        for ($i = 0; $i < $filler; $i++) {
            $zip->addFromString($prefix.'filler/f'.$i.'.txt', 'x');
        }

        $zip->close();
    }

    /**
     * 하네스를 띄워 부트스트랩을 한 번 호출한다.
     *
     * @param  string  $site  부트스트랩 사본이 놓인 사이트 디렉토리 절대 경로
     * @param  array<string, string>  $post  POST 필드
     * @return array{code: int, raw: string, json: array<string, mixed>|null} 종료 코드, 원문 출력, 해석된 JSON 응답
     */
    private function call(string $site, array $post): array
    {
        $command = escapeshellarg(PHP_BINARY)
            .' '.escapeshellarg(__DIR__.'/stubs/zip_bootstrap_probe.php')
            .' '.escapeshellarg($site.'/g7-setup.php')
            .' '.escapeshellarg(base64_encode((string) json_encode($post, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)))
            .' 2>&1';

        $output = [];
        $code = 0;
        exec($command, $output, $code);
        $raw = implode("\n", $output);

        $json = json_decode($raw, true);

        return ['code' => $code, 'raw' => $raw, 'json' => is_array($json) ? $json : null];
    }

    /**
     * 확인 번호를 읽는다 (화면을 한 번 열어 발급시킨 뒤).
     *
     * @param  string  $site  부트스트랩 사본이 놓인 사이트 디렉토리 절대 경로
     * @return string 화면 최초 진입이 발급한 확인 번호
     */
    private function issueToken(string $site): string
    {
        $this->call($site, []);
        $tokenFile = $site.'/g7-setup-token.txt';
        $this->assertFileExists($tokenFile, '화면 최초 진입이 확인 번호 파일을 만들어야 한다');

        return trim((string) file_get_contents($tokenFile));
    }

    /**
     * 상태 파일을 읽는다.
     *
     * @param  string  $site  부트스트랩 사본이 놓인 사이트 디렉토리 절대 경로
     * @return array<string, mixed> 사이트 폴더의 해제 상태 파일 내용(없거나 깨졌으면 빈 배열)
     */
    private function readState(string $site): array
    {
        $decoded = json_decode((string) @file_get_contents($site.'/g7-setup-state.json'), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * 작은 배포본을 한 요청으로 풀 때 최상위 감싼 폴더가 벗겨지고, 진입 파일과 압축, 번호, 상태 파일이 모두 지워지는지 확인한다.
     *
     * @scenario archive=g7_payload, token=valid, entry=fresh
     *
     * @effects zip_bootstrap_strips_wrapper_directory, zip_bootstrap_self_deletes_entry_archive_and_state
     *
     * @return void 반환값 없음
     */
    public function test_한_번에_끝나는_해제가_감싼_폴더를_벗기고_자기를_지운다(): void
    {
        $site = $this->makeSite();
        $this->makePayloadZip($site.'/g7-9.9.9.zip');
        $token = $this->issueToken($site);

        $result = $this->call($site, ['action' => 'extract', 'token' => $token]);

        $this->assertIsArray($result['json'], '해제 응답이 JSON 이어야 한다: '.$result['raw']);
        $this->assertTrue($result['json']['ok'], $result['raw']);
        $this->assertTrue($result['json']['finished'], $result['raw']);

        // 감싼 폴더(`g7-9.9.9/`)는 벗겨진다 — 그대로 두면 문서 루트 아래에 폴더가 하나 더 생겨
        // 사이트가 서지 않는다.
        $this->assertFileExists($site.'/artisan');
        $this->assertFileExists($site.'/composer.json');
        $this->assertFileExists($site.'/public/index.php');
        $this->assertDirectoryDoesNotExist($site.'/g7-9.9.9');

        // 라우팅을 가로채는 루트 파일도 최종적으로는 놓인다.
        $this->assertFileExists($site.'/.htaccess');
        $this->assertStringContainsString('BEGIN G7', (string) file_get_contents($site.'/.htaccess'));
        $this->assertFileExists($site.'/index.php');

        // 자기 삭제 — 진입 파일·ZIP·번호·상태가 남으면 임의 파일 쓰기 입구가 된다.
        $this->assertSame([], $result['json']['leftover'], $result['raw']);
        $this->assertFileDoesNotExist($site.'/g7-setup.php');
        $this->assertFileDoesNotExist($site.'/g7-9.9.9.zip');
        $this->assertFileDoesNotExist($site.'/g7-setup-token.txt');
        $this->assertFileDoesNotExist($site.'/g7-setup-state.json');
    }

    /**
     * 요청 상한을 넘는 배포본이 여러 요청에 걸쳐 이어 풀리고, 라우팅을 가로채는 루트 파일은 마지막 요청 전까지 놓이지 않는지 확인한다.
     *
     * @scenario archive=g7_payload, token=valid, token=session, entry=fresh, entry=resume
     *
     * @effects zip_bootstrap_resumes_from_recorded_entry_index, zip_bootstrap_defers_routing_root_files_to_final_request
     *
     * @return void 반환값 없음
     */
    public function test_분할_해제가_이어서_진행되고_라우팅_파일은_마지막에_놓인다(): void
    {
        $site = $this->makeSite();
        // 한 요청 상한(400)을 넘겨 분할을 강제한다.
        $this->makePayloadZip($site.'/g7-9.9.9.zip', 450);
        $token = $this->issueToken($site);

        $first = $this->call($site, ['action' => 'extract', 'token' => $token]);
        $this->assertIsArray($first['json'], $first['raw']);
        $this->assertTrue($first['json']['ok'], $first['raw']);
        $this->assertFalse($first['json']['finished'], '첫 요청에서 끝나면 분할 축을 재지 못한다');
        $this->assertGreaterThan(0, $first['json']['done']);
        $this->assertLessThan($first['json']['total'], $first['json']['done']);

        // 이 시점에 배포본의 루트 `.htaccess` 가 놓이면 다음 호출이 `public/` 으로 위임되어
        // 재개가 끊긴다. 이 자리에 있는 `.htaccess` 는 부트스트랩이 쓴 임시 보호 규칙뿐이며,
        // 그것은 위임하지 않는다 — 배포본 판본인지는 마커로 가른다.
        $this->assertStringNotContainsString(
            'BEGIN G7',
            (string) @file_get_contents($site.'/.htaccess'),
            '라우팅을 가로채는 루트 파일은 마지막에만 놓인다'
        );
        $this->assertFileDoesNotExist($site.'/index.php');

        $this->assertSame($first['json']['done'], $this->readState($site)['index'] ?? null);

        // 세션 키로 이어서 — 번호 파일은 첫 인증에서 사라졌다.
        $this->assertFileDoesNotExist($site.'/g7-setup-token.txt', '첫 인증 성공은 번호 파일을 지운다');

        $session = (string) $first['json']['session'];
        $this->assertNotSame('', $session);

        $guard = 0;
        $last = $first;
        while ($last['json']['finished'] === false && $guard++ < 20) {
            $last = $this->call($site, ['action' => 'extract', 'session' => $session]);
            $this->assertIsArray($last['json'], $last['raw']);
            $this->assertTrue($last['json']['ok'], $last['raw']);
        }

        $this->assertTrue($last['json']['finished'], '분할 해제가 완주하지 않았다');
        $this->assertFileExists($site.'/.htaccess');
        $this->assertFileExists($site.'/filler/f449.txt');
        $this->assertFileDoesNotExist($site.'/g7-setup.php');
    }

    /**
     * 분할 해제 도중 화면을 다시 열어도 진행 인덱스와 세션이 그대로 남아 다음 요청이 이어서 푸는지 확인한다.
     *
     * @scenario archive=g7_payload, token=session, entry=resume
     *
     * @effects zip_bootstrap_refresh_preserves_progress_and_session
     *
     * @return void 반환값 없음
     */
    public function test_새로고침으로_재진입해도_진행_인덱스가_유지된다(): void
    {
        $site = $this->makeSite();
        $this->makePayloadZip($site.'/g7-9.9.9.zip', 450);
        $token = $this->issueToken($site);

        $first = $this->call($site, ['action' => 'extract', 'token' => $token]);
        $done = (int) $first['json']['done'];

        // 화면 새로고침(GET) — 이 호출이 진행 상태나 세션을 무효로 만들면 안 된다.
        $render = $this->call($site, []);
        $this->assertStringContainsString('<!DOCTYPE html>', $render['raw']);

        $state = $this->readState($site);
        $this->assertSame($done, (int) $state['index'], '새로고침이 진행 인덱스를 되돌렸다');
        $this->assertNotSame('', (string) $state['session_hash'], '새로고침이 진행 중인 세션을 끊었다');

        $next = $this->call($site, ['action' => 'extract', 'session' => (string) $first['json']['session']]);
        $this->assertTrue($next['json']['ok'], $next['raw']);
        $this->assertGreaterThan($done, (int) $next['json']['done']);
    }

    /**
     * 세션 키를 잃은 뒤 재발급 경로로 새 확인 번호를 받아 처음부터가 아니라 멈춘 자리부터 이어 푸는지 확인한다.
     *
     * @scenario archive=g7_payload, token=valid, entry=resume
     *
     * @effects zip_bootstrap_reissues_number_after_browser_session_loss
     *
     * @return void 반환값 없음
     */
    public function test_브라우저를_닫아_세션을_잃으면_번호를_다시_발급받아_이어서_푼다(): void
    {
        $site = $this->makeSite();
        $this->makePayloadZip($site.'/g7-9.9.9.zip', 450);
        $token = $this->issueToken($site);

        $first = $this->call($site, ['action' => 'extract', 'token' => $token]);
        $done = (int) $first['json']['done'];
        $this->assertFileDoesNotExist($site.'/g7-setup-token.txt');

        // 브라우저 종료 = 세션 키 소실. 화면은 재발급 경로를 연다.
        // 재발급에는 최소 간격이 걸려 있다(무인증 경로의 제동) — 시간 경과를 모사한다.
        $state = $this->readState($site);
        $state['token_issued_at'] = time() - 3600;
        file_put_contents($site.'/g7-setup-state.json', json_encode($state));

        $reissue = $this->call($site, ['action' => 'reissue']);
        $this->assertTrue($reissue['json']['ok'], $reissue['raw']);
        $this->assertTrue($reissue['json']['reissued'], $reissue['raw']);
        $this->assertFileExists($site.'/g7-setup-token.txt');

        $newToken = trim((string) file_get_contents($site.'/g7-setup-token.txt'));
        $this->assertNotSame($token, $newToken, '재발급된 번호가 이전과 같으면 재발급이 아니다');

        $resumed = $this->call($site, ['action' => 'extract', 'token' => $newToken]);
        $this->assertTrue($resumed['json']['ok'], $resumed['raw']);
        $this->assertGreaterThan($done, (int) $resumed['json']['done'], '재인증 후 처음부터 다시 풀었다');
    }

    /**
     * 잘못된 확인 번호 여러 형태가 모두 거부되고 어떤 파일도 쓰이지 않는지 확인한다.
     *
     * @scenario archive=g7_payload, token=invalid, entry=fresh
     *
     * @effects zip_bootstrap_rejects_wrong_confirmation_number_without_writing
     *
     * @return void 반환값 없음
     */
    public function test_확인_번호가_틀리면_거부하고_파일시스템을_바꾸지_않는다(): void
    {
        $site = $this->makeSite();
        $this->makePayloadZip($site.'/g7-9.9.9.zip');
        $this->issueToken($site);

        foreach (['', '1', '000000000', 'abcdef'] as $bad) {
            // 직전 실패가 건 지수 지연을 지워 시도 횟수 축만 잰다 (시간 경과 모사).
            $state = $this->readState($site);
            $state['locked_until'] = 0;
            file_put_contents($site.'/g7-setup-state.json', json_encode($state));

            $result = $this->call($site, ['action' => 'extract', 'token' => $bad]);
            $this->assertIsArray($result['json'], $result['raw']);
            $this->assertFalse($result['json']['ok'], '잘못된 번호가 통과했다: '.$bad);
            $this->assertFileDoesNotExist($site.'/artisan', '거부된 요청이 파일을 썼다');
        }
    }

    /**
     * 오입력이 시도 상한에 닿으면 잠금이 걸리고 번호가 새로 발급되며, 잠긴 동안에는 새 번호도 통하지 않는지 확인한다.
     *
     * @scenario archive=g7_payload, token=exhausted, entry=fresh
     *
     * @effects zip_bootstrap_locks_and_reissues_after_attempt_limit
     *
     * @return void 반환값 없음
     */
    public function test_연속_오입력이_상한에_닿으면_잠기고_번호가_재발급된다(): void
    {
        $site = $this->makeSite();
        $this->makePayloadZip($site.'/g7-9.9.9.zip');
        $token = $this->issueToken($site);

        $lastCode = '';
        for ($i = 0; $i < 5; $i++) {
            $state = $this->readState($site);
            $state['locked_until'] = 0;
            file_put_contents($site.'/g7-setup-state.json', json_encode($state));

            $result = $this->call($site, ['action' => 'extract', 'token' => '000001']);
            $lastCode = (string) ($result['json']['code'] ?? '');
        }

        $this->assertSame('token_reissued', $lastCode, '상한에 닿아도 번호가 재발급되지 않았다');

        $state = $this->readState($site);
        $this->assertGreaterThan(time(), (int) $state['locked_until'], '상한 도달 후 잠금이 걸리지 않았다');

        $newToken = trim((string) file_get_contents($site.'/g7-setup-token.txt'));
        $this->assertNotSame($token, $newToken, '상한 도달 시 번호가 그대로 남았다');

        // 잠긴 동안에는 옛 번호도 새 번호도 통하지 않는다.
        $locked = $this->call($site, ['action' => 'extract', 'token' => $newToken]);
        $this->assertSame('locked', (string) ($locked['json']['code'] ?? ''), $locked['raw']);
        $this->assertFileDoesNotExist($site.'/artisan');
    }

    /**
     * 원격 대입 시도가 건 잠금이 이미 인증된 운영자 세션의 해제 요청까지 막지 않는지 확인한다.
     *
     * @scenario archive=g7_payload, token=session, entry=resume
     *
     * @effects zip_bootstrap_lock_does_not_stall_authenticated_session
     *
     * @return void 반환값 없음
     */
    public function test_잠금이_이미_인증된_세션을_멈추지_않는다(): void
    {
        $site = $this->makeSite();
        $this->makePayloadZip($site.'/g7-9.9.9.zip', 450);
        $token = $this->issueToken($site);

        $first = $this->call($site, ['action' => 'extract', 'token' => $token]);
        $session = (string) $first['json']['session'];

        // 원격 대입 시도가 잠금을 건다 — 그 잠금이 운영자의 해제를 멈추면 서비스 거부가 된다.
        $state = $this->readState($site);
        $state['locked_until'] = time() + 300;
        file_put_contents($site.'/g7-setup-state.json', json_encode($state));

        $next = $this->call($site, ['action' => 'extract', 'session' => $session]);
        $this->assertTrue($next['json']['ok'] ?? false, $next['raw']);
    }

    /**
     * 상위 경로로 빠져나가는 엔트리가 든 압축은 계획 단계에서 거부되어 대상 밖은 물론 안전한 엔트리도 쓰이지 않는지 확인한다.
     *
     * @scenario archive=traversal, token=valid, entry=fresh
     *
     * @effects zip_bootstrap_rejects_path_traversal_entries
     *
     * @return void 반환값 없음
     */
    public function test_경로_순회_엔트리가_있으면_거부하고_대상_밖에_쓰지_않는다(): void
    {
        $site = $this->makeSite();
        $outside = $this->workDir.'/outside.txt';

        $zip = new ZipArchive;
        $zip->open($site.'/evil.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('artisan', "#!/usr/bin/env php\n");
        $zip->addFromString('composer.json', "{}\n");
        $zip->addFromString('public/index.php', "<?php\n");
        $zip->addFromString('../outside.txt', 'pwned');
        $zip->close();

        $token = $this->issueToken($site);
        $result = $this->call($site, ['action' => 'extract', 'token' => $token, 'zip' => 'evil.zip']);

        $this->assertIsArray($result['json'], $result['raw']);
        $this->assertFalse($result['json']['ok'], $result['raw']);
        $this->assertSame('unsafe_entry', $result['json']['code'], $result['raw']);
        $this->assertFileDoesNotExist($outside, '대상 디렉토리 밖에 파일이 쓰였다');
        // 계획 단계에서 거부하므로 안전한 엔트리도 한 개도 쓰이지 않는다.
        $this->assertFileDoesNotExist($site.'/artisan');
    }

    /**
     * G7 배포본 형태가 아닌 압축은 거부 코드와 함께 아무것도 풀지 않는지 확인한다.
     *
     * @scenario archive=foreign, token=valid, entry=fresh
     *
     * @effects zip_bootstrap_rejects_non_g7_archive
     *
     * @return void 반환값 없음
     */
    public function test_G7_배포본이_아닌_압축은_풀지_않는다(): void
    {
        $site = $this->makeSite();

        $zip = new ZipArchive;
        $zip->open($site.'/other.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('notes/readme.txt', 'hello');
        $zip->close();

        $token = $this->issueToken($site);
        $result = $this->call($site, ['action' => 'extract', 'token' => $token, 'zip' => 'other.zip']);

        $this->assertSame('not_a_g7_payload', (string) ($result['json']['code'] ?? ''), $result['raw']);
        $this->assertDirectoryDoesNotExist($site.'/notes');
    }

    /**
     * 설치 완료 표식 파일이 있는 사이트에서는 화면이 완료 안내만 보이고 번호 발급과 해제를 모두 거부하는지 확인한다.
     *
     * @scenario archive=g7_payload, token=valid, entry=completed_site
     *
     * @effects zip_bootstrap_refuses_on_completed_site
     *
     * @return void 반환값 없음
     */
    public function test_설치가_끝난_사이트에서는_아무것도_하지_않는다(): void
    {
        $site = $this->makeSite();
        $this->makePayloadZip($site.'/g7-9.9.9.zip');
        mkdir($site.'/storage/app', 0777, true);
        file_put_contents($site.'/storage/app/g7_installed', '1');

        $render = $this->call($site, []);
        $this->assertStringContainsString('already installed', strtolower($render['raw']));
        $this->assertFileDoesNotExist($site.'/g7-setup-token.txt', '완료된 사이트에서 번호를 발급했다');

        $result = $this->call($site, ['action' => 'extract', 'token' => '123456']);
        $this->assertSame('already_installed', (string) ($result['json']['code'] ?? ''), $result['raw']);
        $this->assertFileDoesNotExist($site.'/artisan');
    }

    /**
     * 환경 파일의 설치 완료 플래그만 있어도 해제 요청을 완료 사이트로 보고 거부하는지 확인한다.
     *
     * @scenario archive=g7_payload, token=valid, entry=completed_site
     *
     * @effects zip_bootstrap_refuses_when_env_completion_flag_set
     *
     * @return void 반환값 없음
     */
    public function test_env_완료_표식만_있어도_차단한다(): void
    {
        $site = $this->makeSite();
        $this->makePayloadZip($site.'/g7-9.9.9.zip');
        file_put_contents($site.'/.env', "APP_NAME=G7\nINSTALLER_COMPLETED=true\n");

        $result = $this->call($site, ['action' => 'extract', 'token' => '123456']);
        $this->assertSame('already_installed', (string) ($result['json']['code'] ?? ''), $result['raw']);
    }

    /**
     * 풀 압축 파일이 없으면 화면이 그 사실만 안내하고 확인 번호 파일을 만들지 않는지 확인한다.
     *
     * @scenario archive=absent, token=valid, entry=fresh
     *
     * @effects zip_bootstrap_issues_no_number_without_archive
     *
     * @return void 반환값 없음
     */
    public function test_압축_파일이_없으면_안내만_하고_번호를_만들지_않는다(): void
    {
        $site = $this->makeSite();

        $render = $this->call($site, []);
        $this->assertStringContainsString('No archive', $render['raw']);
        $this->assertFileDoesNotExist($site.'/g7-setup-token.txt');
    }
}
