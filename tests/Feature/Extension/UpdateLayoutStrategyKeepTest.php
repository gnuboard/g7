<?php

namespace Tests\Feature\Extension;

use App\Enums\ExtensionStatus;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Models\Module;
use App\Models\Template;
use App\Models\TemplateLayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * 확장 업데이트의 `layout_strategy=keep` 이 실제로 사용자 수정 레이아웃을 보존하는지
 * 보장하는 회귀 테스트.
 *
 * 배경: `updateModule()` / `updatePlugin()` 의 레이아웃 갱신 단계가 전략을 모르는
 * `registerModuleLayouts()` / `registerPluginLayouts()` 를 전략 인지 refresh **앞에서**
 * 호출했다. 그 메서드는 모든 레이아웃의 content 와 `original_content_hash` 를 파일 기준으로
 * 덮어쓰므로, 뒤이어 실행되는 `refresh*Layouts($preserveModified)` 가 비교할 "수정본" 이
 * 이미 사라져 keep 전략이 언제나 no-op 이 되었다 — 사용자는 '수정 유지' 를 골랐는데도
 * 커스터마이징이 소실됐다.
 *
 * 실제 파일 교체·다운로드를 동반하는 전체 업데이트 경로는 네트워크/디스크에 의존하므로,
 * 여기서는 그 경로가 호출하는 두 단계를 **실제로 실행해** 다음을 고정한다:
 *   1. keep 전략의 보존/덮어쓰기 동작 자체 (§keep/overwrite)
 *   2. 선행 register 가 수정 마커를 파괴한다는 회귀의 인과 (§선행 register 금지)
 *   3. register 를 빼도 신규 레이아웃이 누락되지 않는다는 것 (§created 분기)
 *   4. 업데이트 경로에 그 선행 호출이 남아 있지 않다는 호출 순서 계약 (§호출 계약)
 */
class UpdateLayoutStrategyKeepTest extends TestCase
{
    use RefreshDatabase;

    /** 레이아웃 파일이 실제로 존재하는 설치본 모듈 */
    private const MODULE = 'sirsoft-board';

    private ModuleManager $moduleManager;

    private Template $adminTemplate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->moduleManager = app(ModuleManager::class);

        $this->adminTemplate = Template::create([
            'identifier' => 'test-keep-admin',
            'vendor' => 'test',
            'name' => ['ko' => '보존 테스트 관리자', 'en' => 'Keep Test Admin'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => ExtensionStatus::Active->value,
            'description' => ['ko' => '테스트용', 'en' => 'For testing'],
        ]);

        Module::create([
            'identifier' => self::MODULE,
            'vendor' => 'sirsoft',
            'name' => ['ko' => '게시판', 'en' => 'Board'],
            'version' => '1.0.3',
            'status' => ExtensionStatus::Active->value,
            'description' => ['ko' => '테스트용', 'en' => 'For testing'],
        ]);

        $this->moduleManager->loadModules();

        if (! $this->moduleManager->getModule(self::MODULE)) {
            $this->markTestSkipped(self::MODULE.' 설치본이 없어 레이아웃 보존 동작을 실행할 수 없습니다.');
        }
    }

    /**
     * 배포 상태(파일 기준)로 레이아웃을 DB 에 등록합니다.
     *
     * `registerModuleLayouts()` 는 protected 이므로 리플렉션으로 호출합니다.
     * 이 메서드가 곧 D6 의 원인이 된 "전략 미인지 선덮어쓰기" 단계입니다.
     *
     * @return int 등록된 레이아웃 수
     */
    private function registerFromFiles(): int
    {
        $method = new ReflectionMethod(ModuleManager::class, 'registerModuleLayouts');
        $method->setAccessible(true);

        return (int) $method->invoke($this->moduleManager, self::MODULE);
    }

    /**
     * 사용자가 UI 에서 레이아웃 1건을 수정한 상태를 만듭니다.
     *
     * content 만 바꾸고 original_content_hash 는 배포 원본 그대로 두어야
     * "수정됨" 으로 판정됩니다.
     *
     * @return TemplateLayout 수정된 레이아웃
     */
    private function markOneLayoutAsUserModified(): TemplateLayout
    {
        $layout = TemplateLayout::where('source_type', 'module')
            ->where('source_identifier', self::MODULE)
            ->orderBy('id')
            ->firstOrFail();

        $content = $layout->content;
        $content['__user_marker'] = 'keep-me';
        $layout->content = $content;
        $layout->saveQuietly();

        return $layout->fresh();
    }

    // ========================================================================
    // keep / overwrite 동작
    // ========================================================================

    /**
     * keep 전략은 사용자 수정 레이아웃을 덮어쓰지 않는다.
     */
    public function test_keep_strategy_preserves_user_modified_layout(): void
    {
        $this->assertGreaterThan(0, $this->registerFromFiles(), '레이아웃이 등록되지 않았습니다.');
        $modified = $this->markOneLayoutAsUserModified();

        $this->moduleManager->refreshModuleLayouts(self::MODULE, true);

        $this->assertArrayHasKey(
            '__user_marker',
            $modified->fresh()->content,
            'keep 전략인데 사용자 수정본이 파일 내용으로 덮어써졌습니다.'
        );
    }

