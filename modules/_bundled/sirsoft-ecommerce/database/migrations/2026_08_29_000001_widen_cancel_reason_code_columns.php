<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 취소 기록의 사유 코드 컬럼 폭을 사유 카탈로그(`ecommerce_claim_reasons.code`, 50)와 맞춘다.
 *
 * 두 컬럼은 "ecommerce_claim_reasons.code 참조" 라고 스스로 주석하면서 폭이 30 이었다.
 * 운영자가 31~50자 코드를 만들면(설정·REST 모두 `max:50`) 즉시취소 시 strict 모드에서는
 * `Data too long` 500, 비-strict 면 잘린 코드가 저장된다. 잘린 뒤에는 `countByCancelReasonType()`
 * 이 원본 코드로 세어 0건이 되므로 **사용 중인 사유가 삭제 가드를 통과해 지워진다**.
 * 교환·반품의 `reason_code` 는 이미 50 이다 — 환불→취소 축만 남아 있었다.
 */
return new class extends Migration
{
    /**
     * 컬럼별 대상 테이블과 주석.
     *
     * @var array<string, array{column: string, comment: string}>
     */
    private const TARGETS = [
        'ecommerce_order_cancels' => [
            'column' => 'cancel_reason_type',
            'comment' => '취소 사유 코드 (ecommerce_claim_reasons.code 참조)',
        ],
        'ecommerce_order_options' => [
            'column' => 'cancel_reason',
            'comment' => '취소 사유 코드 (ecommerce_claim_reasons.code 참조)',
        ],
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (self::TARGETS as $table => $target) {
            $this->resize($table, $target['column'], 50, $target['comment']);
        }
    }

    /**
     * Reverse the migrations.
     *
     * 31자 이상 코드가 이미 기록돼 있으면 되돌리기가 그 값을 자른다 — 되돌릴 이유가 있다면
     * 먼저 그런 행이 없는지 확인해야 한다.
     */
    public function down(): void
    {
        foreach (self::TARGETS as $table => $target) {
            $this->resize($table, $target['column'], 30, $target['comment']);
        }
    }

    /**
     * 컬럼 폭을 바꾼다 — 테이블·컬럼이 없거나 이미 그 폭이면 건너뛴다(재실행 안전).
     *
     * @param  string  $table  테이블명
     * @param  string  $column  컬럼명
     * @param  int  $length  목표 폭
     * @param  string  $comment  컬럼 주석
     */
    private function resize(string $table, string $column, int $length, string $comment): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        $current = collect(Schema::getColumns($table))->firstWhere('name', $column);
        if (is_array($current) && ($current['type'] ?? '') === "varchar({$length})") {
            return;
        }

        $nullable = is_array($current) && ($current['nullable'] ?? false);

        Schema::table($table, function (Blueprint $blueprint) use ($column, $length, $comment, $nullable) {
            $definition = $blueprint->string($column, $length)->comment($comment);

            if ($nullable) {
                $definition->nullable();
            }

            $definition->change();
        });
    }
};
