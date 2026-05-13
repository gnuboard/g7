<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * 레이아웃 파일에 permissions 필드를 일괄 추가하는 커맨드
 *
 * 관리자 레이아웃에 접근 제어를 위한 permissions 필드를 추가합니다.
 */
class AddLayoutPermissionsCommand extends Command
{
    /**
     * 커맨드 시그니처
     *
     * @var string
     */
    protected $signature = 'layout:add-permissions
                            {--dry-run : 실제 파일 수정 없이 시뮬레이션만 수행}
                            {--layout= : 특정 레이아웃만 처리 (레이아웃 이름)}
                            {--force : 이미 permissions가 있는 경우에도 덮어쓰기}';

    /**
     * 커맨드 설명
     *
     * @var string
     */
    protected $description = '레이아웃 파일에 permissions 필드를 일괄 추가합니다';

    /**
     * 레이아웃별 권한 매핑
     *
     * @var array<string, array<string>>
     */
    protected array $permissionMappings = [
        // sirsoft-admin_basic 템플릿
        'admin_dashboard' => ['core.dashboard.read'],
        'admin_user_list' => ['core.users.read'],
        'admin_user_form' => ['core.users.read'],
        'admin_user_detail' => ['core.users.read'],
        'admin_role_list' => ['core.permissions.read'],
        'admin_role_form' => ['core.permissions.read'],
        'admin_menu_list' => ['core.menus.read'],
        'admin_schedule_list' => ['core.schedules.read'],
        'admin_module_list' => ['core.modules.read'],
        'admin_plugin_list' => ['core.plugins.read'],
        'admin_template_list' => ['core.templates.read'],
        'admin_template_layout_edit' => ['core.templates.read'],
        'admin_settings' => ['core.settings.read'],

        // sirsoft-ecommerce 모듈
        'admin_ecommerce_product_list' => ['sirsoft-ecommerce.products.read'],
        'admin_ecommerce_product_form' => ['sirsoft-ecommerce.products.read'],
        'admin_ecommerce_brand_index' => ['sirsoft-ecommerce.brands.read'],
        'admin_ecommerce_category_index' => ['sirsoft-ecommerce.categories.read'],
        'admin_ecommerce_order_index' => ['sirsoft-ecommerce.orders.read'],
        'admin_ecommerce_order_list' => ['sirsoft-ecommerce.orders.read'],
        'admin_ecommerce_order_detail' => ['sirsoft-ecommerce.orders.read'],
        'admin_ecommerce_settings' => ['sirsoft-ecommerce.settings.read'],
        'admin_ecommerce_order_settings' => ['sirsoft-ecommerce.settings.read'],
        'admin_ecommerce_mileage_deposit_settings' => ['sirsoft-ecommerce.settings.read'],
        'admin_ecommerce_promotion_coupon_list' => ['sirsoft-ecommerce.promotion-coupon.read'],
        'admin_ecommerce_promotion_coupon_form' => ['sirsoft-ecommerce.promotion-coupon.read'],
        'admin_ecommerce_promotion_coupon_create' => ['sirsoft-ecommerce.promotion-coupon.read'],
        'admin_ecommerce_product_notice_index' => ['sirsoft-ecommerce.product-notice-templates.read'],

        // sirsoft-board 모듈
        'admin_board_index' => ['sirsoft-board.boards.read'],
        'admin_board_form' => ['sirsoft-board.boards.read'],
        'admin_board_posts_index' => ['sirsoft-board.boards.read'],
        'admin_board_post_form' => ['sirsoft-board.boards.read'],
        'admin_board_post_detail' => ['sirsoft-board.boards.read'],
        'admin_board_reports_index' => ['sirsoft-board.reports.view'],
        'admin_board_reports_detail' => ['sirsoft-board.reports.view'],

        // sirsoft-sample 모듈
        'admin_sample_index' => ['sirsoft-sample.items.view'],
        'admin_sample_edit' => ['sirsoft-sample.items.view'],

        // sirsoft-daum_postcode 플러그인
        'plugin_settings' => ['core.plugins.update'],
    ];

