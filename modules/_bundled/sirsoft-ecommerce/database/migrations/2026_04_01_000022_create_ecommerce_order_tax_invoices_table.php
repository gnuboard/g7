<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ecommerce_order_tax_invoices', function (Blueprint $table) {
            $table->id()->comment('세금계산서 ID');
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('payment_id')->nullable();
            $table->string('invoice_status', 30)->comment('발급 상태 (TaxInvoiceStatusEnum)');
            $table->string('company_name', 255)->comment('사업자 상호');
            $table->string('company_number', 20)->comment('사업자번호 (000-00-00000)');
            $table->string('ceo_name', 100)->nullable()->comment('대표자명');
            $table->string('business_type', 100)->nullable()->comment('업종');
            $table->string('business_category', 100)->nullable()->comment('업태');
            $table->string('zipcode', 10)->nullable()->comment('우편번호');
            $table->string('address', 500)->nullable()->comment('주소');
            $table->string('address_detail', 255)->nullable()->comment('상세주소');
            $table->string('manager_name', 100)->nullable()->comment('담당자명');
            $table->string('manager_email', 255)->comment('세금계산서 이메일');
            $table->string('manager_phone', 20)->nullable()->comment('담당자 전화번호');
            $table->decimal('supply_amount', 12, 2)->comment('공급가액');
            $table->decimal('tax_amount', 12, 2)->comment('세액');
            $table->decimal('total_amount', 12, 2)->comment('합계금액');
            $table->string('invoice_number', 50)->nullable()->comment('세금계산서 번호 (발급 후 기록)');
            $table->text('invoice_url')->nullable()->comment('세금계산서 URL');
            $table->timestamp('requested_at')->comment('요청일시');
            $table->timestamp('issued_at')->nullable()->comment('발급일시');
            $table->timestamps();

            $table->index('order_id', 'ecommerce_order_tax_invoices_order_id_index');
            $table->index('payment_id', 'ecommerce_order_tax_invoices_payment_id_index');
            $table->index('invoice_status', 'ecommerce_order_tax_invoices_invoice_status_index');
            $table->index('company_number', 'ecommerce_order_tax_invoices_company_number_index');
            $table->foreign('order_id')->references('id')->on('ecommerce_orders')->cascadeOnDelete();
            $table->foreign('payment_id')->references('id')->on('ecommerce_order_payments')->nullOnDelete();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `".DB::getTablePrefix()."ecommerce_order_tax_invoices` COMMENT '주문 세금계산서 정보'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('ecommerce_order_tax_invoices')) {
            Schema::dropIfExists('ecommerce_order_tax_invoices');
        }
    }
};
