<?php

namespace App\Upgrades;

use App\Contracts\Extension\UpgradeStepInterface;
use App\Extension\UpgradeContext;
use App\Models\NotificationDefinition;
use App\Models\NotificationTemplate;
use Database\Seeders\NotificationDefinitionSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * 코어 7.0.0-beta.2 업그레이드 스텝
 *
 * mail_templates → notification_definitions + notification_templates 이관.
 * 기존 mail_templates 데이터(특히 user_overrides된 커스텀 템플릿)를 보존합니다.
 *
 * @upgrade-path C
 *
 * 경로 C(이전 버전 CoreUpdateCommand 의 in-process 메모리에서 실행) 선언.
 * beta.1 CoreUpdateCommand 가 `core:execute-upgrade-steps` spawn 커맨드를 모르므로
 * 본 파일은 beta.1 메모리 기준으로 실행된다. validate 스크립트가 이 메타데이터를
 * 인식하여 기존 클래스의 신규 메서드 호출에 대한 **강한 규율**을 적용한다.
 *
 * 상세: docs/extension/upgrade-step-guide.md 섹션 9.
 */
class Upgrade_7_0_0_beta_2 implements UpgradeStepInterface
{
    /**
     * 인스톨러 SSoT 경로 목록 (경로 C: config() 호출 불가라 하드코딩).
     *
     * `public/install/includes/config.php:REQUIRED_DIRECTORIES` 와 일치. 상위 → 하위
     * 순서로 나열하여 재귀 chown 후 하위 경로별 개별 복원이 수행되게 한다.
     *
     * @var list<string>
     */
    private const INSTALLER_SSOT_PATHS = [
        'storage',
        'bootstrap/cache',
        'vendor',
        'modules',
        'modules/_pending',
        'plugins',
        'plugins/_pending',
        'templates',
        'templates/_pending',
        'storage/app/core_pending',
    ];

    /**
     * 업그레이드 스텝을 실행합니다.
     *
     * @param UpgradeContext $context 업그레이드 컨텍스트
     * @return void
     */
    public function run(UpgradeContext $context): void
    {
        // 0. PHP 종료 시점 ownership 복원 핸들러 등록 (최우선).
        //
        // 경로 C 한계 — beta.1 CoreUpdateCommand 의 Step 11 `clearAllCaches()` 가 본 upgrade step
        // 반환 이후에 실행되며 내부적으로 `extension:update-autoload` / `package:discover` 등을
        // sudo 컨텍스트에서 호출해 bootstrap/cache 내부 파일을 root 로 재생성한다. beta.1 에는
        // restoreOwnership 자체가 없어 이 재오염을 복원할 주체가 없다.
        // register_shutdown_function 으로 PHP 프로세스 종료 직전(= Step 11 완료 후) spawn 하여
        // beta.2 최신 CoreUpdateService 로 최종 복원한다.
        $this->registerFinalOwnershipRestoreHandler($context);

        // 1. notification_definitions 테이블 존재 확인
        if (! Schema::hasTable('notification_definitions')) {
            $context->logger->warning('[beta.2] notification_definitions 테이블 미존재 — 마이그레이션 먼저 실행 필요');

            return;
        }

        // 2. 코어 알림 정의 시딩 (3종)
        $context->logger->info('[beta.2] 코어 알림 정의 시딩...');
        (new NotificationDefinitionSeeder())->run();

        // 3. mail_templates → notification_templates 데이터 이관
        if (Schema::hasTable('mail_templates')) {
            $context->logger->info('[beta.2] 코어 메일 템플릿 이관 시작...');
            $this->migrateMailTemplates($context);
        }

        // 4. mail_send_logs → notification_logs 데이터 이관
        if (Schema::hasTable('mail_send_logs') && Schema::hasTable('notification_logs')) {
            $context->logger->info('[beta.2] 메일 발송 이력 이관 시작...');
            $this->migrateMailSendLogs($context);
        }

        // 4-1. activity_logs.description_key 변환 (mail_template_* → notification_template_*)
        $this->migrateActivityLogDescriptionKeys($context);

        // 5. 권한 매핑 전환 (core.mail-send-logs.* → core.notification-logs.*)
        if (Schema::hasTable('permissions')) {
            $this->migratePermissions($context);
        }

        // 5-1. 사용자 알림 권한 분리 정리
        // beta.2 개발 중간 빌드에서 core.notifications.*(type=admin)을 user role에 잘못 바인딩한 경우를 정정한다.
        // 정상 시드는 user role에 core.user-notifications.*(type=user)을 매핑해야 한다.
        if (Schema::hasTable('permissions') && Schema::hasTable('roles') && Schema::hasTable('role_permissions')) {
            $this->cleanupUserNotificationPermissions($context);
        }

        // 6. 기존 캐시 무효화
        $this->invalidateCaches();
        $context->logger->info('[beta.2] 캐시 무효화 완료');

        // 7. beta.1 호환 shim 자기 정리 (MailTemplate 모델 + mail_templates 테이블)
        $this->cleanupMailTemplateShim($context);

        // 8. beta.1 → beta.2 1회성 — beta.1 의 core:update 가 sudo 실행 시 인스톨러 SSoT 경로
        //    전체(vendor·bootstrap/cache·storage 등) 를 root 로 오염시킨 흔적 복원.
        $this->restoreAllOwnershipLocal($context);

        // 9. beta.1 → beta.2 1회성 — 메모리의 구 클래스로 인한 신규 권한/메뉴 일부 누락 해결.
        //    proc_open 으로 새 프로세스를 spawn 하여 beta.2 최신 클래스 기반 sync 수행.
        $this->resyncCorePermissionsAndMenus($context);

        // 10. beta.1 → beta.2 1회성 — spawn 실패 fallback 대비 user role 의 알림 권한 직접 attach.
        //     spawn 성공 시에도 idempotent (insertOrIgnore).
        $this->ensureUserRoleCoreNotificationsLocal($context);

        // 11. beta.1 → beta.2 1회성 — config/core.php 기준으로 stale 코어 권한/메뉴/역할 삭제.
        //     spawn 성공 시 이미 정리되었을 가능성 높으나 idempotent 안전 장치. spawn 실패 시 유일 복구 수단.
        $this->cleanupCoreStaleEntriesLocal($context);

        // 12. beta.1 → beta.2 — 모듈/플러그인 레이아웃의 original_content_hash 백필.
        //     beta.1 에서는 registerLayoutToTemplate() 이 hash 를 저장하지 않아 해당 기간 레이아웃은 NULL.
        //     layout_strategy=keep 의 사용자 수정 감지가 작동하려면 hash 가 필요하므로
        //     현재 content 를 원본으로 간주해 복구한다.
        $this->backfillModuleAndPluginLayoutHashes($context);

        $context->logger->info('[beta.2] 알림 시스템 통합 완료');
    }

