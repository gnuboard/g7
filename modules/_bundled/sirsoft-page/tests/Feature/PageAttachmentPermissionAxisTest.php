<?php

namespace Modules\Sirsoft\Page\Tests\Feature;

// FeatureTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../FeatureTestCase.php';

use App\Contracts\Extension\StorageInterface;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Mockery;
use Modules\Sirsoft\Page\Models\Page;
use Modules\Sirsoft\Page\Models\PageAttachment;
use Modules\Sirsoft\Page\Tests\FeatureTestCase;

/**
 * 페이지 슬러그 확인·첨부 업로드·임시 첨부 삭제의 권한 축 회귀 테스트 (7.1.0 abilities 게이트 page-hello #2·#3·#4·N1)
 *
 * - #2 슬러그 확인·#3 첨부 업로드: 등록 권한만 요구해 수정 권한만 가진 관리자가 수정 화면에서 403 을 받았다 → 등록·수정 어느 쪽으로도
 * - #4 임시 첨부 삭제: 수정 권한을 요구해 등록 권한만 가진 관리자가 방금 올린 임시 첨부를 지우지 못했다 → 등록·수정 어느 쪽으로도
 *   (연결된 첨부는 여전히 수정 권한 + 부모 페이지 스코프, 임시 첨부는 올린 사람만)
 * - N1 업로드에 page_id 를 붙이면 그 페이지의 수정 권한·스코프를 본다(등록 권한만으로, 또는 담당 밖 페이지에 첨부를 더할 수 없다)
 *
 * 시나리오 축·효과는 매니페스트 tests/scenarios/page-attachment-permission-axis.yaml 참조.
 */
class PageAttachmentPermissionAxisTest extends FeatureTestCase
{
    private const ENDPOINT = '/api/modules/sirsoft-page/admin/attachments';

    /**
     * 저장소를 모킹합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $storageMock = Mockery::mock(StorageInterface::class);
        $storageMock->shouldReceive('put')->andReturn(true);
        $storageMock->shouldReceive('get')->andReturn('file content');
        $storageMock->shouldReceive('exists')->andReturn(true);
        $storageMock->shouldReceive('delete')->andReturn(true);
        $storageMock->shouldReceive('deleteDirectory')->andReturn(true);
        $storageMock->shouldReceive('getDisk')->andReturn('local');
        $storageMock->shouldReceive('url')->andReturn('/storage/test.pdf');
        $storageMock->shouldReceive('response')->andReturn(null);
        $this->app->instance(StorageInterface::class, $storageMock);

        config(['g7_settings.modules.sirsoft-page.attachment' => [
            'max_count' => 5,
            'max_size_mb' => 10,
            'allowed_types' => ['image/png', 'application/pdf'],
        ]]);
    }

    /**
     * 테스트가 만든 페이지를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Page::where('slug', 'like', 'axis-%')->forceDelete();
        Mockery::close();
        parent::tearDown();
    }

    /**
     * 주어진 권한만 가진 관리자를 만듭니다.
     *
     * @param  array<int, string>  $identifiers  권한 식별자
     * @param  string|null  $scope  스코프 종류(null=전체, self=본인)
     * @return User 관리자
     */
    private function adminWith(array $identifiers, ?string $scope = null): User
    {
        $role = Role::create([
            'identifier' => 'page_axis_'.uniqid(),
            'name' => ['ko' => '페이지 역할', 'en' => 'Page Role'],
            'is_active' => true,
        ]);
        foreach ($identifiers as $identifier) {
            $permission = Permission::where('identifier', $identifier)->first()
                ?? Permission::create(['identifier' => $identifier, 'name' => ['ko' => $identifier, 'en' => $identifier], 'type' => 'admin']);
            // 본인 스코프가 동작하려면 페이지 권한에 소유자 키가 있어야 한다(모듈 권한 정의와 같은 값)
            if ($scope !== null) {
                $permission->update(['resource_route_key' => 'page', 'owner_key' => 'created_by']);
            }
            $role->permissions()->attach($permission->id, ['scope_type' => $scope, 'granted_at' => now()]);
        }
        $user = User::factory()->create(['status' => 'active']);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    /**
     * 수정 권한만 있어도 슬러그 확인이 된다.
     *
     * @scenario case=check_slug_update_only
     *
     * @effects check_slug_allowed_by_create_or_update
     *
     * @return void 반환값 없음
     */
    public function test_check_slug_is_allowed_with_update_only(): void
    {
        $editor = $this->adminWith(['sirsoft-page.pages.read', 'sirsoft-page.pages.update']);

        $this->actingAs($editor)
            ->postJson('/api/modules/sirsoft-page/admin/pages/check-slug', ['slug' => 'axis-free-slug'])
            ->assertOk();
    }

    /**
     * 수정 권한만 있어도 담당 페이지에 첨부를 올릴 수 있다.
     *
     * @scenario case=upload_update_only
     *
     * @effects upload_allowed_by_create_or_update
     *
     * @return void 반환값 없음
     */
    public function test_upload_to_page_is_allowed_with_update_only(): void
    {
        $editor = $this->adminWith(['sirsoft-page.pages.read', 'sirsoft-page.pages.update']);
        $page = Page::factory()->create(['slug' => 'axis-upload', 'created_by' => $editor->id, 'updated_by' => $editor->id]);

        $this->actingAs($editor)
            ->postJson(self::ENDPOINT, ['file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'), 'page_id' => $page->id])
            ->assertCreated();
    }

