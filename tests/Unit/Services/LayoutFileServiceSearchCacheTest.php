<?php

namespace Tests\Unit\Services;

use App\Enums\ExtensionStatus;
use App\Enums\LayoutSourceType;
use App\Models\Template;
use App\Models\TemplateLayout;
use App\Services\LayoutFileService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * 모듈·플러그인 소유 레이아웃 행의 역변환 캐시 (#712 F6b).
 *
 * 참조 점검(`findReferences` — 삭제·이름 변경 전)은 그 행을 역변환할 때 행의 `source_identifier`(모듈 식별자)를
 * 템플릿 자리에 넘긴다. 역변환이 템플릿 표에 의존하던 때는 그 호출이 실패해 빈 마크업이 캐시에 남았고, 같은 행의
 * 본문 검색이 캐시 수명(24시간) 동안 빗나갔으며 참조 줄 번호는 0 이었다. 7.1.0 의 역변환은 템플릿과 무관하므로
 * (2026-09-28 결정) 같은 행은 어느 문맥으로 역변환해도 같은 마크업이어야 한다 — 그 성질을 잠근다.
 */
class LayoutFileServiceSearchCacheTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 참조 점검이 먼저 역변환·캐시한 뒤에도 모듈 소유 행의 본문 검색이 적중하고, 참조 줄 번호가 채워진다.
     *
     * @scenario case=search_cache_module_row
     *
     * @effects module_row_search_hits_after_reference_check
     *
     * @return void 반환값 없음
     */
    public function test_module_row_is_searchable_after_reference_check(): void
    {
        $template = Template::query()->where('identifier', 'sirsoft-admin_basic')->first()
            ?? Template::factory()->create(['identifier' => 'sirsoft-admin_basic', 'type' => 'admin', 'status' => ExtensionStatus::Active->value]);

        TemplateLayout::factory()->create([
            'template_id' => $template->id,
            'name' => 'probe_cache_frag',
            'content' => ['layout_name' => 'probe_cache_frag', 'version' => '1.0.0', 'components' => [['type' => 'basic', 'name' => 'Div']]],
        ]);
        $moduleRow = TemplateLayout::factory()->create([
            'template_id' => $template->id,
            'name' => 'sirsoft-probecache.probe_list',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-probecache',
            'content' => [
                'layout_name' => 'probe_list',
                'version' => '1.0.0',
                'components' => [
                    ['type' => 'basic', 'name' => 'Span', 'text' => 'probecachekeyword'],
                    ['partial' => 'probe_cache_frag.json'],
                ],
            ],
        ]);

        $service = app(LayoutFileService::class);

        $references = $service->findReferences($template->id, 'probe_cache_frag');
        $reference = collect($references)->firstWhere('name', $moduleRow->name);

        $this->assertNotNull($reference, '전제: 모듈 행이 참조로 잡힌다');
        $this->assertGreaterThan(0, $reference['line'], '참조 줄 번호가 0 — 모듈 행 역변환이 실패했다');

        $hits = array_column($service->search($template->id, 'sirsoft-admin_basic', 'probecachekeyword', 'content'), 'name');

        $this->assertContains($moduleRow->name, $hits, '참조 점검 뒤 모듈 행의 본문 검색이 빗나간다 — 역변환 캐시가 오염됐다');
    }
}
