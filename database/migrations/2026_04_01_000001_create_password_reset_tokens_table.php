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
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary()->comment('비밀번호 재설정 요청 이메일');
            $table->string('token')->comment('비밀번호 재설정 토큰');
            $table->timestamp('created_at')->nullable()->comment('생성일시');
        });

        if (DB::getDriverName() == 'mysql') {
            Schema::table('password_reset_tokens', function (Blueprint $table) {
                $table->comment('비밀번호 재설정 토큰 정보');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens');
    }
};
