<?php

namespace Tests\Feature\Extension;

use App\Console\Commands\Core\CoreUpdateCommand;
use App\Console\Commands\Core\ExecuteUpgradeStepsCommand;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Support\RouteCacheHelper;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

/**
 * 라우트 소스가 바뀌는 지점에서 라우트 캐시를 갱신하는지에 대한 회귀 테스트.
 *
 * 확장은 자기 라우트를 부팅 시 등록하지만, `route:cache` 가 걸린 사이트는 캐시에
 * 직렬화된 라우트만 서빙한다. 확장을 설치·업데이트해 라우트가 늘어도 캐시를 갱신하지
 * 않으면 그 라우트는 존재하지 않는 것과 같고, 오류 없이 404 만 난다.
 *
 * 훅 캐시와 달리 라우트 캐시에는 스캔 폴백이 없다는 점이 이 회귀의 핵심이다.
 */
class ExtensionRouteCacheInvalidationTest extends TestCase
{
    #[Test]
    public function 헬퍼는_언제나_stale_캐시를_먼저_제거한다(): void
    {
        Artisan::spy();

        RouteCacheHelper::rebuild();

        // 재생성 여부와 무관하게 낡은 캐시는 남기지 않는다 — 낡은 캐시는
        // "방금 설치한 확장이 통째로 없는" 상태를 만든다.
        Artisan::shouldHaveReceived('call')->with('route:clear')->once();
    }

    #[Test]
    public function 테스트_환경에서는_캐시를_생성하지_않는다(): void
    {
        Artisan::spy();

        RouteCacheHelper::rebuild();

        // 캐시 생성은 같은 프로세스의 뒤따르는 테스트로 라우트가 새어 격리를 깬다.
        Artisan::shouldNotHaveReceived('call', ['route:cache']);
    }

    #[Test]
    public function clear_는_재생성_없이_비우기만_한다(): void
    {
        Artisan::spy();

        RouteCacheHelper::clear();

        Artisan::shouldHaveReceived('call')->with('route:clear')->once();
        Artisan::shouldNotHaveReceived('call', ['route:cache']);
    }

    #[Test]
    public function 라우트가_바뀌는_모든_지점이_갱신을_호출한다(): void
    {
        // 개별 지점을 손으로 열거하면 새 수명주기 메서드가 생겼을 때 그 지점만 조용히
        // 빠진다. 소스를 훑어 "라우트가 바뀌는 지점" 전부가 갱신을 부르는지 본다.
        $sites = [
            ModuleManager::class => ['installModule', 'activateModule', 'deactivateModule', 'uninstallModule', 'updateModule'],
            PluginManager::class => ['installPlugin', 'activatePlugin', 'deactivatePlugin', 'uninstallPlugin', 'updatePlugin'],
        ];

        foreach ($sites as $class => $methods) {
            foreach ($methods as $method) {
                $this->assertStringContainsString(
                    'RouteCacheHelper::rebuild()',
                    $this->methodSource($class, $method),
                    $class.'::'.$method.' 이 라우트 캐시를 갱신하지 않는다 — '
                    .'캐시가 걸린 사이트에서 이 조작 뒤 확장 라우트가 404 가 된다'
                );
            }
        }
    }

    #[Test]
    public function 코어_업데이트_흐름이_라우트_캐시를_되살린다(): void
    {
        // 코어 업데이트는 흐름 중간에 라우트 캐시를 비운다(vendor 교체 중 상태를 구울 수
        // 없으므로 옳다). 그러나 비운 채로 끝내면 그 사이트는 운영자가 수동으로
        // route:cache 를 돌릴 때까지 라우트 캐시 없이 동작한다 — config 가 같은 이유로
        // 흐름 끝에서 재생성되는 것과 대칭이어야 한다.
        foreach ([CoreUpdateCommand::class, ExecuteUpgradeStepsCommand::class] as $class) {
            $source = file_get_contents((new ReflectionClass($class))->getFileName());

            $this->assertStringContainsString(
                'RouteCacheHelper::rebuild()',
                $source,
                $class.' 가 흐름 끝에서 라우트 캐시를 되살리지 않는다'
            );

            // config 와 같은 자리에서 함께 되살아나야 한다 — 한쪽만 되살리면
            // 그 사이트는 절반만 최적화된 상태로 남는다.
            $this->assertStringContainsString(
                'ConfigCacheHelper::rebuild()',
                $source,
                $class.' 의 config 재생성 지점이 사라졌다 (라우트 재생성의 기준점)'
            );
        }
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
}
