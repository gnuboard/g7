<?php

namespace Plugins\Sirsoft\Ckeditor5\Repositories;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Plugins\Sirsoft\Ckeditor5\Repositories\Contracts\ImageReferenceSourceRepositoryInterface;

/**
 * 에디터 이미지 참조 소스 조회 Repository 구현체
 *
 * 테이블명은 확장이 훅으로 선언한 값이라 런타임에 정해집니다. raw SQL 대신 쿼리빌더를
 * 쓰는 이유는 테이블 프리픽스를 빌더가 붙이고 값 바인딩도 빌더가 처리하기 때문입니다.
 */
class ImageReferenceSourceRepository implements ImageReferenceSourceRepositoryInterface
{
    /**
     * {@inheritDoc}
     */
    public function resolveExistingColumns(string $table, array $columns): array
    {
        if ($table === '' || $columns === [] || ! Schema::hasTable($table)) {
            return [];
        }

        return array_values(array_filter(
            $columns,
            fn (string $column) => Schema::hasColumn($table, $column)
        ));
    }

    /**
     * {@inheritDoc}
     */
    public function containsAnyToken(string $table, array $columns, array $tokens): bool
    {
        if ($columns === [] || $tokens === []) {
            return false;
        }

        return DB::table($table)
            ->where(function (Builder $query) use ($columns, $tokens) {
                foreach ($columns as $column) {
                    foreach ($tokens as $token) {
                        $query->orWhere($column, 'like', '%'.$this->escapeLike($token).'%');
                    }
                }
            })
            ->exists();
    }

    /**
     * {@inheritDoc}
     */
    public function findTokensInSource(string $table, array $columns, array $tokens): array
    {
        if ($columns === [] || $tokens === []) {
            return [];
        }

        // id 키셋 순회가 불가능한 테이블은 토큰별 존재 확인으로 폴백한다.
        if (! Schema::hasColumn($table, 'id')) {
            return array_values(array_filter(
                $tokens,
                fn (string $token) => $this->containsAnyToken($table, $columns, [$token])
            ));
        }

        $pending = array_values(array_unique($tokens));
        $found = [];

        // LIKE 는 인덱스를 못 쓰므로 토큰별 쿼리는 (토큰 수 × 전체 스캔) 이 된다.
        // 대신 소스를 키셋 청크로 1회만 읽고 PHP strpos 로 대조한다 — 비용이
        // (테이블 크기 1회 + 행×잔여토큰 strpos) 로 고정되고, 토큰이 모두 발견되면
        // 조기 종료한다.
        DB::table($table)
            ->select(array_merge(['id'], $columns))
            ->lazyById(200)
            ->each(function ($row) use ($columns, &$pending, &$found) {
                foreach ($columns as $column) {
                    $content = (string) ($row->{$column} ?? '');

                    if ($content === '') {
                        continue;
                    }

                    foreach ($pending as $index => $token) {
                        if (str_contains($content, $token)) {
                            $found[] = $token;
                            unset($pending[$index]);
                        }
                    }
                }

                return $pending !== []; // 전 토큰 발견 시 순회 중단
            });

        return $found;
    }

    /**
     * LIKE 와일드카드를 이스케이프합니다.
     *
     * 원본명·해시에 `%`/`_` 가 포함되면 임의 문자로 해석돼 무관한 본문이 매칭됩니다
     * (참조됨 오판 → 정리되지 않고 영구 잔존).
     *
     * @param  string  $value  원본 값
     * @return string 이스케이프된 값
     */
    private function escapeLike(string $value): string
    {
        return addcslashes($value, '\\%_');
    }
}
