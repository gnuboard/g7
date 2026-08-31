<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 주문 배송행의 배송유형 코드 컬럼 폭을 배송유형 카탈로그(`ecommerce_shipping_types.code`, 50)와 맞춘다.
 *
 * `2026_08_29_000001_widen_cancel_reason_code_columns` 가 사유 카탈로그 축에서 한 것과
 * 같은 정비다 — 그 스윕이 사유 코드만 훑어 이 카탈로그가 빠져 있었다.
 *
 * `ecommerce_order_shippings.shipping_type` 은 컬럼 주석이 Enum 을 가리키던 시절의 30 폭
 * 그대로인데, 지금 이 값을 쓰는 것은 운영자가 설정 화면에서 만드는 배송유형 카탈로그의
 * `code` 이고 그 규칙은 `max:50` 이다. 31~50자 코드를 만들면 strict 모드에서는 주문 생성이
 * `Data too long` 500 이 되고, 비-strict 면 잘린 코드가 저장된다. 잘린 뒤에는
 * `countByShippingType()` 이 원본 코드로 세어 0건이 되므로 **사용 중인 배송유형이 삭제
 * 가드를 통과해 지워지고**, 목록의 배송방법 라벨도 카탈로그 조회에 실패해 빈칸이 된다.
 */
return new class extends Migration
{
    /**
     * 컬럼 주석 (폭만 바꾸고 의미는 그대로 둔다 — `change()` 는 미지정 속성을 떨어뜨린다).
     */
    private const COMMENT = '배송유형 코드 (ecommerce_shipping_types.code 참조)';

    private const TABLE = 'ecommerce_order_shippings';

    private const COLUMN = 'shipping_type';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->resize(50);
    }

    /**
     * Reverse the migrations.
     *
     * 31자 이상 코드가 이미 기록돼 있으면 되돌리기가 그 값을 자른다 — 되돌릴 이유가 있다면
     * 먼저 그런 행이 없는지 확인해야 한다.
     */
    public function down(): void
    {
        $this->resize(30);
    }

    /**
     * 컬럼 폭을 바꾼다 — 테이블·컬럼이 없거나 이미 그 폭이면 건너뛴다(재실행 안전).
     *
     * @param  int  $length  목표 폭
     */
    private function resize(int $length): void
    {
        if (! Schema::hasTable(self::TABLE) || ! Schema::hasColumn(self::TABLE, self::COLUMN)) {
            return;
        }

        $current = collect(Schema::getColumns(self::TABLE))->firstWhere('name', self::COLUMN);
        if (is_array($current) && ($current['type'] ?? '') === "varchar({$length})") {
            return;
        }

        $nullable = is_array($current) && ($current['nullable'] ?? false);

        Schema::table(self::TABLE, function (Blueprint $blueprint) use ($length, $nullable) {
            $definition = $blueprint->string(self::COLUMN, $length)->comment(self::COMMENT);

            if ($nullable) {
                $definition->nullable();
            }

            $definition->change();
        });
    }
};
