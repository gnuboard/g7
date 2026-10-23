<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 시험 프로브 테이블을 만듭니다.
     *
     * @return void 반환값 없음
     */
    public function up(): void
    {
        Schema::create('zztest_seedprov_probes', function (Blueprint $table) {
            $table->id();
            $table->string('name')->comment('프로브 이름');
        });
    }

    /**
     * 시험 프로브 테이블을 지웁니다.
     *
     * @return void 반환값 없음
     */
    public function down(): void
    {
        Schema::dropIfExists('zztest_seedprov_probes');
    }
};
