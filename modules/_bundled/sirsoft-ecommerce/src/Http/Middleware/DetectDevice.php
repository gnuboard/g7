<?php

namespace Modules\Sirsoft\Ecommerce\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Modules\Sirsoft\Ecommerce\Helpers\DeviceDetector;
use Symfony\Component\HttpFoundation\Response;

/**
 * 요청 User-Agent 로 iOS 기기 여부를 결정해 컨테이너에 보관하는 미들웨어.
 *
 * SetTimezone / ResolveShippingCountry 패턴(App::instance + static accessor)을 미러한다.
 * web 그룹(SSR 셸 렌더 경로)에 적용되며, InjectAppConfigDeviceListener 가 코어의
 * `core.frontend.filter_app_config` 필터 훅에서 이 값을 읽어 appConfig.isIos 로 주입한다.
 * 프론트는 window.G7Config.appConfig → _global.appConfig.isIos 로 받는다.
 *
 * 서버 UA 판정은 데스크탑 UA 를 보내는 iPadOS 를 놓칠 수 있으므로, 템플릿 부트스트랩이
 * 클라이언트 신호(maxTouchPoints/userAgentData)로 이를 보정한다.
 */
class DetectDevice
{
    /**
     * 애플리케이션 컨테이너에 저장되는 iOS 여부 키
     */
    public const IS_IOS_KEY = 'ecommerce_request_is_ios';

    /**
     * 들어오는 요청의 iOS 여부를 판별해 컨테이너에 보관합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @param  Closure  $next  다음 미들웨어 또는 요청 핸들러
     * @return Response HTTP 응답
     */
    public function handle(Request $request, Closure $next): Response
    {
        App::instance(
            self::IS_IOS_KEY,
            DeviceDetector::isIosFromUserAgent($request->userAgent() ?? '')
        );

        return $next($request);
    }

    /**
     * 현재 요청이 iOS 기기인지 반환합니다.
     *
     * 미들웨어가 실행되지 않은 컨텍스트에서는 false(애플페이 기본 숨김 — fail-safe)를 반환합니다.
     *
     * @return bool iOS 기기 여부
     */
    public static function isIos(): bool
    {
        if (App::bound(self::IS_IOS_KEY)) {
            return (bool) App::make(self::IS_IOS_KEY);
        }

        return false;
    }
}
