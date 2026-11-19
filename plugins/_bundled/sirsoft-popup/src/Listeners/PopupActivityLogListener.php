<?php

namespace Plugins\Sirsoft\Popup\Listeners;

use App\ActivityLog\ChangeDetector;
use App\ActivityLog\Traits\ResolvesActivityLogType;
use App\Contracts\Extension\HookListenerInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Plugins\Sirsoft\Popup\Models\Popup;
use Plugins\Sirsoft\Popup\Repositories\Contracts\PopupRepositoryInterface;

/**
 * 팝업 활동 로그 — 등록·수정·삭제·일괄(노출·복사·삭제, 항목별)·순서·이미지 변경을 기록한다.
 *
 * description_params 에는 id 만 두고, 이름은 `properties.name`(기록 시점 관리용 제목 스냅샷)으로 남겨
 * 표시 때 `ActivityLogDescriptionResolver` 가 해석한다. 일괄 처리는 건마다 한 줄(Per-Item)이다.
 * 표시 방식(플러그인 설정) 변경은 코어 플러그인 설정 저장 로그가 남긴다.
 */
class PopupActivityLogListener implements HookListenerInterface
{
    use ResolvesActivityLogType;

    /**
     * 설명 키 접두
     */
    private const KEY = 'sirsoft-popup::activity_log.description.';

    /**
     * 구독할 훅과 메서드 매핑 반환
     *
     * @return array<string, array<string, mixed>> 훅 이름 => 옵션
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-popup.popup.after_create' => ['method' => 'handleCreated', 'priority' => 20],
            'sirsoft-popup.popup.after_update' => ['method' => 'handleUpdated', 'priority' => 20],
            'sirsoft-popup.popup.after_delete' => ['method' => 'handleDeleted', 'priority' => 20],
            'sirsoft-popup.popup.after_bulk_status' => ['method' => 'handleBulkStatus', 'priority' => 20],
            'sirsoft-popup.popup.after_bulk_copy' => ['method' => 'handleBulkCopy', 'priority' => 20],
            'sirsoft-popup.popup.after_bulk_delete' => ['method' => 'handleBulkDelete', 'priority' => 20],
            'sirsoft-popup.popup.after_reorder' => ['method' => 'handleReordered', 'priority' => 20],
            'sirsoft-popup.popup_image.after_change' => ['method' => 'handleImageChanged', 'priority' => 20],
        ];
    }

    /**
     * 인터페이스 기본 핸들러 (개별 메서드에서 처리)
     *
     * @param  mixed  ...$args  훅 인자
     * @return void 반환값 없음
     */
    public function handle(...$args): void {}

    /**
     * 팝업 등록을 기록합니다.
     *
     * @param  Popup  $popup  등록한 팝업
     * @return void 반환값 없음
     */
    public function handleCreated(Popup $popup): void
    {
        $this->logActivity('popup.create', [
            'loggable' => $popup,
            'description_key' => self::KEY.'popup_create',
            'description_params' => ['popup_id' => $popup->id],
            'properties' => ['name' => $popup->title],
        ]);
    }

    /**
     * 팝업 수정을 기록합니다(변경 항목 포함).
     *
     * @param  Popup  $popup  수정한 팝업
     * @param  array<string, mixed>|null  $snapshot  수정 전 값
     * @return void 반환값 없음
     */
    public function handleUpdated(Popup $popup, ?array $snapshot = null): void
    {
        $this->logActivity('popup.update', [
            'loggable' => $popup,
            'description_key' => self::KEY.'popup_update',
            'description_params' => ['popup_id' => $popup->id],
            'changes' => ChangeDetector::detect($popup, $snapshot),
            'properties' => ['name' => $popup->title],
        ]);
    }

    /**
     * 팝업 삭제를 기록합니다.
     *
     * @param  int  $popupId  삭제한 팝업 ID
     * @param  array<string, mixed>  $snapshot  삭제 전 값
     * @return void 반환값 없음
     */
    public function handleDeleted(int $popupId, array $snapshot = []): void
    {
        $this->logActivity('popup.delete', $this->deletedContext($popupId, $snapshot));
    }

    /**
     * 일괄 노출·비노출을 건마다 기록합니다.
     *
     * @param  array<int, int>  $ids  팝업 ID 목록
     * @param  bool  $isActive  바꾼 사용 여부
     * @param  array<int, array<string, mixed>>  $snapshots  바꾸기 전 값
     * @return void 반환값 없음
     */
    public function handleBulkStatus(array $ids, bool $isActive, array $snapshots = []): void
    {
        $popups = app(PopupRepositoryInterface::class)->findMany($ids)->keyBy('id');

        $this->perItem(function () use ($ids, $popups, $snapshots, $isActive) {
            foreach ($ids as $id) {
                $popup = $popups->get($id);
                if ($popup === null) {
                    continue;
                }
                $this->logActivity('popup.bulk_status', [
                    'loggable' => $popup,
                    'description_key' => self::KEY.($isActive ? 'popup_bulk_show' : 'popup_bulk_hide'),
                    'description_params' => ['popup_id' => $id],
                    'changes' => ChangeDetector::detect($popup, $snapshots[$id] ?? null),
                    'properties' => ['name' => $popup->title],
                ]);
            }
        });
    }