    /**
     * 등록 권한만 가진 관리자가 올린 임시 첨부를 지울 수 있다(남의 임시 첨부는 403).
     *
     * @scenario case=temp_delete_create_only
     *
     * @effects temp_delete_allowed_for_uploader
     *
     * @return void 반환값 없음
     */
    public function test_temp_attachment_delete_is_allowed_for_uploader_with_create_only(): void
    {
        $creator = $this->adminWith(['sirsoft-page.pages.read', 'sirsoft-page.pages.create']);
        $other = $this->adminWith(['sirsoft-page.pages.read', 'sirsoft-page.pages.create']);

        $tempKey = (string) Str::uuid();
        $upload = $this->actingAs($creator)
            ->postJson(self::ENDPOINT, ['file' => UploadedFile::fake()->create('t.pdf', 10, 'application/pdf'), 'temp_key' => $tempKey])
            ->assertCreated();
        $id = (int) PageAttachment::where('temp_key', $tempKey)->value('id');
        $this->assertGreaterThan(0, $id, (string) $upload->getContent());

        $this->actingAs($other)->deleteJson(self::ENDPOINT.'/'.$id)->assertForbidden();
        $this->actingAs($creator)->deleteJson(self::ENDPOINT.'/'.$id)->assertOk();
    }

    /**
     * 등록 권한만으로는 기존 페이지(page_id)에 첨부를 더할 수 없다(N1).
     *
     * @scenario case=upload_page_id_create_only
     *
     * @effects upload_page_id_requires_update_scope
     *
     * @return void 반환값 없음
     */
    public function test_upload_with_page_id_is_forbidden_with_create_only(): void
    {
        $creator = $this->adminWith(['sirsoft-page.pages.read', 'sirsoft-page.pages.create']);
        $page = Page::factory()->create(['slug' => 'axis-create-only', 'created_by' => $creator->id, 'updated_by' => $creator->id]);

        $this->actingAs($creator)
            ->postJson(self::ENDPOINT, ['file' => UploadedFile::fake()->create('b.pdf', 10, 'application/pdf'), 'page_id' => $page->id])
            ->assertForbidden();
        $this->assertSame(0, PageAttachment::where('page_id', $page->id)->count());
    }

    /**
     * 본인 스코프 관리자는 담당 밖(남이 만든) 페이지에 첨부를 더할 수 없다(N1).
     *
     * @scenario case=upload_page_id_out_of_scope
     *
     * @effects upload_page_id_requires_update_scope
     *
     * @return void 반환값 없음
     */
    public function test_upload_with_page_id_out_of_scope_is_forbidden(): void
    {
        $selfScoped = $this->adminWith(['sirsoft-page.pages.read', 'sirsoft-page.pages.create', 'sirsoft-page.pages.update'], 'self');
        $owner = User::factory()->create();
        $page = Page::factory()->create(['slug' => 'axis-out-of-scope', 'created_by' => $owner->id, 'updated_by' => $owner->id]);

        $this->actingAs($selfScoped)
            ->postJson(self::ENDPOINT, ['file' => UploadedFile::fake()->create('c.pdf', 10, 'application/pdf'), 'page_id' => $page->id])
            ->assertForbidden();
        $this->assertSame(0, PageAttachment::where('page_id', $page->id)->count());
    }
}
