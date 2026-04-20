<?php

namespace Tests\Unit\Extension\Vendor;

use App\Extension\Vendor\EnvironmentDetector;
use App\Extension\Vendor\Exceptions\VendorInstallException;
use App\Extension\Vendor\VendorBundleInstaller;
use App\Extension\Vendor\VendorBundler;
use App\Extension\Vendor\VendorInstallContext;
use App\Extension\Vendor\VendorInstallResult;
use App\Extension\Vendor\VendorIntegrityChecker;
use App\Extension\Vendor\VendorMode;
use App\Extension\Vendor\VendorResolver;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class VendorResolverTest extends TestCase
{
    private string $testDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testDir = storage_path('app/test-vendor-resolver-'.uniqid());
        File::ensureDirectoryExists($this->testDir);
    }

    protected function tearDown(): void
    {
        if (File::isDirectory($this->testDir)) {
            File::deleteDirectory($this->testDir);
        }
        parent::tearDown();
    }

    private function makeResolver(bool $canExecuteComposer): VendorResolver
    {
        $env = new class($canExecuteComposer) extends EnvironmentDetector
        {
            public function __construct(private bool $canExec) {}

            public function canExecuteComposer(?string $hint = null): bool
            {
                return $this->canExec;
            }
        };

        $checker = new VendorIntegrityChecker;

        return new VendorResolver(
            environmentDetector: $env,
            integrityChecker: $checker,
            bundleInstaller: new VendorBundleInstaller($checker),
        );
    }

    private function createValidBundle(): void
    {
        $zipPath = $this->testDir.'/vendor-bundle.zip';
        $zip = new \ZipArchive;
        $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('vendor/autoload.php', '<?php // test');
        $zip->close();

        File::put($this->testDir.'/vendor-bundle.json', json_encode([
            'schema_version' => VendorBundler::SCHEMA_VERSION,
            'zip_sha256' => hash_file('sha256', $zipPath),
            'package_count' => 1,
        ]));
    }

    private function makeContext(VendorMode $requested = VendorMode::Auto, ?VendorMode $previous = null, string $operation = 'install'): VendorInstallContext
    {
        return new VendorInstallContext(
            target: 'module',
            identifier: 'test-mod',
            sourceDir: $this->testDir,
            targetDir: $this->testDir,
            requestedMode: $requested,
            previousMode: $previous,
            operation: $operation,
        );
    }

    public function test_resolves_explicit_composer_mode(): void
    {
        $resolver = $this->makeResolver(canExecuteComposer: true);
        $ctx = $this->makeContext(requested: VendorMode::Composer);

        $this->assertSame(VendorMode::Composer, $resolver->resolveMode($ctx));
    }

    public function test_resolves_explicit_bundled_mode(): void
    {
        $resolver = $this->makeResolver(canExecuteComposer: true);
        $ctx = $this->makeContext(requested: VendorMode::Bundled);

        $this->assertSame(VendorMode::Bundled, $resolver->resolveMode($ctx));
    }

    public function test_auto_resolves_to_composer_when_available(): void
    {
        $resolver = $this->makeResolver(canExecuteComposer: true);
        $ctx = $this->makeContext();

        $this->assertSame(VendorMode::Composer, $resolver->resolveMode($ctx));
    }

    public function test_auto_resolves_to_bundled_when_composer_unavailable_but_bundle_exists(): void
    {
        $this->createValidBundle();
        $resolver = $this->makeResolver(canExecuteComposer: false);
        $ctx = $this->makeContext();

        $this->assertSame(VendorMode::Bundled, $resolver->resolveMode($ctx));
    }

    public function test_auto_throws_when_neither_composer_nor_bundle_available(): void
    {
        $resolver = $this->makeResolver(canExecuteComposer: false);
        $ctx = $this->makeContext();

        $this->expectException(VendorInstallException::class);
        $resolver->resolveMode($ctx);
    }

    public function test_update_inherits_previous_mode(): void
    {
        $resolver = $this->makeResolver(canExecuteComposer: true);
        $ctx = $this->makeContext(
            requested: VendorMode::Auto,
            previous: VendorMode::Bundled,
            operation: 'update',
        );

        $this->assertSame(VendorMode::Bundled, $resolver->resolveMode($ctx));
    }

    public function test_update_does_not_inherit_auto_previous_mode(): void
    {
        $resolver = $this->makeResolver(canExecuteComposer: true);
        $ctx = $this->makeContext(
            requested: VendorMode::Auto,
            previous: VendorMode::Auto,
            operation: 'update',
        );

        // auto → auto 해석 → composer 가능하므로 composer 반환
        $this->assertSame(VendorMode::Composer, $resolver->resolveMode($ctx));
    }

    public function test_install_throws_when_composer_mode_but_executor_missing(): void
    {
        $resolver = $this->makeResolver(canExecuteComposer: true);
        $ctx = $this->makeContext(requested: VendorMode::Composer);

        $this->expectException(VendorInstallException::class);
        $resolver->install($ctx, composerExecutor: null);
    }

    public function test_install_delegates_to_composer_executor_callback(): void
    {
        $resolver = $this->makeResolver(canExecuteComposer: true);
        $ctx = $this->makeContext(requested: VendorMode::Composer);

        $called = false;
        $executor = function (VendorInstallContext $c) use (&$called) {
            $called = true;

            return new VendorInstallResult(
                mode: VendorMode::Composer,
                strategy: 'composer',
                packageCount: 10,
            );
        };

        $result = $resolver->install($ctx, $executor);

        $this->assertTrue($called);
        $this->assertSame(VendorMode::Composer, $result->mode);
        $this->assertSame(10, $result->packageCount);
    }

    public function test_install_executes_bundle_strategy_end_to_end(): void
    {
        $this->createValidBundle();
        $resolver = $this->makeResolver(canExecuteComposer: false);
        $ctx = $this->makeContext(requested: VendorMode::Bundled);

        $result = $resolver->install($ctx);

        $this->assertSame(VendorMode::Bundled, $result->mode);
        $this->assertFileExists($this->testDir.'/vendor/autoload.php');
    }

    public function test_install_fails_when_bundled_mode_but_no_zip(): void
    {
        $resolver = $this->makeResolver(canExecuteComposer: true);
        $ctx = $this->makeContext(requested: VendorMode::Bundled);

        $this->expectException(VendorInstallException::class);
        $resolver->install($ctx);
    }
}