    /**
     * 모듈/플러그인 레이아웃의 original_content_hash / original_content_size 를 백필합니다.
     *
     * 2026-04-01 add_original_content_hash_to_template_layouts_table 마이그레이션 이후
     * ModuleManager::registerLayoutToTemplate() / PluginManager::registerLayoutToTemplate() 이
     * hash 를 저장하지 않았던 기간(beta.1~beta.2 개발 중)의 레이아웃 행을 복구합니다.
     *
     * 현재 content 를 "원본" 으로 간주하여 hash/size 를 계산합니다 (원본 파일에서 수정된 적이 없는
     * 경우에만 이 전제가 맞지만, 대안이 없으므로 보수적으로 적용). 이후 사용자가 UI 에서 수정하면
     * 현재 content 의 hash 가 달라져 keep 전략에서 감지됩니다.
     *
     * 멱등: 이미 hash 가 있는 행은 건너뜀.
     *
     * @param UpgradeContext $context 업그레이드 컨텍스트
     * @return void
     */
    private function backfillModuleAndPluginLayoutHashes(UpgradeContext $context): void
    {
        if (! Schema::hasTable('template_layouts')) {
            return;
        }
        if (! Schema::hasColumn('template_layouts', 'original_content_hash')) {
            return;
        }

        $total = 0;
        DB::table('template_layouts')
            ->whereIn('source_type', ['module', 'plugin'])
            ->whereNull('original_content_hash')
            ->orderBy('id')
            ->chunk(100, function ($layouts) use (&$total) {
                foreach ($layouts as $layout) {
                    $content = json_decode($layout->content, true);
                    $normalized = json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $hash = hash('sha256', $normalized);
                    $size = strlen($normalized);

                    DB::table('template_layouts')
                        ->where('id', $layout->id)
                        ->update([
                            'original_content_hash' => $hash,
                            'original_content_size' => $size,
                        ]);
                    $total++;
                }
            });

        if ($total > 0) {
            $context->logger->info("[beta.2] 모듈/플러그인 레이아웃 hash 백필 완료: {$total}건");
        }
    }

    /**
     * beta.1 → beta.2 업그레이드 호환용 MailTemplate shim 과 mail_templates 테이블을 제거합니다.
     *
     * MailTemplate 모델은 beta.2 에서 공식 제거되었으나, in-memory 로 실행되는 beta.1 의
     * `CoreUpdateService::syncCoreMailTemplates()` 가 autoload 를 요구하여 한시적 shim 으로
     * 유지되었다. 본 스텝이 자기 정리하며, 데이터는 `migrateMailTemplates()` 단계에서 이미
     * `notification_templates` 로 이관되었음이 보장된다.
     *
     * 멱등: 파일/테이블이 없으면 no-op.
     *
     * @param UpgradeContext $context
     * @return void
     */
    private function cleanupMailTemplateShim(UpgradeContext $context): void
    {
        // mail_templates 테이블 제거 (데이터 이관 완료 전제)
        if (Schema::hasTable('mail_templates')) {
            Schema::drop('mail_templates');
            $context->logger->info('[beta.2] mail_templates 테이블 제거');
        }

        // MailTemplate 모델 shim 파일 제거
        $shimPath = app_path('Models/MailTemplate.php');
        if (File::exists($shimPath)) {
            File::delete($shimPath);
            $context->logger->info('[beta.2] app/Models/MailTemplate.php shim 제거');
        }
    }

    /**
     * beta.1 → beta.2 전용 1회성 — 메모리의 구 config 를 디스크 기준으로 재로드 후
     * 기존 sync 메서드를 재호출하여 신규 권한/메뉴를 DB 에 반영합니다.
     *
     * 배경:
     *  - Laravel 은 프로세스 시작 시 `config/core.php` 를 1회만 로드. Step 7 applyUpdate
     *    로 파일이 beta.2 로 교체되어도 메모리 config 는 beta.1 그대로 유지된다.
     *  - 더 심각한 문제: sync 로직을 담은 클래스(CoreUpdateService·ExtensionRoleSyncHelper·
     *    PermissionRepository 등) 도 beta.1 로 이미 로드되어 있어 **beta.2 에서 추가된
     *    신규 로직(완전 동기화, 매핑 범위 해소 등) 이 적용되지 않는다.**
     *  - 결과: in-process sync 재호출은 "메모리 config 갱신" 효과만 있을 뿐, 신규 권한·
     *    매핑 로직은 여전히 beta.1 버전 → 일부 권한 upsert/attach 누락.
     *
     * 해법: **proc_open 으로 새 PHP 프로세스를 spawn** 하여 beta.2 최신 클래스를
     * Composer autoload 로 로드하고 `CoreUpdateService::reloadCoreConfigAndResync()` 호출.
     * proc_open 은 런타임 기능(PHP 언어 수준) 이라 upgrade step 파일이 beta.1 메모리에서
     * 실행되어도 새 프로세스는 영향받지 않는다.
     *
     * proc_open 불가 환경 (shared host `disable_functions`) fallback:
     *  - 기존 in-process 경로 (config 재로드 + beta.1 sync 재호출). 불완전하나 최소 복구.
     *  - 수동 복구 안내: `php artisan tinker --execute="..."` 로 beta.2 로직 직접 호출 가능.
     *
     * 본 메서드는 beta.2 이후 버전부터는 정식 CoreUpdateCommand 의 spawn 경로(경로 B)
     * 가 자동 수행하므로 beta.3+ 에서 본 upgrade step 전체와 함께 제거된다.
     *
     * @param  UpgradeContext  $context
     * @return void
     */
    private function resyncCorePermissionsAndMenus(UpgradeContext $context): void
    {
        $corePath = config_path('core.php');
        if (! File::exists($corePath)) {
            $context->logger->warning('[beta.2] config/core.php 미존재 — 권한/메뉴 재동기화 스킵');

            return;
        }

        // 1순위: spawn — beta.2 최신 클래스 기반 완전 동기화
        if ($this->spawnResyncInlineLocal($context)) {
            $context->logger->info('[beta.2] 권한/메뉴 재동기화 완료 (spawn — beta.2 최신 로직)');

            return;
        }

        // 2순위: in-process fallback — beta.1 메모리 구 클래스라 불완전하나 최소 복구
        $context->logger->warning('[beta.2] spawn 실패 — in-process fallback 진행 (일부 누락 가능)');

        $fresh = require $corePath;
        if (! is_array($fresh)) {
            $context->logger->warning('[beta.2] config/core.php 반환값이 배열이 아님 — fallback 스킵');

            return;
        }
        config(['core' => $fresh]);

        $service = app(\App\Services\CoreUpdateService::class);
        try {
            $service->syncCoreRolesAndPermissions();
            $context->logger->info('[beta.2] fallback: 코어 권한 재동기화 완료');
        } catch (\Throwable $e) {
            $context->logger->warning('[beta.2] fallback: syncCoreRolesAndPermissions 실패: '.$e->getMessage());
        }
        try {
            $service->syncCoreMenus();
            $context->logger->info('[beta.2] fallback: 코어 메뉴 재동기화 완료');
        } catch (\Throwable $e) {
            $context->logger->warning('[beta.2] fallback: syncCoreMenus 실패: '.$e->getMessage());
        }

        $context->logger->warning(
            '[beta.2] in-process fallback 완료 — 누락 가능. 수동 복구: '
            .'php artisan tinker --execute='
            .'"app(App\\Services\\CoreUpdateService::class)->reloadCoreConfigAndResync();"'
        );
    }

