<?php

namespace Tests\Feature\LayoutMarkup;

use App\Enums\ExtensionStatus;
use App\Enums\LayoutSourceType;
use App\Models\LayoutExtension;
use App\Models\Template;
use App\Services\LayoutExtensionService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * 템플릿 오버라이드 행의 신원에 오버라이드 대상(`override_target`)이 들어가는지 검증합니다 (#703).
 *
 * 7.0.11 은 오버라이드 행을 여섯 축(대상 확장 포함)으로 갈랐는데, 조각 식별자 마이그레이션의 고유 키는 대상 확장을
 * 빼고 걸었다. 한 템플릿이 같은 확장점을 두 확장에 대해 오버라이드하면 백필 뒤 두 행의 키가 같아져 고유 키 추가가
 * 실패하고(코어 업그레이드 중단), 새로 등록할 때도 두 번째 대상의 오버라이드가 중복 키로 등록되지 않는다.
 */
class LayoutExtensionOverrideTargetKeyTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 같은 확장점·같은 파일 이름의 오버라이드를 두 대상에 대해 등록하면 두 행이 산다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects override_rows_for_two_targets_coexist
     *
     * @return void 없음
     */
    public function test_same_fragment_overridden_for_two_targets_registers_two_rows(): void
    {
        $template = Template::factory()->create([
            'identifier' => 'tpl-otk-'.uniqid(),
            'type' => 'user',
            'status' => ExtensionStatus::Active->value,
        ]);
        $content = ['extension_point' => 'shared_slot', 'components' => []];
        $service = app(LayoutExtensionService::class);

        $service->registerTemplateOverride($content, $template->identifier, 'vendor-first', $template->id, 'shared-override');
        $service->registerTemplateOverride($content, $template->identifier, 'vendor-second', $template->id, 'shared-override');

        $targets = LayoutExtension::where('template_id', $template->id)
            ->where('source_type', LayoutSourceType::Template)
            ->orderBy('override_target')
            ->pluck('override_target')
            ->all();

        $this->assertSame(['vendor-first', 'vendor-second'], $targets);
    }

    /**
     * 모듈·플러그인 행(오버라이드 대상 없음)은 여전히 같은 조각 둘을 가질 수 없다 — NULL 이 고유성을 무력화하지 않는다.
     *
     * @scenario compile_unit=install,source_health=invalid
     *
     * @effects override_rows_for_two_targets_coexist
     *
     * @return void 없음
     */
    public function test_module_rows_without_override_target_stay_unique(): void
    {
        $template = Template::factory()->create([
            'identifier' => 'tpl-otk-'.uniqid(),
            'type' => 'user',
            'status' => ExtensionStatus::Active->value,
        ]);
        $row = [
            'template_id' => $template->id,
            'extension_type' => 'extension_point',
            'target_name' => 'shared_slot',
            'fragment_key' => 'dup',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'vendor-first',
            'content' => ['extension_point' => 'shared_slot'],
            'priority' => 100,
            'is_active' => true,
        ];
        LayoutExtension::create($row);

        $this->expectException(QueryException::class);
        LayoutExtension::create($row);
    }
}
