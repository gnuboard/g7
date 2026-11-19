<?php

namespace Plugins\Sirsoft\CdnCloudfront\Tests\Feature;

use App\Extension\HookManager;
use App\Extension\PluginManager;
use Aws\Result;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Plugins\Sirsoft\CdnCloudfront\Plugin;
use Plugins\Sirsoft\CdnCloudfront\Services\PurgeService;
use Plugins\Sirsoft\CdnCloudfront\Support\CloudFrontDisk;
use Plugins\Sirsoft\CdnCloudfront\Tests\PluginTestCase;

/**
 * 플러그인 제거 뒤 썸네일 연동이 남지 않는지 — 디스크 정의와 큐 워커, 퍼지 기록 캐시, 대기열 테이블
 *
 * 코어는 켜진 플러그인을 제거하기 전에 비활성화(`core.plugins.after_deactivate` 포함)를 거치고, 제거가 끝나면 큐 워커에
 * 재시작 신호를 보낸다(설계 확정 2026-11-08). 그래서 이 플러그인은 제거 훅을 구독하지 않고 비활성화 경로에서 한 번 정리한다.
 * 제거 완료 훅이 발화할 때는 플러그인 디렉터리가 이미 지워져 이 플러그인 리스너 클래스를 읽을 수 없다.
 *
 * @scenario-manifest tests/scenarios/cloudfront-uninstall.yaml
 */
class UninstallCleanupTest extends PluginTestCase
{
    /** 프레임워크가 큐 워커 재시작 신호로 쓰는 캐시 키 */
    private const RESTART_SIGNAL = 'illuminate:queue:restart';

    /**
     * 테스트 시작 — 코어 S3 디스크 설정을 시험값으로 채운다(실제 버킷이 아니다).
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'filesystems.disks.s3.bucket' => 'test-bucket',
            'filesystems.disks.s3.region' => 'ap-northeast-2',
            'filesystems.disks.s3.key' => 'S3TESTKEY',
            'filesystems.disks.s3.secret' => 's3-test-secret',
        ]);
    }

    /**
     * 켜진 채 제거하면 코어가 제거 전에 비활성화를 거치므로(설계 확정 2026-11-08) 디스크 정의 삭제와 재시작 신호는 비활성화
     * 완료 훅에서 한 번 일어나고, 제거 직전·완료 훅에서는 이 플러그인이 아무것도 하지 않는다 — 두 번 정리하지 않는다.
     * 제거 뒤 큐 워커 재시작 신호는 코어가 보낸다. 코어 훅 순서를 그대로 흉내 낸다.
     *
     * @scenario case=active_uninstall
     *
     * @effects active_uninstall_cleaned_once_through_deactivation
     *
     * @return void 반환값 없음
     */
    public function test_active_uninstall_is_cleaned_once_through_deactivation(): void
    {
        $this->configure(['cdn_base_url' => self::CDN]);
        app(CloudFrontDisk::class)->register();
        $this->registerListeners();
        Cache::forget(self::RESTART_SIGNAL);

        HookManager::doAction('core.plugins.before_uninstall', self::IDENTIFIER, false);
        $this->assertSame(self::CDN, config('filesystems.disks.cloudfront.url'), '제거 직전 훅에서는 지우지 않는다 — 제거가 막히면 켜진 채 남는다');
        $this->assertNull(Cache::get(self::RESTART_SIGNAL));

        HookManager::doAction('core.plugins.before_deactivate', self::IDENTIFIER);
        HookManager::doAction('core.plugins.after_deactivate', self::IDENTIFIER);
        $this->assertArrayNotHasKey('cloudfront', (array) config('filesystems.disks'));
        $this->assertNotNull(Cache::get(self::RESTART_SIGNAL));

        // 비활성화 뒤 다시 정의된 디스크가 있어도 제거 완료 훅에서 이 플러그인이 또 지우지 않는다(이 플러그인의 제거 훅 구독이
        // 없다는 것은 아래 시험이 잰다). 제거 완료 뒤의 재시작 신호는 코어 큐 리스너가 보낸다
        app(CloudFrontDisk::class)->register();
        Cache::forget(self::RESTART_SIGNAL);
        HookManager::doAction('core.plugins.after_uninstall', self::IDENTIFIER, false, true);

        $this->assertSame(self::CDN, config('filesystems.disks.cloudfront.url'), '제거 완료 훅에서 두 번째 정리가 돌았다');
        $this->assertNotNull(Cache::get(self::RESTART_SIGNAL), '제거 완료 뒤 코어가 큐 워커에 재시작 신호를 보내야 한다');
    }