    /**
     * 일괄 복사를 건마다 기록합니다 — 복사본에 「{원본} 을(를) 복사」로 남긴다.
     *
     * @param  array<int, int>  $copies  원본 ID => 새 ID
     * @param  array<int, int>  $failed  실패한 원본 ID
     * @return void 반환값 없음
     */
    public function handleBulkCopy(array $copies, array $failed = []): void
    {
        if ($copies === []) {
            return;
        }

        $popups = app(PopupRepositoryInterface::class)->findMany(array_values($copies))->keyBy('id');

        $this->perItem(function () use ($copies, $popups) {
            foreach ($copies as $sourceId => $newId) {
                $copy = $popups->get($newId);
                if ($copy === null) {
                    continue;
                }
                $this->logActivity('popup.bulk_copy', [
                    'loggable' => $copy,
                    'description_key' => self::KEY.'popup_bulk_copy',
                    'description_params' => ['popup_id' => $newId, 'source_id' => (int) $sourceId],
                    'properties' => ['name' => $copy->title, 'source_id' => (int) $sourceId],
                ]);
            }
        });
    }

    /**
     * 일괄 삭제를 건마다 기록합니다.
     *
     * @param  array<int, int>  $ids  팝업 ID 목록
     * @param  array<int, array<string, mixed>>  $snapshots  삭제 전 값
     * @return void 반환값 없음
     */
    public function handleBulkDelete(array $ids, array $snapshots = []): void
    {
        $this->perItem(function () use ($ids, $snapshots) {
            foreach ($ids as $id) {
                if (! isset($snapshots[$id])) {
                    continue;
                }
                $this->logActivity('popup.bulk_delete', $this->deletedContext((int) $id, $snapshots[$id]));
            }
        });
    }

    /**
     * 순서 변경을 기록합니다(1건).
     *
     * @param  array<int, int>  $ids  새 순서
     * @return void 반환값 없음
     */
    public function handleReordered(array $ids = []): void
    {
        $this->logActivity('popup.reorder', [
            'description_key' => self::KEY.'popup_reorder',
            'description_params' => ['count' => count($ids)],
            'properties' => ['ids' => array_values($ids)],
        ]);
    }

    /**
     * 팝업 수정 저장으로 바뀐 기기별 이미지를 기록합니다(업로드만 하고 저장하지 않은 이미지는 기록하지 않는다).
     *
     * @param  Popup  $popup  수정한 팝업
     * @param  string  $device  기기 (pc, mobile)
     * @param  string  $change  replaced(교체) 또는 removed(뺌)
     * @return void 반환값 없음
     */
    public function handleImageChanged(Popup $popup, string $device, string $change = 'replaced'): void
    {
        $action = $change === 'removed' ? 'image_remove' : 'image_update';

        $this->logActivity('popup.'.$action, [
            'loggable' => $popup,
            'description_key' => self::KEY.'popup_'.$action,
            'description_params' => ['popup_id' => $popup->id, 'device' => $device],
            'properties' => ['name' => $popup->title, 'device' => $device],
        ]);
    }

    /**
     * 삭제 기록 문맥을 만듭니다 — 행이 없으므로 loggable 을 직접 지정합니다.
     *
     * @param  int  $popupId  팝업 ID
     * @param  array<string, mixed>  $snapshot  삭제 전 값
     * @return array<string, mixed> 활동 로그 문맥
     */
    private function deletedContext(int $popupId, array $snapshot): array
    {
        return [
            'loggable_type' => Popup::class,
            'loggable_id' => $popupId,
            'description_key' => self::KEY.'popup_delete',
            'description_params' => ['popup_id' => $popupId],
            'properties' => ['name' => $snapshot['title'] ?? null, 'popup_id' => $popupId],
        ];
    }

    /**
     * 건별 기록 묶음을 트랜잭션으로 감쌉니다 — 큐 재실행 때 앞부분이 두 번 기록되지 않게.
     *
     * @param  callable  $records  기록 루프
     * @return void 반환값 없음
     */
    private function perItem(callable $records): void
    {
        try {
            DB::transaction($records);
        } catch (\Throwable $e) {
            Log::error('팝업 일괄 활동 로그 기록 실패', ['error' => $e->getMessage()]);
        }
    }
}
