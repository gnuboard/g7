<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 개인정보 조치 관리자 직권 실행 기록 테이블을 만듭니다.
 *
 * 관리자 화면의 「지금 실행」은 작업을 큐에 넣고 바로 응답한다. 이 테이블이 그 작업의
 * 상태(처리 대기 → 처리 중 → 완료/실패)와 결과(처리 건수·실패 사유)를 들고 있어, 화면이
 * 카드에 진행 상태와 마지막 결과를 보여 주고, 같은 대상에 작업이 겹쳐 접수되는 것을 막는다.
 *
 * - target        : 직권 실행 대상 키 (`retention:user-withdrawn`, `dormant:convert`, `sirsoft-gdpr:annual_notice` …)
 * - requested_by  : 접수한 관리자. 회원이 삭제돼도 기록은 남아야 하므로 FK 를 두지 않는다
 *                   (DB CASCADE 금지 — 삭제 연쇄를 DB 에 맡기지 않는다). 화면은 없는 회원을 「알 수 없음」으로 그린다.
 *
 * 인덱스 (target, id): 대상별 최신 기록 조회와 진행 중 작업 확인이 이 한 쌍으로 끝난다.
 *
 * @since 7.1.0
 */
return new class extends Migration
{
    /**
     * 마이그레이션 실행
     */
    public function up(): void
    {
        if (Schema::hasTable('privacy_manual_runs')) {
            return;
        }

        Schema::create('privacy_manual_runs', function (Blueprint $table) {
            $table->comment('개인정보 조치 관리자 직권 실행 기록');

            $table->id()->comment('실행 기록 ID');
            $table->string('target', 150)->comment('직권 실행 대상 키 (예: retention:user-withdrawn, dormant:convert)');
            $table->string('status', 20)->default('queued')->comment('상태 (queued: 처리 대기, running: 처리 중, completed: 완료, failed: 실패)');
            $table->boolean('skip_waiting')->default(false)->comment('휴면 대기기간 생략 여부');
            $table->unsignedBigInteger('requested_by')->nullable()->comment('접수한 관리자 users.id (FK 없음 — 회원 삭제 후에도 기록 보존)');
            $table->unsignedInteger('processed_count')->nullable()->comment('처리 건수 (완료 시)');
            $table->unsignedInteger('remaining_count')->nullable()->comment('처리 후 남은 대상 건수 (발송 상한이 있는 조치만)');
            $table->string('mode', 50)->nullable()->comment('처리 방식 (anonymize, delete, erase_ip, notify 등)');
            $table->text('error')->nullable()->comment('실패 사유');
            $table->timestamp('queued_at')->nullable()->comment('접수 일시');
            $table->timestamp('started_at')->nullable()->comment('처리 시작 일시');
            $table->timestamp('finished_at')->nullable()->comment('처리 종료 일시');
            $table->timestamps();

            $table->index(['target', 'id'], 'privacy_manual_runs_target_id_index');
            $table->index('requested_by', 'privacy_manual_runs_requested_by_index');
        });
    }

    /**
     * 마이그레이션 되돌리기
     */
    public function down(): void
    {
        Schema::dropIfExists('privacy_manual_runs');
    }
};
