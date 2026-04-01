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
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->comment('사용자 이름');
            $table->string('nickname', 50)->nullable()->comment('닉네임');
            $table->string('email')->unique()->comment('이메일 주소');
            $table->timestamp('email_verified_at')->nullable()->comment('이메일 인증 일시');
            $table->string('password')->comment('비밀번호 해시');
            $table->string('language', 5)->default('ko')->comment('사용자 언어 설정 (ko: 한국어, en: 영어)');
            $table->boolean('is_super')->default(false)->index()->comment('슈퍼 관리자 여부 (삭제 불가, 권한 관리 가능)');
            $table->string('timezone', 50)->nullable()->default('Asia/Seoul')->comment('사용자 시간대 (예: Asia/Seoul, UTC)');
            $table->string('country', 2)->nullable()->index()->comment('국가 코드 (ISO 3166-1 alpha-2)');
            $table->string('status', 20)->default('active')->index()->comment('계정 상태 (active: 활성, inactive: 비활성, blocked: 차단, withdrawn: 탈퇴)');
            $table->string('homepage')->nullable()->comment('홈페이지 URL');
            $table->string('mobile', 20)->nullable()->index()->comment('휴대폰 번호');
            $table->string('phone', 20)->nullable()->index()->comment('전화번호');
            $table->string('zipcode', 10)->nullable()->comment('우편번호');
            $table->string('address')->nullable()->comment('기본 주소');
            $table->string('address_detail')->nullable()->comment('상세 주소');
            $table->text('signature')->nullable()->comment('서명');
            $table->text('bio')->nullable()->comment('자기소개');
            $table->text('admin_memo')->nullable()->comment('관리자 메모');
            $table->string('ip_address', 45)->nullable()->index()->comment('마지막 접속 IP 주소');
            $table->rememberToken();
            $table->timestamps();
            $table->timestamp('last_login_at')->nullable()->comment('마지막 로그인 일시');
            $table->timestamp('withdrawn_at')->nullable()->comment('탈퇴 일시');
            $table->timestamp('blocked_at')->nullable()->comment('차단 일시');
        });

        if (DB::getDriverName() == 'mysql') {
            Schema::table('users', function (Blueprint $table) {
                $table->comment('시스템 사용자 정보');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
