<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * 주문 무게/부피 컬럼의 단위 주석을 실제 저장 단위(g / cm³)로 정정합니다 (공개 #94).
     * 상품 옵션은 g / cm³ 로 저장되고 주문 생성 시 그 값이 그대로 복사되는데,
     * 주문 테이블 주석만 kg 로 적혀 있어 배송비 계산·외부 연동에서 단위를 잘못 읽는
     * 근거가 되었습니다. 저장된 값은 정확하므로 값 변환 없이 주석만 정정합니다.
     */
    public function up(): void
    {
        $this->applyComments(
            orderWeightComment: '총 무게 (g)',
            orderVolumeComment: '총 부피 (cm³)',
            unitWeightComment: '단위 무게 (g, 주문 시점)',
            unitVolumeComment: '단위 부피 (cm³, 주문 시점)',
            subtotalWeightComment: '무게 소계 (g, unit_weight × quantity)',
            subtotalVolumeComment: '부피 소계 (cm³, unit_volume × quantity)',
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->applyComments(
            orderWeightComment: '총 무게 (kg)',
            orderVolumeComment: '총 부피 (cm³)',
            unitWeightComment: '단위 무게 (kg, 주문 시점)',
            unitVolumeComment: '단위 부피 (cm³, 주문 시점)',
            subtotalWeightComment: '무게 소계 (unit_weight × quantity)',
            subtotalVolumeComment: '부피 소계 (unit_volume × quantity)',
        );
    }

    /**
     * 컬럼 주석을 일괄 적용합니다 (컬럼 정의는 그대로, 주석만 변경).
     *
     * @param  string  $orderWeightComment  orders.total_weight 주석
     * @param  string  $orderVolumeComment  orders.total_volume 주석
     * @param  string  $unitWeightComment  order_options.unit_weight 주석
     * @param  string  $unitVolumeComment  order_options.unit_volume 주석
     * @param  string  $subtotalWeightComment  order_options.subtotal_weight 주석
     * @param  string  $subtotalVolumeComment  order_options.subtotal_volume 주석
     */
    private function applyComments(
        string $orderWeightComment,
        string $orderVolumeComment,
        string $unitWeightComment,
        string $unitVolumeComment,
        string $subtotalWeightComment,
        string $subtotalVolumeComment,
    ): void {
        if (Schema::hasTable('ecommerce_orders')) {
            Schema::table('ecommerce_orders', function (Blueprint $table) use ($orderWeightComment, $orderVolumeComment) {
                $table->decimal('total_weight', 10, 3)->nullable()->comment($orderWeightComment)->change();
                $table->decimal('total_volume', 10, 3)->nullable()->comment($orderVolumeComment)->change();
            });
        }

        if (Schema::hasTable('ecommerce_order_options')) {
            Schema::table('ecommerce_order_options', function (Blueprint $table) use (
                $unitWeightComment,
                $unitVolumeComment,
                $subtotalWeightComment,
                $subtotalVolumeComment
            ) {
                $table->decimal('unit_weight', 10, 3)->nullable()->comment($unitWeightComment)->change();
                $table->decimal('unit_volume', 10, 3)->nullable()->comment($unitVolumeComment)->change();
                $table->decimal('subtotal_weight', 10, 3)->nullable()->comment($subtotalWeightComment)->change();
                $table->decimal('subtotal_volume', 10, 3)->nullable()->comment($subtotalVolumeComment)->change();
            });
        }
    }
};
