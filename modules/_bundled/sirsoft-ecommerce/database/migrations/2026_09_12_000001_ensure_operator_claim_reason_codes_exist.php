<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 운영자·시스템 발 취소가 쓰는 클레임 사유 코드를 기존 설치본에도 보장합니다. (#599 후속 일원화)
 *
 * 취소·환불이 클레임 접수 한 입구로 모이면서 **사유 코드가 실행 경로의 전제**가 됐다.
 * `OrderRefundRequestService::resolveReason()` 은 코드를 못 찾으면 `reasonUnavailable()` 을
 * 던지고, 그 예외는 접수 트랜잭션 전체를 되돌린다. 사람이 고르는 사유는 화면이 목록에서
 * 가져오므로 문제가 없지만, **사람이 고르지 않는 두 코드**는 코드가 직접 지목한다:
 *
 *   - `admin_cancel` — 운영자·시스템 발 취소의 정본 사유(`OrderCancellationService::OPERATOR_CANCEL_REASON_CODE`).
 *     결제 플러그인의 가상계좌 환불처럼 사유를 자유 문장으로 넘기는 호출부도 이 코드로 접수된다.
 *   - `auto_cancel_expired` — 만료 주문 자동취소 커맨드가 넘기는 코드.
 *
 * 두 코드는 `ClaimReasonSeeder` 에 있지만 **시더는 설치 때만 돈다**(`ModuleManager::installModule`
 * 한 곳에서만 `runModuleSeeders()` 를 부른다 — 업데이트 경로에는 없다). 그래서 1.3.0 이전에
 * 설치된 상점은 이 행이 없는 채로 새 코드를 받게 되고, 그러면:
 *
 *   - 만료 주문 자동취소가 **전량 실패**하고(스케줄러라 아무도 모른다),
 *   - 가상계좌 환불은 **PG 로 돈이 나간 뒤** 장부 기록만 롤백되어 어떤 문서로도 남지 않는다.
 *
 * 그래서 스키마가 아니라 데이터를 보장한다. 시더와 같은 값으로 넣되 **이미 있으면 건드리지
 * 않는다** — 운영자가 이름·정렬을 고쳤을 수 있고, 그것을 시더 기본값으로 되돌릴 이유가 없다.
 */
return new class extends Migration
{
    /**
     * 대상 테이블.
     */
    private string $table = 'ecommerce_claim_reasons';

    /**
     * 보장할 사유 — 값은 `ClaimReasonSeeder::getDefaults()` 의 같은 코드와 일치한다.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $reasons = [
        [
            'type' => 'refund',
            'code' => 'admin_cancel',
            'name' => '{"ko":"관리자 취소","en":"Admin Cancel"}',
            'fault_type' => 'seller',
            'is_user_selectable' => 0,
            'sort_order' => 5,
        ],
        [
            'type' => 'refund',
            'code' => 'auto_cancel_expired',
            'name' => '{"ko":"결제 기한 만료","en":"Payment Deadline Expired"}',
            'fault_type' => 'customer',
            'is_user_selectable' => 0,
            'sort_order' => 6,
        ],
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable($this->table)) {
            return;
        }

        $now = now();

        foreach ($this->reasons as $reason) {
            $exists = DB::table($this->table)
                ->where('type', $reason['type'])
                ->where('code', $reason['code'])
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table($this->table)->insert($reason + [
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     *
     * **쓰이고 있는 사유는 지우지 않는다.** 이 두 코드는 실행 경로가 이름으로 지목하는 값이라
     * (만료 자동취소 커맨드 · 운영자 발 취소 접수), 참조가 남은 채로 지우면 그 경로들이 접수
     * 단계에서 422 로 막힌다 — 가상계좌 환불은 **PG 로 돈이 나간 뒤** 장부만 롤백된다.
     *
     * 그래서 이 행을 실제로 가리키는 취소·환불 문서가 하나도 없을 때만 되돌린다. 운영자가
     * 이름·정렬을 고쳤을 수 있지만 판정 기준은 참조 유무이지 내용이 아니다 — 참조가 없으면
     * `up()` 이 넣은 그 행이고, 있으면 지우면 안 되는 행이다.
     */
    public function down(): void
    {
        if (! Schema::hasTable($this->table)) {
            return;
        }

        foreach ($this->reasons as $reason) {
            if ($this->isReferenced($reason['code'])) {
                continue;
            }

            DB::table($this->table)
                ->where('type', $reason['type'])
                ->where('code', $reason['code'])
                ->delete();
        }
    }

    /**
     * 이 사유 코드를 가리키는 취소·환불 문서가 있는지 판정합니다.
     *
     * @param  string  $code  사유 코드
     * @return bool 참조가 있으면 true
     */
    private function isReferenced(string $code): bool
    {
        foreach (['ecommerce_order_cancels' => 'cancel_reason_type', 'ecommerce_order_refunds' => 'reason_code'] as $table => $column) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            if (DB::table($table)->where($column, $code)->exists()) {
                return true;
            }
        }

        return false;
    }
};
