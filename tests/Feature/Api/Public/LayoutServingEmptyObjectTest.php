<?php

namespace Tests\Feature\Api\Public;

use App\Enums\ExtensionStatus;
use App\Models\Template;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 레이아웃 서빙이 빈 객체({})를 빈 배열([])로 바꾸지 않는지 검증한다.
 *
 * 레이아웃 원문(파일·DB)은 이름표로 값을 꺼내는 자리를 `{}` 로 두는데(예: 상품 상세의 추가 옵션·상품평 옵션 필터,
 * 주문서 확장 칸), 서빙 응답에서는 `[]` 로 내려가 화면 상태가 빈 목록으로 시작한다. 원문을 고쳐도 런타임에
 * 반영되지 않는다(이커머스 전수 실측 S4 — sirsoft-basic 1.2.0 SB-160 대조 중 발견).
 */
class LayoutServingEmptyObjectTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 저장된 레이아웃의 `{}` 는 서빙 응답에서도 `{}` 로 내려간다.
     *
     * @scenario container=object_and_array_side_by_side
     *
     * @effects empty_object_served_as_object, empty_array_served_as_array
     *
     * @return void 반환값 없음
     */
    public function test_served_layout_keeps_empty_objects(): void
    {
        $template = Template::create([
            'identifier' => 'e130s4-empty-object-tpl',
            'vendor' => 'e130s4',
            'name' => ['ko' => '빈 객체 검증 템플릿', 'en' => 'Empty Object Template'],
            'version' => '1.0.0',
            'type' => 'user',
            'status' => ExtensionStatus::Active->value,
            'description' => ['ko' => '검증용', 'en' => 'test'],
        ]);

        DB::table('template_layouts')->insert([
            'template_id' => $template->id,
            'name' => 'empty_object_probe',
            'content' => '{"meta":{"title":"probe"},"init_actions":[{"handler":"setState","params":{"target":"local","filters":{},"items":[]}}],"components":[]}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson("/api/layouts/{$template->identifier}/empty_object_probe.json");

        $response->assertOk();
        $raw = $response->getContent();
        $this->assertStringContainsString('"filters":{}', $raw, '빈 객체가 빈 배열로 바뀌어 서빙됐다');
        $this->assertStringContainsString('"items":[]', $raw);
    }
}
