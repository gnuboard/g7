<?php

namespace Tests\Unit\Services;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Repositories\LayoutRepositoryInterface;
use App\Enums\ExtensionStatus;
use App\Models\Template;
use App\Models\TemplateLayout;
use App\Services\LayoutService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 베이스 레이아웃을 저장하면 그 베이스를 본문으로만 상속하는 화면의 캐시도 지워지는지 잠근다.
 *
 * 템플릿 자기 레이아웃은 설치·갱신 때 `extends` 칸을 비워 두고 본문(`content.extends`)으로만 상속을
 * 선언한다. 자식 찾기가 칸만 보면 공통 레이아웃(`_user_base`)을 고쳐도 그것을 쓰는 화면들의 병합
 * 캐시·공개 서빙 캐시가 그대로 남아, 베이스에서 바꾼 값(예: 스크롤 복원 기본값)이 화면에 닿지 않는다.
 */
class LayoutServiceDependentCacheContentExtendsTest extends TestCase
{
    use DatabaseTransactions;

    private LayoutService $layoutService;

    private Template $template;

    /**
     * 활성 템플릿과 LayoutService 를 준비한다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->layoutService = app(LayoutService::class);
        $this->template = Template::factory()->create([
            'type' => 'user',
            'status' => ExtensionStatus::Active->value,
        ]);
    }

    /**
     * 베이스를 편집기로 저장하면, 칸이 비고 본문으로만 상속하는 화면의 다음 병합 결과에 새 meta 가 실린다.
     *
     * @scenario case=save_base_content_only_child
     *
     * @effects saving_base_invalidates_content_only_child_merged_and_serving_cache
     *
     * @return void 반환값 없음
     */
    public function test_saving_base_invalidates_child_that_extends_only_in_content(): void
    {
        $base = $this->makeBase(['on_reload' => true]);
        $this->makeContentOnlyChild('board/index');

        // 저장 전 병합본이 캐시에 남는다
        $before = $this->layoutService->loadAndMergeLayout($this->template->id, 'board/index');
        $this->assertSame(['on_reload' => true], $before['meta']['scroll_restoration'] ?? null);

        // 공개 서빙 캐시도 남아 있는 상태를 만든다
        $cache = app(CacheInterface::class);
        $servingKey = "layout.{$this->template->identifier}.board/index.v".LayoutService::getExtensionCacheVersion();
        $cache->put($servingKey, ['stale' => true], 3600);

        // 편집기 저장 — 베이스 meta 를 바꾼다
        $content = $this->baseContent(['on_reload' => false]);
        $this->layoutService->updateLayout($this->template->id, '_user_base', [
            'content' => $content,
            'expected_lock_version' => (int) $base->fresh()->lock_version,
        ]);

        $after = $this->layoutService->loadAndMergeLayout($this->template->id, 'board/index');
        $this->assertSame(['on_reload' => false], $after['meta']['scroll_restoration'] ?? null,
            '베이스 저장 뒤에도 본문 상속 화면이 저장 전 병합본을 돌려준다');
        $this->assertNull($cache->get($servingKey), '본문 상속 화면의 공개 서빙 캐시가 남았다');
    }

    /**
     * 베이스 버전 복원도 본문으로만 상속하는 화면의 병합 캐시를 지운다.
     *
     * @scenario case=restore_base_version_content_only_child
     *
     * @effects restoring_base_version_invalidates_content_only_child
     *
     * @return void 반환값 없음
     */
    public function test_restoring_base_version_invalidates_content_only_child(): void
    {
        $base = $this->makeBase(['on_reload' => true]);
        $this->makeContentOnlyChild('home');

        $this->layoutService->updateLayout($this->template->id, '_user_base', [
            'content' => $this->baseContent(['on_reload' => false]),
            'expected_lock_version' => (int) $base->fresh()->lock_version,
        ]);
        $this->assertSame(['on_reload' => false],
            $this->layoutService->loadAndMergeLayout($this->template->id, 'home')['meta']['scroll_restoration'] ?? null);

        // 첫 저장이 남긴 기준 판(v1 = on_reload true)으로 되돌린다
        $baseline = $base->fresh()->versions()->reorder('version')->first();
        $this->layoutService->restoreVersion($this->template->id, '_user_base', (int) $baseline->id);

        $this->assertSame(['on_reload' => true],
            $this->layoutService->loadAndMergeLayout($this->template->id, 'home')['meta']['scroll_restoration'] ?? null,
            '베이스 복원 뒤에도 본문 상속 화면이 복원 전 병합본을 돌려준다');
    }

