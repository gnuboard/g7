<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * board_posts.ip_address 를 NOT NULL → nullable 로 바꿉니다 (이슈 #636 개인정보 보유기간).
     * 보유기간이 지난 게시글의 작성 IP 를 지우려면 NULL 이 들어갈 수 있어야 하고,
     * board_comments.ip_address 는 처음부터 nullable 이라 두 테이블이 비대칭이었습니다.
     *
     * 기존 행에 남은 자리표시값 '0.0.0.0' 을 NULL 로 되돌리는 정리는 업그레이드 스텝
     * (1.2.0 `NormalizePlaceholderPostIp`)이 맡습니다 — 스키마 변경과 데이터 변환을 나눈다.
     *
     * `->change()` 는 MySQL 에서 표를 다시 쓰므로 게시글이 많으면 그동안 board_posts 쓰기가 막힐 수 있다 —
     * 글이 많은 사이트는 방문이 적은 시간에 업데이트한다.
     */
    public function up(): void
    {
        Schema::table('board_posts', function (Blueprint $table) {
            $table->string('ip_address', 45)->nullable()->comment('IP 주소 (보유기간 경과 시 NULL 로 파기)')->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * NOT NULL 로 되돌리기 전에 NULL 행을 자리표시값 '0.0.0.0' 으로 채워야 합니다 —
     * NULL 이 남아 있으면 컬럼 변경이 실패합니다. 파기된 IP 는 되살릴 수 없으므로
     * 원래 값이 아니라 "모름" 자리표시값으로 채웁니다.
     */
    public function down(): void
    {
        DB::table('board_posts')
            ->whereNull('ip_address')
            ->update(['ip_address' => '0.0.0.0']);

        Schema::table('board_posts', function (Blueprint $table) {
            $table->string('ip_address', 45)->nullable(false)->comment('IP 주소')->change();
        });
    }
};
