<?php

namespace Plugins\Sirsoft\Ckeditor5;

use App\Enums\ExtensionOwnerType;
use App\Extension\AbstractPlugin;
use App\Extension\Helpers\ExtensionMenuSyncHelper;

/**
 * CKEditor 5 WYSIWYG 에디터 플러그인
 *
 * extension_point: "html_editor" 슬롯을 통해 기존 HtmlEditor를 교체합니다.
 * 미설치 시 기존 HtmlEditor로 자동 폴백됩니다.
 */
class Plugin extends AbstractPlugin
{
    /**
     * 플러그인 설정 스키마 반환
     *
     * @return array 설정 스키마
     */
    public function getSettingsSchema(): array
    {
        return [
            'imageUpload' => [
                'type' => 'boolean',
                'default' => true,
                'label' => [
                    'ko' => '이미지 업로드',
                    'en' => 'Image Upload',
                ],
                'hint' => [
                    'ko' => '에디터에서 이미지 업로드 기능을 활성화합니다.',
                    'en' => 'Enable image upload functionality in the editor.',
                ],
                'required' => false,
            ],
            'imageMaxSizeMb' => [
                'type' => 'integer',
                'default' => 2,
                'label' => [
                    'ko' => '이미지 최대 크기 (MB)',
                    'en' => 'Image Max Size (MB)',
                ],
                'hint' => [
                    'ko' => '업로드 가능한 이미지의 최대 파일 크기입니다.',
                    'en' => 'Maximum file size for uploadable images.',
                ],
                'required' => false,
            ],
            'editorHeight' => [
                'type' => 'integer',
                'default' => 400,
                'label' => [
                    'ko' => '에디터 높이 (px)',
                    'en' => 'Editor Height (px)',
                ],
                'hint' => [
                    'ko' => '에디터 영역의 최소 높이입니다.',
                    'en' => 'Minimum height of the editor area.',
                ],
                'required' => false,
            ],
            'toolbar' => [
                'type' => 'enum',
                'options' => ['standard', 'minimal', 'full'],
                'default' => 'standard',
                'label' => [
                    'ko' => '툴바 유형',
                    'en' => 'Toolbar Type',
                ],
                'hint' => [
                    'ko' => '에디터 툴바 구성을 선택합니다.',
                    'en' => 'Select the editor toolbar configuration.',
                ],
                'required' => false,
            ],
            // 선택지가 코어 카탈로그 + 플러그인 훅 등록 디스크로 동적이라 enum 불가 — string.
            // 존재하지 않는 디스크 값은 resolvePublicAssetDisk() 가 스트리밍으로 안전 폴백한다.
            'public_asset_disk' => [
                'type' => 'string',
                'max' => 100,
                'default' => '',
                'label' => [
                    'ko' => '공개 자산 디스크',
                    'en' => 'Public Asset Disk',
                ],
                'hint' => [
                    'ko' => '이 플러그인만 다른 디스크를 쓰려면 선택합니다. 비우면 코어 공개 자산 디스크 설정을 따릅니다.',
                    'en' => 'Select to use a different disk for this plugin only. Leave empty to follow the core public asset disk setting.',
                ],
                'required' => false,
            ],
            'unusedImageCleanup' => [
                'type' => 'boolean',
                'default' => false,
                'label' => [
                    'ko' => '미사용 이미지 자동 정리',
                    'en' => 'Auto Cleanup Unused Images',
                ],
                'hint' => [
                    'ko' => '기본 꺼짐 — 운영자가 직접 켜야 동작합니다. 켜면 보존기간이 지난 미사용 이미지를 매일 삭제합니다.',
                    'en' => 'Off by default — the operator must turn it on. When on, unused images past the retention period are deleted daily.',
                ],
                'required' => false,
            ],
            'unusedImageRetentionDays' => [
                'type' => 'integer',
                'min' => 1,
                'max' => 3650,
                'default' => 30,
                'label' => [
                    'ko' => '미사용 이미지 보존기간 (일)',
                    'en' => 'Unused Image Retention (days)',
                ],
                'hint' => [
                    'ko' => '업로드 후 이 기간이 지난 미사용 이미지만 정리 대상이 됩니다. (1 ~ 3650 일)',
                    'en' => 'Only unused images older than this period are eligible for cleanup. (1 ~ 3650 days)',
                ],
                'required' => false,
            ],
        ];
    }

