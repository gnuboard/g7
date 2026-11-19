<?php

namespace Tests\Unit\Extension;

use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Contracts\Repositories\PluginRepositoryInterface;
use App\Extension\ExtensionManager;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

/**
 * `_bundled` 소스 설치의 vendor 구성 경로에 대한 회귀 테스트.
 *
 * 문서화된 계약은 「`--vendor-mode=bundled` 로 설치하면 동봉된 `vendor-bundle.zip` 을
 * 쓴다」이고(docs/extension/vendor-bundle.md §5.1·§6·§7.1), 릴리즈 페이로드에도 확장의
 * `vendor-bundle.zip` 이 함께 실려 나간다. 그런데 그 통로인 `VendorResolver` 는 한때
 * **`_pending` 스테이징 사본이 있을 때만** 열려 있었다 — 웹 인스톨러의 정상 경로(`_bundled`)와
 * `--force` 재설치는 그 분기를 타지 않아 Phase 4.5 의 raw composer 만 남았다.
 *
 * 그 상태의 결과는 두 갈래이고 둘 다 오류로 드러나지 않는다:
 *  - `proc_open` 차단 호스팅: Symfony Process 가 `LogicException` 을 던지고, 그것은
 *    `RuntimeException` 이 아니라 `runComposerInstallAt` 의 catch 를 통과해 나가 확장 설치가
 *    정체불명의 예외로 중단된다.
 *  - composer 바이너리 부재: `false` + 경고 로그만 남아 **설치는 성공으로 표시**되고,
 *    vendor 가 빈 확장이 남는다. DB `vendor_mode` 에는 `bundled` 로 기록되므로 이후
 *    업데이트만 그 모드를 상속해 번들로 채운다 — 설치만 비어 있는 상태다.
 *
 * 그래서 이 테스트는 두 축을 잠근다: ① 스폰 불가 환경에서 바이너리 탐색이 예외를 흘리지
 * 않는가 ② 설치 경로가 `_pending` 가드 밖에서도 resolver 를 경유하며 두 매니저가 대칭인가.
 *
 * `disable_functions` 는 PHP_INI_SYSTEM 이라 런타임에 재현할 수 없으므로(계획서 §8 자동화
 * 한계), 판정은 주입 이음매(`canSpawnProcess`)와 **호출 지점의 형태**로 고정한다 — 형제
 * `ShellExecGuardCoverageTest` 와 같은 규율이다.
 *
 * 커버 축: extension_vendor_source pending_staging·bundled_source·force_reinstall
 */
class BundledSourceVendorInstallTest extends TestCase
{
    private string $tempDir;

