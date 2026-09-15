<?php

namespace Tests\Feature\DevTools;

use App\Support\DevTools\BrowserLogWriter;
use Illuminate\Http\Request;
use Monolog\Handler\RotatingFileHandler;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/**
 * 브라우저 콘솔 로그 파일 로테이션 회귀 테스트.
 *
 * 배경:
 *   `BrowserLogWriter::channel()` 이 `single` 드라이버로 로거를 만들면
 *   `storage/logs/browser.log` 단일 파일에 로테이션 없이 무한 append 된다 —
 *   개발 도구를 켜 둔 사이트에서 이 파일이 저장소 단일 최대 파일(수백 MB)로
 *   자라도 어떤 경로도 회수하지 않는다. 날짜별 파일 + 유한 보존일을 고정한다.
 *
 * @scenario vector=browser_log_write, actor_permission=debug_gate_enabled
 *
 * @effects browser_logs_rotate_daily_with_finite_retention
 */
class BrowserLogRotationTest extends TestCase
{
    /**
     * 브라우저 로그는 날짜별 파일에 기록되고, 단일 browser.log 에는 더 이상 쓰지 않는다.
     */
    public function test_browser_logs_are_written_to_a_dated_file(): void
    {
        $datedPath = storage_path('logs/browser-'.now()->format('Y-m-d').'.log');
        $singlePath = storage_path('logs/browser.log');

        $datedExistedBefore = file_exists($datedPath);
        // 레거시 단일 파일은 수백 MB 일 수 있다 — 내용을 읽지 않고 크기만 대조한다.
        $singleSizeBefore = file_exists($singlePath) ? filesize($singlePath) : null;

        $marker = 'browser-log-rotation-regression-'.uniqid();

        try {
            $request = Request::create('/_boost/browser-logs', 'POST', [
                'logs' => [
                    ['type' => 'log', 'data' => [$marker], 'url' => 'https://example.test/'],
                ],
            ]);

            BrowserLogWriter::handle($request);

            $this->assertFileExists($datedPath, '브라우저 로그가 날짜별 파일에 기록되지 않았다');
            $this->assertStringContainsString(
                $marker,
                $this->tail($datedPath),
                '날짜별 파일 꼬리에서 이번 기록을 찾지 못했다'
            );

            clearstatcache();
            $singleSizeAfter = file_exists($singlePath) ? filesize($singlePath) : null;
            $this->assertSame(
                $singleSizeBefore,
                $singleSizeAfter,
                '레거시 단일 browser.log 에 여전히 기록이 추가되고 있다'
            );
        } finally {
            if (! $datedExistedBefore && file_exists($datedPath)) {
                @unlink($datedPath);
            }
        }
    }

    /**
     * 로거는 로테이션 핸들러를 쓰고 보존일이 유한해야 한다 (0 = 무제한 금지).
     */
    public function test_browser_log_channel_uses_rotating_handler_with_finite_retention(): void
    {
        $method = new \ReflectionMethod(BrowserLogWriter::class, 'channel');
        /** @var LoggerInterface $logger */
        $logger = $method->invoke(null);

        $handlers = $logger->getLogger()->getHandlers();
        $this->assertNotEmpty($handlers, '로그 핸들러를 확인할 수 없다');

        $rotating = null;
        foreach ($handlers as $handler) {
            if ($handler instanceof RotatingFileHandler) {
                $rotating = $handler;
            }
        }

        $this->assertNotNull($rotating, '브라우저 로그 채널이 로테이션(daily) 핸들러를 쓰지 않는다');

        $maxFiles = new \ReflectionProperty(RotatingFileHandler::class, 'maxFiles');
        $this->assertGreaterThan(
            0,
            $maxFiles->getValue($rotating),
            '보존일이 무제한(0)이다 — 날짜별 파일이 무한 누적된다'
        );
    }

    /**
     * 파일 꼬리(마지막 16KB)를 읽습니다 — 큰 로그 파일 전체 적재 방지.
     *
     * @param  string  $path  대상 파일 경로
     * @return string 꼬리 내용
     */
    private function tail(string $path): string
    {
        $size = (int) filesize($path);
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return '';
        }

        try {
            fseek($handle, max(0, $size - 16384));

            return (string) stream_get_contents($handle);
        } finally {
            fclose($handle);
        }
    }
}
