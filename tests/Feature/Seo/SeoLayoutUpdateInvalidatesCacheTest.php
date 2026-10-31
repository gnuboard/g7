<?php

namespace Tests\Feature\Seo;

use App\Enums\ExtensionStatus;
use App\Models\Template;
use App\Models\TemplateLayout;
use App\Seo\Contracts\SeoCacheManagerInterface;
use App\Services\LayoutService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * 레이아웃 저장·버전 복원 뒤 봇(SEO) 캐시가 그 화면만 비워지는지 통합 테스트
 *
 * 종전에는 레이아웃을 편집해도 봇 캐시를 지우지 않아 TTL 이 끝날 때까지 옛 SEO 가 검색 봇에게 나갔다.
 * 부모 레이아웃(`_user_base`)을 고치면 그것을 물려받는 자식 화면의 캐시도 지워져야 하고,
 * 다른 레이아웃의 캐시는 남아야 한다 (공개#154).
 *
 * 스키마 재생성(migrate:fresh) 없이 트랜잭션으로 격리한다 — 식별자는 공식 이름과 겹치지 않는 가짜 이름이다.
 */
class SeoLayoutUpdateInvalidatesCacheTest extends TestCase
{
    use DatabaseTransactions;

    private Template $template;

    private SeoCacheManagerInterface $seoCache;

    /**
     * 활성 사용자 템플릿 하나와 부모·자식·무관 레이아웃, 각 화면의 봇 캐시 항목을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->template = Template::create([
            'identifier' => 'g7test-seo154-user',
            'vendor' => 'g7test',
            'name' => ['ko' => '시험 사용자 템플릿', 'en' => 'Test user template'],
            'version' => '1.0.0',
            'type' => 'user',
            'status' => ExtensionStatus::Active->value,
            'description' => ['ko' => '시험', 'en' => 'Test'],
        ]);

        TemplateLayout::create([
            'template_id' => $this->template->id,
            'name' => '_user_base',
            'content' => $this->content('부모'),
        ]);
        TemplateLayout::create([
            'template_id' => $this->template->id,
            'name' => 'g7test-hello.user_memo_list',
            'extends' => '_user_base',
            'content' => array_merge($this->content('메모 목록'), ['extends' => '_user_base']),
        ]);
        TemplateLayout::create([
            'template_id' => $this->template->id,
            'name' => 'g7test_standalone',
            'content' => $this->content('독립 화면'),
        ]);

        $this->seoCache = app(SeoCacheManagerInterface::class);
        $this->seoCache->clearAll();
        $this->seoCache->putWithLayout('/memos', 'ko', '<html>memos</html>', 'g7test-hello.user_memo_list');
        $this->seoCache->putWithLayout('/standalone', 'ko', '<html>standalone</html>', 'g7test_standalone');
    }

    /**
     * 테스트가 남긴 봇 캐시 항목을 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->seoCache->clearAll();
        parent::tearDown();
    }

    /**
     * 부모 레이아웃을 저장하면 그것을 물려받는 모듈 화면의 봇 캐시가 지워지고 무관한 화면은 남습니다.
     *
     * @scenario surface=layout_update_parent
     *
     * @effects layout_change_invalidates_bot_cache_of_that_layout
     *
     * @return void 반환값 없음
     */
    public function test_saving_parent_layout_clears_child_screen_bot_cache_only(): void
    {
        app(LayoutService::class)->updateLayout($this->template->id, '_user_base', [
            'content' => $this->content('부모 수정'),
        ]);

        $this->assertNull($this->seoCache->get('/memos', 'ko'), '자식(모듈) 화면 봇 캐시가 남았다');
        $this->assertSame('<html>standalone</html>', $this->seoCache->get('/standalone', 'ko'), '무관한 화면 봇 캐시가 지워졌다');
    }

    /**
     * 버전 복원도 같은 캐시 정리 경로를 거쳐 그 화면의 봇 캐시를 지웁니다.
     *
     * @scenario surface=layout_restore_version
     *
     * @effects layout_change_invalidates_bot_cache_of_that_layout
     *
     * @return void 반환값 없음
     */
    public function test_restoring_version_clears_that_screen_bot_cache(): void
    {
        $service = app(LayoutService::class);
        $service->updateLayout($this->template->id, 'g7test_standalone', ['content' => $this->content('독립 화면 수정')]);

        // 저장으로 지워진 항목을 다시 채운 뒤 복원만으로 지워지는지 본다
        $this->seoCache->putWithLayout('/standalone', 'ko', '<html>standalone v2</html>', 'g7test_standalone');
        $this->assertNotNull($this->seoCache->get('/standalone', 'ko'));

        $versions = $service->getLayoutVersions($this->template->id, 'g7test_standalone');
        $baseline = collect($versions)->sortBy('version')->first();
        $this->assertNotNull($baseline, '저장 뒤 버전 이력이 없다');

        $service->restoreVersion($this->template->id, 'g7test_standalone', (int) $baseline?->id);

        $this->assertNull($this->seoCache->get('/standalone', 'ko'), '복원 뒤 봇 캐시가 남았다');
    }

    /**
     * 시험 레이아웃 본문을 만듭니다.
     *
     * @param  string  $title  레이아웃 meta.title 값
     * @return array<string, mixed> 레이아웃 본문
     */
    private function content(string $title): array
    {
        return [
            'meta' => ['title' => $title, 'seo' => ['enabled' => true]],
            'data_sources' => [],
            'components' => [
                ['type' => 'basic', 'name' => 'Div', 'props' => ['className' => 'container'], 'children' => []],
            ],
        ];
    }
}
