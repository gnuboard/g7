<?php

namespace Plugins\Sirsoft\HeroSection\Listeners;

use App\ActivityLog\ChangeDetector;
use App\ActivityLog\Traits\ResolvesActivityLogType;
use App\Contracts\Extension\HookListenerInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Plugins\Sirsoft\HeroSection\Models\Banner;
use Plugins\Sirsoft\HeroSection\Models\PositionSetting;

/**
 * 히어로섹션 활동 로그 — 배너 등록·수정·삭제·일괄(항목별)·순서·이미지·위치 설정을 기록한다.
 *
 * description_params 에는 id·위치 키만 두고, 이름은 `properties.name`(기록 시점 제목 스냅샷)으로 남겨
 * 표시 때 `ActivityLogDescriptionResolver` 가 해석한다. 일괄 처리는 건마다 한 줄(Per-Item)이다.
 */
class BannerActivityLogListener implements HookListenerInterface
{
    use ResolvesActivityLogType;

    /**
     * 설명 키 접두
     */
    private const KEY = 'sirsoft-hero_section::activity_log.description.';

    /**
     * 구독할 훅과 메서드 매핑 반환
     *
     * @return array<string, array<string, mixed>> 훅 이름 => 옵션
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-hero_section.banner.after_create' => ['method' => 'handleCreated', 'priority' => 20],
            'sirsoft-hero_section.banner.after_update' => ['method' => 'handleUpdated', 'priority' => 20],
            'sirsoft-hero_section.banner.after_delete' => ['method' => 'handleDeleted', 'priority' => 20],
            'sirsoft-hero_section.banner.after_bulk_status' => ['method' => 'handleBulkStatus', 'priority' => 20],
            'sirsoft-hero_section.banner.after_bulk_delete' => ['method' => 'handleBulkDelete', 'priority' => 20],
            'sirsoft-hero_section.banner.after_reorder' => ['method' => 'handleReordered', 'priority' => 20],
            'sirsoft-hero_section.banner_image.after_change' => ['method' => 'handleImageChanged', 'priority' => 20],
            'sirsoft-hero_section.position_setting.after_update' => ['method' => 'handlePositionSetting', 'priority' => 20],
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
     * 배너 등록을 기록합니다.
     *
     * @param  Banner  $banner  등록한 배너
     * @return void 반환값 없음
     */
    public function handleCreated(Banner $banner): void
    {
        $this->logActivity('hero_banner.create', [
            'loggable' => $banner,
            'description_key' => self::KEY.'hero_banner_create',
            'description_params' => ['banner_id' => $banner->id, 'position' => $banner->position],
            'properties' => ['name' => $banner->title],
        ]);
    }

    /**
     * 배너 수정을 기록합니다(변경 항목 포함).
     *
     * @param  Banner  $banner  수정한 배너
     * @param  array<string, mixed>|null  $snapshot  수정 전 값
     * @param  string|null  $previousPosition  수정 전 위치
     * @return void 반환값 없음
     */
    public function handleUpdated(Banner $banner, ?array $snapshot = null, ?string $previousPosition = null): void
    {
        $this->logActivity('hero_banner.update', [
            'loggable' => $banner,
            'description_key' => self::KEY.'hero_banner_update',
            'description_params' => ['banner_id' => $banner->id, 'position' => $banner->position],
            'changes' => ChangeDetector::detect($banner, $snapshot),
            'properties' => ['name' => $banner->title],
        ]);
    }

    /**
     * 배너 삭제를 기록합니다.
     *
     * @param  int  $bannerId  삭제한 배너 ID
     * @param  array<string, mixed>  $snapshot  삭제 전 값
     * @return void 반환값 없음
     */
    public function handleDeleted(int $bannerId, array $snapshot = []): void
    {
        $this->logActivity('hero_banner.delete', $this->deletedContext($bannerId, $snapshot, 'hero_banner_delete'));
    }

