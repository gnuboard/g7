<?php

namespace Tests\Feature\Dashboard;

use App\Extension\HookManager;
use ReflectionClass;
use Tests\TestCase;

/**
 * 코어 대시보드 알림 리스너가 **한 번만** 등록되는지 검증한다.
 *
 * 회귀 시나리오 (G8 실측 2026-09-02):
 *   - `AppServiceProvider::registerCoreHookListeners()` 가 코어 알림 리스너 4종을 명시 등록한다
 *   - 그런데 `HookCacheManager::collectCoreListeners()` 는 `app/Listeners/` 를 재귀 스캔해
 *     같은 리스너를 `bootstrap/cache/hooks.php` 에 실어 **또 등록**한다
 *   - `HookManager::applyFilters()` 는 중복을 걸러 내지 않으므로 필터가 두 번 적용된다
 *   - 결과: `GET /api/admin/dashboard/alerts` 가 같은 `id` 의 알림을 2건 돌려주고,
 *     대시보드에 **같은 경고 카드가 두 번** 뜬다 (실측: `schedule_not_running` 2건)
 *
 * 그 프로바이더의 주석은 「코어 리스너는 자동 발견 대상이 아니다」라고 적고 있었지만
 * 사실이 아니었다 — 등록 경로가 둘이라는 것이 결함의 전부다.
 *
 * 이 테스트는 **등록 횟수**를 잰다. 알림 본문을 재는 테스트는 발화 조건을 만들어야 해
 * 조건이 성립하지 않는 환경에서 조용히 통과한다.
 */
class CoreAlertListenerRegistrationTest extends TestCase
{
    private const HOOK = 'core.dashboard.alerts';

    /**
     * 이 훅을 구독하는 코어 리스너 수 == 등록된 콜백 수.
     *
     * 하나라도 두 경로로 등록되면 콜백 수가 리스너 수를 넘는다.
     */
    public function test_core_alert_listeners_are_registered_exactly_once(): void
    {
        $subscribers = $this->coreListenerClassesSubscribingToHook();

        $this->assertNotSame(
            [],
            $subscribers,
            'app/Listeners 에서 이 훅 구독자를 하나도 찾지 못했다 — 모집단이 비면 이 검사는 공허하다'
        );

        $registered = $this->registeredCallbackCount(self::HOOK);

        $this->assertSame(
            count($subscribers),
            $registered,
            sprintf(
                '훅 "%s" 에 등록된 콜백이 %d 개인데 구독 리스너는 %d 개다 — 리스너가 두 경로로 중복 등록되면 '
                .'같은 알림이 여러 번 뜬다 (구독자: %s)',
                self::HOOK,
                $registered,
                count($subscribers),
                implode(', ', array_map(static fn (string $c): string => class_basename($c), $subscribers))
            )
        );
    }

    /**
     * `app/Listeners/` 에서 이 훅을 구독하는 코어 리스너 클래스를 실측한다.
     *
     * 목록을 리터럴로 적지 않는다 — 리스너가 늘면 이 검사도 함께 커져야 한다.
     *
     * 하위 디렉토리까지 재귀로 본다. 등록 경로인 `HookCacheManager::collectCoreListeners()` 가
     * `app/Listeners/` 를 재귀 스캔하므로(`Analytics/AnalyticsAlertListener` 등), 모집단이 한
     * 겹이면 하위 폴더 리스너의 정상 등록 1회가 「중복」으로 잡힌다.
     *
     * @return list<class-string>
     */
    private function coreListenerClassesSubscribingToHook(): array
    {
        $found = [];
        $root = app_path('Listeners');
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($root) + 1, -4);
            $class = 'App\\Listeners\\'.str_replace(['/', '\\'], '\\', $relative);

            if (! class_exists($class) || ! method_exists($class, 'getSubscribedHooks')) {
                continue;
            }

            /** @var array<string, mixed> $hooks */
            $hooks = $class::getSubscribedHooks();

            if (array_key_exists(self::HOOK, $hooks)) {
                $found[] = $class;
            }
        }

        return $found;
    }

    /**
     * `HookManager` 에 등록된 필터 콜백 수 (우선순위 전체 합).
     */
    private function registeredCallbackCount(string $hook): int
    {
        $property = (new ReflectionClass(HookManager::class))->getProperty('filters');
        $property->setAccessible(true);

        /** @var array<string, array<int, list<callable>>> $filters */
        $filters = $property->getValue();

        $total = 0;

        foreach ($filters[$hook] ?? [] as $callbacks) {
            $total += count($callbacks);
        }

        return $total;
    }
}
