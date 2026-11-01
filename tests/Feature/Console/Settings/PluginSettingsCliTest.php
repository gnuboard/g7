<?php

namespace Tests\Feature\Console\Settings;

use App\Services\PluginSettingsService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * `settings:set --plugin=` — 플러그인 설정 화면 저장과 같은 FormRequest(`UpdatePluginSettingsRequest`)·같은 저장
 * (`PluginSettingsService::save()` — 민감 값 암호화)을 탄다. 플러그인 저장 디스크는 페이크로 격리한다.
 */
class PluginSettingsCliTest extends TestCase
{
    use DatabaseTransactions;

    /** 대상 플러그인 (민감 필드를 가진 번들 결제 플러그인) */
    private const PLUGIN = 'sirsoft-tosspayments';

    /**
     * 플러그인 저장 디스크 격리.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('plugins');
        app(PluginSettingsService::class)->clearCache();
    }

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
     * 민감 값은 환경변수로 받아 암호문으로 저장되고, 조회는 복호화된 값을 돌려준다.
     *
     * @effects plugin_settings_cli_encrypts_sensitive_value
     *
     * @return void 반환값 없음
     */
    public function test_sensitive_value_is_stored_encrypted(): void
    {
        putenv('G7_SETTING_VALUE=test_sk_cli_measure_value');

        $this->artisan('settings:set', ['key' => 'test_secret_key', '--plugin' => self::PLUGIN])
            ->doesntExpectOutputToContain('test_sk_cli_measure_value')
            ->assertExitCode(0);

        $files = Storage::disk('plugins')->allFiles();
        $this->assertNotEmpty($files);
        foreach ($files as $file) {
            $this->assertStringNotContainsString('test_sk_cli_measure_value', (string) Storage::disk('plugins')->get($file), '평문이 파일에 남으면 안 된다');
        }

        app(PluginSettingsService::class)->clearCache(self::PLUGIN);
        $this->assertSame('test_sk_cli_measure_value', app(PluginSettingsService::class)->get(self::PLUGIN, 'test_secret_key'));
    }

    /**
     * 민감 값을 위치 인자로 주면 거부한다.
     *
     * @effects plugin_settings_cli_rejects_sensitive_positional
     *
     * @return void 반환값 없음
     */
    public function test_sensitive_positional_value_is_rejected(): void
    {
        $this->artisan('settings:set', ['key' => 'live_secret_key', 'value' => 'live_sk_plain', '--plugin' => self::PLUGIN])
            ->assertExitCode(1);

        $this->assertEmpty(Storage::disk('plugins')->allFiles());
    }

    /**
     * 스키마 검증(정수 항목에 문자)은 화면과 같이 거부한다.
     *
     * @effects plugin_settings_cli_validates_schema
     *
     * @return void 반환값 없음
     */
    public function test_schema_validation_rejects_wrong_type(): void
    {
        $this->artisan('settings:set', ['key' => 'vbank_valid_hours', 'value' => 'many', '--plugin' => self::PLUGIN])
            ->expectsOutputToContain('vbank_valid_hours')
            ->assertExitCode(1);
    }

    /**
     * 일반 값은 저장되고 다른 키는 그대로 남는다.
     *
     * @effects plugin_settings_cli_saves_only_changed_key
     *
     * @return void 반환값 없음
     */
    public function test_saves_value_and_keeps_other_keys(): void
    {
        $service = app(PluginSettingsService::class);
        $before = $service->get(self::PLUGIN, 'method_card');

        $this->artisan('settings:set', ['key' => 'vbank_valid_hours', 'value' => '48', '--plugin' => self::PLUGIN])->assertExitCode(0);

        $service->clearCache(self::PLUGIN);
        $this->assertSame(48, $service->get(self::PLUGIN, 'vbank_valid_hours'));
        $this->assertSame($before, $service->get(self::PLUGIN, 'method_card'));
    }

    /**
     * 플러그인 설정 저장은 설정 캐시 파일을 다시 만들지 않는다 — 「설정 캐시를 다시 만들었습니다」를 내지 않는다.
     *
     * @effects plugin_settings_cli_does_not_claim_config_cache_rebuild
     *
     * @return void 반환값 없음
     */
    public function test_plugin_set_does_not_claim_config_cache_rebuild(): void
    {
        $this->artisan('settings:set', ['key' => 'vbank_valid_hours', 'value' => '36', '--plugin' => self::PLUGIN])
            ->doesntExpectOutputToContain(__('settings.console.cli.cache_rebuilt'))
            ->assertExitCode(0);
    }

    /**
     * 스키마에 없는 키는 거부한다.
     *
     * @effects plugin_settings_cli_rejects_unknown_key
     *
     * @return void 반환값 없음
     */
    public function test_unknown_key_is_rejected(): void
    {
        $this->artisan('settings:set', ['key' => 'no_such_field', 'value' => '1', '--plugin' => self::PLUGIN])->assertExitCode(1);
    }
}
