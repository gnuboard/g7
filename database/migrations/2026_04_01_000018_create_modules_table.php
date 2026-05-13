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
        Schema::create('modules', function (Blueprint $table) {
            $table->id();
            $table->string('identifier')->unique()->comment('모듈 고유 식별자 (vendor-module 형식)');
            $table->string('vendor')->index()->comment('벤더/개발자명');
            $table->text('name')->comment('모듈 이름 (다국어 JSON)');
            $table->mediumText('description')->nullable()->comment('모듈 설명 (다국어 JSON)');
            $table->string('github_url', 512)->nullable()->comment('GitHub 저장소 URL');
            $table->string('github_changelog_url', 512)->nullable()->comment('GitHub 변경 내역 URL');
            $table->mediumText('metadata')->nullable()->comment('추가 메타데이터');
            $table->unsignedBigInteger('updated_by')->nullable()->comment('수정자 ID');
            $table->string('version')->comment('모듈 버전');
            $table->string('latest_version', 50)->nullable()->comment('마켓플레이스 최신 버전');
            $table->enum('status', ['active', 'inactive', 'installing', 'uninstalling', 'updating'])->default('inactive')->index()->comment('상태 (active: 활성화, inactive: 비활성화, installing: 설치 중, uninstalling: 제거 중, updating: 업데이트 중)');
            $table->boolean('update_available')->default(false)->comment('업데이트 가능 여부');
            $table->string('update_source', 20)->nullable()->comment('업데이트 출처 (github, pending, bundled)');
            $table->mediumText('config')->nullable()->comment('모듈 설정 정보');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete()->comment('모듈 생성자 ID');
            $table->timestamps();
        });

        if (DB::getDriverName() == 'mysql') {
            Schema::table('modules', function (Blueprint $table) {
                $table->comment('시스템 모듈 관리');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('modules');
    }
};
