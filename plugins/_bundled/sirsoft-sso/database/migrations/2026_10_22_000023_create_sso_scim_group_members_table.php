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
     * SCIM 그룹 소속(그룹 ↔ 회원 연결). 그룹 삭제와 회원 탈퇴, 삭제 때는 서비스와 리스너가 명시 삭제한다(CASCADE 미사용).
     *
     * @return void
     */
    public function up(): void
    {
        if (Schema::hasTable('sso_scim_group_members')) {
            return;
        }

        Schema::create('sso_scim_group_members', function (Blueprint $table) {
            $table->foreignId('group_id')
                ->comment('SCIM 그룹 ID — CASCADE 미설정')
                ->constrained('sso_scim_groups');
            $table->foreignId('sso_account_id')
                ->comment('회원 연결 ID — CASCADE 미설정')
                ->constrained('sso_accounts');

            $table->primary(['group_id', 'sso_account_id'], 'sso_scim_group_members_primary');
            $table->index('sso_account_id', 'sso_scim_group_members_account_index');
        });

        if (DB::getDriverName() === 'mysql') {
            Schema::table('sso_scim_group_members', function (Blueprint $table) {
                $table->comment('SCIM 그룹 소속');
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
        Schema::dropIfExists('sso_scim_group_members');
    }
};
