<?php

namespace Tests\Feature\Console\Settings;

use App\Contracts\Repositories\ConfigRepositoryInterface;
use App\Services\SettingsCliKeyResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `settings:set` — 관리자 화면 저장과 같은 FormRequest·같은 서비스로 코어 설정 값을 지정한다.
 *
 * 회귀 성격: 설치 후 메일·보안 같은 설정은 관리자 화면에서만 바꿀 수 있어 자동화(프로비저닝)가 불가능했다.
 * 터미널 경로가 화면과 다른 검증·후처리를 타면 같은 값이 경로마다 다르게 저장된다 — 그 동등성을 잠근다.
 * 설정 디스크는 TestCase 가 페이크로 바꿔 둔다.
 */
class SettingsSetCommandTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 정리.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        putenv('G7_SETTING_VALUE');

        parent::tearDown();
    }

    /**
     * 탭 경로 — 범위 밖 값은 화면과 같은 규칙으로 거부하고 저장하지 않는다.
     *
     * @effects settings_set_tab_path_rejects_out_of_range_value
     *
     * @return void 반환값 없음
     */
    public function test_tab_path_rejects_out_of_range_value(): void
    {
        $before = app(ConfigRepositoryInterface::class)->get('security.max_login_attempts');

        $this->artisan('settings:set', ['key' => 'security.max_login_attempts', 'value' => '101'])
            ->expectsOutputToContain('security.max_login_attempts')
            ->assertExitCode(1);

        $this->assertSame($before, app(ConfigRepositoryInterface::class)->get('security.max_login_attempts'));
    }

    /**
     * 탭 경로 — 화면의 교차 검증(관리자 IP 허용 목록 자기잠금)도 같이 탄다. 터미널의 접속 IP 는 서버 자신이다.
     *
     * @effects settings_set_tab_path_runs_cross_validation
     *
     * @return void 반환값 없음
     */
    public function test_tab_path_runs_ip_self_lockout_cross_validation(): void
    {
        $repository = app(ConfigRepositoryInterface::class);
        $repository->saveCategory('security', array_merge($repository->getCategory('security'), ['ip_restriction_enabled' => true]));

        $this->artisan('settings:set', ['key' => 'security.admin_allowed_ips', 'value' => '["10.9.8.7"]', '--json-value' => true])
            ->expectsOutputToContain('security.admin_allowed_ips')
            ->assertExitCode(1);

        $this->assertSame([], (array) $repository->get('security.admin_allowed_ips', []));
    }

    /**
     * 탭 경로 — 저장·설정 미러 갱신(캐시 재생성 경로)·활동 로그(행위자 없음).
     *
     * @effects settings_set_saves_rebuilds_cache_and_logs_without_actor
     *
     * @return void 반환값 없음
     */
    public function test_tab_path_saves_refreshes_mirror_and_logs(): void
    {
        $this->artisan('settings:set', ['key' => 'seo.meta_title_suffix', 'value' => ' | 실측'])
            ->expectsOutputToContain(__('settings.console.cli.cache_rebuilt'))
            ->assertExitCode(0);

        $this->assertSame(' | 실측', app(ConfigRepositoryInterface::class)->get('seo.meta_title_suffix'));
        $this->assertSame(' | 실측', config('g7_settings.core.seo.meta_title_suffix'), '같은 프로세스의 설정 미러도 갱신');

        $row = DB::table('activity_logs')->where('action', 'settings.save')->latest('id')->first();
        $this->assertNotNull($row);
        $this->assertNull($row->user_id);
    }

    /**
     * 탭이 없는 범주는 단건 경로(`UpdateSettingRequest` → `setSetting`)를 탄다.
     *
     * @effects settings_set_single_path_for_category_without_tab
     *
     * @return void 반환값 없음
     */
    public function test_category_without_tab_uses_single_setting_path(): void
    {
        $resolver = \Mockery::mock(SettingsCliKeyResolver::class, [app(ConfigRepositoryInterface::class)])->makePartial();
        $resolver->shouldReceive('tabOf')->andReturn(null);
        $this->app->instance(SettingsCliKeyResolver::class, $resolver);

        $this->artisan('settings:set', ['key' => 'seo.meta_keywords', 'value' => 'g7,cli'])->assertExitCode(0);
        $this->assertSame('g7,cli', app(ConfigRepositoryInterface::class)->get('seo.meta_keywords'));

        // 단건 경로의 형태 검증 — 정수 설정에 문자는 거부
        $this->artisan('settings:set', ['key' => 'security.max_login_attempts', 'value' => 'many'])->assertExitCode(1);
    }

    /**
     * `.env` 가 값을 정하는 키는 거부한다 (탭 저장처럼 조용히 버리지 않는다).
     *
     * @effects settings_set_rejects_env_locked_key
     *
     * @return void 반환값 없음
     */
    public function test_rejects_env_locked_key(): void
    {
        config(['env-priority.enabled' => true, 'env-priority.explicit' => ['MAIL_HOST' => true]]);

        $this->artisan('settings:set', ['key' => 'mail.host', 'value' => 'smtp.example.test'])
            ->expectsOutputToContain('MAIL_HOST')
            ->assertExitCode(1);
    }

    /**
     * 민감 값은 위치 인자로 받지 않는다 (프로세스 목록·셸 기록 노출 방지).
     *
     * @effects settings_set_rejects_sensitive_positional_value
     *
     * @return void 반환값 없음
     */
    public function test_rejects_sensitive_value_as_positional_argument(): void
    {
        $this->artisan('settings:set', ['key' => 'drivers.s3_secret_key', 'value' => 'plain-secret'])
            ->expectsOutputToContain('G7_SETTING_VALUE')
            ->assertExitCode(1);

        $this->assertNotSame('plain-secret', app(ConfigRepositoryInterface::class)->get('drivers.s3_secret_key'));
    }

    /**
     * 배열 값은 --json-value 로 통째로 교체한다 (출력에 명시).
     *
     * @effects settings_set_json_array_value_replaces_whole
     *
     * @return void 반환값 없음
     */
    public function test_json_array_value_replaces_whole_list(): void
    {
        $this->artisan('settings:set', ['key' => 'security.admin_allowed_ips', 'value' => '["10.0.0.1","10.0.0.2"]', '--json-value' => true])
            ->expectsOutputToContain(__('settings.console.cli.array_replaced'))
            ->assertExitCode(0);

        $this->assertSame(['10.0.0.1', '10.0.0.2'], app(ConfigRepositoryInterface::class)->get('security.admin_allowed_ips'));
    }

    /**
     * 다른 값에서 계산되는 항목은 지정할 수 없다.
     *
     * @effects settings_set_rejects_computed_key
     *
     * @return void 반환값 없음
     */
    public function test_rejects_computed_key(): void
    {
        $this->artisan('settings:set', ['key' => 'general.site_logo_url', 'value' => 'x'])
            ->expectsOutputToContain(__('settings.console.cli.computed_key', ['key' => 'general.site_logo_url']))
            ->assertExitCode(1);
    }

    /**
     * 탭↔범주 대응표는 `SaveSettingsRequest` 의 `_tab` 허용 목록과 같다 (탭 신설 시 함께 고치게 잠근다).
     *
     * @effects settings_cli_tab_map_matches_save_request
     *
     * @return void 반환값 없음
     */
    public function test_tab_map_matches_save_settings_request(): void
    {
        $source = (string) file_get_contents(app_path('Http/Requests/Settings/SaveSettingsRequest.php'));
        $this->assertSame(1, preg_match("/'_tab' => \\['nullable', 'string', Rule::in\\(\\[([^\\]]+)\\]\\)\\]/", $source, $m));
        $tabs = array_map(static fn (string $t) => trim($t, " '"), explode(',', $m[1]));

        $this->assertEqualsCanonicalizing(
            array_values(array_diff($tabs, [SettingsCliKeyResolver::ADVANCED_TAB])),
            SettingsCliKeyResolver::TAB_CATEGORIES,
        );
        $this->assertSame('advanced', app(SettingsCliKeyResolver::class)->tabOf('cache'));
        $this->assertSame('advanced', app(SettingsCliKeyResolver::class)->tabOf('geoip'));
        $this->assertNull(app(SettingsCliKeyResolver::class)->tabOf('not_a_category'));
    }
}
