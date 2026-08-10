<?php

namespace Modules\Sirsoft\Ecommerce\Support;

/**
 * 통화 설정 요청 단위 캐시 (단일 보관소)
 *
 * 통화 설정은 한 요청 안에서 여러 리소스가 반복해서 읽는다. 이전에는 캐시를
 * `HasMultiCurrencyPrices` 트레이트의 `private static` 으로 두었는데, **트레이트의 static 은
 * 사용 클래스마다 별개의 사본**이라 실제로는 캐시가 20벌 존재했다. 그래서
 *
 * - 요청당 설정 파일 읽기가 클래스 수만큼 반복되고,
 * - 초기화 메서드를 호출해도 그 클래스의 사본만 비워져 나머지는 그대로 남았다.
 *
 * 후자는 테스트에서 드러났다 — 앞선 테스트가 어떤 리소스 클래스의 사본을 채워 두면
 * 뒤 테스트가 그 값을 그대로 물려받아, 단독으로는 통과하는 테스트가 함께 돌리면
 * 실패했다(기본 통화 소수 자릿수가 달라져 금액 타입이 int ↔ float 로 갈렸다).
 *
 * 캐시를 이 클래스 하나로 모아 "비우면 전부 비워지는" 상태로 만든다.
 */
class CurrencySettingsCache
{
    /**
     * 캐시된 통화 목록 (미조회 시 null).
     */
    private static ?array $currencies = null;

    /**
     * 통화 설정 목록을 반환합니다. (최초 1회만 설정을 읽고 이후 캐시)
     *
     * @return array 통화 설정 배열
     */
    public static function currencies(): array
    {
        if (self::$currencies === null) {
            $settings = g7_module_settings('sirsoft-ecommerce', 'language_currency');
            self::$currencies = $settings['currencies'] ?? [];
        }

        return self::$currencies;
    }

    /**
     * 캐시를 비웁니다. (설정 변경 후 / 테스트 격리)
     */
    public static function clear(): void
    {
        self::$currencies = null;
    }
}
