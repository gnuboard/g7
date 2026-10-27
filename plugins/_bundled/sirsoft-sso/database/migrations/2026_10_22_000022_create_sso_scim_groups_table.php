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
     * IdP 가 SCIM 으로 보낸 그룹. 역할 매핑의 입력(그룹 출처 `scim`)으로만 쓴다 — G7 역할을 직접 만들지 않는다.
     *
     * @return void
     */
    public function up(): void
    {
        if (Schema::hasTable('sso_scim_groups')) {
            return;
        }

        Schema::create('sso_scim_groups', function (Blueprint $table) {
            $table->id()->comment('고유 ID (SCIM 응답의 그룹 id)');
            $table->foreignId('idp_id')
                ->comment('IdP ID — CASCADE 미설정')
                ->constrained('sso_identity_providers');
            $table->string('external_id', 191)->nullable()->collation('utf8mb4_bin')->comment('SCIM externalId — 대소문자 구분(utf8mb4_bin)');
            $table->string('display_name', 255)->comment('SCIM displayName — 역할 매핑 규칙의 그룹 이름과 대조');
            $table->timestamps();

            $table->unique(['idp_id', 'display_name'], 'sso_scim_groups_idp_name_unique');
            $table->index(['idp_id', 'external_id'], 'sso_scim_groups_idp_external_index');
        });

        if (DB::getDriverName() === 'mysql') {
            Schema::table('sso_scim_groups', function (Blueprint $table) {
                $table->comment('SCIM 으로 받은 IdP 그룹');
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
        Schema::dropIfExists('sso_scim_groups');
    }
};
