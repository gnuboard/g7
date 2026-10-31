<?php

namespace Modules\Sirsoft\Ecommerce\Exports\Formats;

use Modules\Sirsoft\Ecommerce\Exports\Contracts\ExportWriterInterface;
use Modules\Sirsoft\Ecommerce\Exports\ExportColumn;
use RuntimeException;

/**
 * CSV 작성기
 *
 * - 파일 앞에 BOM — 없으면 엑셀이 UTF-8 로 읽지 않아 한글이 깨진다
 * - 줄 끝 CRLF — fputcsv 는 \n 만 붙이므로 직접 맞춘다
 * - 수식 주입 방어 — 문자열 셀이 수식 문자(ASCII·전각)나 선행 제어 문자로 시작하거나, 공백 뒤에 수식 문자가
 *   오면 앞에 ' 를 붙인다. 숫자 계열도 실제 숫자 값만 그대로 둔다.
 */
class CsvExportWriter implements ExportWriterInterface
{
    public const BOM = "\xEF\xBB\xBF";

    /**
     * 셀 맨 앞에 있을 때 수식·행 시작으로 해석될 수 있는 문자
     */
    private const FORMULA_PREFIXES = ['=', '+', '-', '@', "\0", "\x0B", "\t", "\r", "\n", '＝', '＋', '－', '＠'];

    /**
     * 앞에 붙어도 엑셀에서 무시될 수 있는 ASCII 공백·제어 문자
     */
    private const LEADING_ASCII_WHITESPACE = " \t\r\n\0\x0B";

    /**
     * @var resource 파일 핸들
     */
    private $handle;

    /**
     * @param  string  $absolutePath  쓸 파일의 절대 경로
     *
     * @throws RuntimeException 파일을 열 수 없을 때
     */
    public function __construct(string $absolutePath)
    {
        $handle = fopen($absolutePath, 'wb');

        if ($handle === false) {
            throw new RuntimeException('export_file_open_failed');
        }

        $this->handle = $handle;
        fwrite($this->handle, self::BOM);
    }

    /**
     * {@inheritDoc}
     */
    public function writeHeader(array $columns): void
    {
        $this->line(array_map(fn (ExportColumn $c) => self::guard($c->header), $columns));
    }

    /**
     * {@inheritDoc}
     */
    public function writeRow(array $cells, array $columns): void
    {
        $out = [];
        foreach ($columns as $i => $column) {
            $value = $cells[$i] ?? null;
            $out[] = self::guardCell($value, $column);
        }

        $this->line($out);
    }

    /**
     * {@inheritDoc}
     */
    public function writeTotals(array $rows, array $columns): void
    {
        if ($rows === []) {
            return;
        }

        $this->line([]);
        foreach ($rows as $row) {
            $out = [];
            foreach ($columns as $i => $column) {
                $value = $row[$i] ?? null;
                // 합계 표식(첫 칸)은 작성기가 만든 문구라 방어 대상이 아니지만 일관되게 같은 규칙을 탄다
                $out[] = self::guardCell($value, $column);
            }
            $this->line($out);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function close(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
    }

    /**
     * 문자열 셀의 수식 주입을 막습니다.
     *
     * @param  string  $value  셀 문자열
     * @return string 방어한 문자열
     */
    public static function guard(string $value): string
    {
        foreach (self::FORMULA_PREFIXES as $prefix) {
            if (str_starts_with($value, $prefix)) {
                return "'".$value;
            }
        }

        // ASCII 제어 문자와 Unicode 공백이 섞여 있어도 수식 접두까지 건너뛴다.
        // 잘못된 UTF-8이면 바이트 안전한 ASCII ltrim으로 대체한다.
        $trimmed = preg_replace('/\A[\p{Z}\x00-\x20]+/u', '', $value);
        $trimmed ??= ltrim($value, self::LEADING_ASCII_WHITESPACE);
        foreach (self::FORMULA_PREFIXES as $prefix) {
            if (str_starts_with($trimmed, $prefix)) {
                return "'".$value;
            }
        }

        return $value;
    }

    /**
     * 유효한 숫자형 셀은 그대로 두고, 그 밖의 셀 문자열에는 수식 방어를 적용합니다.
     *
     * @param  mixed  $value  셀 값
     * @param  ExportColumn  $column  셀 열 선언
     * @return string|int|float|null 안전하게 기록할 셀 값
     */
    private static function guardCell(mixed $value, ExportColumn $column): string|int|float|null
    {
        if ($value === null) {
            return null;
        }

        if ($column->field->type->isNumeric() && is_numeric($value)) {
            return $value;
        }

        return self::guard((string) $value);
    }

    /**
     * 한 줄을 CRLF 로 씁니다.
     *
     * @param  array<int, string|int|null>  $cells  셀 값
     * @return void
     */
    private function line(array $cells): void
    {
        if ($cells === []) {
            fwrite($this->handle, "\r\n");

            return;
        }

        $buffer = fopen('php://temp', 'r+');
        fputcsv($buffer, array_map(static fn ($v) => $v === null ? '' : (string) $v, $cells), ',', '"', '');
        rewind($buffer);
        $line = rtrim((string) stream_get_contents($buffer), "\r\n");
        fclose($buffer);

        fwrite($this->handle, $line."\r\n");
    }
}
