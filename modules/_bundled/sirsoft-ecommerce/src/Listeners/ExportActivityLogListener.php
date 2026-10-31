<?php

namespace Modules\Sirsoft\Ecommerce\Listeners;

use App\ActivityLog\Traits\ResolvesActivityLogType;
use App\Contracts\Extension\HookListenerInterface;
use App\Models\User;
use Modules\Sirsoft\Ecommerce\Models\Export;

/**
 * 내보내기 활동 로그 리스너 (R14 — 모든 액션을 남긴다)
 *
 * 사용자 행위(요청·거부·다운로드·이력 조회·삭제·다시 요청)와 시스템 전이(시작·완료·실패·만료)를 사건마다
 * 1건씩 남긴다. 로그에는 작업 ID·유형·행 수·사유 키만 싣고 CSV 내용·검색어 원문·고객 개인정보·저장 경로는
 * 싣지 않는다. 설명의 파일명 자리는 `ActivityLogDescriptionResolver` 가 작업 ID 로 해석한다(삭제 뒤에도
 * 보이도록 properties.name 에 파일명 스냅샷을 함께 둔다).
 *
 * 모든 구독이 `'sync' => true` 다 — 기록이 작업 모델의 「지금」 값(파일명·다운로드 순번·행 수)을 읽으므로,
 * 큐로 미루면 워커가 돌 때의 값(이미 다음 상태로 넘어갔거나 삭제된 작업)이 남는다(클레임 활동 로그와 같은 이유).
 */
class ExportActivityLogListener implements HookListenerInterface
{
    use ResolvesActivityLogType;

    /**
     * 구독할 훅과 메서드 매핑 반환
     *
     * @return array<string, array<string, mixed>> 훅 매핑
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-ecommerce.export.after_request' => ['method' => 'handleAfterRequest', 'priority' => 20, 'sync' => true],
            'sirsoft-ecommerce.export.request_denied' => ['method' => 'handleRequestDenied', 'priority' => 20, 'sync' => true],
            'sirsoft-ecommerce.export.after_start' => ['method' => 'handleAfterStart', 'priority' => 20, 'sync' => true],
            'sirsoft-ecommerce.export.after_complete' => ['method' => 'handleAfterComplete', 'priority' => 20, 'sync' => true],
            'sirsoft-ecommerce.export.after_fail' => ['method' => 'handleAfterFail', 'priority' => 20, 'sync' => true],
            'sirsoft-ecommerce.export.after_download' => ['method' => 'handleAfterDownload', 'priority' => 20, 'sync' => true],
            'sirsoft-ecommerce.export.after_view_downloads' => ['method' => 'handleAfterViewDownloads', 'priority' => 20, 'sync' => true],
            'sirsoft-ecommerce.export.after_delete' => ['method' => 'handleAfterDelete', 'priority' => 20, 'sync' => true],
            'sirsoft-ecommerce.export.after_expire' => ['method' => 'handleAfterExpire', 'priority' => 20, 'sync' => true],
            'sirsoft-ecommerce.export.after_retry' => ['method' => 'handleAfterRetry', 'priority' => 20, 'sync' => true],
        ];
    }

    /**
     * 기본 핸들러 (개별 메서드에서 처리)
     *
     * @param  mixed  ...$args  훅 인수
     * @return void
     */
    public function handle(...$args): void {}

    /**
     * 내보내기 요청 접수
     *
     * @param  Export  $export  작업
     * @param  User|null  $user  요청자
     * @return void
     */
    public function handleAfterRequest(Export $export, ?User $user = null): void
    {
        $options = (array) $export->options;

        $this->log('export.request', $export, 'export_request', [
            'type' => $export->type,
            'format' => $export->format,
            'scope' => $options['scope'] ?? null,
            'period' => $options['period'] ?? null,
            'row_unit' => $options['row_unit'] ?? null,
            'field_count' => count((array) ($options['fields'] ?? [])),
            'contains_personal_data' => (bool) $export->contains_personal_data,
            'reason' => $export->reason,
            'warnings' => (array) ($export->warnings ?? []),
        ]);
    }