    /**
     * proc_open 으로 새 PHP 프로세스를 spawn 하여 `reloadCoreConfigAndResync()` 를 호출합니다.
     *
     * 새 프로세스는 디스크의 beta.2 파일을 Composer autoload 로 로드하므로 **모든 클래스·
     * config 가 최신 버전**. 이로써 beta.1 메모리의 클래스 캐싱 제약을 우회.
     *
     * 환경변수 `G7_BASE_PATH` 로 base_path 전달 — inline 스크립트 내에서 `__DIR__` 사용 불가
     * (eval 실행 컨텍스트의 cwd 가 base_path 와 일치한다는 보장 없음).
     *
     * @param  UpgradeContext  $context
     * @return bool  spawn 성공 여부 (false 면 호출자가 in-process fallback 진행)
     */
    private function spawnResyncInlineLocal(UpgradeContext $context): bool
    {
        if (! function_exists('proc_open')) {
            $context->logger->info('[beta.2] proc_open 비활성 — spawn 불가');

            return false;
        }

        $basePath = base_path();

        // beta.1 메모리 (TTY 연결됨) 에서 _bundled 업데이트 감지 + 사용자 확인.
        // spawn 프로세스는 stdin 파이프 기반이라 프롬프트 불가 — 메타정보를 env 로만 전달한다.
        $bulkDecision = $this->promptBulkBundledUpdateLocal($context);

        // Laravel 앱 부팅 + 3가지 후처리를 inline PHP 에서 순차 실행.
        //
        // 1) reloadCoreConfigAndResync() — 신규 권한/메뉴 DB 동기화
        // 2) 번들 확장 자동 일괄 업데이트 — _bundled 에 포함된 새 버전의 모듈/플러그인/템플릿을
        //    layout_strategy='keep' (사용자 수정 레이아웃 보존) 로 자동 반영.
        //    beta.1 CoreUpdateCommand 에는 일괄 업데이트 프롬프트가 없어 누락되는 문제 보완.
        // 3) restoreOwnership([]) — 1·2 단계에서 sudo 컨텍스트로 생성된 파일들
        //    (bootstrap/cache/autoload-extensions.php, modules/{id}/vendor 등) 소유권 복원.
        //    빈 배열 전달 시 config('app.update.restore_ownership') + inferWebServerOwnership fallback.
        //
        // 본 spawn 이 beta.2 최신 클래스를 Composer autoload 로 로드하므로 모든 신 시그니처·재귀 복원
        // 로직을 그대로 사용할 수 있다. spawn 실패 시 in-process fallback 은 권한/메뉴 sync 만 수행.
        $phpCode = <<<'PHP'
$base = getenv('G7_BASE_PATH');
chdir($base);
require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$service = app(App\Services\CoreUpdateService::class);
$service->reloadCoreConfigAndResync();

// 일괄 업데이트 결정은 부모(beta.1 TTY) 에서 내려 env 로 전달된다.
$bulkDecision = getenv('G7_BETA2_BULK_UPDATE') === '1';

if ($bulkDecision) {
    $moduleManager = app(App\Extension\ModuleManager::class);
    $pluginManager = app(App\Extension\PluginManager::class);
    $templateManager = app(App\Extension\TemplateManager::class);

    $moduleManager->loadModules();
    $pluginManager->loadPlugins();
    $templateManager->loadTemplates();

    $updates = $service->collectBundledExtensionUpdates();

    $updated = 0;
    $failed = 0;

    foreach ($updates['modules'] as $u) {
        $id = $u['identifier'];
        try {
            $moduleManager->updateModule($id, true, null, App\Extension\Vendor\VendorMode::Auto, 'keep', null, 'bundled');
            echo "BULK_OK module:{$id} {$u['current_version']} -> {$u['latest_version']}\n";
            $updated++;
        } catch (\Throwable $e) {
            echo "BULK_FAIL module:{$id}: ".$e->getMessage()."\n";
            $failed++;
        }
    }

    foreach ($updates['plugins'] as $u) {
        $id = $u['identifier'];
        try {
            $pluginManager->updatePlugin($id, true, null, App\Extension\Vendor\VendorMode::Auto, 'keep', null, 'bundled');
            echo "BULK_OK plugin:{$id} {$u['current_version']} -> {$u['latest_version']}\n";
            $updated++;
        } catch (\Throwable $e) {
            echo "BULK_FAIL plugin:{$id}: ".$e->getMessage()."\n";
            $failed++;
        }
    }

    foreach ($updates['templates'] as $u) {
        $id = $u['identifier'];
        try {
            $templateManager->updateTemplate($id, true, null, 'keep', 'bundled');
            echo "BULK_OK template:{$id} {$u['current_version']} -> {$u['latest_version']}\n";
            $updated++;
        } catch (\Throwable $e) {
            echo "BULK_FAIL template:{$id}: ".$e->getMessage()."\n";
            $failed++;
        }
    }

    echo "BULK_SUMMARY updated={$updated} failed={$failed}\n";
} else {
    echo "BULK_SKIPPED\n";
}

// 일괄 업데이트 여부와 무관하게 소유권 복원
$service->restoreOwnership([]);
echo "BETA2_SPAWN_OK\n";
PHP;

        $cmd = escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($phpCode).' 2>&1';

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        // 경로 C 인라인 env 구성.
        //
        // `$_ENV` 는 variables_order ini 에 E 가 없으면 비어있을 수 있으므로, getenv() 를
        // 기반으로 삼아 프로세스 환경변수 테이블 전체를 포함 — putenv 로 설정된
        // G7_UPDATE_IN_PROGRESS 등의 플래그가 자식에 확실히 전달되도록 한다.
        //
        // UpgradeContext::spawnEnv() 헬퍼는 beta.2 신규 메서드라 beta.1 메모리에서 호출 불가
        // (경로 C 규율) — 본 파일에서 인라인 구성.
        $env = array_merge(getenv(), $_ENV, [
            'G7_BASE_PATH' => $basePath,
            'G7_BETA2_BULK_UPDATE' => $bulkDecision ? '1' : '0',
            'APP_VERSION' => $context->toVersion,
            'G7_UPDATE_IN_PROGRESS' => '1',
        ]);

        $process = proc_open($cmd, $descriptors, $pipes, $basePath, $env);
        if (! is_resource($process)) {
            $context->logger->warning('[beta.2] proc_open 자원 생성 실패');

            return false;
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $context->logger->info('[beta.2] spawn 출력', ['exit' => $exitCode, 'stdout' => trim((string) $stdout)]);

        // 사용자 콘솔에 요약 출력 — UpgradeContext 는 Command/output 객체 미노출이므로
        // echo 로 직접 stdout 에 써서 Artisan 커맨드 콘솔에 표시되게 한다.
        // BULK_OK / BULK_FAIL / BULK_SUMMARY 라인만 필터링하여 사용자가 번들 확장 업데이트
        // 수행 여부를 즉시 확인할 수 있게 한다.
        $bulkLines = [];
        foreach (explode("\n", (string) $stdout) as $line) {
            $trimmed = rtrim($line);
            // BULK_SKIPPED 는 사용자가 프롬프트에서 이미 거절한 경우로, 추가 요약이 불필요.
            if ($trimmed !== '' && str_starts_with($trimmed, 'BULK_') && $trimmed !== 'BULK_SKIPPED') {
                $bulkLines[] = $trimmed;
            }
        }

        if (! empty($bulkLines)) {
            echo "\n  ── 번들 확장 일괄 업데이트 결과 ──\n";
            foreach ($bulkLines as $line) {
                echo "    {$line}\n";
            }
            echo "\n";
        }

        if ($exitCode === 0 && str_contains((string) $stdout, 'BETA2_SPAWN_OK')) {
            return true;
        }

        $context->logger->warning("[beta.2] spawn 비정상 종료 (exit={$exitCode})");

        return false;
    }

    /**
     * beta.1 메모리 (TTY 연결됨) 에서 _bundled 업데이트 후보를 감지하고 사용자에게 확인받습니다.
     *
     * 경로 C 규율: 신규 클래스 의존 금지. DB/File/Schema 는 beta.1 에도 존재한다.
     * 검사 방식은 CoreUpdateService::collectBundledExtensionUpdates() 와 동일한 로직의
     * 로컬 인라인 버전 — modules/plugins/templates 3종 테이블의 현재 version 을
     * _bundled/{id}/manifest.json 의 version 과 version_compare 로 비교한다.
     *
     * 프롬프트 동작:
     * - 감지 결과 없음 → false (spawn 시 BULK_SKIPPED)
     * - TTY 미연결(자동화·CI·sudo redirected I/O) → 안전하게 false 반환. 사용자가 의식 없이
     *   수정을 덮어쓰는 것을 막기 위해 기본 skip.
     * - TTY 연결 + 감지 결과 있음 → 목록 출력 후 "yes/no [yes]" 프롬프트. 빈 응답=yes.
     *
     * @param  UpgradeContext  $context
     * @return bool  사용자가 일괄 업데이트에 동의했으면 true
     */
    private function promptBulkBundledUpdateLocal(UpgradeContext $context): bool
    {
        $detected = [];
        foreach (['modules' => 'module.json', 'plugins' => 'plugin.json', 'templates' => 'template.json'] as $table => $manifestName) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach (DB::table($table)->get(['identifier', 'version']) as $row) {
                $identifier = (string) $row->identifier;
                $current = (string) $row->version;
                $manifestPath = base_path($table.DIRECTORY_SEPARATOR.'_bundled'.DIRECTORY_SEPARATOR.$identifier.DIRECTORY_SEPARATOR.$manifestName);
                if (! is_file($manifestPath)) {
                    continue;
                }
                $manifest = json_decode((string) @file_get_contents($manifestPath), true);
                $bundled = is_array($manifest) ? ($manifest['version'] ?? null) : null;
                if ($bundled === null || version_compare((string) $bundled, $current, '<=')) {
                    continue;
                }
                $detected[] = [
                    'type' => rtrim($table, 's'),
                    'identifier' => $identifier,
                    'current' => $current,
                    'latest' => (string) $bundled,
                ];
            }
        }

        if (empty($detected)) {
            return false;
        }

        echo "\n  ── 번들 확장 업데이트 감지 ──\n";
        foreach ($detected as $d) {
            echo "    [{$d['type']}] {$d['identifier']}: {$d['current']} → {$d['latest']}\n";
        }
        echo "\n";

        $isTty = defined('STDIN')
            && (function_exists('stream_isatty') ? @stream_isatty(STDIN) : (function_exists('posix_isatty') ? @posix_isatty(STDIN) : false));

        if (! $isTty) {
            echo "  TTY 미연결 — 일괄 업데이트 프롬프트 스킵. 필요 시 이후 `module:update <id> --force` / `plugin:update <id> --force` / `template:update <id> --force` 로 수동 반영하세요.\n\n";
            $context->logger->info('[beta.2] TTY 미연결 — 번들 일괄 업데이트 프롬프트 스킵');

            return false;
        }

        echo "  번들에 포함된 새 버전으로 일괄 업데이트하시겠습니까? (yes/no) [yes]: ";
        $answer = trim((string) fgets(STDIN));
        $confirmed = ($answer === '' || strtolower($answer) === 'yes' || strtolower($answer) === 'y');

        if ($confirmed) {
            echo "  일괄 업데이트 진행 (layout_strategy=keep)...\n\n";
        } else {
            echo "  일괄 업데이트 건너뜀. 필요 시 이후 `module:update <id> --force` / `plugin:update <id> --force` / `template:update <id> --force` 로 수동 반영하세요.\n\n";
        }

        return $confirmed;
    }

