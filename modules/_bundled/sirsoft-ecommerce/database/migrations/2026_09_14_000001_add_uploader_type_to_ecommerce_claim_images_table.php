<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * 클레임 첨부에 업로드 주체 컬럼을 추가합니다.
 *
 * 분쟁이 생기면 "이 사진을 누가 냈는가" 가 판정을 가른다. 기존 `created_by` 는 업로더의
 * 회원 ID 일 뿐이라 주체를 가르지 못한다 — 비회원도 NULL 이고 운영자 대리 업로드도 그냥 id 다.
 *
 * ## 백필을 `up()` 안에서 하는 이유
 *
 * 클레임 기능은 아직 공개 전이라 업그레이드 스텝을 만들지 않는다(운영 결정). 대신 이 마이그레이션이
 * 컬럼 추가 직후 같은 자리에서 기존 행의 주체를 추정해 채운다. **단정할 수 있는 규칙만** 쓰고
 * 나머지는 `unknown` 으로 둔다 — 한 번 굳으면 원본이 없고, 화면은 확신 있게 "고객 첨부" 라고
 * 말하게 되기 때문이다.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
    {
        if (! Schema::hasTable('ecommerce_claim_images')) {
            return;
        }

        if (! Schema::hasColumn('ecommerce_claim_images', 'uploader_type')) {
            Schema::table('ecommerce_claim_images', function (Blueprint $table) {
                $table->string('uploader_type', 20)->default('unknown')->after('created_by')
                    ->comment('업로드 주체 (ClaimImageUploaderTypeEnum — customer: 고객이 신청·수정 화면에서 올림, admin: 운영자가 상세에서 대리로 올림, unknown: 주체 미상)');
            });
        }

        $this->backfillUploaderType();
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        if (Schema::hasTable('ecommerce_claim_images') && Schema::hasColumn('ecommerce_claim_images', 'uploader_type')) {
            Schema::table('ecommerce_claim_images', function (Blueprint $table) {
                $table->dropColumn('uploader_type');
            });
        }
    }

    /**
     * 기존 첨부의 업로드 주체를 추정해 채웁니다. (멱등 — `unknown` 인 행만 건드린다)
     *
     * 판정 규칙:
     *  - `claimable_type = 'pending'`  → customer (미귀속 업로드 경로는 회원·비회원 신청 화면뿐)
     *  - `created_by IS NULL`          → customer (비회원 신청자)
     *  - `created_by = 신청서.requested_by` → customer
     *  - `created_by <> 신청서.requested_by` → admin (운영자 대리 업로드)
     *  - 귀속 신청서 행 부재(고아)      → unknown (단정 금지)
     *
     * @return void
     */
    private function backfillUploaderType(): void
    {
        if (! Schema::hasColumn('ecommerce_claim_images', 'uploader_type')) {
            Log::warning('[claim.image] uploader_type 컬럼이 없어 백필을 건너뜁니다. 컬럼 추가 후 이 마이그레이션을 다시 실행하세요.');

            return;
        }

        // 1) 미귀속(pending) 업로드는 언제나 고객이 올린 것이다.
        DB::table('ecommerce_claim_images')
            ->where('uploader_type', 'unknown')
            ->where('claimable_type', 'pending')
            ->update(['uploader_type' => 'customer']);

        // 2) 업로더가 없으면 비회원 신청자다.
        DB::table('ecommerce_claim_images')
            ->where('uploader_type', 'unknown')
            ->whereNull('created_by')
            ->update(['uploader_type' => 'customer']);

        // 3) 귀속 신청서의 신청자와 업로더를 대조한다. 유형별 신청서 테이블이 따로라 유형마다 돈다.
        //    `UPDATE ... JOIN` 은 MySQL 전용 문법이라 쓰지 않는다 — 존재 서브쿼리 두 번으로 나눠
        //    "신청자 = 업로더 → customer" 를 먼저 채우고, 귀속 신청서가 있는 나머지를 admin 으로 채운다.
        foreach ([
            'exchange' => 'ecommerce_order_exchanges',
            'return' => 'ecommerce_order_returns',
            'refund' => 'ecommerce_order_refunds',
        ] as $claimType => $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            DB::table('ecommerce_claim_images')
                ->where('uploader_type', 'unknown')
                ->where('claimable_type', $claimType)
                ->whereExists(function ($query) use ($table) {
                    $query->select("{$table}.id")
                        ->from($table)
                        ->whereColumn("{$table}.id", 'ecommerce_claim_images.claimable_id')
                        ->whereNotNull("{$table}.requested_by")
                        ->whereColumn("{$table}.requested_by", 'ecommerce_claim_images.created_by');
                })
                ->update(['uploader_type' => 'customer']);

            DB::table('ecommerce_claim_images')
                ->where('uploader_type', 'unknown')
                ->where('claimable_type', $claimType)
                ->whereExists(function ($query) use ($table) {
                    $query->select("{$table}.id")
                        ->from($table)
                        ->whereColumn("{$table}.id", 'ecommerce_claim_images.claimable_id');
                })
                ->update(['uploader_type' => 'admin']);
        }

        // 4) 남은 행은 귀속 신청서를 찾지 못한 고아다 — `unknown` 으로 둔다(단정 금지).
        $orphans = DB::table('ecommerce_claim_images')->where('uploader_type', 'unknown')->count();

        if ($orphans > 0) {
            Log::info('[claim.image] 업로드 주체를 단정할 수 없는 첨부가 남았습니다(귀속 신청서 부재).', [
                'count' => $orphans,
            ]);
        }
    }
};
