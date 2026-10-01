<?php

namespace Plugins\Sirsoft\Gdpr\Tests\Unit\Support;

use App\Extension\HookManager;
use Plugins\Sirsoft\Gdpr\Plugin;
use Plugins\Sirsoft\Gdpr\Support\NecessaryAllowlist;
use Plugins\Sirsoft\Gdpr\Tests\PluginTestCase;

/**
 * 확장 제공 필수 저장 키 계약 검증
 *
 * 이 플러그인은 어떤 확장이 있는지 모른다. 확장은 이 플러그인이 소유한 필터 훅에 자기 키를 덧붙이고,
 * 판정·인라인은 요청마다 그 훅에서 모은다(이커머스 결제수단 등록과 같은 pull 방식). 그래서
 *   - 리스너가 없으면 제공 집합은 비고(판정은 운영자 ∪ 잠금 그대로),
 *   - 리스너가 있으면 판정에 합쳐지며,
 *   - 확장이 돌려준 값 중 형식이 틀린 항목(특히 `*` 단독)은 버려야 한다 — 섞이면 게이트 전체가 열린다.
 * 모두 오류 없이 조용히 틀어지는 경로라 여기서 잠근다.
 *
 * @scenario scope=sessionStorage, notation=exact, locked=operator_item, settings_state=populated, request=valid_item
 *
 * @effects provided_keys_collected_from_filter_hook,
 *   provided_keys_merged_into_necessary_judgement,
 *   provided_keys_reject_invalid_items,
 *   provided_keys_exposed_in_inline_payload
 */
class ProvidedNecessaryStorageTest extends PluginTestCase
{
    /** @var array<int, array<int, callable>> 테스트 전에 이미 붙어 있던 필터 (우선순위 => 콜백) */
    private array $saved = [];

    /**
     * 이미 활성인 확장의 리스너를 잠시 떼어 이 테스트의 리스너만 보이게 합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->saved = HookManager::getFilters()[Plugin::PROVIDED_NECESSARY_STORAGE_HOOK] ?? [];
        HookManager::clearFilter(Plugin::PROVIDED_NECESSARY_STORAGE_HOOK);
        $this->forgetMemo();
    }

    /**
     * 테스트가 붙인 필터를 떼고 원래 필터와 요청 메모를 되돌립니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        HookManager::clearFilter(Plugin::PROVIDED_NECESSARY_STORAGE_HOOK);
        foreach ($this->saved as $priority => $callbacks) {
            foreach ($callbacks as $callback) {
                HookManager::addFilter(Plugin::PROVIDED_NECESSARY_STORAGE_HOOK, $callback, $priority);
            }
        }
        $this->forgetMemo();

        parent::tearDown();
    }

    /**
     * 리스너가 없으면 제공 집합은 세 스코프 모두 빈 배열입니다.
     *
     * @return void 반환값 없음
     *
     * @scenario scope=sessionStorage, notation=exact, locked=operator_item, settings_state=empty, request=valid_item
     *
     * @effects provided_keys_collected_from_filter_hook
     */
    public function test_provided_set_is_empty_without_listeners(): void
    {
        $this->assertSame(
            ['localStorage' => [], 'sessionStorage' => [], 'cookie' => []],
            Plugin::providedNecessaryStorage()
        );
    }

    /**
     * 여러 리스너가 덧붙인 키를 스코프별로 모으고 중복을 없앱니다.
     *
     * @return void 반환값 없음
     *
     * @scenario scope=sessionStorage, notation=exact, locked=operator_item, settings_state=populated, request=valid_item
     *
     * @effects provided_keys_collected_from_filter_hook
     */
    public function test_collects_keys_from_every_listener(): void
    {
        $this->provide(['sessionStorage' => ['ext_a.pending']]);
        $this->provide(['sessionStorage' => ['ext_a.pending', 'ext_b.stash'], 'localStorage' => ['ext_b_cart']]);

        $provided = Plugin::providedNecessaryStorage();

        $this->assertSame(['ext_a.pending', 'ext_b.stash'], $provided['sessionStorage']);
        $this->assertSame(['ext_b_cart'], $provided['localStorage']);
        $this->assertSame([], $provided['cookie']);
    }

    /**
     * 형식이 틀린 항목은 버리고 유효한 항목만 받습니다.
     *
     * @return void 반환값 없음
     *
     * @scenario scope=localStorage, notation=wildcard, locked=operator_item, settings_state=populated, request=invalid_format
     *
     * @effects provided_keys_reject_invalid_items
     */
    public function test_rejects_invalid_items(): void
    {
        $this->provide([
            'localStorage' => ['*', 'bad key', 'a*b', str_repeat('k', 129), 123, null, 'ok_prefix_*', 'ok.key'],
            'unknownScope' => ['ignored'],
        ]);

        $provided = Plugin::providedNecessaryStorage();

        $this->assertSame(['ok_prefix_*', 'ok.key'], $provided['localStorage']);
        $this->assertSame(['localStorage', 'sessionStorage', 'cookie'], array_keys($provided));
    }

