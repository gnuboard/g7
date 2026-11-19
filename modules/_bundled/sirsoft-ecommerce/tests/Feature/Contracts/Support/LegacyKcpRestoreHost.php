<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Contracts\Support;

use Plugins\Sirsoft\PayNhnkcp\Concerns\RecordsPaymentWindowClosure;

/**
 * NHN KCP 현행 재결제 복구 트레이트를 담는 녹화 전용 호스트 (#916)
 *
 * 트레이트가 있을 때만 불러온다(RetryRestoreCharacterizationRecorderTest 가 먼저 확인한다).
 */
final class LegacyKcpRestoreHost
{
    use RecordsPaymentWindowClosure;
}