    /**
     * 자식 찾기는 칸·본문 어느 쪽 선언도 보되, 이름이 접두로 겹치는 다른 부모는 자식으로 세지 않는다.
     *
     * @scenario case=lookup_column_and_content
     *
     * @effects children_lookup_reads_column_and_content_exactly
     *
     * @return void 반환값 없음
     */
    public function test_children_lookup_reads_column_and_content_exactly(): void
    {
        $this->makeBase(['on_reload' => true]);
        $this->makeContentOnlyChild('board/index');

        // 편집기로 저장된 행 — 칸이 채워져 있다
        TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
            'name' => 'home',
            'extends' => '_user_base',
            'content' => ['extends' => '_user_base', 'slots' => ['content' => []]],
        ]);

        // 이름이 접두로 겹치는 다른 부모를 상속하는 행
        TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
            'name' => '_user_base_extra',
            'content' => ['layout_name' => '_user_base_extra', 'components' => []],
        ]);
        TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
            'name' => 'other/page',
            'content' => ['extends' => '_user_base_extra', 'slots' => ['content' => []]],
        ]);

        // 지운 행은 자식이 아니다
        TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
            'name' => 'trashed/page',
            'content' => ['extends' => '_user_base', 'slots' => ['content' => []]],
        ])->delete();

        // 다른 템플릿의 행도 자식이 아니다
        TemplateLayout::factory()->create([
            'name' => 'foreign/page',
            'content' => ['extends' => '_user_base', 'slots' => ['content' => []]],
        ]);

        $names = app(LayoutRepositoryInterface::class)
            ->getChildrenByExtends($this->template->id, '_user_base')
            ->pluck('name')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(['board/index', 'home'], $names);
    }

    /**
     * 부모 이름에 `/` 가 있으면 저장 JSON 의 `\/`·`/` 두 표기 모두 자식으로 잡고, 다른 키의 같은 값은 세지 않는다.
     *
     * @scenario case=lookup_slash_name_encodings
     *
     * @effects children_lookup_handles_both_slash_encodings
     *
     * @return void 반환값 없음
     */
    public function test_children_lookup_handles_slash_names_in_both_encodings(): void
    {
        TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
            'name' => 'layouts/_base',
            'content' => ['layout_name' => '_base', 'components' => []],
        ]);

        // 배열 캐스트 저장 — json_encode 기본값이라 `/` 가 `\/` 로 적힌다
        TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
            'name' => 'escaped/page',
            'content' => ['extends' => 'layouts/_base', 'slots' => ['content' => []]],
        ]);

        // 원문 저장 — `/` 를 그대로 적은 본문
        $raw = TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
            'name' => 'raw/page',
        ]);
        DB::table('template_layouts')->where('id', $raw->id)->update([
            'content' => json_encode(['extends' => 'layouts/_base', 'slots' => ['content' => []]], JSON_UNESCAPED_SLASHES),
        ]);

        // 같은 값을 다른 키에 둔 행 — 상속이 아니다
        TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
            'name' => 'mention/page',
            'content' => ['layout_name' => 'mention', 'meta' => ['note' => 'layouts/_base'], 'components' => []],
        ]);

        $names = app(LayoutRepositoryInterface::class)
            ->getChildrenByExtends($this->template->id, 'layouts/_base')
            ->pluck('name')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(['escaped/page', 'raw/page'], $names);
    }

    /**
     * 베이스 레이아웃 행을 만든다 — 설치 경로처럼 `extends` 칸은 비운다.
     *
     * @param  array<string, mixed>  $scroll  meta.scroll_restoration 값
     * @return TemplateLayout 베이스 행
     */
    private function makeBase(array $scroll): TemplateLayout
    {
        return TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
            'name' => '_user_base',
            'extends' => null,
            'content' => $this->baseContent($scroll),
            'lock_version' => 0,
        ]);
    }

    /**
     * 베이스 레이아웃 본문.
     *
     * @param  array<string, mixed>  $scroll  meta.scroll_restoration 값
     * @return array<string, mixed> 본문
     */
    private function baseContent(array $scroll): array
    {
        return [
            'version' => '1.0.0',
            'layout_name' => '_user_base',
            'meta' => ['is_base' => true, 'scroll_restoration' => $scroll],
            'components' => [
                ['id' => 'main', 'type' => 'basic', 'name' => 'Div', 'children' => [
                    ['type' => 'slot', 'name' => 'content'],
                ]],
            ],
        ];
    }

    /**
     * 템플릿 설치 경로와 같은 모양의 화면 행을 만든다 — 칸은 비고 본문에만 extends 가 있다.
     *
     * @param  string  $name  레이아웃 이름
     * @return TemplateLayout 화면 행
     */
    private function makeContentOnlyChild(string $name): TemplateLayout
    {
        return TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
            'name' => $name,
            'extends' => null,
            'content' => [
                'version' => '1.0.0',
                'layout_name' => $name,
                'extends' => '_user_base',
                'meta' => ['title' => $name],
                'slots' => [
                    'content' => [['type' => 'basic', 'name' => 'Div']],
                ],
            ],
        ]);
    }
}