    /**
     * 테스트마다 쓸 임시 확장 디렉토리를 만든다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'g7-bundled-vendor-'.uniqid();
        File::makeDirectory($this->tempDir, 0755, true);
    }

    /**
     * 임시 확장 디렉토리를 지운다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            File::deleteDirectory($this->tempDir);
        }

        parent::tearDown();
    }

    /**
     * 스폰이 막힌 매니저로 composer 설치를 시도하면 예외를 던지지 않고 false 를 돌려주는지 확인한다.
     *
     * @effects composer_binary_lookup_does_not_leak_exception_when_spawn_blocked
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 스폰_불가_환경에서_composer_바이너리_탐색이_예외를_흘리지_않는다(): void
    {
        File::put($this->tempDir.'/composer.json', json_encode([
            'require' => ['ezyang/htmlpurifier' => '^4.17'],
        ]));

        config(['process.composer_binary' => '']);

        $manager = $this->spawnBlockedExtensionManager();

        // 반환은 false(= 의존성을 못 깔았다)여야 한다. LogicException 이 새어 나오면
        // 호출부의 `catch (\RuntimeException)` 이 잡지 못해 설치 자체가 중단된다.
        $this->assertFalse(
            $manager->runComposerInstallAt($this->tempDir, true),
            '스폰 불가 환경에서는 실패를 값으로 돌려주어야 한다 (예외 전파 금지)'
        );
    }

    /**
     * canSpawnProcess 가 ExecutionEnvironment 로 proc_open 을 판정하고, findComposerBinary 의 스폰 호출이 그 판정 뒤에 있는지 소스로 확인한다.
     *
     * @scenario function_availability=all_blocked
     *
     * @effects spawn_availability_is_judged_through_execution_environment_ssot
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 스폰_가용성_판정은_실행환경_ssot_를_경유한다(): void
    {
        $source = $this->methodSource(ExtensionManager::class, 'canSpawnProcess');

        $this->assertStringContainsString(
            "ExecutionEnvironment::hasFunction('proc_open')",
            $source,
            'function_exists 직접 판정은 disable_functions 를 보지 못한다'
        );

        // 판정만 잠그면 「배선은 아무도 안 본다」가 된다 — 탐색 스폰이 그 판정 뒤에
        // 있는지까지 본다. 실제 composer 를 띄우지 않고 확인할 수 있는 유일한 축이다.
        $finder = $this->stripComments($this->methodSource(ExtensionManager::class, 'findComposerBinary'));
        $guardAt = strpos($finder, 'canSpawnProcess()');
        $spawnAt = strpos($finder, 'new Process(');

        $this->assertNotFalse($guardAt, 'findComposerBinary 가 스폰 가용성 판정을 거치지 않는다');
        $this->assertNotFalse($spawnAt, 'findComposerBinary 의 스폰 호출을 찾지 못했다');
        $this->assertLessThan(
            $spawnAt,
            $guardAt,
            'PATH 탐색 스폰이 가용성 판정보다 앞에 있다 — 차단 호스팅에서 LogicException 이 새어 나간다'
        );
    }

    /**
     * 모듈과 플러그인 설치 메서드가 복사 뒤에 활성 디렉토리 기준으로 resolver 통로를 부르고, 그 통로가 vendor 해결 여부로 막혀 있는지 소스로 확인한다.
     *
     * @scenario extension_vendor_source=bundled_source
     *
     * @effects bundled_source_install_routes_vendor_through_resolver
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 설치_경로는_pending_가드_밖에서도_resolver_를_경유한다(): void
    {
        foreach ([
            [ModuleManager::class, 'installModule'],
            [PluginManager::class, 'installPlugin'],
        ] as [$class, $method]) {
            $source = $this->stripComments($this->methodSource($class, $method));

            $pendingGuardAt = strpos($source, 'ExtensionPendingHelper::isPending');
            $copyAt = strpos($source, 'copyFromPendingOrBundled');

            $this->assertNotFalse($pendingGuardAt, "{$class}::{$method} — _pending 가드 위치를 찾지 못했다");
            $this->assertNotFalse($copyAt, "{$class}::{$method} — 복사 호출 위치를 찾지 못했다");

            // 복사 **뒤**에 놓인 resolver 호출이 있어야 한다. `_pending` 분기의 호출은
            // 복사 앞에 있으므로, 위치 비교가 곧 「가드 밖에도 통로가 있는가」의 판정이다.
            $afterCopy = substr($source, $copyAt);

            $this->assertStringContainsString(
                'installVendorViaResolver',
                $afterCopy,
                "{$class}::{$method} — 복사 후 활성 디렉토리에 resolver 를 적용하는 통로가 없다. "
                .'그러면 _bundled 소스 설치(웹 인스톨러의 정상 경로)는 동봉 번들을 쓸 수 없다'
            );

            $this->assertStringContainsString(
                'hasComposerDependenciesAt($activePath)',
                $afterCopy,
                "{$class}::{$method} — 활성 디렉토리 기준으로 의존성 유무를 판정해야 한다"
            );

            // 그 통로의 조건이 **vendor 해결 여부**여야 한다. 존재만 보면 조건을 상수로
            // 바꿔 통로를 죽여도 초록이 남는다 — 실제로 그 변이를 넣어 red 를 확인했다.
            $resolverAt = strpos($afterCopy, 'installVendorViaResolver');
            $gateAt = strpos($afterCopy, '! $vendorResolved');

            $this->assertNotFalse($gateAt, "{$class}::{$method} — 복사 후 통로의 게이트를 찾지 못했다");
            $this->assertLessThan(
                $resolverAt,
                $gateAt,
                "{$class}::{$method} — 복사 후 resolver 통로가 vendor 해결 플래그로 열리지 않는다 "
                .'(조건이 상수로 바뀌어 통로가 죽어 있을 수 있다)'
            );

            // 그 분기는 `! app()->environment('testing')` 로 막혀 있어 **어떤 테스트도
            // 실행할 수 없다.** 즉 이 파일의 판정은 전부 소스 문자열 대조이고, 행위
            // 측정은 존재하지 않는다 — 그 한계를 여기 명시해 다음 라운드가 「행위까지
            // 잠겨 있다」로 오독하지 않게 한다.
            //
            // 대신 그 환경 가드 자신을 잰다. 가드가 사라지면 확장 설치 테스트가 실제로
            // composer 를 돌려 스위트가 네트워크에 묶이고, 그 사실은 이 파일이 아니면
            // 아무 데서도 드러나지 않는다.
            $this->assertStringContainsString(
                "! app()->environment('testing')",
                $afterCopy,
                "{$class}::{$method} — 복사 후 resolver 통로에 테스트 환경 가드가 없다. "
                .'그러면 확장 설치 테스트가 실제로 composer 를 실행한다'
            );
        }
    }

    /**
     * 두 매니저 설치 메서드에서 raw composer 폴백 호출이 vendor 해결 게이트 뒤에 놓였는지 소스 위치로 확인한다.
     *
     * @scenario extension_vendor_source=force_reinstall
     *
     * @effects raw_composer_fallback_is_gated_by_vendor_resolution_flag
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function raw_composer_폴백은_vendor_해결_플래그_뒤에_있다(): void
    {
        foreach ([
            [ModuleManager::class, 'installModule', 'runComposerInstall(\'modules\''],
            [PluginManager::class, 'installPlugin', 'runComposerInstall(\'plugins\''],
        ] as [$class, $method, $rawCall]) {
            $source = $this->stripComments($this->methodSource($class, $method));

            $flagAt = strpos($source, 'if (! $vendorResolved)');
            $rawAt = strpos($source, $rawCall);

            $this->assertNotFalse($flagAt, "{$class}::{$method} — Phase 4.5 의 vendor 해결 게이트가 없다");
            $this->assertNotFalse($rawAt, "{$class}::{$method} — raw composer 폴백 호출을 찾지 못했다");
            $this->assertLessThan(
                $rawAt,
                $flagAt,
                "{$class}::{$method} — raw composer 폴백이 게이트보다 앞에 있다 (resolver 성공 후에도 재실행)"
            );
        }
    }

    /**
     * 모듈 매니저와 플러그인 매니저 설치 메서드의 resolver 통로 호출 수가 같은지 확인한다.
     *
     * @scenario extension_vendor_source=pending_staging
     *
     * @effects module_and_plugin_managers_have_symmetric_vendor_paths
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 두_매니저의_vendor_통로_개수가_대칭이다(): void
    {
        $moduleSource = $this->stripComments($this->methodSource(ModuleManager::class, 'installModule'));
        $pluginSource = $this->stripComments($this->methodSource(PluginManager::class, 'installPlugin'));

        $this->assertSame(
            substr_count($moduleSource, 'installVendorViaResolver'),
            substr_count($pluginSource, 'installVendorViaResolver'),
            '한쪽 매니저만 통로를 가지면 그 확장 타입에서만 조용히 vendor 가 비어 있게 된다'
        );
    }

    /**
     * 스폰이 불가능한 호스팅을 흉내낸 ExtensionManager 를 만듭니다.
     *
     * `disable_functions` 는 PHP_INI_SYSTEM 이라 런타임에 바꿀 수 없으므로, 판정 이음매
     * (`canSpawnProcess`)를 덮어쓰는 것이 이 환경을 단위 테스트로 잠그는 유일한 방법이다.
     *
     * @return ExtensionManager 스폰 불가로 판정하는 인스턴스
     */
    private function spawnBlockedExtensionManager(): ExtensionManager
    {
        return new class(app(ModuleRepositoryInterface::class), app(PluginRepositoryInterface::class)) extends ExtensionManager
        {
            /**
             * 스폰 불가 환경을 흉내 내도록 항상 스폰할 수 없다고 답한다.
             *
             * @return bool 언제나 false
             */
            protected function canSpawnProcess(): bool
            {
                return false;
            }
        };
    }

    /**
     * 지정한 메서드의 소스 본문을 반환합니다.
     *
     * @param  string  $class  대상 클래스
     * @param  string  $method  대상 메서드
     * @return string 메서드 본문 소스
     */
    private function methodSource(string $class, string $method): string
    {
        $reflection = new ReflectionClass($class);
        $target = $reflection->getMethod($method);
        $lines = file($reflection->getFileName());

        return implode('', array_slice(
            $lines,
            $target->getStartLine() - 1,
            $target->getEndLine() - $target->getStartLine() + 1
        ));
    }

    /**
     * 소스에서 주석을 제거합니다.
     *
     * 호출 위치를 문자열 비교로 판정하므로, 그 호출을 설명하는 주석이 실제 호출로
     * 오인되면 판정이 뒤집힌다.
     *
     * @param  string  $source  대상 소스
     * @return string 주석이 제거된 소스
     */
    private function stripComments(string $source): string
    {
        $stripped = '';

        foreach (token_get_all('<?php '.$source) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                $stripped .= $token[1];

                continue;
            }

            $stripped .= $token;
        }

        return $stripped;
    }
}
