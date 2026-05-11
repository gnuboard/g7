<?php

namespace Modules\Gnuboard7\HelloModule\Tests\Feature\Admin;

require_once __DIR__.'/../../FeatureTestCase.php';

use App\Models\User;
use Modules\Gnuboard7\HelloModule\Models\Memo;
use Modules\Gnuboard7\HelloModule\Tests\FeatureTestCase;
use Tests\Helpers\ProtectsExtensionDirectories;

/**
 * 관리자 메모 컨트롤러 Feature 테스트
 *
 * Admin CRUD 각 1건을 검증합니다.
 */
class MemoControllerTest extends FeatureTestCase
{
    use ProtectsExtensionDirectories;

    protected User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpExtensionProtection();

        $this->adminUser = $this->createAdminUser([
            'gnuboard7-hello_module.memos.read',
            'gnuboard7-hello_module.memos.create',
            'gnuboard7-hello_module.memos.update',
            'gnuboard7-hello_module.memos.delete',
        ]);
    }

    protected function tearDown(): void
    {
        $this->tearDownExtensionProtection();

        Memo::query()->delete();

        parent::tearDown();
    }

    /**
     * 메모 목록을 조회할 수 있는지 확인합니다.
     */
    public function test_admin_can_list_memos(): void
    {
        Memo::factory()->count(2)->create();

        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/gnuboard7-hello_module/admin/memos');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'data',
                    'meta' => ['current_page', 'last_page', 'per_page', 'total'],
                ],
            ]);
    }

    /**
     * 메모를 생성할 수 있는지 확인합니다.
     */
    public function test_admin_can_create_memo(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/gnuboard7-hello_module/admin/memos', [
                'title' => '새 메모',
                'content' => '본문 내용',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.title', '새 메모');

        $this->assertDatabaseHas('gnuboard7_hello_module_memos', [
            'title' => '새 메모',
        ]);
    }

    /**
     * 메모 상세를 조회할 수 있는지 확인합니다.
     */
    public function test_admin_can_show_memo(): void
    {
        $memo = Memo::factory()->create(['title' => '상세 대상']);

        $response = $this->actingAs($this->adminUser)
            ->getJson("/api/modules/gnuboard7-hello_module/admin/memos/{$memo->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $memo->id)
            ->assertJsonPath('data.title', '상세 대상');
    }

    /**
     * 메모를 수정할 수 있는지 확인합니다.
     */
    public function test_admin_can_update_memo(): void
    {
        $memo = Memo::factory()->create(['title' => '수정 전']);

        $response = $this->actingAs($this->adminUser)
            ->putJson("/api/modules/gnuboard7-hello_module/admin/memos/{$memo->id}", [
                'title' => '수정 후',
                'content' => '수정된 본문',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.title', '수정 후');
    }

    /**
     * 메모를 삭제할 수 있는지 확인합니다.
     */
    public function test_admin_can_delete_memo(): void
    {
        $memo = Memo::factory()->create();

        $response = $this->actingAs($this->adminUser)
            ->deleteJson("/api/modules/gnuboard7-hello_module/admin/memos/{$memo->id}");

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('gnuboard7_hello_module_memos', [
            'id' => $memo->id,
        ]);
    }

    /**
     * 필수 필드가 누락되면 422 를 반환하는지 확인합니다.
     */
    public function test_creating_memo_without_required_fields_returns_422(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/gnuboard7-hello_module/admin/memos', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['title', 'content']);
    }
}
