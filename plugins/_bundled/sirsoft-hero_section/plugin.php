<?php

namespace Plugins\Sirsoft\HeroSection;

use App\Enums\ExtensionOwnerType;
use App\Extension\AbstractPlugin;
use App\Extension\Helpers\ExtensionMenuSyncHelper;
use Plugins\Sirsoft\HeroSection\Listeners\ActivityLogDescriptionResolver;
use Plugins\Sirsoft\HeroSection\Listeners\BannerActivityLogListener;
use Plugins\Sirsoft\HeroSection\Listeners\HeroCacheListener;
use Plugins\Sirsoft\HeroSection\Listeners\PluginLifecycleListener;
use Plugins\Sirsoft\HeroSection\Listeners\SlotWidthHintListener;
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
            HeroCacheListener::class,
            PluginLifecycleListener::class,
            SlotWidthHintListener::class,
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
            'before_reorder' => ['action', '순서 변경 직전', 'Before reordering', '(string $position, array $ids)'],
            'after_reorder' => ['action', '순서 변경 직후', 'After reordering', '(string $position, array $ids)'],
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
        $hooks[] = ['name' => 'sirsoft-hero_section.hero.filter_public_banners', 'type' => 'filter', 'description' => ['ko' => '사용자 화면 노출 배너 목록 가공. 인자: (array $banners, string $position, string $locale)', 'en' => 'Filters banners exposed on user screens. Arguments: (array $banners, string $position, string $locale)']];

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
        ];
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
            'public_cache_minutes' => 10,
        ];
    }
}