    /**
     * 리스너가 배열이 아닌 값을 돌려주면 제공 집합은 빈 배열로 떨어집니다.
     *
     * @return void 반환값 없음
     *
     * @scenario scope=cookie, notation=exact, locked=operator_item, settings_state=unreachable, request=invalid_format
     *
     * @effects provided_keys_reject_invalid_items
     */
    public function test_non_array_filter_result_falls_back_to_empty(): void
    {
        $callback = static fn () => 'broken';
        HookManager::addFilter(Plugin::PROVIDED_NECESSARY_STORAGE_HOOK, $callback);

        $this->assertSame(
            ['localStorage' => [], 'sessionStorage' => [], 'cookie' => []],
            Plugin::providedNecessaryStorage()
        );
    }

    /**
     * 제공 키는 판정(운영자 ∪ 잠금 ∪ 제공)에 합쳐지고, 제공하지 않은 스코프로 새지 않습니다.
     *
     * @return void 반환값 없음
     *
     * @scenario scope=cookie, notation=wildcard, locked=operator_item, settings_state=populated, request=valid_item
     *
     * @effects provided_keys_merged_into_necessary_judgement
     */
    public function test_provided_keys_join_the_judgement(): void
    {
        $this->assertFalse(NecessaryAllowlist::matches('ext_consent_mode', 'cookie'));
        $this->forgetMemo();

        $this->provide(['cookie' => ['ext_consent_*']]);

        $this->assertTrue(NecessaryAllowlist::matches('ext_consent_mode', 'cookie'));
        $this->assertFalse(NecessaryAllowlist::matches('ext_consent_mode', 'localStorage'));
        // 잠금 집합은 제공 집합이 있어도 그대로 남는다.
        $this->assertTrue(NecessaryAllowlist::matches('XSRF-TOKEN', 'cookie'));
    }

    /**
     * 제공 집합은 요청마다 한 번만 모읍니다 (쿠키마다 훅을 다시 돌리지 않는다).
     *
     * @return void 반환값 없음
     *
     * @scenario scope=cookie, notation=exact, locked=operator_item, settings_state=populated, request=valid_item
     *
     * @effects provided_keys_collected_from_filter_hook
     */
    public function test_collects_once_per_request(): void
    {
        $calls = 0;
        $callback = static function (array $keys) use (&$calls) {
            $calls++;
            $keys['cookie'][] = 'ext_once';

            return $keys;
        };
        HookManager::addFilter(Plugin::PROVIDED_NECESSARY_STORAGE_HOOK, $callback);

        NecessaryAllowlist::matches('a', 'cookie');
        NecessaryAllowlist::matches('b', 'cookie');
        NecessaryAllowlist::matches('c', 'localStorage');

        $this->assertSame(1, $calls);
    }

    /**
     * 제공 집합은 인라인 페이로드(설정값)로 실려 클라이언트 판정에도 쓰입니다.
     *
     * @return void 반환값 없음
     *
     * @scenario scope=sessionStorage, notation=exact, locked=operator_item, settings_state=populated, request=valid_item
     *
     * @effects provided_keys_exposed_in_inline_payload
     */
    public function test_provided_set_is_exposed_in_config_values(): void
    {
        $this->provide(['sessionStorage' => ['ext_inline.key']]);

        $values = (new Plugin)->getConfigValues();

        $this->assertSame(['ext_inline.key'], $values['necessary_storage_provided']['sessionStorage']);

        $defaults = json_decode(
            file_get_contents(dirname(__DIR__, 3).'/config/settings/defaults.json'),
            true
        );
        $this->assertTrue($defaults['frontend_schema']['necessary_storage_provided']['expose'] ?? false);
    }

    /**
     * 제공 키를 덧붙이는 필터를 등록합니다.
     *
     * @param  array<string, array<int, mixed>>  $add  스코프 => 덧붙일 항목
     * @return void 반환값 없음
     */
    private function provide(array $add): void
    {
        $callback = static function ($keys) use ($add) {
            foreach ($add as $scope => $items) {
                $keys[$scope] = [...($keys[$scope] ?? []), ...$items];
            }

            return $keys;
        };
        HookManager::addFilter(Plugin::PROVIDED_NECESSARY_STORAGE_HOOK, $callback);
        $this->forgetMemo();
    }

    /**
     * 요청 단위 메모를 지웁니다.
     *
     * @return void 반환값 없음
     */
    private function forgetMemo(): void
    {
        if (app()->bound('request')) {
            app('request')->attributes->remove('sirsoft-gdpr.necessary_storage.provided');
        }
    }
}
