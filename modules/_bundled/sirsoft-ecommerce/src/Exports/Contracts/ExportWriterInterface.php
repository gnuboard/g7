<?php

namespace Modules\Sirsoft\Ecommerce\Exports\Contracts;

use Modules\Sirsoft\Ecommerce\Exports\ExportColumn;

/**
 * 열린 내보내기 파일 하나에 쓰는 작성기
 *
 * 행을 메모리에 쌓지 않고 받는 즉시 쓴다 — 수만 행 파일도 메모리 사용이 일정해야 한다.
 */
interface ExportWriterInterface
{
    /**
     * 헤더 행을 씁니다.
     *
     * @param  array<int, ExportColumn>  $columns  열 목록 (헤더 문구 포함)
     * @return void
     */
    public function writeHeader(array $columns): void;

    /**
     * 데이터 행을 씁니다.
     *
     * @param  array<int, string|int|null>  $cells  셀 값 (열 순서)
     * @param  array<int, ExportColumn>  $columns  열 목록 (셀 종류 판정용)
     * @return void
     */
    public function writeRow(array $cells, array $columns): void;

    /**
     * 데이터 끝에 합계 행들을 씁니다(빈 행 1줄로 데이터와 구분).
     *
     * @param  array<int, array<int, string|int|null>>  $rows  합계 행 목록 (통화별 1행)
     * @param  array<int, ExportColumn>  $columns  열 목록
     * @return void
     */
    public function writeTotals(array $rows, array $columns): void;

    /**
     * 파일을 닫습니다.
     *
     * @return void
     */
    public function close(): void;
}
