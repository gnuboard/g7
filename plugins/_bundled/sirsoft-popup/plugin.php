<?php

namespace Plugins\Sirsoft\Popup;

use App\Enums\ExtensionOwnerType;
use App\Extension\AbstractPlugin;
use App\Extension\Helpers\ExtensionMenuSyncHelper;
use Plugins\Sirsoft\Popup\Listeners\ActivityLogDescriptionResolver;
use Plugins\Sirsoft\Popup\Listeners\PluginLifecycleListener;
use Plugins\Sirsoft\Popup\Listeners\PopupActivityLogListener;
use Plugins\Sirsoft\Popup\Listeners\PopupCacheListener;
use Plugins\Sirsoft\Popup\Listeners\ProvideNecessaryStorageKeysListener;
use Plugins\Sirsoft\Popup\Services\PublicPopupService;

/**
 * 팝업 플러그인
 *
 * 공지·프로모션·점검 안내를 사용자 화면 위 팝업 레이어로 띄운다. 사용자 템플릿은 고치지 않고 공통 확장점
 * `user_global_overlay` 에 붙으며(GDPR 쿠키 배너와 같은 방식), 레이어 컴포넌트·데이터·표시 방식은 이 플러그인이 소유한다(#812).
 */
class Plugin extends AbstractPlugin
{
    /**
     * 「보지 않기」 기록 쿠키 이름 — 사용자 레이어(`resources/js/lib/dismissCookie.ts`)와 같은 값이어야 한다.
     */
    public const DISMISS_COOKIE = 'g7_popup_dismissed';

