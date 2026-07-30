<?php

namespace App\Console\Commands;

use App\Extension\Traits\ResolvesLayoutInstallSource;
use App\Support\LayoutMarkup\LayoutCorpus;
use App\Support\LayoutMarkup\MarkupPermissionsEditor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * 레이아웃에 permissions 를 일괄 추가하는 커맨드
 *
 * 관리자 레이아웃에 접근 제어를 위한 permissions 를 추가합니다.
 *
 * ## S18.5 — 저작본을 고친다
 *
 * 종전에는 확장 `resources/layouts` 를 재귀 순회하며 `getExtension() !== 'json'` 으로 거르고, 찾은
 * `.json` 에 권한을 **in-place 로 기록**했다. 짝이 전건 병존하던 동안에는 결함이 0 이었으나 두 가지
 * 문제가 있었다 — ⓐ 마크업 전용 레이아웃을 조용히 건너뛰었고 ⓑ **굽기(`layout:decompile --sync`)가
 * `.json` 을 기계 산출로 바꾸면 이 편집은 다음 굽기에 소실된다**. 32차가 이 자리를
 * `JSON_ONLY_LAYOUT_CONSUMER_DEBT` 에 등재하며 "S18.5 착수 시 전환해야 하는 자리" 로 사유를 적어 뒀다.
 *
 * 이제 소스 선택을 **규약에 위임**하고({@see ResolvesLayoutInstallSource::mergeLayoutSourcesByPriority()})
 * 이긴 소스를 고친다 — 저작본이 있으면 `.g7.html` 의 `<permissions>` 를, 없으면 종전처럼 `.json` 을.
 * 저작본을 고친 뒤에는 짝을 다시 구워야 하므로 커맨드가 그 명령을 안내한다.
 *
 * 모집단도 `LayoutCorpus`(번들 트리) 로 옮겼다 — 종전에는 활성 설치 디렉토리를 순회해, 확장 수정은
 * `_bundled` 에서만 한다는 프로젝트 규율과 어긋나 있었고 그 편집은 다음 `{type}:update` 에 덮였다.
 */
class AddLayoutPermissionsCommand extends Command
{
    use ResolvesLayoutInstallSource;

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
        // 저작본을 고친 건수 — 0 보다 크면 짝을 다시 구우라고 안내한다.
        'markupUpdated' => 0,
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

        $this->info('📁 번들 레이아웃 스캔 중...');

        foreach ($this->layoutSources() as $source) {
            $this->processLayoutSource($source, $targetLayout, $isDryRun, $force);
        }

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

        if (! $isDryRun && $this->stats['markupUpdated'] > 0) {
            $this->newLine();
            $this->warn('저작본(.g7.html) 을 고쳤습니다 — 짝 .json 을 다시 구우세요: php artisan layout:decompile --all --sync');
        }

