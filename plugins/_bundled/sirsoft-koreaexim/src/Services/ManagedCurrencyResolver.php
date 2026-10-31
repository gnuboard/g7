<?php

namespace Plugins\Sirsoft\Koreaexim\Services;

use App\Helpers\TimezoneHelper;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Plugins\Sirsoft\Koreaexim\Enums\RateType;
use Plugins\Sirsoft\Koreaexim\Enums\RunStatus;
use Plugins\Sirsoft\Koreaexim\Repositories\Contracts\RateRunRepositoryInterface;

/** 최신 성공 응답으로 관리 범위를 판정합니다. 화면 조회에서는 외부 API를 호출하지 않습니다. */
class ManagedCurrencyResolver
{
    /**
     * 처리에 필요한 의존 객체를 주입합니다.
     *
     * @param  EcommerceSettingsService  $settings  settings 입력값
     * @param  RateRunRepositoryInterface  $runs  runs 입력값
     * @param  ExchangeRateCalculator  $calculator  calculator 입력값
     */
    public function __construct(private EcommerceSettingsService $settings, private RateRunRepositoryInterface $runs, private ExchangeRateCalculator $calculator) {}

    /**
     * 최신 성공 스냅샷으로 현재 관리 대상 통화를 계산합니다.
     *
     * @return array 처리 결과
     */
    public function resolve(): array
    {
        $last = $this->runs->latestSuccessful();
        $currency = $this->settings->getSettings('language_currency');
        $result = ['managed_codes' => [], 'unsupported_codes' => [], 'notice_key' => null,
            'last_updated_at' => TimezoneHelper::toUserTimezone($last?->finished_at), 'last_updated_at_formatted' => TimezoneHelper::toUserDateTimeString($last?->finished_at)];
        if (! plugin_setting('sirsoft-koreaexim', 'auth_key', '')) {
            $result['notice_key'] = 'auth_key_required';
        } elseif (! plugin_setting('sirsoft-koreaexim', 'auto_manage', false)) {
            $result['notice_key'] = 'auto_manage_disabled';
        } elseif ($last !== null) {
            $calculation = $this->calculator->calculate($last->raw_response, $currency['currencies'], $currency['default_currency'],
                RateType::tryFrom(plugin_setting('sirsoft-koreaexim', 'rate_type', 'deal_bas_r')) ?? RateType::DealBaseRate,
                (int) plugin_setting('sirsoft-koreaexim', 'rate_decimal_places', 6));
            $result['managed_codes'] = $calculation['managed_codes'];
            $result['unsupported_codes'] = $calculation['unsupported_codes'];
            $result['notice_key'] = $calculation['status'] === RunStatus::UnsupportedBase ? 'unsupported_base' : null;
        }

        return $result;
    }
}
