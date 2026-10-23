<?php

namespace Plugins\Sirsoft\MessageBizppurio\Tests\Feature\Installation;

use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\Support\LifecycleWarnings;
use App\Models\NotificationTemplate;
use Database\Seeders\NotificationDefinitionSeeder;
use Plugins\Sirsoft\MessageBizppurio\Plugin;
use Plugins\Sirsoft\MessageBizppurio\Tests\PluginTestCase;

/**
 * 활성화 시 코어/모듈 회원 알림에 sms·alimtalk 채널 템플릿이 자동 생성되는지 검증한다.
 *
 * 배경(실서버 회귀): 코어/모듈 알림 정의는 플러그인 설치 이전에 이미 DB 로 시딩돼 있고
 * (mail·database 만), sms·alimtalk 채널은 SeedChannelTemplatesListener 가 시딩 필터 훅에
 * 편승해 증강한다. 그러나 PluginManager::activatePlugin() 은 status 를 active 로 바꾸기
 * '전에' Plugin::activate() 를 호출하므로, activate() 안의 재시딩 시점엔 이 리스너가
 * 아직 자동 등록되지 않아 sms·alimtalk 이 붙지 않았다.
 *
 * 수정: Plugin::activate() 가 재시딩 전에 SeedChannelTemplatesListener 를 명시적으로
 * 선등록하도록 하여, 설치/재활성화만으로 채널 템플릿이 채워지게 한다.
 *
 * @scenario-manifest plugins/_bundled/sirsoft-message_bizppurio/tests/scenarios/activate-channel-reseed.yaml
 */
class ChannelTemplateSeedOnActivateTest extends PluginTestCase
{
    /**
     * 활성화 전(리스너 미등록) 코어 알림을 시딩하면 sms·alimtalk 이 없다가,
     * 활성화 후 회원 알림에 두 채널 템플릿이 생성된다.
     *
     * @scenario step=core, outcome=seeded
     *
     * @effects activate_seeds_channel_templates
     *
     * @return void 반환값 없음
     */
    public function test_activate_시_회원_알림에_sms_alimtalk_템플릿이_생성된다(): void
    {
        // [준비] 설치 이전 상태 모사: 리스너가 등록되지 않은 채 코어 알림 정의 시딩.
        // PluginTestCase 가 훅을 격리하므로 이 시점엔 SeedChannelTemplatesListener 가 없다.
        app(NotificationDefinitionSeeder::class)->run();

        $this->assertSame(
            0,
            NotificationTemplate::whereIn('channel', ['sms', 'alimtalk'])->count(),
            '활성화 전에는 sms·alimtalk 채널 템플릿이 없어야 한다(설치 전 상태).'
        );

        // [실행] 정식 활성화 진입점. 이 안에서 리스너 선등록 → 재시딩이 채널을 증강해야 한다.
        (new Plugin)->activate();

        // [검증] 회원 대상 코어 알림(welcome 등)에 sms·alimtalk 템플릿이 생성됐다.
        $this->assertGreaterThan(
            0,
            NotificationTemplate::where('channel', 'sms')->count(),
            'activate() 후 sms 채널 템플릿이 회원 알림에 생성돼야 한다.'
        );
        $this->assertGreaterThan(
            0,
            NotificationTemplate::where('channel', 'alimtalk')->count(),
            'activate() 후 alimtalk 채널 템플릿이 회원 알림에 생성돼야 한다.'
        );
    }

    /**
     * 모듈 단계가 반환값(failed)으로만 알리는 확장별 재시드 실패도 활성화를 막지 않고 코어 생명주기 경고로 남는다.
     *
     * @scenario step=modules, outcome=per_extension_failed
     *
     * @effects per_extension_reseed_failure_becomes_lifecycle_warning
     *
     * @return void 반환값 없음
     */
    public function test_activate_확장별_재시드_실패는_경고로_남는다(): void
    {
        $byTarget = $this->activateWithFailingResync(
            moduleResult: ['synced' => ['zz-ok'], 'failed' => ['zz-broken-module' => 'definition boom']],
            pluginFailure: null,
        );

        $this->assertSame('definition boom', $byTarget['sirsoft-message_bizppurio:modules:zz-broken-module'] ?? null, '확장별 실패가 경고로 올라와야 한다');
        $this->assertArrayNotHasKey('sirsoft-message_bizppurio:modules:zz-ok', $byTarget);
    }

    /**
     * 재시드 단계 자체가 예외로 끝나도 활성화를 막지 않고 코어 생명주기 경고로 남는다.
     *
     * @scenario step=plugins, outcome=exception
     *
     * @effects reseed_step_exception_becomes_lifecycle_warning
     *
     * @return void 반환값 없음
     */
    public function test_activate_재시드_단계_예외는_경고로_남는다(): void
    {
        $byTarget = $this->activateWithFailingResync(
            moduleResult: ['synced' => [], 'failed' => []],
            pluginFailure: new \RuntimeException('plugins boom'),
        );

        $this->assertSame('plugins boom', $byTarget['sirsoft-message_bizppurio:plugins'] ?? null, '단계 예외가 경고로 올라와야 한다');
    }

    /**
     * 모듈·플러그인 알림 재동기화를 대역으로 바꾼 뒤 활성화하고, 쌓인 생명주기 경고를 대상별 사유로 돌려줍니다.
     *
     * @param  array<string, mixed>  $moduleResult  모듈 재동기화 반환값(synced·failed)
     * @param  \Throwable|null  $pluginFailure  플러그인 재동기화가 던질 예외(null 이면 성공)
     * @return array<string, string> 경고 대상 => 사유
     */
    private function activateWithFailingResync(array $moduleResult, ?\Throwable $pluginFailure): array
    {
        $modules = \Mockery::mock(ModuleManager::class)->makePartial();
        $modules->shouldReceive('resyncActiveModuleNotificationDefinitions')->andReturn($moduleResult);
        $this->app->instance(ModuleManager::class, $modules);

        $plugins = \Mockery::mock(PluginManager::class)->makePartial();
        $pluginFailure === null
            ? $plugins->shouldReceive('resyncActivePluginNotificationDefinitions')->andReturn(['synced' => [], 'failed' => []])
            : $plugins->shouldReceive('resyncActivePluginNotificationDefinitions')->andThrow($pluginFailure);
        $this->app->instance(PluginManager::class, $plugins);

        $warnings = LifecycleWarnings::current();
        $warnings->clear();

        $this->assertTrue((new Plugin)->activate(), '재시드 실패가 활성화를 막으면 안 된다');

        return array_column($warnings->all(), 'message', 'target');
    }
}
