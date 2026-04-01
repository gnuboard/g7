<?php

namespace App\Http\Middleware;

use App\Services\GeoIpService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class SetTimezone
{
    /**
     * 애플리케이션 컨테이너에 저장되는 타임존 키
     */
    public const TIMEZONE_KEY = 'user_timezone';

    public function __construct(
        private GeoIpService $geoIpService
    ) {}

    /**
     * 들어오는 요청을 처리하고 사용자 타임존을 설정합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @param  Closure  $next  다음 미들웨어 또는 요청 핸들러
     * @return Response HTTP 응답
     */
    public function handle(Request $request, Closure $next): Response
    {
        $timezone = $this->determineTimezone($request);
        App::instance(self::TIMEZONE_KEY, $timezone);

        return $next($request);
    }

    /**
     * 사용자의 타임존 설정을 결정합니다.
     *
     * 우선순위:
     * 1. 로그인한 사용자의 타임존 설정 (users.timezone)
     * 2. 브라우저 X-Timezone 헤더 (프론트엔드에서 전송)
     * 3. IP 기반 GeoIP 타임존 감지
     * 4. 시스템 기본값 (config('app.default_user_timezone'))
     *
     * 참고: 프론트엔드에서는 Intl.DateTimeFormat().resolvedOptions().timeZone을
     *       사용하여 브라우저 타임존을 X-Timezone 헤더로 전송할 수 있습니다.
     *
     * @param  Request  $request  HTTP 요청
     * @return string 결정된 타임존 (예: 'Asia/Seoul', 'UTC')
     */
    private function determineTimezone(Request $request): string
    {
        $supportedTimezones = config('app.supported_timezones', ['Asia/Seoul', 'UTC']);

        // 1. 인증된 사용자의 타임존 설정 확인 (최우선)
        if (Auth::check() && Auth::user()->timezone) {
            $userTimezone = Auth::user()->timezone;
            if (in_array($userTimezone, $supportedTimezones)) {
                return $userTimezone;
            }
        }

        // 2. X-Timezone 헤더 확인 (브라우저 타임존)
        $browserTimezone = $request->header('X-Timezone');
        if ($browserTimezone && in_array($browserTimezone, $supportedTimezones)) {
            return $browserTimezone;
        }

        // 3. IP 기반 GeoIP 타임존 감지
        $geoTimezone = $this->geoIpService->getTimezoneByIp(
            $request->ip(),
            $supportedTimezones
        );
        if ($geoTimezone) {
            return $geoTimezone;
        }

        // 4. 기본값 반환
        return config('app.default_user_timezone', 'Asia/Seoul');
    }

    /**
     * 현재 요청에 설정된 타임존을 가져옵니다.
     *
     * 미들웨어가 실행되지 않은 경우 기본값을 반환합니다.
     *
     * @return string 현재 타임존
     */
    public static function getTimezone(): string
    {
        if (App::bound(self::TIMEZONE_KEY)) {
            return App::make(self::TIMEZONE_KEY);
        }

        return config('app.default_user_timezone', 'Asia/Seoul');
    }
}
