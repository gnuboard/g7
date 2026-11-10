<?php

namespace Tests\Unit\Support;

use App\Contracts\Extension\PluginInterface;
use App\Contracts\Extension\StorageInterface;
use App\Extension\PluginManager;
use App\Repositories\JsonConfigRepository;
use App\Services\PluginSettingsService;
use App\Support\ExtensionSettingsMirror;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 설정 미러 낭비 정리 (#860 W9)
 *
 * (a) 미러는 민감 필드를 버리므로 복호화부터 하지 않는다. (b) 플러그인 설정 서비스는 요청 단위로 하나라
 * 같은 요청에서 저장한 값이 다른 소비자에게 바로 보인다. (c) 설정 기본값 파일은 파일이 바뀌지 않는 한
 * 저장소 인스턴스가 여럿이어도 한 번만 읽는다.
 */
class ExtensionSettingsMirrorSensitiveTest extends TestCase
{
    /**
     * 가짜 플러그인 설정 파일 (파일명 => 내용)
     *
     * @var array<string, string>
     */
    private array $files = [];

    /**
     * 민감 필드 하나·일반 필드 하나를 가진 가짜 플러그인을 플러그인 매니저에 꽂습니다.
     *
     * @return void 반환값 없음
     */
    private function bindFakePlugin(): void
    {
        $files = &$this->files;

        $storage = Mockery::mock(StorageInterface::class)->shouldIgnoreMissing();
        $storage->shouldReceive('exists')->andReturnUsing(function (string $category, string $file) use (&$files): bool {
            return isset($files[$file]);
        });
        $storage->shouldReceive('get')->andReturnUsing(function (string $category, string $file) use (&$files): ?string {
            return $files[$file] ?? null;
        });
        $storage->shouldReceive('put')->andReturnUsing(function (string $category, string $file, string $content) use (&$files): bool {
            $files[$file] = $content;

            return true;
        });

        $plugin = Mockery::mock(PluginInterface::class)->shouldIgnoreMissing();
        $plugin->shouldReceive('getIdentifier')->andReturn('w9-fake');
        $plugin->shouldReceive('getConfigValues')->andReturn(['api_key' => '', 'mode' => 'test']);
        $plugin->shouldReceive('getSettingsSchema')->andReturn([
            'api_key' => ['type' => 'string', 'sensitive' => true],
            'mode' => ['type' => 'string'],
        ]);
        $plugin->shouldReceive('getStorage')->andReturn($storage);

        $manager = Mockery::mock(PluginManager::class)->shouldIgnoreMissing();
        $manager->shouldReceive('getPlugin')->with('w9-fake')->andReturn($plugin);

        $this->app->instance(PluginManager::class, $manager);
        $this->app->forgetScopedInstances();
    }

    /**
     * 미러는 민감 필드를 담지 않고, 그 값을 복호화하지도 않습니다.
     *
     * @effects settings_mirror_never_decrypts_sensitive_fields
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 미러는_민감_필드를_복호화하지_않는다(): void
    {
        $this->files['setting.json'] = json_encode(['api_key' => Crypt::encryptString('secret'), 'mode' => 'live']);
        $this->bindFakePlugin();

        Crypt::spy();

        app(ExtensionSettingsMirror::class)->refreshPlugin('w9-fake');

        $mirror = config('g7_settings.plugins.w9-fake');
        $this->assertSame('live', $mirror['mode'] ?? null);
        $this->assertArrayNotHasKey('api_key', $mirror, '민감 필드는 미러에 실리지 않는다.');
        Crypt::shouldNotHaveReceived('decryptString');
    }

    /**
     * 같은 요청에서 다른 소비자가 저장한 값이 바로 보입니다.
     *
     * @effects plugin_settings_saved_value_is_visible_in_same_request
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 같은_요청에서_저장한_값이_다른_소비자에게_보인다(): void
    {
        $this->files['setting.json'] = json_encode(['mode' => 'test']);
        $this->bindFakePlugin();

        $reader = app(PluginSettingsService::class);
        $this->assertSame('test', $reader->get('w9-fake', 'mode'));

        app(PluginSettingsService::class)->save('w9-fake', ['mode' => 'live']);

        $this->assertSame('live', $reader->get('w9-fake', 'mode'), '먼저 읽어 둔 소비자도 저장한 값을 봐야 한다.');
    }

    /**
     * 설정 기본값 파일은 바뀌지 않는 한 저장소 인스턴스가 여럿이어도 한 번만 읽습니다.
     *
     * @effects defaults_json_is_read_once_per_file_version
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 기본값_파일은_파일이_바뀔_때만_다시_읽는다(): void
    {
        $dir = storage_path('framework/testing/w9-defaults-'.uniqid());
        File::ensureDirectoryExists($dir);
        $path = $dir.'/defaults.json';
        File::put($path, json_encode(['_meta' => ['categories' => ['general']], 'defaults' => ['general' => ['a' => 1]], 'frontend_schema' => []]));

        $counter = new \ArrayObject(['reads' => 0]);
        $make = static fn () => new class($path, $counter) extends JsonConfigRepository
        {
            /**
             * 시험용 기본값 경로와 읽기 계수기를 받습니다.
             *
             * @param  string  $testPath  기본값 파일 경로
             * @param  \ArrayObject  $counter  읽기 횟수 계수기
             */
            public function __construct(private string $testPath, private \ArrayObject $counter) {}

            /**
             * 시험용 기본값 경로를 돌려줍니다.
             *
             * @return string 파일 경로
             */
            protected function defaultsPath(): string
            {
                return $this->testPath;
            }

            /**
             * 읽기를 세고 실제로 읽습니다.
             *
             * @param  string  $path  파일 경로
             * @return string|false 파일 내용
             */
            protected function readDefaultsFile(string $path): string|false
            {
                $this->counter['reads']++;

                return parent::readDefaultsFile($path);
            }
        };

        try {
            $this->assertSame(['a' => 1], $make()->getDefaults()['general'] ?? null);
            $this->assertSame(['a' => 1], $make()->getDefaults()['general'] ?? null);
            $this->assertSame(1, $counter['reads'], '같은 파일을 인스턴스마다 다시 읽으면 안 된다.');

            // 파일이 바뀌면(코어 업데이트·settings:install) 다시 읽는다
            File::put($path, json_encode(['_meta' => ['categories' => ['general']], 'defaults' => ['general' => ['a' => 22]], 'frontend_schema' => []]));
            touch($path, time() + 5);
            $this->assertSame(['a' => 22], $make()->getDefaults()['general'] ?? null);
            $this->assertSame(2, $counter['reads']);
        } finally {
            File::deleteDirectory($dir);
        }
    }
}
