<?php

namespace Plugins\Sirsoft\HeroSection;

use App\Enums\ExtensionOwnerType;
use App\Extension\AbstractPlugin;
use App\Extension\Helpers\ExtensionMenuSyncHelper;
use Illuminate\Support\Facades\Schema;
use Plugins\Sirsoft\HeroSection\Listeners\ActivityLogDescriptionResolver;
use Plugins\Sirsoft\HeroSection\Listeners\BannerActivityLogListener;
use Plugins\Sirsoft\HeroSection\Listeners\BuiltinPositionListener;
use Plugins\Sirsoft\HeroSection\Listeners\HeroCacheListener;
use Plugins\Sirsoft\HeroSection\Listeners\PluginLifecycleListener;
use Plugins\Sirsoft\HeroSection\Listeners\SlotWidthHintListener;
use Plugins\Sirsoft\HeroSection\Listeners\ThumbnailRegistrationListener;
use Plugins\Sirsoft\HeroSection\Listeners\TargetChangeListener;
use Plugins\Sirsoft\HeroSection\Services\BannerImageService;
use Plugins\Sirsoft\HeroSection\Services\PositionRegistry;
use Plugins\Sirsoft\HeroSection\Services\PublicHeroService;

/**
 * 히어로섹션 플러그인
 *
 * 사이트 홈·쇼핑몰 메인 상단의 히어로섹션(메인 배너)을 관리하고, 사용자 템플릿이 열어 둔 확장점
 * (`home_hero`·`shop_hero`)에 슬라이더로 공급합니다. 템플릿은 자리만 두고 화면 컴포넌트·데이터는
 * 이 플러그인이 소유합니다(#810).
 */
class Plugin extends AbstractPlugin
{
    /**
     * 훅 리스너 목록 반환 — 플러그인 리스너는 자동 발견되지 않으므로 여기 올린 것만 등록된다
     *
     * @return array<class-string> 리스너 클래스 목록
     */
    public function getHookListeners(): array
    {
        return [
            ActivityLogDescriptionResolver::class,
            BannerActivityLogListener::class,
            BuiltinPositionListener::class,
            HeroCacheListener::class,
            PluginLifecycleListener::class,
            SlotWidthHintListener::class,
            ThumbnailRegistrationListener::class,
            TargetChangeListener::class,
        ];
    }

