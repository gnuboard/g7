<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 비즈뿌리오 발송 이력 테이블.
 *
 * 문자(SMS/LMS)·알림톡 1건 발송마다 1행. 발송 시 pending 으로 생성되고,
 * 비즈뿌리오 webhook(URL PUSH) 리포트 수신 시 refkey 로 매칭해 상태를 갱신한다.
 * 발송 이력 화면(계획서 §6-5)의 데이터소스.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::dropIfExists('bizppurio_dispatches');

        Schema::create('bizppurio_dispatches', function (Blueprint $table) {
            $table->bigIncrements('id')->comment('발송 이력 PK');
            $table->string('refkey', 32)->comment('우리 부여 키 (webhook 매칭용, UTF-8 최대 32byte)');
            $table->string('messagekey', 64)->nullable()->comment('비즈뿌리오 부여 키 (발송 응답 messagekey)');
            $table->string('channel', 20)->comment('발송 채널: sms / lms / alimtalk (DispatchChannel enum)');
            $table->string('media', 10)->nullable()->comment('webhook MEDIA — 실제 발송 유형: SMS / LMS / KAT(알림톡)');
            $table->string('to_number', 20)->comment('수신 전화번호 (숫자만)');
            $table->string('to_name', 100)->nullable()->comment('수신자명 (발송 시점 스냅샷)');
            $table->foreignId('to_user_id')->nullable()->comment('회원이면 users FK, 비회원이면 null')
                ->constrained('users', indexName: 'bizppurio_dispatch_user_fk')->nullOnDelete();
            $table->text('content')->comment('발송 본문(SMS) 또는 템플릿 참조(알림톡)');
            $table->json('request_payload')->nullable()->comment('실제 비즈뿌리오 API 전송 요청 payload (개인식별 정보 제외 — to/refkey/type/본문 텍스트는 다른 컬럼과 중복이라 제외, account/from/senderkey/templatecode/버튼 등 요소만 저장)');
            $table->string('notification_type', 100)->nullable()->comment('코어 notification_definitions.type 참조 (어느 알림). 수동발송 시 null');
            $table->unsignedBigInteger('notification_log_id')->nullable()->comment('코어 notification_logs.id 연결 표식 (A-2). 코어 알림 발송 이력 화면에서 결과를 이 행에 붙이기 위한 매칭 키. 코어 테이블 무수정 — 연결 표식은 비즈뿌리오 쪽에만 둔다');
            $table->string('status', 20)->default('pending')->comment('발송 상태: pending / sent / success / failed (DispatchStatus enum)');
            $table->string('result_code', 10)->nullable()->comment('결과 코드 (발송응답 or 리포트)');
            $table->string('result_message', 255)->nullable()->comment('결과 사유 원본 (result_codes lang 해석 전)');
            $table->string('fallback_status', 20)->nullable()->comment('대체발송 결과: 성공/실패/없음 (webhook TELRES)');
            $table->string('source', 10)->default('auto')->comment('발송 출처: auto / manual / bulk (DispatchSource enum, 1차 auto)');
            $table->boolean('is_test_mode')->nullable()->comment('발송 시점 검수 모드 여부 (null=컬럼 신설 이전 이력)');
            $table->timestamp('sent_at')->nullable()->comment('발송 시각');
            $table->timestamp('reported_at')->nullable()->comment('webhook 리포트 수신 시각 (replay 멱등 판정)');
            $table->json('raw_payload')->nullable()->comment('webhook 원본 페이로드');
            $table->timestamps();

            $table->unique('refkey', 'bizppurio_dispatch_refkey_unique');
            $table->index('channel', 'bizppurio_dispatch_channel_idx');
            $table->index('status', 'bizppurio_dispatch_status_idx');
            $table->index('notification_type', 'bizppurio_dispatch_notif_type_idx');
            $table->index('sent_at', 'bizppurio_dispatch_sent_at_idx');
            $table->index('to_user_id', 'bizppurio_dispatch_user_idx');
            $table->index(['status', 'sent_at'], 'bizppurio_dispatch_status_sent_idx');
            $table->index('notification_log_id', 'bizppurio_dispatch_notif_log_idx');
        });

        if (DB::getDriverName() === 'mysql') {
            Schema::table('bizppurio_dispatches', function (Blueprint $table) {
                $table->comment('비즈뿌리오 문자·알림톡 발송 이력');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bizppurio_dispatches');
    }
};