    /**
     * 훅 리스너 목록 반환 — 플러그인 리스너는 자동 발견되지 않으므로 여기 올린 것만 등록된다
     *
     * @return array<class-string> 리스너 클래스 목록
     */
    public function getHookListeners(): array
    {
        return [
            ActivityLogDescriptionResolver::class,
            PopupActivityLogListener::class,
            PopupCacheListener::class,
            PluginLifecycleListener::class,
            ProvideNecessaryStorageKeysListener::class,
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
        $popup = [
            'before_create' => ['action', '팝업 등록 직전', 'Before a popup is created', '(array $data)'],
            'after_create' => ['action', '팝업 등록 직후', 'After a popup is created', '(Popup $popup)'],
            'before_update' => ['action', '팝업 수정 직전', 'Before a popup is updated', '(Popup $popup, array $data)'],
            'after_update' => ['action', '팝업 수정 직후', 'After a popup is updated', '(Popup $popup, array $snapshot)'],
            'before_delete' => ['action', '팝업 영구 삭제 직전', 'Before a popup is permanently deleted', '(Popup $popup)'],
            'after_delete' => ['action', '팝업 영구 삭제 직후', 'After a popup is permanently deleted', '(int $popupId, array $snapshot)'],
            'before_bulk_status' => ['action', '일괄 노출·비노출 직전', 'Before bulk show/hide', '(array $ids, bool $isActive)'],
            'after_bulk_status' => ['action', '일괄 노출·비노출 직후', 'After bulk show/hide', '(array $ids, bool $isActive, array $snapshots)'],
            'before_bulk_copy' => ['action', '일괄 복사 직전', 'Before bulk copy', '(array $ids)'],
            'after_bulk_copy' => ['action', '일괄 복사 직후', 'After bulk copy', '(array $copies — 원본 ID => 새 ID, array $failedIds)'],
            'before_bulk_delete' => ['action', '일괄 삭제 직전', 'Before bulk delete', '(array $ids)'],
            'after_bulk_delete' => ['action', '일괄 삭제 직후', 'After bulk delete', '(array $ids, array $snapshots)'],
            'before_reorder' => ['action', '순서 변경 직전', 'Before reordering', '(array $ids)'],
            'after_reorder' => ['action', '순서 변경 직후', 'After reordering', '(array $ids)'],
            'filter_create_data' => ['filter', '등록 값 가공', 'Filters popup create data', '(array $data)'],
            'filter_update_data' => ['filter', '수정 값 가공', 'Filters popup update data', '(array $data, Popup $popup)'],
            'filter_list_params' => ['filter', '관리자 목록 조건 가공', 'Filters admin list params', '(array $filters)'],
            'store_validation_rules' => ['filter', '등록 검증 규칙 가공', 'Filters create validation rules', '(array $rules, FormRequest $request)'],
            'update_validation_rules' => ['filter', '수정 검증 규칙 가공', 'Filters update validation rules', '(array $rules, FormRequest $request)'],
        ];
        foreach ($popup as $suffix => [$type, $ko, $en, $args]) {
            $hooks[] = [
                'name' => 'sirsoft-popup.popup.'.$suffix,
                'type' => $type,
                'description' => ['ko' => $ko.'. 인자: '.$args, 'en' => $en.'. Arguments: '.$args],
            ];
        }

        $hooks[] = ['name' => 'sirsoft-popup.popup_image.filter_upload_file', 'type' => 'filter', 'description' => ['ko' => '올린 이미지 파일 가공(압축 등). 인자: (UploadedFile $file)', 'en' => 'Filters the uploaded image file. Arguments: (UploadedFile $file)']];
        $hooks[] = ['name' => 'sirsoft-popup.popup_image.after_upload', 'type' => 'action', 'description' => ['ko' => '팝업 이미지 임시 업로드 직후(팝업에는 저장 때 붙는다). 인자: (PopupImage $image)', 'en' => 'After a popup image is uploaded to a temporary bundle (attached on save). Arguments: (PopupImage $image)']];
        $hooks[] = ['name' => 'sirsoft-popup.popup_image.after_change', 'type' => 'action', 'description' => ['ko' => '팝업 수정 저장으로 기기별 이미지가 바뀐 직후. 인자: (Popup $popup, string $device, string $change — replaced·removed)', 'en' => 'After a device image changes by saving a popup edit. Arguments: (Popup $popup, string $device, string $change — replaced or removed)']];
        $hooks[] = ['name' => 'sirsoft-popup.popup_image.after_delete', 'type' => 'action', 'description' => ['ko' => '팝업 이미지 삭제 직후. 인자: (array $snapshot)', 'en' => 'After a popup image is deleted. Arguments: (array $snapshot)']];
        $hooks[] = ['name' => 'sirsoft-popup.popup.filter_sanitize_config', 'type' => 'filter', 'description' => ['ko' => '직접 작성형 본문 정화 허용 목록 가공. 인자: (array $config — HTMLPurifier 설정 키 => 값)', 'en' => 'Filters the HTML sanitizer allow-list for authored bodies. Arguments: (array $config — HTMLPurifier config key => value)']];
        $hooks[] = ['name' => 'sirsoft-popup.popup.before_public_list', 'type' => 'action', 'description' => ['ko' => '사용자 화면 노출 후보 조회 직전(봇 제외). 인자: (array $context — user·locale)', 'en' => 'Before popup candidates for user screens are read (bots excluded). Arguments: (array $context — user, locale)']];
        $hooks[] = ['name' => 'sirsoft-popup.popup.filter_public_list_result', 'type' => 'filter', 'description' => ['ko' => '사용자 화면 노출 후보 가공(캐시에서 꺼내 기간을 다시 판정한 뒤). 인자: (array $popups, array $context — user·locale)', 'en' => 'Filters popup candidates exposed on user screens (after the cached list is re-judged by period). Arguments: (array $popups, array $context — user, locale)']];
        $hooks[] = ['name' => 'sirsoft-popup.popup.after_public_list', 'type' => 'action', 'description' => ['ko' => '사용자 화면 노출 후보 조회 직후. 인자: (array $popups, array $context — user·locale)', 'en' => 'After popup candidates for user screens are read. Arguments: (array $popups, array $context — user, locale)']];

        return $hooks;
    }

    /**
     * 플러그인 권한 — 조회 / 등록·수정 / 삭제 3개, 소유 범위 없음(팝업은 사이트 공용 자원).
     *
     * @return array<string, mixed> 권한 정의
     */
    public function getPermissions(): array
    {
        return [
            'name' => ['ko' => '팝업', 'en' => 'Popup'],
            'description' => ['ko' => '팝업 플러그인이 제공하는 권한', 'en' => 'Permissions provided by the popup plugin'],
            'categories' => [
                [
                    'identifier' => 'popups',
                    'name' => ['ko' => '팝업 관리', 'en' => 'Popup Management'],
                    'description' => ['ko' => '사용자 화면 팝업 관리', 'en' => 'Manage user screen popups'],
                    'permissions' => [
                        [
                            'action' => 'read',
                            'name' => ['ko' => '팝업 조회', 'en' => 'View Popups'],
                            'description' => ['ko' => '팝업 목록·상세·표시 방식을 봅니다.', 'en' => 'View popup lists, details and display settings.'],
                            'type' => 'admin',
                            'roles' => ['admin'],
                        ],
                        [
                            'action' => 'update',
                            'name' => ['ko' => '팝업 등록·수정', 'en' => 'Create and Edit Popups'],
                            'description' => ['ko' => '팝업 등록·수정·복사·순서 변경·노출 전환·이미지 업로드를 합니다.', 'en' => 'Create, edit, copy, reorder and show/hide popups and upload images.'],
                            'type' => 'admin',
                            'roles' => ['admin'],
                        ],
                        [
                            'action' => 'delete',
                            'name' => ['ko' => '팝업 삭제', 'en' => 'Delete Popups'],
                            'description' => ['ko' => '팝업을 영구 삭제합니다(이미지 파일 포함).', 'en' => 'Permanently delete popups (including image files).'],
                            'type' => 'admin',
                            'roles' => ['admin'],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * 관리자 메뉴 — 그룹 없는 최상위 「팝업 관리」, 히어로섹션(55) 바로 아래.
     *
     * @return array<int, array<string, mixed>> 메뉴 정의
     */
    public function getAdminMenus(): array
    {
        return [
            [
                'name' => ['ko' => '팝업 관리', 'en' => 'Popups'],
                'slug' => 'sirsoft-popup',
                'url' => '/admin/plugins/sirsoft-popup/popups',
                'icon' => 'fas fa-window-restore',
                'order' => 56,
                'permission' => 'sirsoft-popup.popups.read',
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
     * 플러그인 비활성화 — 사용자 화면 공개 캐시를 비웁니다(확장점 주입·라우트는 코어가 즉시 뺀다).
     *
     * @return bool 비활성화 성공 여부
     */
    public function deactivate(): bool
    {
        try {
            app(PublicPopupService::class)->invalidate();
        } catch (\Throwable) {
            // 캐시 비우기 실패가 비활성화를 막지 않는다 — 다음 활성화 뒤 첫 변경 때 버전이 다시 오른다
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
                'label' => ['ko' => '임시 팝업 이미지 삭제', 'en' => 'Prune temporary popup images'],
                // temp_key 가 남아 있으면 끝내 저장되지 않은 등록·수정 화면 부산물이라 오탐 여지가 없다 (보존기간은 커맨드 옵션)
                'description' => ['ko' => '팝업에 연결되지 않은 임시 이미지를 지웁니다.', 'en' => 'Deletes temporary images not linked to any popup.'],
                'command' => 'sirsoft-popup:prune-temp-images',
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
            'popup_images',
            'popups',
        ];
    }

    /**
     * 플러그인 설정 스키마 — 모든 팝업에 똑같이 적용되는 표시 방식과 업로드·캐시 값.
     *
     * 실제로 받을 수 있는 업로드 크기는 `max_upload_mb` 와 서버 PHP 의 upload_max_filesize·post_max_size 중 가장 작은 값이다.
     *
     * @return array<string, array<string, mixed>> 설정 스키마
     */
    public function getSettingsSchema(): array
    {
        return [
            'pc_position' => [
                'type' => 'enum', 'options' => ['center', 'coords'], 'default' => 'center', 'required' => true,
                'label' => ['ko' => 'PC 위치', 'en' => 'PC position'],
                'hint' => ['ko' => '화면 중앙 또는 좌상단 기준 좌표', 'en' => 'Screen center or top-left coordinates'],
            ],
            'pc_left' => [
                'type' => 'integer', 'min' => 0, 'max' => 2000, 'default' => 80, 'required' => true,
                'label' => ['ko' => '왼쪽에서(px)', 'en' => 'From left (px)'],
                'hint' => ['ko' => '좌상단 기준 좌표일 때만 씁니다.', 'en' => 'Used only with top-left coordinates.'],
            ],
            'pc_top' => [
                'type' => 'integer', 'min' => 0, 'max' => 2000, 'default' => 120, 'required' => true,
                'label' => ['ko' => '위에서(px)', 'en' => 'From top (px)'],
                'hint' => ['ko' => '좌상단 기준 좌표일 때만 씁니다.', 'en' => 'Used only with top-left coordinates.'],
            ],
            'pc_width' => [
                'type' => 'integer', 'min' => 240, 'max' => 1200, 'default' => 480, 'required' => true,
                'label' => ['ko' => '너비(px)', 'en' => 'Width (px)'],
                'hint' => ['ko' => '내용 영역 너비 · 이미지 권장 크기로도 안내됩니다.', 'en' => 'Content width, also shown as the recommended image size.'],
            ],
            'pc_height' => [
                'type' => 'integer', 'min' => 200, 'max' => 1000, 'default' => 520, 'required' => true,
                'label' => ['ko' => '높이(px)', 'en' => 'Height (px)'],
                'hint' => ['ko' => '내용 영역 높이 · 이미지 권장 크기로도 안내됩니다.', 'en' => 'Content height, also shown as the recommended image size.'],
            ],
            'mobile_style' => [
                'type' => 'enum', 'options' => ['center', 'sheet'], 'default' => 'sheet', 'required' => true,
                'label' => ['ko' => '모바일 표시', 'en' => 'Mobile display'],
                'hint' => ['ko' => '화면 중앙 또는 하단 시트형', 'en' => 'Screen center or bottom sheet'],
            ],
            'dim_background' => [
                'type' => 'boolean', 'default' => true, 'required' => true,
                'label' => ['ko' => '팝업 뒤 배경 어둡게', 'en' => 'Dim the background'],
                'hint' => ['ko' => '', 'en' => ''],
            ],
            'close_on_backdrop' => [
                'type' => 'boolean', 'default' => false, 'required' => true,
                'label' => ['ko' => '배경을 누르면 닫기', 'en' => 'Close on background click'],
                'hint' => ['ko' => '', 'en' => ''],
            ],
            'autoplay' => [
                'type' => 'boolean', 'default' => true, 'required' => true,
                'label' => ['ko' => '여러 개일 때 자동 넘김', 'en' => 'Auto-advance multiple popups'],
                'hint' => ['ko' => '', 'en' => ''],
            ],
            'autoplay_interval' => [
                'type' => 'enum', 'options' => [4, 6, 8], 'default' => 6, 'required' => true,
                'label' => ['ko' => '넘김 간격(초)', 'en' => 'Interval (seconds)'],
                'hint' => ['ko' => '', 'en' => ''],
            ],
            'dismiss_mode' => [
                'type' => 'enum', 'options' => ['today', 'days', 'close'], 'default' => 'today', 'required' => true,
                'label' => ['ko' => '닫기 옵션', 'en' => 'Close options'],
                'hint' => ['ko' => '「보지 않기」를 누르면 그때 보이던 팝업들이 그 기간 동안 숨겨지고, 그 뒤 새로 등록한 팝업은 바로 보입니다.', 'en' => 'Choosing "do not show" hides the popups shown at that moment for the period; popups added later still appear.'],
            ],
            'dismiss_days' => [
                'type' => 'integer', 'min' => 1, 'max' => 30, 'default' => 7, 'required' => true,
                'label' => ['ko' => '보지 않을 일수', 'en' => 'Days to hide'],
                'hint' => ['ko' => '「N일간 보지 않기」일 때만 씁니다.', 'en' => 'Used only with "hide for N days".'],
            ],
            'max_upload_mb' => [
                'type' => 'integer', 'min' => 1, 'max' => 10, 'default' => 5, 'required' => true,
                'label' => ['ko' => '이미지 최대 용량(MB)', 'en' => 'Maximum image size (MB)'],
                'hint' => [
                    'ko' => '팝업 이미지 한 장의 최대 용량입니다. 서버 PHP 의 upload_max_filesize·post_max_size 보다 크게 잡아도 그 값을 넘는 파일은 올라가지 않습니다.',
                    'en' => 'Maximum size of one popup image. Files larger than the server PHP upload_max_filesize or post_max_size are still rejected even if this value is higher.',
                ],
            ],
            'public_cache_minutes' => [
                'type' => 'integer', 'min' => 0, 'max' => 60, 'default' => 10, 'required' => true,
                'label' => ['ko' => '사용자 화면 팝업 캐시 시간(분)', 'en' => 'Popup cache time on user screens (minutes)'],
                'hint' => [
                    'ko' => '0 이면 캐시를 쓰지 않습니다. 팝업을 저장·삭제하거나 순서를 바꾸면 바로 반영되고, 예약 시작·종료 시각에도 캐시가 먼저 만료됩니다.',
                    'en' => 'Set 0 to disable caching. Saving, deleting or reordering popups is reflected immediately, and the cache also expires at scheduled start and end times.',
                ],
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
        $values = [];
        foreach ($this->getSettingsSchema() as $key => $config) {
            $values[$key] = $config['default'];
        }

        return $values;
    }
}
