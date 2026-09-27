<?php

namespace App\Repositories\Analytics;

use App\Contracts\Repositories\Analytics\TableStatsRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * 표 용량 Repository
 *
 * 방문자 통계 표가 디스크를 얼마나 쓰는지 데이터베이스에 묻습니다. 이 값은 행이
 * 아니라 저장소 자체의 상태라, 모델로는 읽을 수 없어 원문 질의를 씁니다.
 */
class TableStatsRepository implements TableStatsRepositoryInterface
{
    /**
     * 지금 드라이버가 표 용량 통계를 제공하는지 확인합니다.
     *
     * @return bool 제공하면 true
     */
    public function supportsTableStats(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }

    /**
     * 표별 행 수와 크기를 읽습니다.
     *
     * @param  array<int, string>  $tables  접두 없는 표 이름 목록
     * @return array<string, array{rows: int, data_bytes: int, index_bytes: int}> 표 이름으로 묶은 현황
     */
    public function sizes(array $tables): array
    {
        if ($tables === [] || ! $this->supportsTableStats()) {
            return [];
        }

        $prefix = (string) DB::connection()->getTablePrefix();
        $names = array_map(fn (string $table): string => $prefix.$table, $tables);

        try {
            // 쿼리 빌더는 테이블 접두를 붙이므로 통계 표는 원문 질의로 읽는다.
            $rows = DB::select(
                'SELECT TABLE_NAME AS table_name, TABLE_ROWS AS table_rows, '
                .'DATA_LENGTH AS data_length, INDEX_LENGTH AS index_length '
                .'FROM information_schema.TABLES '
                .'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('
                .implode(',', array_fill(0, count($names), '?')).')',
                $names,
            );
        } catch (Throwable) {
            // 통계 표를 읽을 권한이 없는 계정도 있다 — 현황이 비어도 화면은 떠야 한다.
            return [];
        }

        $stats = [];

        foreach ($rows as $row) {
            $name = (string) $row->table_name;
            $bare = $prefix !== '' && str_starts_with($name, $prefix)
                ? substr($name, strlen($prefix))
                : $name;

            $stats[$bare] = [
                'rows' => (int) $row->table_rows,
                'data_bytes' => (int) $row->data_length,
                'index_bytes' => (int) $row->index_length,
            ];
        }

        return $stats;
    }
}
