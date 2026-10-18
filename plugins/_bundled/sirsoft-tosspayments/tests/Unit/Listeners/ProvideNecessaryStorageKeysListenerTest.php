<?php

namespace Plugins\Sirsoft\Tosspayments\Tests\Unit\Listeners;

use App\Extension\HookManager;
use Plugins\Sirsoft\Tosspayments\Listeners\ProvideNecessaryStorageKeysListener;
use Plugins\Sirsoft\Tosspayments\Tests\PluginTestCase;

/**
 * 개인정보 동의 플러그인에 필수 저장 키를 제공하는 리스너 검증
 *
 * 동의 플러그인은 동의 전에는 허용목록 밖의 저장 키를 부팅마다 지우고, 어떤 확장이 있는지 모른다.
 * 이 확장이 자기 키를 그 플러그인의 필터 훅에 덧붙이지 않으면 결제창 복귀 기록이 지워지면 결제 실패·창 닫힘 보고가 누락된다 — 오류도 로그도 남지 않는다.
 * 반대로 키 이름이 실제 저장 코드와 어긋나면 제공해도 아무것도 지켜지지 않으므로 원문과 대조한다.
 *
 * @scenario scope=sessionStorage, consent_plugin=installed
 *
 * @effects provider_subscribes_to_consent_plugin_filter_hook,
 *   provider_appends_own_keys_without_dropping_others,
 *   provider_keys_match_the_storage_writing_code,
 *   provider_does_not_reference_consent_plugin_classes
 */
class ProvideNecessaryStorageKeysListenerTest extends PluginTestCase
{
    /** @var string 동의 플러그인이 소유한 계약 훅 */
    private const HOOK = 'sirsoft-gdpr.necessary_storage.filter_provided_keys';

    /** @var array<string, array<int, string>> 이 확장이 제공해야 하는 키 */
    private const EXPECTED = [
        'sessionStorage' => [
            'g7:sirsoft-tosspayments:pendingClose',
        ],
    ];

    /** @var array<string, string> 키 => 그 키를 실제로 저장하는 소스 (이 확장 루트 기준) */
    private const WRITERS = [
        'g7:sirsoft-tosspayments:pendingClose' => 'resources/js/paymentCloseReport.ts',
    ];

    /**
     * 훅을 filter 로 구독합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario scope=sessionStorage, consent_plugin=installed
     *
     * @effects provider_subscribes_to_consent_plugin_filter_hook
     */
    public function test_subscribes_to_the_consent_plugin_filter_hook(): void
    {
        $hooks = ProvideNecessaryStorageKeysListener::getSubscribedHooks();

        $this->assertArrayHasKey(self::HOOK, $hooks);
        $this->assertSame('filter', $hooks[self::HOOK]['type']);
        $this->assertSame('provide', $hooks[self::HOOK]['method']);
    }

    /**
     * 자기 키를 덧붙이되 앞선 리스너가 모은 키는 그대로 두고 중복은 만들지 않습니다.
     *
     * @return void 반환값 없음
     *
     * @scenario scope=sessionStorage, consent_plugin=installed
     *
     * @effects provider_appends_own_keys_without_dropping_others
     */
    public function test_appends_own_keys_without_dropping_others(): void
    {
        $firstKey = self::EXPECTED['sessionStorage'][0];
        $input = [
            'localStorage' => ['other_ext_key'],
            'sessionStorage' => ['other_ext.pending'],
            'cookie' => ['other_cookie'],
        ];
        $input['sessionStorage'][] = $firstKey;

        $result = (new ProvideNecessaryStorageKeysListener)->provide($input);

        foreach (self::EXPECTED as $scope => $keys) {
            foreach ($keys as $key) {
                $this->assertContains($key, $result[$scope]);
            }
        }
        $this->assertContains('other_ext_key', $result['localStorage']);
        $this->assertContains('other_ext.pending', $result['sessionStorage']);
        $this->assertSame(['other_cookie'], $result['cookie']);
        $this->assertSame(1, count(array_keys($result['sessionStorage'], $firstKey, true)));
    }

    /**
     * 배열이 아닌 값을 받아도 자기 키만 담은 배열을 돌려줍니다.
     *
     * @return void 반환값 없음
     *
     * @scenario scope=sessionStorage, consent_plugin=installed
     *
     * @effects provider_appends_own_keys_without_dropping_others
     */
    public function test_tolerates_non_array_input(): void
    {
        $result = (new ProvideNecessaryStorageKeysListener)->provide(null);

        foreach (self::EXPECTED as $scope => $keys) {
            $this->assertSame($keys, $result[$scope]);
        }
    }

    /**
     * 리스너를 훅 체인에 걸면 키가 체인 결과에 실립니다.
     *
     * @return void 반환값 없음
     *
     * @scenario scope=sessionStorage, consent_plugin=installed
     *
     * @effects provider_appends_own_keys_without_dropping_others
     */
    public function test_keys_arrive_through_the_hook_chain(): void
    {
        $callback = [new ProvideNecessaryStorageKeysListener, 'provide'];
        HookManager::addFilter(self::HOOK, $callback);

        try {
            $result = HookManager::applyFilters(self::HOOK, ['localStorage' => [], 'sessionStorage' => [], 'cookie' => []]);
        } finally {
            HookManager::removeFilter(self::HOOK, $callback);
        }

        foreach (self::EXPECTED as $scope => $keys) {
            foreach ($keys as $key) {
                $this->assertContains($key, $result[$scope]);
            }
        }
    }

    /**
     * 제공 키가 그 키를 실제로 저장하는 코드의 문자열과 같습니다 (어긋나면 제공해도 지켜지지 않는다).
     *
     * @return void 반환값 없음
     *
     * @scenario scope=sessionStorage, consent_plugin=installed
     *
     * @effects provider_keys_match_the_storage_writing_code
     */
    public function test_keys_match_the_storage_writing_code(): void
    {
        $root = dirname(__DIR__, 3);
        $provided = array_values(array_unique(array_merge(...array_values(self::EXPECTED))));

        $this->assertSame(array_keys(self::WRITERS), $provided);

        foreach (self::WRITERS as $key => $relative) {
            $source = file_get_contents($root.'/'.$relative);
            $this->assertIsString($source, $relative.' 를 읽지 못했습니다.');
            $this->assertMatchesRegularExpression(
                '/[\'"]'.preg_quote($key, '/').'[\'"]/',
                $source,
                "{$key} 가 {$relative} 의 저장 키 문자열과 어긋납니다."
            );
        }
    }

    /**
     * 리스너가 동의 플러그인의 클래스를 참조하지 않습니다 (미설치여도 이 확장이 선다).
     *
     * @return void 반환값 없음
     *
     * @scenario scope=sessionStorage, consent_plugin=absent
     *
     * @effects provider_does_not_reference_consent_plugin_classes
     */
    public function test_does_not_reference_consent_plugin_classes(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3).'/src/Listeners/ProvideNecessaryStorageKeysListener.php');

        $this->assertStringNotContainsString('Plugins\\Sirsoft\\Gdpr', $source);
        $this->assertStringNotContainsString('class_exists', $source);
    }
}
