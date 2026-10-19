<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Helpers\PermissionHelper;
use App\Helpers\TimezoneHelper;
use App\Http\Resources\BaseApiCollection;
use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Models\ExportDownload;

/**
 * 내보내기 다운로드 이력 컬렉션 (다운로드 이력 모달)
 *
 * 순번은 최신이 큰 수다 — 「몇 번째로 받았는가」를 보여 주므로 총 건수에서 거꾸로 센다.
 */
class ExportDownloadCollection extends BaseApiCollection
{
    /**
     * 컬렉션을 배열로 변환합니다.
     *
     * @param  Request  $request  요청
     * @return array<string, mixed> 이력 응답
     */
    public function toArray(Request $request): array
    {
        $total = method_exists($this->resource, 'total') ? (int) $this->resource->total() : $this->collection->count();
        $offset = method_exists($this->resource, 'firstItem') ? max(0, (int) $this->resource->firstItem() - 1) : 0;

        $showIp = PermissionHelper::check('sirsoft-ecommerce.exports.personal_data', $request->user());

        $rows = [];
        foreach ($this->collection->values() as $i => $download) {
            /** @var ExportDownload $download */
            $rows[] = [
                'id' => $download->id,
                'sequence' => $total - $offset - $i,
                'user' => [
                    'name' => $download->user?->name ?? $download->user_name_snapshot,
                    'uuid' => $download->user?->uuid,
                ],
                // 받은 사람의 IP 는 개인정보다 — 개인정보 권한이 있어야 보인다
                'ip_address' => $showIp ? $download->ip_address : null,
                'downloaded_at' => $download->downloaded_at
                    ? $download->downloaded_at->copy()->setTimezone(TimezoneHelper::getUserTimezone())->format('Y-m-d H:i:s')
                    : null,
            ];
        }

        return [
            'data' => $rows,
            ...$this->paginationMeta(),
        ];
    }
}
