<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Export\Exports;

use Modules\Sirsoft\Ecommerce\Exports\ExportColumn;
use Modules\Sirsoft\Ecommerce\Exports\ExportDecimal;
use Modules\Sirsoft\Ecommerce\Exports\ExportField;
use Modules\Sirsoft\Ecommerce\Exports\ExportFieldType;
use Modules\Sirsoft\Ecommerce\Exports\Formats\CsvExportWriter;
use Modules\Sirsoft\Ecommerce\Tests\ExportTestCase;

/**
 * CSV 작성기·소수 합계 테스트
 *
 * - 엑셀이 한글을 깨뜨리지 않도록 BOM 으로 시작하고 줄 끝은 CRLF 다.
 * - `= + - @`·제어 문자·전각 수식 기호·선행 공백 뒤 수식으로 해석될 글자 값은 앞에 `'` 를 붙인다.
 *   숫자 열은 실제 숫자 값만 그대로 둔다(음수 금액 `-300` 에 따옴표가 붙으면 합계가 깨진다).
 * - 합계는 부동소수 오차 없이 정수 눈금(× 10⁴)으로 더한다.
 */
class CsvExportWriterTest extends ExportTestCase
{
    /**
     * 테스트용 열을 만듭니다.
     *
     * @param  string  $key  필드 키
     * @param  ExportFieldType  $type  값 종류
     * @return ExportColumn 열
     */
    private function column(string $key, ExportFieldType $type): ExportColumn
    {
        return new ExportColumn(
            new ExportField($key, 'label', $type, false, static fn (object $row) => null),
            $key,
        );
    }

    /**
     * 글로 쓴 파일 내용을 돌려줍니다.
     *
     * @param  callable(CsvExportWriter, array<int, ExportColumn>): void  $write  작성 콜백
     * @param  array<int, ExportColumn>  $columns  열
     * @return string 파일 내용
     */
    private function write(callable $write, array $columns): string
    {
        $path = tempnam(sys_get_temp_dir(), 'csvtest_');
        $writer = new CsvExportWriter($path);
        $write($writer, $columns);
        $writer->close();
        $content = (string) file_get_contents($path);
        @unlink($path);

        return $content;
    }

    /**
     * BOM 으로 시작하고 모든 줄이 CRLF 로 끝난다.
     *
     * @return void 반환값 없음
     *
     * @scenario format=csv
     *
     * @effects csv_starts_with_bom_and_uses_crlf
     */
    public function test_file_starts_with_bom_and_lines_end_with_crlf(): void
    {
        $columns = [$this->column('name', ExportFieldType::STRING)];
        $content = $this->write(function (CsvExportWriter $w, array $c) {
            $w->writeHeader($c);
            $w->writeRow(['홍길동'], $c);
        }, $columns);

        $this->assertStringStartsWith(CsvExportWriter::BOM, $content);
        $body = substr($content, strlen(CsvExportWriter::BOM));
        $this->assertSame("name\r\n홍길동\r\n", $body);
    }

    /**
     * 수식으로 시작하는 글자 값은 무력화하고, 숫자 열의 음수는 그대로 둔다.
     *
     * @return void 반환값 없음
     *
     * @scenario format=csv
     *
     * @effects csv_formula_cells_neutralized, csv_numeric_cells_untouched
     */
    public function test_formula_cells_are_neutralized_but_numbers_are_kept(): void
    {
        $columns = [
            $this->column('memo', ExportFieldType::STRING),
            $this->column('amount', ExportFieldType::MONEY),
        ];
        $content = $this->write(function (CsvExportWriter $w, array $c) {
            $w->writeRow(['=HYPERLINK("http://x")', '-300'], $c);
            $w->writeRow(['@SUM(A1)', '1500.5'], $c);
            $w->writeRow(['+82-10', null], $c);
            foreach (['＝1+1', '＋1+1', '－1+1', '＠SUM(A1)'] as $formula) {
                $w->writeRow([$formula, '-250'], $c);
            }
            $w->writeRow(["\u{3000} =1+1", '-250'], $c);
            $w->writeRow(["\n=1+1", '=1+1'], $c);
        }, $columns);

        $body = substr($content, strlen(CsvExportWriter::BOM));
        $lines = explode("\r\n", $body);
        $this->assertSame('"\'=HYPERLINK(""http://x"")",-300', $lines[0]);
        $this->assertSame("'@SUM(A1),1500.5", $lines[1]);
        $this->assertSame("'+82-10,", $lines[2]);
        foreach (["'＝1+1,-250\r\n", "'＋1+1,-250\r\n", "'－1+1,-250\r\n", "'＠SUM(A1),-250\r\n"] as $expected) {
            $this->assertStringContainsString($expected, $body);
        }
        $this->assertStringContainsString("\"'　 =1+1\",-250\r\n", $body);
        $this->assertStringContainsString("\"'\n=1+1\",'=1+1\r\n", $body);
    }

    /**
     * 소수 합계가 부동소수 오차 없이 더해지고, 끝자리 0 은 지워진다.
     *
     * @return void 반환값 없음
     *
     * @scenario totals=on
     *
     * @effects totals_are_exact_decimal_sums
     */
    public function test_decimal_units_sum_without_float_error(): void
    {
        $sum = 0;
        foreach (['0.1', '0.2', '45.05', '-0.35', 3] as $value) {
            $sum += ExportDecimal::toUnits($value);
        }

        $this->assertSame('48', ExportDecimal::fromUnits($sum));
        $this->assertSame('12.5', ExportDecimal::normalize('12.5000'));
        $this->assertSame('-300.25', ExportDecimal::normalize(-300.25));
        $this->assertNull(ExportDecimal::toUnits('abc'));
    }
}
