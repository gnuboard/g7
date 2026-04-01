<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Listeners;

use App\Enums\ActivityLogType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Mockery;
use Modules\Sirsoft\Ecommerce\Listeners\CategoryActivityLogListener;
use Modules\Sirsoft\Ecommerce\Models\Category;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * CategoryActivityLogListener 테스트
 *
 * 카테고리 활동 로그 리스너의 모든 훅 메서드를 검증합니다.
 * - 로그 기록 (5개): create, update, delete, toggle_status, reorder
 */
class CategoryActivityLogListenerTest extends ModuleTestCase
{
    private CategoryActivityLogListener $listener;

    private $logChannel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance('request', Request::create('/api/admin/sirsoft-ecommerce/test'));
        $this->listener = new CategoryActivityLogListener();
        $this->logChannel = Mockery::mock(\Psr\Log\LoggerInterface::class);
        Log::shouldReceive('channel')
            ->with('activity')
            ->andReturn($this->logChannel);
        Log::shouldReceive('error')->byDefault();
    }

    // ═══════════════════════════════════════════
    // getSubscribedHooks
    // ═══════════════════════════════════════════

    public function test_getSubscribedHooks_returns_all_hooks(): void
    {
        $hooks = CategoryActivityLogListener::getSubscribedHooks();

        $this->assertCount(5, $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.category.after_create', $hooks);
        $this->assertArrayNotHasKey('sirsoft-ecommerce.category.before_update', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.category.after_update', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.category.after_delete', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.category.after_toggle_status', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.category.after_reorder', $hooks);
    }

    // ═══════════════════════════════════════════
    // 이벤트 핸들러 테스트
    // ═══════════════════════════════════════════

    public function test_handleAfterCreate_logs_activity(): void
    {
        $category = $this->createCategoryMock(1, 'Electronics');

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function ($action, $context) {
                return $action === 'category.create'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'sirsoft-ecommerce::activity_log.description.category_create'
                    && $context['description_params']['category_id'] === 1
                    && isset($context['loggable'])
                    && $context['properties']['name'] === 'Electronics';
            });

        $this->listener->handleAfterCreate($category, ['name' => 'Electronics']);
    }

    public function test_handleAfterCreate_with_null_name(): void
    {
        $category = $this->createCategoryMock(2, null);

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function ($action, $context) {
                return $action === 'category.create'
                    && $context['properties']['name'] === null;
            });

        $this->listener->handleAfterCreate($category, []);
    }

    public function test_handleAfterUpdate_logs_activity_with_changes(): void
    {
        $category = $this->createCategoryMock(3, 'Updated Category');
        $snapshot = ['id' => 3, 'name' => 'Old Category'];

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function ($action, $context) {
                return $action === 'category.update'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'sirsoft-ecommerce::activity_log.description.category_update'
                    && $context['description_params']['category_id'] === 3
                    && isset($context['loggable'])
                    && array_key_exists('changes', $context);
            });

        $this->listener->handleAfterUpdate($category, ['name' => 'Updated Category'], $snapshot);
    }

    public function test_handleAfterUpdate_without_snapshot(): void
    {
        $category = $this->createCategoryMock(99, 'No Snapshot');

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function ($action, $context) {
                return $action === 'category.update'
                    && $context['changes'] === null;
            });

        $this->listener->handleAfterUpdate($category, [], null);
    }

    public function test_handleAfterDelete_logs_activity(): void
    {
        $categoryId = 5;

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function ($action, $context) use ($categoryId) {
                return $action === 'category.delete'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'sirsoft-ecommerce::activity_log.description.category_delete'
                    && $context['description_params']['category_id'] === $categoryId
                    && $context['properties']['category_id'] === $categoryId
                    && ! isset($context['loggable']);
            });

        $this->listener->handleAfterDelete($categoryId);
    }

    public function test_handleAfterToggleStatus_logs_activity(): void
    {
        $category = $this->createCategoryMock(6, 'Toggle Category');

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function ($action, $context) {
                return $action === 'category.toggle_status'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'sirsoft-ecommerce::activity_log.description.category_toggle_status'
                    && $context['description_params']['category_id'] === 6
                    && isset($context['loggable']);
            });

        $this->listener->handleAfterToggleStatus($category);
    }

    public function test_handleAfterReorder_logs_activity(): void
    {
        $orders = [
            ['id' => 1, 'sort_order' => 0],
            ['id' => 2, 'sort_order' => 1],
            ['id' => 3, 'sort_order' => 2],
        ];

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function ($action, $context) {
                return $action === 'category.reorder'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'sirsoft-ecommerce::activity_log.description.category_reorder'
                    && $context['properties']['count'] === 3
                    && ! isset($context['loggable']);
            });

        $this->listener->handleAfterReorder($orders);
    }

    public function test_handleAfterReorder_with_empty_orders(): void
    {
        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function ($action, $context) {
                return $action === 'category.reorder'
                    && $context['properties']['count'] === 0;
            });

        $this->listener->handleAfterReorder([]);
    }

    // ═══════════════════════════════════════════
    // 에러 핸들링 테스트
    // ═══════════════════════════════════════════

    public function test_logActivity_catches_exception_and_logs_error(): void
    {
        $this->logChannel->shouldReceive('info')
            ->once()
            ->andThrow(new \Exception('Log channel error'));

        Log::shouldReceive('error')
            ->once()
            ->withArgs(function ($message, $context) {
                return $message === 'Failed to record activity log'
                    && $context['action'] === 'category.delete'
                    && $context['error'] === 'Log channel error';
            });

        $this->listener->handleAfterDelete(1);
    }

    // ═══════════════════════════════════════════
    // handle 기본 핸들러 테스트
    // ═══════════════════════════════════════════

    public function test_handle_does_nothing(): void
    {
        $this->listener->handle('arg1', 'arg2');
        $this->assertTrue(true);
    }

    // ═══════════════════════════════════════════
    // 헬퍼 메서드
    // ═══════════════════════════════════════════

    private function createCategoryMock(int $id, ?string $name = null): Category
    {
        $category = Mockery::mock(Category::class)->makePartial();
        $category->forceFill(['id' => $id, 'name' => $name]);
        $category->shouldReceive('getKey')->andReturn($id);
        $category->shouldReceive('getMorphClass')->andReturn('category');

        return $category;
    }

}
