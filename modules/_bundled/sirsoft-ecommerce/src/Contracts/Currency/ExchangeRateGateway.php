<?php

namespace Modules\Sirsoft\Ecommerce\Contracts\Currency;

use Modules\Sirsoft\Ecommerce\Contracts\Currency\Data\CurrencySettings;
use Modules\Sirsoft\Ecommerce\Contracts\Currency\Data\ExchangeRateApplyResult;
use Modules\Sirsoft\Ecommerce\Exceptions\ExchangeRateSaveException;

/**
 * 환율 반영 공개 계약
 *
 * 환율 제공 확장(수출입은행 등)이 통화 설정을 읽고, 받아 온 환율을 쇼핑몰 통화 설정에 반영할 때 쓴다. 설정 서비스는
 * 이커머스 내부라 다른 확장이 직접 쓰지 않는다. 서비스 컨테이너로 주입받는다(`app(ExchangeRateGateway::class)`).
 *
 * @since 1.4.0
 */
interface ExchangeRateGateway
{
    /** 계약 판 — 메서드 추가는 판을 올리지 않는다(구현은 이커머스만) */
    public const VERSION = 1;

    /**
     * 현재 통화 설정을 읽습니다(기본값 병합·읽기 시점 보강 포함).
     *
     * @return CurrencySettings 통화 설정
     */
    public function getCurrencySettings(): CurrencySettings;

    /**
     * 제공자가 받아 온 환율을 통화 설정에 반영합니다.
     *
     * `$source` 가 현재 선택된 환율 제공자와 다르면 아무것도 저장하지 않고 빈 결과를 돌려줍니다. 바뀐 통화가 있을 때만
     * 저장하고 `sirsoft-ecommerce.currency.after_apply_rates` 액션을 발화합니다.
     *
     * @param  array<string, float|int|string>  $rates  통화 코드 => 기준 통화 기준 환율
     * @param  string  $source  제공자 id
     * @return ExchangeRateApplyResult 반영 결과
     *
     * @throws ExchangeRateSaveException 설정 저장 실패
     */
    public function applyExchangeRates(array $rates, string $source): ExchangeRateApplyResult;
}
