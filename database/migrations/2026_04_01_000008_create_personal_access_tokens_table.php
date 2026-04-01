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
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name')->comment('토큰 이름');
            $table->string('token', 64)->unique()->comment('액세스 토큰');
            $table->text('abilities')->nullable()->comment('토큰 권한');
            $table->timestamp('last_used_at')->nullable()->comment('마지막 사용 시간');
            $table->timestamp('expires_at')->nullable()->index()->comment('만료 시간');
            $table->timestamps();
        });

        if (DB::getDriverName() == 'mysql') {
            Schema::table('personal_access_tokens', function (Blueprint $table) {
                $table->comment('개인 액세스 토큰 관리');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
