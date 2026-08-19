<?php

namespace App\Support\DevTools;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;

/**
 * 브라우저 콘솔 로그 수집기 (`POST _boost/browser-logs`).
 *
 * 왜 G7 가 이 엔드포인트를 소유하는가:
 *   원래는 `Laravel\Boost\BoostServiceProvider::boot()` 이 등록했다. 그런데 라우트 캐시가
 *   걸리면 `Router::setCompiledRoutes()` 가 라우트 컬렉션을 **통째로 교체**하고, 이 교체는
 *   `booted` 콜백에서 일어난다. 즉 프로바이더 `boot()` 에서 등록한 라우트는 조건 충족 여부와
 *   무관하게 전부 폐기된다 (프레임워크 자신의 `BroadcastManager::routes()` 는 `routesAreCached()`
 *   가드를 갖고 있으나 boost 에는 없다).
 *
 *   그 결과 `routes/web.php` 의 SPA catch-all(GET 전용)만 남아 POST 가 405 로 튕기고,
 *   `BrowserLogger` 는 `Route::has('boost.browser-logs')` 가 false 라 폴백 리터럴 URL 을
 *   스크립트에 굽는다. fetch 실패는 `.catch` 로 삼켜져 **오류 한 줄 없이 로그 수집만 사라진다.**
 *   G7 라우트 파일에서 직접 등록하면 캐시에 함께 구워져 이 경로가 살아남는다.
 *
 * 로그 채널 결정:
 *   `logging.channels.browser` 는 `config/logging.php` 에 없고 boost 가 런타임에 주입한다.
 *   그 주입은 boost 의 `shouldRun()` 뒤에 있어 testing·비-local·`boost.enabled=false` 에서는
 *   아예 일어나지 않는다. 따라서 채널 존재를 전제하지 않고 없으면 직접 만든다.
 *   반대로 `config/logging.php` 에 채널을 정적 정의하지는 **않는다** — 정의하면 boost 의
 *   `registerBrowserLogger()` 가 `!== null` 조건에 걸려 조용히 스킵되므로 결합이 오히려 는다.
 */
class BrowserLogWriter
{
    /**
     * 브라우저가 전송한 콘솔 로그를 기록합니다.
     *
     * @param  Request  $request  로그 전송 요청
     * @return JsonResponse 처리 결과
     */
    public static function handle(Request $request): JsonResponse
    {
        $logs = $request->input('logs', []);

        // 빈 요청이 browser.log 파일을 만들지 않도록 채널 해석 자체를 미룬다.
        if (! is_array($logs) || $logs === []) {
            return response()->json(['status' => 'logged']);
        }

        $logger = self::channel();

        foreach ($logs as $log) {
            if (! is_array($log)) {
                continue;
            }

            $logger->log(
                self::level($log['type'] ?? 'log'),
                self::buildMessage($log['data'] ?? []),
                [
                    'url' => $log['url'] ?? null,
                    'user_agent' => ($log['userAgent'] ?? null) ?: null,
                    'timestamp' => ($log['timestamp'] ?? null) ?: now()->toIso8601String(),
                ]
            );
        }

        return response()->json(['status' => 'logged']);
    }

    /**
     * 브라우저 로그 타입을 PSR-3 로그 레벨로 변환합니다.
     *
     * boost 원본은 `$log['type']` 이 없으면 `match` 에서 UnhandledMatchError 로 터진다.
     * 여기서는 호출부에서 기본값을 채워 넘긴다.
     *
     * @param  mixed  $type  브라우저 로그 타입
     * @return string PSR-3 로그 레벨
     */
    private static function level(mixed $type): string
    {
        return match ($type) {
            'warn' => 'warning',
            'log', 'table' => 'debug',
            'window_error', 'uncaught_error', 'unhandled_rejection' => 'error',
            'error', 'info', 'notice', 'critical', 'alert', 'emergency' => $type,
            default => 'debug',
        };
    }

    /**
     * 브라우저 로그 data 배열을 사람이 읽을 수 있는 한 줄 메시지로 만듭니다.
     *
     * 중첩 배열(예: `{"message":"...","reason":{"name":"TypeError",...}}`)도 재귀 평탄화한다.
     *
     * @param  mixed  $data  로그 data 페이로드
     * @return string 조립된 메시지
     */
    private static function buildMessage(mixed $data): string
    {
        if (! is_array($data)) {
            return self::stringify($data);
        }

        return implode(' ', array_map(
            static fn (mixed $value): string => is_array($value)
                ? self::buildMessage($value)
                : self::stringify($value),
            $data
        ));
    }

    /**
     * 스칼라/객체 값을 문자열로 변환합니다.
     *
     * @param  mixed  $value  변환할 값
     * @return string 문자열 표현
     */
    private static function stringify(mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            is_numeric($value) => (string) $value,
            is_bool($value) => $value ? 'true' : 'false',
            is_null($value) => 'null',
            default => (string) json_encode($value),
        };
    }

    /**
     * 브라우저 로그 채널을 반환합니다 (미정의 시 즉석 생성).
     *
     * @return LoggerInterface 로그 채널
     */
    private static function channel(): LoggerInterface
    {
        if (config('logging.channels.browser') !== null) {
            return Log::channel('browser');
        }

        // daily + 유한 보존일 — single 드라이버는 로테이션 없이 무한 append 되어
        // browser.log 가 저장소 단일 최대 파일로 자란다 (실측 989MB).
        return Log::build([
            'driver' => 'daily',
            'path' => storage_path('logs/browser.log'),
            'days' => 7,
            'level' => config('logging.level', 'debug'),
        ]);
    }
}
