<?php

namespace Tests\Unit\Bootstrap;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\PureTestCase;

/**
 * `public/index.php` 가 vendor 없이 응답하는 경로 회귀 테스트.
 *
 * 코어 업데이트는 유지보수 모드를 켠 채 운영 `vendor/` 를 비우고 새 vendor 를 복사한다(수십 초).
 * 그 사이 들어온 요청은 미리 렌더된 유지보수 화면이 없으면 `vendor/autoload.php` require 에서 치명 오류로 죽어,
 * 유지보수 503 이 아니라 500(display_errors 가 켜진 환경은 200 + 오류 본문)을 받았다.
 *
 * 진입점은 프레임워크를 싣기 전에 실행되므로 앱 부팅 없이 진짜 웹 요청으로 확인한다 — vendor 없는 임시 루트에
 * 현재 `public/index.php` 만 복사하고 PHP 내장 웹서버로 띄워 상태 코드·헤더·본문을 본다.
 */
class PublicIndexVendorGuardTest extends PureTestCase
{
    private string $root;

    private ?Process $server = null;

    private int $port = 0;

    /**
     * vendor 없는 설치 완료 상태의 임시 루트를 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'g7_index_guard_'.uniqid();
        mkdir($this->root.'/public', 0777, true);
        mkdir($this->root.'/storage/app', 0777, true);
        mkdir($this->root.'/storage/framework', 0777, true);
        mkdir($this->root.'/vendor', 0777, true);

        copy(dirname(__DIR__, 3).'/public/index.php', $this->root.'/public/index.php');
        touch($this->root.'/storage/app/g7_installed');
    }

    /**
     * 내장 웹서버를 끄고 임시 루트를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->server?->stop(3);
        $this->server = null;
        $this->removeTree($this->root);

        parent::tearDown();
    }

    /**
     * 유지보수 중 vendor 가 비어 있으면 치명 오류 대신 유지보수 503 과 down 파일의 Retry-After 를 보낸다.
     *
     * @scenario case=maintenance_vendor_empty_html
     *
     * @effects maintenance_vendor_gap_returns_503
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_maintenance_with_empty_vendor_returns_503(): void
    {
        $this->writeDownFile();

        $response = $this->fetchUri('/');

        $this->assertSame(503, $response['status'], $response['body']);
        $this->assertSame('60', $response['headers']['retry-after'] ?? null);
        $this->assertSame('15', $response['headers']['refresh'] ?? null);
        $this->assertStringContainsString('text/html', $response['headers']['content-type'] ?? '');
        $this->assertStringContainsString('Service Unavailable', $response['body']);
        $this->assertNoFatalLeak($response['body']);
    }

    /**
     * 유지보수 중 vendor 가 비어 있을 때 API 요청은 유지보수 미들웨어와 같은 모양의 JSON 503 을 받는다.
     *
     * @scenario case=maintenance_vendor_empty_api
     *
     * @effects maintenance_vendor_gap_returns_json_503_for_api
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_maintenance_with_empty_vendor_returns_json_503_for_api(): void
    {
        $this->writeDownFile();

        $response = $this->fetchUri('/api/settings/public');

        $this->assertSame(503, $response['status'], $response['body']);
        $this->assertStringContainsString('application/json', $response['headers']['content-type'] ?? '');
        $decoded = json_decode($response['body'], true);
        $this->assertIsArray($decoded, $response['body']);
        $this->assertFalse($decoded['success']);
        $this->assertNotSame('', (string) ($decoded['message'] ?? ''));
    }

    /**
     * 복사 도중(autoload.php 는 왔지만 그것이 부르는 파일이 아직 없음)에 난 치명 오류도 유지보수 중이면 503 이 된다.
     *
     * @scenario case=maintenance_vendor_partial_html
     *
     * @effects maintenance_vendor_partial_copy_returns_503
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_maintenance_with_partially_copied_vendor_returns_503(): void
    {
        $this->writeDownFile();
        mkdir($this->root.'/vendor/composer', 0777, true);
        file_put_contents(
            $this->root.'/vendor/autoload.php',
            "<?php\nreturn require __DIR__.'/composer/autoload_real.php';\n"
        );
        file_put_contents(
            $this->root.'/vendor/composer/autoload_real.php',
            "<?php\nrequire __DIR__.'/not-copied-yet.php';\n"
        );

        $response = $this->fetchUri('/');

        $this->assertSame(503, $response['status'], $response['body']);
        $this->assertSame('60', $response['headers']['retry-after'] ?? null);
        $this->assertStringContainsString('Service Unavailable', $response['body']);
        $this->assertNoFatalLeak($response['body']);
    }

    /**
     * 유지보수 중이라도 vendor 가 온전하면 진입점은 끼어들지 않고 프레임워크(유지보수 미들웨어)가 응답한다.
     *
     * 가짜 autoload·bootstrap 으로 프레임워크 자리를 채워, 응답이 그쪽에서 나왔는지 본문 표식으로 확인한다.
     *
     * @scenario case=maintenance_vendor_complete_html
     *
     * @effects maintenance_with_complete_vendor_delegates_to_framework
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_maintenance_with_complete_vendor_delegates_to_framework(): void
    {
        $this->writeDownFile();
        mkdir($this->root.'/bootstrap', 0777, true);
        file_put_contents($this->root.'/vendor/autoload.php', <<<'PHP'
<?php
namespace Illuminate\Http {
    class Request
    {
        public static function capture(): self
        {
            return new self;
        }
    }
}
namespace {
    return new stdClass;
}
PHP);
        file_put_contents($this->root.'/bootstrap/app.php', <<<'PHP'
<?php
return new class
{
    public function handleRequest($request): void
    {
        http_response_code(503);
        echo 'framework-maintenance-page';
    }
};
PHP);

        $response = $this->fetchUri('/');

        $this->assertSame(503, $response['status'], $response['body']);
        $this->assertSame('framework-maintenance-page', $response['body']);
    }

    /**
     * 유지보수가 아닌데 vendor 가 없으면 일시 장애(503)가 아니라 설치 손상이므로 500 을 보내고 오류 내부를 노출하지 않는다.
     *
     * @scenario case=no_maintenance_vendor_empty
     *
     * @effects vendor_missing_outside_maintenance_returns_500
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_empty_vendor_outside_maintenance_returns_500(): void
    {
        $response = $this->fetchUri('/');

        $this->assertSame(500, $response['status'], $response['body']);
        $this->assertArrayNotHasKey('retry-after', $response['headers']);
        $this->assertNoFatalLeak($response['body']);
    }

    /**
     * 미설치 사이트는 vendor 유무와 관계없이 지금처럼 설치 화면으로 보낸다.
     *
     * @scenario case=not_installed
     *
     * @effects not_installed_redirect_unchanged
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_not_installed_still_redirects_to_installer(): void
    {
        unlink($this->root.'/storage/app/g7_installed');

        $response = $this->fetchUri('/');

        $this->assertSame(302, $response['status'], $response['body']);
        $this->assertStringEndsWith('/install', $response['headers']['location'] ?? '');
    }

    /**
     * `php artisan down --secret --retry=60 --refresh=15` 가 남기는 것과 같은 down 파일을 씁니다.
     *
     * @return void 반환값 없음
     */
    private function writeDownFile(): void
    {
        file_put_contents($this->root.'/storage/framework/down', json_encode([
            'except' => [],
            'redirect' => null,
            'retry' => 60,
            'refresh' => 15,
            'secret' => 'test-secret',
            'status' => 503,
            'template' => null,
        ], JSON_PRETTY_PRINT));
    }

