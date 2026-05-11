<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\ExtensionOwnerType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 사용자 컨트롤러 다중 검색 조건 테스트
 */
class UserControllerMultipleSearchTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdminUser();
        $this->token = $this->admin->createToken('test-token')->plainTextToken;
    }

    /**
     * 관리자 역할 생성 및 할당
     */
    private function createAdminUser(): User
    {
        $user = User::factory()->create();

        // 관리자 권한 생성
        $permission = Permission::firstOrCreate(
            ['identifier' => 'core.users.read'],
            [
                'name' => json_encode(['ko' => '사용자 조회', 'en' => 'Read Users']),
                'description' => json_encode(['ko' => '사용자 목록 조회 권한', 'en' => 'Permission to read users']),
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                    'type' => 'admin',
            ]
        );

        // 관리자 역할 생성
        $adminRole = Role::firstOrCreate(
            ['identifier' => 'admin'],
            [
                'name' => json_encode(['ko' => '관리자', 'en' => 'Administrator']),
                'description' => json_encode(['ko' => '시스템 관리자', 'en' => 'System Administrator']),
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                    'type' => 'admin',
                'is_active' => true,
            ]
        );

        // 역할에 권한 할당
        if (! $adminRole->permissions()->where('permissions.id', $permission->id)->exists()) {
            $adminRole->permissions()->attach($permission->id);
        }

        // 사용자에게 역할 할당
        $user->roles()->attach($adminRole->id, [
            'assigned_at' => now(),
            'assigned_by' => null,
        ]);

        return $user->fresh();
    }

    /**
     * 인증된 요청 헬퍼 메서드
     */
    private function authRequest(): static
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ]);
    }

    /**
     * 테스트용 사용자 데이터 생성
     */
    private function createTestUsers(): void
    {
        // 기본 사용자 추가 (admin은 이미 setUp에서 생성됨)
        User::factory()->create([
            'name' => '홍길동',
            'email' => 'hong@example.com',
        ]);

        User::factory()->create([
            'name' => '홍길순',
            'email' => 'hongsister@example.com',
        ]);

        User::factory()->create([
            'name' => '김철수',
            'email' => 'kim@test.com',
        ]);

        User::factory()->create([
            'name' => '이영희',
            'email' => 'lee@example.com',
        ]);
    }

    /**
     * 단일 필터 조건으로 사용자 목록 조회
     */
    #[Test]
    public function test_can_search_users_with_single_filter(): void
    {
        $this->createTestUsers();

        // 이름으로 검색 (filters 형식)
        $response = $this->authRequest()->getJson('/api/admin/users?'.http_build_query([
            'filters' => [
                ['field' => 'name', 'value' => '홍'],
            ],
            'date_filter' => 'all',
        ]));

        $response->assertOk();
        $response->assertJsonStructure([
            'success',
            'data' => [
                'data' => [
                    // UserResource 는 공개 식별자로 uuid 만 노출 (id 제외)
                    '*' => ['uuid', 'name', 'email'],
                ],
            ],
        ]);

        // '홍'으로 시작하는 이름을 가진 사용자가 2명 있어야 함
        $users = collect($response->json('data.data'));
        $hongUsers = $users->filter(fn ($user) => str_contains($user['name'], '홍'));
        $this->assertGreaterThanOrEqual(2, $hongUsers->count());
    }

    /**
     * 이메일 필드로 단일 필터 검색
     */
    public function test_can_search_users_with_email_filter(): void
    {
        $this->createTestUsers();

        // 이메일로 검색 (filters 형식)
        $response = $this->authRequest()->getJson('/api/admin/users?'.http_build_query([
            'filters' => [
                ['field' => 'email', 'value' => 'example'],
            ],
            'date_filter' => 'all',
        ]));

        $response->assertOk();

        // 'example.com' 이메일을 가진 사용자들이 검색되어야 함
        $users = collect($response->json('data.data'));
        $exampleUsers = $users->filter(fn ($user) => str_contains($user['email'], 'example'));
        $this->assertGreaterThanOrEqual(3, $exampleUsers->count());
    }

    /**
     * 다중 검색 조건으로 사용자 목록 조회 (AND 조건)
     */
    public function test_can_search_users_with_multiple_filters(): void
    {
        $this->createTestUsers();

        // 이름에 '홍' 포함 AND 이메일에 'example' 포함
        $response = $this->authRequest()->getJson('/api/admin/users?'.http_build_query([
            'filters' => [
                ['field' => 'name', 'value' => '홍'],
                ['field' => 'email', 'value' => 'example'],
            ],
            'date_filter' => 'all',
        ]));

        $response->assertOk();

        // 이름에 '홍'이 포함되고 이메일에 'example'이 포함된 사용자만 검색되어야 함
        $users = collect($response->json('data.data'));
        $this->assertGreaterThanOrEqual(1, $users->count());

        foreach ($users as $user) {
            $this->assertStringContainsString('홍', $user['name']);
            $this->assertStringContainsString('example', $user['email']);
        }
    }

    /**
     * 정확한 일치(eq) 연산자로 검색
     */
    public function test_can_search_users_with_exact_match_operator(): void
    {
        $this->createTestUsers();

        // 이름이 정확히 '홍길동'인 사용자 검색
        $response = $this->authRequest()->getJson('/api/admin/users?'.http_build_query([
            'filters' => [
                ['field' => 'name', 'value' => '홍길동', 'operator' => 'eq'],
            ],
            'date_filter' => 'all',
        ]));

        $response->assertOk();

        $users = collect($response->json('data.data'));
        $this->assertGreaterThanOrEqual(1, $users->count());

        foreach ($users as $user) {
            $this->assertEquals('홍길동', $user['name']);
        }
    }

    /**
     * 시작 문자열 일치(starts_with) 연산자로 검색
     */
    public function test_can_search_users_with_starts_with_operator(): void
    {
        $this->createTestUsers();

        // 이메일이 'hong'으로 시작하는 사용자 검색
        $response = $this->authRequest()->getJson('/api/admin/users?'.http_build_query([
            'filters' => [
                ['field' => 'email', 'value' => 'hong', 'operator' => 'starts_with'],
            ],
            'date_filter' => 'all',
        ]));

        $response->assertOk();

        $users = collect($response->json('data.data'));
        $this->assertGreaterThanOrEqual(2, $users->count());

        foreach ($users as $user) {
            $this->assertStringStartsWith('hong', $user['email']);
        }
    }

    /**
     * 끝 문자열 일치(ends_with) 연산자로 검색
     */
    public function test_can_search_users_with_ends_with_operator(): void
    {
        $this->createTestUsers();

        // 이메일이 '@test.com'으로 끝나는 사용자 검색
        $response = $this->authRequest()->getJson('/api/admin/users?'.http_build_query([
            'filters' => [
                ['field' => 'email', 'value' => '@test.com', 'operator' => 'ends_with'],
            ],
            'date_filter' => 'all',
        ]));

        $response->assertOk();

        $users = collect($response->json('data.data'));
        $this->assertGreaterThanOrEqual(1, $users->count());

        foreach ($users as $user) {
            $this->assertStringEndsWith('@test.com', $user['email']);
        }
    }

    /**
     * 부분 일치(like) 연산자로 검색 (기본값)
     */
    #[Test]
    public function test_can_search_users_with_like_operator(): void
    {
        $this->createTestUsers();

        // 이름에 '길'이 포함된 사용자 검색 (기본 연산자)
        $response = $this->authRequest()->getJson('/api/admin/users?'.http_build_query([
            'filters' => [
                ['field' => 'name', 'value' => '길'],
            ],
            'date_filter' => 'all',
        ]));

        $response->assertOk();

        $users = collect($response->json('data.data'));
        $this->assertGreaterThanOrEqual(2, $users->count());

        foreach ($users as $user) {
            $this->assertStringContainsString('길', $user['name']);
        }
    }

    /**
     * 복합 다중 조건 검색 (여러 필드, 여러 연산자)
     */
    public function test_can_search_users_with_complex_multiple_filters(): void
    {
        $this->createTestUsers();

        // 이름에 '홍' 포함 AND 이메일이 'example.com'으로 끝남
        $response = $this->authRequest()->getJson('/api/admin/users?'.http_build_query([
            'filters' => [
                ['field' => 'name', 'value' => '홍', 'operator' => 'like'],
                ['field' => 'email', 'value' => '@example.com', 'operator' => 'ends_with'],
            ],
            'date_filter' => 'all',
        ]));

        $response->assertOk();

        $users = collect($response->json('data.data'));
        $this->assertGreaterThanOrEqual(1, $users->count());

        foreach ($users as $user) {
            $this->assertStringContainsString('홍', $user['name']);
            $this->assertStringEndsWith('@example.com', $user['email']);
        }
    }

    /**
     * 다중 검색 조건 유효성 검증 - 유효하지 않은 필드
     */
    public function test_validation_fails_for_invalid_filter_field(): void
    {
        $response = $this->authRequest()->getJson('/api/admin/users?'.http_build_query([
            'filters' => [
                ['field' => 'invalid_field', 'value' => 'test'],
            ],
            'date_filter' => 'all',
        ]));

        $response->assertStatus(422);
    }

    /**
     * 다중 검색 조건 유효성 검증 - 유효하지 않은 연산자
     */
    public function test_validation_fails_for_invalid_filter_operator(): void
    {
        $response = $this->authRequest()->getJson('/api/admin/users?'.http_build_query([
            'filters' => [
                ['field' => 'name', 'value' => 'test', 'operator' => 'invalid_operator'],
            ],
            'date_filter' => 'all',
        ]));

        $response->assertStatus(422);
    }

    /**
     * 다중 검색 조건 유효성 검증 - 필수 필드 누락
     */
    public function test_validation_fails_for_missing_filter_field(): void
    {
        $response = $this->authRequest()->getJson('/api/admin/users?'.http_build_query([
            'filters' => [
                ['value' => 'test'],
            ],
            'date_filter' => 'all',
        ]));

        $response->assertStatus(422);
    }

    /**
     * 다중 검색 조건 - value가 비어있는 필터는 무시됨
     *
     * prepareForValidation에서 value가 비어있는 filters를 자동으로 제거하므로
     * 422 오류가 아닌 정상 응답이 반환되어야 함
     */
    public function test_empty_filter_value_is_ignored(): void
    {
        $this->createTestUsers();

        $response = $this->authRequest()->getJson('/api/admin/users?'.http_build_query([
            'filters' => [
                ['field' => 'name'],  // value가 없음
            ],
            'date_filter' => 'all',
        ]));

        // value가 비어있는 filter는 제거되어 정상 응답 반환
        $response->assertOk();
    }

    /**
     * 다중 검색 조건 유효성 검증 - 최대 개수 초과
     */
    public function test_validation_fails_for_too_many_filters(): void
    {
        // 11개의 필터 생성 (최대 10개 허용)
        $filters = [];
        for ($i = 0; $i < 11; $i++) {
            $filters[] = ['field' => 'name', 'value' => 'test'.$i];
        }

        $response = $this->authRequest()->getJson('/api/admin/users?'.http_build_query([
            'filters' => $filters,
            'date_filter' => 'all',
        ]));

        $response->assertStatus(422);
    }

    /**
     * 다중 검색 조건과 정렬 함께 사용
     */
    public function test_can_search_users_with_filters_and_sorting(): void
    {
        $this->createTestUsers();

        $response = $this->authRequest()->getJson('/api/admin/users?'.http_build_query([
            'filters' => [
                ['field' => 'email', 'value' => 'example'],
            ],
            'sort_by' => 'name',
            'sort_order' => 'asc',
            'date_filter' => 'all',
        ]));

        $response->assertOk();

        $users = collect($response->json('data.data'));
        $this->assertGreaterThanOrEqual(1, $users->count());

        // 정렬 순서 확인
        $names = $users->pluck('name')->toArray();
        $sortedNames = $names;
        sort($sortedNames);
        $this->assertEquals($sortedNames, $names);
    }

    /**
     * 다중 검색 조건과 페이지네이션 함께 사용
     */
    public function test_can_search_users_with_filters_and_pagination(): void
    {
        // 충분한 테스트 데이터 생성
        User::factory(20)->create([
            'email' => fn () => 'user'.uniqid().'@example.com',
        ]);

        $response = $this->authRequest()->getJson('/api/admin/users?'.http_build_query([
            'filters' => [
                ['field' => 'email', 'value' => 'example'],
            ],
            'per_page' => 5,
            'page' => 1,
            'date_filter' => 'all',
        ]));

        $response->assertOk();

        // 페이지네이션 구조 확인
        $response->assertJsonStructure([
            'data' => [
                'data',
                'pagination' => [
                    'total',
                    'per_page',
                    'current_page',
                ],
            ],
        ]);

        $pagination = $response->json('data.pagination');
        $this->assertEquals(5, $pagination['per_page']);
        $this->assertEquals(1, $pagination['current_page']);
    }

    /**
     * 빈 filters 배열로 요청 시 모든 사용자 반환
     */
    public function test_empty_filters_returns_all_users(): void
    {
        $this->createTestUsers();

        $response = $this->authRequest()->getJson('/api/admin/users?'.http_build_query([
            'filters' => [],
            'date_filter' => 'all',
        ]));

        $response->assertOk();

        // 5명의 사용자가 있어야 함 (admin 1명 + 테스트 사용자 4명)
        $users = collect($response->json('data.data'));
        $this->assertGreaterThanOrEqual(5, $users->count());
    }

    /**
     * 'all' 필드로 전체 검색 가능한 필드를 OR 조건으로 검색
     */
    public function test_can_search_users_with_all_field(): void
    {
        $this->createTestUsers();

        // 'all' 필드로 검색하면 name 또는 email 중 하나라도 일치하면 반환
        $response = $this->authRequest()->getJson('/api/admin/users?'.http_build_query([
            'filters' => [
                ['field' => 'all', 'value' => '홍'],
            ],
            'date_filter' => 'all',
        ]));

        $response->assertOk();

        // '홍'이 name 또는 email 중 하나에 포함된 사용자가 검색되어야 함
        $users = collect($response->json('data.data'));
        $this->assertGreaterThanOrEqual(2, $users->count());

        foreach ($users as $user) {
            $matchesAny = str_contains($user['name'] ?? '', '홍')
                || str_contains($user['email'] ?? '', '홍');
            $this->assertTrue($matchesAny, '검색 결과가 OR 조건으로 필터링되어야 합니다.');
        }
    }

    /**
     * 'all' 필드로 이메일 도메인 검색
     */
    public function test_can_search_users_with_all_field_by_email_domain(): void
    {
        $this->createTestUsers();

        // 'example'로 검색하면 이메일에 'example'이 포함된 사용자가 검색되어야 함
        $response = $this->authRequest()->getJson('/api/admin/users?'.http_build_query([
            'filters' => [
                ['field' => 'all', 'value' => 'example'],
            ],
            'date_filter' => 'all',
        ]));

        $response->assertOk();

        $users = collect($response->json('data.data'));
        $this->assertGreaterThanOrEqual(3, $users->count());
    }

    /**
     * 'all' 필드와 다른 조건 함께 사용
     */
    public function test_can_search_users_with_all_field_and_other_filters(): void
    {
        $this->createTestUsers();

        // 'all' 필드로 '홍' 검색 AND 이메일이 'example'로 시작
        $response = $this->authRequest()->getJson('/api/admin/users?'.http_build_query([
            'filters' => [
                ['field' => 'all', 'value' => '홍'],
                ['field' => 'email', 'value' => 'hong', 'operator' => 'starts_with'],
            ],
            'date_filter' => 'all',
        ]));

        $response->assertOk();

        $users = collect($response->json('data.data'));
        // '홍'이 이름/이메일에 포함되고 이메일이 'hong'으로 시작하는 사용자
        foreach ($users as $user) {
            $this->assertStringStartsWith('hong', $user['email']);
        }
    }
}