    /**
     * 플러그인이 발행하는 훅 선언
     *
     * @return array<int, array<string, mixed>> 훅 정의 배열
     */
    public function getHooks(): array
    {
        $hooks = [];
        $banner = [
            'before_create' => ['action', '배너 등록 직전', 'Before a banner is created', '(array $data)'],
            'after_create' => ['action', '배너 등록 직후', 'After a banner is created', '(Banner $banner)'],
            'before_update' => ['action', '배너 수정 직전', 'Before a banner is updated', '(Banner $banner, array $data)'],
            'after_update' => ['action', '배너 수정 직후', 'After a banner is updated', '(Banner $banner, array $snapshot, string $previousPosition)'],
            'before_delete' => ['action', '배너 영구 삭제 직전', 'Before a banner is permanently deleted', '(Banner $banner)'],
            'after_delete' => ['action', '배너 영구 삭제 직후', 'After a banner is permanently deleted', '(int $bannerId, array $snapshot)'],
            'before_bulk_status' => ['action', '일괄 노출·비노출 직전', 'Before bulk show/hide', '(array $ids, bool $isActive)'],
            'after_bulk_status' => ['action', '일괄 노출·비노출 직후', 'After bulk show/hide', '(array $ids, bool $isActive, array $snapshots)'],
            'before_bulk_delete' => ['action', '일괄 삭제 직전', 'Before bulk delete', '(array $ids)'],
            'after_bulk_delete' => ['action', '일괄 삭제 직후', 'After bulk delete', '(array $ids, array $snapshots)'],
            'before_reorder' => ['action', '순서 변경 직전', 'Before reordering', '(string $position, array $ids, ?string $targetKey)'],
            'after_reorder' => ['action', '순서 변경 직후', 'After reordering', '(string $position, array $ids, ?string $targetKey)'],
            'filter_create_data' => ['filter', '등록 값 가공', 'Filters banner create data', '(array $data)'],
            'filter_update_data' => ['filter', '수정 값 가공', 'Filters banner update data', '(array $data, Banner $banner)'],
            'filter_list_params' => ['filter', '관리자 목록 조건 가공', 'Filters admin list params', '(array $filters)'],
            'store_validation_rules' => ['filter', '등록 검증 규칙 가공', 'Filters create validation rules', '(array $rules, FormRequest $request)'],
            'update_validation_rules' => ['filter', '수정 검증 규칙 가공', 'Filters update validation rules', '(array $rules, FormRequest $request)'],
        ];
        foreach ($banner as $suffix => [$type, $ko, $en, $args]) {
            $hooks[] = [
                'name' => 'sirsoft-hero_section.banner.'.$suffix,
                'type' => $type,
                'description' => ['ko' => $ko.'. 인자: '.$args, 'en' => $en.'. Arguments: '.$args],
            ];
        }

        $hooks[] = ['name' => 'sirsoft-hero_section.banner_image.filter_upload_file', 'type' => 'filter', 'description' => ['ko' => '올린 이미지 파일 가공(압축 등). 인자: (UploadedFile $file)', 'en' => 'Filters the uploaded image file. Arguments: (UploadedFile $file)']];
        $hooks[] = ['name' => 'sirsoft-hero_section.banner_image.after_upload', 'type' => 'action', 'description' => ['ko' => '배너 이미지 임시 업로드 직후(배너에는 저장 때 붙는다). 인자: (BannerImage $image)', 'en' => 'After a banner image is uploaded to a temporary bundle (attached to the banner on save). Arguments: (BannerImage $image)']];
        $hooks[] = ['name' => 'sirsoft-hero_section.banner_image.after_change', 'type' => 'action', 'description' => ['ko' => '배너 수정 저장으로 기기별 이미지가 바뀐 직후. 인자: (Banner $banner, string $device, string $change — replaced·removed)', 'en' => 'After a device image changes by saving a banner edit. Arguments: (Banner $banner, string $device, string $change — replaced or removed)']];
        $hooks[] = ['name' => 'sirsoft-hero_section.banner_image.after_delete', 'type' => 'action', 'description' => ['ko' => '배너 이미지 삭제 직후. 인자: (array $snapshot)', 'en' => 'After a banner image is deleted. Arguments: (array $snapshot)']];
        $hooks[] = ['name' => 'sirsoft-hero_section.position_setting.after_update', 'type' => 'action', 'description' => ['ko' => '위치 슬라이더 설정 저장 직후. 인자: (PositionSetting $setting, array $snapshot)', 'en' => 'After position slider settings are saved. Arguments: (PositionSetting $setting, array $snapshot)']];
        $hooks[] = ['name' => PositionRegistry::FILTER, 'type' => 'filter', 'description' => ['ko' => '노출 위치 등록. 인자: (array $positions — 위치 정의 [key, label, order, target?{type, label, resolver}] 목록)', 'en' => 'Registers exposure positions. Arguments: (array $positions — list of position definitions [key, label, order, target?{type, label, resolver}])']];
        $hooks[] = ['name' => 'sirsoft-hero_section.hero.filter_public_banners', 'type' => 'filter', 'description' => ['ko' => '사용자 화면 노출 배너 목록 가공. 인자: (array $banners, string $position, string $locale, ?string $targetKey — 동적 위치의 대상, 정적 위치는 null)', 'en' => 'Filters banners exposed on user screens. Arguments: (array $banners, string $position, string $locale, ?string $targetKey — the target of a dynamic position, null for static positions)']];

        return $hooks;
    }

