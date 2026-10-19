<?php

namespace Modules\Sirsoft\Ecommerce\Exports\Formats;

use Modules\Sirsoft\Ecommerce\Exports\Contracts\ExportFormatInterface;
use Modules\Sirsoft\Ecommerce\Exports\Contracts\ExportWriterInterface;

/**
 * CSV 형식 (UTF-8 BOM · CRLF · 수식 주입 방어)
 */
class CsvExportFormat implements ExportFormatInterface
{
    public const KEY = 'csv';

    /**
     * {@inheritDoc}
     */
    public function key(): string
    {
        return self::KEY;
    }

    /**
     * {@inheritDoc}
     */
    public function labelKey(): string
    {
        return 'sirsoft-ecommerce::export.formats.csv';
    }

    /**
     * {@inheritDoc}
     */
    public function extension(): string
    {
        return 'csv';
    }

    /**
     * {@inheritDoc}
     */
    public function mimeType(): string
    {
        return 'text/csv; charset=UTF-8';
    }

    /**
     * {@inheritDoc}
     */
    public function version(): string
    {
        return '1';
    }

    /**
     * {@inheritDoc}
     */
    public function createWriter(string $absolutePath): ExportWriterInterface
    {
        return new CsvExportWriter($absolutePath);
    }
}
