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
     * SCIM 수신 이력. 요청 본문은 저장하지 않는다(개인정보) — 메서드, 자원, 대상 id, 상태 코드, 오류 코드만 남긴다.
     * IdP 를 지워도 이력은 남기므로 FK 를 두지 않는다.
     *
     * @return void
     */
    public function up(): void
    {
        if (Schema::hasTable('sso_scim_events')) {
            return;
        }

        Schema::create('sso_scim_events', function (Blueprint $table) {
            $table->id()->comment('고유 ID');
            $table->unsignedBigInteger('idp_id')->nullable()->comment('IdP ID (FK 없음 — 이력)');
            $table->string('method', 6)->comment('HTTP 메서드');
            $table->string('resource', 25)->comment('SCIM 자원 — Users, Groups, Schemas, ResourceTypes, ServiceProviderConfig');
            $table->string('target_id', 191)->nullable()->comment('대상 자원 id');
            $table->unsignedSmallInteger('status')->comment('응답 상태 코드');
            $table->string('error_code', 50)->nullable()->comment('SCIM 오류 종류(scimType) 또는 내부 오류 코드');
            $table->timestamp('created_at')->nullable()->comment('수신 시각');

            $table->index(['idp_id', 'created_at'], 'sso_scim_events_idp_created_index');
        });

        if (DB::getDriverName() === 'mysql') {
            Schema::table('sso_scim_events', function (Blueprint $table) {
                $table->comment('SCIM 수신 이력');
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
        Schema::dropIfExists('sso_scim_events');
    }
};
