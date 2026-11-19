<?php

namespace App\Models\Concerns;

use App\Exceptions\StageLayoutWriteException;
use App\Support\LayoutTable;

/**
 * 레이아웃 모델의 테이블을 실행 역할에 맞춥니다 — 검수 서버(stage)는 `stage_` 접두 테이블을 읽고 쓴다.
 *
 * 쓰기 방어: 어떤 경로로든(명시 `setTable()` 등) stage 역할이 운영 테이블에, live 역할이 스테이지 테이블에 쓰려 하면 예외를 던진다.
 *
 * @since 7.2.0
 */
trait UsesRuntimeRoleLayoutTable
{
    /**
     * 쓰기 방어 이벤트를 겁니다.
     *
     * @return void 반환값 없음
     */
    public static function bootUsesRuntimeRoleLayoutTable(): void
    {
        $guard = function ($model): void {
            if (LayoutTable::isForbiddenWriteTarget($model->getTable())) {
                throw new StageLayoutWriteException($model->getTable());
            }
        };

        static::saving($guard);
        static::deleting($guard);
    }

    /**
     * 실행 역할에 맞는 테이블 이름.
     *
     * @return string 테이블 이름
     */
    public function getTable()
    {
        $table = parent::getTable();

        // 명시로 스테이지 테이블을 지정한 인스턴스는 그대로 둔다(쓰기 방어가 판정)
        if (str_starts_with($table, LayoutTable::STAGE_PREFIX)) {
            return $table;
        }

        return LayoutTable::name($table);
    }
}