    /**
     * config/core.php 기준으로 stale 코어 권한/메뉴/역할을 삭제합니다 (beta.1 → beta.2 1회성).
     *
     * 정책:
     *  - config 에 없는 DB 레코드 중 `user_overrides` 가 **비어있는 것만** 삭제
     *  - 사용자 커스터마이즈(user_overrides 채워진) 는 보존
     *  - 역할의 경우 `user_roles` 피벗에 참조 사용자가 있으면 **삭제 차단 + 경고**
     *
     * 경로 C 규율: helper 의 `cleanupStale*` 호출 금지 (beta.1 메모리 구 클래스 가능성).
     * `Schema::hasTable/hasColumn` 방어. `DB::table` 로 직접 조작. idempotent.
     *
     * spawn 성공 시 beta.2 helper 가 이미 완전 동기화하므로 대개 no-op. spawn 실패 시
     * 유일한 stale 정리 경로. 어느 쪽이든 안전하게 호출 가능.
     *
     * @param  UpgradeContext  $context
     * @return void
     */
    private function cleanupCoreStaleEntriesLocal(UpgradeContext $context): void
    {
        $corePath = config_path('core.php');
        if (! File::exists($corePath)) {
            $context->logger->warning('[beta.2] config/core.php 미존재 — stale 정리 스킵');

            return;
        }
        $fresh = require $corePath;
        if (! is_array($fresh)) {
            return;
        }

        // 예상 식별자 수집
        $expectedPermIds = [$fresh['permissions']['module']['identifier'] ?? 'core'];
        foreach ($fresh['permissions']['categories'] ?? [] as $cat) {
            $expectedPermIds[] = $cat['identifier'];
            foreach ($cat['permissions'] ?? [] as $p) {
                $expectedPermIds[] = $p['identifier'];
            }
        }
        $expectedMenuSlugs = $this->collectMenuSlugsRecursiveLocal($fresh['menus'] ?? []);
        $expectedRoleIds = array_column($fresh['roles'] ?? [], 'identifier');

        // 트랜잭션: 중간 실패 시 부분 삭제 롤백. 예외는 상위 runUpgradeSteps 가 처리 → 백업 복원.
        DB::transaction(function () use ($context, $expectedPermIds, $expectedMenuSlugs, $expectedRoleIds) {
            $this->deleteStaleCorePermissionsLocal($context, $expectedPermIds);
            $this->deleteStaleCoreMenusLocal($context, $expectedMenuSlugs);
            $this->deleteStaleCoreRolesLocal($context, $expectedRoleIds);
        });
    }

    /**
     * config 메뉴 정의 트리에서 모든 slug 를 재귀 수집합니다.
     *
     * @param  array  $menus
     * @return list<string>
     */
    private function collectMenuSlugsRecursiveLocal(array $menus): array
    {
        $slugs = [];
        foreach ($menus as $menu) {
            if (isset($menu['slug'])) {
                $slugs[] = $menu['slug'];
            }
            if (! empty($menu['children']) && is_array($menu['children'])) {
                $slugs = array_merge($slugs, $this->collectMenuSlugsRecursiveLocal($menu['children']));
            }
        }

        return $slugs;
    }

    /**
     * user_overrides 비어있음 조건을 쿼리에 추가합니다 (컬럼 존재 시).
     *
     * user_overrides 는 JSON 배열(보통 수정된 필드명 목록). null / 빈 배열 / 빈 객체 / 빈 문자열
     * 을 모두 "비어있음" 으로 판정.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  bool  $hasOverrides
     * @return void
     */
    private function whereUserOverridesEmptyLocal($query, bool $hasOverrides): void
    {
        if (! $hasOverrides) {
            return;
        }
        $query->where(function ($q) {
            $q->whereNull('user_overrides')
                ->orWhere('user_overrides', '')
                ->orWhere('user_overrides', '[]')
                ->orWhere('user_overrides', '{}')
                ->orWhere('user_overrides', 'null');
        });
    }

