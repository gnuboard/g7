<?php

namespace Tests\Feature\Settings;

use App\Seo\SeoCacheRegenerator;
use App\Services\PluginSettingsService;
use App\Support\ExtensionSettingsMirror;
use App\Support\SettingsVersion;
use App\Support\WorkerSettingsRefresher;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Queue\Events\JobProcessing;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 큐 워커의 두 번째 작업이 첫 작업의 플러그인 설정을 붙잡지 않는다 (#860 W9(b))
 *
 * 플러그인 설정 서비스는 요청·작업 단위(scoped)로 설정 파일을 메모한다. 워커 수명 동안 살아 있는 객체가 이 서비스를
 * 생성자로 붙잡으면, 관리자가 설정을 바꿔도 그 객체는 워커를 재시작할 때까지 옛 값을 쓴다. 그래서 붙잡는 쪽도
 * 작업마다 새로 만들거나(scoped), 설정이 바뀌면 버린다(알림 채널 드라이버).
 */
class WorkerJobScopedSettingsTest extends TestCase
{
    /**
     * 워커 기억(싱글톤)과 설정 버전을 비웁니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->forgetInstance(WorkerSettingsRefresher::class);
        SettingsVersion::forget();
    }

    /**
     * 큐 작업 시작 이벤트를 냅니다.
     *
     * @return void 반환값 없음
     */
    private function startJob(): void
    {
        $job = Mockery::mock(Job::class);
        $job->shouldReceive('payload')->andReturn([]);
        $job->shouldIgnoreMissing();

        event(new JobProcessing('database', $job));
    }

    /**
     * 플러그인 설정 서비스는 한 작업 안에서는 하나이고, 다음 작업은 새 인스턴스를 받습니다.
     *
     * @effects plugin_settings_service_is_rebuilt_for_each_queue_job
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 두_번째_작업은_새_플러그인_설정_서비스를_쓴다(): void
    {
        $first = $this->app->make(PluginSettingsService::class);
        $this->assertSame($first, $this->app->make(PluginSettingsService::class));

        // 워커는 작업이 끝날 때마다 scoped 인스턴스를 버린다
        $this->app->forgetScopedInstances();

        $this->assertNotSame($first, $this->app->make(PluginSettingsService::class));
    }

    /**
     * 설정이 바뀐 뒤 시작한 작업은 알림 채널 드라이버를 새로 만들고, 바뀌지 않았으면 그대로 씁니다.
     *
     * @effects worker_settings_refresh_rebuilds_notification_channel_drivers
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 설정이_바뀌면_알림_채널_드라이버를_새로_만든다(): void
    {
        $mirror = Mockery::mock(ExtensionSettingsMirror::class);
        $mirror->shouldReceive('refreshCore', 'refreshAllModules', 'refreshAllPlugins');
        $this->app->instance(ExtensionSettingsMirror::class, $mirror);

        $manager = $this->app->make(ChannelManager::class);
        $manager->extend('w860_probe', fn () => new \stdClass);

        // 첫 작업은 무조건 다시 읽는다 — 그 전에 만든 드라이버는 버려진다
        $beforeFirstJob = $manager->driver('w860_probe');
        $this->startJob();
        $firstJob = $manager->driver('w860_probe');
        $this->assertNotSame($beforeFirstJob, $firstJob);

        // 설정 버전이 그대로면 드라이버를 유지한다
        $this->startJob();
        $this->assertSame($firstJob, $manager->driver('w860_probe'));

        // 설정을 저장해 버전이 오르면 다음 작업이 드라이버를 새로 만든다
        SettingsVersion::bump();
        $this->startJob();
        $this->assertNotSame($firstJob, $manager->driver('w860_probe'));
    }

    /**
     * SEO 캐시 재생성기·렌더러는 작업마다 새로 만들어져 그 작업의 플러그인 설정 서비스를 씁니다. (실패-먼저)
     *
     * @effects seo_renderer_uses_current_job_plugin_settings
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 두_번째_작업의_seo_렌더러는_그_작업의_설정_서비스를_쓴다(): void
    {
        $first = $this->app->make(SeoCacheRegenerator::class);
        $this->assertSame($first, $this->app->make(SeoCacheRegenerator::class));

        $this->app->forgetScopedInstances();

        $second = $this->app->make(SeoCacheRegenerator::class);
        $this->assertNotSame($first, $second);

        $renderer = $this->readProperty($second, 'renderer');
        $this->assertSame(
            $this->app->make(PluginSettingsService::class),
            $this->readProperty($renderer, 'pluginSettingsService'),
        );
    }

    /**
     * private 속성 값을 읽습니다.
     *
     * @param  object  $object  대상 객체
     * @param  string  $property  속성 이름
     * @return mixed 속성 값
     */
    private function readProperty(object $object, string $property): mixed
    {
        return (new \ReflectionProperty($object, $property))->getValue($object);
    }
}
