<?php

namespace Modules\Sirsoft\Ecommerce\Exports;

use App\Extension\HookManager;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Exports\Contracts\ExportTypeInterface;
use Modules\Sirsoft\Ecommerce\Exports\Types\MileageExportType;
use Modules\Sirsoft\Ecommerce\Exports\Types\OrderExportType;
use Modules\Sirsoft\Ecommerce\Exports\Types\ProductExportType;

/**
 * 내보내기 유형 레지스트리
 *
 * 기본 3종(주문내역·상품내역·마일리지 내역) + 확장이 `sirsoft-ecommerce.export.registered_types` 필터로
 * 더한 유형. 같은 키를 다시 등록하면 뒤엣것이 이긴다.
 */
class ExportTypeRegistry
{
    /**
     * 해석된 유형 캐시
     *
     * @var array<string, ExportTypeInterface>|null
     */
    private ?array $types = null;

    /**
     * 등록된 유형 전부를 돌려줍니다.
     *
     * @return array<string, ExportTypeInterface> 유형 키 → 유형
     */
    public function all(): array
    {
        if ($this->types !== null) {
            return $this->types;
        }

        $types = [];
        foreach ([OrderExportType::class, ProductExportType::class, MileageExportType::class] as $class) {
            $type = app($class);
            $types[$type->key()] = $type;
        }

        $registered = HookManager::applyFilters('sirsoft-ecommerce.export.registered_types', $types);

        $valid = [];
        foreach (is_array($registered) ? $registered : $types as $type) {
            if ($type instanceof ExportTypeInterface) {
                $valid[$type->key()] = $type;
            } else {
                Log::warning('[내보내기] ExportTypeInterface 가 아닌 유형 등록을 무시했습니다');
            }
        }

        return $this->types = $valid;
    }

    /**
     * 키로 유형을 찾습니다.
     *
     * @param  string  $key  유형 키
     * @return ExportTypeInterface|null 유형
     */
    public function find(string $key): ?ExportTypeInterface
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * 등록된 유형 키 목록
     *
     * @return array<int, string> 유형 키
     */
    public function keys(): array
    {
        return array_keys($this->all());
    }
}
