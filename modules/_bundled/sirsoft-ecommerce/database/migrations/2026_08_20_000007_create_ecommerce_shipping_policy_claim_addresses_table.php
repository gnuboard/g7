<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 배송정책별 클레임 회수지 테이블을 생성합니다.
 *
 * 배송정책 × 클레임 유형(교환/반품)마다 회수지를 하나씩 둔다. 신청 시점에
 * 클레임 헤더의 return_address_snapshot 으로 복사되므로, 이후 정책이 바뀌어도
 * 이미 접수된 건의 회수지는 흔들리지 않는다.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 부분 실패 뒤 재실행이나 이미 만들어진 설치본에서 "Base table already exists" 로 멈추지 않게 한다.
        if (Schema::hasTable('ecommerce_shipping_policy_claim_addresses')) {
            return;
        }

        Schema::create('ecommerce_shipping_policy_claim_addresses', function (Blueprint $table) {
            $table->id()->comment('클레임 회수지 ID');
            $table->unsignedBigInteger('shipping_policy_id')->comment('배송정책 ID');
            $table->string('claim_type', 20)->comment('클레임 유형 (ClaimTypeEnum — exchange: 교환, return: 반품)');
            $table->string('recipient_name', 100)->comment('회수지 수취인명');
            $table->string('phone', 20)->nullable()->comment('회수지 연락처');
            $table->string('zipcode', 10)->nullable()->comment('회수지 우편번호');
            $table->string('address1', 255)->comment('회수지 기본주소');
            $table->string('address2', 255)->nullable()->comment('회수지 상세주소');
            $table->string('country_code', 10)->default('KR')->comment('회수지 국가코드');
            $table->timestamps();

            // 테이블명이 길어 자동 생성 인덱스명이 MySQL identifier 한도(64자)를 넘으므로
            // 짧은 이름을 명시한다 (country_settings 테이블과 동일 규율).
            $table->unique(['shipping_policy_id', 'claim_type'], 'uniq_policy_claim_type');
            $table->index('claim_type', 'idx_ca_claim_type');
            $table->foreign('shipping_policy_id', 'fk_ca_shipping_policy_id')
                ->references('id')->on('ecommerce_shipping_policies')->cascadeOnDelete();

            $table->comment('배송정책별 클레임 회수지');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('ecommerce_shipping_policy_claim_addresses')) {
            Schema::dropIfExists('ecommerce_shipping_policy_claim_addresses');
        }
    }
};
