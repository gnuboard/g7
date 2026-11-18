<?php

namespace App\Upgrades\Data\V7_2_0\Migrations;

use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\Support\LifecycleWarnings;
use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;

/**
 * 활성 모듈·플러그인의 선언형 산출물(7.2.0 부터 스케줄 포함)을 새 코드로 재동기화합니다.
 *
 * 7.1.x 까지 확장 예약은 `routes/console.php` 가 매 틱 `getSchedules()` 를 읽어 등록했다. 7.2.0 은 그 등록을 지우고
 * 스케줄 등록부(`schedules` 표)만 실행하므로, 확장 선언이 등록부에 동기화되어 있어야 돈다. 동기화는 확장 활성화·갱신
 * 때 일어나는데, 코어만 올리고 확장은 갱신하지 않은 사이트(또는 번들 갱신을 건너뛴 업데이트)는 그 계기가 없다 — 그러면
 * 업데이트 직후부터 입금 기한 주문 취소·마일리지 소멸·환율 갱신 같은 확장 예약이 조용히 멈춘다.
 *
 * 이 스텝은 모듈·플러그인 매니저의 `resyncAllActiveDeclarativeArtifacts()`(활성 확장 전수의 선언형 산출물 비파괴
 * 동기화 — 7.2.0 부터 스케줄 포함)를 부른다. 옛 형식 선언(`schedule`·`enabled_config`)도 동기화 헬퍼가 정규화한다.
 * 운영자 수정(user_overrides)은 보존되고, 이미 동기화된 사이트에서는 바꿀 것이 없다(멱등). 비활성 확장은 대상이 아니다
 * (활성화 때 동기화된다).
 *
 * 재동기화가 읽는 선언은 새 판 `_bundled` 의 것이지만 확장 클래스는 아직 7.1.x 활성 디렉토리에서 오토로드된다. 그래서
 * 새 판에만 있는 확장 클래스를 가리키는 항목 선언(`field_definitions` — 게시판 `GuestAuthorFields`, 쇼핑몰
 * `OrderContactFields`)은 이 구간에서 「Class … not found」로 실패한다. 그 실패는 경고 표에서 빼고 로그로만 남긴다 —
 * 이어지는 확장 갱신이 같은 선언을 새 클래스로 다시 동기화한다(7.1.6 → 7.2.0 실측: 오경보 2건 뒤 갱신이 정상으로 채움).
 *
 * V-1 안전 예외(스텝 00 과 같은 사유): 새 코드의 동기화를 부르는 것이 이 스텝의 목적이라 매니저를 직접 부른다. 메서드가
 * 없는 판에서는 건너뛰고 복구 방법을 경고로 남긴다. 실패도 예외로 올리지 않는다.
 */
class SyncActiveExtensionSchedules implements DataMigration
{
    /** 운영자 복구 안내 (확장 선언 재동기화 — 확장 갱신과 같은 효과) */
    private const RECOVERY_HINT = 'php artisan core:execute-upgrade-steps --from=7.2.0 --to=7.2.0 --force --skip-migrations --skip-bundled-updates (또는 각 확장 module:update / plugin:update)';

    /**
     * @param  string|null  $basePath  설치 루트 (null 이면 base_path() — 테스트가 임시 루트를 주입한다)
     */
    public function __construct(
        private readonly ?string $basePath = null,
    ) {}

    /**
     * 마이그레이션 식별자를 반환합니다.
     *
     * @return string 마이그레이션 이름
     */
    public function name(): string
    {
        return 'SyncActiveExtensionSchedules';
    }

    /**
     * 활성 모듈·플러그인의 선언형 산출물을 재동기화합니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return void 반환값 없음
     */
    public function run(UpgradeContext $context): void
    {
        // audit:allow upgrade-step-vone-safety reason: 새 코드의 확장 선언 동기화(스케줄 포함)를 부르는 것이 이 스텝의 목적 — method_exists 가드
        // audit:allow upgrade-step-data-snapshot reason: 위와 같음
        $moduleManager = app(ModuleManager::class);
        // audit:allow upgrade-step-vone-safety reason: 위와 같음 (플러그인 매니저)
        // audit:allow upgrade-step-data-snapshot reason: 위와 같음
        $pluginManager = app(PluginManager::class);
        $managers = ['module' => $moduleManager, 'plugin' => $pluginManager];

        foreach ($managers as $kind => $manager) {
            if (! method_exists($manager, 'resyncAllActiveDeclarativeArtifacts')) {
                $context->logger->warning("[7.2.0] 활성 {$kind} 선언 재동기화 건너뜀 — 재동기화 메서드 없음. 업데이트 뒤 실행: ".self::RECOVERY_HINT);

                continue;
            }

            $warnings = $this->warningCollector();
            $mark = $warnings?->mark();

            try {
                $result = $manager->resyncAllActiveDeclarativeArtifacts();
                $context->logger->info("[7.2.0] 활성 {$kind} 선언형 산출물(스케줄 포함) 재동기화 완료 (운영자 수정 보존, 멱등)", [
                    'result' => $result,
                ]);
            } catch (\Throwable $e) {
                $context->logger->warning("[7.2.0] 활성 {$kind} 선언 재동기화 실패 — 업데이트 뒤 실행: ".self::RECOVERY_HINT, [
                    'error' => $e->getMessage(),
                ]);
            }

            if ($warnings !== null && $mark !== null) {
                $this->deferPendingExtensionClassWarnings($warnings, $mark, $kind, $context);
            }
        }
    }

