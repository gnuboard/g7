<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Helpers\PermissionHelper;
use App\Http\Resources\BaseApiResource;
use App\Http\Resources\Traits\ExposesMemberContact;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Sirsoft\Ecommerce\Enums\ExportStatusEnum;
use Modules\Sirsoft\Ecommerce\Exports\ExportTypeRegistry;
use Modules\Sirsoft\Ecommerce\Models\Export;
use Modules\Sirsoft\Ecommerce\Support\ExportAccess;

/**
 * 내보내기 작업 리소스 (관리 화면 목록·상세)
 *
 * 저장 경로·실행 컨텍스트·중복 방지 키는 싣지 않는다. 요청자 이메일은 canExposeMemberEmail 게이트를 통과할 때만 싣는다. 행별 권한(`abilities`)은 권한·범위에 상태 조건을
 * 더해 판정한다 — 화면은 이 값만 보고 링크·행 메뉴를 잠근다.
 *
 * @mixin Export
 */
class ExportResource extends BaseApiResource
{
    use ExposesMemberContact;

    /**
     * 리소스를 배열로 변환합니다.
     *
     * @param  Request  $request  요청
     * @return array<string, mixed> 작업 배열
     */
    public function toArray(Request $request): array
    {
        /** @var Export $export */
        $export = $this->resource;
        $type = app(ExportTypeRegistry::class)->find($export->type);
        $options = (array) $export->options;
        $status = $export->status;
        $total = (int) ($export->total_rows ?? 0);
        $processed = (int) ($export->processed_rows ?? 0);

        return [
            'id' => $export->id,
            'uuid' => $export->uuid,
            'type' => $export->type,
            'type_label' => $type ? __($type->labelKey()) : $export->type,
            'format' => $export->format,
            'status' => $status->value,
            'status_label' => $status->label(),
            'requester' => [
                'name' => $export->requester?->name ?? $export->requester_name_snapshot,
                'email' => $export->requester && $this->canExposeMemberEmail($request, (int) $export->requester->id) ? $export->requester->email : null,
                'uuid' => $export->requester?->uuid,
            ],
            'file_name' => $export->file_name,
            'file_size' => $export->file_size,
            'file_size_label' => $export->file_size !== null ? $this->formatFileSize((int) $export->file_size) : null,
            'total_rows' => $export->total_rows,
            'processed_rows' => $processed,
            'progress_percent' => $total > 0 ? min(100, (int) floor($processed * 100 / $total)) : ($status === ExportStatusEnum::COMPLETED ? 100 : 0),
            'download_count' => (int) $export->download_count,
            'contains_personal_data' => (bool) $export->contains_personal_data,
            'reason' => $export->reason,
            'warnings' => array_map(fn (string $code) => [
                'code' => $code,
                'label' => __('sirsoft-ecommerce::export.warnings.'.$code.'.short'),
            ], (array) ($export->warnings ?? [])),
            'error_code' => $export->error_code,
            'error_message' => $export->error_code ? __('sirsoft-ecommerce::exceptions.export.'.$export->error_code) : null,
            'summary' => $this->summary($options, $type?->rowUnits() ?? []),
            'requested_at' => $this->formatDateTimeStringForUser($export->requested_at),
            'cutoff_at' => $this->formatDateTimeStringForUser($export->cutoff_at),
            'started_at' => $this->formatDateTimeStringForUser($export->started_at),
            'completed_at' => $this->formatDateTimeStringForUser($export->completed_at),
            'expires_at' => $this->formatDateTimeStringForUser($export->expires_at),
            ...$this->resourceMeta($request),
        ];
    }

    /**
     * 요청 조건 요약 (펼침 줄·다운로드 이력 모달)
     *
     * @param  array<string, mixed>  $options  작업 옵션
     * @param  array<string, string>  $rowUnits  행 단위 → 라벨 키
     * @return array<string, mixed> 요약
     */
    private function summary(array $options, array $rowUnits): array
    {
        $period = (array) ($options['period'] ?? []);
        $periodText = ($period['mode'] ?? 'current') === 'custom' && ! empty($period['start_date'])
            ? ($period['start_date'].' ~ '.($period['end_date'] ?? ''))
            : __('sirsoft-ecommerce::export.summary.period_current');

        $unit = (string) ($options['row_unit'] ?? '');

        return [
            'scope' => __('sirsoft-ecommerce::export.summary.scope.'.($options['scope'] ?? 'filter')),
            'period' => $periodText,
            'row_unit' => isset($rowUnits[$unit]) ? __($rowUnits[$unit]) : $unit,
            'locale_mode' => __('sirsoft-ecommerce::export.summary.locale_mode.'.($options['locale_mode'] ?? 'locale')),
            'include_totals' => (bool) ($options['include_totals'] ?? false),
            'field_count' => count((array) ($options['fields'] ?? [])),
            'selected_count' => (int) ($options['selected_count'] ?? 0),
        ];
    }

    /**
     * 소유자 필드 — 요청자 본인 여부(`is_owner`)
     *
     * @return string|null 소유자 필드명
     */
    protected function ownerField(): ?string
    {
        return 'requested_by';
    }

    /**
     * 행별 권한 — 권한·범위(본인/전체)에 상태 조건을 더한다.
     *
     * @param  Request  $request  요청
     * @return array<string, bool> 권한 맵
     */
    protected function resolveAbilities(Request $request): array
    {
        // 목록 묶음 안에서는 리소스가 한 겹 더 감싸여 온다 — 모델까지 벗긴다
        $export = $this->resource;
        while ($export instanceof JsonResource) {
            $export = $export->resource;
        }
        /** @var Export $export */
        $user = $request->user();
        $type = app(ExportTypeRegistry::class)->find($export->type);
        $domainRead = $type !== null && PermissionHelper::check($type->readPermission(), $user);
        $personalOk = ! $export->contains_personal_data || PermissionHelper::check('sirsoft-ecommerce.exports.personal_data', $user);
        // 범위 — `exports.all` 이 없으면 본인이 요청한 작업만 다룬다
        $inScope = ExportAccess::inScope($export, $user);

        $base = $this->resolveAbilitiesFromMap([
            'can_view_downloads' => 'sirsoft-ecommerce.exports.read',
            'can_download' => 'sirsoft-ecommerce.exports.download',
            'can_delete' => 'sirsoft-ecommerce.exports.delete',
            'can_retry' => 'sirsoft-ecommerce.exports.create',
        ], $user);

        return [
            'can_view_downloads' => $base['can_view_downloads'] && $inScope,
            // 남이 만든 파일은 원본 조회 범위가 「전체」여야 받는다 — 판정은 컨트롤러와 같은 한곳
            'can_download' => $base['can_download'] && $inScope && $export->isDownloadable() && ExportAccess::canReadSource($export, $user, $type),
            'can_delete' => $base['can_delete'] && $inScope && $export->status->isDeletable(),
            'can_retry' => $base['can_retry'] && $inScope && $export->status === ExportStatusEnum::FAILED && $domainRead && $personalOk,
            // 남의 개인정보 작업을 다시 요청하면 자기 사유를 남겨야 한다(창이 사유 칸을 연다)
            'retry_needs_reason' => $export->contains_personal_data && (int) $export->requested_by !== (int) $user?->id,
        ];
    }
}