    /**
     * 어떤 리스너도 제거 직전·완료 훅을 구독하지 않는다 — 제거 완료 훅 때는 플러그인 디렉터리가 지워져 리스너 클래스를
     * 다시 읽을 수 없고, 정리는 비활성화 경로가 맡는다. 리스너 해석을 막아 두어도 제거 완료 훅이 오류 없이 지나간다.
     *
     * @scenario case=listener_files_gone
     *
     * @effects no_listener_subscribes_uninstall_hooks
     *
     * @return void 반환값 없음
     */
    public function test_no_listener_subscribes_uninstall_hooks(): void
    {
        foreach ((new Plugin)->getHookListeners() as $listener) {
            $hooks = $listener::getSubscribedHooks();
            $this->assertArrayNotHasKey('core.plugins.before_uninstall', $hooks, $listener);
            $this->assertArrayNotHasKey('core.plugins.after_uninstall', $hooks, $listener);
        }

        $this->configure(['cdn_base_url' => self::CDN]);
        app(CloudFrontDisk::class)->register();
        $this->registerListeners();

        foreach ((new Plugin)->getHookListeners() as $listener) {
            $this->app->bind($listener, static fn () => throw new \LogicException($listener.' 를 다시 읽었습니다'));
        }

        HookManager::doAction('core.plugins.before_uninstall', self::IDENTIFIER, true);
        HookManager::doAction('core.plugins.after_uninstall', self::IDENTIFIER, true, true);

        $this->assertSame(self::CDN, config('filesystems.disks.cloudfront.url'));
    }

    /**
     * 코어가 제거와 비활성화 때 비우는 플러그인 캐시에 마지막 무효화 성공 시각이 들어 있다 — 다시 설치해도
     * 지난 설치의 성공 시각이 현황에 나오지 않는다.
     *
     * @scenario case=data_cleanup
     *
     * @effects plugin_cache_flush_clears_last_purge_success
     *
     * @return void 반환값 없음
     */
    public function test_plugin_cache_flush_clears_last_purge_success(): void
    {
        $this->configureReady();
        $this->aws->append(new Result(['Invalidation' => ['Id' => 'I1', 'Status' => 'InProgress']]));

        app(PurgeService::class)->purgeItems([$this->purgeItem('modules/shop/images/p1.jpg')]);
        $this->assertNotNull(app(PurgeService::class)->status()['last_success_at']);

        app(PluginManager::class)->getPlugin(self::IDENTIFIER)->getCache()->flush();

        $this->assertNull(app(PurgeService::class)->status()['last_success_at']);
    }

    /**
     * 데이터 삭제를 고른 제거가 지우는 테이블 목록에 이 플러그인 마이그레이션이 만드는 테이블이 모두 들어 있다 —
     * 퍼지 대기열(cloudfront_purges)이 남으면 다시 설치했을 때 지난 배포의 무효화를 다시 보낸다.
     *
     * @scenario case=data_cleanup
     *
     * @effects dynamic_tables_cover_migration_tables
     *
     * @return void 반환값 없음
     */
    public function test_dynamic_tables_cover_every_migration_table(): void
    {
        $created = [];
        foreach (glob(dirname(__DIR__, 2).'/database/migrations/*.php') as $file) {
            preg_match_all("/Schema::create\\(\\s*'([^']+)'/", (string) file_get_contents($file), $matches);
            $created = array_merge($created, $matches[1]);
        }

        $this->assertContains('cloudfront_purges', $created);
        $this->assertTrue(Schema::hasTable('cloudfront_purges'));
        $this->assertSame([], array_values(array_diff($created, (new Plugin)->getDynamicTables())));
    }
}
