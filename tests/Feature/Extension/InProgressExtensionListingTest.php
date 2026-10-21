<?php

namespace Tests\Feature\Extension;

use App\Enums\ExtensionStatus;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\TemplateManager;
use App\Models\Module;
use App\Models\Plugin;
use App\Models\Template;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * 진행 중 상태(updating 등)에 멈춘 확장이 목록에 「미설치」 카드로 겹쳐 보이지 않는지 (7.1.1 출시 전)
 *
 * 번들 업데이트 자식 프로세스가 죽으면 행이 `updating` 으로 남는다. 「설치됨」 캐시 목록은 active·inactive 만 세므로
 * 미설치 목록이 그 확장을 다시 실어, 관리자 목록에 「업데이트 중」 카드와 「미설치 · 설치」 카드가 함께 보였다
 * (`module:list` 도 두 줄). 행이 있으면 상태와 무관하게 미설치가 아니다.
 */
class InProgressExtensionListingTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * updating 으로 멈춘 모듈·플러그인·템플릿은 미설치 목록에 없고, 설치 목록에는 그 상태로 한 번만 나온다.
     *
     * @scenario entry_point=listing
     *
     * @effects in_progress_extension_not_listed_as_uninstalled, in_progress_extension_listed_once_with_its_status
     *
     * @return void 반환값 없음
     */
    public function test_stuck_updating_extension_is_not_listed_as_uninstalled(): void
    {
        $cases = [
            [Module::class, 'sirsoft-page', fn () => app(ModuleManager::class), 'getUninstalledModules', 'getInstalledModulesWithDetails', 'loadModules', 'invalidateModuleStatusCache'],
            [Plugin::class, 'sirsoft-daum_postcode', fn () => app(PluginManager::class), 'getUninstalledPlugins', 'getInstalledPluginsWithDetails', 'loadPlugins', 'invalidatePluginStatusCache'],
            [Template::class, 'sirsoft-basic', fn () => app(TemplateManager::class), 'getUninstalledTemplates', 'getInstalledTemplatesWithDetails', 'loadTemplates', 'invalidateTemplateStatusCache'],
        ];

        foreach ($cases as [$model, $identifier, $resolve, $uninstalled, $installed, $load, $invalidate]) {
            $manager = $resolve();
            $manager->{$load}();

            $row = $model::query()->where('identifier', $identifier)->first();
            if ($row === null) {
                $row = new $model;
                $row->forceFill([
                    'identifier' => $identifier,
                    'vendor' => 'sirsoft',
                    'name' => json_encode(['ko' => $identifier]),
                    'version' => '1.0.0',
                    ...($model === Template::class ? ['type' => 'user'] : []),
                ]);
            }
            $row->forceFill(['status' => ExtensionStatus::Updating->value])->save();
            $manager::{$invalidate}();

            $this->assertArrayNotHasKey($identifier, $manager->{$uninstalled}(), "{$identifier}: 진행 중 확장이 미설치 목록에 겹쳐 나온다");

            $listed = array_values(array_filter($manager->{$installed}(), fn (array $item): bool => ($item['identifier'] ?? null) === $identifier));
            $this->assertCount(1, $listed, "{$identifier}: 설치 목록에 한 번만");
            $this->assertSame(ExtensionStatus::Updating->value, $listed[0]['status']);
        }
    }
}
