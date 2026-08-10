<?php

namespace Tests\Unit\Repositories;

use App\Models\Template;
use App\Models\TemplateLayout;
use App\Repositories\LayoutRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * LayoutRepository::deleteByName 단위 테스트.
 *
 * 이름으로 재등록될 수 있는 레이아웃(파일 → DB 동기화 대상)은 soft delete 잔여 행이 남으면
 * 재등록이 충돌하므로 `forceDelete` 여야 한다. 삭제 범위가 템플릿 경계를 넘지 않는지도 함께 잠근다.
 */
class LayoutRepositoryDeleteByNameTest extends TestCase
{
    use RefreshDatabase;

    private LayoutRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = app(LayoutRepository::class);
    }

    /**
     * @effects delete_by_name_force_deletes_matching_layout
     */
    #[Test]
    public function delete_by_name_force_deletes_matching_layout(): void
    {
        $template = Template::factory()->create();
        TemplateLayout::factory()->create([
            'template_id' => $template->id,
            'name' => 'e2e_sandbox',
        ]);

        $this->assertTrue($this->repository->deleteByName($template->id, 'e2e_sandbox'));

        // soft delete 잔여 행이 남으면 같은 이름 재등록이 유니크 제약과 충돌한다.
        $this->assertFalse(
            TemplateLayout::withTrashed()
                ->where('template_id', $template->id)
                ->where('name', 'e2e_sandbox')
                ->exists()
        );
    }

    /**
     * @effects delete_by_name_returns_false_when_absent
     */
    #[Test]
    public function delete_by_name_returns_false_when_absent(): void
    {
        $template = Template::factory()->create();

        $this->assertFalse($this->repository->deleteByName($template->id, 'no_such_layout'));
    }

    /**
     * @effects delete_by_name_does_not_touch_same_name_in_other_template
     */
    #[Test]
    public function delete_by_name_does_not_touch_same_name_in_other_template(): void
    {
        $target = Template::factory()->create();
        $other = Template::factory()->create();

        TemplateLayout::factory()->create(['template_id' => $target->id, 'name' => 'e2e_sandbox']);
        TemplateLayout::factory()->create(['template_id' => $other->id, 'name' => 'e2e_sandbox']);

        $this->repository->deleteByName($target->id, 'e2e_sandbox');

        $this->assertTrue(
            TemplateLayout::where('template_id', $other->id)->where('name', 'e2e_sandbox')->exists(),
            '다른 템플릿의 동명 레이아웃은 삭제되지 않아야 한다'
        );
    }
}