    /**
     * 플러그인이 제공하는 훅 정보 반환
     *
     * @return array 훅 정의 배열 (action 2 + filter 2)
     */
    public function getHooks(): array
    {
        return [
            [
                'name' => 'sirsoft-ckeditor5.image.before_upload',
                'type' => 'action',
                'description' => [
                    'ko' => '에디터 이미지 업로드 직전 발화 (본인인증·쿼터 등 확장 지점)',
                    'en' => 'Fired right before an editor image upload (identity verification, quota, etc.)',
                ],
                'parameters' => [
                    'file' => 'UploadedFile - 업로드된 파일',
                    'uploadedBy' => 'int|null - 업로드 사용자 ID',
                ],
            ],
            [
                'name' => 'sirsoft-ckeditor5.image.after_upload',
                'type' => 'action',
                'description' => [
                    'ko' => '에디터 이미지 업로드 기록 생성 후 발화',
                    'en' => 'Fired after the editor image upload record is created',
                ],
                'parameters' => [
                    'record' => 'Model - Ckeditor5ImageUpload',
                ],
            ],
            [
                'name' => 'sirsoft-ckeditor5.image.filter_upload_file',
                'type' => 'filter',
                'description' => [
                    'ko' => '업로드 파일 변형 지점 (압축·리사이즈 등)',
                    'en' => 'Transform the uploaded file (compression, resizing, etc.)',
                ],
                'parameters' => [
                    'file' => 'UploadedFile - 업로드된 파일',
                ],
            ],
            [
                'name' => 'sirsoft-ckeditor5.image.filter_reference_sources',
                'type' => 'filter',
                'description' => [
                    'ko' => '에디터 이미지 참조 스캔 대상 테이블/컬럼 목록에 확장 콘텐츠를 추가',
                    'en' => 'Append extension content tables/columns to the editor image reference scan sources',
                ],
                'parameters' => [
                    'sources' => 'array - list<array{table: string, columns: list<string>}>',
                ],
            ],
        ];
    }

