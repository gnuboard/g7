<?php

namespace App\Http\Controllers\Api\Base;

use App\Contracts\Extension\CacheInterface;
use Illuminate\Support\Facades\Log;

/**
 * 공개 API용 베이스 컨트롤러
 *
 * 인증이 필요하지 않은 공개 API 컨트롤러가 상속받아야 하는 기본 클래스입니다.
 * 캐싱, 속도 제한, API 사용량 추적 등의 기능을 제공합니다.
 */
abstract class PublicBaseController extends BaseApiController
{
    public function __construct()
    {
        // 공개 API는 속도 제한 없음 (무제한 접근 허용)
    }

    /**
     * 캐시된 응답을 반환하거나 새로 생성합니다.
     *
     * 주의: 콜백 반환값이 배열이고 `error` 키가 있으면 캐시하지 않는다.
     * 이는 확장 설치 직후·활성화 전 등의 일시적 상태에서 얻은 "not found" 같은
     * 에러 응답이 영구 캐시되어 복구 후에도 잘못된 응답을 반환하는 문제를 방지한다.
     *
     * @param  string  $key  캐시 키
     * @param  callable  $callback  데이터 생성 콜백
     * @param  int  $ttl  캐시 유지 시간 (초)
     * @return mixed
     */
    protected function cached(string $key, callable $callback, int $ttl = 3600)
    {
        $cache = app(CacheInterface::class);

        $cached = $cache->get($key);
        if ($cached !== null) {
            return $cached;
        }

        $result = $callback();

        // 에러 응답은 캐시하지 않음 (일시적 상태 회복 가능성 보장)
        if (! (is_array($result) && isset($result['error']))) {
            $cache->put($key, $result, $ttl);
        }

        return $result;
    }

    /**
     * API 사용량을 기록합니다.
     *
     * @param  string  $endpoint  엔드포인트
     * @param  array  $data  관련 데이터
     * @return void
     */
    protected function logApiUsage(string $endpoint, array $data = []): void
    {
        // TODO: API 사용량 통계 시스템 구현
        //
        // 호출 지점은 레이아웃·확장 번들·정적 자산처럼 페이지 로드마다 여러 번 열리는
        // 공개 엔드포인트다. 통계 시스템이 서기 전까지 이 자리가 요청마다 로그 파일에
        // 줄을 쌓지 않도록, 디버그 모드에서만 기록한다. 기본 설치는 APP_DEBUG=false 이므로
        // 아무것도 쓰지 않는다.
        if (! config('app.debug')) {
            return;
        }

        Log::debug("Public API Usage: {$endpoint}", [
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'data' => $data,
            'timestamp' => now(),
        ]);
    }

    /**
     * 클라이언트 정보를 가져옵니다.
     *
     * @return array
     */
    protected function getClientInfo(): array
    {
        return [
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'referer' => request()->header('referer'),
            'timestamp' => now(),
        ];
    }
}
