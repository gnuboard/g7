<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 클레임(교환/반품/환불) 신설에 따라 기존 테이블을 확장합니다.
 *
 * 1) `ecommerce_order_refunds` — 환불신청서를 클레임 체계로 일반화한다.
 *    발생 출처를 polymorphic(refund_source_type/refund_source_id)으로 전환하고,
 *    신청 주체·사유·귀책·승인/보류/철회 시각을 신청서로서 갖춘다.
 *    기존 order_cancel_id 는 하위호환을 위해 남기고 취소 발 환불에 이중 기록한다.
 * 2) `ecommerce_shipping_policy_country_settings` — 클레임 배송비 산정 모드와
 *    유형별 고정액. 배송비는 국가·통화 문맥과 같은 행에 둔다.
 * 3) `ecommerce_mileage_transactions` — 클레임 추가결제(마일리지) 멱등 축.
 *
 * 추가 컬럼은 전부 nullable 또는 default 를 가져 기존 데이터에 영향을 주지 않는다.
 *
 * 재실행 안전: MySQL 은 DDL 을 트랜잭션으로 되돌리지 않으므로, up() 이 중간에 실패하면
 * 앞부분은 커밋된 채 남고 마이그레이션 기록은 남지 않아 다음 실행이 같은 up() 을 처음부터
 * 다시 돈다. 그래서 컬럼뿐 아니라 **인덱스·외래키까지** 존재 여부를 확인한 뒤 추가한다.
 * 컬럼만 막아 두면 그 다음 단계에서 "Duplicate key name" 으로 영구 실패한다.
 *
 * down() 도 같은 3축(컬럼·인덱스·외래키)을 **각각** 확인한다. 컬럼 존재만으로 인덱스·외래키
 * 드롭까지 묶으면, up() 이 컬럼 생성 뒤 인덱스 단계에서 죽은 설치본에서 down() 이 없는
 * 인덱스를 지우려다 죽어 되돌리기 자체가 막힌다 — up() 이 못 만든 것을 down() 이 있다고
 * 전제하는 셈이라 두 방향의 가드 강도가 어긋나서는 안 된다.
 */
