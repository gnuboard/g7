<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\BuildsZipFixtures;
use ZipArchive;

/**
 * ZIP 부트스트랩(`g7-setup.php`)의 실제 해제 흐름을 하위 프로세스로 실측한다 (G4 · B-7).
 *
 * 트러블슈팅 `[case:backend-75]` 의 회귀 그물이다(GitHub·Finder·역슬래시 모양, 실패 뒤 세션 유지).
 *
 * ## 왜 하위 프로세스인가
 *
 * 부트스트랩은 압축을 풀 대상 디렉토리를 `__DIR__` 로 잡고, 응답을 `exit` 로 끝낸다. 그래서
 * 테스트 프로세스 안에서 그대로 부를 수 없다 — 임시 디렉토리에 사본을 두고 하네스
 * (`tests/Unit/Installer/stubs/zip_bootstrap_probe.php`)로 띄운다. 이 방식은 「대상 디렉토리 = 파일이 놓인
 * 디렉토리」라는 계약 자체를 함께 재는 이점이 있다.
 *
 * ## 무엇이 걸려 있는가
 *
 * 이 경로는 **인증되지 않은 파일 쓰기 표면**이다(§11.15). 잘못되면 조용히 두 가지가 된다 —
 * ① 완료 후에도 진입 파일이 남아 임의 파일 쓰기 입구가 되고 ② 라우팅을 가로채는 루트 파일이
 * 먼저 놓여 재개 호출이 통째로 끊긴다. 둘 다 오류를 남기지 않으므로 여기서 잰다.
 *
 * 커버 축: entry fresh·resume·completed_site / token valid·invalid·exhausted·session / archive
 * g7_payload·github_dir_entries·backslash_dir_entries·macosx·traversal·foreign / outcome extracted·refused·self_deleted
 *
 * ZIP 모양은 `BuildsZipFixtures` 로 실제 배포 도구 모양(GitHub 폴더 항목·Mac `__MACOSX`·Windows 역슬래시)을 만든다 —
 * 파일 항목만 넣던 픽스처가 GitHub 소스 ZIP 거부 결함을 놓쳤다.
 */
