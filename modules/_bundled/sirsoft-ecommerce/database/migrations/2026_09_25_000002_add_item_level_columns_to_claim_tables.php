<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 클레임 상품 행에 상품 단위 사실 컬럼을, 신청서 헤더에 선교환·판매자 접수·대리 철회 컬럼을 추가합니다.
 *
 * 상품은 따로 움직이는데(상품 행마다 `option_status`, 수량 분할) 사실이 신청서 헤더 한 칸에 저장되던
 * 자리를 상품 행으로 옮긴다.
 *
 *  - 반려 사유(`reject_reason`) — 상품 B 의 반려가 상품 A 의 사유를 덮어썼다.
 *  - 재고 복구 여부(`is_stock_restored`) — 일부만 복구해도 신청서 전체가 「복구함」 이었다.
 *  - 회수지(`return_address_snapshot`) — 배송정책이 다른 상품을 묶으면 두 번째 상품의 회수지가 틀렸다.
 *  - 반품 환불 연결(`refund_id`) — 부분 완료 2회차 환불신청서를 접수할 수 없었다.
 *  - 미회수 완료(`unreturned_at`·`unreturned_reason`) — 선교환에서 헌 물건이 끝내 오지 않은 행.
 *
 * 헤더에는 선교환(`exchange_mode` 등), 운영자 대리 접수(`requested_by_admin`), 운영자 대리
 * 철회(`withdrawn_by_admin`·`withdraw_reason`) 컬럼을 둔다.
 *
 * 컬럼 추가만 하므로 기존 행 백필은 다음 마이그레이션(`move_claim_tracking_to_option_rows`)이 한다.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void 반환값 없음
     */
    public function up(): void
    {
        $this->addOptionColumns('ecommerce_order_exchange_options', withUnreturned: true);
        $this->addOptionColumns('ecommerce_order_return_options', withUnreturned: false);
        $this->addReturnOptionRefundLink();
        $this->addRefundOptionRejectReason();
        $this->addExchangeModeColumns();
        $this->addAdminActorColumns('ecommerce_order_exchanges', 'fk_ex_requested_by_admin', 'fk_ex_withdrawn_by_admin', withRequester: true);
        $this->addAdminActorColumns('ecommerce_order_returns', 'fk_rt_requested_by_admin', 'fk_rt_withdrawn_by_admin', withRequester: true);
        $this->addAdminActorColumns('ecommerce_order_refunds', null, 'fk_rf_withdrawn_by_admin', withRequester: false);
    }

    /**
     * 교환·반품 상품 행에 반려 사유·재고 복구·회수지(·미회수) 컬럼을 추가합니다.
     *
     * @param  string  $table  테이블명
     * @param  bool  $withUnreturned  미회수 컬럼 포함 여부 (선교환이 있는 교환만)
     * @return void 반환값 없음
     */
    private function addOptionColumns(string $table, bool $withUnreturned): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($table, $withUnreturned) {
            if (! Schema::hasColumn($table, 'reject_reason')) {
                $blueprint->text('reject_reason')->nullable()->after('completed_at')
                    ->comment('이 상품의 반려 사유 (상품 단위 반려 — 신청서 헤더 사유는 신청서 전체가 반려될 때만)');
            }
            if (! Schema::hasColumn($table, 'is_stock_restored')) {
                $blueprint->boolean('is_stock_restored')->default(false)->after('reject_reason')
                    ->comment('이 상품의 원 옵션 재고 복구 여부 (1: 복구함, 0: 복구 안 함 — 완료 모달 선택 또는 뒤늦은 [재고 복구])');
            }
            if (! Schema::hasColumn($table, 'return_address_snapshot')) {
                $blueprint->mediumText('return_address_snapshot')->nullable()->after('is_stock_restored')
                    ->comment('이 상품의 회수지 주소 스냅샷 (상품이 적용받은 배송정책의 클레임 회수지 — 접수 시 복사)');
            }
            if ($withUnreturned && ! Schema::hasColumn($table, 'unreturned_at')) {
                $blueprint->timestamp('unreturned_at')->nullable()->after('return_address_snapshot')
                    ->comment('미회수 완료 시각 (선교환에서 헌 물건을 받지 못한 채 완료한 행)');
            }
            if ($withUnreturned && ! Schema::hasColumn($table, 'unreturned_reason')) {
                $blueprint->text('unreturned_reason')->nullable()->after('unreturned_at')
                    ->comment('미회수 완료 사유 (관리자 입력)');
            }
        });
    }

    /**
     * 반품 상품 행에 환불신청서 연결 컬럼을 추가합니다. (회차마다 환불신청서 접수)
     *
     * @return void 반환값 없음
     */
    private function addReturnOptionRefundLink(): void
    {
        $table = 'ecommerce_order_return_options';

        if (! Schema::hasTable($table)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($table) {
            if (! Schema::hasColumn($table, 'refund_id')) {
                $blueprint->foreignId('refund_id')->nullable()->after('return_address_snapshot')
                    ->comment('이 상품을 담아 접수된 환불신청서 ID (완료 회차마다 환불 미연결 상품으로 접수)');
            }
        });

        Schema::table($table, function (Blueprint $blueprint) use ($table) {
            if (! Schema::hasIndex($table, 'ecommerce_order_rt_opts_refund_id_index')) {
                $blueprint->index('refund_id', 'ecommerce_order_rt_opts_refund_id_index');
            }
            if (! $this->hasForeignKey($table, 'fk_rt_opt_refund_id')) {
                $blueprint->foreign('refund_id', 'fk_rt_opt_refund_id')
                    ->references('id')->on('ecommerce_order_refunds')->nullOnDelete();
            }
        });
    }

    /**
     * 환불 상품 행에 반려 사유 컬럼을 추가합니다.
     *
     * @return void 반환값 없음
     */
    private function addRefundOptionRejectReason(): void
    {
        $table = 'ecommerce_order_refund_options';

        if (! Schema::hasTable($table) || Schema::hasColumn($table, 'reject_reason')) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->text('reject_reason')->nullable()
                ->comment('이 상품의 반려 사유 (상품 단위 반려 — 신청서 헤더 사유는 신청서 전체가 반려될 때만)');
        });
    }

    /**
     * 교환 신청서에 진행 방식(일반·선교환)과 회수 기한 컬럼을 추가합니다.
     *
     * @return void 반환값 없음
     */
    private function addExchangeModeColumns(): void
    {
        $table = 'ecommerce_order_exchanges';

        if (! Schema::hasTable($table)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($table) {
            if (! Schema::hasColumn($table, 'exchange_mode')) {
                $blueprint->string('exchange_mode', 20)->default('standard')->after('exchange_status')
                    ->comment('교환 진행 방식 (ExchangeModeEnum — standard: 일반 교환(회수 뒤 발송), advance: 선교환(새 물건 먼저 발송))');
            }
            if (! Schema::hasColumn($table, 'exchange_mode_set_by')) {
                $blueprint->foreignId('exchange_mode_set_by')->nullable()->after('exchange_mode')
                    ->comment('진행 방식을 마지막으로 바꾼 관리자');
            }
            if (! Schema::hasColumn($table, 'exchange_mode_set_at')) {
                $blueprint->timestamp('exchange_mode_set_at')->nullable()->after('exchange_mode_set_by')
                    ->comment('진행 방식을 마지막으로 바꾼 시각');
            }
            if (! Schema::hasColumn($table, 'collection_due_at')) {
                $blueprint->timestamp('collection_due_at')->nullable()->after('exchange_mode_set_at')
                    ->comment('선교환 헌 물건 반송 기한 (교환품 도착 시각 — 없으면 발송 시각 — + 설정 일수)');
            }
            if (! Schema::hasColumn($table, 'collection_overdue_notified_at')) {
                $blueprint->timestamp('collection_overdue_notified_at')->nullable()->after('collection_due_at')
                    ->comment('회수 지연 관리자 알림 발송 시각 (1회만 — 기한이 다시 미래가 되면 비운다)');
            }
        });

        Schema::table($table, function (Blueprint $blueprint) use ($table) {
            if (! Schema::hasIndex($table, 'ecommerce_order_exchanges_mode_due_index')) {
                $blueprint->index(['exchange_mode', 'collection_due_at'], 'ecommerce_order_exchanges_mode_due_index');
            }
            if (! $this->hasForeignKey($table, 'fk_ex_mode_set_by')) {
                $blueprint->foreign('exchange_mode_set_by', 'fk_ex_mode_set_by')
                    ->references('id')->on('users')->nullOnDelete();
            }
        });
    }

    /**
     * 신청서 헤더에 운영자 대리 접수·대리 철회 컬럼을 추가합니다.
     *
     * @param  string  $table  테이블명
     * @param  string|null  $requesterForeignKey  대리 접수자 외래키 이름 (환불은 기존 `refund_source_type=admin` 축을 쓰므로 null)
     * @param  string  $withdrawerForeignKey  대리 철회자 외래키 이름
     * @param  bool  $withRequester  대리 접수자 컬럼 포함 여부
     * @return void 반환값 없음
     */
    private function addAdminActorColumns(string $table, ?string $requesterForeignKey, string $withdrawerForeignKey, bool $withRequester): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($table, $withRequester) {
            if ($withRequester && ! Schema::hasColumn($table, 'requested_by_admin')) {
                $blueprint->foreignId('requested_by_admin')->nullable()->after('requested_by')
                    ->comment('운영자 대리 접수자 — 값이 있으면 판매자 접수, 고객 철회·수정 불가');
            }
            if (! Schema::hasColumn($table, 'withdrawn_by_admin')) {
                $blueprint->foreignId('withdrawn_by_admin')->nullable()->after('withdrawn_at')
                    ->comment('고객 요청으로 대신 철회한 관리자 (NULL 이면 신청자 본인 철회)');
            }
            if (! Schema::hasColumn($table, 'withdraw_reason')) {
                $blueprint->text('withdraw_reason')->nullable()->after('withdrawn_by_admin')
                    ->comment('관리자 대리 철회 사유');
            }
        });

        Schema::table($table, function (Blueprint $blueprint) use ($table, $requesterForeignKey, $withdrawerForeignKey, $withRequester) {
            if ($withRequester && $requesterForeignKey !== null && ! $this->hasForeignKey($table, $requesterForeignKey)) {
                $blueprint->foreign('requested_by_admin', $requesterForeignKey)
                    ->references('id')->on('users')->nullOnDelete();
            }
            if (! $this->hasForeignKey($table, $withdrawerForeignKey)) {
                $blueprint->foreign('withdrawn_by_admin', $withdrawerForeignKey)
                    ->references('id')->on('users')->nullOnDelete();
            }
        });
    }

    /**
     * 지정한 이름의 외래키가 이미 존재하는지 확인합니다.
     *
     * @param  string  $table  테이블명 (프리픽스 없는 논리명)
     * @param  string  $name  외래키 제약 이름
     * @return bool 존재하면 true
     */
    private function hasForeignKey(string $table, string $name): bool
    {
        foreach (Schema::getForeignKeys($table) as $foreignKey) {
            if (($foreignKey['name'] ?? null) === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reverse the migrations.
     *
     * @return void 반환값 없음
     */
    public function down(): void
    {
        $this->dropColumns('ecommerce_order_refunds', ['fk_rf_withdrawn_by_admin' => 'foreign'], ['withdrawn_by_admin', 'withdraw_reason']);
        $this->dropColumns('ecommerce_order_returns', [
            'fk_rt_requested_by_admin' => 'foreign',
            'fk_rt_withdrawn_by_admin' => 'foreign',
        ], ['requested_by_admin', 'withdrawn_by_admin', 'withdraw_reason']);
        $this->dropColumns('ecommerce_order_exchanges', [
            'fk_ex_requested_by_admin' => 'foreign',
            'fk_ex_withdrawn_by_admin' => 'foreign',
            'fk_ex_mode_set_by' => 'foreign',
            'ecommerce_order_exchanges_mode_due_index' => 'index',
        ], [
            'requested_by_admin', 'withdrawn_by_admin', 'withdraw_reason',
            'exchange_mode', 'exchange_mode_set_by', 'exchange_mode_set_at', 'collection_due_at', 'collection_overdue_notified_at',
        ]);
        $this->dropColumns('ecommerce_order_refund_options', [], ['reject_reason']);
        $this->dropColumns('ecommerce_order_return_options', [
            'fk_rt_opt_refund_id' => 'foreign',
            'ecommerce_order_rt_opts_refund_id_index' => 'index',
        ], ['refund_id', 'reject_reason', 'is_stock_restored', 'return_address_snapshot']);
        $this->dropColumns('ecommerce_order_exchange_options', [], [
            'reject_reason', 'is_stock_restored', 'return_address_snapshot', 'unreturned_at', 'unreturned_reason',
        ]);
    }

    /**
     * 외래키·인덱스를 먼저 지우고 컬럼을 지웁니다.
     *
     * @param  string  $table  테이블명
     * @param  array<string, string>  $constraints  제약 이름 => foreign|index
     * @param  array<int, string>  $columns  지울 컬럼
     * @return void 반환값 없음
     */
    private function dropColumns(string $table, array $constraints, array $columns): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($table, $constraints) {
            foreach ($constraints as $name => $kind) {
                if ($kind === 'foreign' && $this->hasForeignKey($table, $name)) {
                    $blueprint->dropForeign($name);
                }
            }
        });

        Schema::table($table, function (Blueprint $blueprint) use ($table, $constraints) {
            foreach ($constraints as $name => $kind) {
                if ($kind === 'index' && Schema::hasIndex($table, $name)) {
                    $blueprint->dropIndex($name);
                }
            }
        });

        $existing = array_values(array_filter($columns, fn (string $column) => Schema::hasColumn($table, $column)));

        if ($existing === []) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($existing) {
            $blueprint->dropColumn($existing);
        });
    }
};
