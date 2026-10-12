<?php

namespace Tests\Unit\Services;

use App\Services\TemplateService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 편집기 트리의 모달 조각 해석이 layouts 폴더 경계를 지키는지 봉인한다 (#144 같은 계열).
 *
 * 종전 판정은 문자열 접두라 `…/layouts` 기준에 이름이 비슷한 형제 `…/layouts-x` 의 파일이 통과해, 편집기 트리에
 * layouts 밖 파일의 모달이 실렸다.
 */
class TemplateServiceResolveModalPartialTest extends TestCase
{
    /** 임시 트리 최상위 */
    private string $tree;

    /** layouts 폴더 */
    private string $layouts;

    /**
     * layouts 폴더와 형제 폴더에 모달 조각을 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->tree = storage_path('framework/testing/modal_partial_'.uniqid());
        $this->layouts = $this->tree.'/layouts';

        File::makeDirectory($this->layouts.'/admin', 0777, true);
        File::makeDirectory($this->layouts.'/partials/admin', 0777, true);
        File::makeDirectory($this->tree.'/layouts-x', 0777, true);

        File::put($this->layouts.'/partials/admin/_modal_ok.json', json_encode(['id' => 'modal_ok', 'type' => 'composite', 'name' => 'Modal']));
        File::put($this->tree.'/layouts-x/_modal_evil.json', json_encode(['id' => 'modal_evil', 'type' => 'composite', 'name' => 'Modal']));
    }

    /**
     * 임시 트리를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        File::deleteDirectory($this->tree);

        parent::tearDown();
    }

    /**
     * 같은 layouts 안의 모달 조각은 해석되고, 이름이 비슷한 형제 폴더의 조각은 null 이다.
     *
     * @scenario case=modal_partial_boundary
     *
     * @effects editor_modal_partial_rejects_sibling_prefix_directory
     *
     * @return void 반환값 없음
     */
    public function test_modal_partial_inside_layouts_resolves_and_sibling_prefix_is_rejected(): void
    {
        $service = app(TemplateService::class);
        $method = new \ReflectionMethod($service, 'resolveModalPartial');

        $inside = $method->invoke($service, 'partials/admin/_modal_ok.json', $this->layouts, $this->layouts.'/admin');
        $this->assertSame('modal_ok', $inside['id'] ?? null);

        $sibling = $method->invoke($service, '../../layouts-x/_modal_evil.json', $this->layouts, $this->layouts.'/admin');
        $this->assertNull($sibling, 'layouts 와 이름이 비슷한 형제 폴더의 모달 조각이 해석됐습니다.');
    }
}