    /**
     * 임시 루트를 내장 웹서버로 띄우고 요청 하나를 보냅니다.
     *
     * display_errors 를 켜 로컬 개발 환경과 같게 둔다 — 꺼진 환경보다 오류 본문이 새기 쉬운 쪽이다.
     *
     * @param  string  $uri  요청 경로
     * @return array{status:int, headers:array<string,string>, body:string} 응답
     */
    private function fetchUri(string $uri): array
    {
        $this->startServer();

        $context = stream_context_create(['http' => [
            'ignore_errors' => true,
            'follow_location' => 0,
            'timeout' => 15,
        ]]);
        $body = @file_get_contents("http://127.0.0.1:{$this->port}{$uri}", false, $context);
        $this->assertNotFalse($body, '내장 웹서버 응답 없음: '.($this->server?->getErrorOutput() ?? ''));

        $status = 0;
        $headers = [];
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $status = (int) $m[1];

                continue;
            }
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($name))] = trim($value);
            }
        }

        return ['status' => $status, 'headers' => $headers, 'body' => (string) $body];
    }

    /**
     * 빈 포트를 골라 내장 웹서버를 띄우고 접속될 때까지 기다립니다.
     *
     * @return void 반환값 없음
     */
    private function startServer(): void
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($probe, "빈 포트를 찾지 못함: {$errstr}");
        $this->port = (int) substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);

        $this->server = new Process([
            PHP_BINARY,
            '-d', 'display_errors=1',
            '-d', 'log_errors=0',
            '-S', "127.0.0.1:{$this->port}",
            '-t', $this->root.'/public',
            $this->root.'/public/index.php',
        ], $this->root);
        $this->server->start();

        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            $socket = @fsockopen('127.0.0.1', $this->port, $errno, $errstr, 0.2);
            if ($socket !== false) {
                fclose($socket);

                return;
            }
            usleep(100_000);
        }

        $this->fail('내장 웹서버가 뜨지 않음: '.$this->server->getErrorOutput());
    }

    /**
     * 본문에 PHP 치명 오류·서버 경로가 새지 않았는지 확인합니다.
     *
     * @param  string  $body  응답 본문
     * @return void 반환값 없음
     */
    private function assertNoFatalLeak(string $body): void
    {
        $this->assertStringNotContainsStringIgnoringCase('fatal error', $body);
        $this->assertStringNotContainsString('autoload', $body);
        $this->assertStringNotContainsString($this->root, $body);
    }

    /**
     * 디렉터리를 하위까지 지웁니다.
     *
     * @param  string  $path  지울 경로
     * @return void 반환값 없음
     */
    private function removeTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() && ! $item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($path);
    }
}
