<?php

namespace Tests\Unit\Services\Schedule;

use App\Contracts\Extension\PluginInterface;
use App\Enums\ExtensionOwnerType;
use App\Extension\PluginManager;
use App\Models\Schedule;
use App\Services\PluginSettingsService;
use App\Services\Schedule\ScheduleConditionEvaluator;
use Mockery;
use Tests\TestCase;

/**
 * 스케줄 실행 조건 해석기(K2) 테스트.
 *
 * 옛 커밋 결함 d — enabled_condition 을 config() 로 읽어 코어 설정(g7_core_settings)을 못 보고 사이트맵·GeoIP 가
 * 영구 비활성이었다. source 별 해석과 카탈로그 키 해석 가능성 검사를 고정한다. DB 를 쓰지 않는다(모델은 저장 안 함).
 * 조합은 메서드마다 @scenario 로 단다(매니페스트 schedule-condition-evaluator.yaml).
 */
class ScheduleConditionEvaluatorTest extends TestCase
{
    private ScheduleConditionEvaluator $evaluator;

    /**
     * 해석기를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->evaluator = $this->app->make(ScheduleConditionEvaluator::class);
    }

    /**
     * 조건 목록을 가진 저장하지 않은 스케줄을 만듭니다.
     *
     * @param  array<int, mixed>|null  $conditions  실행 조건
     * @param  ExtensionOwnerType|null  $owner  소유 타입
     * @param  string|null  $identifier  소유 식별자
     * @return Schedule 스케줄 (미저장)
     */
    private function schedule(?array $conditions, ?ExtensionOwnerType $owner = null, ?string $identifier = null): Schedule
    {
        return new Schedule([
            'name' => 'probe',
            'enabled_condition' => $conditions,
            'extension_type' => $owner?->value,
            'extension_identifier' => $identifier,
        ]);
    }

    /**
     * 플러그인 설정 서비스를 고정 값으로 바꿉니다.
     *
     * @param  mixed  $value  plugin_setting() 이 돌려줄 값
     * @return void 반환값 없음
     */
    private function fakePluginSetting(mixed $value): void
    {
        $settings = Mockery::mock(PluginSettingsService::class);
        $settings->shouldReceive('get')->withArgs(fn ($id) => $id === 'vendor-probe')->andReturn($value);
        $this->app->instance(PluginSettingsService::class, $settings);
    }

    /**
     * 조건이 없으면 통과하고 미충족 목록이 비어 있다.
     *
     * @effects condition_operators_evaluate
     *
     * @return void 반환값 없음
     */
    public function test_no_conditions_pass(): void
    {
        $this->assertTrue($this->evaluator->passes($this->schedule(null)));
        $this->assertSame([], $this->evaluator->unmetConditions($this->schedule([])));
    }

    /**
     * config 원천 — truthy 미충족, not_empty 충족, equals(불리언 기대값 포함), AND 결합.
     *
     * @scenario condition_operator=truthy, condition_source=config, condition_state=unmet
     *
     * @effects condition_operators_evaluate
     *
     * @return void 반환값 없음
     */
    public function test_config_operators_and_conjunction(): void
    {
        // @scenario condition_operator=not_empty, condition_source=config, condition_state=met
        config(['probe.flag' => '1', 'probe.zero' => '0', 'probe.empty' => '', 'probe.mode' => 'daily', 'probe.bool' => 'true']);

        $truthy = ['source' => 'config', 'key' => 'probe.flag', 'operator' => 'truthy'];
        $truthyUnmet = ['source' => 'config', 'key' => 'probe.zero', 'operator' => 'truthy'];
        $notEmpty = ['source' => 'config', 'key' => 'probe.mode', 'operator' => 'not_empty'];
        $notEmptyUnmet = ['source' => 'config', 'key' => 'probe.empty', 'operator' => 'not_empty', 'default' => ''];
        $equals = ['source' => 'config', 'key' => 'probe.mode', 'operator' => 'equals', 'value' => 'daily'];
        $equalsBool = ['source' => 'config', 'key' => 'probe.bool', 'operator' => 'equals', 'value' => true];

        $this->assertTrue($this->evaluator->passes($this->schedule([$truthy, $notEmpty, $equals, $equalsBool])));
        $this->assertFalse($this->evaluator->passes($this->schedule([$truthyUnmet])));
        $this->assertSame(
            [['source' => 'config', 'key' => 'probe.empty', 'operator' => 'not_empty']],
            $this->evaluator->unmetConditions($this->schedule([$truthy, $notEmptyUnmet])),
        );
    }

