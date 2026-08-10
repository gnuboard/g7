<?php

namespace Tests\Feature\Extension;

use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\TemplateManager;
use Tests\TestCase;

/**
 * `*:check-updates` 커맨드가 확인 결과를 사실대로 표시하는지 고정하는 테스트
 *
 * 매니저의 `checkAll*ForUpdates()` 는 `details` 에 **업데이트 가능한 항목만** 담고,
 * 각 항목에 `update_available` 키를 넣지 않는다. 커맨드가 `$detail['update_available'] ?? false`
 * 로 상태를 읽으면 업데이트가 있는 확장을 전부 "최신" 으로 표시하고 요약도 "0개" 가 된다.
 *
 * 운영자가 커맨드로 확인하면 "업데이트 없음" 을 보고 넘어가게 되므로, 표시 계약을
 * 여기서 고정한다.
 */
class CheckUpdatesCommandReportingTest extends TestCase
{
    /**
     * 매니저가 돌려주는 details 항목이 표시에 필요한 키를 갖추는지 검증합니다.
     *
     * @return void
     */
    public function test_details_항목이_업데이트_여부를_스스로_말한다(): void
    {
        foreach ([ModuleManager::class, PluginManager::class, TemplateManager::class] as $manager) {
            $source = file_get_contents((new \ReflectionClass($manager))->getFileName());

            $this->assertMatchesRegularExpression(
                "/'update_available' => (true|\\\$result\['update_available'\]),\s*\n\s*'identifier'|'identifier' => \\\$identifier,\s*\n\s*'update_available'/",
                $source,
                class_basename($manager).'::details 항목에 update_available 이 없으면 커맨드가 상태를 판별할 수 없습니다'
            );
        }
    }

    /**
     * 커맨드가 요약 건수를 "확인한 전체" 가 아닌 값으로 오기재하지 않는지 검증합니다.
     *
     * @return void
     */
    public function test_커맨드가_업데이트_가능_건수를_details_로_센다(): void
    {
        foreach ([
            'app/Console/Commands/Module/CheckModuleUpdatesCommand.php',
            'app/Console/Commands/Plugin/CheckPluginUpdatesCommand.php',
            'app/Console/Commands/Template/CheckTemplateUpdatesCommand.php',
        ] as $path) {
            $source = file_get_contents(base_path($path));

            $this->assertStringNotContainsString(
                "\$detail['update_available'] ?? false",
                $source,
                basename($path).': 없는 키를 기본 false 로 읽으면 업데이트 가능 항목이 "최신" 으로 표시됩니다'
            );
        }
    }
}
