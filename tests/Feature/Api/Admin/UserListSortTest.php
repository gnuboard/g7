<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * 회원 목록 정렬 화이트리스트 회귀 테스트 (#705 A2)
 *
 * 목록 표는 `status`·`language`·`country` 머리글을 정렬 가능으로 그리는데 서버 규칙은 그 셋을
 * 받지 않아 머리글을 누르면 422 가 났다. 규칙과 저장소 화이트리스트를 함께 넓혔으므로 이제 실제로
 * 정렬돼야 한다.
 *
 * 트랜잭션 격리 — 기존 행과 섞이지 않게 이메일 표식 검색으로 이 테스트의 회원만 본다.
 */
#[Group('core')]
#[Group('admin')]
#[Group('user')]
class UserListSortTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private string $marker;

    /**
     * 운영자와 표식을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->marker = '@st'.Str::lower(Str::random(10)).'.test';
        $user = User::factory()->create();
        $role = Role::create(['name' => ['ko' => 'st', 'en' => 'st'], 'identifier' => 'st-'.$user->id, 'is_active' => true]);
        $permission = Permission::firstOrCreate(
            ['identifier' => 'core.users.read'],
            ['name' => ['ko' => 'core.users.read', 'en' => 'core.users.read'], 'type' => 'admin', 'order' => 1]
        );
        $role->permissions()->syncWithoutDetaching([$permission->id]);
        $user->roles()->attach($role->id);
        $this->admin = $user->fresh();
    }

    /**
     * 계정 상태 정렬이 오름·내림 모두 반영된다.
     *
     * @scenario filter_axis=sort, outcome=narrows
     *
     * @effects admin_user_list_sort_status_language_country
     *
     * @return void 반환값 없음
     */
    public function test_sort_by_status(): void
    {
        $this->seedMembers('status', ['withdrawn', 'active', 'blocked']);

        $this->assertSame(['active', 'blocked', 'withdrawn'], $this->column('status', 'asc', 'status'));
        $this->assertSame(['withdrawn', 'blocked', 'active'], $this->column('status', 'desc', 'status'));
    }

    /**
     * 언어 정렬이 반영된다.
     *
     * @scenario filter_axis=sort, outcome=narrows
     *
     * @effects admin_user_list_sort_status_language_country
     *
     * @return void 반환값 없음
     */
    public function test_sort_by_language(): void
    {
        $this->seedMembers('language', ['ko', 'en', 'ko']);

        $this->assertSame(['en', 'ko', 'ko'], $this->column('language', 'asc', 'language'));
    }

    /**
     * 국가 정렬이 반영된다.
     *
     * @scenario filter_axis=sort, outcome=narrows
     *
     * @effects admin_user_list_sort_status_language_country
     *
     * @return void 반환값 없음
     */
    public function test_sort_by_country(): void
    {
        $this->seedMembers('country', ['US', 'JP', 'KR']);

        $this->assertSame(['US', 'KR', 'JP'], $this->column('country', 'desc', 'country'));
    }

    /**
     * 화이트리스트 밖 정렬 기준은 여전히 422 로 거부한다.
     *
     * @scenario filter_axis=sort, outcome=rejected
     *
     * @effects admin_user_list_filter_invalid_values_rejected
     *
     * @return void 반환값 없음
     */
    public function test_unknown_sort_field_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->getJson('/api/admin/users?sort_by=password&sort_order=asc')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sort_by']);
    }

    /**
     * 한 컬럼에 값을 달리한 회원 여럿을 만듭니다.
     *
     * @param  string  $column  컬럼
     * @param  array<int, string>  $values  값 목록
     * @return void 반환값 없음
     */
    private function seedMembers(string $column, array $values): void
    {
        foreach ($values as $value) {
            User::factory()->create([
                'email' => Str::lower(Str::random(12)).$this->marker,
                $column => $value,
            ]);
        }
    }

    /**
     * 정렬한 목록에서 한 컬럼 값을 차례대로 뽑습니다.
     *
     * @param  string  $sortBy  정렬 기준
     * @param  string  $sortOrder  정렬 방향
     * @param  string  $field  뽑을 응답 필드
     * @return array<int, mixed> 값 목록
     */
    private function column(string $sortBy, string $sortOrder, string $field): array
    {
        $response = $this->actingAs($this->admin)->getJson('/api/admin/users?'.http_build_query([
            'filters' => [['field' => 'email', 'value' => $this->marker]],
            'sort_by' => $sortBy,
            'sort_order' => $sortOrder,
            'per_page' => 100,
        ]))->assertOk();

        return collect($response->json('data.data'))->pluck($field)->all();
    }
}