    /**
     * 공개 레이아웃 목록 (권한 추가 제외)
     *
     * @var array<string>
     */
    protected array $publicLayouts = [
        // Base 레이아웃
        '_admin_base',
        '_user_base',

        // 인증 페이지
        'admin_login',
        'login',
        'register',
        'forgot_password',
        'reset_password',

        // 에러 페이지
        '403',
        '404',
        '500',
        '503',

        // 테스트용
        'template_partial_test',
        'module_partial_test',
    ];

    /**
     * 처리 통계
     *
     * @var array<string, int>
     */
    protected array $stats = [
        'scanned' => 0,
        'updated' => 0,
        'skipped' => 0,
        'errors' => 0,
    ];

    /**
     * 커맨드 실행
     */
    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');
        $targetLayout = $this->option('layout');
        $force = $this->option('force');

        if ($isDryRun) {
            $this->info('🔍 시뮬레이션 모드로 실행합니다. (실제 파일 수정 없음)');
            $this->newLine();
        }

        // 템플릿 레이아웃 처리
        $this->info('📁 템플릿 레이아웃 스캔 중...');
        $this->processTemplateLayouts($targetLayout, $isDryRun, $force);

        // 모듈 레이아웃 처리
        $this->newLine();
        $this->info('📁 모듈 레이아웃 스캔 중...');
        $this->processModuleLayouts($targetLayout, $isDryRun, $force);

        // 플러그인 레이아웃 처리
        $this->newLine();
        $this->info('📁 플러그인 레이아웃 스캔 중...');
        $this->processPluginLayouts($targetLayout, $isDryRun, $force);

        // 결과 출력
        $this->newLine();
        $this->info('📊 처리 결과:');
        $this->table(
            ['항목', '개수'],
            [
                ['스캔된 레이아웃', $this->stats['scanned']],
                ['업데이트됨', $this->stats['updated']],
                ['건너뜀', $this->stats['skipped']],
                ['오류', $this->stats['errors']],
            ]
        );

        if ($isDryRun && $this->stats['updated'] > 0) {
            $this->newLine();
            $this->warn('⚠️  --dry-run 옵션을 제거하면 실제로 파일이 수정됩니다.');
        }

