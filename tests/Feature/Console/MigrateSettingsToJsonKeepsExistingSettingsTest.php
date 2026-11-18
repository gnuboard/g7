<?php

namespace Tests\Feature\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * `settings:migrate-to-json` 이 옮길 옛 설정이 없을 때 기존 설정 JSON 을 기본값으로 덮지 않는다.
 *
 * 7.0 이후 설치본에는 옛 설정 표(`system_configs`)가 없다. 종전에는 그 경우(그리고 표가 비었을 때) 기존
 * `storage/app/settings/*.json` 의 존재·`--force`·확인을 보지 않고 전 설정을 기본값으로 저장하고 종료 코드 0 으로 끝났다 —
 * 터미널 없이 한 번 실행하자 사이트 이름·주소·관리자 이메일·메일 발신자·드라이버·업로드 값이 기본값이 됐다(7.2.3 실측).
 *
 * 이 시험이 잠그는 것:
 *  - 옮길 옛 설정이 없고 설정 파일이 이미 있으면 아무것도 쓰지 않고 그 사실을 알린다(종료 0).
 *  - 설정 파일이 없으면 종전처럼 기본값 파일을 만든다(새 설치).
 *  - 기본값으로 되돌리기는 `--force` 와 확인이 함께 있을 때만 — 확인에 답할 수 없으면 거절(종료 1), 덮기 전에 사본을 남긴다.
 *
 * 시나리오 SSoT: tests/scenarios/settings-migrate-to-json-keeps-existing.yaml
 */
class MigrateSettingsToJsonKeepsExistingSettingsTest extends TestCase
{
    /** 운영 중인 설정 파일 본문 */
    private const OPERATOR_GENERAL = '{"site_name":"G7 R3 리허설B","site_url":"https://r3.example.test","admin_email":"ops@example.test"}';

    /** 시험이 만든 옛 설정 표 */
    private bool $createdTable = false;

    /**
     * 시험이 만든 옛 설정 표를 지운다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        if ($this->createdTable) {
            Schema::dropIfExists('system_configs');
        }

        parent::tearDown();
    }

    /**
     * 실측 재현: 옛 설정 표가 없는 운영 사이트에서 터미널 없이 실행해도 기존 설정 파일을 덮지 않고 그 사실을 알린다.
     * 대화형 실행도 묻지 않고 같다.
     *
     * @scenario case=no_table_existing_settings
     *
     * @effects existing_settings_kept_when_nothing_to_migrate, kept_notice_printed
     *
     * @return void 반환값 없음
     */
    public function test_existing_settings_are_kept_when_the_legacy_table_is_missing(): void
    {
        $this->assertFalse(Schema::hasTable('system_configs'), '전제: 7.0 이후 설치본처럼 옛 설정 표가 없다');
        $this->seedOperatorSettings();

        $this->artisan('settings:migrate-to-json', ['--no-interaction' => true])
            ->expectsOutputToContain((string) __('settings.console.migrate_to_json.existing_kept'))
            ->doesntExpectOutputToContain((string) __('settings.console.migrate_to_json.defaults_created'))
            ->assertExitCode(Command::SUCCESS);

        $this->assertSame(self::OPERATOR_GENERAL, Storage::disk('settings')->get('general.json'), '운영 설정 파일을 기본값으로 덮었다');

        $this->artisan('settings:migrate-to-json')
            ->expectsOutputToContain((string) __('settings.console.migrate_to_json.existing_kept'))
            ->assertExitCode(Command::SUCCESS);

        $this->assertSame(self::OPERATOR_GENERAL, Storage::disk('settings')->get('general.json'));
    }

