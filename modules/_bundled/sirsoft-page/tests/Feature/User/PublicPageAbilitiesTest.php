<?php

namespace Modules\Sirsoft\Page\Tests\Feature\User;

// FeatureTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../FeatureTestCase.php';

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Modules\Sirsoft\Page\Models\Page;
use Modules\Sirsoft\Page\Tests\FeatureTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 공개 페이지 응답의 관리자 링크 판정 회귀 테스트 (7.1.0 abilities 게이트 G28)
 *
 * 공개 페이지의 [편집]·버전 배지·없는 페이지의 [페이지 만들기]가 `is_admin` 만 보고 열려, 페이지 수정·등록
 * 권한이 없는 관리자도 링크를 눌러 403 화면을 만났다. 응답이 그 링크의 대상 화면과 같은 권한을 싣는다.
 * - 페이지가 있으면 data.abilities.can_update(= sirsoft-page.pages.update)
 * - 없으면(404) errors.abilities.can_create(= sirsoft-page.pages.create)
 *
 * 시나리오 축·효과는 매니페스트 tests/scenarios/public-page-abilities.yaml 참조.
 */
class PublicPageAbilitiesTest extends FeatureTestCase
{
    /**
     * 테스트가 만든 페이지를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Page::where('slug', 'like', 'g28-%')->forceDelete();
        parent::tearDown();
    }

    /**
     * 주어진 관리자 권한만 가진 사용자를 만듭니다.
     *
     * @param  array<int, string>  $identifiers  권한 식별자
     * @return User 사용자
     */
    private function adminWith(array $identifiers): User
    {
        $role = Role::create([
            'identifier' => 'g28_role_'.uniqid(),
            'name' => ['ko' => '페이지 역할', 'en' => 'Page Role'],
            'is_active' => true,
        ]);
        foreach ($identifiers as $identifier) {
            $permission = Permission::firstOrCreate(
                ['identifier' => $identifier],
                ['name' => ['ko' => $identifier, 'en' => $identifier], 'type' => 'admin']
            );
            $role->permissions()->attach($permission->id, ['scope_type' => null, 'granted_at' => now()]);
        }
        $user = User::factory()->create(['status' => 'active']);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    /**
     * 발행 페이지를 만듭니다.
     *
     * @param  string  $slug  슬러그
     * @return void 반환값 없음
     */
    private function publishedPage(string $slug): void
    {
        Page::factory()->create([
            'slug' => $slug,
            'published' => true,
            'published_at' => now(),
            'content' => ['ko' => '<p>본문</p>'],
        ]);
    }

    /**
     * 페이지 수정 권한이 없으면 can_update=false, 있으면 true.
     *
     * @scenario case=page_update_ability
     *
     * @effects public_page_reports_can_update
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function public_page_reports_can_update_by_permission(): void
    {
        $this->publishedPage('g28-edit');

        $this->actingAs($this->adminWith(['sirsoft-page.pages.read']))
            ->getJson('/api/modules/sirsoft-page/pages/g28-edit')
            ->assertOk()
            ->assertJsonPath('data.abilities.can_update', false);

        $this->actingAs($this->adminWith(['sirsoft-page.pages.read', 'sirsoft-page.pages.update']))
            ->getJson('/api/modules/sirsoft-page/pages/g28-edit')
            ->assertOk()
            ->assertJsonPath('data.abilities.can_update', true);
    }

    /**
     * 없는 페이지(404)는 등록 권한 여부를 errors.abilities.can_create 로 알린다.
     *
     * @scenario case=missing_page_create_ability
     *
     * @effects missing_page_reports_can_create
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function missing_page_reports_can_create_by_permission(): void
    {
        $this->actingAs($this->adminWith(['sirsoft-page.pages.read']))
            ->getJson('/api/modules/sirsoft-page/pages/g28-missing')
            ->assertNotFound()
            ->assertJsonPath('errors.abilities.can_create', false);

        $this->actingAs($this->adminWith(['sirsoft-page.pages.read', 'sirsoft-page.pages.create']))
            ->getJson('/api/modules/sirsoft-page/pages/g28-missing')
            ->assertNotFound()
            ->assertJsonPath('errors.abilities.can_create', true);
    }
}