        return self::SUCCESS;
    }

    /**
     * 처리 대상 레이아웃 소스를 **규약 우선순위대로** 도출합니다.
     *
     * 모집단은 {@see LayoutCorpus} 가 SSoT 이고(번들 트리 — 활성 디렉토리 편집은 다음
     * `{type}:update` 에 덮인다), 짝이 병존할 때 어느 쪽을 고칠지는
     * {@see ResolvesLayoutInstallSource::mergeLayoutSourcesByPriority()} 가 정한다. 여기서 `.json` 을
     * 하드코딩해 고르면 마크업 전용 레이아웃이 조용히 빠지고, 고친 값이 다음 굽기에 소실된다.
     *
     * 조각(`partials/`)은 대상이 아니다 — 권한은 문서 최상위 키이고 조각의 루트는 노드다.
     *
     * @return array<int, array{path: string, markup: bool}> 소스 디스크립터 목록
     */
    protected function layoutSources(): array
    {
        $isPartial = static fn (string $path): bool => str_contains(str_replace('\\', '/', $path), '/partials/');

        $sources = $this->mergeLayoutSourcesByPriority(
            array_values(array_filter(LayoutCorpus::scanLayouts('.g7.html'), static fn (string $p): bool => ! $isPartial($p))),
            array_values(array_filter(LayoutCorpus::scanLayouts('.json'), static fn (string $p): bool => ! $isPartial($p))),
            '레이아웃',
        );

        usort($sources, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));

        return $sources;
    }

    /**
     * 소스 하나를 처리합니다 (저작본이면 마크업, 아니면 JSON 을 고친다).
     *
     * @param  array{path: string, markup: bool}  $source  소스 디스크립터
     * @param  string|null  $targetLayout  특정 레이아웃만 처리
     * @param  bool  $isDryRun  시뮬레이션 여부
     * @param  bool  $force  기존 permissions 덮어쓰기
     */
    protected function processLayoutSource(array $source, ?string $targetLayout, bool $isDryRun, bool $force): void
    {
        $this->stats['scanned']++;

        $filePath = $source['path'];
        $layoutName = $this->layoutNameOf($filePath);

        if ($targetLayout !== null && $layoutName !== $targetLayout) {
            return;
        }

        if ($this->isPublicLayout($layoutName)) {
            $this->line("  ⏭️  건너뜀 (공개 레이아웃): {$layoutName}");
            $this->stats['skipped']++;

            return;
        }

        try {
            $hasPermissions = $source['markup']
                ? MarkupPermissionsEditor::hasDocumentPermissions(File::get($filePath), $filePath)
                : array_key_exists('permissions', $this->decodeLayout($filePath));
        } catch (\Throwable $error) {
            $this->error("  ❌ 읽기 실패: {$this->relative($filePath)} — {$error->getMessage()}");
            $this->stats['errors']++;

            return;
        }

        // 멱등성 보장 — 이미 있으면 --force 없이는 건드리지 않는다.
        if ($hasPermissions && ! $force) {
            $this->line("  ⏭️  건너뜀 (이미 존재): {$layoutName}");
            $this->stats['skipped']++;

            return;
        }

        $permissions = $this->permissionMappings[$layoutName] ?? [];
        $permissionDisplay = $permissions === [] ? '[] (공개)' : json_encode($permissions);
        $surface = $source['markup'] ? '저작본' : 'JSON';

        if ($isDryRun) {
            $this->info("  ✅ 업데이트 예정: {$layoutName} → {$permissionDisplay} ({$surface})");
            $this->stats['updated']++;

            return;
        }

        try {
            $source['markup']
                ? $this->writeMarkupPermissions($filePath, $permissions)
                : $this->writeJsonPermissions($filePath, $permissions);
        } catch (\Throwable $error) {
            $this->error("  ❌ 기록 실패: {$this->relative($filePath)} — {$error->getMessage()}");
            $this->stats['errors']++;

            return;
        }

        $this->stats['markupUpdated'] += $source['markup'] ? 1 : 0;
        $this->info("  ✅ 업데이트됨: {$layoutName} → {$permissionDisplay} ({$surface})");
        $this->stats['updated']++;
    }

    /**
     * 저작본의 `<permissions>` 를 고칩니다.
     *
     * @param  string  $filePath  `.g7.html` 경로
     * @param  array<int, string>  $permissions  권한 목록
     */
    protected function writeMarkupPermissions(string $filePath, array $permissions): void
    {
        File::put($filePath, MarkupPermissionsEditor::apply(File::get($filePath), $permissions, $filePath));
    }

    /**
     * 저작본이 없는 레이아웃의 `.json` 에 권한을 기록합니다.
     *
     * @param  string  $filePath  `.json` 경로
     * @param  array<int, string>  $permissions  권한 목록
     */
    protected function writeJsonPermissions(string $filePath, array $permissions): void
    {
        $layout = $this->decodeLayout($filePath);
        $layout['permissions'] = $permissions;

        File::put(
            $filePath,
            json_encode($this->reorderLayoutKeys($layout), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n",
        );
    }

    /**
     * 레이아웃 이름을 결정합니다 (저작본은 루트 `name` 속성, JSON 은 `layout_name`, 둘 다 없으면 파일명).
     *
     * @param  string  $filePath  소스 경로
     * @return string 레이아웃 이름
     */
    protected function layoutNameOf(string $filePath): string
    {
        $basename = preg_replace('/\.(g7\.html|json)$/i', '', basename($filePath)) ?? '';

        if (preg_match('/\.g7\.html$/i', $filePath) === 1) {
            return preg_match('/<(?:layout|override)\b[^>]*\bname="([^"]+)"/', File::get($filePath), $match) === 1
                ? $match[1]
                : $basename;
        }

        try {
            return $this->decodeLayout($filePath)['layout_name'] ?? $basename;
        } catch (\Throwable) {
            return $basename;
        }
    }

    /**
     * `.json` 레이아웃을 디코드합니다.
     *
     * @param  string  $filePath  `.json` 경로
     * @return array<string, mixed> 디코드된 레이아웃
     *
     * @throws \JsonException JSON 파싱 실패 시
     */
    protected function decodeLayout(string $filePath): array
    {
        $layout = json_decode(File::get($filePath), true);

        if (! is_array($layout)) {
            // 메시지는 PHP 가 만든 파싱 오류를 그대로 옮긴다 — 커맨드가 문구를 지어내지 않으므로
            // 다국어 키가 필요 없고, 한국어 하드코딩도 생기지 않는다.
            throw new \JsonException(json_last_error_msg(), json_last_error());
        }

        return $layout;
    }

    /**
     * 절대 경로를 저장소 상대 경로로 바꿉니다 (보고용).
     *
     * @param  string  $path  절대 경로
     * @return string 상대 경로
     */
    protected function relative(string $path): string
    {
        return str_replace('\\', '/', str_replace(base_path().DIRECTORY_SEPARATOR, '', $path));
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
