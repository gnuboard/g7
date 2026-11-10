<?php

namespace Tests\Unit\Thumbnails;

use App\Contracts\Extension\ModuleInterface;
use App\Contracts\Extension\PluginInterface;
use App\Extension\HookManager;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Support\ExtensionStoragePath;
use App\Thumbnails\ThumbnailStore;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 확장 제거(데이터 삭제) 때 그 확장 원본의 썸네일 정리 테스트
 *
 * 모듈·플러그인 저장 폴더는 드라이버를 거치지 않고 `File::deleteDirectory` 로 통째 지운다 — 저장소 계층
 * 무효화가 닿지 않으므로 매니저가 `ThumbnailStore::purgeIdentifier()` 를 직접 부른다. 다른 확장·코어 원본의
 * 파생본은 남아야 하고, 원본 디스크가 무엇이든(modules·local 등) 그 식별자 접두를 지운다.
 */
class ExtensionRemovalThumbnailPurgeTest extends TestCase
{
    /** 시험 확장 식별자 (공식 확장과 겹치지 않는 가짜 이름) */
    private const IDENTIFIER = 'g7test-thumbrm';

    /** @var list<list<array<string, mixed>>> 퍼지 훅이 받은 묶음 */
    private array $purges = [];

    /**
     * 썸네일 디스크를 가짜로 바꾸고 퍼지 훅을 기록합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('thumbnails');
        Config::set('core.thumbnail.disk_public', 'thumbnails');
        Config::set('core.thumbnail.disk_gated', 'thumbnails');
        $this->purges = [];
        HookManager::clearAction('core.thumbnail.after_purge');
        HookManager::addAction('core.thumbnail.after_purge', function (array $items) {
            $this->purges[] = $items;
        });
    }

    /**
     * 퍼지 훅과 시험 저장 폴더를 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        HookManager::clearAction('core.thumbnail.after_purge');

        foreach ([ExtensionStoragePath::module(self::IDENTIFIER), ExtensionStoragePath::plugin(self::IDENTIFIER)] as $dir) {
            if (is_dir($dir)) {
                (new Filesystem)->deleteDirectory($dir);
            }
        }

        parent::tearDown();
    }

    /**
     * 매니저 표 — 매니저, 확장 인터페이스, 정리 메서드, 저장 폴더, 원본 디스크
     *
     * @return array<string, array{0:class-string,1:class-string,2:string,3:string}> 표
     */
    public static function managerProvider(): array
    {
        return [
            'module' => [ModuleManager::class, ModuleInterface::class, 'deleteModuleStorage', 'modules'],
            'plugin' => [PluginManager::class, PluginInterface::class, 'deletePluginSettingsDirectory', 'plugins'],
        ];
    }

    /**
     * 확장 저장 폴더를 지우면 그 식별자의 파생본만 모든 원본 디스크 접두에서 지워진다.
     *
     * @effects extension_removal_purges_its_derivatives, purge_hook_fired_with_payload
     *
     * @param  class-string  $managerClass  매니저
     * @param  class-string  $extensionInterface  확장 인터페이스
     * @param  string  $method  정리 메서드
     * @param  string  $sourceDisk  확장 원본 디스크
     * @return void 반환값 없음
     */
    #[DataProvider('managerProvider')]
    public function test_extension_removal_purges_its_derivatives(string $managerClass, string $extensionInterface, string $method, string $sourceDisk): void
    {
        $storageDir = $sourceDisk === 'modules' ? ExtensionStoragePath::module(self::IDENTIFIER) : ExtensionStoragePath::plugin(self::IDENTIFIER);
        File::ensureDirectoryExists($storageDir.'/images');
        File::put($storageDir.'/images/a.jpg', 'x');

        $thumbnails = Storage::disk('thumbnails');
        $mine = 'thumbnails/'.$sourceDisk.'/'.self::IDENTIFIER.'/images/a.jpg/sm_0123abcd.jpg';
        $mineOnLocal = 'thumbnails/local/'.self::IDENTIFIER.'/attachments/b.png/md_0123abcd.png';
        $other = 'thumbnails/'.$sourceDisk.'/g7test-thumbkeep/images/a.jpg/sm_0123abcd.jpg';
        $similar = 'thumbnails/'.$sourceDisk.'/'.self::IDENTIFIER.'-extra/images/a.jpg/sm_0123abcd.jpg';
        $core = 'thumbnails/attachments/2026/11/05/c.jpg/sm_0123abcd.jpg';

        foreach ([$mine, $mineOnLocal, $other, $similar, $core] as $key) {
            $thumbnails->put($key, 't');
        }

        $extension = Mockery::mock($extensionInterface);
        $extension->shouldReceive('getIdentifier')->andReturn(self::IDENTIFIER);
        $manager = Mockery::mock($managerClass)->makePartial()->shouldAllowMockingProtectedMethods();

        (new \ReflectionMethod($managerClass, $method))->invoke($manager, $extension);

        $this->assertDirectoryDoesNotExist($storageDir);
        $thumbnails->assertMissing($mine);
        $thumbnails->assertMissing($mineOnLocal);
        $thumbnails->assertExists($other);
        // 식별자 접두가 같은 다른 확장(`…-extra`)은 남는다
        $thumbnails->assertExists($similar);
        $thumbnails->assertExists($core);

        $prefixes = array_column(array_merge(...$this->purges), 'prefix', 'source_disk');
        $this->assertSame(self::IDENTIFIER, $prefixes[$sourceDisk] ?? null);
        $this->assertSame(self::IDENTIFIER, $prefixes['local'] ?? null);
    }

    /**
     * 식별자 형식이 아니면(경로 조각·빈 값) 아무 것도 지우지 않는다.
     *
     * @effects extension_removal_purges_its_derivatives
     *
     * @return void 반환값 없음
     */
    public function test_purge_identifier_rejects_non_identifiers(): void
    {
        Storage::disk('thumbnails')->put('thumbnails/modules/a/x.jpg/sm_0123abcd.jpg', 't');

        $store = app(ThumbnailStore::class);

        foreach (['', '..', '../modules', 'a/b', 'A-B', '*'] as $bad) {
            $this->assertSame(0, $store->purgeIdentifier($bad), $bad);
        }

        Storage::disk('thumbnails')->assertExists('thumbnails/modules/a/x.jpg/sm_0123abcd.jpg');
        $this->assertSame([], $this->purges);
    }
}