return new class extends Migration
{
    /** `order_cancel_id` 의 새 주석 — 대체 컬럼을 명시한다. */
    private const ORDER_CANCEL_ID_COMMENT = '취소 FK (deprecated — refund_source_type/refund_source_id 로 대체, 취소 발 환불에 이중 기록)';

    /** `order_cancel_id` 의 원래 주석 — 생성 마이그레이션(`2026_04_01_000043`)의 문구. */
    private const ORDER_CANCEL_ID_ORIGINAL_COMMENT = '취소 FK (추후 polymorphic 전환 가능)';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->extendOrderRefunds();
        $this->extendCountrySettings();
        $this->extendMileageTransactions();
    }

    /**
     * 환불 테이블을 클레임 신청서 형태로 확장합니다.
     */
    private function extendOrderRefunds(): void
    {
        $table = 'ecommerce_order_refunds';

        Schema::table($table, function (Blueprint $blueprint) use ($table) {
            if (! Schema::hasColumn($table, 'refund_source_type')) {
                $blueprint->string('refund_source_type', 20)->nullable()->after('order_cancel_id')
                    ->comment('환불 발생 출처 유형 (RefundSourceTypeEnum — cancel: 주문 취소 발, return: 반품 발, exchange: 교환 저가 차액 발, direct: 고객 직접 접수, admin: 운영자 대리 접수)');
            }
            if (! Schema::hasColumn($table, 'refund_source_id')) {
                $blueprint->unsignedBigInteger('refund_source_id')->nullable()->after('refund_source_type')
                    ->comment('환불 발생 출처 ID');
            }
            if (! Schema::hasColumn($table, 'reason_code')) {
                $blueprint->string('reason_code', 50)->nullable()->after('refund_method')
                    ->comment('환불 사유 코드 (ecommerce_claim_reasons.code, type=refund 참조)');
            }
            if (! Schema::hasColumn($table, 'reason_detail')) {
                $blueprint->text('reason_detail')->nullable()->after('reason_code')
                    ->comment('상세 사유 (기타 선택 시)');
            }
            if (! Schema::hasColumn($table, 'fault_type')) {
                $blueprint->string('fault_type', 20)->nullable()->after('reason_detail')
                    ->comment('귀책 구분 스냅샷 (ClaimReasonFaultTypeEnum — customer: 고객 귀책, seller: 판매자 귀책, carrier: 배송사 귀책)');
            }
            if (! Schema::hasColumn($table, 'requested_by')) {
                $blueprint->unsignedBigInteger('requested_by')->nullable()->after('fault_type')
                    ->comment('신청자 (비회원이면 NULL)');
            }
            if (! Schema::hasColumn($table, 'approved_at')) {
                $blueprint->timestamp('approved_at')->nullable()->after('refunded_at')
                    ->comment('승인 시각');
            }
            if (! Schema::hasColumn($table, 'on_hold_at')) {
                $blueprint->timestamp('on_hold_at')->nullable()->after('approved_at')
                    ->comment('보류 시각');
            }
            if (! Schema::hasColumn($table, 'withdrawn_at')) {
                $blueprint->timestamp('withdrawn_at')->nullable()->after('on_hold_at')
                    ->comment('철회 시각');
            }
            if (! Schema::hasColumn($table, 'reject_reason')) {
                $blueprint->text('reject_reason')->nullable()->after('withdrawn_at')
                    ->comment('반려 사유');
            }
        });

        Schema::table($table, function (Blueprint $blueprint) use ($table) {
            if (! Schema::hasIndex($table, 'ecommerce_order_refunds_source_index')) {
                $blueprint->index(['refund_source_type', 'refund_source_id'], 'ecommerce_order_refunds_source_index');
            }
            if (! Schema::hasIndex($table, 'ecommerce_order_refunds_requested_by_index')) {
                $blueprint->index('requested_by', 'ecommerce_order_refunds_requested_by_index');
            }
            if (! $this->hasForeignKey($table, 'fk_rf_requested_by')) {
                $blueprint->foreign('requested_by', 'fk_rf_requested_by')
                    ->references('id')->on('users')->nullOnDelete();
            }
            // 유저 클레임 목록의 기간 필터가 이 컬럼을 범위 조건으로 읽는다. 신규 교환·반품 테이블은
            // 생성 시점에 같은 인덱스를 갖는데 기존 취소·환불 두 테이블에는 없어, 그 두 탭만 풀스캔이었다.
            if (! Schema::hasIndex($table, 'ecommerce_order_refunds_created_at_index')) {
                $blueprint->index('created_at', 'ecommerce_order_refunds_created_at_index');
            }
        });

        if (Schema::hasTable('ecommerce_order_cancels')) {
            Schema::table('ecommerce_order_cancels', function (Blueprint $blueprint) {
                if (! Schema::hasIndex('ecommerce_order_cancels', 'ecommerce_order_cancels_created_at_index')) {
                    $blueprint->index('created_at', 'ecommerce_order_cancels_created_at_index');
                }
            });
        }

        // order_cancel_id 는 유지하되 대체 컬럼을 주석으로 명시한다 (하위호환 이중 기록).
        $this->commentOrderCancelId(self::ORDER_CANCEL_ID_COMMENT);
    }

    /**
     * `ecommerce_order_refunds.order_cancel_id` 의 주석만 바꿉니다 (자료형·NULL 허용은 그대로).
     *
     * 이미 그 주석이면 건너뛴다 — `->change()` 는 매번 ALTER 를 내므로 재실행 때 불필요한
     * DDL 을 만들지 않는다. 외래키가 걸린 컬럼이라 자료형을 바꾸지 않는 것이 전제다.
     *
     * @param  string  $comment  적용할 주석
     * @return void
     */
    private function commentOrderCancelId(string $comment): void
    {
        $table = 'ecommerce_order_refunds';

        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'order_cancel_id')) {
            return;
        }

        $current = collect(Schema::getColumns($table))->firstWhere('name', 'order_cancel_id');
        if (is_array($current) && ($current['comment'] ?? null) === $comment) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($comment) {
            $blueprint->unsignedBigInteger('order_cancel_id')->nullable()->comment($comment)->change();
        });
    }

    /**
     * 배송정책 국가별 설정에 클레임 배송비 축을 추가합니다.
     */
    private function extendCountrySettings(): void
    {
        $table = 'ecommerce_shipping_policy_country_settings';

        Schema::table($table, function (Blueprint $blueprint) use ($table) {
            if (! Schema::hasColumn($table, 'claim_fee_mode')) {
                $blueprint->string('claim_fee_mode', 30)->default('fixed')->after('extra_fee_multiply')
                    ->comment('클레임 배송비 산정 모드 (ClaimShippingFeeModeEnum — fixed: 고정액, original_policy: 원 배송정책 재계산)');
            }
            if (! Schema::hasColumn($table, 'return_fixed_fee')) {
                $blueprint->decimal('return_fixed_fee', 12, 2)->default(0)->after('claim_fee_mode')
                    ->comment('반품 클레임 배송비 고정액 (편도)');
            }
            if (! Schema::hasColumn($table, 'exchange_fixed_fee')) {
                $blueprint->decimal('exchange_fixed_fee', 12, 2)->default(0)->after('return_fixed_fee')
                    ->comment('교환 클레임 배송비 고정액 (왕복)');
            }
        });
    }

    /**
     * 마일리지 거래에 클레임 멱등 축 2종(추가결제 환급 · 클레임 환불 발 환급)을 추가합니다.
     */
    private function extendMileageTransactions(): void
    {
        $table = 'ecommerce_mileage_transactions';

        Schema::table($table, function (Blueprint $blueprint) use ($table) {
            if (! Schema::hasColumn($table, 'claim_payment_id')) {
                $blueprint->unsignedBigInteger('claim_payment_id')->nullable()->after('order_cancel_id')
                    ->comment('관련 클레임 추가결제 ID (마일리지 결제/환급 멱등 기준)');
            }
            // 클레임 환불 실행(S6b)의 사용 마일리지 환급 멱등 축 — 취소 발 복원의 order_cancel_id 와
            // 같은 역할을 클레임 발 환불에서 담당한다. 이 브랜치가 처음 도입하는 미공개 마이그레이션이라
            // 별도 마이그레이션 없이 제자리에 추가한다 (§4-9 미릴리스 제자리 정정 선례).
            if (! Schema::hasColumn($table, 'order_refund_id')) {
                $blueprint->unsignedBigInteger('order_refund_id')->nullable()->after('claim_payment_id')
                    ->comment('관련 주문 환불 ID (클레임 환불 실행의 사용 마일리지 환급 멱등 기준)');
            }
        });

        Schema::table($table, function (Blueprint $blueprint) use ($table) {
            if (! Schema::hasIndex($table, 'ecommerce_mileage_trx_claim_payment_index')) {
                $blueprint->index('claim_payment_id', 'ecommerce_mileage_trx_claim_payment_index');
            }
            if (! $this->hasForeignKey($table, 'fk_mt_claim_payment_id')) {
                $blueprint->foreign('claim_payment_id', 'fk_mt_claim_payment_id')
                    ->references('id')->on('ecommerce_claim_payments')->nullOnDelete();
            }
            if (! Schema::hasIndex($table, 'ecommerce_mileage_trx_order_refund_index')) {
                $blueprint->index('order_refund_id', 'ecommerce_mileage_trx_order_refund_index');
            }
            if (! $this->hasForeignKey($table, 'fk_mt_order_refund_id')) {
                $blueprint->foreign('order_refund_id', 'fk_mt_order_refund_id')
                    ->references('id')->on('ecommerce_order_refunds')->nullOnDelete();
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
     */
    public function down(): void
    {
        if (Schema::hasTable('ecommerce_mileage_transactions')) {
            $table = 'ecommerce_mileage_transactions';

            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                if ($this->hasForeignKey($table, 'fk_mt_claim_payment_id')) {
                    $blueprint->dropForeign('fk_mt_claim_payment_id');
                }
                if (Schema::hasIndex($table, 'ecommerce_mileage_trx_claim_payment_index')) {
                    $blueprint->dropIndex('ecommerce_mileage_trx_claim_payment_index');
                }
                if (Schema::hasColumn($table, 'claim_payment_id')) {
                    $blueprint->dropColumn('claim_payment_id');
                }
                if ($this->hasForeignKey($table, 'fk_mt_order_refund_id')) {
                    $blueprint->dropForeign('fk_mt_order_refund_id');
                }
                if (Schema::hasIndex($table, 'ecommerce_mileage_trx_order_refund_index')) {
                    $blueprint->dropIndex('ecommerce_mileage_trx_order_refund_index');
                }
                if (Schema::hasColumn($table, 'order_refund_id')) {
                    $blueprint->dropColumn('order_refund_id');
                }
            });
        }

        if (Schema::hasTable('ecommerce_shipping_policy_country_settings')) {
            Schema::table('ecommerce_shipping_policy_country_settings', function (Blueprint $table) {
                foreach (['claim_fee_mode', 'return_fixed_fee', 'exchange_fixed_fee'] as $column) {
                    if (Schema::hasColumn('ecommerce_shipping_policy_country_settings', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('ecommerce_order_cancels')) {
            Schema::table('ecommerce_order_cancels', function (Blueprint $table) {
                if (Schema::hasIndex('ecommerce_order_cancels', 'ecommerce_order_cancels_created_at_index')) {
                    $table->dropIndex('ecommerce_order_cancels_created_at_index');
                }
            });
        }

        if (Schema::hasTable('ecommerce_order_refunds')) {
            Schema::table('ecommerce_order_refunds', function (Blueprint $table) {
                if (Schema::hasIndex('ecommerce_order_refunds', 'ecommerce_order_refunds_created_at_index')) {
                    $table->dropIndex('ecommerce_order_refunds_created_at_index');
                }
                if ($this->hasForeignKey('ecommerce_order_refunds', 'fk_rf_requested_by')) {
                    $table->dropForeign('fk_rf_requested_by');
                }
                if (Schema::hasIndex('ecommerce_order_refunds', 'ecommerce_order_refunds_requested_by_index')) {
                    $table->dropIndex('ecommerce_order_refunds_requested_by_index');
                }
                if (Schema::hasIndex('ecommerce_order_refunds', 'ecommerce_order_refunds_source_index')) {
                    $table->dropIndex('ecommerce_order_refunds_source_index');
                }

                $columns = [
                    'refund_source_type',
                    'refund_source_id',
                    'reason_code',
                    'reason_detail',
                    'fault_type',
                    'requested_by',
                    'approved_at',
                    'on_hold_at',
                    'withdrawn_at',
                    'reject_reason',
                ];
                foreach ($columns as $column) {
                    if (Schema::hasColumn('ecommerce_order_refunds', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });

            $this->commentOrderCancelId(self::ORDER_CANCEL_ID_ORIGINAL_COMMENT);
        }
    }
};
