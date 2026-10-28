<?php

namespace Tests\Feature\Api\Admin;

use App\Contracts\Extension\CacheInterface;
use App\Enums\ExtensionOwnerType;
use App\Extension\Helpers\SafeArchiveExtractor;
use App\Extension\Storage\CoreStorageDriver;
use App\Http\Requests\Settings\SaveSettingsRequest;
use App\Http\Resources\ModuleResource;
use App\Http\Resources\PluginResource;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Seo\SitemapWriter;
use App\Seo\SitemapXmlRenderer;
use App\Support\PhpExtensionProbe;
use App\Support\Utf8;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * 코어 PHP 확장 요구 표시·선택 확장 부재 대체 동작 Feature 테스트 (#151 W1-a·W1-d·W4~W7)
 *
 * - 관리자 정보 탭(system-info): 필수 16(bcmath 없음)·선택(bcmath 포함)·확장이 요구한 확장(requested_by)
 * - 모듈·플러그인 리소스: php_extensions 4필드
 * - 선택 확장이 없을 때 500 대신 안내: 설정 백업·복원(zip) 422, SEO 미리보기 UTF-8 정화(iconv), 사이트맵 gzip(zlib)
 *   저장 차단·평문 폴백, GeoIP tar.gz 해제(zlib·phar) 원인 메시지
 *
 * 부재는 PhpExtensionProbe 교체로 흉내 낸다. DB 는 트랜잭션으로 격리한다.
 */
class OptionalPhpExtensionFallbackTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 시스템 정보의 PHP 확장 목록이 SSoT 를 따르고, 확장이 요구한 확장을 함께 싣습니다.
     *
     * @scenario surface=system_info, extension_state=present
     *
     * @effects system_info_lists_follow_ssot_with_requested_by
     *
     * @return void 반환값 없음
     */
    public function test_system_info_lists_follow_ssot(): void
    {
        $this->app->make(CacheInterface::class)->forget('settings.system_info.'.app()->getLocale());

        $response = $this->actingAsAdmin()->getJson('/api/admin/settings/system-info')->assertOk();
        $php = $response->json('data.php_extensions');

        $this->assertCount(16, $php['required']);
        $this->assertArrayNotHasKey('bcmath', $php['required']);
        $this->assertArrayHasKey('zip', $php['required']);
        $this->assertArrayHasKey('pdo_mysql', $php['required']);
        $this->assertArrayHasKey('bcmath', $php['optional']);
        $this->assertArrayHasKey('requested_by', $php);
        $this->assertArrayHasKey('requested', $php);
    }

    /**
     * 모듈·플러그인 리소스가 composer.json 선언과 서버 로드 상태를 php_extensions 로 싣습니다.
     *
     * @scenario surface=extension_resource, extension_state=absent
     *
     * @effects extension_resource_exposes_php_extensions
     *
     * @return void 반환값 없음
     */
    public function test_extension_resources_expose_php_extensions(): void
    {
        $this->probeMissing(['soap']);

        $plugin = (new PluginResource(['identifier' => 'sirsoft-pay_nhnkcp']))->toArray(request());
        $this->assertSame([], $plugin['php_extensions']['required']);
        $this->assertArrayHasKey('soap', $plugin['php_extensions']['suggested']);
        $this->assertSame(['soap'], $plugin['php_extensions']['missing_suggested']);
        $this->assertSame([], $plugin['php_extensions']['missing_required']);

        $module = (new ModuleResource(['identifier' => 'sirsoft-ecommerce']))->toDetailArray();
        $this->assertSame(['required' => [], 'suggested' => [], 'missing_required' => [], 'missing_suggested' => []], $module['php_extensions']);
    }

    /**
     * zip 이 없으면 설정 백업·복원이 500 대신 422 와 원인 안내로 응답합니다.
     *
     * @scenario surface=settings_backup, extension_state=absent
     *
     * @effects backup_restore_without_zip_returns_422
     *
     * @return void 반환값 없음
     */
    public function test_backup_and_restore_without_zip_return_422(): void
    {
        $this->probeMissing(['zip']);
        $message = __('settings.errors.zip_extension_required', ['extension' => 'zip']);
        $admin = $this->actingAsAdmin();

        $admin->postJson('/api/admin/settings/backup')
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', $message);

        Storage::disk('settings')->put('backups/backup_test.zip', 'zip-bytes');
        $admin->postJson('/api/admin/settings/restore', ['backup_path' => 'backups/backup_test.zip'])
            ->assertStatus(422)
            ->assertJsonPath('message', $message);
    }

    /**
     * iconv 유무와 관계없이 깨진 UTF-8 바이트만 떼어 같은 결과를 냅니다.
     *
     * @scenario surface=seo_preview, extension_state=absent
     *
     * @effects utf8_scrub_works_without_iconv
     *
     * @return void 반환값 없음
     */
    public function test_utf8_scrub_same_result_with_or_without_iconv(): void
    {
        $broken = "<p>가나\xE3\x81다</p>";

        $withoutIconv = Utf8::scrub($broken, iconvAvailable: false);
        $this->assertTrue(mb_check_encoding($withoutIconv, 'UTF-8'));
        $this->assertStringContainsString('가나', $withoutIconv);
        $this->assertSame('<p>정상</p>', Utf8::scrub('<p>정상</p>', iconvAvailable: false));

        if (function_exists('iconv')) {
            $this->assertTrue(mb_check_encoding(Utf8::scrub($broken, iconvAvailable: true), 'UTF-8'));
            $this->assertSame('<p>정상</p>', Utf8::scrub('<p>정상</p>', iconvAvailable: true));
        }
    }

    /**
     * zlib(gzencode) 없이 사이트맵 gzip 을 켜는 저장은 필드 오류로 막습니다.
     *
     * @scenario surface=sitemap_gzip_setting, extension_state=absent
     *
     * @effects sitemap_gzip_save_requires_zlib
     *
     * @return void 반환값 없음
     */
    public function test_sitemap_gzip_setting_requires_zlib(): void
    {
        $rules = SaveSettingsRequest::create('/', 'PUT', ['_tab' => 'seo'])->rules()['seo.sitemap_gzip'];

        $this->probeMissing([], functions: ['gzencode']);
        $blocked = Validator::make(['seo' => ['sitemap_gzip' => true]], ['seo.sitemap_gzip' => $rules]);
        $this->assertTrue($blocked->fails());
        $this->assertSame(__('validation.settings.sitemap_gzip_requires_zlib'), $blocked->errors()->first('seo.sitemap_gzip'));
        $this->assertFalse(Validator::make(['seo' => ['sitemap_gzip' => false]], ['seo.sitemap_gzip' => $rules])->fails(), '끄는 저장은 막지 않는다');

        $this->probeMissing([]);
        $this->assertFalse(Validator::make(['seo' => ['sitemap_gzip' => true]], ['seo.sitemap_gzip' => $rules])->fails());
    }

    /**
     * 이미 gzip 이 켜진 상태에서 zlib 이 없으면 생성이 실패하지 않고 압축 없이(.xml) 기록합니다.
     *
     * @scenario surface=sitemap_writer, extension_state=absent
     *
     * @effects sitemap_writer_falls_back_to_plain_xml
     *
     * @return void 반환값 없음
     */
    public function test_sitemap_writer_falls_back_to_plain_xml_without_zlib(): void
    {
        Config::set('app.supported_locales', ['ko']);
        Storage::fake('local');
        $this->probeMissing([], functions: ['gzencode']);

        $writer = new SitemapWriter(new CoreStorageDriver('local'), SitemapXmlRenderer::fromConfig(), 10, true);
        $writer->open();
        $writer->addUrl(['loc' => url('/p/1'), 'priority' => 0.5]);
        $writer->close();
        $result = $writer->commit();

        $this->assertFalse($result['gzip']);
        $this->assertStringEndsWith('.xml', $result['children'][0]['file']);
        $this->assertStringEndsWith('.xml', $result['children'][0]['loc']);
    }

    /**
     * zlib·phar 가 없으면 tar.gz 해제가 「Call to undefined…」 대신 필요한 확장을 알리는 메시지로 멈춥니다.
     *
     * 실제 부재는 별도 PHP 프로세스(disable_functions=gzopen)로 만든다 — 이 프로세스에서는 함수를 지울 수 없다.
     *
     * @scenario surface=geoip_archive, extension_state=absent
     *
     * @effects tar_extract_names_missing_extensions
     *
     * @return void 반환값 없음
     */
    public function test_tar_extract_names_missing_extensions(): void
    {
        $base = base_path();
        $code = 'require '.var_export($base.'/vendor/autoload.php', true).';'
            .' $app = require '.var_export($base.'/bootstrap/app.php', true).';'
            .' $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();'
            .' app()->setLocale("ko");'
            .' try { '.SafeArchiveExtractor::class.'::extractTarGz(__FILE__, sys_get_temp_dir()); } catch (Throwable $e) { echo get_class($e), "|", $e->getMessage(); }';

        $process = new Process([PHP_BINARY, '-d', 'disable_functions=gzopen', '-r', $code], $base);
        $process->run();

        $this->assertStringContainsString('RuntimeException|', $process->getOutput(), $process->getErrorOutput());
        $this->assertStringContainsString(__('common.errors.archive_php_extension_missing', ['extensions' => 'zlib, phar'], 'ko'), $process->getOutput());
        $this->assertStringNotContainsString('undefined function', $process->getOutput());
    }

    /**
     * PhpExtensionProbe 를 교체해 확장·함수 부재를 흉내 냅니다.
     *
     * @param  list<string>  $extensions  없는 것으로 볼 확장
     * @param  list<string>  $functions  없는 것으로 볼 함수
     * @return void 반환값 없음
     */
    private function probeMissing(array $extensions, array $functions = []): void
    {
        $this->app->instance(PhpExtensionProbe::class, new class($extensions, $functions) extends PhpExtensionProbe
        {
            /**
             * 없는 것으로 볼 확장·함수 목록을 받습니다.
             *
             * @param  list<string>  $extensions  없는 것으로 볼 확장
             * @param  list<string>  $functions  없는 것으로 볼 함수
             */
            public function __construct(private array $extensions, private array $functions) {}

            /**
             * 주입한 부재 목록으로 확장 로드 여부를 답합니다.
             *
             * @param  string  $extension  확장 이름
             * @return bool 로드돼 있으면 true
             */
            public function loaded(string $extension): bool
            {
                return ! in_array($extension, $this->extensions, true);
            }

            /**
             * 주입한 부재 목록으로 함수 사용 가능 여부를 답합니다.
             *
             * @param  string  $function  함수 이름
             * @return bool 쓸 수 있으면 true
             */
            public function functionAvailable(string $function): bool
            {
                return ! in_array($function, $this->functions, true);
            }
        });
    }

    /**
     * 설정 수정 권한을 가진 관리자로 인증된 요청을 만듭니다.
     *
     * @return static 인증 헤더가 붙은 테스트 케이스
     */
    private function actingAsAdmin(): static
    {
        $user = User::factory()->create();
        $role = Role::firstOrCreate(['identifier' => 'admin'], [
            'name' => json_encode(['ko' => '관리자', 'en' => 'Administrator']),
            'extension_type' => ExtensionOwnerType::Core,
            'extension_identifier' => 'core',
            'is_active' => true,
        ]);
        foreach (['core.settings.read', 'core.settings.update'] as $identifier) {
            $permission = Permission::firstOrCreate(['identifier' => $identifier], [
                'name' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'type' => 'admin',
            ]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $user->roles()->attach($role->id, ['assigned_at' => now(), 'assigned_by' => null]);

        return $this->withHeaders([
            'Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken,
            'Accept' => 'application/json',
        ]);
    }
}
