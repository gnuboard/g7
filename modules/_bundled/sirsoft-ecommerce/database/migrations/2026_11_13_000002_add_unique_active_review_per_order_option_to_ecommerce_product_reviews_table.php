<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** 대상 테이블 (프리픽스 제외) */
    private const TABLE = 'ecommerce_product_reviews';

    /** 활성 후기 파생 키 컬럼명 */
    private const COLUMN = 'active_order_option_key';

    /** 주문옵션당 활성 후기 1건 유니크 인덱스명 */
    private const INDEX = 'ecommerce_product_reviews_active_order_option_unique';

    /**
     * Run the migrations.
     *
     * 주문옵션당 살아 있는(소프트 삭제되지 않은) 회원 후기를 1건으로 강제합니다.
     *
     * 작성 자격 검사(canWrite)는 "이 주문옵션에 후기가 없다"를 조회로만 확인하므로, 같은 옵션의 작성
     * 요청이 동시에 겹치거나 작성 훅 안에서 다시 작성이 일어나면 두 요청이 모두 검사를 통과해 후기가
     * 두 건 생긴다. 조회 시점에는 잠글 행이 없어 비관적 잠금으로 막을 수 없으므로 DB 제약으로 막는다.
     *
     * 지운 후기 뒤에 다시 쓰는 것은 허용해야 하고, 회원·주문 없이 등록한 후기(이관 후기)는 주문옵션이
     * NULL 이므로, 소프트 삭제되지 않은 행만 주문옵션 ID 를 갖는 생성 컬럼에 유니크를 건다
     * (MySQL 유니크 인덱스는 NULL 중복을 허용한다).
     *
     * 기설치본에는 이미 활성 중복이 쌓여 있을 수 있어 인덱스 생성 전에 가장 오래된 1건만 남기고 나머지를
     * 소프트 삭제한다(행은 지우지 않으므로 관리자가 확인 후 복구할 수 있다). 각 단계는 재실행에 안전하게
     * 독립적으로 가드한다.
     */
    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        $this->softDeleteDuplicateActiveReviews();

        if (! Schema::hasColumn(self::TABLE, self::COLUMN)) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->unsignedBigInteger(self::COLUMN)->nullable()
                    ->virtualAs('CASE WHEN `deleted_at` IS NULL THEN `order_option_id` END')
                    ->comment('주문옵션당 활성 후기 1건 강제용 파생 키 (삭제된 후기·주문 없는 후기는 NULL)');
            });
        }

        if (! Schema::hasIndex(self::TABLE, self::INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->unique(self::COLUMN, self::INDEX);
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * 인덱스와 생성 컬럼만 제거한다. 업그레이드 때 소프트 삭제한 중복 후기는 되살리지 않는다
     * (되살리면 같은 주문옵션에 활성 후기가 다시 둘이 된다).
     */
    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        if (Schema::hasIndex(self::TABLE, self::INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->dropUnique(self::INDEX);
            });
        }

        if (Schema::hasColumn(self::TABLE, self::COLUMN)) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->dropColumn(self::COLUMN);
            });
        }
    }

    /**
     * 같은 주문옵션에 살아 있는 후기가 2건 이상이면 가장 오래된 1건만 남기고 나머지를 소프트 삭제합니다.
     *
     * 소프트 삭제한 후기 ID 는 주문옵션별로 업그레이드 로그(warning)에 남긴다 — 운영자가 어떤 후기가
     * 가려졌는지 확인하고 필요하면 복구할 수 있게 한다.
     *
     * 반복 종료는 데이터에 맡기지 않는다 — 한 바퀴에서 실제로 소프트 삭제한 행이 없으면 다음 바퀴도
     * 같은 목록을 다시 읽을 뿐이므로 그 자리에서 멈춘다 (진행 없는 반복 차단).
     *
     * @return void 반환값 없음
     */
    private function softDeleteDuplicateActiveReviews(): void
    {
        // 중복 주문옵션 목록은 정리 대상이 남아 있는 동안만 나눠 조회한다(중복이 많은 설치본의 메모리 보호).
        while (true) {
            $duplicated = DB::table(self::TABLE)
                ->select('order_option_id')
                ->whereNull('deleted_at')
                ->whereNotNull('order_option_id')
                ->groupBy('order_option_id')
                ->havingRaw('COUNT(*) > 1')
                ->limit(200)
                ->pluck('order_option_id');

            if ($duplicated->isEmpty()) {
                return;
            }

            $affected = 0;

            foreach ($duplicated as $orderOptionId) {
                $ids = DB::table(self::TABLE)
                    ->whereNull('deleted_at')
                    ->where('order_option_id', $orderOptionId)
                    ->orderBy('created_at')
                    ->orderBy('id')
                    ->pluck('id');

                if ($ids->count() < 2) {
                    continue;
                }

                $keptId = $ids->shift();
                $removedIds = $ids->all();

                $affected += DB::table(self::TABLE)
                    ->whereIn('id', $removedIds)
                    ->whereNull('deleted_at')
                    ->update(['deleted_at' => now()]);

                Log::warning('[ecommerce] 같은 주문옵션의 중복 활성 후기를 소프트 삭제했습니다 — 가장 오래된 후기만 남깁니다', [
                    'table' => self::TABLE,
                    'order_option_id' => $orderOptionId,
                    'kept_review_id' => $keptId,
                    'soft_deleted_review_ids' => $removedIds,
                ]);
            }

            // 목록은 남아 있는데 한 행도 정리하지 못했다면 더 진행할 수 없다.
            if ($affected === 0) {
                return;
            }
        }
    }
};
