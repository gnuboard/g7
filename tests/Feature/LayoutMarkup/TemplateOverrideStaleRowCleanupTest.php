<?php

namespace Tests\Feature\LayoutMarkup;

use App\Enums\LayoutExtensionType;
use App\Enums\LayoutSourceType;
use App\Extension\TemplateManager;
use App\Models\LayoutExtension;
use App\Models\Template;
use App\Services\LayoutExtensionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\File;
use ReflectionClass;
use Tests\TestCase;

/**
 * 템플릿 확장 오버라이드 등록이 소스에 더는 없는 옛 행을 정리하는지 검증합니다.
 *
 * 브라우저 실측(#703): 주문서 배송지의 「주소 검색」 버튼이 두 개 그려졌다. 조각 식별자(fragment_key)를 들인
 * 마이그레이션이 기존 행의 키를 대상 이름(`address_search_slot`)으로 백필했는데, 이후 템플릿 오버라이드 등록은
 * 파일 이름(`user-address-search`)을 키로 써 새 행을 만들었다. 모듈·플러그인 갱신 경로는 소스에 없는 조각 행을
 * 지우지만(`removeOrphanFragments`) 템플릿 오버라이드 경로에는 그 정리가 없어 옛 행이 살아남아 두 번 주입됐다.
 * 7.0.11 에서 올라오는 설치는 모두 이 백필을 거치므로 같은 일이 난다.
 */
class TemplateOverrideStaleRowCleanupTest extends TestCase
{
    use DatabaseTransactions;

    private TemplateManager $manager;

    private Template $template;

    private string $root;

    /**
     * 임시 템플릿과 그 소스 디렉토리, 오버라이드 등록에 쓸 관리자 인스턴스를 준비합니다.
     *
     * @return void 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $identifier = 'tpl-stale-'.uniqid();
        $this->template = Template::factory()->create(['identifier' => $identifier]);
        $this->root = base_path("templates/{$identifier}");

        $this->manager = (new ReflectionClass(TemplateManager::class))->newInstanceWithoutConstructor();
        $property = new \ReflectionProperty(TemplateManager::class, 'layoutExtensionService');
        $property->setAccessible(true);
        $property->setValue($this->manager, $this->app->make(LayoutExtensionService::class));
    }

    /**
     * 임시 템플릿 소스 디렉토리를 지웁니다.
     *
     * @return void 없음
     */
    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    /**
     * 오버라이드 조각 파일을 둡니다.
     *
     * @param  string  $target  오버라이드 대상 확장 식별자
     * @param  string  $name  파일 이름(확장자 제외) — 조각 식별자가 된다
     * @param  string  $extensionPoint  주입 자리 이름
     * @return void 없음
     */
    private function putOverride(string $target, string $name, string $extensionPoint): void
    {
        File::ensureDirectoryExists("{$this->root}/extensions/{$target}");
        File::put(
            "{$this->root}/extensions/{$target}/{$name}.json",
            json_encode(['extension_point' => $extensionPoint, 'components' => []])
        );
    }

    /**
     * 마이그레이션 백필로 만들어진 옛 행(키 = 대상 이름)을 둡니다.
     *
     * @param  string  $target  오버라이드 대상 확장 식별자
     * @param  string  $extensionPoint  주입 자리 이름
     * @return LayoutExtension 만든 행
     */
    private function putLegacyRow(string $target, string $extensionPoint): LayoutExtension
    {
        return LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::ExtensionPoint,
            'target_name' => $extensionPoint,
            'fragment_key' => $extensionPoint,
            'source_type' => LayoutSourceType::Template,
            'source_identifier' => $this->template->identifier,
            'override_target' => $target,
            'content' => ['extension_point' => $extensionPoint, 'components' => []],
            'priority' => 50,
            'is_active' => true,
        ]);
    }

    /**
     * 템플릿 오버라이드 등록을 실행합니다.
     *
     * @return array<string, int> 등록 통계
     */
    private function registerOverrides(): array
    {
        $method = new \ReflectionMethod(TemplateManager::class, 'registerExtensionOverrides');
        $method->setAccessible(true);

        return $method->invoke($this->manager, $this->template->identifier, $this->template->id);
    }

    /**
     * 살아 있는 이 템플릿의 오버라이드 행 조각 키를 모읍니다.
     *
     * @return array<int, string> 조각 키 목록(정렬)
     */
    private function liveKeys(): array
    {
        return LayoutExtension::query()
            ->where('template_id', $this->template->id)
            ->where('source_type', LayoutSourceType::Template)
            ->pluck('fragment_key')
            ->sort()
            ->values()
            ->all();
    }

    /**
     * 백필된 옛 행이 있을 때 갱신하면 파일 이름 키 행 하나만 남는다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects template_override_refresh_removes_backfilled_stale_row
     *
     * @return void 없음
     */
    public function test_refresh_removes_backfilled_row_whose_key_is_the_target_name(): void
    {
        $this->putOverride('sirsoft-daum_postcode', 'user-address-search', 'address_search_slot');
        $legacy = $this->putLegacyRow('sirsoft-daum_postcode', 'address_search_slot');

        $this->registerOverrides();

        $this->assertSame(['user-address-search'], $this->liveKeys(), '같은 조각이 두 행으로 남으면 두 번 주입된다');
        $this->assertSoftDeleted($legacy);
    }

    /**
     * 템플릿이 더는 오버라이드하지 않는 대상의 행도 정리하고, 살아 있는 조각은 건드리지 않는다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects template_override_refresh_removes_backfilled_stale_row
     *
     * @return void 없음
     */
    public function test_refresh_removes_rows_of_dropped_override_targets_and_keeps_live_ones(): void
    {
        $this->putOverride('sirsoft-daum_postcode', 'user-address-search', 'address_search_slot');
        $dropped = $this->putLegacyRow('sirsoft-removed_plugin', 'gone_slot');

        $this->registerOverrides();
        $this->registerOverrides();

        $this->assertSame(['user-address-search'], $this->liveKeys(), '재등록을 거듭해도 살아 있는 조각 행은 하나');
        $this->assertSoftDeleted($dropped);
    }

    /**
     * 소스는 있는데 컴파일에 실패한 조각의 행은 지우지 않는다 (모듈·플러그인 갱신과 같은 기준 — 소스 존재).
     *
     * 정리가 「컴파일에 성공한 정의」만 살아 있다고 보면, 한 번의 컴파일 실패로 템플릿 오버라이드 행이 지워져
     * 모듈 원본 조각이 대신 그려진다.
     *
     * @scenario compile_unit=install,source_health=invalid
     *
     * @effects template_override_refresh_keeps_rows_of_failing_sources
     *
     * @return void 없음
     */
    public function test_refresh_keeps_row_of_a_source_that_fails_to_compile(): void
    {
        File::ensureDirectoryExists("{$this->root}/extensions/sirsoft-daum_postcode");
        File::put("{$this->root}/extensions/sirsoft-daum_postcode/user-address-search.g7.html", '<extension extension-point="address_search_slot"><div
');
        $existing = LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::ExtensionPoint,
            'target_name' => 'address_search_slot',
            'fragment_key' => 'user-address-search',
            'source_type' => LayoutSourceType::Template,
            'source_identifier' => $this->template->identifier,
            'override_target' => 'sirsoft-daum_postcode',
            'content' => ['extension_point' => 'address_search_slot', 'components' => []],
            'priority' => 50,
            'is_active' => true,
        ]);

        $this->registerOverrides();

        $this->assertNotSoftDeleted($existing);
    }
}
