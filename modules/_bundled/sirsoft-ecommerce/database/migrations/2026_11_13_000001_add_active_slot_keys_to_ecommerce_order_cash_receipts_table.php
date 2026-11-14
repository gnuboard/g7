<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/*
 * comment 문자열·코드에 모듈 Enum 클래스를 참조하지 않는다.
 * `php artisan migrate` 는 모듈 오토로더가 등록되지 않은 상태에서도 실행될 수 있어
 * (신규 설치, 모듈 비활성 상태 등) Enum 클래스 해석에 실패한다. 상태·유형 값은 문자열 상수로 쓴다.
 */
return new class extends Migration
{
    /** 대상 테이블 (프리픽스 제외) */
    private const TABLE = 'ecommerce_order_cash_receipts';

    /** 주문당 활성 발급 1건 유니크 인덱스명 */
    private const ACTIVE_ISSUE_UNIQUE = 'ecommerce_order_cash_receipts_active_issue_order_unique';

    /** 발급 행당 열린 취소 1건 유니크 인덱스명 */
    private const OPEN_CANCEL_UNIQUE = 'ecommerce_order_cash_receipts_open_cancel_target_unique';

    /** 오래된 처리 중 이력을 실패로 정리할 때 남기는 에러코드 */
    private const STALE_ERROR_CODE = 'IN_PROGRESS_STALE';

    /**
     * 현금영수증 이력에 「주문당 활성 발급 1건」·「발급 행당 열린 취소 1건」 제약을 겁니다. (KVE-2026-3191, U1)
     *
     * 발급 서비스는 활성 영수증을 잠금 없이 먼저 조회한 뒤 외부 PG 를 부르고 나서 이력을 INSERT 했다. 같은 주문에
     * 발급 요청이 겹치면 두 요청 모두 「활성 없음」 을 보고 PG 에 각각 발급해 영수증이 2건 생겼다. 취소·재발급
     * 경로도 같은 모양이었다. 이제 서비스는 PG 호출 전에 처리 중(IN_PROGRESS) 행을 먼저 INSERT 해 자리를
     * 예약하고, 그 자리는 이 마이그레이션이 만드는 생성 컬럼의 유니크 인덱스가 지킨다.
     *
     * - `slot_released_at`: 발급 행이 쥔 활성 슬롯을 놓은 시각 (취소 완료·키 없는 완료·과거 중복 정리)
     * - `cancel_target_id`: 취소 행이 취소하는 발급 행 ID
     * - `active_issue_order_key`(VIRTUAL): 발급·처리중/완료·미해제 행만 order_id → UNIQUE
     * - `open_cancel_target_key`(VIRTUAL): 취소·처리중/완료 행만 cancel_target_id → UNIQUE
     *
     * MySQL 유니크 인덱스는 NULL 중복을 허용하므로 조건 밖 행은 제약을 받지 않는다(선례
     * `2026_08_21_000001_add_unique_purchase_earn_lot_*`).
     *
     * 백필은 제약을 걸기 전에만 돈다 — 제약이 이미 있는 설치본에서 다시 실행되면 진행 중인 예약 행을
     * 실패로 바꿔 버리기 때문이다.
     *
     * @return void 반환값 없음
     */
    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        if (! Schema::hasColumn(self::TABLE, 'slot_released_at')) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->timestamp('slot_released_at')->nullable()
                    ->comment('활성 발급 슬롯 해제 일시 (발급 행 전용 — 취소 완료·영수증 키 없는 완료·과거 중복 정리 시 기록, NULL 이면 슬롯 보유)');
            });
        }

        if (! Schema::hasColumn(self::TABLE, 'cancel_target_id')) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->unsignedBigInteger('cancel_target_id')->nullable()
                    ->comment('취소 대상 발급 이력 ID (취소 행 전용, 발급 행은 NULL)');
            });
        }

        if (! Schema::hasIndex(self::TABLE, self::ACTIVE_ISSUE_UNIQUE) || ! Schema::hasIndex(self::TABLE, self::OPEN_CANCEL_UNIQUE)) {
            $this->backfill();
        }

        if (! Schema::hasColumn(self::TABLE, 'active_issue_order_key')) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->unsignedBigInteger('active_issue_order_key')->nullable()
                    ->virtualAs("CASE WHEN `transaction_type` = 'issue' AND `issue_status` IN ('IN_PROGRESS', 'COMPLETED') AND `slot_released_at` IS NULL THEN `order_id` ELSE NULL END")
                    ->comment('주문당 활성 발급 1건 강제용 파생 키 (발급·처리중/완료·슬롯 미해제 행만 주문 ID, 그 외 NULL)');
            });
        }

        if (! Schema::hasColumn(self::TABLE, 'open_cancel_target_key')) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->unsignedBigInteger('open_cancel_target_key')->nullable()
                    ->virtualAs("CASE WHEN `transaction_type` = 'cancel' AND `issue_status` IN ('IN_PROGRESS', 'COMPLETED') THEN `cancel_target_id` ELSE NULL END")
                    ->comment('발급 행당 열린 취소 1건 강제용 파생 키 (취소·처리중/완료 행만 취소 대상 ID, 그 외 NULL)');
            });
        }

        if (! Schema::hasIndex(self::TABLE, self::ACTIVE_ISSUE_UNIQUE)) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->unique('active_issue_order_key', self::ACTIVE_ISSUE_UNIQUE);
            });
        }

        if (! Schema::hasIndex(self::TABLE, self::OPEN_CANCEL_UNIQUE)) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->unique('open_cancel_target_key', self::OPEN_CANCEL_UNIQUE);
            });
        }
    }

    /**
     * 유니크 인덱스와 생성 컬럼만 제거합니다.
     *
     * 실컬럼(`slot_released_at`, `cancel_target_id`)은 남긴다 — 백필로 채운 취소 대상·해제 시각은 다시
     * 계산할 수 없는 이력이라, 롤백 뒤 재적용 때도 그대로 쓰인다.
     *
     * @return void 반환값 없음
     */
    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        foreach ([self::ACTIVE_ISSUE_UNIQUE, self::OPEN_CANCEL_UNIQUE] as $index) {
            if (Schema::hasIndex(self::TABLE, $index)) {
                Schema::table(self::TABLE, function (Blueprint $table) use ($index) {
                    $table->dropUnique($index);
                });
            }
        }

        foreach (['active_issue_order_key', 'open_cancel_target_key'] as $column) {
            if (Schema::hasColumn(self::TABLE, $column)) {
                Schema::table(self::TABLE, function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }

    /**
     * 제약을 걸기 전에 기존 이력을 새 규칙에 맞춰 정리합니다.
     *
     * 순서가 의미를 가진다 — 처리 중 행을 먼저 실패로 정리해야 「열린 취소」 판정이 완료 행만 보게 되고,
     * 취소 대상을 먼저 채워야 취소된 발급 행의 슬롯을 놓을 수 있으며, 그 뒤에 남은 슬롯 보유 행만이
     * 과거 중복 판정 대상이 된다.
     *
     * @return void 반환값 없음
     */
    private function backfill(): void
    {
        $this->failStaleInProgress();
        $this->assignCancelTargets();
        $this->releaseCancelledIssues();
        $this->releaseKeylessCompletedIssues();
        $this->releaseDuplicateSlotHolders();
    }

    /**
     * 남아 있는 처리 중(IN_PROGRESS) 이력을 실패(IN_PROGRESS_STALE)로 정리합니다.
     *
     * 예약 행 방식 도입 전에는 처리 중 상태로 저장하는 경로가 없었다. 남아 있다면 PG 응답을 끝내 받지 못한
     * 행이므로, 자동 재시도 없이 실패로 두어 관리자가 PG 화면을 확인한 뒤 재발급하게 한다.
     *
     * @return void 반환값 없음
     */
    private function failStaleInProgress(): void
    {
        DB::table(self::TABLE)
            ->where('issue_status', 'IN_PROGRESS')
            ->update([
                'issue_status' => 'FAILED',
                'error_code' => self::STALE_ERROR_CODE,
                'error_message' => $this->staleMessage(),
                'updated_at' => now(),
            ]);
    }

    /**
     * 취소 행마다 취소 대상 발급 행을 채웁니다.
     *
     * 대상 = 같은 주문·같은 영수증 키·더 작은 ID 의 최신 발급 행. 같은 발급 행을 가리키는 완료 취소가 둘 이상이면
     * (예약 행 도입 전 취소 경쟁의 흔적) 가장 먼저 기록된 취소만 대상을 갖고, 나머지는 대상을 비워 두고
     * warning 로그로 남긴다 — 열린 취소 유니크 제약을 걸 수 있어야 하기 때문이다.
     *
     * @return void 반환값 없음
     */
    private function assignCancelTargets(): void
    {
        DB::table(self::TABLE)
            ->where('transaction_type', 'cancel')
            ->whereNull('cancel_target_id')
            ->whereNotNull('receipt_key')
            ->where('receipt_key', '!=', '')
            ->select(['id', 'order_id', 'receipt_key', 'issue_status'])
            ->chunkById(500, function ($cancels) {
                foreach ($cancels as $cancel) {
                    $targetId = DB::table(self::TABLE)
                        ->where('order_id', $cancel->order_id)
                        ->where('transaction_type', 'issue')
                        ->where('receipt_key', $cancel->receipt_key)
                        ->where('id', '<', $cancel->id)
                        ->max('id');

                    if ($targetId === null) {
                        continue;
                    }

                    if ($this->isOpenStatus((string) $cancel->issue_status)) {
                        $holder = DB::table(self::TABLE)
                            ->where('transaction_type', 'cancel')
                            ->where('cancel_target_id', $targetId)
                            ->whereIn('issue_status', ['IN_PROGRESS', 'COMPLETED'])
                            ->value('id');

                        if ($holder !== null) {
                            Log::warning('[ecommerce] 현금영수증 발급 1건에 완료 취소가 여러 건 — 먼저 기록된 취소만 취소 대상으로 연결', [
                                'order_id' => (int) $cancel->order_id,
                                'issue_receipt_id' => (int) $targetId,
                                'linked_cancel_id' => (int) $holder,
                                'unlinked_cancel_id' => (int) $cancel->id,
                            ]);

                            continue;
                        }
                    }

                    DB::table(self::TABLE)
                        ->where('id', $cancel->id)
                        ->update(['cancel_target_id' => (int) $targetId]);
                }
            });
    }

    /**
     * 완료된 취소가 가리키는 발급 행의 슬롯을 놓습니다. 해제 시각은 그 취소 이력의 기록 시각입니다.
     *
     * @return void 반환값 없음
     */
    private function releaseCancelledIssues(): void
    {
        DB::table(self::TABLE)
            ->where('transaction_type', 'cancel')
            ->where('issue_status', 'COMPLETED')
            ->whereNotNull('cancel_target_id')
            ->select(['id', 'cancel_target_id', 'created_at'])
            ->chunkById(500, function ($cancels) {
                foreach ($cancels as $cancel) {
                    DB::table(self::TABLE)
                        ->where('id', $cancel->cancel_target_id)
                        ->where('transaction_type', 'issue')
                        ->whereNull('slot_released_at')
                        ->update(['slot_released_at' => $cancel->created_at ?? now()]);
                }
            });
    }

    /**
     * 영수증 키 없이 완료된 발급 행의 슬롯을 놓습니다.
     *
     * 키가 없으면 취소 대상을 특정할 수 없어 화면에서도 활성으로 보지 않는다(`filterActive`). 그런 행이 슬롯을
     * 쥐고 있으면 다시 발급할 수 없게 되므로 놓는다.
     *
     * @return void 반환값 없음
     */
    private function releaseKeylessCompletedIssues(): void
    {
        DB::table(self::TABLE)
            ->where('transaction_type', 'issue')
            ->where('issue_status', 'COMPLETED')
            ->whereNull('slot_released_at')
            ->where(function ($query) {
                $query->whereNull('receipt_key')->orWhere('receipt_key', '');
            })
            ->update(['slot_released_at' => now()]);
    }

    /**
     * 슬롯을 쥔 발급 행이 둘 이상인 주문은 최신 1건만 남기고 나머지 슬롯을 놓습니다.
     *
     * 행은 지우지 않는다 — 국세청 신고 근거 원장이고, 화면에서는 여전히 활성으로 보여 관리자가 취소할 수 있다.
     * PG 측에 실제로 2건이 발급됐을 수 있으므로 주문·행 ID 를 warning 로그로 남겨 수동 취소를 안내한다.
     *
     * 반복 종료는 데이터에 맡기지 않는다 — 한 바퀴에서 놓은 행이 없으면 그 자리에서 멈춘다(진행 없는 반복 차단).
     *
     * @return void 반환값 없음
     */
    private function releaseDuplicateSlotHolders(): void
    {
        while (true) {
            $orderIds = DB::table(self::TABLE)
                ->select('order_id')
                ->where('transaction_type', 'issue')
                ->whereIn('issue_status', ['IN_PROGRESS', 'COMPLETED'])
                ->whereNull('slot_released_at')
                ->groupBy('order_id')
                ->havingRaw('COUNT(*) > 1')
                ->limit(200)
                ->pluck('order_id');

            if ($orderIds->isEmpty()) {
                return;
            }

            $released = 0;

            foreach ($orderIds as $orderId) {
                $holderIds = DB::table(self::TABLE)
                    ->where('order_id', $orderId)
                    ->where('transaction_type', 'issue')
                    ->whereIn('issue_status', ['IN_PROGRESS', 'COMPLETED'])
                    ->whereNull('slot_released_at')
                    ->orderByDesc('id')
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id);

                if ($holderIds->count() < 2) {
                    continue;
                }

                $keptId = $holderIds->shift();
                $releasedIds = $holderIds->values()->all();

                $released += DB::table(self::TABLE)
                    ->whereIn('id', $releasedIds)
                    ->update(['slot_released_at' => now()]);

                Log::warning('[ecommerce] 현금영수증 활성 발급 중복 — 최신 1건만 슬롯 유지, PG 측 중복 발급은 관리자 수동 취소 필요', [
                    'order_id' => (int) $orderId,
                    'kept_receipt_id' => $keptId,
                    'released_receipt_ids' => $releasedIds,
                ]);
            }

            if ($released === 0) {
                return;
            }
        }
    }

    /**
     * 「열린」 상태(처리 중·완료)인지 판정합니다.
     *
     * @param  string  $status  발급 상태 값
     * @return bool 처리 중이거나 완료면 true
     */
    private function isOpenStatus(string $status): bool
    {
        return in_array($status, ['IN_PROGRESS', 'COMPLETED'], true);
    }

    /**
     * 오래된 처리 중 이력에 남길 안내 문구를 반환합니다.
     *
     * 모듈 번역이 로드되지 않은 실행(신규 설치·모듈 비활성)에서는 번역 키가 그대로 돌아오므로 한국어 문구로 대신한다.
     *
     * @return string 안내 문구
     */
    private function staleMessage(): string
    {
        $key = 'sirsoft-ecommerce::cash_receipt.errors.in_progress_stale';
        $message = __($key);

        return is_string($message) && $message !== $key
            ? $message
            : '처리 중 상태로 남은 이력을 실패로 정리했습니다. PG 관리 화면에서 실제 처리 여부를 확인한 뒤 다시 시도하세요.';
    }
};
