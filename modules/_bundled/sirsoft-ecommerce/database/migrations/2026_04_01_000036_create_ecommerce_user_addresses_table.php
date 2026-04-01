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
        Schema::create('ecommerce_user_addresses', function (Blueprint $table) {
            $table->id()->comment('배송지 ID');
            $table->unsignedBigInteger('user_id');
            $table->string('name', 100)->comment('배송지 별칭');
            $table->string('recipient_name', 100)->comment('수령인 이름');
            $table->string('recipient_phone', 30)->comment('수령인 연락처 (국제 형식 포함)');
            $table->string('country_code', 2)->default('KR')->comment('국가 코드 (ISO 3166-1 alpha-2: KR, US, JP, DE 등)');
            $table->string('zipcode', 10)->nullable()->comment('우편번호 (국내)');
            $table->string('address', 255)->nullable()->comment('기본주소 (국내)');
            $table->string('address_detail', 255)->nullable()->comment('상세주소 (국내)');
            $table->string('address_line_1', 255)->nullable()->comment('해외 주소 1 (Street address)');
            $table->string('address_line_2', 255)->nullable()->comment('해외 주소 2 (Apt, Suite, Unit 등)');
            $table->string('city', 100)->nullable()->comment('도시 (City)');
            $table->string('state', 100)->nullable()->comment('주/지역 (State/Province/Region)');
            $table->string('postal_code', 20)->nullable()->comment('우편번호 (해외)');
            $table->boolean('is_default')->default(false)->comment('기본 배송지 여부');
            $table->timestamps();

            $table->index('user_id', 'ecommerce_user_addresses_user_id_index');
            $table->index(['user_id', 'is_default'], 'ecommerce_user_addresses_user_default_index');
            $table->index('country_code', 'ecommerce_user_addresses_country_code_index');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `".DB::getTablePrefix()."ecommerce_user_addresses` COMMENT '사용자 저장 배송지 정보 (국내/해외 지원)'");
        }

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('ecommerce_user_addresses')) {
            Schema::dropIfExists('ecommerce_user_addresses');
        }
    }
};
