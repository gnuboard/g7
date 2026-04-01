<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Models;

use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderTaxInvoiceFactory;
use Modules\Sirsoft\Ecommerce\Enums\TaxInvoiceStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderTaxInvoice;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * OrderTaxInvoice 모델 테스트
 */
class OrderTaxInvoiceTest extends ModuleTestCase
{
    public function test_order_tax_invoice_can_be_created(): void
    {
        $order = OrderFactory::new()->create();
        $taxInvoice = OrderTaxInvoiceFactory::new()->forOrder($order)->create([
            'company_number' => '123-45-67890',
            'company_name' => '테스트 회사',
        ]);

        $this->assertDatabaseHas('ecommerce_order_tax_invoices', [
            'id' => $taxInvoice->id,
            'order_id' => $order->id,
            'company_number' => '123-45-67890',
        ]);
    }

    public function test_order_tax_invoice_belongs_to_order(): void
    {
        $order = OrderFactory::new()->create();
        $taxInvoice = OrderTaxInvoiceFactory::new()->forOrder($order)->create();

        $this->assertInstanceOf(Order::class, $taxInvoice->order);
        $this->assertEquals($order->id, $taxInvoice->order->id);
    }

    public function test_order_tax_invoice_casts_status_to_enum(): void
    {
        $order = OrderFactory::new()->create();
        $taxInvoice = OrderTaxInvoiceFactory::new()->forOrder($order)->pending()->create();

        $this->assertInstanceOf(TaxInvoiceStatusEnum::class, $taxInvoice->invoice_status);
        $this->assertEquals(TaxInvoiceStatusEnum::PENDING, $taxInvoice->invoice_status);
    }

    public function test_pending_tax_invoice_factory_state(): void
    {
        $order = OrderFactory::new()->create();
        $taxInvoice = OrderTaxInvoiceFactory::new()->forOrder($order)->pending()->create();

        $this->assertEquals(TaxInvoiceStatusEnum::PENDING, $taxInvoice->invoice_status);
        $this->assertNull($taxInvoice->issued_at);
    }

    public function test_issued_tax_invoice_factory_state(): void
    {
        $order = OrderFactory::new()->create();
        $taxInvoice = OrderTaxInvoiceFactory::new()->forOrder($order)->issued()->create();

        $this->assertEquals(TaxInvoiceStatusEnum::ISSUED, $taxInvoice->invoice_status);
        $this->assertNotNull($taxInvoice->issued_at);
        $this->assertNotNull($taxInvoice->invoice_number);
    }

    public function test_cancelled_tax_invoice_factory_state(): void
    {
        $order = OrderFactory::new()->create();
        $taxInvoice = OrderTaxInvoiceFactory::new()->forOrder($order)->cancelled()->create();

        $this->assertEquals(TaxInvoiceStatusEnum::CANCELLED, $taxInvoice->invoice_status);
    }

    public function test_tax_invoice_casts_amounts_to_decimal(): void
    {
        $order = OrderFactory::new()->create();
        $taxInvoice = OrderTaxInvoiceFactory::new()->forOrder($order)->create([
            'supply_amount' => 45000.00,
            'tax_amount' => 4500.00,
            'total_amount' => 49500.00,
        ]);

        $this->assertEquals('45000.00', $taxInvoice->supply_amount);
        $this->assertEquals('4500.00', $taxInvoice->tax_amount);
        $this->assertEquals('49500.00', $taxInvoice->total_amount);
    }

    public function test_tax_invoice_contains_business_info(): void
    {
        $order = OrderFactory::new()->create();
        $taxInvoice = OrderTaxInvoiceFactory::new()->forOrder($order)->create([
            'company_number' => '123-45-67890',
            'company_name' => '주식회사 테스트',
            'ceo_name' => '홍길동',
            'business_type' => '서비스업',
            'business_category' => '소프트웨어',
        ]);

        $this->assertEquals('123-45-67890', $taxInvoice->company_number);
        $this->assertEquals('주식회사 테스트', $taxInvoice->company_name);
        $this->assertEquals('홍길동', $taxInvoice->ceo_name);
        $this->assertEquals('서비스업', $taxInvoice->business_type);
        $this->assertEquals('소프트웨어', $taxInvoice->business_category);
    }

    public function test_order_can_have_multiple_tax_invoices(): void
    {
        $order = OrderFactory::new()->create();
        OrderTaxInvoiceFactory::new()->forOrder($order)->count(2)->create();

        $this->assertCount(2, $order->fresh()->taxInvoices);
    }

    public function test_is_pending_method(): void
    {
        $order = OrderFactory::new()->create();
        $taxInvoice = OrderTaxInvoiceFactory::new()->forOrder($order)->pending()->create();

        $this->assertTrue($taxInvoice->isPending());
        $this->assertFalse($taxInvoice->isIssued());
        $this->assertFalse($taxInvoice->isFailed());
    }

    public function test_is_issued_method(): void
    {
        $order = OrderFactory::new()->create();
        $taxInvoice = OrderTaxInvoiceFactory::new()->forOrder($order)->issued()->create();

        $this->assertTrue($taxInvoice->isIssued());
        $this->assertFalse($taxInvoice->isPending());
        $this->assertFalse($taxInvoice->isFailed());
    }

    public function test_is_failed_method(): void
    {
        $order = OrderFactory::new()->create();
        $taxInvoice = OrderTaxInvoiceFactory::new()->forOrder($order)->failed()->create();

        $this->assertTrue($taxInvoice->isFailed());
        $this->assertFalse($taxInvoice->isPending());
        $this->assertFalse($taxInvoice->isIssued());
    }

    public function test_get_full_address_method(): void
    {
        $order = OrderFactory::new()->create();
        $taxInvoice = OrderTaxInvoiceFactory::new()->forOrder($order)->create([
            'address' => '서울시 강남구 테헤란로 123',
            'address_detail' => '10층',
        ]);

        $this->assertEquals('서울시 강남구 테헤란로 123 10층', $taxInvoice->getFullAddress());
    }

    public function test_get_formatted_company_number_method(): void
    {
        $order = OrderFactory::new()->create();
        $taxInvoice = OrderTaxInvoiceFactory::new()->forOrder($order)->create([
            'company_number' => '1234567890',
        ]);

        $this->assertEquals('123-45-67890', $taxInvoice->getFormattedCompanyNumber());
    }
}
