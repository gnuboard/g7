<?php

namespace Tests\Feature\Console\Settings;

use App\Contracts\Repositories\ConfigRepositoryInterface;
use Tests\TestCase;

/**
 * `settings:get` — 민감 값 가림·`--show-secrets`·계산값 표시·JSON 출력.
 *
 * 설정 디스크는 TestCase 가 페이크로 바꿔 둔다(DB 를 쓰지 않는다).
 */
class SettingsGetCommandTest extends TestCase
{
    /**
     * 민감 값을 심어 둔다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $repository = app(ConfigRepositoryInterface::class);
        $repository->saveCategory('drivers', array_merge($repository->getCategory('drivers'), ['s3_secret_key' => 'sk-real-value']));
    }

    /**
     * 민감 값은 기본으로 가린다.
     *
     * @effects settings_get_masks_sensitive_values
     *
     * @return void 반환값 없음
     */
    public function test_masks_sensitive_value_by_default(): void
    {
        $this->artisan('settings:get', ['key' => 'drivers.s3_secret_key'])
            ->expectsOutputToContain('********')
            ->doesntExpectOutputToContain('sk-real-value')
            ->assertExitCode(0);
    }

    /**
     * `--show-secrets` 면 원문과 경고 한 줄.
     *
     * @effects settings_get_show_secrets_prints_raw_with_warning
     *
     * @return void 반환값 없음
     */
    public function test_show_secrets_prints_raw_value_with_warning(): void
    {
        $this->artisan('settings:get', ['key' => 'drivers.s3_secret_key', '--show-secrets' => true])
            ->expectsOutputToContain(__('settings.console.cli.secrets_shown'))
            ->expectsOutputToContain('sk-real-value')
            ->assertExitCode(0);
    }

    /**
     * 계산 항목은 「계산값」으로 표시한다.
     *
     * @effects settings_get_marks_computed_values
     *
     * @return void 반환값 없음
     */
    public function test_marks_computed_values(): void
    {
        // 한 줄(표의 한 행)은 기대 하나만 소비하므로, 계산 항목 행의 「계산값」 표시 하나로 본다.
        $this->artisan('settings:get', ['key' => 'general.site_logo_url'])
            ->expectsOutputToContain(__('settings.console.cli.computed_marker'))
            ->assertExitCode(0);
    }

    /**
     * `--json` 은 「키 => 값」 JSON (민감 값은 가린 채).
     *
     * @effects settings_get_json_output
     *
     * @return void 반환값 없음
     */
    public function test_json_output(): void
    {
        $this->artisan('settings:get', ['key' => 'drivers', '--json' => true])
            ->expectsOutputToContain('"drivers.s3_secret_key": "********"')
            ->assertExitCode(0);
    }

    /**
     * 없는 키는 실패한다.
     *
     * @effects settings_get_unknown_key_fails
     *
     * @return void 반환값 없음
     */
    public function test_unknown_key_fails(): void
    {
        $this->artisan('settings:get', ['key' => 'nope.nothing'])->assertExitCode(1);
    }
}