    /**
     * 플러그인 권한 목록 반환 (계층 구조)
     *
     * 업로드 이미지 관리 화면(조회/삭제)의 접근을 분리한다 — 조회는 감사·현황 파악,
     * 삭제는 파일을 실제로 파기하므로 별도 권한으로 둔다.
     *
     * @return array 권한 정의 배열
     */
    public function getPermissions(): array
    {
        return [
            'name' => [
                'ko' => 'CKEditor 5 WYSIWYG 에디터',
                'en' => 'CKEditor 5 WYSIWYG Editor',
            ],
            'description' => [
                'ko' => 'CKEditor5 플러그인이 제공하는 권한',
                'en' => 'Permissions provided by the CKEditor5 plugin',
            ],
            'categories' => [
                [
                    'identifier' => 'uploads',
                    'name' => ['ko' => '에디터 업로드 이미지', 'en' => 'Editor Uploads'],
                    'description' => [
                        'ko' => '에디터로 업로드된 이미지의 조회·삭제 권한',
                        'en' => 'View and delete permissions for images uploaded via the editor',
                    ],
                    'permissions' => [
                        [
                            'action' => 'read',
                            'name' => ['ko' => '업로드 이미지 조회', 'en' => 'View Uploads'],
                            'description' => [
                                'ko' => '에디터 업로드 이미지 목록·참조 상태 조회',
                                'en' => 'View the editor upload list and reference status',
                            ],
                            'type' => 'admin',
                            'roles' => ['admin'],
                        ],
                        [
                            'action' => 'delete',
                            'name' => ['ko' => '업로드 이미지 삭제', 'en' => 'Delete Uploads'],
                            'description' => [
                                'ko' => '에디터 업로드 이미지의 파일·기록 삭제',
                                'en' => 'Delete editor upload files and records',
                            ],
                            'type' => 'admin',
                            'roles' => ['admin'],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * 관리자 메뉴 정의
     *
     * 코어 PluginManager 가 설치·업데이트 공통 경로에서 이 선언을 동기화한다.
     * activate() 의 직접 동기화는 그 경로를 타지 않는 활성화 단독 호출을 위한 것이며,
     * 동기화는 upsert 라 두 경로가 겹쳐도 메뉴가 중복 생성되지 않는다.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAdminMenus(): array
    {
        return [
            [
                'name' => ['ko' => '에디터 업로드 이미지', 'en' => 'Editor Uploads'],
                'slug' => 'sirsoft-ckeditor5-uploads',
                'url' => '/admin/plugins/sirsoft-ckeditor5/uploads',
                'icon' => 'fas fa-images',
                'order' => 50,
            ],
        ];
    }

    /**
     * 플러그인 활성화 — 관리자 메뉴 자동 등록.
     *
     * @return bool 활성화 성공 여부
     */
    public function activate(): bool
    {
        $helper = app(ExtensionMenuSyncHelper::class);

        foreach ($this->getAdminMenus() as $menuData) {
            $helper->syncMenuRecursive(
                $menuData,
                ExtensionOwnerType::Plugin,
                $this->getIdentifier(),
            );
        }

        return true;
    }

    /**
     * 플러그인 비활성화 — 관리자 메뉴 일괄 제거.
     *
     * @return bool 비활성화 성공 여부
     */
    public function deactivate(): bool
    {
        app(ExtensionMenuSyncHelper::class)->cleanupStaleMenus(
            ExtensionOwnerType::Plugin,
            $this->getIdentifier(),
            currentSlugs: [],
        );

        return true;
    }

    /**
     * 플러그인 제거 — 메뉴 잔존 안전망 (정상 흐름은 deactivate 가 먼저 처리).
     *
     * @return bool 제거 성공 여부
     */
    public function uninstall(): bool
    {
        $this->deactivate();

        return true;
    }

    /**
     * 플러그인 스케줄 목록 반환
     *
     * 미참조 이미지 정리는 사용자 파일을 지우므로 기본 꺼짐(옵트인)이다.
     * enabled_config 게이트는 설정 조회 실패 시 true 로 폴백하므로, 커맨드가
     * `--scheduled` 에서 같은 설정을 false 폴백으로 재확인해 자동 삭제를 차단한다.
     *
     * @return array<int, array<string, string>> 스케줄 정의 목록
     */
    public function getSchedules(): array
    {
        return [
            [
                'command' => 'sirsoft-ckeditor5:prune-unused-images --scheduled',
                'schedule' => 'daily',
                'description' => '미참조 에디터 업로드 이미지 정리',
                'enabled_config' => 'sirsoft-ckeditor5.unusedImageCleanup',
            ],
        ];
    }

    /**
     * 완전 공개 자산 스토리지 카테고리 목록
     *
     * 이 카테고리들만 공개 자산 디스크 설정을 따른다. 권한 검사가 걸린 자산은
     * 직접 URL 이 권한을 우회하므로 포함하지 않는다. 에디터가 이미지 외의
     * 공개 자산 업로드를 지원하게 되면 여기에만 카테고리를 추가하면 된다.
     *
     * @var list<string>
     */
    private const PUBLIC_ASSET_CATEGORIES = ['images'];

    /**
     * 카테고리별 스토리지 디스크 이름 반환
     *
     * 완전 공개 자산 카테고리만 공개 자산 디스크(플러그인 설정 public_asset_disk >
     * 코어 전역 core.storage.public_asset_disk)를 따르고, 설정 등 나머지 카테고리는
     * 기본 디스크를 유지합니다. 미설정/고아 디스크는 기본 디스크로 폴백해 기존
     * 스트리밍 동작을 보존합니다.
     *
     * 플러그인 설정 조회는 공개 자산 카테고리에서만 수행합니다 — 'settings' 카테고리에서
     * 조회하면 설정 로드와 재귀 고리가 생깁니다 (AbstractPlugin 주석 참조).
     *
     * @param  string  $category  카테고리
     * @return string 디스크 이름
     */
    public function getStorageDiskFor(string $category): string
    {
        if (! in_array($category, self::PUBLIC_ASSET_CATEGORIES, true)) {
            return $this->getStorageDisk();
        }

        $override = plugin_setting('sirsoft-ckeditor5', 'public_asset_disk', '');

        return $this->resolvePublicAssetDisk(is_string($override) ? $override : '')
            ?? $this->getStorageDisk();
    }

    /**
     * 플러그인이 관리하는 동적 테이블 목록 반환
     *
     * @return array 테이블명 배열
     */
    public function getDynamicTables(): array
    {
        return [
            'ckeditor5_image_uploads',
        ];
    }
}
