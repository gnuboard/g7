<?php

namespace Tests\Feature\Api\Admin\Identity;

use App\Models\IdentityPolicy;
use App\Models\Role;
use App\Models\User;
use App\Services\IdentityPolicyService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 관리자 — IDV 정책 CRUD (Service + HTTP 통합 테스트).
 *
 * Service 비즈니스 규칙 + HTTP 라우트 권한 가드 양쪽을 검증합니다.
 */
class AdminIdentityPolicyCrudTest extends TestCase
{
    use RefreshDatabase;

    private IdentityPolicyService $service;

    private User $admin;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->app->make(IdentityPolicyService::class);

        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::factory()->create(['is_super' => true]);
        $adminRole = Role::where('identifier', 'admin')->first();
        if ($adminRole) {
            $this->admin->roles()->attach($adminRole->id, [
                'assigned_at' => now(),
                'assigned_by' => null,
            ]);
        }
        $this->admin = $this->admin->fresh();
        $this->token = $this->admin->createToken('test-token')->plainTextToken;
    }

    /**
     * 인증 헤더 헬퍼.
     */
    private function authRequest(): static
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ]);
    }

    public function test_create_admin_policy_stores_with_admin_source(): void
    {
        $policy = $this->service->createAdminPolicy([
            'key' => 'admin.new.policy',
            'scope' => 'route',
            'target' => 'api.some.route',
            'purpose' => 'sensitive_action',
            'grace_minutes' => 0,
            'enabled' => true,
            'applies_to' => 'both',
            'fail_mode' => 'block',
        ]);

        $this->assertSame('admin', $policy->source_type->value);
        $this->assertSame('admin.new.policy', $policy->key);
        $this->assertDatabaseHas('identity_policies', [
            'key' => 'admin.new.policy',
            'source_type' => 'admin',
        ]);
    }

    public function test_update_policy_appends_user_overrides_for_non_admin_source(): void
    {
        $policy = IdentityPolicy::create($this->makePolicyData([
            'key' => 'core.editable.policy',
            'source_type' => 'core',
            'source_identifier' => 'core',
            'grace_minutes' => 0,
        ]));

        // 관리자가 grace_minutes 만 수정 (제한 필드)
        $this->service->updatePolicy($policy, ['grace_minutes' => 30]);

        $policy->refresh();
        $this->assertSame(30, $policy->grace_minutes);
        $this->assertContains('grace_minutes', $policy->user_overrides ?? []);
    }

    public function test_update_admin_policy_applies_all_fields(): void
    {
        $policy = IdentityPolicy::create($this->makePolicyData([
            'key' => 'admin.edit.policy',
            'source_type' => 'admin',
            'source_identifier' => 'admin',
        ]));

        $this->service->updatePolicy($policy, ['grace_minutes' => 15, 'target' => 'api.new.target']);

        $policy->refresh();
        $this->assertSame(15, $policy->grace_minutes);
        $this->assertSame('api.new.target', $policy->target);
        // admin source 는 config 에 없으므로 user_overrides 기록이 stale cleanup 에 영향 없음 (정책상 무해)
    }

    public function test_delete_admin_policy_removes_row(): void
    {
        $policy = IdentityPolicy::create($this->makePolicyData([
            'key' => 'admin.to.delete',
            'source_type' => 'admin',
            'source_identifier' => 'admin',
        ]));

        $this->assertTrue($this->service->deleteAdminPolicy($policy));
        $this->assertDatabaseMissing('identity_policies', ['key' => 'admin.to.delete']);
    }

    public function test_delete_core_policy_returns_false(): void
    {
        $policy = IdentityPolicy::create($this->makePolicyData([
            'key' => 'core.protected.policy',
            'source_type' => 'core',
            'source_identifier' => 'core',
        ]));

        $this->assertFalse($this->service->deleteAdminPolicy($policy));
        $this->assertDatabaseHas('identity_policies', ['key' => 'core.protected.policy']);
    }

    public function test_search_paginates_policies(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            IdentityPolicy::create($this->makePolicyData(['key' => "search.item.{$i}"]));
        }

        $paginated = $this->service->search([], 10);

        $this->assertGreaterThanOrEqual(3, $paginated->total());
    }

    public function test_search_orders_policies_by_most_recent_first(): void
    {
        $first = IdentityPolicy::create($this->makePolicyData(['key' => 'recent.order.first']));
        $first->forceFill(['created_at' => now()->subMinutes(10)])->save();

        $second = IdentityPolicy::create($this->makePolicyData(['key' => 'recent.order.second']));
        $second->forceFill(['created_at' => now()->subMinutes(5)])->save();

        $third = IdentityPolicy::create($this->makePolicyData(['key' => 'recent.order.third']));
        $third->forceFill(['created_at' => now()])->save();

        $paginated = $this->service->search([], 10);
        $keys = collect($paginated->items())->pluck('key')->all();

        $idxFirst = array_search('recent.order.first', $keys, true);
        $idxSecond = array_search('recent.order.second', $keys, true);
        $idxThird = array_search('recent.order.third', $keys, true);

        $this->assertLessThan($idxSecond, $idxThird, '최근 생성된 정책이 더 앞에 위치해야 함');
        $this->assertLessThan($idxFirst, $idxSecond, '최근 생성된 정책이 더 앞에 위치해야 함');
    }

    // ====================================================================
    // HTTP E2E — 라우트 + permission 미들웨어 연결 검증
    // ====================================================================

    public function test_http_index_returns_paginated_policies(): void
    {
        IdentityPolicy::create($this->makePolicyData(['key' => 'http.idx.1']));
        IdentityPolicy::create($this->makePolicyData(['key' => 'http.idx.2']));

        $response = $this->authRequest()->getJson('/api/admin/identity/policies');

        $response->assertStatus(200)
            ->assertJsonPath('success', true);
        $this->assertGreaterThanOrEqual(2, count($response->json('data.data')));
    }

    public function test_http_store_creates_admin_policy(): void
    {
        $response = $this->authRequest()->postJson('/api/admin/identity/policies', [
            'key' => 'http.store.policy',
            'scope' => 'route',
            'target' => 'api.http.some',
            'purpose' => 'sensitive_action',
            'grace_minutes' => 0,
            'applies_to' => 'both',
            'fail_mode' => 'block',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.source_type', 'admin')
            ->assertJsonPath('data.key', 'http.store.policy');
    }

    public function test_http_store_accepts_module_raw_identifier_as_source(): void
    {
        // 모듈 환경설정 탭에서 운영자가 추가하는 정책 — source_identifier 는 raw 모듈 식별자.
        // 모듈/플러그인 sync 경로 및 목록 필터가 모두 raw identifier 컨벤션이므로 동일 형식 허용.
        $response = $this->authRequest()->postJson('/api/admin/identity/policies', [
            'key' => 'http.store.module.scoped',
            'scope' => 'route',
            'target' => 'api.shop.checkout',
            'purpose' => 'sensitive_action',
            'grace_minutes' => 0,
            'applies_to' => 'both',
            'fail_mode' => 'block',
            'source_identifier' => 'sirsoft-ecommerce',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.source_identifier', 'sirsoft-ecommerce');
    }

    public function test_http_store_rejects_invalid_source_identifier_format(): void
    {
        $response = $this->authRequest()->postJson('/api/admin/identity/policies', [
            'key' => 'http.store.invalid.source',
            'scope' => 'route',
            'target' => 'api.some.route',
            'purpose' => 'sensitive_action',
            'grace_minutes' => 0,
            'applies_to' => 'both',
            'fail_mode' => 'block',
            'source_identifier' => 'Invalid Source!',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['source_identifier']);
    }

    public function test_http_update_policy(): void
    {
        $policy = IdentityPolicy::create($this->makePolicyData([
            'key' => 'http.update.policy',
            'source_type' => 'admin',
            'source_identifier' => 'admin',
        ]));

        $response = $this->authRequest()->putJson("/api/admin/identity/policies/{$policy->id}", [
            'grace_minutes' => 42,
        ]);

        $response->assertStatus(200);
        $this->assertSame(42, $policy->fresh()->grace_minutes);
    }

    /**
     * 모듈 선언 정책의 conditions 운영자 편집 — 297-groovy-pixel B안 리팩토링.
     *
     * 회원가입 단계(signup_stage) 등 정책 조건은 운영자가 화면에서 직접 변경할 수 있어야 하며,
     * 변경 시 user_overrides 에 'conditions' 가 추가되어 모듈 재sync 시 운영자 값이 보존된다.
     */
    public function test_http_update_module_policy_persists_conditions_change(): void
    {
        $policy = IdentityPolicy::create($this->makePolicyData([
            'key' => 'http.module.signup_before_submit',
            'source_type' => 'module',
            'source_identifier' => 'sirsoft-board',
            'purpose' => 'signup',
            'scope' => 'route',
            'target' => 'api.auth.register',
            'conditions' => ['signup_stage' => 'before_submit'],
        ]));

        $response = $this->authRequest()->putJson("/api/admin/identity/policies/{$policy->id}", [
            'conditions' => ['signup_stage' => 'after_create'],
        ]);

        $response->assertStatus(200);

        $fresh = $policy->fresh();
        $this->assertSame(['signup_stage' => 'after_create'], $fresh->conditions);
        $this->assertContains('conditions', $fresh->user_overrides ?? []);
    }

    public function test_http_update_admin_policy_persists_conditions_change(): void
    {
        $policy = IdentityPolicy::create($this->makePolicyData([
            'key' => 'http.admin.signup',
            'source_type' => 'admin',
            'source_identifier' => 'admin',
            'purpose' => 'signup',
            'conditions' => null,
        ]));

        $response = $this->authRequest()->putJson("/api/admin/identity/policies/{$policy->id}", [
            'conditions' => ['signup_stage' => 'after_create'],
        ]);

        $response->assertStatus(200);
        $this->assertSame(['signup_stage' => 'after_create'], $policy->fresh()->conditions);
    }

    public function test_http_destroy_rejects_core_policy(): void
    {
        $policy = IdentityPolicy::create($this->makePolicyData([
            'key' => 'http.core.protected',
            'source_type' => 'core',
            'source_identifier' => 'core',
        ]));

        $response = $this->authRequest()->deleteJson("/api/admin/identity/policies/{$policy->id}");

        $response->assertStatus(403);
    }

    public function test_http_destroy_deletes_admin_policy(): void
    {
        $policy = IdentityPolicy::create($this->makePolicyData([
            'key' => 'http.admin.delete',
            'source_type' => 'admin',
            'source_identifier' => 'admin',
        ]));

        $response = $this->authRequest()->deleteJson("/api/admin/identity/policies/{$policy->id}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('identity_policies', ['key' => 'http.admin.delete']);
    }

    /**
     * 정책 저장 — 필수 필드 누락 시 422 응답 + 필드별 errors 배열 + 한국어 attribute 메시지.
     *
     * 회귀 방지: 모달 저장 시 errors 객체가 비어 있거나 영어 메시지만 떨어지면
     * 프런트의 "상단 에러 나열 / 필드별 테두리/문구" UI 가 동작하지 않는다.
     */
    public function test_http_store_returns_422_with_field_errors_and_localized_messages(): void
    {
        app()->setLocale('ko');

        $response = $this->authRequest()->postJson('/api/admin/identity/policies', [
            // key/scope/target/purpose/grace_minutes/applies_to/fail_mode 모두 누락
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors([
                'key',
                'scope',
                'target',
                'purpose',
                'grace_minutes',
                'applies_to',
                'fail_mode',
            ]);

        // 한국어 attribute 메시지 노출 확인 (예: "정책 키")
        $errors = $response->json('errors');
        $this->assertIsArray($errors);
        $this->assertNotEmpty($errors['key'] ?? null);
        $keyMessage = $errors['key'][0] ?? '';
        $this->assertSame('정책 키를 입력해주세요.', $keyMessage);
    }

    /**
     * 정책 저장 — 중복 key 는 422.
     */
    public function test_http_store_returns_422_for_duplicate_key(): void
    {
        IdentityPolicy::create($this->makePolicyData([
            'key' => 'http.dup.policy',
            'source_type' => 'admin',
        ]));

        $response = $this->authRequest()->postJson('/api/admin/identity/policies', [
            'key' => 'http.dup.policy',
            'scope' => 'route',
            'target' => 'api.dup',
            'purpose' => 'sensitive_action',
            'grace_minutes' => 0,
            'applies_to' => 'both',
            'fail_mode' => 'block',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['key']);
    }

    public function test_http_unauthenticated_returns_401(): void
    {
        $response = $this->withHeaders(['Accept' => 'application/json'])
            ->getJson('/api/admin/identity/policies');

        $response->assertStatus(401);
    }

    /**
     * @return array<string, mixed>
     */
    private function makePolicyData(array $overrides = []): array
    {
        return array_merge([
            'key' => 'test.default.key',
            'scope' => 'route',
            'target' => 'api.test.default',
            'purpose' => 'sensitive_action',
            'grace_minutes' => 0,
            'enabled' => true,
            'priority' => 100,
            'source_type' => 'admin',
            'source_identifier' => 'admin',
            'applies_to' => 'both',
            'fail_mode' => 'block',
        ], $overrides);
    }
}
