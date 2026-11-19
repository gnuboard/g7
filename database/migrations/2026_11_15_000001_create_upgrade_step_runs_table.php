<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * upgrade_step_runs 테이블을 신설합니다 — 업그레이드 스텝 실행 대장.
 *
 * 코어 판 번호는 서버마다 `.env` 에만 있어, DB 를 함께 쓰는 두 번째 서버(웹 여러 대 등)가 같은 스텝을 다시 돌렸다.
 * 스텝을 돌리기 전에 이 대장을 보고, 이미 끝난 스텝은 건너뛰며, 실행 선점은 유니크 삽입으로 한다.
 * 코어 스텝의 `identifier` 는 빈 문자열이다 — NULL 은 유니크 제약에서 서로 다른 값으로 취급돼 선점이 성립하지 않는다.
 * `fingerprint` 는 끝낸 스텝의 내용 지문이다 — 검수 서버의 같은 판 `--force` 재배포가 같은 내용의 스텝은 건너뛰고 고친 스텝만 다시 돌린다.
 *
 * @since 7.2.0
 */
return new class extends Migration
{
    /**
     * 테이블을 만듭니다.
     *
     * @return void 반환값 없음
     */
    public function up(): void
    {
        if (Schema::hasTable('upgrade_step_runs')) {
            return;
        }

        Schema::create('upgrade_step_runs', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 20)->comment('스텝 소유 범위 (core|module|plugin|template)');
            $table->string('identifier', 191)->default('')->comment('확장 식별자 — 코어 스텝은 빈 문자열');
            $table->string('version', 50)->comment('스텝 판 번호');
            $table->string('step', 255)->comment('스텝 식별 (스텝 클래스명 또는 클로저 스텝은 callable@판)');
            $table->char('fingerprint', 64)->nullable()->comment('스텝 내용 지문 (sha256 — 스텝 클래스 파일 + data/<판>/ 아래 파일, 클로저 스텝·지문 도입 전 행은 NULL)');
            $table->string('status', 20)->comment('실행 상태 (running|done|failed)');
            $table->string('host', 191)->nullable()->comment('실행한 서버 이름 (진단용)');
            $table->timestamp('started_at')->nullable()->comment('실행 시작 일시');
            $table->timestamp('finished_at')->nullable()->comment('실행 종료 일시');
            $table->timestamps();

            $table->unique(['scope', 'identifier', 'version', 'step'], 'upgrade_step_runs_unique_step');
            $table->comment('업그레이드 스텝 실행 대장 — DB 를 공유하는 서버 간 중복 실행 방지');
        });
    }

    /**
     * 테이블을 지웁니다.
     *
     * @return void 반환값 없음
     */
    public function down(): void
    {
        Schema::dropIfExists('upgrade_step_runs');
    }
};
