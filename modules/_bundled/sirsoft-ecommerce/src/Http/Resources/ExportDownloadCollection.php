<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Helpers\PermissionHelper;
use App\Helpers\TimezoneHelper;
use App\Http\Resources\BaseApiCollection;
use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Models\Export;
use Modules\Sirsoft\Ecommerce\Models\ExportDownload;
use Modules\Sirsoft\Ecommerce\Support\ExportAccess;
use Modules\Sirsoft\Ecommerce\Support\MemberDisplayName;

/**
 * 내보내기 다운로드 이력 컬렉션 (다운로드 이력 모달)
 *
 * 순번은 최신이 큰 수다 — 「몇 번째로 받았는가」를 보여 주므로 총 건수에서 거꾸로 센다.
 */
class ExportDownloadCollection extends BaseApiCollection
{
    /**
     * 이력의 작업 — 받은 사람 IP 를 보일지 그 작업의 개인정보 범위로 정한다
     */
    private ?Export $export = null;

    /**
     * 이력의 작업을 정합니다.
     *
     * @param  Export  $export  작업
     * @return static 이 컬렉션
     */
    public function forExport(Export $export): static
    {
        $this->export = $export;

        return $this;
    }

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

        // 받은 사람의 IP 는 개인정보다 — 개인정보 권한이 있고 이 작업이 그 권한의 범위 안이어야 보인다
        $user = $request->user();
        $showIp = $this->export !== null
            ? ExportAccess::inScope($this->export, $user, ExportAccess::PERSONAL_PERMISSION)
            : PermissionHelper::check(ExportAccess::PERSONAL_PERMISSION, $user);

        $rows = [];
        foreach ($this->collection->values() as $i => $download) {
            /** @var ExportDownload $download */
            $rows[] = [
                'id' => $download->id,
                'sequence' => $total - $offset - $i,
                'user' => [
                    // 받은 사람 이름은 받을 때 남긴 이름 그대로다(기록물). 남긴 이름이 없는 행만 이커머스 관리자 영역의 표시 이름으로 보인다.
                    'name' => filled($download->user_name_snapshot)
                        ? $download->user_name_snapshot
                        : MemberDisplayName::name($download->user, MemberDisplayName::AREA_ADMIN),
                    'uuid' => $download->user?->uuid,
                ],
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