    /**
     * 사이트맵·GeoIP 조건은 코어 설정(g7_core_settings) 값을 따른다 — 결함 d 회귀. 코어 equals 미충족 포함.
     *
     * @scenario condition_operator=truthy, condition_source=core_setting, condition_state=met
     *
     * @effects sitemap_and_geoip_conditions_follow_settings
     *
     * @return void 반환값 없음
     */
    public function test_sitemap_and_geoip_follow_core_settings(): void
    {
        // @scenario condition_operator=equals, condition_source=core_setting, condition_state=unmet
        $catalog = config('core.schedules');
        $sitemap = $this->schedule($catalog['sitemap-generate']['enabled_condition']);
        $geoip = $this->schedule($catalog['geoip-update']['enabled_condition']);

        config(['g7_settings.core.seo.sitemap_enabled' => true]);
        $this->assertTrue($this->evaluator->passes($sitemap));
        config(['g7_settings.core.seo.sitemap_enabled' => false]);
        $this->assertFalse($this->evaluator->passes($sitemap));

        config(['g7_settings.core.geoip' => ['feature_enabled' => true, 'auto_update_enabled' => true, 'license_key' => 'k']]);
        $this->assertTrue($this->evaluator->passes($geoip));
        config(['g7_settings.core.geoip.license_key' => '']);
        $this->assertSame(['geoip.license_key'], array_column($this->evaluator->unmetConditions($geoip), 'key'));
        config(['g7_settings.core.geoip' => ['feature_enabled' => false, 'auto_update_enabled' => true, 'license_key' => 'k']]);
        $this->assertFalse($this->evaluator->passes($geoip));

        config(['g7_settings.core.seo.sitemap_schedule' => 'daily']);
        $equals = ['source' => 'core_setting', 'key' => 'seo.sitemap_schedule', 'operator' => 'equals', 'value' => 'weekly'];
        $this->assertFalse($this->evaluator->passes($this->schedule([$equals])));
    }

    /**
     * 플러그인 설정 조건은 행의 소유 플러그인으로 plugin_setting() 을 읽는다 — truthy 충족, not_empty 미충족.
     *
     * @scenario condition_operator=truthy, condition_source=plugin_setting, condition_state=met
     *
     * @effects condition_operators_evaluate
     *
     * @return void 반환값 없음
     */
    public function test_plugin_setting_uses_row_owner(): void
    {
        // @scenario condition_operator=not_empty, condition_source=plugin_setting, condition_state=unmet
        $truthy = ['source' => 'plugin_setting', 'key' => 'cleanup.enabled', 'operator' => 'truthy', 'default' => false];
        $notEmpty = ['source' => 'plugin_setting', 'key' => 'api.key', 'operator' => 'not_empty', 'default' => ''];

        $this->fakePluginSetting(true);
        $this->assertTrue($this->evaluator->passes($this->schedule([$truthy], ExtensionOwnerType::Plugin, 'vendor-probe')));

        $this->fakePluginSetting('');
        $this->assertFalse($this->evaluator->passes($this->schedule([$notEmpty], ExtensionOwnerType::Plugin, 'vendor-probe')));
        $this->assertFalse(
            $this->evaluator->passes($this->schedule([$truthy], ExtensionOwnerType::Plugin, 'vendor-probe')),
            '빈 문자열은 truthy 미충족',
        );
    }

