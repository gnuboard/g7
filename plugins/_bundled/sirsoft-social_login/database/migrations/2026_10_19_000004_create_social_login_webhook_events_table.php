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
     * 제공자 웹훅 수신 1건. event_key UNIQUE 로 같은 알림의 중복 처리를 막는다. payload 에는 서명·토큰·암호문을
     * 지운 요약만 저장한다.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('social_login_webhook_events', function (Blueprint $table) {
            $table->id()->comment('고유 ID');
            $table->string('provider_key', 50)->comment('제공자 키');
            $table->string('event_key', 191)->unique()->comment('멱등 키 — 같은 알림의 중복 수신 차단');
            $table->string('event_type', 40)->comment('이벤트 종류 — unlink·account_delete·deauthorize·data_deletion·incomplete_signup');
            $table->string('provider_user_id', 191)->collation('utf8mb4_bin')->nullable()->comment('제공자 쪽 사용자 ID — 대소문자 구분(utf8mb4_bin)');
            $table->json('payload')->comment('수신 요약 — 서명·토큰·암호문 제거');
            $table->string('status', 10)->comment('처리 상태 — received·processed·ignored·failed');
            $table->unsignedTinyInteger('attempts')->default(0)->comment('처리 시도 횟수');
            $table->text('error')->nullable()->comment('마지막 처리 오류');
            $table->dateTime('received_at')->comment('수신 시각');
            $table->dateTime('processed_at')->nullable()->comment('처리 완료 시각');
            $table->string('confirmation_code', 64)->nullable()->comment('페이스북 데이터 삭제 확인 코드');

            $table->index(['provider_key', 'received_at'], 'social_login_webhook_events_provider_received_index');
            $table->index('status', 'social_login_webhook_events_status_index');
        });

        if (DB::getDriverName() === 'mysql') {
            Schema::table('social_login_webhook_events', function (Blueprint $table) {
                $table->comment('소셜 로그인 제공자 웹훅 수신 기록');
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('social_login_webhook_events');
    }
};
