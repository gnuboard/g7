<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Upgrades;

use App\Extension\AbstractUpgradeStep;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Enums\CashReceiptType;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use Modules\Sirsoft\Ecommerce\Upgrades\Upgrade_1_1_0;
use PHPUnit\Framework\Attributes\Test;

/**
 * Upgrade_1_1_0 — cash_receipt_type 레거시 값 정규화 테스트
 *
 * KG이니시스가 기록해 온 income_deduction/expenditure_proof 를 CashReceiptType Enum
 * SSoT 값(income/expense)으로 정규화한다.
 */
class NormalizeCashReceiptTypeTest extends ModuleTestCase
{
    /**
     * 지정한 cash_receipt_type 을 가진 결제 행을 생성합니다.
     */
    private function makePaymentWithType(?string $type): OrderPayment
    {
        $order = Order::factory()->create();

        $payment = OrderPayment::factory()->create([
            'order_id' => $order->id,
            'payment_method' => PaymentMethodEnum::DBANK,
        ]);

        // Enum 캐스트를 우회해 레거시 원시값을 직접 심는다.
        DB::table('ecommerce_order_payments')
            ->where('id', $payment->id)
            ->update(['cash_receipt_type' => $type]);

        return $payment;
    }

    /**
     * 결제 행의 cash_receipt_type 원시값을 조회합니다.
     */
    private function rawType(OrderPayment $payment): ?string
    {
        return DB::table('ecommerce_order_payments')
            ->where('id', $payment->id)
            ->value('cash_receipt_type');
    }

    private function runUpgrade(): void
    {
        $step = new Upgrade_1_1_0;
        $context = (new UpgradeContext(
            fromVersion: '1.0.1',
            toVersion: '1.1.0',
            logChannel: 'extension-upgrade',
        ))->withCurrentStep('1.1.0');

        $step->run($context);
    }

    #[Test]
    public function 업그레이드_스텝은_abstract_upgrade_step_을_상속한다(): void
    {
        // g7_version(">=7.0.0") 이 7.0.0-beta.5 이상이므로 상속 의무가 발동한다.
        $this->assertInstanceOf(AbstractUpgradeStep::class, new Upgrade_1_1_0);
    }

    #[Test]
    public function income_deduction_을_income_으로_정규화한다(): void
    {
        $payment = $this->makePaymentWithType('income_deduction');

        $this->runUpgrade();

        $this->assertSame('income', $this->rawType($payment));
    }

    #[Test]
    public function expenditure_proof_를_expense_로_정규화한다(): void
    {
        $payment = $this->makePaymentWithType('expenditure_proof');

        $this->runUpgrade();

        $this->assertSame('expense', $this->rawType($payment));
    }

    #[Test]
    public function 이미_정규화된_값은_변경하지_않는다(): void
    {
        $income = $this->makePaymentWithType('income');
        $expense = $this->makePaymentWithType('expense');

        $this->runUpgrade();

        $this->assertSame('income', $this->rawType($income));
        $this->assertSame('expense', $this->rawType($expense));
    }

    #[Test]
    public function null_값은_그대로_둔다(): void
    {
        $payment = $this->makePaymentWithType(null);

        $this->runUpgrade();

        $this->assertNull($this->rawType($payment));
    }

    #[Test]
    public function 두_번_실행해도_동일한_결과다(): void
    {
        $legacy = $this->makePaymentWithType('income_deduction');

        $this->runUpgrade();
        $this->runUpgrade();

        $this->assertSame('income', $this->rawType($legacy));
    }

    #[Test]
    public function 레거시_값이_남아있어도_결제_모델을_읽을_수_있다(): void
    {
        // KG이니시스 플러그인은 S5 에서 제거되기 전까지 income_deduction 을 계속 기록한다.
        // cash_receipt_type 을 Enum 캐스트하면 Laravel 이 ::from() 으로 ValueError 를 던져
        // 해당 행을 읽을 수 없게 되고, 업그레이드 스텝 자신도 그 행을 읽어야 한다.
        $payment = $this->makePaymentWithType('income_deduction');

        $reloaded = OrderPayment::find($payment->id);

        $this->assertSame('income_deduction', $reloaded->cash_receipt_type, '캐스트 없이 원시값으로 읽힌다');
        $this->assertSame(CashReceiptType::INCOME, $reloaded->getCashReceiptType(), 'fromLegacy 로 안전하게 해석');
    }

    #[Test]
    public function 정규화_후에도_동일한_해석_결과를_유지한다(): void
    {
        $payment = $this->makePaymentWithType('expenditure_proof');

        $this->runUpgrade();

        $reloaded = OrderPayment::find($payment->id);

        $this->assertSame('expense', $reloaded->cash_receipt_type);
        $this->assertSame(CashReceiptType::EXPENSE, $reloaded->getCashReceiptType());
    }
}
