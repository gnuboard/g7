<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * board_mail_templates 테이블 삭제.
     *
     * notification_templates 시스템으로 통합 완료 (#146).
     * 운영 환경 데이터 이관은 게시판 모듈 Upgrade_1_0_0_beta_2 에서 수행됨.
     *
     * @return void
     */
    public function up(): void
    {
        if (! Schema::hasTable('board_mail_templates')) {
            return;
        }

        if (! Schema::hasTable('notification_templates')) {
            throw new \RuntimeException(
                'board_mail_templates 제거 전 notification_templates 가 존재해야 합니다. '
                .'코어 Upgrade_7_0_0_beta_2 + 게시판 Upgrade_1_0_0_beta_2 업그레이드 스텝을 먼저 실행하세요.'
            );
        }

        Schema::drop('board_mail_templates');
    }

    /**
     * board_mail_templates 테이블 복원.
     *
     * 운영 데이터 복구는 별도 백업에서 수행해야 합니다.
     *
     * @return void
     */
    public function down(): void
    {
        if (Schema::hasTable('board_mail_templates')) {
            return;
        }

        Schema::create('board_mail_templates', function (Blueprint $table) {
            $table->id()->comment('ID');
            $table->string('type', 100)->unique()->comment('템플릿 유형 (new_comment, reply_comment 등)');
            $table->text('subject')->comment('다국어 제목');
            $table->mediumText('body')->comment('다국어 본문');
            $table->text('variables')->nullable()->comment('사용 가능 변수 메타데이터');
            $table->boolean('is_active')->default(true)->comment('활성 여부');
            $table->boolean('is_default')->default(true)->comment('시더 생성 여부');
            $table->text('user_overrides')->nullable()->comment('유저가 수정한 필드명 목록');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete()->comment('수정자');
            $table->timestamps();
        });
    }
};
