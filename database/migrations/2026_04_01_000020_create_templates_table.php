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
        Schema::create('templates', function (Blueprint $table) {
            $table->id()->comment('템플릿 ID');
            $table->string('identifier')->unique()->comment('템플릿 고유 식별자 (vendor-name 형식, 예: sirsoft-admin_basic)');
            $table->string('vendor')->index()->comment('벤더/개발자명 (예: sirsoft)');
            $table->text('name')->nullable()->comment('템플릿 이름 (다국어 JSON)');
            $table->string('version')->comment('템플릿 버전 (예: 1.0.0)');
            $table->string('latest_version', 50)->nullable()->comment('마켓플레이스 최신 버전');
            $table->boolean('update_available')->default(false)->comment('업데이트 가능 여부');
            $table->string('update_source', 20)->nullable()->comment('업데이트 출처 (github, pending, bundled)');
            $table->enum('type', ['admin', 'user'])->comment('템플릿 타입 (admin: 관리자용, user: 사용자용)');
            $table->enum('status', ['active', 'inactive', 'installing', 'uninstalling', 'updating'])->default('inactive')->comment('상태 (active: 활성화, inactive: 비활성화, installing: 설치 중, uninstalling: 제거 중, updating: 업데이트 중)');
            $table->mediumText('description')->nullable()->comment('템플릿 설명 (다국어 JSON)');
            $table->timestamp('user_modified_at')->nullable()->comment('사용자가 레이아웃을 마지막으로 수정한 시각');
            $table->string('github_url')->nullable()->comment('GitHub 저장소 URL');
            $table->string('github_changelog_url', 512)->nullable()->comment('GitHub 변경 내역 URL');
            $table->mediumText('metadata')->nullable()->comment('추가 메타데이터 (JSON 형식)');
            $table->unsignedBigInteger('created_by')->nullable()->comment('생성자 ID');
            $table->unsignedBigInteger('updated_by')->nullable()->comment('수정자 ID');
            $table->timestamps();

            $table->index('type');
            $table->index('status');
        });

        if (DB::getDriverName() == 'mysql') {
            Schema::table('templates', function (Blueprint $table) {
                $table->comment('시스템 템플릿 정보');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('templates');
    }
};