class ZipBootstrapFlowTest extends TestCase
{
    use BuildsZipFixtures;

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
     * 기본 모양(파일 항목만)은 기존 테스트가 쓰던 그대로다. 실제 배포 도구가 만드는 모양은 `$shape` 로 고른다 —
     * GitHub 소스 ZIP 은 `directoryEntries`, Mac 재압축은 `macosx`, Windows 압축은 `backslash`(`BuildsZipFixtures`).
     *
     * @param  string  $path  만들 ZIP 경로
     * @param  int  $filler  채움 파일 개수 (분할 해제를 강제하기 위한 축)
     * @param  string  $prefix  감싼 최상위 폴더 (빈 문자열이면 감싸지 않는다)
     * @param  array{directoryEntries?: bool, macosx?: bool, backslash?: bool}  $shape  ZIP 모양 옵션
     * @return void 반환값 없음
     */
    private function makePayloadZip(string $path, int $filler = 0, string $prefix = 'g7-9.9.9/', array $shape = []): void
    {
        $files = [
            'artisan' => "#!/usr/bin/env php\n",
            'composer.json' => "{}\n",
            'public/index.php' => "<?php // front controller\n",
            // 라우팅을 가로채는 루트 파일 — 계획상 맨 마지막에 놓여야 한다.
            '.htaccess' => "# BEGIN G7\n# END G7\n",
            'index.php' => "<?php // root shim\n",
            // 배포본에 들어 있는 부트스트랩 사본 — 풀지 않아야 한다.
            'g7-setup.php' => "<?php // payload copy\n",
        ];

        for ($i = 0; $i < $filler; $i++) {
            $files['filler/f'.$i.'.txt'] = 'x';
        }

        $this->makeZipFixture($path, $files, ['wrapper' => rtrim($prefix, '/')] + $shape);
        $this->assertFileExists($path);
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
        $this->assertFileDoesNotExist($site.'/g7-setup-state.lock');
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

        foreach (['1', '000000000', 'abcdef'] as $bad) {
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
     * 번호를 넣지 않은 요청은 오입력으로 세지 않고 번호를 넣으라고만 알린다.
     *
     * 브라우저를 닫았다 다시 열면 번호가 새로 만들어지는데, 운영자는 진행 표시와 [이어서 풀기] 를 보고
     * 번호 칸을 비운 채 누르기 쉽다. 그것을 오입력으로 세면 남은 횟수가 줄고 지연이 걸리며 다섯 번이면
     * 번호가 또 바뀌고 잠긴다. 빈 값은 대입 시도가 아니므로 세지 않아도 방어가 약해지지 않는다.
     * 숫자가 아닌 문자만 넣은 값(숫자만 남기면 빈 값)도 같다. 파일은 쓰지 않는다.
     *
     * @scenario archive=g7_payload, token=invalid, entry=resume
     *
     * @effects zip_bootstrap_empty_number_is_not_counted_as_attempt
     *
     * @return void 반환값 없음
     */
    public function test_번호를_비운_요청은_오입력으로_세지_않는다(): void
    {
        $site = $this->makeSite();
        $this->makePayloadZip($site.'/g7-9.9.9.zip');
        $this->issueToken($site);

        foreach (['', '   ', 'abcdef'] as $empty) {
            $result = $this->call($site, ['action' => 'extract', 'token' => $empty]);

            $this->assertIsArray($result['json'], $result['raw']);
            $this->assertFalse($result['json']['ok'], '빈 번호가 통과했다');
            $this->assertSame('token_required', (string) ($result['json']['code'] ?? ''), $result['raw']);
            $this->assertArrayNotHasKey('retry_after', $result['json'], '빈 번호에 지연이 걸렸다');
        }

        $state = $this->readState($site);
        $this->assertSame(0, (int) $state['attempts'], '빈 번호가 오입력 횟수를 늘렸다');
        $this->assertSame(0, (int) $state['locked_until'], '빈 번호가 잠금을 걸었다');
        $this->assertFileDoesNotExist($site.'/artisan', '거부된 요청이 파일을 썼다');
    }

    /**
     * 세션이 무효가 된 탭이 번호 없이 보내 `token_required` 를 받으면 세션을 버리고 번호 칸을 다시 보인다.
     *
     * 세션 키를 가진 탭은 번호 칸을 숨긴 채 세션만 보낸다. 서버에서 그 세션이 무효가 되면(번호 재발급, 해제
     * 완료 뒤 상태 초기화) 빈 번호 요청이 되어 `token_required` 가 오는데, 화면이 그 코드에서 세션을 지우지
     * 않으면 번호 칸이 숨겨진 채 같은 요청만 되풀이해 운영자가 번호를 넣을 길이 없다.
     *
     * @scenario archive=g7_payload, token=session, entry=resume
     *
     * @effects zip_bootstrap_empty_number_is_not_counted_as_attempt
     *
     * @return void 반환값 없음
     */
    public function test_세션이_무효가_된_탭은_번호_칸을_다시_보인다(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3).'/g7-setup.php');
        $this->assertSame(1, preg_match("~if \(data\.code === 'token_reissued'[^)]*\) \{(.*?)\n                \}~s", $source, $m), '인증 실패 코드 처리 분기를 찾지 못했다');

        $this->assertStringContainsString("'token_required'", $m[0], '번호 없이 보낸 요청의 실패(token_required)에서 세션을 버리지 않는다 — 번호 칸이 숨겨진 채 남는다');
        $this->assertStringContainsString("removeItem('g7_setup_session')", $m[1], '세션 키를 지우지 않는다');
        $this->assertStringContainsString("tokenField.style.display = ''", $m[1], '숨긴 번호 칸을 다시 보이지 않는다');
    }

