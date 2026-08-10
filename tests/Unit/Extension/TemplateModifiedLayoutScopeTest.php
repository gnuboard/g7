<?php

namespace Tests\Unit\Extension;

use App\Enums\ExtensionStatus;
use App\Extension\TemplateManager;
use App\Extension\Traits\ComputesLayoutContentHash;
use App\Models\Template;
use App\Models\TemplateLayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 템플릿 수정 레이아웃 감지의 소유 범위 회귀 테스트.
 *
 * 배경(Chrome MCP 정밀 점검에서 실측): `hasModifiedLayouts()` 가
 * `getByTemplateId()` 로 해당 template_id 의 **모든** 레이아웃을 세어,
 * 같은 템플릿에 등록된 모듈/플러그인 소유 레이아웃의 수정본까지 템플릿 자신의
 * 수정으로 집계했다(실측: 모달 4건 중 3건이 board/gdpr/sample 소유).
 * 실제 갱신 범위인 `refreshTemplateLayouts()` 는 source_type='template' +
 * source_identifier=null 만 다루므로 표시와 동작이 어긋난다.
 *
 * 조회 소스의 철자가 아니라 **실제 집계 결과**로 범위를 고정한다.
 */
class TemplateModifiedLayoutScopeTest extends TestCase
{
    use ComputesLayoutContentHash, RefreshDatabase;

    private TemplateManager $templateManager;

    private Template $template;

    /** 배포 원본 콘텐츠 */
    private const ORIGINAL = ['layout_name' => 'home', 'slots' => ['content' => []]];

    /** 사용자가 UI 에서 수정한 콘텐츠 */
    private const MODIFIED = ['layout_name' => 'home', 'slots' => ['content' => [['component' => 'Div']]]];

    protected function setUp(): void
    {
        parent::setUp();

        $this->templateManager = app(TemplateManager::class);

        $this->template = Template::create([
            'identifier' => 'test-scope-admin',
            'vendor' => 'test',
            'name' => ['ko' => '범위 테스트 관리자', 'en' => 'Scope Test Admin'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => ExtensionStatus::Active->value,
            'description' => ['ko' => '테스트용', 'en' => 'For testing'],
        ]);
    }

    /**
     * 레이아웃 1건을 시드합니다.
     *
     * original_content_hash 는 항상 배포 원본 기준으로 기록하고, content 만 수정본으로
     * 바꿔 "사용자가 UI 에서 고친 상태" 를 재현합니다.
     *
     * @param  int  $templateId  소속 템플릿 ID
     * @param  string  $name  레이아웃 이름
     * @param  string  $sourceType  소유 구분 (template/module/plugin)
     * @param  string|null  $sourceIdentifier  소유 확장 식별자 (템플릿 소유는 null)
     * @param  bool  $modified  true 면 수정된 상태로 시드
     * @return TemplateLayout 생성된 레이아웃
     */
    private function seedLayout(
        int $templateId,
        string $name,
        string $sourceType,
        ?string $sourceIdentifier,
        bool $modified
    ): TemplateLayout {
        return TemplateLayout::create([
            'template_id' => $templateId,
            'name' => $name,
            'content' => $modified ? self::MODIFIED : self::ORIGINAL,
            'source_type' => $sourceType,
            'source_identifier' => $sourceIdentifier,
            'original_content_hash' => $this->computeContentHash(self::ORIGINAL),
            'original_content_size' => $this->computeContentSize(self::ORIGINAL),
        ]);
    }

    /**
     * 템플릿 소유 수정본만 집계하고 남의 확장 소유는 제외해야 한다.
     */
    public function test_has_modified_layouts_counts_only_template_owned_layouts(): void
    {
        $this->seedLayout($this->template->id, 'admin_home', 'template', null, true);

        // 같은 template_id 에 얹힌 남의 확장 소유 수정본 3건
        $this->seedLayout($this->template->id, 'sirsoft-board.admin_index', 'module', 'sirsoft-board', true);
        $this->seedLayout($this->template->id, 'sirsoft-page.admin_index', 'module', 'sirsoft-page', true);
        $this->seedLayout($this->template->id, 'sirsoft-gdpr.admin_index', 'plugin', 'sirsoft-gdpr', true);

        $result = $this->templateManager->hasModifiedLayouts('test-scope-admin');

        $this->assertTrue($result['has_modified_layouts']);
        $this->assertSame(
            1,
            $result['modified_count'],
            '템플릿 업데이트가 실제로 갱신하지 않는 모듈/플러그인 소유 레이아웃이 수정 건수에 합산되었습니다.'
        );
        $this->assertSame(['admin_home'], array_column($result['modified_layouts'], 'name'));
    }

    /**
     * 남의 확장만 수정된 상태에서는 경고가 뜨면 안 된다.
     */
    public function test_has_modified_layouts_returns_zero_when_only_other_extensions_are_modified(): void
    {
        $this->seedLayout($this->template->id, 'admin_home', 'template', null, false);
        $this->seedLayout($this->template->id, 'sirsoft-board.admin_index', 'module', 'sirsoft-board', true);

        $result = $this->templateManager->hasModifiedLayouts('test-scope-admin');

        $this->assertFalse($result['has_modified_layouts']);
        $this->assertSame(0, $result['modified_count']);
        $this->assertEmpty($result['modified_layouts']);
    }

    /**
     * 다른 템플릿의 수정본은 집계 대상이 아니다.
     */
    public function test_has_modified_layouts_ignores_layouts_of_other_templates(): void
    {
        $other = Template::create([
            'identifier' => 'test-scope-user',
            'vendor' => 'test',
            'name' => ['ko' => '범위 테스트 사용자', 'en' => 'Scope Test User'],
            'version' => '1.0.0',
            'type' => 'user',
            'status' => ExtensionStatus::Active->value,
            'description' => ['ko' => '테스트용', 'en' => 'For testing'],
        ]);

        $this->seedLayout($other->id, 'user_home', 'template', null, true);
        $this->seedLayout($this->template->id, 'admin_home', 'template', null, false);

        $result = $this->templateManager->hasModifiedLayouts('test-scope-admin');

        $this->assertSame(0, $result['modified_count'], '다른 템플릿의 수정본까지 집계되었습니다.');
    }

    /**
     * 미존재 템플릿은 빈 결과를 반환한다.
     */
    public function test_has_modified_layouts_returns_empty_for_unknown_template(): void
    {
        $result = $this->templateManager->hasModifiedLayouts('no-such-template');

        $this->assertFalse($result['has_modified_layouts']);
        $this->assertSame(0, $result['modified_count']);
        $this->assertEmpty($result['modified_layouts']);
    }
}
