<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * 본문 미디어 저장소를 범용 첨부 표로 옮긴 뒤의 옛 전용 표 정리 (#887).
 *
 * 상품 설명·상품 공통 정보 편집기 본문 미디어는 1.3.4 개발 중 전용 표 `ecommerce_content_media` 에 두었다가(#883), 출시 전에 이 모듈
 * 범용 첨부 표 `ecommerce_attachments`(collection = content_media, `2026_11_10_000001_create_ecommerce_attachments_table`)로 옮겼다.
 * 전용 표를 만들던 마이그레이션은 지웠고, 이미 그 표를 만든 개발 설치본을 위해 다음을 한다.
 *
 * - up(): 전용 표가 있고 **비어 있으면** 지운다. 행이 있으면 그대로 둔다 — 행은 해시(저장된 본문의 주소)를 유지한 채 새 표로 옮겨야
 *   하므로 1.3.4 업그레이드 스텝(`upgrades/data/1.3.4/migrations/03_MoveContentMediaToEcommerceAttachments`)이 옮긴 뒤 지운다.
 *   마이그레이션은 업그레이드 스텝보다 먼저 돌므로 여기서 행째 지우면 개발 데이터의 본문 주소가 깨진다.
 * - down(): 옛 전용 표는 되살리지 않는다 — 남아 있으면 up() 과 같은 판정(빈 표만 지움)을 한 번 더 한다. 「데이터도 삭제」 제거의
 *   본문 미디어 행 삭제는 범용 첨부 표 마이그레이션의 down() 이 표째 한다.
 */
return new class extends Migration
{
    /** 옛 전용 표 */
    private const LEGACY_TABLE = 'ecommerce_content_media';

    /**
     * 마이그레이션 실행
     */
    public function up(): void
    {
        if (! Schema::hasTable(self::LEGACY_TABLE)) {
            return;
        }

        if (DB::table(self::LEGACY_TABLE)->exists()) {
            Log::info('[ecommerce] 옛 본문 미디어 전용 표에 행이 있어 남김 — 1.3.4 업그레이드 스텝이 범용 첨부 표로 옮긴 뒤 지운다', [
                'table' => self::LEGACY_TABLE,
            ]);

            return;
        }

        Schema::dropIfExists(self::LEGACY_TABLE);
    }

    /**
     * 마이그레이션 되돌리기 — 옛 전용 표는 되살리지 않는다(범용 첨부 표 마이그레이션의 down() 이 본문 미디어 행을 표째 지운다).
     * 「데이터도 삭제」 제거에서 옛 전용 표가 남아 있으면 빈 표만 지운다 — 행이 남은 표는 옮기지 못한 개발 데이터라 그대로 둔다.
     */
    public function down(): void
    {
        if (Schema::hasTable(self::LEGACY_TABLE) && ! DB::table(self::LEGACY_TABLE)->exists()) {
            Schema::dropIfExists(self::LEGACY_TABLE);
        }
    }
};
