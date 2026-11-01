<?php

namespace Tests\Feature\Console\Settings;

use App\Extension\ModuleManager;
use App\Services\ModuleSettingsService;
use App\Services\SettingsConsoleService;
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
     * 게시판 기본 권한 「비밀글 읽기」(`posts.read-secret`)는 역할 목록일 뿐이다 — 이름 끝의 secret 때문에
     * 민감 값으로 가리면 안 된다. 진짜 비밀 이름(`client_secret`·`api_token`·`smtppassword`)은 계속 가린다.
     *
     * @effects module_settings_cli_get_does_not_mask_permission_named_secret
     *
     * @return void 반환값 없음
     */
    public function test_board_permission_named_secret_is_not_masked(): void
    {
        $this->artisan('settings:get', ['key' => 'basic_defaults.default_board_permissions', '--module' => 'sirsoft-board', '--json' => true])
            ->expectsOutputToContain('"basic_defaults.default_board_permissions.posts.read-secret": [')
            ->doesntExpectOutputToContain(SettingsConsoleService::MASK)
            ->assertExitCode(0);

        $service = app(SettingsConsoleService::class);
        $this->assertFalse($service->isSensitive('basic_defaults.default_board_permissions.posts.read-secret', module: 'sirsoft-board'));
        $this->assertFalse($service->isSensitive('basic_defaults.default_board_permissions.admin.posts.read-secret', module: 'sirsoft-board'));
        $this->assertFalse($service->isSensitive('basic_defaults.secret_mode', module: 'sirsoft-board'));
        $this->assertTrue($service->isSensitive('integration.client_secret', module: 'sirsoft-board'));
        $this->assertTrue($service->isSensitive('integration.api_token', module: 'sirsoft-board'));
        $this->assertTrue($service->isSensitive('integration.smtppassword', module: 'sirsoft-board'));
        $this->assertTrue($service->isSensitive('integration.secret', module: 'sirsoft-board'));
    }

    /**
     * 표에 보인 권한 키(이름 안에 점이 든 `posts.read`)를 그대로 다시 조회·지정할 수 있다 — 지정은 그 권한 하나만 바꾼다.
     *
     * @effects module_settings_cli_dotted_permission_key_round_trips
     *
     * @return void 반환값 없음
     */
    public function test_board_permission_key_containing_dot_can_be_read_and_set(): void
    {
        $this->artisan('settings:get', ['key' => 'basic_defaults.default_board_permissions.posts.read', '--module' => 'sirsoft-board', '--json' => true])
            ->expectsOutputToContain('"basic_defaults.default_board_permissions.posts.read": [')
            ->assertExitCode(0);

        $service = app(ModuleSettingsService::class)->resolveModuleService('sirsoft-board');
        $before = (array) $service->getSetting('basic_defaults.default_board_permissions');

        $this->artisan('settings:set', [
            'key' => 'basic_defaults.default_board_permissions.posts.read-secret',
            'value' => '["admin","user"]',
            '--json-value' => true,
            '--module' => 'sirsoft-board',
        ])->assertExitCode(0);

        $after = (array) app(ModuleSettingsService::class)->resolveModuleService('sirsoft-board')->getSetting('basic_defaults.default_board_permissions');
        $this->assertSame(['admin', 'user'], $after['posts.read-secret'] ?? null);
        $this->assertArrayNotHasKey('posts', $after, '점이 든 권한 이름을 중첩 배열로 쪼개 저장하면 안 된다');
        unset($before['posts.read-secret'], $after['posts.read-secret']);
        $this->assertSame($before, $after, '다른 권한은 그대로');
    }

    /**
     * 모듈 설정 저장은 설정 캐시 파일을 다시 만들지 않는다 — 「설정 캐시를 다시 만들었습니다」를 내지 않는다.
     *
     * @effects module_settings_cli_does_not_claim_config_cache_rebuild
     *
     * @return void 반환값 없음
     */
    public function test_module_set_does_not_claim_config_cache_rebuild(): void
    {
        $this->artisan('settings:set', ['key' => 'basic_defaults.per_page', 'value' => '25', '--module' => 'sirsoft-board'])
            ->doesntExpectOutputToContain(__('settings.console.cli.cache_rebuilt'))
            ->assertExitCode(0);
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