    /**
     * 옛 설정 표가 있지만 비어 있을 때도(확인을 받았어도) 옮길 것이 없으므로 기존 설정 파일을 덮지 않는다.
     *
     * @scenario case=empty_table_existing_settings
     *
     * @effects existing_settings_kept_when_nothing_to_migrate
     *
     * @return void 반환값 없음
     */
    public function test_existing_settings_are_kept_when_the_legacy_table_is_empty(): void
    {
        $this->createLegacyTable();
        $this->seedOperatorSettings();

        $this->artisan('settings:migrate-to-json', ['--no-interaction' => true, '--yes' => true])
            ->expectsOutputToContain((string) __('settings.console.migrate_to_json.existing_kept'))
            ->assertExitCode(Command::SUCCESS);

        $this->assertSame(self::OPERATOR_GENERAL, Storage::disk('settings')->get('general.json'), '빈 옛 설정 표 때문에 운영 설정 파일을 기본값으로 덮었다');
    }

    /**
     * 설정 파일이 하나도 없으면(새 설치) 종전처럼 기본값 파일을 만든다.
     *
     * @scenario case=no_table_no_settings
     *
     * @effects defaults_created_on_fresh_install
     *
     * @return void 반환값 없음
     */
    public function test_defaults_are_created_when_no_settings_exist(): void
    {
        $this->assertSame([], array_values(array_filter(Storage::disk('settings')->files(), fn (string $f): bool => str_ends_with($f, '.json'))), '전제: 설정 파일이 없다');

        $this->artisan('settings:migrate-to-json', ['--no-interaction' => true])
            ->expectsOutputToContain((string) __('settings.console.migrate_to_json.defaults_created'))
            ->assertExitCode(Command::SUCCESS);

        $this->assertTrue(Storage::disk('settings')->exists('general.json'));
    }

    /**
     * 기본값으로 되돌리기는 `--force` 와 확인이 함께 있어야 한다 — `--force` 만으로 터미널 없이 실행하면 거절(종료 1)하고
     * 아무것도 쓰지 않는다. `--force --yes` 면 덮기 전 사본을 남기고 기본값으로 되돌린다.
     *
     * @scenario case=force_reset
     *
     * @effects force_reset_requires_confirmation, reset_keeps_a_copy_before_overwrite
     *
     * @return void 반환값 없음
     */
    public function test_force_reset_requires_confirmation_and_keeps_a_copy(): void
    {
        $this->seedOperatorSettings();

        $this->artisan('settings:migrate-to-json', ['--no-interaction' => true, '--force' => true])
            ->expectsOutputToContain((string) __('settings.console.migrate_to_json.reset_unavailable'))
            ->assertExitCode(Command::FAILURE);

        $this->assertSame(self::OPERATOR_GENERAL, Storage::disk('settings')->get('general.json'), '확인 없이 기본값으로 되돌렸다');

        $this->artisan('settings:migrate-to-json', ['--no-interaction' => true, '--force' => true, '--yes' => true])
            ->assertExitCode(Command::SUCCESS);

        $general = json_decode((string) Storage::disk('settings')->get('general.json'), true);
        $this->assertNotSame('G7 R3 리허설B', $general['site_name'] ?? null, '확인을 받았으면 기본값으로 되돌린다');

        $copies = array_values(array_filter(Storage::disk('settings')->allFiles('backups'), fn (string $f): bool => str_ends_with($f, '/general.json')));
        $this->assertCount(1, $copies, '덮기 전 사본을 남기지 않았다');
        $this->assertSame(self::OPERATOR_GENERAL, Storage::disk('settings')->get($copies[0]));
    }

    /**
     * 운영 중인 설정 파일을 설정 디스크에 둔다.
     *
     * @return void 반환값 없음
     */
    private function seedOperatorSettings(): void
    {
        Storage::disk('settings')->put('general.json', self::OPERATOR_GENERAL);
        Storage::disk('settings')->put('mail.json', '{"from_address":"noreply@example.test","from_name":"R3"}');
    }

    /**
     * 비어 있는 옛 설정 표를 만든다(7.0 이전 구조의 최소 칼럼).
     *
     * @return void 반환값 없음
     */
    private function createLegacyTable(): void
    {
        Schema::create('system_configs', function (Blueprint $table): void {
            $table->id();
            $table->string('key');
            $table->text('value')->nullable();
            $table->string('type')->default('string');
            $table->timestamps();
        });
        $this->createdTable = true;
    }
}
