<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * board_posts, board_comments, board_attachments 3개 테이블의 파티션 제거.
     * 복합 PK (id, board_id) → id 단독 PK로 변경.
     * STEP 2의 복합 인덱스 적용을 위한 선행 작업.
     */
    public function up(): void
    {
        // ℹ️ 마이그레이션 파일에서는 DB::getTablePrefix() 사용 (Laravel 표준)
        $prefix = DB::getTablePrefix();

        DB::statement("ALTER TABLE {$prefix}board_posts REMOVE PARTITIONING");
        DB::statement("ALTER TABLE {$prefix}board_posts DROP PRIMARY KEY, ADD PRIMARY KEY (id)");

        DB::statement("ALTER TABLE {$prefix}board_comments REMOVE PARTITIONING");
        DB::statement("ALTER TABLE {$prefix}board_comments DROP PRIMARY KEY, ADD PRIMARY KEY (id)");

        DB::statement("ALTER TABLE {$prefix}board_attachments REMOVE PARTITIONING");
        DB::statement("ALTER TABLE {$prefix}board_attachments DROP PRIMARY KEY, ADD PRIMARY KEY (id)");
    }

    /**
     * Reverse the migrations.
     *
     * 파티션 복원은 파티션별 데이터 재배치가 필요하므로 자동 롤백이 불가합니다.
     */
    public function down(): void
    {
        throw new \RuntimeException(
            'board_posts/board_comments/board_attachments 파티션 제거는 롤백이 불가합니다. '
            . '복원이 필요한 경우 DBA가 수동으로 수행해야 합니다.'
        );
    }
};
