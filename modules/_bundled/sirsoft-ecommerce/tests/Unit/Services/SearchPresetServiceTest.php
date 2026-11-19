<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use App\Extension\HookManager;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Mockery;
use Mockery\MockInterface;
use Modules\Sirsoft\Ecommerce\Exceptions\UnauthorizedPresetAccessException;
use Modules\Sirsoft\Ecommerce\Models\SearchPreset;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\SearchPresetRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\SearchPresetService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * SearchPresetService 단위 테스트
 */
class SearchPresetServiceTest extends ModuleTestCase
{
    private SearchPresetService $service;

    /** @var MockInterface&SearchPresetRepositoryInterface */
    private $repository;

    private User $user;

    private User $otherUser;

    /**
     * 모의 저장소로 SearchPresetService 를 만들고 본인과 다른 사용자를 준비합니다.
     *
     * @return void 반환값 없음
     */
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

    /**
     * Mockery 기대를 검증하고 닫은 뒤 부모 정리를 실행합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        try {
            Mockery::close();
        } finally {
            parent::tearDown();
        }
    }

    /**
     * 목록 조회가 로그인 사용자와 대상 화면으로 저장소를 조회해 프리셋을 돌려주는지 확인
     *
     * @scenario case=list
     *
     * @effects search_preset_list_scoped_to_user_and_screen
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_get_presets_returns_user_presets(): void
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

    /**
     * 생성이 로그인 사용자, 대상 화면, 이름, 조건을 저장소에 전달하는지 확인
     *
     * @scenario case=create
     *
     * @effects search_preset_create_stores_owner_screen_name_conditions
     *
     * @return void 반환값 없음
     */
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

    /**
     * 생성이 before_create, filter_create_data, after_create 훅을 발행하는지 확인
     *
     * @scenario case=create_hooks
     *
     * @effects search_preset_create_fires_before_filter_after_hooks
     *
     * @return void 반환값 없음
     */
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

    /**
     * 본인 프리셋 수정이 저장소 update 결과를 돌려주는지 확인
     *
     * @scenario case=update
     *
     * @effects search_preset_update_returns_updated_preset
     *
     * @return void 반환값 없음
     */
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

    /**
     * 수정이 before_update, filter_update_data, after_update 훅을 발행하는지 확인
     *
     * @scenario case=update_hooks
     *
     * @effects search_preset_update_fires_before_filter_after_hooks
     *
     * @return void 반환값 없음
     */
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

    /**
     * 다른 사용자의 프리셋을 수정하면 UnauthorizedPresetAccessException 이 발생하는지 확인
     *
     * @scenario case=update_other_user
     *
     * @effects search_preset_update_rejected_for_other_user
     *
     * @return void 반환값 없음
     */
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

    /**
     * 본인 프리셋 삭제가 저장소 delete 를 호출하고 true 를 돌려주는지 확인
     *
     * @scenario case=delete
     *
     * @effects search_preset_delete_removes_own_preset
     *
     * @return void 반환값 없음
     */
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

    /**
     * 삭제가 before_delete, after_delete 훅을 발행하는지 확인
     *
     * @scenario case=delete_hooks
     *
     * @effects search_preset_delete_fires_before_after_hooks
     *
     * @return void 반환값 없음
     */
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

    /**
     * 다른 사용자의 프리셋을 삭제하면 UnauthorizedPresetAccessException 이 발생하는지 확인
     *
     * @scenario case=delete_other_user
     *
     * @effects search_preset_delete_rejected_for_other_user
     *
     * @return void 반환값 없음
     */
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
