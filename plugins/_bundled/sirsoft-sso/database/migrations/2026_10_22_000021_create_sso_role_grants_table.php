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
     * 역할 매핑이 실제로 붙인 역할만 기록한다. 「매핑 역할만 회수」 모드는 이 표에 있는 역할만 뗀다 — 회원이 이미 수동으로
     * 갖고 있던 역할은 기록하지 않으므로 회수되지 않는다. 회원 탈퇴, 삭제 때는 리스너가 명시 삭제한다(CASCADE 미사용).
     *
     * @return void
     */
    public function up(): void
    {
        if (Schema::hasTable('sso_role_grants')) {
            return;
        }

        Schema::create('sso_role_grants', function (Blueprint $table) {
            $table->id()->comment('고유 ID');
            $table->foreignId('user_id')
                ->comment('회원 ID — CASCADE 미설정 (회원 탈퇴, 삭제 시 리스너가 명시 삭제)')
                ->constrained('users');
            $table->foreignId('role_id')
                ->comment('매핑이 붙인 역할 ID — CASCADE 미설정')
                ->constrained('roles');
            $table->foreignId('idp_id')
                ->comment('매핑 규칙을 가진 IdP ID — CASCADE 미설정')
                ->constrained('sso_identity_providers');
            $table->timestamp('granted_at')->nullable()->comment('매핑이 역할을 붙인 시각');

            $table->unique(['user_id', 'role_id', 'idp_id'], 'sso_role_grants_user_role_idp_unique');
        });

        if (DB::getDriverName() === 'mysql') {
            Schema::table('sso_role_grants', function (Blueprint $table) {
                $table->comment('IdP 그룹 역할 매핑이 붙인 역할 기록');
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
        Schema::dropIfExists('sso_role_grants');
    }
};
