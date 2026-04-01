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
        Schema::create('ecommerce_order_addresses', function (Blueprint $table) {
            $table->id()->comment('배송지 ID');
            $table->unsignedBigInteger('order_id');
            $table->string('address_type', 20)->comment('주소 유형 (shipping: 배송지, billing: 청구지)');
            $table->string('orderer_name', 100)->comment('주문자 이름');
            $table->string('orderer_phone', 20)->comment('주문자 휴대폰');
            $table->string('orderer_email', 255)->nullable()->comment('주문자 이메일');
            $table->string('recipient_name', 100)->comment('수령인 이름');
            $table->string('recipient_phone', 20)->comment('수령인 휴대폰');
            $table->string('recipient_email', 255)->nullable()->comment('수령인 이메일');
            $table->string('recipient_country_code', 2)->nullable()->comment('국가 코드 (ISO 3166-1 alpha-2: KR, US 등)');
            $table->string('recipient_province_code', 10)->nullable()->comment('시/도 코드 (행정구역 코드)');
            $table->string('recipient_city', 100)->nullable()->comment('도시 (국제 주소 호환)');
            $table->string('zipcode', 10)->comment('우편번호');
            $table->string('address', 500)->comment('기본 주소');
            $table->string('address_detail', 255)->nullable()->comment('상세 주소');
            $table->string('address_line_1', 255)->nullable()->comment('해외 주소 1 (Street address)');
            $table->string('address_line_2', 255)->nullable()->comment('해외 주소 2 (Apt, Suite 등)');
            $table->string('intl_city', 100)->nullable()->comment('도시 (City) - 해외배송용');
            $table->string('intl_state', 100)->nullable()->comment('주/지역 (State/Province) - 해외배송용');
            $table->string('intl_postal_code', 20)->nullable()->comment('우편번호 (해외)');
            $table->string('address_type_code', 10)->nullable()->comment('주소 유형 코드 (J: 지번, R: 도로명)');
            $table->text('delivery_memo')->nullable()->comment('배송 메모');
            $table->timestamps();

            $table->index('order_id', 'ecommerce_order_addresses_order_id_index');
            $table->index('orderer_name', 'ecommerce_order_addresses_orderer_name_index');
            $table->index('recipient_name', 'ecommerce_order_addresses_recipient_name_index');
            $table->index('recipient_country_code', 'ecommerce_order_addresses_recipient_country_index');
            $table->foreign('order_id')->references('id')->on('ecommerce_orders')->cascadeOnDelete();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `".DB::getTablePrefix()."ecommerce_order_addresses` COMMENT '주문 배송지 정보'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('ecommerce_order_addresses')) {
            Schema::dropIfExists('ecommerce_order_addresses');
        }
    }
};
