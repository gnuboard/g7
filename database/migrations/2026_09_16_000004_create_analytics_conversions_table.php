<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 방문자 통계 전환 원장 테이블을 생성합니다.
     *
     * 확장이 등록한 목표(주문 완료·가입 등)가 달성될 때마다 1행입니다. 확장을 데이터까지
     * 지우며 제거하면 extension_identifier 로 이 표의 행을 찾아 함께 지웁니다.
     *
     * @return void
     */
    public function up(): void
    {
        if (Schema::hasTable('analytics_conversions')) {
            return;
        }

        Schema::create('analytics_conversions', function (Blueprint $table) {
            $table->id()->comment('ID');
            $table->unsignedBigInteger('visit_id')->comment('방문 ID');
            $table->char('visitor_key', 32)->comment('방문자 키 사본');
            $table->string('goal_key', 100)->comment('목표 키 (확장식별자.목표 또는 core.목표)');
            $table->string('extension_type', 10)->nullable()->comment('목표를 등록한 확장 종류 (module, plugin)');
            $table->string('extension_identifier', 100)->nullable()->comment('목표를 등록한 확장 식별자');
            $table->string('label', 255)->nullable()->comment('전환 표시 라벨 (주문번호 등)');
            $table->decimal('revenue', 15, 2)->nullable()->comment('전환 금액');
            $table->char('currency', 3)->nullable()->comment('통화 코드');
            $table->dateTime('occurred_at')->comment('발생 시각 (UTC)');
            $table->json('custom_dimensions')->nullable()->comment('확장이 넣은 커스텀 차원 값');
            $table->timestamp('created_at')->useCurrent()->comment('생성 시각');

            $table->index(['goal_key', 'occurred_at'], 'idx_analytics_conversions_goal');
            $table->index('visit_id', 'idx_analytics_conversions_visit');
            $table->index('extension_identifier', 'idx_analytics_conversions_ext');
        });
    }

    /**
     * 방문자 통계 전환 원장 테이블을 삭제합니다.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('analytics_conversions');
    }
};
