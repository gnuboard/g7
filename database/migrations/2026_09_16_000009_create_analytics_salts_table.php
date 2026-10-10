<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 방문자 통계 일자별 솔트 테이블을 생성합니다.
     *
     * 익명 모드에서 방문자를 구분하는 해시는 날마다 바뀌는 이 솔트로 만듭니다. 솔트가
     * 며칠 뒤 사라지면 어제의 해시와 오늘의 해시를 이어 붙일 방법이 없어져, 같은 사람을
     * 여러 날에 걸쳐 추적하는 일이 원리적으로 불가능해집니다.
     *
     * @return void
     */
    public function up(): void
    {
        if (Schema::hasTable('analytics_salts')) {
            return;
        }

        Schema::create('analytics_salts', function (Blueprint $table) {
            $table->date('salt_date')->primary()->comment('솔트 일자 (사이트 표준시 기준)');
            $table->char('salt', 64)->comment('그날의 임의 솔트 — 며칠 뒤 정리 배치가 지운다');
            $table->timestamp('created_at')->useCurrent()->comment('생성 시각');
        });
    }

    /**
     * 방문자 통계 일자별 솔트 테이블을 삭제합니다.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('analytics_salts');
    }
};
