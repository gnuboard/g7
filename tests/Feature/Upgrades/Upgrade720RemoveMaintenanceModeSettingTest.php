<?php

namespace Tests\Feature\Upgrades;

use App\Extension\UpgradeContext;
use App\Http\Requests\Settings\SaveSettingsRequest;
use App\Support\EnvPriority;
use App\Upgrades\Data\V7_2_0\Migrations\RemoveMaintenanceModeSetting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 읽히지 않던 점검 모드 설정(`general.maintenance_mode`) 제거 (#888)
 *
 * 점검 모드는 `php artisan down` / `php artisan up` 이 맡는다. 설정 키는 기본값에 있었지만 아무 코드도 읽지 않아,
 * 켜 두어도 사이트가 막히지 않았다. 7.2.0 은 기본값·저장 검증에서 키를 없애고, 업그레이드 데이터 스텝이 저장 파일에
 * 남은 값을 지운다. 다른 키를 건드리거나 두 번째 실행에서 파일을 다시 쓰면 안 된다.
 */
class Upgrade720RemoveMaintenanceModeSettingTest extends TestCase
{
    use DatabaseTransactions;

    /** @var string 이 시험 전용 settings 디스크 루트 */
    private string $settingsRoot;

    /**
     * 테스트 환경 준비 — 데이터 스텝 적재와 전용 settings 디스크.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 업그레이드 데이터 스텝은 오토로드되지 않는다 — 실행 시점과 같은 방식으로 싣는다.
        require_once base_path('upgrades/data/7.2.0/migrations/10_RemoveMaintenanceModeSetting.php');

        // 공용 가짜 디스크 경로는 다른 PHPUnit 프로세스의 Storage::fake() 가 지울 수 있다 — 이 시험만의 루트를 쓴다.
        $this->settingsRoot = storage_path('framework/testing/upgrade720-maintenance-'.uniqid());
        File::ensureDirectoryExists($this->settingsRoot);
        Storage::set('settings', Storage::build(['driver' => 'local', 'root' => $this->settingsRoot]));
    }

    /**
     * 전용 settings 디스크를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        File::deleteDirectory($this->settingsRoot);

        parent::tearDown();
    }

    /**
     * 기본값·저장 검증·환경변수 대응표·문서 설명 어디에도 점검 모드 설정 키가 없다.
     *
     * @scenario case=settings_schema
     *
     * @effects maintenance_mode_setting_key_absent
     *
     * @return void 반환값 없음
     */
    public function test_maintenance_mode_key_is_gone_from_settings_surface(): void
    {
        $defaults = json_decode((string) file_get_contents(config_path('settings/defaults.json')), true);
        $this->assertIsArray($defaults);
        $this->assertArrayNotHasKey('maintenance_mode', $defaults['defaults']['general'], '기본값에 점검 모드 키가 남아 있다');

        $request = SaveSettingsRequest::create('/api/admin/settings', 'POST', ['_tab' => 'general']);
        $this->assertArrayNotHasKey('general.maintenance_mode', $request->rules(), '저장 검증에 점검 모드 키가 남아 있다');
        $this->assertArrayNotHasKey('general.maintenance_mode', $request->attributes());

        $this->assertArrayNotHasKey('general.maintenance_mode', EnvPriority::EXEMPT);
    }

    /**
     * 저장 파일에 키가 있으면 그 키만 지우고, 두 번째 실행은 파일을 다시 쓰지 않는다.
     *
     * @scenario case=step_key_present
     *
     * @effects stored_maintenance_mode_key_removed, other_general_keys_kept, removal_is_idempotent
     *
     * @return void 반환값 없음
     */
    public function test_step_removes_stored_key_and_is_idempotent(): void
    {
        $general = [
            '_meta' => ['version' => '1.0.0'],
            'site_name' => '사이트',
            'site_url' => 'https://example.com',
            'maintenance_mode' => true,
            'site_logo' => [],
        ];
        Storage::disk('settings')->put('general.json', json_encode($general, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->runStep();

        $after = json_decode((string) Storage::disk('settings')->get('general.json'), true);
        $this->assertArrayNotHasKey('maintenance_mode', $after);
        unset($general['maintenance_mode']);
        $this->assertSame($general, $after, '점검 모드 키 말고 다른 키가 바뀌었다');

        // 멱등 — 두 번째 실행은 키가 없어 파일을 다시 쓰지 않는다(수정 시각 대신 내용과 바이트로 확인).
        $firstBytes = Storage::disk('settings')->get('general.json');
        $this->runStep();
        $this->assertSame($firstBytes, Storage::disk('settings')->get('general.json'));
    }

    /**
     * 파일이 없거나, 키가 없거나, 깨진 파일이면 아무것도 쓰지 않는다.
     *
     * @scenario case=step_key_absent_or_broken
     *
     * @effects removal_skips_missing_or_broken_file
     *
     * @return void 반환값 없음
     */
    public function test_step_leaves_missing_or_broken_files_alone(): void
    {
        // 파일 없음 — 만들지 않는다.
        $this->runStep();
        $this->assertFalse(Storage::disk('settings')->exists('general.json'));

        // 키 없음 — 내용 그대로.
        $clean = json_encode(['site_name' => '사이트'], JSON_PRETTY_PRINT);
        Storage::disk('settings')->put('general.json', $clean);
        $this->runStep();
        $this->assertSame($clean, Storage::disk('settings')->get('general.json'));

        // 깨진 파일 — 고치지 않는다.
        Storage::disk('settings')->put('general.json', '{not json');
        $this->runStep();
        $this->assertSame('{not json', Storage::disk('settings')->get('general.json'));
    }

    /**
     * 데이터 스텝을 7.1.x → 7.2.0 컨텍스트로 실행합니다.
     *
     * @return void 반환값 없음
     */
    private function runStep(): void
    {
        (new RemoveMaintenanceModeSetting)->run(new UpgradeContext(
            fromVersion: '7.1.1',
            toVersion: '7.2.0',
            currentStep: '7.2.0',
            logChannel: 'upgrade',
        ));
    }
}
