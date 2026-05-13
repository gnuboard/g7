<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use App\Extension\HookManager;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Modules\Sirsoft\Ecommerce\Exceptions\UnauthorizedPresetAccessException;
use Modules\Sirsoft\Ecommerce\Models\SearchPreset;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\SearchPresetRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\SearchPresetService;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * SearchPresetService 단위 테스트
 */
class SearchPresetServiceTest extends TestCase
{
    use RefreshDatabase;

    private SearchPresetService $service;

    /** @var \Mockery\MockInterface&SearchPresetRepositoryInterface */
    private $repository;

    private User $user;

    private User $otherUser;

    protected function setUp(): void
    {
        parent::setUp();

        // Telescope 비활성화 (테스트 환경)
        config(['telescope.enabled' => false]);

        // Mock Repository 생성
        $this->repository = Mockery::mock(SearchPresetRepositoryInterface::class);

        // Service 생성
        $this->service = new SearchPresetService($this->repository);

        // 테스트 사용자 생성
        $this->user = User::factory()->create();
        $this->otherUser = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**     */
    #[Test]
    public function test_getPresets_returns_user_presets(): void
    {
        // Arrange
        $this->actingAs($this->user);
        $targetScreen = 'products';

        $expectedPresets = new EloquentCollection([
            new SearchPreset(['preset_name' => 'Preset 1']),
            new SearchPreset(['preset_name' => 'Preset 2']),
        ]);

        $this->repository
            ->shouldReceive('getByUserAndScreen')
            ->once()
            ->with($this->user->id, $targetScreen)
            ->andReturn($expectedPresets);

        // Act
        $result = $this->service->getPresets($targetScreen);

        // Assert
        $this->assertCount(2, $result);
    }

    /**     */
    #[Test]
    public function test_create_creates_preset_with_correct_data(): void
    {
        // Arrange
        $this->actingAs($this->user);
        $targetScreen = 'products';
        $name = '품절상품';
        $conditions = ['salesStatus' => ['sold_out']];

        $expectedPreset = new SearchPreset([
            'user_id' => $this->user->id,
            'target_screen' => $targetScreen,
            'preset_name' => $name,
            'conditions' => $conditions,
        ]);
        $expectedPreset->id = 1;

        $this->repository
            ->shouldReceive('create')
            ->once()
            ->withArgs(function ($data) use ($targetScreen, $name, $conditions) {
                return $data['user_id'] === $this->user->id
                    && $data['target_screen'] === $targetScreen
                    && $data['preset_name'] === $name
                    && $data['conditions'] === $conditions;
            })
            ->andReturn($expectedPreset);

        // Act
        $result = $this->service->create($targetScreen, $name, $conditions);

        // Assert
        $this->assertEquals($name, $result->preset_name);
    }

    /**     */
    #[Test]
    public function test_create_fires_hooks(): void
    {
        // Arrange
        $this->actingAs($this->user);
        $beforeCreateFired = false;
        $afterCreateFired = false;
        $filterApplied = false;

        HookManager::addAction('sirsoft-ecommerce.preset.before_create', function () use (&$beforeCreateFired) {
            $beforeCreateFired = true;
        });

        HookManager::addFilter('sirsoft-ecommerce.preset.filter_create_data', function ($data) use (&$filterApplied) {
            $filterApplied = true;

            return $data;
        });

        HookManager::addAction('sirsoft-ecommerce.preset.after_create', function () use (&$afterCreateFired) {
            $afterCreateFired = true;
        });

        $expectedPreset = new SearchPreset([
            'user_id' => $this->user->id,
            'target_screen' => 'products',
            'preset_name' => 'Test',
            'conditions' => [],
        ]);
        $expectedPreset->id = 1;

        $this->repository
            ->shouldReceive('create')
            ->once()
            ->andReturn($expectedPreset);

        // Act
        $this->service->create('products', 'Test', []);

        // Assert
        $this->assertTrue($beforeCreateFired, 'before_create hook should be fired');
        $this->assertTrue($filterApplied, 'filter_create_data hook should be applied');
        $this->assertTrue($afterCreateFired, 'after_create hook should be fired');

        // Cleanup hooks
        HookManager::clearAction('sirsoft-ecommerce.preset.before_create');
        HookManager::clearFilter('sirsoft-ecommerce.preset.filter_create_data');
        HookManager::clearAction('sirsoft-ecommerce.preset.after_create');
    }

    /**     */
    #[Test]
    public function test_update_updates_preset(): void
    {
        // Arrange
        $this->actingAs($this->user);

        $preset = new SearchPreset([
            'user_id' => $this->user->id,
            'target_screen' => 'products',
            'preset_name' => 'Old Name',
            'conditions' => [],
        ]);
        $preset->id = 1;

        $updatedPreset = new SearchPreset([
            'user_id' => $this->user->id,
            'target_screen' => 'products',
            'preset_name' => 'New Name',
            'conditions' => [],
        ]);
        $updatedPreset->id = 1;

        $this->repository
            ->shouldReceive('update')
            ->once()
            ->with($preset, Mockery::type('array'))
            ->andReturn($updatedPreset);

        // Act
        $result = $this->service->update($preset, ['name' => 'New Name']);

        // Assert
        $this->assertEquals('New Name', $result->preset_name);
    }

    /**     */
    #[Test]
    public function test_update_fires_hooks(): void
    {
        // Arrange
        $this->actingAs($this->user);
        $beforeUpdateFired = false;
        $afterUpdateFired = false;
        $filterApplied = false;

        HookManager::addAction('sirsoft-ecommerce.preset.before_update', function () use (&$beforeUpdateFired) {
            $beforeUpdateFired = true;
        });

        HookManager::addFilter('sirsoft-ecommerce.preset.filter_update_data', function ($data) use (&$filterApplied) {
            $filterApplied = true;

            return $data;
        });

        HookManager::addAction('sirsoft-ecommerce.preset.after_update', function () use (&$afterUpdateFired) {
            $afterUpdateFired = true;
        });

        $preset = new SearchPreset([
            'user_id' => $this->user->id,
            'target_screen' => 'products',
            'preset_name' => 'Test',
            'conditions' => [],
        ]);
        $preset->id = 1;

        $this->repository
            ->shouldReceive('update')
            ->once()
            ->andReturn($preset);

        // Act
        $this->service->update($preset, ['name' => 'Updated']);

        // Assert
        $this->assertTrue($beforeUpdateFired, 'before_update hook should be fired');
        $this->assertTrue($filterApplied, 'filter_update_data hook should be applied');
        $this->assertTrue($afterUpdateFired, 'after_update hook should be fired');

        // Cleanup hooks
        HookManager::clearAction('sirsoft-ecommerce.preset.before_update');
        HookManager::clearFilter('sirsoft-ecommerce.preset.filter_update_data');
        HookManager::clearAction('sirsoft-ecommerce.preset.after_update');
    }

    /**     */
    #[Test]
    public function test_update_throws_exception_for_other_user_preset(): void
    {
        // Arrange
        $this->actingAs($this->user);

        $preset = new SearchPreset([
            'user_id' => $this->otherUser->id,  // 다른 사용자의 프리셋
            'target_screen' => 'products',
            'preset_name' => 'Other User Preset',
            'conditions' => [],
        ]);
        // id는 fillable이 아니므로 별도 설정
        $preset->id = 1;

        // Assert
        $this->expectException(UnauthorizedPresetAccessException::class);

        // Act
        $this->service->update($preset, ['name' => 'Hacked']);
    }

    /**     */
    #[Test]
    public function test_delete_deletes_preset(): void
    {
        // Arrange
        $this->actingAs($this->user);

        $preset = new SearchPreset([
            'user_id' => $this->user->id,
            'target_screen' => 'products',
            'preset_name' => 'To Delete',
            'conditions' => [],
        ]);
        $preset->id = 1;

        $this->repository
            ->shouldReceive('delete')
            ->once()
            ->with($preset)
            ->andReturn(true);

        // Act
        $result = $this->service->delete($preset);

        // Assert
        $this->assertTrue($result);
    }

    /**     */
    #[Test]
    public function test_delete_fires_hooks(): void
    {
        // Arrange
        $this->actingAs($this->user);
        $beforeDeleteFired = false;
        $afterDeleteFired = false;

        HookManager::addAction('sirsoft-ecommerce.preset.before_delete', function () use (&$beforeDeleteFired) {
            $beforeDeleteFired = true;
        });

        HookManager::addAction('sirsoft-ecommerce.preset.after_delete', function () use (&$afterDeleteFired) {
            $afterDeleteFired = true;
        });

        $preset = new SearchPreset([
            'user_id' => $this->user->id,
            'target_screen' => 'products',
            'preset_name' => 'Test',
            'conditions' => [],
        ]);
        $preset->id = 1;

        $this->repository
            ->shouldReceive('delete')
            ->once()
            ->andReturn(true);

        // Act
        $this->service->delete($preset);

        // Assert
        $this->assertTrue($beforeDeleteFired, 'before_delete hook should be fired');
        $this->assertTrue($afterDeleteFired, 'after_delete hook should be fired');

        // Cleanup hooks
        HookManager::clearAction('sirsoft-ecommerce.preset.before_delete');
        HookManager::clearAction('sirsoft-ecommerce.preset.after_delete');
    }

    /**     */
    #[Test]
    public function test_delete_throws_exception_for_other_user_preset(): void
    {
        // Arrange
        $this->actingAs($this->user);

        $preset = new SearchPreset([
            'user_id' => $this->otherUser->id,  // 다른 사용자의 프리셋
            'target_screen' => 'products',
            'preset_name' => 'Other User Preset',
            'conditions' => [],
        ]);
        // id는 fillable이 아니므로 별도 설정
        $preset->id = 1;

        // Assert
        $this->expectException(UnauthorizedPresetAccessException::class);

        // Act
        $this->service->delete($preset);
    }
}
