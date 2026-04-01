<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 게시판 설정
        Schema::create('boards', function (Blueprint $table) {
            $table->id()->comment('게시판 ID');
            $table->text('name')->comment('게시판명 (다국어 JSON)');
            $table->string('slug', 50)->unique()->comment('게시판 슬러그 (URL/테이블명)');
            $table->mediumText('description')->nullable()->comment('게시판 설명 (다국어 JSON)');
            $table->boolean('is_active')->default(true)->comment('게시판 활성화 여부 (1: 활성화, 0: 비활성화)');
            $table->unsignedInteger('per_page')->default(20)->comment('페이지당 게시글 수 (PC)');
            $table->unsignedInteger('per_page_mobile')->default(15)->comment('페이지당 게시글 수 (Mobile)');
            $table->string('order_by', 20)->default('created_at')->comment('정렬 기준 (created_at, view_count, title, author)');
            $table->string('order_direction', 4)->default('DESC')->comment('정렬 방향 (ASC, DESC)');
            $table->string('type', 50)->default('basic')->index()->comment('게시판 타입 (basic, gallery, card 등)');
            $table->text('categories')->nullable()->comment('분류 목록 (배열)');
            $table->boolean('show_view_count')->default(false)->comment('조회수 노출');
            $table->enum('secret_mode', ['disabled', 'enabled', 'always'])->default('disabled')->comment('비밀글 설정 (disabled: 사용안함, enabled: 사용함, always: 고정)');
            $table->boolean('use_comment')->default(true)->comment('댓글 기능 사용');
            $table->boolean('use_reply')->default(true)->comment('게시글 답변 기능 사용 (댓글에 대한 답글 아님)');
            $table->unsignedSmallInteger('max_reply_depth')->default(5)->comment('답변글 최대 깊이 (1~5)');
            $table->boolean('use_report')->default(false)->comment('게시글/댓글 신고 기능 사용');
            $table->unsignedInteger('new_display_hours')->default(24)->comment('신규 게시글 표시 기간 (시간 단위)');
            $table->unsignedInteger('min_title_length')->default(2)->comment('최소 제목 글자 수');
            $table->unsignedInteger('max_title_length')->default(200)->comment('최대 제목 글자 수');
            $table->unsignedInteger('min_content_length')->default(10)->comment('최소 게시글 글자 수');
            $table->unsignedInteger('max_content_length')->default(10000)->comment('최대 게시글 글자 수');
            $table->unsignedInteger('min_comment_length')->default(2)->comment('최소 댓글 글자 수');
            $table->unsignedInteger('max_comment_length')->default(1000)->comment('최대 댓글 글자 수');
            $table->boolean('use_file_upload')->default(false)->comment('파일 업로드 사용');
            $table->unsignedInteger('max_file_size')->nullable()->default(10)->comment('최대 파일 크기 (MB)');
            $table->unsignedInteger('max_file_count')->nullable()->default(5)->comment('최대 파일 개수');
            $table->text('allowed_extensions')->nullable()->comment('허용 확장자 배열');
            $table->string('comment_order', 4)->default('ASC')->comment('댓글 정렬 순서 (ASC: 오름차순, DESC: 내림차순)');
            $table->unsignedSmallInteger('max_comment_depth')->default(10)->comment('대댓글 최대 깊이 (1~10)');
            $table->boolean('notify_author')->default(true)->comment('작성자 이메일 알림 (댓글, 대댓글, 답변글, 관리자 처리 시)');
            $table->boolean('notify_admin_on_post')->default(true)->comment('관리자 이메일 알림 (게시글 등록 시)');
            $table->text('notify_author_channels')->nullable()->comment('작성자 알림 채널 (배열, 예: ["mail", "database"])');
            $table->text('notify_admin_on_post_channels')->nullable()->comment('관리자 알림 채널 (배열, 예: ["mail", "database"])');
            $table->text('blocked_keywords')->nullable()->comment('금지어 목록 (배열)');
            $table->unsignedBigInteger('created_by')->nullable()->index()->comment('생성자 ID');
            $table->unsignedBigInteger('updated_by')->nullable()->comment('수정자 ID');
            $table->timestamps();

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });

        if (DB::getDriverName() == 'mysql') {
            Schema::table('boards', function (Blueprint $table) {
                $table->comment('게시판 설정 정보');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('boards')) {
            Schema::dropIfExists('boards');
        }
    }
};
