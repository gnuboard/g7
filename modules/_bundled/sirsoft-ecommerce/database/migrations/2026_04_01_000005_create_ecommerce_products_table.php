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
        Schema::create('ecommerce_products', function (Blueprint $table) {
            $table->id()->comment('상품 ID');
            $table->text('name')->comment('상품명 (다국어 JSON: {ko: "...", en: "..."})');
            $table->string('product_code', 50)->comment('상품코드');
            $table->string('sales_product_code', 50)->nullable()->comment('판매자 상품코드 (사용자 입력용)');
            $table->string('sku', 100)->nullable()->comment('SKU');
            $table->unsignedBigInteger('brand_id')->nullable()->comment('브랜드 ID');
            $table->unsignedBigInteger('list_price')->default(0)->comment('정가 (원)');
            $table->unsignedBigInteger('selling_price')->default(0)->comment('판매가 (원)');
            $table->string('currency_code', 10)->default('KRW')->comment('통화 코드 (저장 시 기본통화 기준)');
            $table->integer('stock_quantity')->default(0)->comment('재고 수량 (옵션 있으면 옵션 합계)');
            $table->unsignedInteger('safe_stock_quantity')->default(0)->comment('안전재고 수량');
            $table->string('sales_status', 20)->default('on_sale')->comment('판매상태: on_sale(판매중), suspended(판매중지), sold_out(품절), coming_soon(출시예정)');
            $table->string('display_status', 20)->default('visible')->comment('전시상태: visible(전시), hidden(숨김)');
            $table->string('tax_status', 20)->default('taxable')->comment('과세여부: taxable(과세), tax_free(면세)');
            $table->decimal('tax_rate', 5, 2)->default(10.00)->comment('세율 (%)');
            $table->unsignedBigInteger('shipping_policy_id')->nullable()->comment('배송정책 ID');
            $table->unsignedBigInteger('common_info_id')->nullable()->comment('공통정보 템플릿 ID');
            $table->unsignedInteger('min_purchase_qty')->default(1)->comment('최소 구매 수량');
            $table->unsignedInteger('max_purchase_qty')->default(0)->comment('최대 구매 수량 (0=무제한)');
            $table->string('purchase_restriction', 20)->default('none')->comment('구매 제한: none(없음), restricted(제한)');
            $table->text('allowed_roles')->nullable()->comment('구매 허용 역할 ID 배열');
            $table->mediumText('description')->nullable()->comment('상세 설명 (다국어 JSON, HTML 포함)');
            $table->string('description_mode', 10)->default('text')->comment('설명 모드: text(텍스트), html(HTML)');
            $table->string('meta_title', 200)->nullable()->comment('SEO 제목');
            $table->text('meta_description')->nullable()->comment('SEO 설명');
            $table->text('meta_keywords')->nullable()->comment('SEO 키워드 (배열)');
            $table->string('barcode', 50)->nullable()->comment('바코드');
            $table->string('hs_code', 20)->nullable()->comment('HS 코드 (관세 분류)');
            $table->boolean('has_options')->default(false)->comment('옵션 사용 여부');
            $table->mediumText('option_groups')->nullable()->comment('옵션 그룹 정의: [{name: "색상", values: ["빨강", "파랑"]}]');
            $table->unsignedBigInteger('created_by')->nullable()->comment('생성자 ID');
            $table->unsignedBigInteger('updated_by')->nullable()->comment('수정자 ID');
            $table->timestamps();
            $table->softDeletes()->comment('소프트 삭제 일시');

            $table->unique('product_code');
            $table->index('product_code');
            $table->index('sku');
            $table->index('sales_status');
            $table->index('display_status');
            $table->index('brand_id');
            $table->index('created_at');
            $table->index(['sales_status', 'display_status']);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `".DB::getTablePrefix()."ecommerce_products` COMMENT '상품 정보'");
        }

        // brand_id FK (brands table created in 000003)
        Schema::table('ecommerce_products', function (Blueprint $table) {
            $table->foreign('brand_id')->references('id')->on('ecommerce_brands')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('ecommerce_products')) {
            Schema::table('ecommerce_products', function (Blueprint $table) {
                if (Schema::hasColumn('ecommerce_products', 'brand_id')) {
                    $table->dropForeign(['brand_id']);
                }
            });
            Schema::dropIfExists('ecommerce_products');
        }
    }
};