    /**
     * 내보내기 요청 거부 (권한·동시 요청 상한)
     *
     * @param  string  $type  요청한 유형 키
     * @param  string  $reason  거부 사유 키 (forbidden · too_many_pending)
     * @param  User|null  $user  요청자
     * @return void
     */
    public function handleRequestDenied(string $type, string $reason, ?User $user = null): void
    {
        $this->logActivity('export.request_denied', [
            'description_key' => 'sirsoft-ecommerce::activity_log.description.export_request_denied',
            'description_params' => ['type' => $type, 'reason' => $reason],
            'properties' => ['type' => $type, 'reason' => $reason],
        ]);
    }

    /**
     * 처리 시작
     *
     * @param  Export  $export  작업
     * @return void
     */
    public function handleAfterStart(Export $export): void
    {
        $this->log('export.start', $export, 'export_start', ['total_rows' => $export->total_rows]);
    }

    /**
     * 생성 완료
     *
     * @param  Export  $export  작업
     * @return void
     */
    public function handleAfterComplete(Export $export): void
    {
        $this->log('export.complete', $export, 'export_complete', [
            'rows' => $export->processed_rows,
            'file_size' => $export->file_size,
        ]);
    }

    /**
     * 생성 실패
     *
     * @param  Export  $export  작업
     * @param  string|null  $code  실패 사유 키
     * @return void
     */
    public function handleAfterFail(Export $export, ?string $code = null): void
    {
        $this->log('export.fail', $export, 'export_fail', ['error_code' => $code ?? $export->error_code]);
    }

    /**
     * 파일 다운로드
     *
     * @param  Export  $export  작업
     * @param  User|null  $user  내려받은 사람
     * @return void
     */
    public function handleAfterDownload(Export $export, ?User $user = null): void
    {
        $this->log('export.download', $export, 'export_download', ['sequence' => $export->download_count]);
    }

    /**
     * 다운로드 이력 조회
     *
     * @param  Export  $export  작업
     * @param  User|null  $user  조회한 사람
     * @return void
     */
    public function handleAfterViewDownloads(Export $export, ?User $user = null): void
    {
        $this->log('export.view_downloads', $export, 'export_view_downloads');
    }

    /**
     * 삭제
     *
     * @param  Export  $export  작업
     * @param  User|null  $user  삭제한 사람
     * @return void
     */
    public function handleAfterDelete(Export $export, ?User $user = null): void
    {
        $this->log('export.delete', $export, 'export_delete');
    }

    /**
     * 보관 기간 만료 (일괄 정리도 건별로 남긴다)
     *
     * @param  Export  $export  작업
     * @return void
     */
    public function handleAfterExpire(Export $export): void
    {
        $this->log('export.expire', $export, 'export_expire');
    }

    /**
     * 다시 요청
     *
     * @param  Export  $new  새 작업
     * @param  Export  $original  실패했던 원 작업
     * @param  User|null  $user  요청자
     * @return void
     */
    public function handleAfterRetry(Export $new, Export $original, ?User $user = null): void
    {
        $this->log('export.retry', $new, 'export_retry', ['original_export_id' => $original->id]);
    }

    /**
     * 작업 하나에 대한 로그를 남깁니다(삭제 뒤에도 해석되도록 대상·이름 스냅샷을 직접 지정).
     *
     * @param  string  $action  액션 키
     * @param  Export  $export  작업
     * @param  string  $descriptionSuffix  설명 키 접미 (activity_log.description.*)
     * @param  array<string, mixed>  $properties  추가 메타데이터
     * @return void
     */
    private function log(string $action, Export $export, string $descriptionSuffix, array $properties = []): void
    {
        $this->logActivity($action, [
            'loggable_type' => $export->getMorphClass(),
            'loggable_id' => $export->id,
            'description_key' => 'sirsoft-ecommerce::activity_log.description.'.$descriptionSuffix,
            'description_params' => ['export_id' => $export->id],
            'properties' => array_merge([
                'export_id' => $export->id,
                'type' => $export->type,
                'name' => $export->file_name,
            ], $properties),
        ]);
    }
}
