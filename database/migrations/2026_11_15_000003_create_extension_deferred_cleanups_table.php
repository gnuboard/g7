<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * extension_deferred_cleanups 테이블을 신설합니다 — 검수 서버가 미뤄 둔 선언형 정리 대장.
 *
 * 검수 서버(실행 역할 stage)에서 확장을 업데이트하면, 새 판 선언에서 빠진 메뉴·권한·알림 정의 등을 지우는 정리가 운영 서버와
 * 공유하는 DB 에서 곧바로 돌아 운영 화면이 먼저 바뀌었다. stage 는 지우지 않고 「이 확장의 선언 정리가 필요하다」만 이 대장에
 * 적는다. 운영 서버의 `extension:apply-deferred-cleanup` 은 지울 대상을 검수 서버 시점의 목록이 아니라 실행하는 순간의 그 서버
 * 확장 파일 선언과 공유 DB(게시판 목록·사용 중인 시드 행)로 다시 정한다 — 검수 서버에 여러 번 배포하거나 배포 뒤 운영 서버에서
 * 데이터가 생겨도 낡은 목록으로 지우지 않는다.
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
        if (Schema::hasTable('extension_deferred_cleanups')) {
            return;
        }

        Schema::create('extension_deferred_cleanups', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 20)->comment('정리 대상 소유 범위 (module|plugin|core)');
            $table->string('identifier', 191)->default('')->comment('확장 식별자 — 코어 정리는 빈 문자열');
            $table->string('kind', 20)->comment('정리 종류 (declarations: 선언 항목 다시 동기화 | seeder: 시드 정리를 맡은 시더 다시 실행)');
            $table->string('target', 191)->default('')->comment('seeder 종류의 시더 클래스 — 그 밖은 빈 문자열');
            $table->string('recorded_version', 32)->nullable()->comment('코어 정리를 적을 때의 코어 판 — 운영 서버 코어가 이 판 이상일 때만 실행');
            $table->char('code_fingerprint', 64)->nullable()->comment('적을 때 검수 서버 코드 내용 지문(sha256 — 확장은 활성 폴더, 코어는 config/core.php) — 운영 서버 파일 지문이 같을 때만 실행');
            $table->timestamps();

            $table->unique(['scope', 'identifier', 'kind', 'target'], 'extension_deferred_cleanups_unique');
            $table->comment('검수 서버가 미뤄 둔 선언형 정리 — 운영 서버가 실행');
        });
    }

    /**
     * 테이블을 지웁니다.
     *
     * @return void 반환값 없음
     */
    public function down(): void
    {
        Schema::dropIfExists('extension_deferred_cleanups');
    }
};
