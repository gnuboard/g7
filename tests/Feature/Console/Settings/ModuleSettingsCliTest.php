<?php

namespace Tests\Feature\Console\Settings;

use App\Extension\ModuleManager;
use App\Services\ModuleSettingsService;
use App\Support\ExtensionStoragePath;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Providers\BoardServiceProvider;
use Modules\Sirsoft\Ecommerce\Providers\EcommerceServiceProvider;
use Tests\TestCase;

/**
 * `settings:set --module=` — 모듈이 지정한 관리자 저장 FormRequest(`settingsSaveRequestClass()`)로 검증하고
 * 그 모듈 설정 서비스로 저장한 뒤, 화면 저장과 같은 코어 훅(`core.module_settings.after_save`)을 발화한다.
 *
 * 모듈 설정 파일은 실제 저장 경로(`ExtensionStoragePath::module`)에 쓰이므로, 두 모듈의 설정 디렉터리를
 * 테스트 전에 떠 두고 끝나면 그대로 되돌린다(게시판 테스트 기반의 RestoresBoardSettingsStorage 와 같은 방식).
 */
class ModuleSettingsCliTest extends TestCase
{
    use DatabaseTransactions;

    /** 대상 모듈 */
    private const MODULES = ['sirsoft-board', 'sirsoft-ecommerce'];

    /** @var array<string, array{path: string, files: array<string, string>|null}> 설정 디렉터리 스냅샷 */
    private array $snapshots = [];

    /**
     * 모듈 바인딩 등록 + 설정 디렉터리 스냅샷.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::MODULES as $module) {
            $path = ExtensionStoragePath::module($module, 'settings');
            $this->snapshots[$module] = ['path' => $path, 'files' => is_dir($path) ? $this->readFiles($path) : null];
        }

        $this->app->register(BoardServiceProvider::class);
        $this->app->register(EcommerceServiceProvider::class);
        $this->app->make(ModuleManager::class)->loadModules();
    }

    /**
     * 설정 디렉터리를 테스트 전 상태로 되돌린다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        foreach ($this->snapshots as ['path' => $path, 'files' => $original]) {
            $current = is_dir($path) ? $this->readFiles($path) : [];
            foreach (array_diff_key($current, $original ?? []) as $name => $_) {
                @unlink($path.DIRECTORY_SEPARATOR.$name);
            }
            foreach ($original ?? [] as $name => $content) {
                if (($current[$name] ?? null) !== $content) {
                    file_put_contents($path.DIRECTORY_SEPARATOR.$name, $content);
                }
            }
            if ($original === null && is_dir($path)) {
                @rmdir($path);
            }
        }

        parent::tearDown();
    }

    /**
     * 디렉터리 바로 아래 파일 내용.
     *
     * @param  string  $path  디렉터리
     * @return array<string, string> 파일명 => 내용
     */
    private function readFiles(string $path): array
    {
        $files = [];
        foreach ((array) glob($path.DIRECTORY_SEPARATOR.'*') as $file) {
            if (is_file($file)) {
                $files[basename($file)] = (string) file_get_contents($file);
            }
        }

        return $files;
    }

    /**
     * 게시판 — 저장되고 활동 로그(행위자 없음)가 남는다.
     *
     * @effects module_settings_cli_board_saves_through_form_request
     *
     * @return void 반환값 없음
     */
    public function test_board_setting_is_saved_and_logged(): void
    {
        $this->artisan('settings:set', ['key' => 'basic_defaults.per_page', 'value' => '30', '--module' => 'sirsoft-board'])
            ->assertExitCode(0);

        $service = app(ModuleSettingsService::class)->resolveModuleService('sirsoft-board');
        $this->assertSame(30, (int) $service->getSetting('basic_defaults.per_page'));

        $row = DB::table('activity_logs')->where('action', 'module_settings.save')->latest('id')->first();
        $this->assertNotNull($row);
        $this->assertNull($row->user_id);
    }

    /**
     * 게시판 — FormRequest 규칙 위반(정수 항목에 문자)은 거부한다.
     *
     * @effects module_settings_cli_board_validation_rejects
     *
     * @return void 반환값 없음
     */
    public function test_board_validation_rejects_wrong_type(): void
    {
        $this->artisan('settings:set', ['key' => 'basic_defaults.per_page', 'value' => 'many', '--module' => 'sirsoft-board'])
            ->assertExitCode(1);
    }

    /**
     * 게시판 — 설정 파일에 두지 않는 항목(반응 유형 목록)은 관리자 화면 전용이다.
     *
     * @effects module_settings_cli_rejects_admin_screen_only_key
     *
     * @return void 반환값 없음
     */
    public function test_board_key_outside_settings_file_is_admin_screen_only(): void
    {
        $this->artisan('settings:set', ['key' => 'basic_defaults.reaction_types', 'value' => '[]', '--json-value' => true, '--module' => 'sirsoft-board'])
            ->expectsOutputToContain(__('settings.console.cli.admin_screen_only', ['key' => 'basic_defaults.reaction_types']))
            ->assertExitCode(1);
    }

    /**
     * 이커머스 — 같은 계약으로 저장된다.
     *
     * @effects module_settings_cli_ecommerce_saves_through_form_request
     *
     * @return void 반환값 없음
     */
    public function test_ecommerce_setting_is_saved(): void
    {
        $this->artisan('settings:set', ['key' => 'review_settings.max_images', 'value' => '4', '--module' => 'sirsoft-ecommerce'])
            ->assertExitCode(0);

        $service = app(ModuleSettingsService::class)->resolveModuleService('sirsoft-ecommerce');
        $this->assertSame(4, (int) $service->getSetting('review_settings.max_images'));
    }

    /**
     * 이커머스 — 관리자 화면 저장 규칙(StoreEcommerceSettingsRequest)의 범위 위반(리뷰 이미지 최대 20장)은 거부하고 값은 그대로다.
     *
     * @effects module_settings_cli_ecommerce_validation_rejects
     *
     * @return void 반환값 없음
     */
    public function test_ecommerce_validation_rejects_out_of_range_value(): void
    {
        $service = app(ModuleSettingsService::class)->resolveModuleService('sirsoft-ecommerce');
        $before = $service->getSetting('review_settings.max_images');

        $this->artisan('settings:set', ['key' => 'review_settings.max_images', 'value' => '99', '--module' => 'sirsoft-ecommerce'])
            ->assertExitCode(1);

        $this->assertSame($before, app(ModuleSettingsService::class)->resolveModuleService('sirsoft-ecommerce')->getSetting('review_settings.max_images'));
    }

    /**
     * 저장 FormRequest 를 지정하지 않은 모듈은 거부한다.
     *
     * @effects module_settings_cli_rejects_unsupported_module
     *
     * @return void 반환값 없음
     */
    public function test_module_without_save_request_is_unsupported(): void
    {
        $this->artisan('settings:set', ['key' => 'any.key', 'value' => '1', '--module' => 'sirsoft-page'])
            ->expectsOutputToContain(__('settings.console.cli.module_unsupported', ['id' => 'sirsoft-page']))
            ->assertExitCode(1);
    }
}
