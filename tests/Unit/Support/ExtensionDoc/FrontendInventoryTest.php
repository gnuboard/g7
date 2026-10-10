<?php

namespace Tests\Unit\Support\ExtensionDoc;

use App\Support\ExtensionDoc\ExtensionInventory;
use App\Support\ExtensionDoc\FrontendInventory;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 확장 문서 생성기의 재등록 진입점 판정을 고정합니다 (#639 ④).
 *
 * 로케일을 바꾸면 코어가 ActionDispatcher 를 새로 만들고 `window.__{확장}.initPlugin()` 을 불러
 * 핸들러를 다시 꽂는다. 핸들러를 등록하면서 그 전역을 두지 않은 확장은 언어 전환 뒤 액션이
 * 조용히 사라진다(실측 2026-10-05: TOTP `copyToClipboard` 가 ko→en 전환 뒤 디스패처에서 빠짐).
 *
 * 생성기가 이 상태를 「엔트리 없음」으로 적어 경고가 묻혔다. TOTP 는 `registerHandler` 를 변수로
 * 떼어 별칭(`register.call(dispatcher, …)`)으로 부르므로, 호출 문자열만 찾으면 등록 사실을 놓친다.
 */
class FrontendInventoryTest extends TestCase
{
    /** @var string 임시 확장 루트 */
    private string $root;

    /**
     * 테스트마다 비어 있는 임시 확장 루트를 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->root = storage_path('framework/testing/frontend-inventory-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($this->root);
    }

    /**
     * 임시 확장 루트를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    /**
     * 진입 파일 하나를 가진 임시 확장의 진입점 판정을 돌려준다.
     *
     * @param  string  $relative  진입 파일 상대 경로
     * @param  string  $source  진입 파일 내용
     * @param  string  $type  확장 유형
     * @return array<string, mixed> 진입점 판정 결과(entryPoints)
     */
    private function entryPointsFor(string $relative, string $source, string $type = ExtensionInventory::TYPE_PLUGIN): array
    {
        $file = $this->root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
        File::ensureDirectoryExists(dirname($file));
        File::put($file, $source);

        return (new FrontendInventory)->collect([
            'path' => $this->root,
            'type' => $type,
            'id' => 'sirsoft-fixture',
        ])['entryPoints'];
    }

    /**
     * 별칭으로 핸들러를 등록하면서 재등록 전역을 두지 않은 확장을 누락으로 판정한다. (선-실패)
     *
     * @scenario entry=plugin, register=alias, global=absent
     *
     * @effects reinit_global_missing_is_reported
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 별칭으로_핸들러를_등록하고_전역이_없으면_재등록_전역_누락으로_본다(): void
    {
        $entry = $this->entryPointsFor('resources/js/index.ts', <<<'TS'
function registerHandlers(): number {
    const dispatcher = (window as any).G7Core?.getActionDispatcher?.();
    const register = dispatcher.registerHandler as (name: string, handler: unknown) => void;
    register.call(dispatcher, 'sirsoft-fixture.copy', () => {});
    return 1;
}
registerHandlers();
TS);

        $this->assertTrue($entry['registersHandlers']);
        $this->assertNull($entry['global']);
        $this->assertTrue($entry['missingReinit']);
    }

    /**
     * 화살표 함수로 선언한 initPlugin 도 재등록 진입점으로 인정한다.
     *
     * @scenario entry=plugin, register=direct, global=present
     *
     * @effects arrow_init_function_is_recognized
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 화살표_함수_init_plugin_도_재등록_진입점으로_인정한다(): void
    {
        $entry = $this->entryPointsFor('resources/js/index.ts', <<<'TS'
const initPlugin = (): void => {
    (window as any).G7Core.getActionDispatcher().registerHandler('sirsoft-fixture.a', () => {});
};
(window as unknown as Record<string, unknown>).__SirsoftFixture = { identifier: 'sirsoft-fixture', initPlugin };
initPlugin();
TS);

        $this->assertSame('__SirsoftFixture', $entry['global']);
        $this->assertSame('initPlugin', $entry['initFunction']);
        $this->assertFalse($entry['missingReinit']);
    }

    /**
     * 템플릿의 G7TemplateHandlers 전역은 재등록 경로로 인정해 거짓 경고를 내지 않는다.
     *
     * @scenario entry=template, register=handler_map, global=template_handlers
     *
     * @effects template_handler_global_is_recognized
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 템플릿의_g7_template_handlers_전역은_재등록_경로로_인정한다(): void
    {
        $entry = $this->entryPointsFor('src/index.ts', <<<'TS'
const handlerMap = { a: () => {} };
if (typeof window !== 'undefined') {
  (window as any).G7TemplateHandlers = handlerMap;
}
TS, ExtensionInventory::TYPE_TEMPLATE);

        $this->assertSame('G7TemplateHandlers', $entry['global']);
        $this->assertFalse($entry['missingReinit']);
    }

    /**
     * 핸들러를 등록하지 않는 진입 파일은 재등록 전역 누락으로 보지 않는다.
     *
     * @scenario entry=plugin, register=none, global=absent
     *
     * @effects non_registering_entry_is_not_flagged
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 핸들러를_등록하지_않는_진입_파일은_누락으로_보지_않는다(): void
    {
        $entry = $this->entryPointsFor('resources/js/index.ts', <<<'TS'
document.addEventListener('DOMContentLoaded', () => {});
TS);

        $this->assertFalse($entry['registersHandlers']);
        $this->assertFalse($entry['missingReinit']);
    }
}