    /**
     * 형식이 잘못된 조건은 미충족이다(실행하지 않는 쪽이 안전) — 원천별 형식 오류.
     *
     * @scenario condition_operator=equals, condition_source=plugin_setting, condition_state=malformed
     *
     * @effects malformed_condition_is_unmet
     *
     * @return void 반환값 없음
     */
    public function test_malformed_condition_is_unmet(): void
    {
        // @scenario condition_operator=not_empty, condition_source=core_setting, condition_state=malformed
        // @scenario condition_operator=equals, condition_source=config, condition_state=malformed
        foreach ([
            ['source' => 'plugin_setting', 'key' => 'mode', 'operator' => 'equals'],
            ['source' => 'core_setting', 'key' => '', 'operator' => 'not_empty'],
            ['source' => 'config', 'key' => 'app.name', 'operator' => 'equals'],
            ['source' => 'env', 'key' => 'X', 'operator' => 'truthy'],
            ['source' => 'config', 'key' => 'app.name', 'operator' => 'greater'],
            'not-an-array',
        ] as $condition) {
            $schedule = $this->schedule([$condition], ExtensionOwnerType::Plugin, 'vendor-probe');
            $this->assertFalse($this->evaluator->passes($schedule), json_encode($condition));
            if (is_array($condition)) {
                $this->assertFalse($this->evaluator->isResolvable($condition, 'vendor-probe'));
            }
        }
    }

    /**
     * isResolvable — 코어 설정은 defaults.json, config 는 설정 저장소, 없는 키는 거짓.
     *
     * @scenario condition_operator=truthy, condition_source=core_setting, condition_state=met
     *
     * @effects is_resolvable_checks_defaults
     *
     * @return void 반환값 없음
     */
    public function test_is_resolvable_for_core_and_config(): void
    {
        $this->assertTrue($this->evaluator->isResolvable(['source' => 'core_setting', 'key' => 'seo.sitemap_enabled', 'operator' => 'truthy']));
        $this->assertFalse($this->evaluator->isResolvable(['source' => 'core_setting', 'key' => 'seo.no_such_key', 'operator' => 'truthy']));
        $this->assertTrue($this->evaluator->isResolvable(['source' => 'config', 'key' => 'app.name', 'operator' => 'not_empty']));
        $this->assertFalse($this->evaluator->isResolvable(['source' => 'config', 'key' => 'no.such.config', 'operator' => 'not_empty']));
        $this->assertFalse(
            $this->evaluator->isResolvable(['source' => 'plugin_setting', 'key' => 'x', 'operator' => 'truthy']),
            '확장 조건은 식별자 없이 해석할 수 없다',
        );
    }

    /**
     * 확장 설정 키는 defaults.json 에 없어도 getConfigValues() 에 있으면 해석 가능하다(gdpr 처럼).
     *
     * @scenario condition_operator=truthy, condition_source=plugin_setting, condition_state=met
     *
     * @effects extension_setting_resolvable_from_config_values
     *
     * @return void 반환값 없음
     */
    public function test_extension_setting_resolvable_from_config_values(): void
    {
        $plugin = Mockery::mock(PluginInterface::class);
        $plugin->shouldReceive('getSettingsDefaultsPath')->andReturn(null);
        $plugin->shouldReceive('getConfigValues')->andReturn(['annual_notice' => ['enabled' => true]]);

        $manager = Mockery::mock(PluginManager::class);
        $manager->shouldReceive('getPlugin')->with('vendor-gdpr')->andReturn($plugin);
        $manager->shouldReceive('getPlugin')->with('vendor-missing')->andReturn(null);
        $this->app->instance(PluginManager::class, $manager);

        $condition = ['source' => 'plugin_setting', 'key' => 'annual_notice.enabled', 'operator' => 'truthy', 'default' => true];
        $this->assertTrue($this->evaluator->isResolvable($condition, 'vendor-gdpr'));
        $this->assertTrue($this->evaluator->isResolvable($condition + ['identifier' => 'vendor-gdpr']));
        $this->assertFalse($this->evaluator->isResolvable(['source' => 'plugin_setting', 'key' => 'annual_notice.nope', 'operator' => 'truthy'], 'vendor-gdpr'));
        $this->assertFalse($this->evaluator->isResolvable($condition, 'vendor-missing'));
    }
}
