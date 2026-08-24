<?php

namespace Plugins\Sirsoft\MessageBizppurio\Tests\Unit\Listeners;

use App\Extension\Cache\PluginCacheDriver;
use App\Extension\HookManager;
use App\Services\PluginSettingsService;
use Plugins\Sirsoft\MessageBizppurio\Listeners\InvalidateTokenOnSettingsSaveListener;
use Plugins\Sirsoft\MessageBizppurio\Services\BizppurioTokenService;
use Plugins\Sirsoft\MessageBizppurio\Tests\PluginTestCase;

/**
 * 설정 저장 시 인증 토큰 캐시 무효화 리스너를 실제 훅 체인 + 실제 캐시로 검증한다.
 *
 * 리스너 메서드를 직접 부르지 않고, `core.plugin_settings.after_save` 훅을 통해
 * 실제 확장 캐시 드라이버(PluginCacheDriver)의 관찰 가능한 상태 변화(존재→부재)로
 * 검증한다(Hook/Event 도메인 규정 — mock 금지, 실제 훅 체인).
 *
 * BizppurioTokenService 는 contextual binding 으로 플러그인 식별자 네임스페이스가
 * 적용된 PluginCacheDriver 를 주입받는다(전역 app(CacheInterface::class) 는 코어
 * 네임스페이스의 CoreCacheDriver 로 별개 저장 공간이므로 검증에 사용할 수 없다).
 * 따라서 이 테스트도 동일한 PluginCacheDriver 를 직접 생성해 관찰한다.
 */
class InvalidateTokenOnSettingsSaveListenerTest extends PluginTestCase
{
    private const IDENTIFIER = 'sirsoft-message_bizppurio';

    private const CACHE_KEY = 'bizppurio:token';

    private PluginCacheDriver $cache;

    protected function setUp(): void
    {
        parent::setUp();

        // BizppurioTokenService 가 contextual binding 으로 받는 것과 동일한 캐시
        // (식별자 네임스페이스 + 기본 캐시 스토어).
        $this->cache = new PluginCacheDriver(self::IDENTIFIER, config('cache.default'));
        $this->cache->flush();
    }

    /**
     * 리스너를 실제 훅 파이프라인에 등록합니다.
     */
    private function registerListener(): void
    {
        $tokenService = new BizppurioTokenService($this->cache, app(PluginSettingsService::class));
        $listener = new InvalidateTokenOnSettingsSaveListener($tokenService);

        HookManager::addAction(
            'core.plugin_settings.after_save',
            [$listener, 'invalidateToken'],
            10,
        );
    }

    public function test_저장_성공시_본_플러그인_캐시가_무효화된다(): void
    {
        $this->cache->put(self::CACHE_KEY, 'STALE_TOKEN', 3600);
        $this->assertTrue($this->cache->has(self::CACHE_KEY));

        $this->registerListener();

        HookManager::doAction('core.plugin_settings.after_save', self::IDENTIFIER, ['bizppurio_id' => 'acct'], true);

        $this->assertFalse($this->cache->has(self::CACHE_KEY));
    }

    public function test_저장_실패시_캐시를_건드리지_않는다(): void
    {
        $this->cache->put(self::CACHE_KEY, 'EXISTING_TOKEN', 3600);

        $this->registerListener();

        HookManager::doAction('core.plugin_settings.after_save', self::IDENTIFIER, ['bizppurio_id' => 'acct'], false);

        $this->assertTrue($this->cache->has(self::CACHE_KEY));
        $this->assertSame('EXISTING_TOKEN', $this->cache->get(self::CACHE_KEY));
    }

    public function test_다른_플러그인_저장시_캐시를_건드리지_않는다(): void
    {
        $this->cache->put(self::CACHE_KEY, 'EXISTING_TOKEN', 3600);

        $this->registerListener();

        HookManager::doAction('core.plugin_settings.after_save', 'sirsoft-other-plugin', [], true);

        $this->assertTrue($this->cache->has(self::CACHE_KEY));
    }

    /**
     * 실제 저장 API(PluginSettingsService::save) 를 호출해 코어 저장 경로 →
     * after_save 훅 → 캐시 무효화까지 end-to-end 로 관찰한다.
     */
    public function test_설정_저장_API_호출시_실제_저장_경로를_통해_캐시가_무효화된다(): void
    {
        $this->cache->put(self::CACHE_KEY, 'STALE_TOKEN', 3600);

        $this->registerListener();

        app(PluginSettingsService::class)->save(self::IDENTIFIER, [
            'bizppurio_id' => 'new-account',
            'password' => 'new-secret',
            'is_test_mode' => true,
        ]);

        $this->assertFalse($this->cache->has(self::CACHE_KEY));
    }
}
