<?php

namespace Tests\Feature\Extension;

use App\Console\Commands\HooksCatalogCommand;
use App\Contracts\Extension\HookListenerInterface;
use App\Extension\HookContract\HookCatalogScanner;
use App\Extension\HookContract\HookContract;
use App\Extension\HookContract\HookContractGuard;
use App\Extension\HookManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * 공개 훅 계약 — 서드파티 확장이 모르고 깨지지 않게 하는 장치 (#138 후속, 설계 결정 2026-09-29)
 *
 * 공개 훅의 이름과 인자 개수는 서드파티가 코드에 박아 쓰는 계약이다. 이 테스트는
 *  - 발화 목록(data/catalog.php)이 소스와 같아 훅 추가·삭제·인자 변화가 리뷰에 드러나는지,
 *  - 이름을 풀 수 없는 새 동적 발화처가 목록(data/dynamic.php) 밖에 생기지 않았는지,
 *  - 코어·번들 리스너가 사용 중단·발화처 없는 훅을 구독하지 않는지,
 *  - 계약을 깨는 변화(삭제·인자 개수 감소)를 목록 생성기가 거부하는지,
 *  - 등록 시점 가드와 액션 옛 이름 병행 발화가 동작하는지
 * 를 잰다.
 */
class HookContractTest extends TestCase
{
    /**
     * 스캔 결과 (테스트 간 재사용).
     *
     * @var array{hooks: array<string, array<string, mixed>>, dynamic: array<int, array<string, mixed>>}|null
     */
    private static ?array $scan = null;

    /**
     * 소스를 한 번만 스캔합니다.
     *
     * @return array{hooks: array<string, array<string, mixed>>, dynamic: array<int, array<string, mixed>>} 스캔 결과
     */
    private function scan(): array
    {
        return self::$scan ??= (new HookCatalogScanner)->scan(base_path());
    }

    /**
     * 가드·계약 캐시를 비웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        HookContract::flush();
        HookContractGuard::flush();
        HookManager::resetAll();

        parent::tearDown();
    }

    /**
     * 발화 목록 파일이 소스 스캔 결과와 같다 — 훅을 더하거나 바꾸면 hooks:catalog 로 목록을 갱신해 리뷰에 드러낸다.
     *
     * @scenario case=catalog_snapshot
     *
     * @effects catalog_matches_source
     *
     * @return void 반환값 없음
     */
    public function test_catalog_file_matches_source(): void
    {
        $expected = HooksCatalogCommand::render($this->scan()['hooks']);
        $actual = str_replace("\r\n", "\n", (string) file_get_contents(HookContract::DATA_DIR.'/catalog.php'));

        $this->assertSame($expected, $actual, '훅 발화 목록이 소스와 다르다 — php artisan hooks:catalog 를 실행하고 변화를 확인하세요');

        // 사람이 읽는 목록 문서도 같은 원본에서 만든다 — 공개 훅 전부가 문서에 나온다
        $expectedDoc = HooksCatalogCommand::renderDoc(
            $this->scan()['hooks'],
            HookContract::deprecated(),
            require HookContract::DATA_DIR.'/dynamic.php',
        );
        $actualDoc = str_replace("\r\n", "\n", (string) file_get_contents(base_path(HooksCatalogCommand::DOC_PATH)));

        $this->assertSame($expectedDoc, $actualDoc, '훅 목록 문서(docs/extension/hook-catalog.md)가 최신이 아니다 — php artisan hooks:catalog');
    }

    /**
     * 이름을 풀 수 없는 동적 발화처는 모두 data/dynamic.php 에 패턴이 있고, 없어진 발화처는 목록에서도 지웠다.
     *
     * @scenario case=dynamic_sites
     *
     * @effects every_dynamic_site_has_patterns
     *
     * @return void 반환값 없음
     */
    public function test_every_dynamic_site_is_mapped(): void
    {
        $mapped = require HookContract::DATA_DIR.'/dynamic.php';
        $found = [];
        foreach ($this->scan()['dynamic'] as $site) {
            $found[$site['file'].'|'.$site['expr']] = true;
        }

        $this->assertSame([], array_values(array_diff(array_keys($found), array_keys($mapped))), '이름을 풀 수 없는 새 동적 발화처 — data/dynamic.php 에 이름 패턴을 적으세요');
        $this->assertSame([], array_values(array_diff(array_keys($mapped), array_keys($found))), '없어진 동적 발화처가 data/dynamic.php 에 남아 있다');
    }

    /**
     * 코어·번들 리스너는 사용 중단되었거나 발화처가 없는 훅을 구독하지 않는다.
     *
     * @scenario case=listener_subscriptions
     *
     * @effects bundled_listeners_subscribe_only_published_hooks
     *
     * @return void 반환값 없음
     */
    public function test_core_and_bundled_listeners_subscribe_only_published_hooks(): void
    {
        $problems = [];

        foreach ($this->listenerClasses() as $class) {
            foreach ($class::getSubscribedHooks() as $hook => $config) {
                $problem = HookContract::subscriptionProblem($hook);
                if ($problem !== null) {
                    $problems[] = "{$class} → {$hook} ({$problem['kind']}".($problem['replacement'] ? ", 대체: {$problem['replacement']}" : '').')';
                }
            }
        }

        $this->assertSame([], $problems);
    }

    /**
     * 목록 생성기는 deprecated 목록에 없는 훅 삭제·인자 개수 감소를 계약 위반으로 보고, 뒤에 인자를 더한 것은 허용한다.
     *
     * @scenario case=breaking_change_guard
     *
     * @effects removal_or_arg_loss_without_deprecation_is_rejected
     *
     * @return void 반환값 없음
     */
    public function test_breaking_changes_are_detected_unless_deprecated(): void
    {
        $previous = [
            'core.x.removed' => ['type' => 'action', 'args' => [1]],
            'core.x.narrowed' => ['type' => 'action', 'args' => [2]],
            'core.x.widened' => ['type' => 'action', 'args' => [1]],
            'core.x.retired' => ['type' => 'action', 'args' => [1]],
        ];
        $current = [
            'core.x.narrowed' => ['type' => 'action', 'args' => [1]],
            'core.x.widened' => ['type' => 'action', 'args' => [1, 2]],
        ];

        $breaks = HooksCatalogCommand::breakingChanges($previous, $current, ['core.x.retired' => ['replacement' => null]]);

        $this->assertCount(2, $breaks);
        $this->assertStringContainsString('core.x.removed', $breaks[0]);
        $this->assertStringContainsString('core.x.narrowed', $breaks[1]);
    }

    /**
     * 사용 중단된 훅이나 발화처 없는 코어 훅을 구독하면 경고가 남고, 서드파티 자기 이름공간·정상 훅은 조용하다.
     *
     * @scenario case=subscription_guard
     *
     * @effects deprecated_or_unpublished_subscription_is_warned
     *
     * @return void 반환값 없음
     */
    public function test_guard_warns_on_deprecated_and_unpublished_subscriptions(): void
    {
        Cache::flush();
        Log::spy();

        HookContractGuard::checkSubscription('Vendor\\Ext\\A', 'core.modules.installed');
        HookContractGuard::checkSubscription('Vendor\\Ext\\A', 'core.no_such.hook');
        HookContractGuard::checkSubscription('Vendor\\Ext\\A', 'core.modules.after_install');
        HookContractGuard::checkSubscription('Vendor\\Ext\\A', 'acme-shop.order.after_create');
        HookContractGuard::checkSubscription('Vendor\\Ext\\A', 'core.modules.installed'); // 같은 조합은 한 번만

        Log::shouldHaveReceived('warning')->twice();
        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context) => $context['hook'] === 'core.modules.installed'
            && $context['replacement'] === 'core.modules.after_install')->once();
        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context) => $context['hook'] === 'core.no_such.hook')->once();
    }

    /**
     * 액션 옛 이름 병행 발화 — 표준 이름은 늘 발화하고, 옛 이름은 구독자가 있을 때만 같은 인자로 발화한다.
     *
     * @scenario case=legacy_action_alias
     *
     * @effects legacy_action_name_fires_only_with_subscribers
     *
     * @return void 반환값 없음
     */
    public function test_do_action_with_legacy_name_fires_legacy_only_when_subscribed(): void
    {
        $calls = [];
        HookManager::addAction('core.demo.after_new', function ($a) use (&$calls) {
            $calls[] = ['new', $a];
        });

        HookManager::doActionWithLegacyName('core.demo.after_new', 'core.demo.old', 1);
        $this->assertSame([['new', 1]], $calls);

        HookManager::addAction('core.demo.old', function ($a) use (&$calls) {
            $calls[] = ['old', $a];
        });
        HookManager::doActionWithLegacyName('core.demo.after_new', 'core.demo.old', 2);

        $this->assertSame([['new', 1], ['new', 2], ['old', 2]], $calls);
    }

    /**
     * 코어·번들 확장의 정적 훅 리스너 클래스 목록.
     *
     * @return array<int, class-string<HookListenerInterface>> 리스너 FQCN 목록
     */
    private function listenerClasses(): array
    {
        $classes = [];
        $roots = [base_path('app/Listeners') => 'App\\Listeners'];
        foreach (glob(base_path('{modules,plugins}/_bundled/*/src/Listeners'), GLOB_BRACE | GLOB_ONLYDIR) ?: [] as $dir) {
            $roots[$dir] = null;
        }

        foreach ($roots as $root => $prefix) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $source = (string) file_get_contents($file->getPathname());
                if (! preg_match('/^namespace\s+([^;]+);/m', $source, $ns) || ! preg_match('/^(?:final\s+|abstract\s+)?class\s+(\w+)/m', $source, $cls)) {
                    continue;
                }
                $fqcn = $ns[1].'\\'.$cls[1];
                if (class_exists($fqcn) && is_subclass_of($fqcn, HookListenerInterface::class) && ! (new \ReflectionClass($fqcn))->isAbstract()) {
                    $classes[] = $fqcn;
                }
            }
        }

        sort($classes);

        return $classes;
    }
}
