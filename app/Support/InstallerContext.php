<?php

namespace App\Support;

/**
 * 부팅 시점 "DB 스키마 준비성" 판정에 쓰이는 인스톨러 컨텍스트 헬퍼.
 *
 * `config('app.installer_completed')` fast-path 는 프로덕션에서 `Schema::hasTable()`
 * 호출을 건너뛰어 매 요청 오버헤드를 제거한다. 그러나 `INSTALLER_COMPLETED=true` 가
 * 기록된 `.env` 를 빈 DB 새 서버에 복사한 뒤 `php artisan migrate` 로 테이블을
 * 만들기 전에 앱이 부팅되면, fast-path 가 테이블 존재를 잘못 전제하여 뒤따르는
 * 쿼리가 "table not found" 로 부팅을 깨뜨린다.
 *
 * 이 헬퍼는 스키마를 파괴/재생성하는 마이그레이션 계열 콘솔 명령 판정을 단일
 * SSoT 로 캡슐화하여, 해당 컨텍스트에서 fast-path 를 무력화하는 가드에 쓰인다.
 */
class InstallerContext
{
    /**
     * 스키마를 파괴/재생성하는 콘솔 명령(마이그레이션 계열)이 실행 중인지 판정합니다.
     *
     * 이 컨텍스트에서는 `installer_completed=true` 라도 테이블이 아직 없을 수 있으므로
     * fast-path(hasTable 스킵)를 신뢰해서는 안 됩니다. 웹 요청 등 콘솔이 아닌
     * 실행 경로에서는 항상 false 를 반환합니다.
     *
     * @return bool 마이그레이션 계열 명령 실행 중 여부
     */
    public static function isSchemaMutatingCommand(): bool
    {
        if (! app()->runningInConsole()) {
            return false;
        }

        return in_array(
            $_SERVER['argv'][1] ?? null,
            ['migrate', 'migrate:fresh', 'migrate:refresh', 'migrate:rollback', 'migrate:reset', 'db:wipe'],
            true
        );
    }
}
