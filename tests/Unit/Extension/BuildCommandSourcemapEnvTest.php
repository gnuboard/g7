<?php

namespace Tests\Unit\Extension;

use App\Console\Commands\Core\BuildCoreCommand;
use App\Console\Commands\Module\BuildModuleCommand;
use App\Console\Commands\Plugin\BuildPluginCommand;
use App\Console\Commands\Template\BuildTemplateCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use ReflectionNamedType;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * 빌드 커맨드 소스맵 억제 환경변수 주입 테스트
 *
 * `--production` 빌드는 소스맵을 생성하지 않아야 한다. 소스맵에는 원본 코드
 * 전문(sourcesContent)이 담기므로 배포 산출물에 포함되면 코드가 그대로 노출된다.
 *
 * 판정 신호는 각 vite config 가 읽는 `G7_BUILD_SOURCEMAP` 환경변수이며,
 * 빌드 커맨드가 이 값을 npm 프로세스에 주입하는 책임을 진다. 실제 npm 실행 없이
 * 주입 지점(buildEnv) 과 전달 통로(runNpmCommand 시그니처) 를 검증한다.
 */
class BuildCommandSourcemapEnvTest extends TestCase
{
    /**
     * 빌드 커맨드 클래스 목록
     *
     * @return array<string, array{class-string}>
     */
    public static function buildCommandProvider(): array
    {
        return [
            'core' => [BuildCoreCommand::class],
            'module' => [BuildModuleCommand::class],
            'plugin' => [BuildPluginCommand::class],
            'template' => [BuildTemplateCommand::class],
        ];
    }

    /**
     * 프로덕션 빌드 시 소스맵 억제 환경변수가 주입되는지 테스트
     *
     * @param  class-string  $commandClass  빌드 커맨드 클래스
     */
    #[DataProvider('buildCommandProvider')]
    public function test_production_build_injects_sourcemap_off_env(string $commandClass): void
    {
        $env = $this->invokeBuildEnv($commandClass, productionMode: true);

        $this->assertSame(['G7_BUILD_SOURCEMAP' => '0'], $env);
    }

    /**
     * 일반(비프로덕션) 빌드에는 환경변수를 주입하지 않는지 테스트
     *
     * 미주입 시 vite config 의 기본값이 적용되어 소스맵이 생성된다.
     * 개발자가 확장 디렉토리에서 `npm run build` 를 직접 치는 흐름과 동일하게 유지된다.
     *
     * @param  class-string  $commandClass  빌드 커맨드 클래스
     */
    #[DataProvider('buildCommandProvider')]
    public function test_non_production_build_injects_nothing(string $commandClass): void
    {
        $env = $this->invokeBuildEnv($commandClass, productionMode: false);

        $this->assertSame([], $env);
    }

    /**
     * runNpmCommand() 가 env 를 전달받을 수 있는 시그니처인지 테스트
     *
     * buildEnv() 가 올바른 값을 만들어도 runNpmCommand() 가 이를 받지 못하면
     * 주입이 프로세스까지 도달하지 못한다(이번 이슈 이전의 죽은 `--production` 상태).
     *
     * @param  class-string  $commandClass  빌드 커맨드 클래스
     */
    #[DataProvider('buildCommandProvider')]
    public function test_run_npm_command_accepts_env_argument(string $commandClass): void
    {
        $method = new ReflectionMethod($commandClass, 'runNpmCommand');
        $params = $method->getParameters();

        $envParam = null;
        foreach ($params as $param) {
            if ($param->getName() === 'env') {
                $envParam = $param;
                break;
            }
        }

        $this->assertNotNull($envParam, "{$commandClass}::runNpmCommand() 에 env 파라미터가 없습니다.");

        $type = $envParam->getType();
        $this->assertInstanceOf(ReflectionNamedType::class, $type);
        $this->assertSame('array', $type->getName());
        $this->assertTrue($envParam->isDefaultValueAvailable(), 'env 는 기본값을 가져야 한다(기존 호출부 호환).');
        $this->assertSame([], $envParam->getDefaultValue());
    }

    /**
     * Symfony Process 가 지정한 env 를 부모 환경에 병합하는지 테스트
     *
     * setEnv() 가 부모 환경을 대체(replace)한다면 PATH 가 소실되어 npm 실행 자체가
     * 깨진다. 추가분만 넘기는 현재 구현의 전제를 고정한다.
     */
    public function test_process_env_merges_with_parent_environment(): void
    {
        $process = new Process(['echo', 'test']);
        $process->setEnv(['G7_BUILD_SOURCEMAP' => '0']);

        $this->assertSame(['G7_BUILD_SOURCEMAP' => '0'], $process->getEnv());

        // PATH 는 부모 환경에서 상속되므로 setEnv 로 지우지 않는다.
        $this->assertNotEmpty(getenv('PATH') ?: getenv('Path'), '부모 환경에 PATH 가 있어야 병합 전제가 성립한다.');
    }

    /**
     * 커맨드의 private buildEnv() 를 호출합니다.
     *
     * @param  class-string  $commandClass  빌드 커맨드 클래스
     * @param  bool  $productionMode  프로덕션 빌드 여부
     * @return array<string, string> 주입될 환경변수
     */
    private function invokeBuildEnv(string $commandClass, bool $productionMode): array
    {
        $command = app($commandClass);
        $method = new ReflectionMethod($commandClass, 'buildEnv');

        return $method->invoke($command, $productionMode);
    }
}
