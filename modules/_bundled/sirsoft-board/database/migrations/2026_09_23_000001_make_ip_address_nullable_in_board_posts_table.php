<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * board_posts.ip_address 를 NOT NULL → nullable 로 바꿉니다 (이슈 #636 개인정보 보유기간).
     * 보유기간이 지난 게시글의 작성 IP 를 지우려면 NULL 이 들어갈 수 있어야 하고,
     * board_comments.ip_address 는 처음부터 nullable 이라 두 테이블이 비대칭이었습니다.
     *
     * 함께, IP 를 알 수 없을 때 NOT NULL 을 채우려고 넣어 두었던 자리표시값 '0.0.0.0'
     * (이커머스 상품문의 연동이 채움)을 NULL 로 되돌립니다 — 실제 접속 IP 가 될 수 없는 값이라
     * 의미상 "모름" 이고, 남겨 두면 보유기간 처리가 그 행을 개인정보가 남은 행으로 셉니다.
     */
    public function up(): void
    {
        $prefix = DB::getTablePrefix();

        DB::statement(
            "ALTER TABLE {$prefix}board_posts MODIFY COLUMN ip_address VARCHAR(45) NULL "
            ."COMMENT 'IP 주소 (보유기간 경과 시 NULL 로 파기)'"
        );

        DB::table('board_posts')
            ->where('ip_address', '0.0.0.0')
            ->update(['ip_address' => null]);
    }

    /**
     * Reverse the migrations.
     *
     * NOT NULL 로 되돌리기 전에 NULL 행을 자리표시값 '0.0.0.0' 으로 채워야 합니다 —
     * NULL 이 남아 있으면 MODIFY COLUMN 이 실패합니다. 파기된 IP 는 되살릴 수 없으므로
     * 원래 값이 아니라 "모름" 자리표시값으로 채웁니다.
     */
    public function down(): void
    {
        $prefix = DB::getTablePrefix();

        DB::table('board_posts')
            ->whereNull('ip_address')
            ->update(['ip_address' => '0.0.0.0']);

        DB::statement(
            "ALTER TABLE {$prefix}board_posts MODIFY COLUMN ip_address VARCHAR(45) NOT NULL "
            ."COMMENT 'IP 주소'"
        );
    }
};
