<?php

namespace Plugins\Sirsoft\HeroSection\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Plugins\Sirsoft\HeroSection\Models\Banner;
use Plugins\Sirsoft\HeroSection\Models\PositionSetting;
use Plugins\Sirsoft\HeroSection\Services\PublicHeroService;

/**
 * 사용자 화면 공개 캐시 비우기 — 배너·이미지·위치 설정이 바뀌면 그 위치의 모든 언어 캐시를 무효로 한다.
 *
 * 저장 직후 사용자 화면에 바로 반영돼야 하므로 모두 `sync` 로 돈다(큐로 미루면 그사이 옛 배너가 보인다).
 * 플러그인 설정(캐시 시간) 변경·비활성화 때는 Plugin 이 전체를 비운다.
 */
class HeroCacheListener implements HookListenerInterface
{
    /**
     * 구독할 훅과 메서드 매핑 반환
     *
     * @return array<string, array<string, mixed>> 훅 이름 => 옵션
     */
    public static function getSubscribedHooks(): array
    {
        $sync = ['priority' => 5, 'sync' => true];

        return [
            'sirsoft-hero_section.banner.after_create' => ['method' => 'onBannerSaved'] + $sync,
            'sirsoft-hero_section.banner.after_update' => ['method' => 'onBannerUpdated'] + $sync,
            'sirsoft-hero_section.banner.after_delete' => ['method' => 'onBannerDeleted'] + $sync,
            'sirsoft-hero_section.banner.after_bulk_status' => ['method' => 'onBulkStatus'] + $sync,
            'sirsoft-hero_section.banner.after_bulk_delete' => ['method' => 'onBulkDelete'] + $sync,
            'sirsoft-hero_section.banner.after_reorder' => ['method' => 'onReorder'] + $sync,
            'sirsoft-hero_section.position_setting.after_update' => ['method' => 'onPositionSetting'] + $sync,
            'core.plugin_settings.after_save' => ['method' => 'onPluginSettingsSaved'] + $sync,
            'core.plugin_settings.after_reset' => ['method' => 'onPluginSettingsSaved'] + $sync,
        ];
    }

    /**
     * HeroCacheListener 생성자
     *
     * @param  PublicHeroService  $hero  공개 노출 서비스
     */
    public function __construct(private PublicHeroService $hero) {}

    /**
     * 인터페이스 기본 핸들러 (개별 메서드에서 처리)
     *
     * @param  mixed  ...$args  훅 인자
     * @return void 반환값 없음
     */
    public function handle(...$args): void {}

    /**
     * 배너 등록 뒤 그 위치 캐시를 비웁니다.
     *
     * @param  Banner  $banner  등록한 배너
     * @return void 반환값 없음
     */
    public function onBannerSaved(Banner $banner): void
    {
        $this->hero->invalidate($banner->position);
    }

    /**
     * 배너 수정 뒤 옛·새 위치 캐시를 비웁니다.
     *
     * @param  Banner  $banner  수정한 배너
     * @param  array<string, mixed>|null  $snapshot  수정 전 값
     * @param  string|null  $previousPosition  수정 전 위치
     * @return void 반환값 없음
     */
    public function onBannerUpdated(Banner $banner, ?array $snapshot = null, ?string $previousPosition = null): void
    {
        $this->hero->invalidate($banner->position);
        if ($previousPosition !== null && $previousPosition !== $banner->position) {
            $this->hero->invalidate($previousPosition);
        }
    }

    /**
     * 배너 삭제 뒤 그 위치 캐시를 비웁니다.
     *
     * @param  int  $bannerId  삭제한 배너 ID
     * @param  array<string, mixed>  $snapshot  삭제 전 값
     * @return void 반환값 없음
     */
    public function onBannerDeleted(int $bannerId, array $snapshot = []): void
    {
        $this->hero->invalidate($snapshot['position'] ?? null);
    }

    /**
     * 일괄 노출·비노출 뒤 관련 위치 캐시를 비웁니다.
     *
     * @param  array<int, int>  $ids  배너 ID 목록
     * @param  bool  $isActive  바꾼 사용 여부
     * @param  array<int, array<string, mixed>>  $snapshots  바꾸기 전 값
     * @return void 반환값 없음
     */
    public function onBulkStatus(array $ids, bool $isActive, array $snapshots = []): void
    {
        $this->invalidatePositions($snapshots);
    }

    /**
     * 일괄 삭제 뒤 관련 위치 캐시를 비웁니다.
     *
     * @param  array<int, int>  $ids  배너 ID 목록
     * @param  array<int, array<string, mixed>>  $snapshots  삭제 전 값
     * @return void 반환값 없음
     */
    public function onBulkDelete(array $ids, array $snapshots = []): void
    {
        $this->invalidatePositions($snapshots);
    }

    /**
     * 순서 변경 뒤 그 위치 캐시를 비웁니다.
     *
     * @param  string  $position  위치 키
     * @param  array<int, int>  $ids  새 순서
     * @return void 반환값 없음
     */
    public function onReorder(string $position, array $ids = []): void
    {
        $this->hero->invalidate($position);
    }

    /**
     * 위치 슬라이더 설정 변경 뒤 그 위치 캐시를 비웁니다.
     *
     * @param  PositionSetting  $setting  저장한 설정
     * @param  array<string, mixed>  $snapshot  바꾸기 전 값
     * @return void 반환값 없음
     */
    public function onPositionSetting(PositionSetting $setting, array $snapshot = []): void
    {
        $this->hero->invalidate($setting->position);
    }

    /**
     * 이 플러그인의 설정(캐시 시간)이 바뀌면 전체 캐시를 비웁니다.
     *
     * @param  mixed  ...$args  코어 훅 인자 (첫 인자: 플러그인 식별자)
     * @return void 반환값 없음
     */
    public function onPluginSettingsSaved(...$args): void
    {
        if (($args[0] ?? null) === 'sirsoft-hero_section') {
            $this->hero->invalidate();
        }
    }

    /**
     * 스냅샷들의 위치 캐시를 비웁니다.
     *
     * @param  array<int, array<string, mixed>>  $snapshots  배너 스냅샷
     * @return void 반환값 없음
     */
    private function invalidatePositions(array $snapshots): void
    {
        $positions = array_unique(array_filter(array_map(fn (array $s) => $s['position'] ?? null, $snapshots)));
        foreach ($positions as $position) {
            $this->hero->invalidate($position);
        }
    }
}
