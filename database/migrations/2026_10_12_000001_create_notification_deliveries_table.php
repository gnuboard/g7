<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 알림 수신자별 발송 기록 표를 만듭니다 (#139).
 *
 * 알림 발송 잡이 재시도·재예약으로 다시 돌 때 이미 보낸 수신자를 건너뛰기 위한 중복 방지 저장소다.
 * 보내기 전에 행을 선점(리스)하고 보낸 뒤 「보냈음」으로 표시한다. 발송 이력(notification_logs)을 쓰지 않는
 * 이유 — 고유 키가 없고, 실패·건너뜀 행이 섞이며, 관리자 삭제·보존기간 정리로 방지가 풀리고, 리스 상태를
 * 둘 곳이 없다. 회원·비회원 키가 섞이므로 users 외래 키는 두지 않는다. 오래된 행은 일 1회 정리 명령이 지운다.
 */
return new class extends Migration
{
    /**
     * 마이그레이션을 실행합니다.
     */
    public function up(): void
    {
        if (Schema::hasTable('notification_deliveries')) {
            return;
        }

        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->char('delivery_key', 64)->unique()->comment('중복 방지 키 sha256(이벤트 ID·알림 유형·채널·수신자 키)');
            $table->string('event_id', 64)->comment('훅 리스너 잡 단위 이벤트 ID');
            $table->string('notification_type', 100)->comment('알림 유형');
            $table->string('channel', 50)->comment('발송 채널');
            $table->string('recipient_key', 191)->comment('수신자 키 (user:{id} · guest:{hash} · {모델}:{id})');
            $table->string('status', 16)->comment('상태 (sending=발송 중 선점, sent=보냄, skipped=건너뜀)');
            $table->unsignedSmallInteger('attempts')->default(1)->comment('선점 횟수 (오래된 선점을 넘겨받으면 증가)');
            $table->timestamp('claimed_at')->comment('마지막 선점 시각 (리스 만료 판정 기준)');
            $table->timestamp('completed_at')->nullable()->comment('보냄·건너뜀 확정 시각');
            $table->timestamps();

            $table->index('created_at', 'idx_notification_deliveries_created_at');
        });
    }

    /**
     * 마이그레이션을 되돌립니다.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
    }
};