    /**
     * 플러그인 권한 — 조회 / 등록·수정 / 삭제 3개, 소유 범위 없음(배너는 사이트 공용 자원).
     *
     * @return array<string, mixed> 권한 정의
     */
    public function getPermissions(): array
    {
        return [
            'name' => ['ko' => '히어로섹션', 'en' => 'Hero Section'],
            'description' => ['ko' => '히어로섹션 플러그인이 제공하는 권한', 'en' => 'Permissions provided by the hero section plugin'],
            'categories' => [
                [
                    'identifier' => 'banners',
                    'name' => ['ko' => '배너 관리', 'en' => 'Banner Management'],
                    'description' => ['ko' => '히어로섹션 배너 관리', 'en' => 'Manage hero section banners'],
                    'permissions' => [
                        [
                            'action' => 'read',
                            'name' => ['ko' => '배너 조회', 'en' => 'View Banners'],
                            'description' => ['ko' => '배너 목록·상세·미리보기를 봅니다.', 'en' => 'View banner lists, details and previews.'],
                            'type' => 'admin',
                            'roles' => ['admin'],
                        ],
                        [
                            'action' => 'update',
                            'name' => ['ko' => '배너 등록·수정', 'en' => 'Create and Edit Banners'],
                            'description' => ['ko' => '배너 등록·수정·순서 변경·노출 전환·이미지 업로드와 슬라이더 설정을 합니다.', 'en' => 'Create, edit, reorder and show/hide banners, upload images and change slider settings.'],
                            'type' => 'admin',
                            'roles' => ['admin'],
                        ],
                        [
                            'action' => 'delete',
                            'name' => ['ko' => '배너 삭제', 'en' => 'Delete Banners'],
                            'description' => ['ko' => '배너를 영구 삭제합니다(이미지 파일 포함).', 'en' => 'Permanently delete banners (including image files).'],
                            'type' => 'admin',
                            'roles' => ['admin'],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * 관리자 메뉴 — 「히어로섹션 관리」.
     *
     * @return array<int, array<string, mixed>> 메뉴 정의
     */
    public function getAdminMenus(): array
    {
        return [
            [
                'name' => ['ko' => '히어로섹션 관리', 'en' => 'Hero Section'],
                'slug' => 'sirsoft-hero_section',
                'url' => '/admin/plugins/sirsoft-hero_section/banners',
                'icon' => 'fas fa-images',
                'order' => 55,
                'permission' => 'sirsoft-hero_section.banners.read',
            ],
        ];
    }

    /**
     * 플러그인 활성화 — 관리자 메뉴를 등록하고 선언에서 빠진 옛 메뉴를 거둡니다.
     *
     * @return bool 활성화 성공 여부
     */
    public function activate(): bool
    {
        $helper = app(ExtensionMenuSyncHelper::class);
        $currentSlugs = [];

        foreach ($this->getAdminMenus() as $menuData) {
            $helper->syncMenuRecursive($menuData, ExtensionOwnerType::Plugin, $this->getIdentifier());
            $currentSlugs[] = $menuData['slug'];
        }

        $helper->cleanupStaleMenus(ExtensionOwnerType::Plugin, $this->getIdentifier(), currentSlugs: $currentSlugs);

        return true;
    }

    /**
     * 플러그인 비활성화 — 사용자 화면 공개 캐시를 비웁니다(확장점 주입은 코어가 즉시 뺀다).
     *
     * @return bool 비활성화 성공 여부
     */
    public function deactivate(): bool
    {
        try {
            app(PublicHeroService::class)->invalidate();
        } catch (\Throwable) {
            // 캐시 비우기 실패가 비활성화를 막지 않는다 — 다음 활성화 때 버전이 다시 오른다
        }

        return true;
    }

    /**
     * 플러그인 제거 — 데이터까지 지운 제거면 이미지 폴더를 비웁니다.
     *
     * 코어 삭제는 테이블만 지우고 스토리지 파일은 남긴다. `core.plugins.before_uninstall` 은 제거 가드보다 먼저 발화해
     * 거기서 지우면 제거가 막혀도 파일만 사라지므로, 가드와 마이그레이션 롤백을 지난 이 시점에 지운다.
     * 테이블이 남아 있으면 데이터를 보존하는 제거다 — 다시 설치하면 배너가 그대로 보여야 하므로 건드리지 않는다.
     *
     * @return bool 제거 성공 여부
     */
    public function uninstall(): bool
    {
        if (Schema::hasTable('hero_section_banners')) {
            return true;
        }

        // 지금 업로드 디스크와, 제거 시작 때(테이블이 있을 때) 읽어 둔 행 디스크를 모두 비운다(#878).
        // 파일 정리 실패가 이미 끝난 데이터 삭제를 되돌리지 못한다 — 남은 파일은 디스크별 로그로 알린다
        BannerImageService::purgeDisks($this->getStorageFor(BannerImageService::CATEGORY), BannerImageService::takeDisksForUninstall());

        return true;
    }

    /**
     * 예약 작업 — 고아 임시 이미지 정리(매일 0시).
     *
     * @return array<int, array<string, mixed>> 예약 작업
     */
    public function getSchedules(): array
    {
        return [
            [
                'name' => 'prune-temp-images',
                'type' => 'artisan',
                'label' => ['ko' => '임시 배너 이미지 삭제', 'en' => 'Prune temporary banner images'],
                // temp_key 가 남아 있으면 끝내 연결되지 않은 등록 화면 부산물이라 오탐 여지가 없다 (보존기간은 커맨드 옵션)
                'description' => ['ko' => '배너에 연결되지 않은 임시 이미지를 지웁니다.', 'en' => 'Deletes temporary images not linked to any banner.'],
                'command' => 'sirsoft-hero_section:prune-temp-images',
                'frequency' => 'daily',
                'expression' => '0 0 * * *',
            ],
        ];
    }

    /**
     * 데이터까지 삭제할 때 지울 테이블 (자식 먼저).
     *
     * @return array<int, string> 테이블명
     */
    public function getDynamicTables(): array
    {
        return [
            'hero_section_banner_images',
            'hero_section_banners',
            'hero_section_position_settings',
        ];
    }

    /**
     * 플러그인 설정 스키마 반환
     *
     * 실제로 받을 수 있는 업로드 크기는 이 값과 서버 PHP 의 upload_max_filesize·post_max_size 중 가장 작은 값이다.
     *
     * @return array<string, array<string, mixed>> 설정 스키마
     */
    public function getSettingsSchema(): array
    {
        return [
            'max_upload_mb' => [
                'type' => 'integer',
                'default' => 5,
                'min' => 1,
                'max' => 10,
                'label' => ['ko' => '이미지 최대 용량(MB)', 'en' => 'Maximum image size (MB)'],
                'hint' => [
                    'ko' => '배너 이미지 한 장의 최대 용량입니다. 서버 PHP 의 upload_max_filesize·post_max_size 보다 크게 잡아도 그 값을 넘는 파일은 올라가지 않습니다.',
                    'en' => 'Maximum size of one banner image. Files larger than the server PHP upload_max_filesize or post_max_size are still rejected even if this value is higher.',
                ],
                'required' => true,
            ],
            'max_video_upload_mb' => [
                'type' => 'integer',
                'default' => 30,
                'min' => 1,
                'max' => 100,
                'label' => ['ko' => '영상 최대 용량(MB)', 'en' => 'Maximum video size (MB)'],
                'hint' => [
                    'ko' => '배너 영상 파일(MP4·WEBM) 한 개의 최대 용량입니다. 서버 PHP 의 upload_max_filesize·post_max_size 보다 크게 잡아도 그 값을 넘는 파일은 올라가지 않습니다.',
                    'en' => 'Maximum size of one banner video file (MP4, WEBM). Files larger than the server PHP upload_max_filesize or post_max_size are still rejected even if this value is higher.',
                ],
                'required' => true,
            ],
            'public_cache_minutes' => [
                'type' => 'integer',
                'default' => 10,
                'min' => 0,
                'max' => 60,
                'label' => ['ko' => '사용자 화면 배너 캐시 시간(분)', 'en' => 'Banner cache time on user screens (minutes)'],
                'hint' => [
                    'ko' => '0 이면 캐시를 쓰지 않습니다. 배너를 저장·삭제하거나 순서를 바꾸면 바로 반영되고, 예약 시작·종료 시각에도 캐시가 먼저 만료됩니다.',
                    'en' => 'Set 0 to disable caching. Saving, deleting or reordering banners is reflected immediately, and the cache also expires at scheduled start and end times.',
                ],
                'required' => true,
            ],
            // 선택지가 코어 카탈로그 + 플러그인 훅 등록 디스크로 동적이라 enum 불가 — string.
            // 존재하지 않는 디스크 값은 resolvePublicAssetDisk() 가 스트리밍으로 안전 폴백한다.
            'public_asset_disk' => [
                'type' => 'string',
                'max' => 100,
                'default' => '',
                'label' => ['ko' => '공개 자산 디스크', 'en' => 'Public Asset Disk'],
                'hint' => [
                    'ko' => '배너 이미지·영상만 다른 디스크에 두려면 선택합니다. 비우면 코어 공개 자산 디스크 설정을 따릅니다. 바꾸면 새로 올리는 파일부터 적용되고, 이미 올린 파일은 올린 디스크에서 그대로 제공됩니다. 공개 디스크(public)는 서버에 storage:link 가 있어야 PHP 를 거치지 않고 파일을 내보냅니다.',
                    'en' => 'Select to store banner images and videos on a different disk. Leave empty to follow the core public asset disk setting. A change applies to newly uploaded files; files already uploaded keep being served from the disk they were uploaded to. The public disk serves files without PHP only when storage:link exists on the server.',
                ],
                'required' => false,
            ],
        ];
    }

    /**
     * 완전 공개 자산 스토리지 카테고리 목록 — 배너 이미지·영상(`images`)은 해시만 알면 받는 공개 파일이다.
     *
     * @var list<string>
     */
    private const PUBLIC_ASSET_CATEGORIES = ['images'];

    /**
     * 카테고리별 스토리지 디스크 이름 반환 (#878)
     *
     * 공개 자산 카테고리(`images`)만 공개 자산 디스크(플러그인 설정 public_asset_disk > 코어 전역
     * core.storage.public_asset_disk)를 따르고, 나머지 카테고리는 기본 디스크를 유지합니다. 미설정·고아 디스크는
     * 기본 디스크로 폴백해 기존 스트리밍 동작을 보존합니다. 이 값은 「지금 업로드가 갈 디스크」일 뿐이고, 이미 올린
     * 파일의 읽기·삭제는 행에 기록된 disk 를 따른다(`BannerImageService::storageFor()`).
     *
     * 플러그인 설정 조회는 공개 자산 카테고리에서만 한다 — 'settings' 카테고리에서 조회하면 설정 로드와 재귀
     * 고리가 생긴다(AbstractPlugin 주석 참조).
     *
     * @param  string  $category  카테고리
     * @return string 디스크 이름
     */
    public function getStorageDiskFor(string $category): string
    {
        if (! in_array($category, self::PUBLIC_ASSET_CATEGORIES, true)) {
            return $this->getStorageDisk();
        }

        $override = plugin_setting('sirsoft-hero_section', 'public_asset_disk', '');

        return $this->resolvePublicAssetDisk(is_string($override) ? $override : '')
            ?? $this->getStorageDisk();
    }

    /**
     * 플러그인 설정 기본값 반환 (defaults.json 이 1순위, 이것은 하위 호환용)
     *
     * @return array<string, mixed> 기본 설정값
     */
    public function getConfigValues(): array
    {
        return [
            'max_upload_mb' => 5,
            'max_video_upload_mb' => 30,
            'public_cache_minutes' => 10,
            'public_asset_disk' => '',
        ];
    }
}
