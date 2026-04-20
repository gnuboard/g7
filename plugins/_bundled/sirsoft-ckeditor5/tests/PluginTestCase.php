<?php

namespace Plugins\Sirsoft\Ckeditor5\Tests;

use App\Contracts\Extension\StorageInterface;
use App\Enums\PermissionType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Extension\Storage\PluginStorageDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Plugins\Sirsoft\Ckeditor5\Http\Controllers\ImageServeController;
use Plugins\Sirsoft\Ckeditor5\Http\Controllers\ImageUploadController;
use Plugins\Sirsoft\Ckeditor5\Repositories\Contracts\ImageUploadRepositoryInterface;
use Plugins\Sirsoft\Ckeditor5\Repositories\ImageUploadRepository;
use Plugins\Sirsoft\Ckeditor5\Services\ImageServeService;
use Plugins\Sirsoft\Ckeditor5\Services\ImageUploadService;
use Tests\TestCase;

/**
 * CKEditor5 플러그인 테스트 베이스 클래스
 *
 * 모든 CKEditor5 플러그인 테스트는 이 클래스를 상속받아야 합니다.
 * 코어 + 플러그인 마이그레이션 및 라우트를 자동으로 처리합니다.
 */
abstract class PluginTestCase extends TestCase
{
    use RefreshDatabase;

    /**
     * 테스트 환경 설정
     *
     * 플러그인 라우트와 바인딩을 테스트 환경에서 직접 등록합니다.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Repository 바인딩
        $this->app->bind(
            ImageUploadRepositoryInterface::class,
            ImageUploadRepository::class
        );

        // StorageInterface 바인딩
        $this->app->when([ImageUploadService::class, ImageServeService::class])
            ->needs(StorageInterface::class)
            ->give(fn () => new PluginStorageDriver('sirsoft-ckeditor5', 'plugins'));

        // 라우트 등록
        Route::prefix('api/plugins/sirsoft-ckeditor5')
            ->middleware('api')
            ->group(function () {
                Route::post('upload', [ImageUploadController::class, 'upload'])
                    ->name('api.sirsoft-ckeditor5.upload');

                Route::get('images/{hash}', [ImageServeController::class, 'serve'])
                    ->name('api.sirsoft-ckeditor5.images.serve');
            });
    }

    /**
     * 관리자 권한을 가진 사용자를 생성합니다.
     *
     * @return User
     */
    protected function createAdminUser(): User
    {
        $adminRole = Role::firstOrCreate(
            ['identifier' => 'admin'],
            ['name' => ['ko' => '관리자', 'en' => 'Admin'], 'description' => ['ko' => '관리자', 'en' => 'Admin']]
        );

        $permission = Permission::firstOrCreate(
            ['identifier' => 'admin.access'],
            ['name' => ['ko' => '관리자 접근', 'en' => 'Admin Access'], 'type' => PermissionType::Admin]
        );

        $adminRole->permissions()->syncWithoutDetaching([$permission->id]);

        $user = User::factory()->create();
        $user->roles()->attach($adminRole->id);

        return $user;
    }

    /**
     * 마이그레이션 경로를 반환합니다.
     *
     * @return array
     */
    protected function migrateFreshUsing(): array
    {
        return [
            '--drop-views' => $this->shouldDropViews(),
            '--drop-types' => $this->shouldDropTypes(),
            '--seed' => false,
            '--path' => [
                'database/migrations',
                'plugins/_bundled/sirsoft-ckeditor5/database/migrations',
            ],
        ];
    }
}
