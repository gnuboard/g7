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
     * IdP 가 준 회원 추가 속성(부서, 직급, 사번 등). 코어 `users` 스키마는 바꾸지 않고 이 표에 키/값으로 둔다.
     * 회원 탈퇴, 삭제 때는 리스너가 명시 삭제한다(CASCADE 미사용).
     *
     * @return void
     */
    public function up(): void
    {
        if (Schema::hasTable('sso_user_attributes')) {
            return;
        }

        Schema::create('sso_user_attributes', function (Blueprint $table) {
            $table->id()->comment('고유 ID');
            $table->foreignId('user_id')
                ->comment('회원 ID — CASCADE 미설정 (회원 탈퇴, 삭제 시 리스너가 명시 삭제)')
                ->constrained('users');
            $table->foreignId('idp_id')
                ->comment('값을 준 IdP ID — CASCADE 미설정')
                ->constrained('sso_identity_providers');
            $table->string('key', 50)->comment('속성 키 (예: department, title, employee_number)');
            $table->text('value')->nullable()->comment('속성 값');
            $table->timestamp('synced_at')->nullable()->comment('마지막 동기화 시각');

            $table->unique(['user_id', 'idp_id', 'key'], 'sso_user_attributes_user_idp_key_unique');
            $table->index('key', 'sso_user_attributes_key_index');
        });

        if (DB::getDriverName() === 'mysql') {
            Schema::table('sso_user_attributes', function (Blueprint $table) {
                $table->comment('회원의 IdP 추가 속성');
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
        Schema::dropIfExists('sso_user_attributes');
    }
};
