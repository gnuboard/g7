<?php

namespace Tests\Feature\Settings;

use App\Repositories\JsonConfigRepository;
use App\Services\SettingsService;
use App\Support\ExtensionSettingsMirror;
use App\Support\SettingsVersion;
use App\Support\WorkerSettingsRefresher;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobProcessing;
use Mockery;
use Tests\TestCase;

/**
 * 큐 워커가 작업 시작 직전에 설정 버전을 비교해 바뀐 설정을 다시 읽는다
 *
 * 워커는 부팅 때 설정을 한 번 읽고 프로세스가 끝날 때까지 들고 있다. 관리자가 메일 발신 주소나 모듈 설정을 바꿔도
 * 저장한 웹 프로세스만 다시 읽으므로, 워커가 보내는 메일·계산은 재시작 전까지 옛 설정을 쓴다. 설정 저장 때 올린
 * 버전을 작업 시작마다 비교해 바뀌었으면 다시 읽는다. 첫 작업은 무조건 한 번 다시 읽는다.
 */
class WorkerSettingsRefreshTest extends TestCase
{
    /**
     * 워커 기억(싱글톤)을 비우고 설정 버전을 지웁니다.
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
     * @param  string  $connection  큐 연결 이름
     * @return void 반환값 없음
     */
    private function startJob(string $connection = 'database'): void
    {
        $job = Mockery::mock(Job::class);
        $job->shouldReceive('payload')->andReturn([]);
        $job->shouldIgnoreMissing();

        event(new JobProcessing($connection, $job));
    }

    /**
     * 다시 읽기 횟수를 센다 — 다시 읽을 때마다 코어 설정 미러를 한 번 채우므로 그 호출 수를 센다.
     *
     * @return \Closure(): int 지금까지의 다시 읽기 횟수를 돌려주는 함수
     */
    private function countRefreshLogs(): \Closure
    {
        $count = 0;
        $mirror = Mockery::mock(ExtensionSettingsMirror::class);
        $mirror->shouldReceive('refreshCore')->andReturnUsing(function () use (&$count) {
            $count++;
        });
        $mirror->shouldReceive('refreshAllModules', 'refreshAllPlugins');
        $this->app->instance(ExtensionSettingsMirror::class, $mirror);

        return function () use (&$count) {
            return $count;
        };
    }

    /**
     * 첫 작업은 다시 읽고, 버전이 그대로면 다음 작업은 다시 읽지 않고, 올리면 다시 읽는다. (실패-먼저)
     *
     * @scenario case=worker_refresh
     *
     * @effects worker_rereads_on_version_change
     *
     * @return void 반환값 없음
     */
    public function test_refreshes_on_first_job_and_on_version_change_only(): void
    {
        $count = $this->countRefreshLogs();

        $this->startJob();
        $this->assertSame(1, $count(), '첫 작업에서 설정을 다시 읽지 않았습니다.');

        $this->startJob();
        $this->assertSame(1, $count(), '설정 버전이 그대로인데 다시 읽었습니다.');

        SettingsVersion::bump();
        $this->startJob();
        $this->assertSame(2, $count(), '설정 버전이 바뀌었는데 다시 읽지 않았습니다.');
    }

    /**
     * 메일 발신 주소를 바꾸고 버전을 올리면 다음 작업에서 워커의 메일 설정이 새 값이다. (실패-먼저)
     *
     * @scenario case=worker_refresh
     *
     * @effects worker_rereads_on_version_change
     *
     * @return void 반환값 없음
     */
    public function test_mail_from_address_is_refreshed(): void
    {
        $this->startJob();

        (new JsonConfigRepository)->saveCategory('mail', ['from_address' => 'worker-724@example.test', 'from_name' => 'G7 724']);
        SettingsVersion::bump();
        $this->startJob();

        $this->assertSame('worker-724@example.test', config('mail.from.address'), '워커의 메일 발신 주소가 옛 값입니다.');
    }

    /**
     * 다시 읽을 때 코어·모듈·플러그인 미러를 모두 다시 채운다. (실패-먼저)
     *
     * @scenario case=worker_refresh
     *
     * @effects worker_rereads_on_version_change
     *
     * @return void 반환값 없음
     */
    public function test_refresh_repopulates_every_settings_mirror(): void
    {
        $mirror = Mockery::mock(ExtensionSettingsMirror::class);
        $mirror->shouldReceive('refreshCore')->once();
        $mirror->shouldReceive('refreshAllModules')->once();
        $mirror->shouldReceive('refreshAllPlugins')->once();
        $this->app->instance(ExtensionSettingsMirror::class, $mirror);

        $this->startJob();
    }

    /**
     * sync 연결 작업(웹 요청 안)은 버전을 비교하지 않는다.
     *
     * @scenario case=worker_refresh
     *
     * @effects sync_connection_ignored
     *
     * @return void 반환값 없음
     */
    public function test_sync_connection_is_ignored(): void
    {
        $count = $this->countRefreshLogs();

        $this->startJob('sync');

        $this->assertSame(0, $count());
    }

    /**
     * 다시 읽다 예외가 나도 작업을 실패시키지 않고 경고만 남긴다.
     *
     * @scenario case=worker_refresh
     *
     * @effects refresh_failure_does_not_fail_job
     *
     * @return void 반환값 없음
     */
    public function test_refresh_failure_is_swallowed(): void
    {
        $mirror = Mockery::mock(ExtensionSettingsMirror::class);
        $mirror->shouldReceive('refreshCore')->andThrow(new \RuntimeException('미러 장애 대역'));
        $this->app->instance(ExtensionSettingsMirror::class, $mirror);

        $this->startJob();

        $this->assertTrue(true, '예외가 작업 시작 이벤트 밖으로 새지 않았다');
    }

    /**
     * 코어 설정을 저장하면 설정 버전이 바뀐다. (실패-먼저)
     *
     * @scenario case=version
     *
     * @effects version_bumped_on_save
     *
     * @return void 반환값 없음
     */
    public function test_core_settings_save_bumps_version(): void
    {
        $before = SettingsVersion::current();

        app(SettingsService::class)->setSetting('general.site_name', '설정 버전 724');

        $this->assertNotNull(SettingsVersion::current(), '코어 설정을 저장했는데 설정 버전이 없습니다.');
        $this->assertNotSame($before, SettingsVersion::current());
    }
}