    /**
     * 일괄 노출·비노출을 건마다 기록합니다.
     *
     * 이 리스너는 큐에서 늦게 돈다 — 그 사이 배너가 지워지거나 다시 고쳐질 수 있으므로 DB 를 다시 읽지 않고, 서비스가 잠근 행에서
     * 뜬 변경 시점 스냅숏으로 남긴다(일괄 삭제와 같은 방식). 바뀐 값은 사용 여부 하나다.
     *
     * @param  array<int, int>  $ids  배너 ID 목록
     * @param  bool  $isActive  바꾼 사용 여부
     * @param  array<int, array<string, mixed>>  $snapshots  바꾸기 전 값 (배너 ID => toArray)
     * @return void 반환값 없음
     */
    public function handleBulkStatus(array $ids, bool $isActive, array $snapshots = []): void
    {
        $this->perItem(function () use ($ids, $snapshots, $isActive) {
            foreach ($ids as $id) {
                $snapshot = $snapshots[$id] ?? null;
                if (! is_array($snapshot)) {
                    continue;
                }

                $before = (bool) ($snapshot['is_active'] ?? false);
                $this->logActivity('hero_banner.bulk_status', [
                    'loggable_type' => Banner::class,
                    'loggable_id' => (int) $id,
                    'description_key' => self::KEY.($isActive ? 'hero_banner_bulk_show' : 'hero_banner_bulk_hide'),
                    'description_params' => ['banner_id' => (int) $id, 'position' => $snapshot['position'] ?? null],
                    'changes' => $before === $isActive ? null : [[
                        'field' => 'is_active',
                        'label_key' => Banner::$activityLogFields['is_active']['label_key'],
                        'old' => $before,
                        'new' => $isActive,
                        'type' => 'boolean',
                    ]],
                    'properties' => ['name' => $snapshot['title'] ?? null],
                ]);
            }
        });
    }

    /**
     * 일괄 삭제를 건마다 기록합니다.
     *
     * @param  array<int, int>  $ids  배너 ID 목록
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
                $this->logActivity('hero_banner.bulk_delete', $this->deletedContext((int) $id, $snapshots[$id], 'hero_banner_delete'));
            }
        });
    }

    /**
     * 순서 변경을 기록합니다(위치 1건).
     *
     * @param  string  $position  위치 키
     * @param  array<int, int>  $ids  새 순서
     * @return void 반환값 없음
     */
    public function handleReordered(string $position, array $ids = []): void
    {
        $this->logActivity('hero_banner.reorder', [
            'description_key' => self::KEY.'hero_banner_reorder',
            'description_params' => ['position' => $position, 'count' => count($ids)],
            'properties' => ['position' => $position, 'ids' => array_values($ids)],
        ]);
    }

    /**
     * 배너 수정 저장으로 바뀐 기기별 이미지를 기록합니다(업로드만 하고 저장하지 않은 이미지는 기록하지 않는다).
     *
     * @param  Banner  $banner  수정한 배너
     * @param  string  $device  기기 (pc, mobile)
     * @param  string  $change  replaced(교체) 또는 removed(뺌)
     * @return void 반환값 없음
     */
    public function handleImageChanged(Banner $banner, string $device, string $change = 'replaced'): void
    {
        // 선택 키가 영상(`pc_video`·`mobile_video`)이면 영상 교체·삭제로 남기고 기기 이름만 적는다
        $video = str_ends_with($device, '_video');
        $device = $video ? substr($device, 0, -strlen('_video')) : $device;
        $action = ($video ? 'video_' : 'image_').($change === 'removed' ? 'remove' : 'update');

        $this->logActivity('hero_banner.'.$action, [
            'loggable' => $banner,
            'description_key' => self::KEY.'hero_banner_'.$action,
            'description_params' => ['banner_id' => $banner->id, 'device' => $device],
            'properties' => ['name' => $banner->title, 'device' => $device],
        ]);
    }

    /**
     * 위치 슬라이더 설정 변경을 기록합니다.
     *
     * @param  PositionSetting  $setting  저장한 설정
     * @param  array<string, mixed>  $snapshot  바꾸기 전 값
     * @return void 반환값 없음
     */
    public function handlePositionSetting(PositionSetting $setting, array $snapshot = []): void
    {
        $this->logActivity('hero_position.update', [
            'loggable' => $setting,
            'description_key' => self::KEY.'hero_position_update',
            'description_params' => ['position' => $setting->position],
            'changes' => ChangeDetector::detect($setting, $snapshot),
        ]);
    }

    /**
     * 삭제 기록 문맥을 만듭니다 — 행이 없으므로 loggable 을 직접 지정합니다.
     *
     * @param  int  $bannerId  배너 ID
     * @param  array<string, mixed>  $snapshot  삭제 전 값
     * @param  string  $key  설명 키 접미
     * @return array<string, mixed> 활동 로그 문맥
     */
    private function deletedContext(int $bannerId, array $snapshot, string $key): array
    {
        return [
            'loggable_type' => Banner::class,
            'loggable_id' => $bannerId,
            'description_key' => self::KEY.$key,
            'description_params' => ['banner_id' => $bannerId, 'position' => $snapshot['position'] ?? null],
            'properties' => ['name' => $snapshot['title'] ?? null, 'banner_id' => $bannerId],
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
            Log::error('히어로섹션 일괄 활동 로그 기록 실패', ['error' => $e->getMessage()]);
        }
    }
}