    /**
     * config 에 없는 stale 코어 권한 삭제 (user_overrides 보존).
     *
     * @param  UpgradeContext  $context
     * @param  list<string>  $expected  config 에 정의된 식별자
     * @return void
     */
    private function deleteStaleCorePermissionsLocal(UpgradeContext $context, array $expected): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }
        $hasOverrides = Schema::hasColumn('permissions', 'user_overrides');

        $query = DB::table('permissions')
            ->where('extension_type', 'core')
            ->where('extension_identifier', 'core')
            ->whereNotIn('identifier', $expected);
        $this->whereUserOverridesEmptyLocal($query, $hasOverrides);

        $targets = $query->get(['id', 'identifier']);
        foreach ($targets as $perm) {
            if (Schema::hasTable('role_permissions')) {
                DB::table('role_permissions')->where('permission_id', $perm->id)->delete();
            }
            DB::table('permissions')->where('id', $perm->id)->delete();
            $context->logger->info('[beta.2] stale 코어 권한 삭제', ['identifier' => $perm->identifier]);
        }
        if ($targets->count() > 0) {
            $context->logger->info('[beta.2] stale 코어 권한 삭제 완료', ['count' => $targets->count()]);
        }
    }

    /**
     * config 에 없는 stale 코어 메뉴 삭제 (user_overrides 보존).
     *
     * @param  UpgradeContext  $context
     * @param  list<string>  $expected  config 에 정의된 slug
     * @return void
     */
    private function deleteStaleCoreMenusLocal(UpgradeContext $context, array $expected): void
    {
        if (! Schema::hasTable('menus')) {
            return;
        }
        $hasOverrides = Schema::hasColumn('menus', 'user_overrides');

        $query = DB::table('menus')
            ->where('extension_type', 'core')
            ->where('extension_identifier', 'core')
            ->whereNotIn('slug', $expected);
        $this->whereUserOverridesEmptyLocal($query, $hasOverrides);

        $targets = $query->get(['id', 'slug']);
        foreach ($targets as $menu) {
            if (Schema::hasTable('role_menus')) {
                DB::table('role_menus')->where('menu_id', $menu->id)->delete();
            }
            // 자식 메뉴는 cascade 대신 명시 삭제 (parent_id=자기)
            DB::table('menus')->where('parent_id', $menu->id)->delete();
            DB::table('menus')->where('id', $menu->id)->delete();
            $context->logger->info('[beta.2] stale 코어 메뉴 삭제', ['slug' => $menu->slug]);
        }
        if ($targets->count() > 0) {
            $context->logger->info('[beta.2] stale 코어 메뉴 삭제 완료', ['count' => $targets->count()]);
        }
    }

    /**
     * config 에 없는 stale 코어 역할 삭제 (user_overrides 보존 + user_roles 참조 차단).
     *
     * @param  UpgradeContext  $context
     * @param  list<string>  $expected  config 에 정의된 역할 식별자
     * @return void
     */
    private function deleteStaleCoreRolesLocal(UpgradeContext $context, array $expected): void
    {
        if (! Schema::hasTable('roles')) {
            return;
        }
        $hasOverrides = Schema::hasColumn('roles', 'user_overrides');

        $query = DB::table('roles')
            ->where('extension_type', 'core')
            ->where('extension_identifier', 'core')
            ->whereNotIn('identifier', $expected);
        $this->whereUserOverridesEmptyLocal($query, $hasOverrides);

        $targets = $query->get(['id', 'identifier']);
        foreach ($targets as $role) {
            // user_roles 참조 검사 — 참조 있으면 삭제 차단 + 경고
            if (Schema::hasTable('user_roles')) {
                $userCount = DB::table('user_roles')->where('role_id', $role->id)->count();
                if ($userCount > 0) {
                    $context->logger->warning('[beta.2] stale 코어 역할 삭제 차단 — 참조 사용자 존재', [
                        'identifier' => $role->identifier,
                        'id' => $role->id,
                        'user_count' => $userCount,
                        'remediation' => '사용자를 다른 역할로 재배정 후 수동 삭제 필요',
                    ]);

                    continue;
                }
            }
            if (Schema::hasTable('role_permissions')) {
                DB::table('role_permissions')->where('role_id', $role->id)->delete();
            }
            if (Schema::hasTable('role_menus')) {
                DB::table('role_menus')->where('role_id', $role->id)->delete();
            }
            DB::table('roles')->where('id', $role->id)->delete();
            $context->logger->info('[beta.2] stale 코어 역할 삭제', ['identifier' => $role->identifier]);
        }
    }

    /**
     * user role 에 코어 사용자 알림 권한(core.user-notifications.*) 매핑을 보장합니다 (beta.1 → beta.2 1회성).
     *
     * 운영 환경 관측: beta.2 에서 신규 추가된 `core.user-notifications.*` (type=user) 3종이
     * user 역할에 매핑되지 않아 사용자 권한 UI 코어 카테고리가 완전히 비어있는 문제 발생.
     *
     * spawn 이 성공하면 `syncCoreRolesAndPermissions` 의 매핑 로직이 자동 처리하지만,
     * proc_open 차단 환경에서도 최소한의 사용자 알림 권한은 복구되어야 하므로 본 메서드를
     * **spawn 성공/실패 무관** 하게 호출한다. `insertOrIgnore` 로 idempotent.
     *
     * 규율: 로컬 private + DB::table 직접 조작 (helper 우회). beta.1 메모리 제약 회피.
     *
     * @param  UpgradeContext  $context
     * @return void
     */
    private function ensureUserRoleCoreNotificationsLocal(UpgradeContext $context): void
    {
        if (! Schema::hasTable('role_permissions') || ! Schema::hasTable('roles') || ! Schema::hasTable('permissions')) {
            $context->logger->info('[beta.2] roles/permissions/role_permissions 테이블 부재 — user role 보강 스킵');

            return;
        }

        $role = DB::table('roles')->where('identifier', 'user')->first();
        if (! $role) {
            $context->logger->warning('[beta.2] user 역할 미존재 — 보강 스킵 (수동 확인 필요)');

            return;
        }

        $requiredPerms = [
            'core.user-notifications.read',
            'core.user-notifications.update',
            'core.user-notifications.delete',
        ];

        $attached = 0;
        $missingPerms = [];
        foreach ($requiredPerms as $identifier) {
            $perm = DB::table('permissions')->where('identifier', $identifier)->first();
            if (! $perm) {
                $missingPerms[] = $identifier;

                continue;
            }

            $result = DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $role->id,
                'permission_id' => $perm->id,
                'granted_at' => now(),
            ]);
            if ($result) {
                $attached++;
            }
        }

        if (! empty($missingPerms)) {
            $context->logger->warning('[beta.2] user role 보강 — 일부 권한 DB 미존재', [
                'missing' => $missingPerms,
                'note' => 'spawn 결과 또는 다음 업그레이드에서 생성됩니다',
            ]);
        }

        $context->logger->info('[beta.2] user role core.user-notifications.* 매핑 확인', [
            'newly_attached' => $attached,
            'required_count' => count($requiredPerms),
        ]);
    }

    /**
     * 인스톨러 SSoT 기준 경로 전체의 소유권을 원본으로 복원합니다 (beta.1 → beta.2 1회성).
     *
     * beta.1 의 `CoreUpdateService::runComposerInstall(base_path())` 및 기타 외부 프로세스
     * (composer·artisan 등) 가 sudo 환경에서 `vendor/`·`bootstrap/cache/` 등을 root 로
     * 재생성하는 문제가 있으며, beta.1 코드는 이미 배포되어 변경 불가이므로 beta.2 upgrade
     * step 에서 1회성으로 복원한다.
     *
     * 대상 경로는 인스톨러([`public/install/includes/config.php:REQUIRED_DIRECTORIES`]) 와
     * 일치시켜 SSoT 로 수렴한다. `vendor/` 단독이 아니라 `bootstrap/cache`·`storage`·
     * `modules/_pending` 등 모든 인스톨러 안내 경로를 포함.
     *
     * ⚠ 경로 C 규율 — 본 메서드는 **beta.1 이 in-memory 로 실행되는 환경** 에서 동작한다.
     * 따라서 `config()` 로 읽은 `restore_ownership` 은 beta.1 캐시값(`vendor,bootstrap/cache`)
     * 만 반환하므로 **로컬 상수 배열** 을 사용한다. `FilePermissionHelper::chownRecursive()`
     * 등 beta.2 신규 메서드도 호출 금지 (beta.1 메모리 구 클래스라 "undefined method" Fatal).
     *
     * beta.2 이후 버전은 `CoreUpdateService::snapshotOwnership()` + `restoreOwnership()`
     * 이 Step 11 Cleanup 에서 자동 수행하므로 본 메서드는 beta.3+ 에서 제거된다.
     *
     * 멱등: 이미 기준 소유자와 일치하는 경로는 개별 스킵. chown 미지원 환경은 전체 no-op.
     *
     * @param  UpgradeContext  $context
     * @return void
     */
    private function restoreAllOwnershipLocal(UpgradeContext $context): void
    {
        if (! function_exists('chown')) {
            return;
        }

        [$expectedOwner, $expectedGroup, $source] = $this->inferWebServerOwnershipLocal();

        if ($expectedOwner === false) {
            $context->logger->warning('[beta.2] 원본 소유자 판정 불가 — 소유권 복원 스킵');

            return;
        }

        $context->logger->info(
            "[beta.2] 소유권 복원 진입 (추정 원본 uid={$expectedOwner}, 출처={$source})"
        );

        // 경로 C: config() 호출 금지 (beta.1 캐시값). 인스톨러 SSoT 경로 하드코딩.
        $targets = self::INSTALLER_SSOT_PATHS;

        $totalRestored = 0;
        foreach ($targets as $target) {
            $path = base_path($target);
            if (! File::isDirectory($path)) {
                continue;
            }

            // 주의: 최상위 디렉토리 소유자가 일치해도 내부 파일 중 일부만 오염된 경우가 있다.
            // 예) beta.1 Step 11 clearAllCaches 가 `bootstrap/cache/autoload-extensions.php` 를
            // root 로 재생성하지만 `bootstrap/cache` 디렉토리 자체는 원본 소유자 유지.
            // 따라서 무조건 재귀 진입하여 파일 단위로 판정한다.
            // chownRecursiveLocal 내부에서 소유자 일치 파일은 chown 을 건너뛰므로 비용은 최소.
            $restored = $this->chownRecursiveLocal($path, $expectedOwner, $expectedGroup);
            if ($restored > 0) {
                $context->logger->info("[beta.2] {$target} 소유권 복원 — {$restored}건 변경");
            }
            $totalRestored += $restored;
        }

        $context->logger->info("[beta.2] 소유권 복원 완료 — 총 {$totalRestored}건 변경");
    }

    /**
     * 웹서버 쓰기 디렉토리 탐색으로 원본 소유자를 추정합니다. (로컬 전용 — 클래스 캐싱 회피)
     *
     * 로직은 `FilePermissionHelper::inferWebServerOwnership()` 과 동일하나, 본 메서드는
     * beta.1 메모리의 구 클래스를 우회하기 위해 로컬 구현을 유지한다.
     * beta.3 에서 본 upgrade step 전체가 제거되며 공용 Helper 만 남는다.
     *
     * @return array{0: int|false, 1: int|false, 2: string}  [owner, group, source]
     */
    private function inferWebServerOwnershipLocal(): array
    {
        $baseOwner = @fileowner(base_path());
        $baseGroup = @filegroup(base_path());

        if ($baseOwner === false) {
            return [false, false, 'none'];
        }

        $candidates = [
            'storage/logs',
            'storage/framework/views',
            'storage/framework/cache',
            'storage/app',
            'storage',
            'bootstrap/cache',
        ];

        foreach ($candidates as $candidate) {
            $path = base_path($candidate);
            if (! File::isDirectory($path)) {
                continue;
            }

            $owner = @fileowner($path);
            if ($owner !== false && $owner !== $baseOwner) {
                return [$owner, @filegroup($path), $candidate];
            }
        }

        return [$baseOwner, $baseGroup, 'base_path (대칭 구성)'];
    }

    /**
     * 경로와 하위 항목의 소유자를 재귀적으로 복원합니다. (로컬 전용 — 클래스 캐싱 회피)
     *
     * 로직은 `FilePermissionHelper::chownRecursive()` 과 동일하나, beta.1 메모리 구
     * 클래스 우회를 위해 로컬 구현을 유지한다.
     *
     * @param string $path 대상 경로
     * @param int $owner 기준 소유자 UID
     * @param int|false $group 기준 그룹 GID
     * @return int 변경 건수
     */
    private function chownRecursiveLocal(string $path, int $owner, int|false $group): int
    {
        $changed = 0;

        $currentOwner = @fileowner($path);
        if ($currentOwner !== false && $currentOwner !== $owner) {
            if (@chown($path, $owner)) {
                $changed++;
            }
            if ($group !== false && function_exists('chgrp')) {
                @chgrp($path, $group);
            }
        }

        if (! is_dir($path) || is_link($path)) {
            return $changed;
        }

        $items = new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS);
        foreach ($items as $item) {
            $changed += $this->chownRecursiveLocal($item->getPathname(), $owner, $group);
        }

        return $changed;
    }

    /**
     * user role에 잘못 바인딩된 core.notifications.*(admin) 매핑을 제거합니다.
     *
     * 정상 시드는 user role에 core.user-notifications.*(user)을 바인딩하므로,
     * admin 타입 권한이 user role에 남아 있으면 사용자 라우트가 통과되지 않습니다.
     * (PermissionMiddleware는 type까지 매칭하기 때문)
     *
     * 본 정리 스텝은 이미 buggy 시드가 실행된 dev/staging 환경에서만 영향이 있으며,
     * 신규 설치에서는 no-op 입니다.
     *
     * @param UpgradeContext $context 업그레이드 컨텍스트
     * @return void
     */
    private function cleanupUserNotificationPermissions(UpgradeContext $context): void
    {
        $userRoleId = DB::table('roles')->where('identifier', 'user')->value('id');
        if (! $userRoleId) {
            return;
        }

        $strayPermissions = DB::table('permissions')
            ->whereIn('identifier', [
                'core.notifications.read',
                'core.notifications.update',
                'core.notifications.delete',
            ])
            ->where('type', 'admin')
            ->pluck('id');

        if ($strayPermissions->isEmpty()) {
            return;
        }

        $removed = DB::table('role_permissions')
            ->where('role_id', $userRoleId)
            ->whereIn('permission_id', $strayPermissions)
            ->delete();

        if ($removed > 0) {
            $context->logger->info(
                "[beta.2] user role에 잘못 바인딩된 core.notifications.*(admin) 매핑 {$removed}건 제거 — 정상 시드는 core.user-notifications.*(user) 사용"
            );
        }
    }

    /**
     * mail_templates → notification_templates 데이터를 이관합니다.
     *
     * 기존 사용자 수정 데이터(user_overrides)를 보존합니다.
     *
     * @param UpgradeContext $context
     * @return void
     */
    private function migrateMailTemplates(UpgradeContext $context): void
    {
        $templates = DB::table('mail_templates')->get();
        $migratedCount = 0;

        foreach ($templates as $template) {
            $definition = NotificationDefinition::where('type', $template->type)->first();
            if (! $definition) {
                $context->logger->warning("[beta.2] 알림 정의 미발견 — type: {$template->type}, 이관 스킵");

                continue;
            }

            // mail_templates.variables → notification_definitions.variables fallback 매핑.
            // 시더가 채운 값이 있으면 보존, 비어있는 경우에만 운영 데이터로 보강.
            if ($template->variables) {
                $existingVariables = $definition->variables;
                $isEmpty = empty($existingVariables) || $existingVariables === '[]' || $existingVariables === [];
                if ($isEmpty) {
                    $definition->variables = json_decode($template->variables, true) ?? [];
                    $definition->save();
                }
            }

            NotificationTemplate::updateOrCreate(
                ['definition_id' => $definition->id, 'channel' => 'mail'],
                [
                    'subject' => json_decode($template->subject, true) ?? [],
                    'body' => json_decode($template->body, true) ?? [],
                    'click_url' => null,
                    'recipients' => null,
                    'is_active' => $template->is_active,
                    'is_default' => $template->is_default,
                    'user_overrides' => $template->user_overrides ? json_decode($template->user_overrides, true) : null,
                    'updated_by' => $template->updated_by,
                ]
            );

            $migratedCount++;
        }

        $context->logger->info("[beta.2] 코어 메일 템플릿 {$migratedCount}건 이관 완료");
    }

    /**
     * activity_logs.description_key 의 mail_template_* 키를 notification_template_* 로 변환합니다.
     *
     * 멱등성: 두 번째 실행 시 매칭 0건 (안전).
     *
     * @param UpgradeContext $context
     * @return void
     */
    private function migrateActivityLogDescriptionKeys(UpgradeContext $context): void
    {
        if (! Schema::hasTable('activity_logs')) {
            return;
        }

        $keyMap = [
            'mail_template_update' => 'notification_template_update',
            'mail_template_toggle_active' => 'notification_template_toggle_active',
        ];

        $totalConverted = 0;
        foreach ($keyMap as $oldKey => $newKey) {
            $count = DB::table('activity_logs')
                ->where('description_key', $oldKey)
                ->update(['description_key' => $newKey]);
            $totalConverted += $count;
        }

        if ($totalConverted > 0) {
            $context->logger->info("[beta.2] activity_logs.description_key {$totalConverted}건 변환 완료");
        }
    }

    /**
     * mail_send_logs → notification_logs 데이터를 이관합니다.
     *
     * @param UpgradeContext $context
     * @return void
     */
    private function migrateMailSendLogs(UpgradeContext $context): void
    {
        $total = DB::table('mail_send_logs')->count();
        if ($total === 0) {
            $context->logger->info('[beta.2] 이관할 메일 발송 이력 없음');

            return;
        }

        // 이미 이관된 데이터가 있으면 스킵 (재실행 방어)
        $alreadyMigrated = DB::table('notification_logs')
            ->where('source', 'migrated_from_mail_send_logs')
            ->exists();
        if ($alreadyMigrated) {
            $context->logger->info('[beta.2] 메일 발송 이력 이미 이관됨 — 스킵');

            return;
        }

        $migratedCount = 0;

        DB::table('mail_send_logs')->orderBy('id')->chunk(500, function ($logs) use (&$migratedCount) {
            $rows = [];
            foreach ($logs as $log) {
                $rows[] = [
                    'channel' => 'mail',
                    'notification_type' => $log->template_type ?? '',
                    'extension_type' => $log->extension_type ?? 'core',
                    'extension_identifier' => $log->extension_identifier ?? 'core',
                    'recipient_user_id' => null,
                    'recipient_identifier' => $log->recipient_email ?? '',
                    'recipient_name' => $log->recipient_name,
                    'sender_user_id' => null,
                    'subject' => $log->subject,
                    'body' => $log->body,
                    'status' => $log->status ?? 'sent',
                    'error_message' => $log->error_message,
                    'source' => 'migrated_from_mail_send_logs',
                    'sent_at' => $log->sent_at,
                    'created_at' => $log->created_at,
                    'updated_at' => $log->updated_at,
                ];
            }

            DB::table('notification_logs')->insert($rows);
            $migratedCount += count($rows);
        });

        $context->logger->info("[beta.2] 메일 발송 이력 {$migratedCount}건 이관 완료");
    }

    /**
     * 권한 식별자를 mail-send-logs → notification-logs로 전환합니다.
     *
     * @param UpgradeContext $context
     * @return void
     */
    private function migratePermissions(UpgradeContext $context): void
    {
        $mapping = [
            'core.mail-send-logs.read' => 'core.notification-logs.read',
            'core.mail-send-logs.delete' => 'core.notification-logs.delete',
        ];

        $migrated = 0;
        foreach ($mapping as $old => $new) {
            $newExists = DB::table('permissions')->where('identifier', $new)->exists();
            $oldExists = DB::table('permissions')->where('identifier', $old)->exists();

            if ($newExists && $oldExists) {
                // 신규 권한이 이미 존재하면 구 권한의 역할 매핑만 이관 후 구 권한 삭제
                $oldPermission = DB::table('permissions')->where('identifier', $old)->first();
                $newPermission = DB::table('permissions')->where('identifier', $new)->first();

                if ($oldPermission && $newPermission && Schema::hasTable('role_permissions')) {
                    $oldRoles = DB::table('role_permissions')->where('permission_id', $oldPermission->id)->pluck('role_id');
                    foreach ($oldRoles as $roleId) {
                        DB::table('role_permissions')->insertOrIgnore([
                            'role_id' => $roleId,
                            'permission_id' => $newPermission->id,
                        ]);
                    }
                    DB::table('role_permissions')->where('permission_id', $oldPermission->id)->delete();
                    DB::table('permissions')->where('id', $oldPermission->id)->delete();
                }
                $migrated++;
            } elseif ($oldExists && ! $newExists) {
                // 구 권한만 존재하면 식별자 변경
                DB::table('permissions')
                    ->where('identifier', $old)
                    ->update(['identifier' => $new]);
                $migrated++;
            }
        }

        // 부모 카테고리 정리: leaf 권한이 모두 새 식별자로 이관된 후 빈 부모 카테고리(core.mail-send-logs)도 삭제
        // (자식 권한이 모두 사라진 카테고리만 안전하게 삭제 — 사용자 정의 권한이 남아있으면 보존)
        $parentCategory = DB::table('permissions')
            ->where('identifier', 'core.mail-send-logs')
            ->first();

        if ($parentCategory) {
            $childCount = DB::table('permissions')
                ->where('parent_id', $parentCategory->id)
                ->count();

            if ($childCount === 0) {
                if (Schema::hasTable('role_permissions')) {
                    DB::table('role_permissions')->where('permission_id', $parentCategory->id)->delete();
                }
                DB::table('permissions')->where('id', $parentCategory->id)->delete();
                $context->logger->info('[beta.2] 빈 부모 카테고리 core.mail-send-logs 삭제');
            } else {
                $context->logger->warning(
                    "[beta.2] core.mail-send-logs 카테고리에 자식 권한 {$childCount}건 잔존 — 카테고리 삭제 스킵"
                );
            }
        }

        if ($migrated > 0) {
            $context->logger->info("[beta.2] 권한 매핑 전환: {$migrated}건");
        }
    }

    /**
     * 기존 메일 템플릿 캐시 + 공통 캐시 시스템 도입 이전의 레거시 캐시 키를 무효화합니다.
     *
     * beta.2 에서 공통 CacheInterface 로 이관되며 캐시 키 접두사가 통일되었습니다
     * (`g7:core:*`). 이전 버전에서 생성된 레거시 키들은 TTL 만료까지 남아있어
     * 서버 메모리/디스크를 점유하므로 업그레이드 시점에 일괄 삭제합니다.
     *
     * @return void
     */
    private function invalidateCaches(): void
    {
        // 1. pre-beta.2 메일 템플릿 캐시 (기존 로직 유지)
        $mailTypes = ['welcome', 'reset_password', 'password_changed'];
        foreach ($mailTypes as $type) {
            Cache::forget('mail_template:core:' . $type);
        }

        // 2. 공통 캐시 이관 이전의 레거시 키 제거 (고아 방지)
        //    새 시스템의 키는 `g7:core:*` 접두사를 사용하므로 이전 키들은
        //    TTL 만료까지 불필요하게 캐시 저장소를 점유함.
        $legacyKeys = [
            // SettingsService
            'system_settings',
            // ClearsTemplateCaches / InvalidatesLayoutCache
            'extension_cache_version',
            // ExtensionCompatibilityAlertListener
            'extension_compatibility_alerts',
            // SeoCacheManager / SeoConfigMerger / Sitemap
            'seo:cached_urls',
            'seo:sitemap',
            // NotificationDefinitionService
            'notification_definition:all_active',
        ];

        foreach ($legacyKeys as $key) {
            Cache::forget($key);
        }

        // 3. 확장 상태 캐시(CacheService::key() 패턴)
        //    'g7:{group}:{key}' 형식이었으나 새 시스템은 `g7:core:ext.*`
        $legacyExtensionKeys = [
            'g7:modules:active_identifiers',
            'g7:modules:installed_identifiers',
            'g7:plugins:active_identifiers',
            'g7:plugins:installed_identifiers',
            'g7:templates:active_identifiers',
            'g7:templates:active_identifiers_admin',
            'g7:templates:active_identifiers_user',
            'g7:templates:installed_identifiers',
        ];

        foreach ($legacyExtensionKeys as $key) {
            Cache::forget($key);
        }
    }

    /**
     * PHP 프로세스 종료 시점에 최종 ownership 복원을 수행하는 shutdown handler 를 등록합니다.
     *
     * 배경 — 경로 C (beta.1 메모리) 한계:
     *  - beta.1 `CoreUpdateCommand::handle()` Step 11 `clearAllCaches()` 가 본 upgrade step
     *    반환 이후에 실행된다. 그 내부에서 `Artisan::call('package:discover')` +
     *    `Artisan::call('extension:update-autoload')` 가 sudo 컨텍스트로 돌아 `bootstrap/cache/`
     *    내부 파일(services.php, packages.php, autoload-extensions.php) 을 root 로 재생성한다.
     *  - beta.1 에는 `restoreOwnership` 자체가 없어 이 재오염을 복원할 in-process 경로가 없다.
     *  - Upgrade step 은 Step 10 에서 실행되어 Step 11 보다 먼저 끝나므로 upgrade step 안에서
     *    Step 11 이후 상태를 직접 제어할 수 없다.
     *
     * 해법 — PHP 내장 shutdown handler:
     *  - `register_shutdown_function` 은 PHP 스크립트가 반환 또는 exit 된 후 실행된다.
     *    즉 beta.1 Step 11 clearAllCaches 까지 완료된 시점. sudo 컨텍스트는 프로세스 종료
     *    전까지 유지되므로 여기서 chown 가능.
     *  - callback 내부에서 proc_open 으로 새 PHP 프로세스 spawn → Laravel 앱 부팅 →
     *    beta.2 최신 `CoreUpdateService::restoreOwnership([])` 호출.
     *  - 결과는 `storage/logs/beta2-shutdown-ownership.log` 에 append. 표준 출력은 shutdown
     *    시점에 사용자에게 보이지 않을 수 있어 파일 로그만 신뢰.
     *
     * @param  UpgradeContext  $context
     */
    private function registerFinalOwnershipRestoreHandler(UpgradeContext $context): void
    {
        if (! function_exists('register_shutdown_function') || ! function_exists('proc_open')) {
            $context->logger->info('[beta.2] shutdown handler 미등록 — register_shutdown_function / proc_open 비활성');

            return;
        }

        $basePath = base_path();

        register_shutdown_function(function () use ($basePath) {
            try {
                $phpCode = <<<'PHP'
$base = getenv('G7_BASE_PATH');
chdir($base);
require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
app(App\Services\CoreUpdateService::class)->restoreOwnership([]);
echo "BETA2_SHUTDOWN_OWNERSHIP_OK\n";
PHP;

                $cmd = escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($phpCode).' 2>&1';
                // shutdown 단계는 Step 11 이후라 .env APP_VERSION 은 이미 toVersion. 하지만
                // G7_UPDATE_IN_PROGRESS 플래그는 부모가 handle() 중 세운 것이므로 shutdown 콜백
                // 실행 시점에도 여전히 부모 프로세스에 살아있다. 자식 boot 의 validateAndDeactivate
                // 가 확실히 skip 되도록 getenv() 기반으로 전파.
                $env = array_merge(getenv(), $_ENV, [
                    'G7_BASE_PATH' => $basePath,
                    'G7_UPDATE_IN_PROGRESS' => '1',
                ]);
                $descriptors = [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ];

                $process = @proc_open($cmd, $descriptors, $pipes, $basePath, $env);
                if (! is_resource($process)) {
                    return;
                }

                @fclose($pipes[0]);
                $stdout = @stream_get_contents($pipes[1]);
                @fclose($pipes[1]);
                @fclose($pipes[2]);
                $exit = @proc_close($process);

                $logPath = $basePath.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR
                    .'logs'.DIRECTORY_SEPARATOR.'beta2-shutdown-ownership.log';
                @file_put_contents(
                    $logPath,
                    '['.date('Y-m-d H:i:s')."] exit={$exit} ".trim((string) $stdout)."\n",
                    FILE_APPEND
                );
            } catch (\Throwable) {
                // shutdown 중 예외는 조용히 무시 (프로세스 이미 종료 중)
            }
        });

        $context->logger->info('[beta.2] PHP 종료 시점 ownership 복원 핸들러 등록 완료');
    }
}