        return self::SUCCESS;
    }

    /**
     * 템플릿 레이아웃 처리
     */
    protected function processTemplateLayouts(?string $targetLayout, bool $isDryRun, bool $force): void
    {
        $templatesPath = base_path('templates');

        if (! File::exists($templatesPath)) {
            return;
        }

        foreach (File::directories($templatesPath) as $templateDir) {
            $layoutsPath = $templateDir . '/layouts';

            if (! File::exists($layoutsPath)) {
                continue;
            }

            $this->processLayoutDirectory($layoutsPath, $targetLayout, $isDryRun, $force);
        }
    }

    /**
     * 모듈 레이아웃 처리
     */
    protected function processModuleLayouts(?string $targetLayout, bool $isDryRun, bool $force): void
    {
        $modulesPath = base_path('modules');

        if (! File::exists($modulesPath)) {
            return;
        }

        foreach (File::directories($modulesPath) as $moduleDir) {
            $layoutsPath = $moduleDir . '/resources/layouts';

            if (! File::exists($layoutsPath)) {
                continue;
            }

            $this->processLayoutDirectory($layoutsPath, $targetLayout, $isDryRun, $force);
        }
    }

    /**
     * 플러그인 레이아웃 처리
     */
    protected function processPluginLayouts(?string $targetLayout, bool $isDryRun, bool $force): void
    {
        $pluginsPath = base_path('plugins');

        if (! File::exists($pluginsPath)) {
            return;
        }

        foreach (File::directories($pluginsPath) as $pluginDir) {
            $layoutsPath = $pluginDir . '/resources/layouts';

            if (! File::exists($layoutsPath)) {
                continue;
            }

            $this->processLayoutDirectory($layoutsPath, $targetLayout, $isDryRun, $force);
        }
    }

    /**
     * 레이아웃 디렉토리 내 JSON 파일 처리
     */
    protected function processLayoutDirectory(string $path, ?string $targetLayout, bool $isDryRun, bool $force): void
    {
        // 재귀적으로 모든 JSON 파일 검색
        $files = File::allFiles($path);

        foreach ($files as $file) {
            if ($file->getExtension() !== 'json') {
                continue;
            }

            // partials 디렉토리는 건너뜀
            if (str_contains($file->getPathname(), '/partials/') || str_contains($file->getPathname(), '\\partials\\')) {
                continue;
            }

            $this->processLayoutFile($file->getPathname(), $targetLayout, $isDryRun, $force);
        }
    }

    /**
     * 개별 레이아웃 파일 처리
     */
    protected function processLayoutFile(string $filePath, ?string $targetLayout, bool $isDryRun, bool $force): void
    {
        $this->stats['scanned']++;

        // JSON 파일 로드
        $content = File::get($filePath);
        $layout = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->error("  ❌ JSON 파싱 실패: {$filePath}");
            $this->stats['errors']++;
            return;
        }

        $layoutName = $layout['layout_name'] ?? pathinfo($filePath, PATHINFO_FILENAME);

        // 특정 레이아웃만 처리하는 경우
        if ($targetLayout !== null && $layoutName !== $targetLayout) {
            return;
        }

        // 공개 레이아웃인 경우 건너뜀
        if ($this->isPublicLayout($layoutName)) {
            $this->line("  ⏭️  건너뜀 (공개 레이아웃): {$layoutName}");
            $this->stats['skipped']++;
            return;
        }

        // 이미 permissions가 있는 경우 (멱등성 보장)
        if (isset($layout['permissions']) && ! $force) {
            $this->line("  ⏭️  건너뜀 (이미 존재): {$layoutName}");
            $this->stats['skipped']++;
            return;
        }

        // permissions 결정: 매핑이 있으면 해당 권한, 없으면 빈 배열
        $permissions = $this->permissionMappings[$layoutName] ?? [];

        // 로그 메시지 생성 (빈 배열/권한 있음 구분)
        $permissionDisplay = empty($permissions) ? '[] (공개)' : json_encode($permissions);

        if ($isDryRun) {
            $this->info("  ✅ 업데이트 예정: {$layoutName} → {$permissionDisplay}");
            $this->stats['updated']++;
            return;
        }

        // 실제 파일 수정
        $layout['permissions'] = $permissions;

        // version 다음에 permissions 배치하기 위해 순서 재정렬
        $orderedLayout = $this->reorderLayoutKeys($layout);

        $newContent = json_encode($orderedLayout, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        File::put($filePath, $newContent . "\n");

        $this->info("  ✅ 업데이트됨: {$layoutName} → {$permissionDisplay}");
        $this->stats['updated']++;
    }

    /**
     * 공개 레이아웃인지 확인
     */
    protected function isPublicLayout(string $layoutName): bool
    {
        // 정확한 일치 확인
        if (in_array($layoutName, $this->publicLayouts, true)) {
            return true;
        }

        // _로 시작하는 베이스 레이아웃
        if (str_starts_with($layoutName, '_')) {
            return true;
        }

        // auth/ 경로
        if (str_starts_with($layoutName, 'auth/') || str_starts_with($layoutName, 'auth_')) {
            return true;
        }

        // errors/ 경로
        if (str_starts_with($layoutName, 'errors/') || str_starts_with($layoutName, 'errors_')) {
            return true;
        }

        return false;
    }

    /**
     * 레이아웃 키 순서 재정렬 (permissions를 version 다음에 배치)
     */
    protected function reorderLayoutKeys(array $layout): array
    {
        $orderedKeys = [
            'version',
            'layout_name',
            'permissions',
            'extends',
            'endpoint',
            'meta',
            'state',
            'init_state',
            'computed',
            'data_sources',
            'defines',
            'init_actions',
            'components',
            'slots',
            'modals',
            'scripts',
        ];

        $ordered = [];

        // 정의된 순서대로 키 배치
        foreach ($orderedKeys as $key) {
            if (array_key_exists($key, $layout)) {
                $ordered[$key] = $layout[$key];
            }
        }

        // 나머지 키 추가 (정의되지 않은 키)
        foreach ($layout as $key => $value) {
            if (! array_key_exists($key, $ordered)) {
                $ordered[$key] = $value;
            }
        }

        return $ordered;
    }
}