    /**
     * 생명주기 경고 수집기를 돌려줍니다 — 이 스텝이 쓰는 메서드(mark·since·all·clear·add)가 없는 판에서는 null.
     *
     * @return LifecycleWarnings|null 수집기 (쓸 수 없으면 null)
     */
    private function warningCollector(): ?LifecycleWarnings
    {
        if (! class_exists(LifecycleWarnings::class)) {
            return null;
        }

        foreach (['current', 'mark', 'since', 'all', 'clear', 'add'] as $method) {
            if (! method_exists(LifecycleWarnings::class, $method)) {
                return null;
            }
        }

        return LifecycleWarnings::current();
    }

    /**
     * 확장 갱신 전이라 아직 없는 확장 클래스 때문에 실패한 항목 선언 동기화 경고를 「확장 갱신이 이어서 한다」로 돌립니다.
     *
     * 재동기화는 새 판 `_bundled/{id}` 의 선언 파일을 읽지만, 그 선언이 가리키는 확장 클래스(예: 게시판
     * `Fields\GuestAuthorFields`)는 오토로드가 보는 활성 디렉토리(`{modules|plugins}/{id}/src`)에 아직 없다 — 7.1.x 에서
     * 올리는 업데이트는 코어 → 확장 순서라 반드시 이 구간을 지난다. 그 실패는 운영자가 고칠 일이 아니고 확장 갱신
     * (`updateModule`·`updatePlugin` → `syncDeclarativeArtifacts`)이 같은 선언을 새 클래스로 다시 동기화하므로, 경고 표에서
     * 빼고 로그에만 남긴다. 번들 갱신을 건너뛴 사이트에서도 활성 확장은 7.1.x 판이라 그 항목 집합을 쓰지 않는다.
     *
     * 판정: 단계가 `field_definitions` 이고, 사유가 `Class "…" not found` 이며, 그 클래스 파일이 새 판
     * `_bundled/{id}/src/…` 에는 있고 활성 디렉토리 `{id}/src/…` 에는 없을 때만. 그 밖의 경고는 그대로 둔다.
     *
     * @param  LifecycleWarnings  $warnings  수집기
     * @param  int  $mark  재동기화 직전 위치
     * @param  string  $kind  `module` 또는 `plugin`
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return void 반환값 없음
     */
    private function deferPendingExtensionClassWarnings(LifecycleWarnings $warnings, int $mark, string $kind, UpgradeContext $context): void
    {
        $kept = [];
        $deferred = [];

        foreach ($warnings->since($mark) as $item) {
            $class = $this->pendingExtensionClass($item, $kind);

            if ($class === null) {
                $kept[] = $item;

                continue;
            }

            $deferred[] = ['target' => (string) ($item['target'] ?? ''), 'class' => $class];
        }

        if ($deferred === []) {
            return;
        }

        $before = array_slice($warnings->all(), 0, $mark);
        $warnings->clear();

        foreach ([...$before, ...$kept] as $item) {
            $warnings->add(
                (string) $item['step'],
                (string) $item['target'],
                (string) $item['message'],
                (string) ($item['level'] ?? LifecycleWarnings::LEVEL_WARNING),
                isset($item['target_name']) ? (string) $item['target_name'] : null,
                isset($item['cause']) ? (string) $item['cause'] : null,
            );
        }

        $context->logger->info("[7.2.0] 활성 {$kind} 의 항목 선언 동기화는 확장 갱신이 이어서 한다 — 새 판 확장 클래스가 아직 활성 디렉토리에 없음", [
            'deferred' => $deferred,
        ]);
    }

    /**
     * 경고 항목이 「확장 갱신 전이라 아직 없는 확장 클래스」로 인한 항목 선언 동기화 실패면 그 클래스 이름을 돌려줍니다.
     *
     * @param  array<string, mixed>  $item  경고 항목
     * @param  string  $kind  `module` 또는 `plugin`
     * @return string|null 클래스 이름 (해당하지 않으면 null)
     */
    private function pendingExtensionClass(array $item, string $kind): ?string
    {
        $identifier = (string) ($item['target'] ?? '');

        if (($item['step'] ?? null) !== 'field_definitions' || ! preg_match('/^[a-z0-9][a-z0-9_-]*$/', $identifier)) {
            return null;
        }

        if (! preg_match('/Class "([A-Za-z0-9_\\\\]+)" not found/', (string) ($item['message'] ?? ''), $m)) {
            return null;
        }

        // 확장 클래스 이름공간은 `{Modules|Plugins}\{Vendor}\{Name}\…` 이고 `{Vendor}\{Name}\` 이 `src/` 에 대응한다.
        $segments = explode('\\', ltrim($m[1], '\\'));
        $root = $kind === 'plugin' ? 'Plugins' : 'Modules';

        if (count($segments) < 4 || $segments[0] !== $root) {
            return null;
        }

        $relative = 'src/'.implode('/', array_slice($segments, 3)).'.php';
        $directory = $kind === 'plugin' ? 'plugins' : 'modules';
        $base = rtrim($this->basePath ?? base_path(), '/\\');

        $inBundled = is_file("{$base}/{$directory}/_bundled/{$identifier}/{$relative}");
        $inActive = is_file("{$base}/{$directory}/{$identifier}/{$relative}");

        return $inBundled && ! $inActive ? $m[1] : null;
    }
}
