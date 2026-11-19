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
     * 회원과 제공자 계정의 연결 1행. 제공자 사용자 ID 는 대소문자를 구분해야 하므로(네이버 id 등)
     * utf8mb4_bin 으로 비교한다 — 기본 collation 이면 대소문자만 다른 두 계정이 한 회원으로 묶인다.
     * 회원 삭제·탈퇴 때는 리스너가 명시 삭제한다 (CASCADE 미사용).
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id()->comment('고유 ID');
            $table->foreignId('user_id')
                ->comment('회원 ID — CASCADE 미설정 (회원 삭제·탈퇴 시 리스너가 명시 삭제)')
                ->constrained('users');
            $table->string('provider_key', 50)->comment('제공자 키 (social_login_providers.key)');
            $table->string('provider_user_id', 191)->collation('utf8mb4_bin')->comment('제공자 쪽 사용자 ID — 대소문자 구분(utf8mb4_bin)');
            $table->string('email', 255)->nullable()->comment('제공자가 준 이메일 (없을 수 있음)');
            $table->boolean('email_verified')->nullable()->comment('제공자의 이메일 검증 표시 — null 은 제공자가 알려 주지 않음');
            $table->string('name', 255)->nullable()->comment('제공자가 준 이름');
            $table->string('nickname', 50)->nullable()->comment('제공자가 준 별명');
            $table->string('avatar_url', 1024)->nullable()->comment('제공자 프로필 사진 주소');
            $table->text('access_token')->nullable()->comment('제공자 접근 토큰 (encrypted cast)');
            $table->text('refresh_token')->nullable()->comment('제공자 갱신 토큰 (encrypted cast)');
            $table->dateTime('token_expires_at')->nullable()->comment('접근 토큰 만료 시각');
            $table->text('profile_raw')->nullable()->comment('제공자 원본 프로필 — 토큰·비밀 제거 (encrypted cast)');
            $table->dateTime('profile_raw_expires_at')->nullable()->comment('원본 프로필 보존 만료 시각 (배치가 지움)');
            $table->dateTime('linked_at')->comment('연결 시각');
            $table->dateTime('last_login_at')->nullable()->comment('이 연결로 마지막 로그인한 시각');
            $table->timestamps();

            $table->unique(['provider_key', 'provider_user_id'], 'social_accounts_provider_uid_unique');
            $table->unique(['provider_key', 'user_id'], 'social_accounts_provider_user_unique');
            $table->index('email', 'social_accounts_email_index');
        });

        if (DB::getDriverName() === 'mysql') {
            Schema::table('social_accounts', function (Blueprint $table) {
                $table->comment('회원-소셜 계정 연결');
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
        Schema::dropIfExists('social_accounts');
    }
};
