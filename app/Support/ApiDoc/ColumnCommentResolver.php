<?php

namespace App\Support\ApiDoc;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * 컬럼 주석 해석기
 *
 * 모델 테이블의 컬럼 주석(한국어 comment) 을 조회해 응답 필드 설명의 기본값으로
 * 제공합니다. G7 은 마이그레이션 한국어 comment 를 필수화하므로, 이 주석이 필드
 * 설명의 SSoT 가 됩니다. 정적 스키마 조회이므로 실측 없이도 동작합니다.
 */
class ColumnCommentResolver
{
    /**
     * @var array<string, array<string, string>> 테이블별 컬럼 주석 캐시
     */
    private array $cache = [];

    /**
     * 모델 테이블의 컬럼명 => 주석 맵을 반환합니다.
     *
     * @param  class-string|null  $modelClass  모델 FQCN
     * @return array<string, string> 컬럼명 => 주석
     */
    public function forModel(?string $modelClass): array
    {
        if (! $modelClass || ! class_exists($modelClass) || ! is_subclass_of($modelClass, Model::class)) {
            return [];
        }

        try {
            $table = (new $modelClass)->getTable();
        } catch (\Throwable) {
            return [];
        }

        return $this->forTable($table);
    }

    /**
     * 테이블의 컬럼명 => 주석 맵을 반환합니다 (캐시).
     *
     * @param  string  $table  테이블명
     * @return array<string, string> 컬럼명 => 주석
     */
    public function forTable(string $table): array
    {
        if (isset($this->cache[$table])) {
            return $this->cache[$table];
        }

        $map = [];

        try {
            foreach (Schema::getColumns($table) as $column) {
                $comment = $column['comment'] ?? null;

                if (is_string($comment) && $comment !== '') {
                    $map[$column['name']] = $comment;
                }
            }
        } catch (\Throwable) {
            // 테이블 부재/드라이버 미지원 시 빈 맵
        }

        return $this->cache[$table] = $map;
    }
}
