<?php

declare(strict_types=1);

namespace Modules\Sirsoft\Ecommerce\Helpers;

use Illuminate\Http\Request;

/**
 * 결제 디바이스 판별 헬퍼
 *
 * payment_device 컬럼에 저장되는 값(`pc` / `mobile`)을 PG 플러그인 공통으로
 * 일관되게 산출. User-Agent 키워드 기반 판별.
 *
 * 4개 PG (nhnkcp/kginicis/nicepayments/tosspayments) 가 각자 동일 로직을 복제하던
 * `detectDevice()` private 메서드를 본 헬퍼로 통합.
 */
class DeviceDetector
{
    /**
     * 모바일 User-Agent 키워드
     *
     * @var array<int, string>
     */
    private const MOBILE_UA_KEYWORDS = ['Mobile', 'Android', 'iPhone', 'iPad', 'iPod'];

    /**
     * iOS User-Agent 키워드 (iPhone/iPad/iPod)
     *
     * @var array<int, string>
     */
    private const IOS_UA_KEYWORDS = ['iPhone', 'iPad', 'iPod'];

    /**
     * Request 의 User-Agent 로 결제 디바이스 판별
     *
     * @param  Request  $request  유입 요청
     * @return string 'pc' 또는 'mobile'
     */
    public static function detect(Request $request): string
    {
        return self::detectFromUserAgent($request->userAgent() ?? '');
    }

    /**
     * 임의 User-Agent 문자열에서 결제 디바이스 판별
     *
     * @param  string  $userAgent  User-Agent 헤더 값
     * @return string 'pc' 또는 'mobile'
     */
    public static function detectFromUserAgent(string $userAgent): string
    {
        foreach (self::MOBILE_UA_KEYWORDS as $keyword) {
            if (stripos($userAgent, $keyword) !== false) {
                return 'mobile';
            }
        }

        return 'pc';
    }

    /**
     * User-Agent 문자열이 iOS 기기(iPhone/iPad/iPod)인지 판별
     *
     * 애플페이는 iOS 기기에서만 결제 가능하므로, 체크아웃 결제수단 목록에서
     * 애플페이 노출 여부를 정하는 데 쓰인다. 데스크탑 UA 를 보내는 iPadOS 는
     * 이 판정에서 false 가 될 수 있으며, 그 경우 클라이언트(maxTouchPoints)가 보정한다.
     *
     * @param  string  $userAgent  User-Agent 헤더 값
     * @return bool iOS 기기 여부
     */
    public static function isIosFromUserAgent(string $userAgent): bool
    {
        foreach (self::IOS_UA_KEYWORDS as $keyword) {
            if (stripos($userAgent, $keyword) !== false) {
                return true;
            }
        }

        return false;
    }
}
