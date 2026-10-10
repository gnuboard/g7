<?php

namespace Tests\Feature\Extension;

use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\TemplateManager;
use App\Models\Module;
use App\Models\Plugin;
use App\Models\Template;
use App\Services\Extension\ExtensionIconResolver;
use App\Services\LicenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 설치된 확장의 아이콘·라이선스가 설치본에서만 읽히는지 검증합니다.
 *
 * 설치된 확장(확장 테이블에 active·inactive 행)은 설치본에 파일이 없으면 없는 것으로 처리하고
 * 동봉 원본(`_bundled`)으로 채우지 않는다. 설치하지 않은 확장만 활성 → `_pending` → `_bundled`
 * 순으로 찾는다 — 목록의 미설치 번들·대기 행이 그림과 라이선스를 보여 줄 수 있어야 하기 때문이다.
 *
 * 소유 시나리오: `installed-copy-reads`
 */
class InstalledCopyIconAndLicenseTest extends TestCase
{
    use RefreshDatabase;

    /** @var string 정사각 유효 SVG */
    private const VALID_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><title>t</title><rect width="64" height="64" fill="#336699"/></svg>';

    /** @var list<string> 정리할 픽스처 디렉토리 (절대 경로) */
    private array $fixtureDirs = [];

    /**
     * 테스트가 만든 픽스처 디렉토리를 지우고 확장 상태 캐시를 비웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        foreach ($this->fixtureDirs as $dir) {
            File::deleteDirectory($dir);
        }
        $this->fixtureDirs = [];

        $this->flushExtensionStatusCache();

        parent::tearDown();
    }

    /**
     * 설치된 모듈의 설치본에 아이콘이 없으면 동봉 원본에 아이콘이 있어도 해석되지 않습니다.
     *
     * 목록 행은 icon_url 이 null(기본 그림)이고 공개 아이콘 주소는 404 다.
     *
     * @scenario surface=extension_icon, install_state=installed_file_only_in_bundled
     *
     * @effects installed_extension_icon_reads_installed_copy_only
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function installed_extension_icon_is_not_filled_from_bundled(): void
    {
        $this->makeExtension('modules/test-icp-icon-gap', []);
        $this->makeExtension('modules/_bundled/test-icp-icon-gap', ['assets/icon.svg' => self::VALID_SVG]);
        Module::factory()->create(['identifier' => 'test-icp-icon-gap', 'status' => 'active']);
        $this->flushExtensionStatusCache();

        $resolver = new ExtensionIconResolver;

        $this->assertNull($resolver->resolve('module', 'test-icp-icon-gap'));
        $this->assertSame(
            ['icon_url' => null, 'icon_source' => 'none'],
            $resolver->describe('module', 'test-icp-icon-gap', '1.0.0')
        );

        $this->get('/api/extensions/module/test-icp-icon-gap/icon')->assertNotFound();
    }

    /**
     * 번들이 아닌 경로로 설치된 확장의 아이콘은 설치본에서 해석됩니다.
     *
     * @scenario surface=extension_icon, install_state=installed_non_bundled
     *
     * @effects installed_extension_icon_reads_installed_copy_only
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function installed_non_bundled_extension_icon_resolves_from_installed_copy(): void
    {
        $this->makeExtension('plugins/test-icp-icon-plug', ['assets/icon.svg' => self::VALID_SVG]);
        Plugin::factory()->create(['identifier' => 'test-icp-icon-plug', 'status' => 'inactive']);
        $this->flushExtensionStatusCache();

        $resolved = (new ExtensionIconResolver)->resolve('plugin', 'test-icp-icon-plug');

        $this->assertNotNull($resolved);
        $this->assertSame('active', $resolved['location']);
    }

    /**
     * 설치하지 않은 번들 확장의 아이콘은 종전대로 동봉 원본에서 해석됩니다.
     *
     * @scenario surface=extension_icon, install_state=not_installed_bundled
     *
     * @effects not_installed_extension_falls_back_to_pending_and_bundled
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function not_installed_bundled_extension_icon_resolves_from_bundled(): void
    {
        $this->makeExtension('templates/_bundled/test-icp-icon-tpl', ['assets/icon.svg' => self::VALID_SVG]);
        $this->flushExtensionStatusCache();

        $resolved = (new ExtensionIconResolver)->resolve('template', 'test-icp-icon-tpl');

        $this->assertNotNull($resolved);
        $this->assertSame('bundled', $resolved['location']);
    }

    /**
     * 설치된 플러그인의 설치본에 LICENSE 가 없으면 동봉 원본의 LICENSE 를 돌려주지 않습니다.
     *
     * @scenario surface=extension_license, install_state=installed_file_only_in_bundled
     *
     * @effects installed_extension_license_reads_installed_copy_only
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function installed_extension_license_is_not_filled_from_bundled(): void
    {
        $this->makeExtension('plugins/test-icp-lic-gap', []);
        $this->makeExtension('plugins/_bundled/test-icp-lic-gap', ['LICENSE' => 'BUNDLED LICENSE']);
        Plugin::factory()->create(['identifier' => 'test-icp-lic-gap', 'status' => 'active']);
        $this->flushExtensionStatusCache();

        $this->assertNull(app(LicenseService::class)->getExtensionLicense('plugins', 'test-icp-lic-gap'));
    }

    /**
     * 번들이 아닌 경로로 설치된 템플릿의 LICENSE 는 설치본에서 읽힙니다.
     *
     * @scenario surface=extension_license, install_state=installed_non_bundled
     *
     * @effects installed_extension_license_reads_installed_copy_only
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function installed_non_bundled_extension_license_reads_installed_copy(): void
    {
        $this->makeExtension('templates/test-icp-lic-tpl', ['LICENSE' => 'INSTALLED LICENSE']);
        Template::factory()->create(['identifier' => 'test-icp-lic-tpl', 'status' => 'active', 'type' => 'user']);
        $this->flushExtensionStatusCache();

        $this->assertSame(
            'INSTALLED LICENSE',
            app(LicenseService::class)->getExtensionLicense('templates', 'test-icp-lic-tpl')
        );
    }

    /**
     * 설치하지 않은 확장의 LICENSE 는 _pending 이 _bundled 보다 먼저 읽힙니다.
     *
     * 미설치 번들 행만 있으면 동봉 원본을, 대기본도 있으면 대기본을 돌려준다(아이콘과 같은 순서).
     *
     * @scenario surface=extension_license, install_state=not_installed_bundled
     *
     * @effects not_installed_extension_falls_back_to_pending_and_bundled
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function not_installed_extension_license_falls_back_to_pending_then_bundled(): void
    {
        $this->makeExtension('modules/_bundled/test-icp-lic-mod', ['LICENSE' => 'BUNDLED LICENSE']);
        $this->flushExtensionStatusCache();

        $service = app(LicenseService::class);
        $this->assertSame('BUNDLED LICENSE', $service->getExtensionLicense('modules', 'test-icp-lic-mod'));

        $this->makeExtension('modules/_pending/test-icp-lic-mod', ['LICENSE' => 'PENDING LICENSE']);
        $this->assertSame('PENDING LICENSE', $service->getExtensionLicense('modules', 'test-icp-lic-mod'));
    }

    /**
     * 확장 루트와 파일 픽스처를 만듭니다.
     *
     * @param  string  $relativeRoot  base_path 기준 확장 루트
     * @param  array<string, string>  $files  루트 기준 상대 경로 → 내용
     * @return void 반환값 없음
     */
    private function makeExtension(string $relativeRoot, array $files): void
    {
        $root = base_path($relativeRoot);
        File::ensureDirectoryExists($root);
        $this->fixtureDirs[] = $root;

        foreach ($files as $relative => $contents) {
            $path = $root.'/'.$relative;
            File::ensureDirectoryExists(dirname($path));
            File::put($path, $contents);
        }
    }

    /**
     * 설치된 확장 식별자 캐시를 비웁니다 — 픽스처 DB 행이 판정에 바로 반영되게 합니다.
     *
     * @return void 반환값 없음
     */
    private function flushExtensionStatusCache(): void
    {
        ModuleManager::invalidateModuleStatusCache();
        PluginManager::invalidatePluginStatusCache();
        TemplateManager::invalidateTemplateStatusCache();
    }
}
