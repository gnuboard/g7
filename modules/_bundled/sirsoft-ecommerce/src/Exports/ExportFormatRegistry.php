<?php

namespace Modules\Sirsoft\Ecommerce\Exports;

use App\Extension\HookManager;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Exports\Contracts\ExportFormatInterface;
use Modules\Sirsoft\Ecommerce\Exports\Formats\CsvExportFormat;

/**
 * 내보내기 파일 형식 레지스트리
 *
 * 기본 CSV + 확장이 `sirsoft-ecommerce.export.registered_formats` 필터로 더한 형식(예: XLSX 플러그인).
 */
class ExportFormatRegistry
{
    /**
     * 해석된 형식 캐시
     *
     * @var array<string, ExportFormatInterface>|null
     */
    private ?array $formats = null;

    /**
     * 등록된 형식 전부를 돌려줍니다.
     *
     * @return array<string, ExportFormatInterface> 형식 키 → 형식
     */
    public function all(): array
    {
        if ($this->formats !== null) {
            return $this->formats;
        }

        $csv = new CsvExportFormat;
        $formats = [$csv->key() => $csv];

        $registered = HookManager::applyFilters('sirsoft-ecommerce.export.registered_formats', $formats);

        $valid = [];
        foreach (is_array($registered) ? $registered : $formats as $format) {
            if ($format instanceof ExportFormatInterface) {
                $valid[$format->key()] = $format;
            } else {
                Log::warning('[내보내기] ExportFormatInterface 가 아닌 형식 등록을 무시했습니다');
            }
        }

        return $this->formats = $valid;
    }

    /**
     * 키로 형식을 찾습니다.
     *
     * @param  string  $key  형식 키
     * @return ExportFormatInterface|null 형식
     */
    public function find(string $key): ?ExportFormatInterface
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * 등록된 형식 키 목록
     *
     * @return array<int, string> 형식 키
     */
    public function keys(): array
    {
        return array_keys($this->all());
    }
}