    /**
     * overwrite 전략은 사용자 수정 레이아웃을 파일 내용으로 되돌린다 (비회귀).
     */
    public function test_overwrite_strategy_replaces_user_modified_layout(): void
    {
        $this->registerFromFiles();
        $modified = $this->markOneLayoutAsUserModified();

        $this->moduleManager->refreshModuleLayouts(self::MODULE, false);

        $this->assertArrayNotHasKey(
            '__user_marker',
            $modified->fresh()->content,
            'overwrite 전략인데 수정본이 그대로 남았습니다.'
        );
    }

    // ========================================================================
    // 선행 register 금지 (D6 의 인과)
    // ========================================================================

    /**
     * 전략 인지 refresh **앞에서** register 를 호출하면 keep 이 무효가 된다.
     *
     * 이 테스트는 결함 재현을 고정한다 — 여기서 보존이 되어버리면 오히려 전제가 바뀐
     * 것이므로, updateModule 의 호출 계약(아래)과 함께 읽어야 한다.
     */
    public function test_pre_registering_before_refresh_destroys_the_modification_marker(): void
    {
        $this->registerFromFiles();
        $modified = $this->markOneLayoutAsUserModified();

        // 결함 당시 updateModule 이 하던 순서를 그대로 재현
        $this->registerFromFiles();
        $this->moduleManager->refreshModuleLayouts(self::MODULE, true);

        $this->assertArrayNotHasKey(
            '__user_marker',
            $modified->fresh()->content,
            '선행 register 가 수정 마커를 파괴하지 않는다면 이 회귀의 전제가 달라진 것입니다 — 계약을 재검토하세요.'
        );
    }

    /**
     * register 를 빼도 DB 에 없는 레이아웃은 refresh 가 생성한다.
     */
    public function test_refresh_creates_layouts_missing_from_database(): void
    {
        $registered = $this->registerFromFiles();

        TemplateLayout::where('source_type', 'module')
            ->where('source_identifier', self::MODULE)
            ->forceDelete();

        $this->assertSame(0, $this->countModuleLayouts());

        $this->moduleManager->refreshModuleLayouts(self::MODULE, true);

        $this->assertSame(
            $registered,
            $this->countModuleLayouts(),
            'registerModuleLayouts 제거 후 신규 레이아웃이 누락되었습니다 (refresh 의 created 분기 확인 필요).'
        );
    }

    /**
     * 현재 템플릿에 등록된 모듈 레이아웃 수.
     */
    private function countModuleLayouts(): int
    {
        return TemplateLayout::where('template_id', $this->adminTemplate->id)
            ->where('source_type', 'module')
            ->where('source_identifier', self::MODULE)
            ->count();
    }

    // ========================================================================
    // 호출 계약 — 전체 업데이트 경로는 실행 불가하므로 순서만 고정
    // ========================================================================

    /**
     * updateModule / updatePlugin 의 레이아웃 단계에 전략 미인지 선덮어쓰기가 없어야 한다.
     */
    public function test_update_path_does_not_pre_register_layouts(): void
    {
        foreach ([
            [ModuleManager::class, 'updateModule', 'refreshModuleLayouts', 'registerModuleLayouts'],
            [PluginManager::class, 'updatePlugin', 'refreshPluginLayouts', 'registerPluginLayouts'],
        ] as [$class, $method, $refresh, $register]) {
            $source = $this->methodSource($class, $method);

            $this->assertMatchesRegularExpression(
                '/'.preg_quote($refresh, '/').'\(\s*\$identifier\s*,\s*\$preserveModified\s*\)/',
                $source,
                "{$class}::{$method} 는 전략 인지 {$refresh} 를 호출해야 한다"
            );
            $this->assertDoesNotMatchRegularExpression(
                '/\$this->'.preg_quote($register, '/').'\(\s*\$identifier\s*\)/',
                $source,
                "{$class}::{$method} 가 {$register} 를 호출하면 keep 전략이 무효화된다 "
                .'(content/original_content_hash 를 파일 기준으로 선덮어쓰기)'
            );
        }
    }

    /**
     * 지정 메서드의 소스 본문을 반환합니다.
     *
     * @param  string  $class  대상 클래스 FQCN
     * @param  string  $method  대상 메서드명
     * @return string 메서드 본문 소스
     */
    private function methodSource(string $class, string $method): string
    {
        $ref = new ReflectionMethod($class, $method);
        $lines = file($ref->getFileName());

        return implode('', array_slice(
            $lines,
            $ref->getStartLine() - 1,
            $ref->getEndLine() - $ref->getStartLine() + 1
        ));
    }
}