    /**
     * 임시 보호 규칙이 `mod_access_compat` 이 없는 Apache 2.4 에서도 번호, 상태 파일을 막는다.
     *
     * 종전 규칙은 `Order`/`Deny` 하나만 써서, 그 하위호환 모듈이 없는 서버에서는 두 파일이 200 으로
     * 열렸다(실측: 번호 6자리가 그대로 읽힘). 루트 `.htaccess` 와 같은 방식으로 경로 판정은
     * `RedirectMatch 403`(mod_alias, FileInfo 등급)을 쓰고, mod_alias 가 없을 때만 `Order`/`Deny` 로 물러선다.
     *
     * @scenario archive=g7_payload, token=valid, entry=fresh
     *
     * @effects zip_bootstrap_guard_blocks_number_file_without_access_compat
     *
     * @return void 반환값 없음
     */
    public function test_임시_보호_규칙은_access_compat_없이도_번호_파일을_막는다(): void
    {
        $site = $this->makeSite();
        $this->makePayloadZip($site.'/g7-9.9.9.zip');
        $this->issueToken($site);

        $rules = (string) file_get_contents($site.'/.htaccess');

        $this->assertMatchesRegularExpression(
            '~<IfModule mod_alias\.c>\s*'.preg_quote('RedirectMatch 403 (?i)/g7-setup-(token\.txt|state\.json)$', '~').'\s*</IfModule>~',
            $rules,
            '번호, 상태 파일 경로를 mod_alias 로 막는 규칙이 없다 — mod_access_compat 없는 Apache 에서 열린다'
        );
        $this->assertMatchesRegularExpression('~<IfModule !mod_alias\.c>.*Deny from all.*</IfModule>~s', $rules, 'mod_alias 가 없을 때의 대체 규칙이 없다');
        $this->assertStringNotContainsString('Require', $rules, 'Require 는 AuthConfig 등급이라 제한 호스팅에서 500 이 된다');

        $pattern = '~'.str_replace('~', '\~', substr((string) strstr($rules, '(?i)'), 4, strpos((string) strstr($rules, '(?i)'), '$') - 3)).'~i';
        $this->assertSame(1, preg_match($pattern, '/g7/g7-setup-token.txt'));
        $this->assertSame(1, preg_match($pattern, '/G7-SETUP-STATE.JSON'));
        $this->assertSame(0, preg_match($pattern, '/g7/g7-setup.php'), '부트스트랩 자신까지 막는다');
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

    /**
     * 첫 인증에 성공하면 상태 파일에서 확인 번호의 해시가 지워지고, 옛 번호로는 다시 인증되지 않는지 확인한다.
     *
     * 6자리 번호의 해시는 소금이 없으면 10^6 번 대입으로 되돌릴 수 있어 사실상 평문이다. 상태 파일은
     * 문서 루트에 있으므로 남겨 두면 번호 파일을 지운 의미가 사라진다.
     *
     * @scenario archive=g7_payload, token=valid, entry=fresh
     *
     * @effects zip_bootstrap_forgets_number_hash_after_first_auth
     *
     * @return void 반환값 없음
     */
    public function test_첫_인증_뒤_상태_파일에_번호_해시가_남지_않는다(): void
    {
        $site = $this->makeSite();
        $this->makePayloadZip($site.'/g7-9.9.9.zip', 450);
        $token = $this->issueToken($site);

        $this->assertNotSame('', (string) ($this->readState($site)['token_hash'] ?? ''), '전제: 발급 직후에는 해시가 있다');

        $first = $this->call($site, ['action' => 'extract', 'token' => $token]);
        $this->assertTrue($first['json']['ok'] ?? false, $first['raw']);
        $this->assertFalse($first['json']['finished'], '첫 요청에서 끝나면 상태 파일이 지워져 이 축을 잴 수 없다');

        $this->assertSame('', (string) ($this->readState($site)['token_hash'] ?? 'missing'), '첫 인증 뒤에도 상태 파일에 번호 해시가 남았다');

        $replay = $this->call($site, ['action' => 'extract', 'token' => $token]);
        $this->assertFalse($replay['json']['ok'] ?? true, '첫 인증에 쓴 번호가 다시 통했다: '.$replay['raw']);
    }

    /**
     * 오입력 판정이 상태 파일 잠금 안에서 읽고 쓰는지 확인한다 — 다른 요청이 잠금을 쥔 동안 기다렸다가, 그 요청이 남긴 시도 횟수 위에 더한다.
     *
     * 잠금이 없으면 병렬 요청이 모두 같은 시도 횟수를 읽고 같은 값을 써서, 오입력 상한과 지수 지연이
     * 요청 수만큼 무력해진다.
     *
     * @scenario archive=g7_payload, token=invalid, entry=fresh
     *
     * @effects zip_bootstrap_serializes_attempt_judgment_under_state_lock
     *
     * @return void 반환값 없음
     */
    public function test_오입력_판정은_상태_잠금을_기다린_뒤_누적된다(): void
    {
        $site = $this->makeSite();
        $this->makePayloadZip($site.'/g7-9.9.9.zip');
        $this->issueToken($site);

        $lock = fopen($site.'/g7-setup-state.lock', 'c');
        $this->assertNotFalse($lock);
        $this->assertTrue(flock($lock, LOCK_EX), '테스트가 상태 잠금을 쥐지 못했다');

        $command = [
            PHP_BINARY,
            __DIR__.'/stubs/zip_bootstrap_probe.php',
            $site.'/g7-setup.php',
            base64_encode((string) json_encode(['action' => 'extract', 'token' => '000001'])),
        ];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);

        try {
            usleep(1500000);
            $this->assertTrue(proc_get_status($process)['running'], '상태 잠금을 쥐고 있는데 오입력 판정이 기다리지 않고 끝났다');

            // 잠금을 쥔 동안 다른 요청이 오입력 3회를 기록한 것과 같은 상태를 만든다.
            $state = $this->readState($site);
            $state['attempts'] = 3;
            $state['locked_until'] = 0;
            file_put_contents($site.'/g7-setup-state.json', json_encode($state));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        $raw = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $json = json_decode($raw, true);
        $this->assertSame('invalid_token', $json['code'] ?? null, $raw);
        $this->assertSame(4, (int) ($this->readState($site)['attempts'] ?? 0), '잠금이 풀린 뒤 읽은 시도 횟수 위에 더해야 한다');
    }

    /**
     * 운영자가 미리 둔 루트 `.htaccess` 가 있으면 G7 블록만 들이고 그 밖의 원래 내용은 지우지 않는지 확인한다.
     *
     * 코어 업데이터(`RootHtaccessUpdater`)와 같은 규칙이다 — 블록 밖은 운영자 영역이다.
     *
     * @scenario archive=g7_payload, token=valid, entry=fresh
     *
     * @effects zip_bootstrap_preserves_operator_root_file_outside_g7_block
     *
     * @return void 반환값 없음
     */
    public function test_운영자의_루트_htaccess_는_블록_밖_내용이_보존된다(): void
    {
        $site = $this->makeSite();
        $operator = "# 호스팅 기본 설정\nAddHandler application/x-httpd-php83 .php\n";
        file_put_contents($site.'/.htaccess', $operator);
        $this->makePayloadZip($site.'/g7-9.9.9.zip');
        $token = $this->issueToken($site);

        $result = $this->call($site, ['action' => 'extract', 'token' => $token]);
        $this->assertTrue($result['json']['finished'] ?? false, $result['raw']);

        $merged = (string) file_get_contents($site.'/.htaccess');
        $this->assertStringContainsString("# BEGIN G7\n# END G7\n", $merged, '배포본의 G7 블록이 들어오지 않았다');
        $this->assertStringContainsString('AddHandler application/x-httpd-php83 .php', $merged, '운영자의 원래 규칙이 사라졌다');
    }

    /**
     * 운영자 `web.config` 에 G7 블록이 있으면 블록만 새 판본으로 바뀌고 블록 밖 요소는 그대로 남는지 확인한다.
     *
     * @scenario archive=g7_payload, token=valid, entry=fresh
     *
     * @effects zip_bootstrap_preserves_operator_root_file_outside_g7_block
     *
     * @return void 반환값 없음
     */
    public function test_운영자의_web_config_는_G7_블록만_교체된다(): void
    {
        $site = $this->makeSite();
        file_put_contents($site.'/web.config', "<?xml version=\"1.0\"?>\n<configuration>\n<!-- BEGIN G7 -->\n  <system.webServer><old/></system.webServer>\n<!-- END G7 -->\n  <system.web><customErrors mode=\"Off\" /></system.web>\n</configuration>\n");
        $this->makePayloadZip($site.'/g7-9.9.9.zip');
        $this->addZipEntry($site.'/g7-9.9.9.zip', 'g7-9.9.9/web.config', "<?xml version=\"1.0\"?>\n<configuration>\n<!-- BEGIN G7 -->\n  <system.webServer><new/></system.webServer>\n<!-- END G7 -->\n</configuration>\n");
        $token = $this->issueToken($site);

        $result = $this->call($site, ['action' => 'extract', 'token' => $token]);
        $this->assertTrue($result['json']['finished'] ?? false, $result['raw']);

        $merged = (string) file_get_contents($site.'/web.config');
        $this->assertStringContainsString('<new/>', $merged, '배포본의 G7 블록으로 바뀌지 않았다');
        $this->assertStringNotContainsString('<old/>', $merged, '옛 G7 블록이 남았다');
        $this->assertStringContainsString('<customErrors mode="Off" />', $merged, '블록 밖 운영자 요소가 사라졌다');
    }

    /**
     * G7 블록이 없는 운영자 `web.config` 는 합칠 자리가 없으므로 덮어쓰지 않고, 아무것도 풀기 전에 이유를 알리며 멈추는지 확인한다.
     *
     * @scenario archive=g7_payload, token=valid, entry=fresh
     *
     * @effects zip_bootstrap_refuses_unmergeable_operator_root_file
     *
     * @return void 반환값 없음
     */
    public function test_블록_없는_운영자_web_config_는_덮지_않고_멈춘다(): void
    {
        $site = $this->makeSite();
        $operator = "<?xml version=\"1.0\"?>\n<configuration>\n  <system.webServer><defaultDocument enabled=\"true\" /></system.webServer>\n</configuration>\n";
        file_put_contents($site.'/web.config', $operator);
        $this->makePayloadZip($site.'/g7-9.9.9.zip');
        $this->addZipEntry($site.'/g7-9.9.9.zip', 'g7-9.9.9/web.config', "<?xml version=\"1.0\"?>\n<configuration>\n<!-- BEGIN G7 -->\n  <system.webServer><new/></system.webServer>\n<!-- END G7 -->\n</configuration>\n");
        $token = $this->issueToken($site);

        $result = $this->call($site, ['action' => 'extract', 'token' => $token]);
        $this->assertFalse($result['json']['ok'] ?? true, $result['raw']);
        $this->assertSame('root_file_conflict', $result['json']['code'] ?? null, $result['raw']);
        $this->assertSame($operator, (string) file_get_contents($site.'/web.config'), '운영자 web.config 가 바뀌었다');
        $this->assertFileDoesNotExist($site.'/artisan', '합칠 수 없는 파일이 있는데 해제를 시작했다');
    }

    /**
     * GitHub 소스 ZIP 처럼 감싼 폴더의 폴더 항목(`g7-9.9.9/`)이 맨 앞에 있어도 감싼 폴더를 벗겨 문서 루트에 푸는지 확인한다.
     *
     * 폴더 항목은 끝 `/` 를 떼면 `/` 가 없는 이름이 되어 「최상위에 파일이 있다」로 오판됐다. 그러면 감싼 폴더를
     * 벗기지 못해 배포본 표식(`artisan`·`composer.json`·`public/index.php`)을 못 찾고 `not_a_g7_payload` 가 났다.
     *
     * @scenario archive=github_dir_entries, token=valid, entry=fresh
     *
     * @effects zip_bootstrap_strips_wrapper_with_directory_entries, zip_bootstrap_strips_wrapper_directory
     *
     * @return void 반환값 없음
     */
    public function test_폴더_항목이_있는_GitHub_모양_압축을_감싼_폴더를_벗겨_푼다(): void
    {
        $site = $this->makeSite();
        $this->makePayloadZip($site.'/g7-9.9.9.zip', 0, 'g7-9.9.9/', ['directoryEntries' => true]);
        $token = $this->issueToken($site);

        $result = $this->call($site, ['action' => 'extract', 'token' => $token]);

        $this->assertIsArray($result['json'], $result['raw']);
        $this->assertTrue($result['json']['ok'] ?? false, $result['raw']);
        $this->assertTrue($result['json']['finished'] ?? false, $result['raw']);
        $this->assertFileExists($site.'/artisan');
        $this->assertFileExists($site.'/public/index.php');
        $this->assertDirectoryDoesNotExist($site.'/g7-9.9.9', '감싼 폴더가 벗겨지지 않았다');
    }

    /**
     * 감싸지 않은 압축에 폴더 항목이 있어도 접두사 없이 그대로 푸는지 확인한다 (폴더 항목 판정이 평탄 ZIP 을 감싼 것으로 오판하지 않는다).
     *
     * @scenario archive=github_dir_entries, token=valid, entry=fresh
     *
     * @effects zip_bootstrap_strips_wrapper_with_directory_entries
     *
     * @return void 반환값 없음
     */
    public function test_감싸지_않은_압축에_폴더_항목이_있어도_그대로_푼다(): void
    {
        $site = $this->makeSite();
        $this->makePayloadZip($site.'/g7-9.9.9.zip', 0, '', ['directoryEntries' => true]);
        $token = $this->issueToken($site);

        $result = $this->call($site, ['action' => 'extract', 'token' => $token]);

        $this->assertTrue($result['json']['finished'] ?? false, $result['raw']);
        $this->assertFileExists($site.'/artisan');
        $this->assertFileExists($site.'/public/index.php');
        $this->assertDirectoryDoesNotExist($site.'/public/public', '평탄 ZIP 을 감싼 것으로 보고 한 겹 벗겼다');
    }

    /**
     * Windows 압축 도구가 만든 역슬래시 폴더 항목(`g7-9.9.9\public\`)을 0바이트 파일이 아니라 폴더로 만드는지 확인한다.
     *
     * 폴더 판정이 원본 이름의 끝 `/` 만 보면 `public` 이 빈 파일로 먼저 쓰이고, 그 아래 `public/index.php` 가
     * 폴더를 만들지 못해 `write_failed` 가 된다.
     *
     * @scenario archive=backslash_dir_entries, token=valid, entry=fresh
     *
     * @effects zip_bootstrap_backslash_directory_entries_become_directories
     *
     * @return void 반환값 없음
     */
    public function test_역슬래시_폴더_항목을_폴더로_만든다(): void
    {
        $site = $this->makeSite();
        $this->makePayloadZip($site.'/g7-9.9.9.zip', 0, 'g7-9.9.9/', ['directoryEntries' => true, 'backslash' => true]);
        $token = $this->issueToken($site);

        $result = $this->call($site, ['action' => 'extract', 'token' => $token]);

        $this->assertTrue($result['json']['finished'] ?? false, $result['raw']);
        $this->assertDirectoryExists($site.'/public', '역슬래시 폴더 항목이 폴더가 아니다');
        $this->assertFileExists($site.'/public/index.php');
        $this->assertFileExists($site.'/artisan');
        $this->assertDirectoryDoesNotExist($site.'/g7-9.9.9');
    }

    /**
     * Mac 에서 다시 압축한 ZIP 의 `__MACOSX/` 부속 항목이 감싼 폴더 판정을 깨지 않고, 풀리지도 진행 개수에 세지도 않는지 확인한다.
     *
     * @scenario archive=macosx, token=valid, entry=fresh
     *
     * @effects zip_bootstrap_skips_macosx_resource_forks, zip_bootstrap_strips_wrapper_directory
     *
     * @return void 반환값 없음
     */
    public function test_MACOSX_부속_폴더는_판정과_해제에서_빠진다(): void
    {
        $plain = $this->makeSite('plain');
        $this->makePayloadZip($plain.'/g7-9.9.9.zip', 0, 'g7-9.9.9/', ['directoryEntries' => true]);
        $plainResult = $this->call($plain, ['action' => 'extract', 'token' => $this->issueToken($plain)]);
        $this->assertTrue($plainResult['json']['finished'] ?? false, '전제: 부속 항목 없는 같은 ZIP 이 풀려야 한다 — '.$plainResult['raw']);

        $site = $this->makeSite('mac');
        $this->makePayloadZip($site.'/g7-9.9.9.zip', 0, 'g7-9.9.9/', ['directoryEntries' => true, 'macosx' => true]);
        $result = $this->call($site, ['action' => 'extract', 'token' => $this->issueToken($site)]);

        $this->assertTrue($result['json']['finished'] ?? false, $result['raw']);
        $this->assertFileExists($site.'/artisan');
        $this->assertDirectoryDoesNotExist($site.'/__MACOSX', '부속 폴더가 풀렸다');
        $this->assertDirectoryDoesNotExist($site.'/g7-9.9.9');
        $this->assertSame($plainResult['json']['total'], $result['json']['total'], '부속 항목이 진행 개수에 들어갔다');
    }

    /**
     * 맞는 번호로 인증한 뒤 압축 판정이 실패해도 응답이 세션 키를 돌려주는지 확인한다.
     *
     * 번호는 첫 인증 성공에서 지워진다. 실패 응답이 세션을 싣지 않으면 화면은 지워진 번호를 다시 보내
     * `invalid_token` 과 시도 차감을 받고, 복구는 새로고침(번호 재발급)뿐이었다.
     *
     * @scenario archive=foreign, token=valid, entry=fresh
     *
     * @effects zip_bootstrap_keeps_session_after_post_auth_failure, zip_bootstrap_rejects_non_g7_archive
     *
     * @return void 반환값 없음
     */
    public function test_판정이_실패해도_인증된_세션을_돌려준다(): void
    {
        $site = $this->makeSite();
        $this->makeZipFixture($site.'/other.zip', ['notes/readme.txt' => 'hello']);
        $token = $this->issueToken($site);

        $result = $this->call($site, ['action' => 'extract', 'token' => $token, 'zip' => 'other.zip']);

        $this->assertSame('not_a_g7_payload', (string) ($result['json']['code'] ?? ''), $result['raw']);
        $this->assertNotSame('', (string) ($result['json']['session'] ?? ''), '판정 실패 응답에 세션이 없다 — 번호만 소모된다');
        $this->assertFileDoesNotExist($site.'/g7-setup-token.txt', '전제: 번호는 첫 인증에서 지워진다');
        $this->assertSame(0, (int) ($this->readState($site)['attempts'] ?? -1));
    }

    /**
     * 판정 실패 응답으로 받은 세션으로, 압축 파일을 바꾼 뒤 번호 없이 이어서 푸는지 확인한다.
     *
     * @scenario archive=foreign, token=session, entry=fresh
     *
     * @effects zip_bootstrap_keeps_session_after_post_auth_failure
     *
     * @return void 반환값 없음
     */
    public function test_실패_뒤_압축을_바꾸면_번호_없이_세션으로_이어_푼다(): void
    {
        $site = $this->makeSite();
        $this->makeZipFixture($site.'/g7-9.9.9.zip', ['notes/readme.txt' => 'hello']);
        $token = $this->issueToken($site);

        $failed = $this->call($site, ['action' => 'extract', 'token' => $token]);
        $this->assertSame('not_a_g7_payload', (string) ($failed['json']['code'] ?? ''), $failed['raw']);
        $session = (string) ($failed['json']['session'] ?? '');
        $this->assertNotSame('', $session, '판정 실패 응답에 세션이 없다: '.$failed['raw']);

        // FTP 로 올바른 배포본을 같은 이름으로 다시 올린다 — 크기가 달라 진행 인덱스는 처음부터다.
        $this->makePayloadZip($site.'/g7-9.9.9.zip', 0, 'g7-9.9.9/', ['directoryEntries' => true]);

        $next = $this->call($site, ['action' => 'extract', 'session' => $session]);

        $this->assertTrue($next['json']['ok'] ?? false, $next['raw']);
        $this->assertTrue($next['json']['finished'] ?? false, $next['raw']);
        $this->assertFileExists($site.'/artisan');
    }

    /**
     * 인증 뒤에 나는 실패 응답 종류.
     *
     * @return array<string, array{0: string}> 실패 코드 목록
     */
    public static function postAuthFailureProvider(): array
    {
        return [
            '압축 파일 없음' => ['no_zip'],
            '압축 파일 열기 실패' => ['zip_open_failed'],
            '경로 순회 항목' => ['unsafe_entry'],
            '배포본 아님' => ['not_a_g7_payload'],
            '합칠 수 없는 운영자 루트 파일' => ['root_file_conflict'],
        ];
    }

    /**
     * 맞는 번호로 인증한 요청이면 어느 실패 응답이든 세션 키를 싣는지 확인한다.
     *
     * @scenario archive=traversal, token=valid, entry=fresh
     *
     * @effects zip_bootstrap_keeps_session_after_post_auth_failure
     *
     * @param  string  $code  기대 실패 코드
     * @return void 반환값 없음
     */
    #[DataProvider('postAuthFailureProvider')]
    public function test_인증_뒤_실패_응답은_모두_세션을_싣는다(string $code): void
    {
        $site = $this->makeSite();
        $this->makePayloadZip($site.'/g7-9.9.9.zip');
        $token = $this->issueToken($site);

        switch ($code) {
            case 'no_zip':
                unlink($site.'/g7-9.9.9.zip');
                break;
            case 'zip_open_failed':
                file_put_contents($site.'/g7-9.9.9.zip', 'not a zip archive');
                break;
            case 'unsafe_entry':
                $this->addZipEntry($site.'/g7-9.9.9.zip', '../outside.txt', 'pwned');
                break;
            case 'not_a_g7_payload':
                $this->makeZipFixture($site.'/g7-9.9.9.zip', ['notes/readme.txt' => 'hello']);
                break;
            case 'root_file_conflict':
                file_put_contents($site.'/web.config', "<?xml version=\"1.0\"?>\n<configuration />\n");
                $this->addZipEntry($site.'/g7-9.9.9.zip', 'g7-9.9.9/web.config', "<?xml version=\"1.0\"?>\n<configuration>\n<!-- BEGIN G7 -->\n<!-- END G7 -->\n</configuration>\n");
                break;
        }

        $result = $this->call($site, ['action' => 'extract', 'token' => $token]);

        $this->assertFalse($result['json']['ok'] ?? true, $result['raw']);
        $this->assertSame($code, (string) ($result['json']['code'] ?? ''), $result['raw']);
        $this->assertNotSame('', (string) ($result['json']['session'] ?? ''), $code.' 응답에 세션이 없다 — 번호만 소모된다');
    }

    /**
     * 이미 만든 ZIP 에 엔트리 하나를 더한다.
     *
     * @param  string  $zipPath  대상 ZIP 경로
     * @param  string  $name  ZIP 안의 엔트리 이름
     * @param  string  $content  엔트리 내용
     * @return void 반환값 없음
     */
    private function addZipEntry(string $zipPath, string $name, string $content): void
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($zipPath) === true);
        $zip->addFromString($name, $content);
        $zip->close();
    }
}
