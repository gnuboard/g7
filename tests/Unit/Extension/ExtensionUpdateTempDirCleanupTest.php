<?php

namespace Tests\Unit\Extension;

use App\Contracts\Extension\ModuleInterface;
use App\Contracts\Extension\PluginInterface;
use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Contracts\Repositories\PluginRepositoryInterface;
use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Exceptions\ExtensionUpdateFailedException;
use App\Extension\ExtensionManager;
use App\Extension\HookCacheManager;
use App\Extension\Integrity\PackageVerification;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\TemplateManager;
use App\Models\Module;
use App\Models\Plugin;
use App\Models\Template;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * 확장 업데이트가 백업 단계에서 실패해도 받아 둔 소스 임시 디렉토리를 지운다 (#916)
 *
 * ZIP(`--zip`)·GitHub 소스는 백업보다 먼저 받아 검증하고 `storage/app/temp/{type}_update_*` 에 풀어 둔다. 그 디렉토리는
 * 스테이징 단계의 finally 가 지우는데, 그보다 앞(백업 생성·진행 기록·상태 변경)에서 실패하면 바깥 catch 로 가서 남았다.
 *
 * 실패는 실제 백업 단계에서 낸다 — 활성 디렉토리가 없는 확장의 백업(`ExtensionBackupHelper::createBackup`)은 예외를 던진다.
 * 실패 경로의 캐시 복구는 새 프로세스를 띄우지 않게(없는 PHP 경로) 하고, 훅 캐시 지우기는 목으로 받아 개발 사이트 캐시를 건드리지 않는다.
 */
class ExtensionUpdateTempDirCleanupTest extends TestCase
{
    /** 시험 확장 식별자 (활성 디렉토리를 만들지 않는다) */
    private const IDENTIFIER = 'g7test-tmpclean';

    /**
     * 받아 둔 소스를 흉내 낸 임시 디렉토리
     */
    private string $tempDir;

    /**
     * 임시 소스 디렉토리를 만들고 실패 경로의 캐시 복구를 격리합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = storage_path('app/temp/g7test_update_'.uniqid());
        File::ensureDirectoryExists($this->tempDir.'/extracted');
        File::put($this->tempDir.'/extracted/manifest.json', '{}');

        // 실패 경로의 ExtensionRollbackCacheRepair — 새 프로세스(extension:update-autoload)를 띄우지 않고, 훅 캐시도 지우지 않는다
        Config::set('process.php_binary', storage_path('framework/testing/no-such-php-binary'));
        $this->mock(HookCacheManager::class, fn (MockInterface $mock) => $mock->shouldReceive('clear')->andReturn(true));
    }

    /**
     * 남은 임시 디렉토리를 지우고 목을 닫습니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        if (File::isDirectory($this->tempDir)) {
            File::deleteDirectory($this->tempDir);
        }

        parent::tearDown();
    }

    /**
     * 모듈·플러그인·템플릿 업데이트가 백업 생성에서 실패하면 받아 둔 소스 임시 디렉토리가 남지 않는다.
     *
     * @scenario operation=update_temp_cleanup
     *
     * @effects update_source_temp_dir_removed_when_backup_fails
     *
     * @return void 반환값 없음
     */
    public function test_source_temp_dir_is_removed_when_backup_fails(): void
    {
        foreach (['module', 'plugin', 'template'] as $type) {
            File::ensureDirectoryExists($this->tempDir.'/extracted');
            $this->assertDirectoryExists($this->tempDir, $type);

            $failure = null;
            try {
                $this->runUpdate($type);
            } catch (\Throwable $e) {
                $failure = $e;
            }

            // 바깥 catch(백업·상태 복원 경로)만 ExtensionUpdateFailedException 을 던진다 — 그보다 앞에서 멈춘 것이 아니다
            $this->assertInstanceOf(ExtensionUpdateFailedException::class, $failure, "{$type}: 백업 단계 실패가 아니다 — ".($failure?->getMessage() ?? '예외 없음'));
            $this->assertStringContainsString('g7test-tmpclean', (string) $failure->getPrevious()?->getMessage().$failure->getMessage(), $type);
            $this->assertDirectoryDoesNotExist($this->tempDir, "{$type}: 백업 실패 뒤 받아 둔 소스 임시 디렉토리가 남았다");
        }
    }

    /**
     * 받아 둔 ZIP 의 판이 설치 판보다 낮아 다운그레이드 차단으로 멈춰도 소스 임시 디렉토리가 남지 않는다.
     *
     * 이 차단은 소스 준비 뒤·백업 전에 던진다 — 바깥 catch 를 지나지 않는다.
     *
     * @scenario operation=update_temp_cleanup
     *
     * @effects update_source_temp_dir_removed_when_backup_fails
     *
     * @return void 반환값 없음
     */
    public function test_source_temp_dir_is_removed_when_downgrade_is_blocked(): void
    {
        foreach (['module', 'plugin', 'template'] as $type) {
            File::ensureDirectoryExists($this->tempDir.'/extracted');

            $failure = null;
            try {
                $this->runUpdate($type, '0.9.0');
            } catch (\Throwable $e) {
                $failure = $e;
            }

            $this->assertInstanceOf(\RuntimeException::class, $failure, $type);
            $this->assertStringContainsString('0.9.0', (string) $failure->getMessage(), "{$type}: 다운그레이드 차단이 아니다");
            $this->assertDirectoryDoesNotExist($this->tempDir, "{$type}: 다운그레이드 차단 뒤 받아 둔 소스 임시 디렉토리가 남았다");
        }
    }

    /**
     * 종류별 매니저로 ZIP 업데이트를 실행합니다 (소스 준비는 목, 백업부터는 실제 경로).
     *
     * @param  string  $type  확장 종류 (module|plugin|template)
     * @param  string  $toVersion  받아 둔 소스의 판 (설치 판 1.0.0)
     * @return void 반환값 없음
     */
    private function runUpdate(string $type, string $toVersion = '2.0.0'): void
    {
        $extensionManager = Mockery::mock(ExtensionManager::class)->shouldIgnoreMissing();
        $extensionManager->shouldReceive('prepareVerifiedZipSource')->andReturn([
            'temp_dir' => $this->tempDir,
            'extracted_dir' => $this->tempDir.'/extracted',
            'to_version' => $toVersion,
            'manifest' => ['identifier' => self::IDENTIFIER, 'version' => $toVersion],
            'verification' => PackageVerification::unsigned(),
        ]);

        [$repositoryInterface, $modelClass, $managerClass, $repositoryParam] = match ($type) {
            'module' => [ModuleRepositoryInterface::class, Module::class, ModuleManager::class, 'moduleRepository'],
            'plugin' => [PluginRepositoryInterface::class, Plugin::class, PluginManager::class, 'pluginRepository'],
            'template' => [TemplateRepositoryInterface::class, Template::class, TemplateManager::class, 'templateRepository'],
        };

        /** @var Model $record */
        $record = (new $modelClass)->forceFill(['identifier' => self::IDENTIFIER, 'version' => '1.0.0', 'status' => 'active']);
        $repository = Mockery::mock($repositoryInterface)->shouldIgnoreMissing();
        $repository->shouldReceive('findByIdentifier')->andReturn($record);
        $repository->shouldReceive('updateByIdentifier')->andReturn(true);

        // 컨테이너는 매니저를 클로저로 바인딩해 make() 의 인자 덮어쓰기를 무시한다 — 생성자를 직접 부른다
        $manager = $this->makeManager($managerClass, [$repositoryParam => $repository, 'extensionManager' => $extensionManager]);

        // 모듈·플러그인은 로드된 인스턴스가 있어야 업데이트에 들어간다 (활성 디렉토리는 없다 — 백업이 실패한다)
        if ($type !== 'template') {
            $instance = Mockery::mock($type === 'module' ? ModuleInterface::class : PluginInterface::class)->shouldIgnoreMissing();
            $instance->shouldReceive('getIdentifier')->andReturn(self::IDENTIFIER);
            $instance->shouldReceive('getVersion')->andReturn('1.0.0');
            $property = new \ReflectionProperty($manager, $type === 'module' ? 'modules' : 'plugins');
            $property->setValue($manager, [self::IDENTIFIER => $instance]);
        }

        $this->assertSame($repository, (fn () => $this->{$repositoryParam})->call($manager), "{$type}: 목 저장소가 주입되지 않았다");

        match ($type) {
            'module' => $manager->updateModule(self::IDENTIFIER, zipPath: $this->tempDir.'/source.zip'),
            'plugin' => $manager->updatePlugin(self::IDENTIFIER, zipPath: $this->tempDir.'/source.zip'),
            'template' => $manager->updateTemplate(self::IDENTIFIER, zipPath: $this->tempDir.'/source.zip'),
        };
    }

    /**
     * 생성자 인자를 이름으로 덮어 매니저를 만듭니다 (나머지 인자는 컨테이너에서 해석).
     *
     * @param  class-string  $managerClass  매니저 클래스
     * @param  array<string, mixed>  $overrides  인자 이름 → 값
     * @return object 매니저
     */
    private function makeManager(string $managerClass, array $overrides): object
    {
        $arguments = [];
        foreach ((new \ReflectionClass($managerClass))->getConstructor()->getParameters() as $parameter) {
            $name = $parameter->getName();
            if (array_key_exists($name, $overrides)) {
                $arguments[] = $overrides[$name];
            } elseif ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();
            } else {
                $arguments[] = app((string) $parameter->getType());
            }
        }

        return new $managerClass(...$arguments);
    }
}
