<?php

namespace Tests\Unit\Extension;

use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\TemplateManager;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use Tests\TestCase;

/**
 * `_bundled`·`_pending` 메타 지연 로딩 (#860 W2)
 *
 * 부팅마다 미설치 번들 확장 매니페스트까지 읽던 것을, 관리 목록·업데이트 확인처럼 그 메타를 실제로 쓰는
 * 곳에서 처음 읽을 때로 미룬다. 읽는 것은 JSON 뿐이고(진입 클래스 require 없음), `load*()` 를 다시 부르면
 * 지연 상태가 처음으로 돌아간다.
 */
class ExtensionPendingLazyLoadTest extends TestCase
{
    /**
     * 샌드박스 확장 루트
     */
    private string $sandbox;

    /**
     * 매니저 종류별 설정 — [클래스, 루트 경로 속성, 매니페스트 이름, 번들 배열 속성, 공개 getter, load 메서드]
     *
     * @return array<string, array<int, string>> 데이터셋
     */
    public static function managers(): array
    {
        return [
            'module' => [ModuleManager::class, 'modulesPath', 'module.json', 'bundledModules', 'getBundledModules', 'loadModules'],
            'plugin' => [PluginManager::class, 'pluginsPath', 'plugin.json', 'bundledPlugins', 'getBundledPlugins', 'loadPlugins'],
            'template' => [TemplateManager::class, 'templatesPath', 'template.json', 'bundledTemplates', 'getBundledTemplates', 'loadTemplates'],
        ];
    }

    /**
     * 샌드박스 경로를 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->sandbox = storage_path('framework/testing/pending-lazy-'.uniqid());
        File::ensureDirectoryExists($this->sandbox);
    }

    /**
     * 샌드박스를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        File::deleteDirectory($this->sandbox);

        parent::tearDown();
    }

    /**
     * 샌드박스 `_bundled/{id}` 에 매니페스트를 씁니다.
     *
     * @param  string  $manifest  매니페스트 파일 이름
     * @param  string  $version  기록할 버전
     * @return void 반환값 없음
     */
    private function writeBundled(string $manifest, string $version): void
    {
        $dir = $this->sandbox.'/_bundled/lazy-test';
        File::ensureDirectoryExists($dir);
        File::put($dir.'/'.$manifest, json_encode([
            'identifier' => 'lazy-test',
            'vendor' => 'lazy',
            'name' => ['ko' => '지연 시험', 'en' => 'Lazy'],
            'version' => $version,
            'type' => 'user',
        ]));
    }

    /**
     * 샌드박스를 루트로 쓰는 새 매니저를 만듭니다.
     *
     * @param  string  $class  매니저 클래스
     * @param  string  $rootProperty  루트 경로 속성
     * @return object 매니저
     */
    private function makeManager(string $class, string $rootProperty): object
    {
        $manager = $this->app->make($class);

        foreach ([$rootProperty => $this->sandbox] as $property => $value) {
            (new ReflectionProperty($manager, $property))->setValue($manager, $value);
        }

        return $manager;
    }

    /**
     * 번들 배열 속성 원값을 읽습니다 (getter 를 거치지 않는다).
     *
     * @param  object  $manager  매니저
     * @param  string  $property  속성 이름
     * @return array<string, mixed> 원값
     */
    private function raw(object $manager, string $property): array
    {
        return (new ReflectionProperty($manager, $property))->getValue($manager);
    }

    /**
     * 부팅 load 는 `_bundled`·`_pending` 매니페스트를 읽지 않습니다.
     *
     * @effects pending_and_bundled_manifests_are_not_read_during_boot
     *
     * @param  string  $class  매니저 클래스
     * @param  string  $root  루트 경로 속성
     * @param  string  $manifest  매니페스트 파일 이름
     * @param  string  $property  번들 배열 속성
     * @param  string  $getter  공개 getter
     * @param  string  $load  load 메서드
     * @return void 반환값 없음
     */
    #[Test]
    #[DataProvider('managers')]
    public function 부팅_load_는_번들_매니페스트를_읽지_않는다(string $class, string $root, string $manifest, string $property, string $getter, string $load): void
    {
        $this->writeBundled($manifest, '1.0.0');
        $manager = $this->makeManager($class, $root);

        $manager->{$load}();

        $this->assertSame([], $this->raw($manager, $property), '부팅 직후에는 번들 메타가 메모리에 없어야 한다.');
    }

    /**
     * 처음 읽힐 때 한 번만 읽고, 그 뒤에는 메모리 값을 씁니다.
     *
     * @effects pending_and_bundled_manifests_load_once_on_first_access
     *
     * @param  string  $class  매니저 클래스
     * @param  string  $root  루트 경로 속성
     * @param  string  $manifest  매니페스트 파일 이름
     * @param  string  $property  번들 배열 속성
     * @param  string  $getter  공개 getter
     * @param  string  $load  load 메서드
     * @return void 반환값 없음
     */
    #[Test]
    #[DataProvider('managers')]
    public function 처음_읽힐_때_한_번만_읽는다(string $class, string $root, string $manifest, string $property, string $getter, string $load): void
    {
        $this->writeBundled($manifest, '1.0.0');
        $manager = $this->makeManager($class, $root);
        $manager->{$load}();

        $this->assertSame('1.0.0', $manager->{$getter}()['lazy-test']['version'] ?? null);

        // 파일이 바뀌어도 같은 로드 주기 안에서는 다시 읽지 않는다
        $this->writeBundled($manifest, '2.0.0');
        $this->assertSame('1.0.0', $manager->{$getter}()['lazy-test']['version'] ?? null);
    }

    /**
     * `load*()` 를 다시 부르면 지연 상태가 처음으로 돌아가 새 매니페스트를 읽습니다.
     *
     * @effects reloading_extensions_resets_lazy_state
     *
     * @param  string  $class  매니저 클래스
     * @param  string  $root  루트 경로 속성
     * @param  string  $manifest  매니페스트 파일 이름
     * @param  string  $property  번들 배열 속성
     * @param  string  $getter  공개 getter
     * @param  string  $load  load 메서드
     * @return void 반환값 없음
     */
    #[Test]
    #[DataProvider('managers')]
    public function 다시_load_하면_지연_상태가_초기화된다(string $class, string $root, string $manifest, string $property, string $getter, string $load): void
    {
        $this->writeBundled($manifest, '1.0.0');
        $manager = $this->makeManager($class, $root);
        $manager->{$load}();
        $manager->{$getter}();

        $this->writeBundled($manifest, '2.0.0');
        $manager->{$load}();

        $this->assertSame([], $this->raw($manager, $property));
        $this->assertSame('2.0.0', $manager->{$getter}()['lazy-test']['version'] ?? null);
    }
}
