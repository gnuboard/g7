<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Models\ExtraFeeTemplate;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * ExtraFeeTemplateController Feature 테스트
 *
 * 추가배송비 템플릿 관리 API 엔드포인트 테스트
 * Issue #74: exists/unique 검증 규칙이 Model::class 기반으로 올바르게 동작하는지 검증
 */
class ExtraFeeTemplateControllerTest extends ModuleTestCase
{
    protected User $adminUser;

    /**
     * 테스트 환경 설정
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = $this->createAdminUser([
            'sirsoft-ecommerce.shipping-policies.read',
            'sirsoft-ecommerce.shipping-policies.create',
            'sirsoft-ecommerce.shipping-policies.update',
            'sirsoft-ecommerce.shipping-policies.delete',
        ]);
    }

    /**
     * 테스트용 추가배송비 템플릿 생성
     *
     * @param  array  $attributes  오버라이드할 속성
     */
    private function createTemplate(array $attributes = []): ExtraFeeTemplate
    {
        return ExtraFeeTemplate::create(array_merge([
            'zipcode' => '63000',
            'fee' => 3000.00,
            'region' => '제주도',
            'description' => '제주도 추가배송비',
            'is_active' => true,
            'created_by' => $this->adminUser->id,
        ], $attributes));
    }

    // ────────────────────────────────────────────────────────
    // Store (ExtraFeeTemplateStoreRequest)
    // ────────────────────────────────────────────────────────

    /**
     * 추가배송비 템플릿 생성 성공 테스트
     */
    public function test_store_creates_template_successfully(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/extra-fee-templates', [
                'zipcode' => '63100',
                'fee' => 5000,
                'region' => '제주 서귀포',
                'description' => '서귀포 추가배송비',
                'is_active' => true,
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('ecommerce_shipping_policy_extra_fee_templates', [
            'zipcode' => '63100',
            'region' => '제주 서귀포',
        ]);
    }

    /**
     * 중복 우편번호 생성 시 검증 실패 테스트
     * (Rule::unique(ExtraFeeTemplate::class, 'zipcode') 검증)
     */
    public function test_store_fails_with_duplicate_zipcode(): void
    {
        $this->createTemplate(['zipcode' => '63100']);

        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/extra-fee-templates', [
                'zipcode' => '63100',
                'fee' => 3000,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['zipcode']);
    }

    /**
     * 필수 필드 누락 시 검증 실패 테스트
     */
    public function test_store_fails_without_required_fields(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/extra-fee-templates', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['zipcode', 'fee']);
    }

    // ────────────────────────────────────────────────────────
    // Update (ExtraFeeTemplateUpdateRequest)
    // ────────────────────────────────────────────────────────

    /**
     * 추가배송비 템플릿 수정 성공 테스트
     */
    public function test_update_modifies_template_successfully(): void
    {
        $template = $this->createTemplate();

        $response = $this->actingAs($this->adminUser)
            ->putJson("/api/modules/sirsoft-ecommerce/admin/extra-fee-templates/{$template->id}", [
                'zipcode' => '63000',
                'fee' => 5000,
                'region' => '제주도 (수정)',
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('ecommerce_shipping_policy_extra_fee_templates', [
            'id' => $template->id,
            'region' => '제주도 (수정)',
        ]);
    }

    /**
     * 자기 자신의 우편번호로 수정 시 unique 제외 테스트
     * (Rule::unique(ExtraFeeTemplate::class, 'zipcode')->ignore($id) 검증)
     */
    public function test_update_allows_same_zipcode_for_same_record(): void
    {
        $template = $this->createTemplate(['zipcode' => '63000']);

        $response = $this->actingAs($this->adminUser)
            ->putJson("/api/modules/sirsoft-ecommerce/admin/extra-fee-templates/{$template->id}", [
                'zipcode' => '63000',
                'fee' => 7000,
            ]);

        $response->assertStatus(200);
    }

    /**
     * 다른 레코드의 우편번호로 수정 시 검증 실패 테스트
     */
    public function test_update_fails_with_other_records_zipcode(): void
    {
        $this->createTemplate(['zipcode' => '63000']);
        $template2 = $this->createTemplate(['zipcode' => '63100']);

        $response = $this->actingAs($this->adminUser)
            ->putJson("/api/modules/sirsoft-ecommerce/admin/extra-fee-templates/{$template2->id}", [
                'zipcode' => '63000',
                'fee' => 3000,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['zipcode']);
    }

    // ────────────────────────────────────────────────────────
    // Bulk Delete (ExtraFeeTemplateBulkDeleteRequest)
    // ────────────────────────────────────────────────────────

    /**
     * 일괄 삭제 성공 테스트
     * (Rule::exists(ExtraFeeTemplate::class, 'id') 검증 - 올바른 테이블 참조 확인)
     */
    public function test_bulk_delete_removes_templates_successfully(): void
    {
        $template1 = $this->createTemplate(['zipcode' => '63000']);
        $template2 = $this->createTemplate(['zipcode' => '63100']);

        $response = $this->actingAs($this->adminUser)
            ->deleteJson('/api/modules/sirsoft-ecommerce/admin/extra-fee-templates/bulk', [
                'ids' => [$template1->id, $template2->id],
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseMissing('ecommerce_shipping_policy_extra_fee_templates', ['id' => $template1->id]);
        $this->assertDatabaseMissing('ecommerce_shipping_policy_extra_fee_templates', ['id' => $template2->id]);
    }

    /**
     * 존재하지 않는 ID로 일괄 삭제 시 검증 실패 테스트
     * (Rule::exists(ExtraFeeTemplate::class, 'id')가 올바른 테이블을 참조하는지 검증)
     */
    public function test_bulk_delete_fails_with_nonexistent_ids(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->deleteJson('/api/modules/sirsoft-ecommerce/admin/extra-fee-templates/bulk', [
                'ids' => [99999, 99998],
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['ids.0', 'ids.1']);
    }

    /**
     * 빈 배열로 일괄 삭제 시 검증 실패 테스트
     */
    public function test_bulk_delete_fails_with_empty_ids(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->deleteJson('/api/modules/sirsoft-ecommerce/admin/extra-fee-templates/bulk', [
                'ids' => [],
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['ids']);
    }

    // ────────────────────────────────────────────────────────
    // Bulk Toggle Active (ExtraFeeTemplateBulkToggleActiveRequest)
    // ────────────────────────────────────────────────────────

    /**
     * 일괄 활성/비활성 변경 성공 테스트
     * (Rule::exists(ExtraFeeTemplate::class, 'id') 검증 - 올바른 테이블 참조 확인)
     */
    public function test_bulk_toggle_active_updates_status_successfully(): void
    {
        $template1 = $this->createTemplate(['zipcode' => '63000', 'is_active' => true]);
        $template2 = $this->createTemplate(['zipcode' => '63100', 'is_active' => true]);

        $response = $this->actingAs($this->adminUser)
            ->patchJson('/api/modules/sirsoft-ecommerce/admin/extra-fee-templates/bulk-toggle-active', [
                'ids' => [$template1->id, $template2->id],
                'is_active' => false,
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('ecommerce_shipping_policy_extra_fee_templates', [
            'id' => $template1->id,
            'is_active' => false,
        ]);
        $this->assertDatabaseHas('ecommerce_shipping_policy_extra_fee_templates', [
            'id' => $template2->id,
            'is_active' => false,
        ]);
    }

    /**
     * 존재하지 않는 ID로 일괄 활성 변경 시 검증 실패 테스트
     */
    public function test_bulk_toggle_active_fails_with_nonexistent_ids(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->patchJson('/api/modules/sirsoft-ecommerce/admin/extra-fee-templates/bulk-toggle-active', [
                'ids' => [99999],
                'is_active' => true,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['ids.0']);
    }

    /**
     * is_active 필드 누락 시 검증 실패 테스트
     */
    public function test_bulk_toggle_active_fails_without_is_active(): void
    {
        $template = $this->createTemplate();

        $response = $this->actingAs($this->adminUser)
            ->patchJson('/api/modules/sirsoft-ecommerce/admin/extra-fee-templates/bulk-toggle-active', [
                'ids' => [$template->id],
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['is_active']);
    }

    // ────────────────────────────────────────────────────────
    // 범위 형식 우편번호 테스트
    // ────────────────────────────────────────────────────────

    /**
     * 범위 형식 우편번호(11자 이상) 생성 성공 테스트
     * (zipcode 컬럼 string(20) 확장 검증)
     */
    public function test_store_creates_template_with_range_zipcode(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/extra-fee-templates', [
                'zipcode' => '23100-23116',
                'fee' => 3000,
                'region' => '인천 옹진 백령/대청/연평/북도면',
                'description' => '도서산간 지역',
                'is_active' => true,
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('ecommerce_shipping_policy_extra_fee_templates', [
            'zipcode' => '23100-23116',
            'region' => '인천 옹진 백령/대청/연평/북도면',
        ]);
    }

    /**
     * 20자 우편번호 생성 성공 테스트 (max:20 경계값)
     */
    public function test_store_creates_template_with_max_length_zipcode(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/extra-fee-templates', [
                'zipcode' => '12345678901234567890',
                'fee' => 3000,
            ]);

        $response->assertStatus(201);
    }

    /**
     * 21자 우편번호 생성 실패 테스트 (max:20 초과)
     */
    public function test_store_fails_with_zipcode_exceeding_max_length(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/extra-fee-templates', [
                'zipcode' => '123456789012345678901',
                'fee' => 3000,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['zipcode']);
    }

    // ────────────────────────────────────────────────────────
    // Zipcode 형식 검증 (regex)
    // ────────────────────────────────────────────────────────

    /**
     * 한글 우편번호 입력 시 검증 실패 테스트
     */
    public function test_store_fails_with_korean_zipcode(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/extra-fee-templates', [
                'zipcode' => 'ㅁㅁㅁ',
                'fee' => 3000,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['zipcode']);
    }

    /**
     * 특수문자 우편번호 입력 시 검증 실패 테스트
     */
    public function test_store_fails_with_special_characters_zipcode(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/extra-fee-templates', [
                'zipcode' => '123@#$',
                'fee' => 3000,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['zipcode']);
    }

    /**
     * 영문자 우편번호 입력 시 검증 실패 테스트
     */
    public function test_store_fails_with_alphabetic_zipcode(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/extra-fee-templates', [
                'zipcode' => 'abcde',
                'fee' => 3000,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['zipcode']);
    }

    /**
     * 공백 포함 우편번호 입력 시 검증 실패 테스트
     */
    public function test_store_fails_with_spaces_in_zipcode(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/extra-fee-templates', [
                'zipcode' => '123 456',
                'fee' => 3000,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['zipcode']);
    }

    /**
     * 숫자만 우편번호 생성 성공 테스트
     */
    public function test_store_succeeds_with_numeric_zipcode(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/extra-fee-templates', [
                'zipcode' => '63000',
                'fee' => 3000,
            ]);

        $response->assertStatus(201);
    }

    /**
     * 범위 형식 우편번호 생성 성공 테스트 (숫자-숫자)
     */
    public function test_store_succeeds_with_range_format_zipcode(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/extra-fee-templates', [
                'zipcode' => '63000-63100',
                'fee' => 3000,
            ]);

        $response->assertStatus(201);
    }

    /**
     * 수정 시 한글 우편번호 검증 실패 테스트
     */
    public function test_update_fails_with_korean_zipcode(): void
    {
        $template = $this->createTemplate();

        $response = $this->actingAs($this->adminUser)
            ->putJson("/api/modules/sirsoft-ecommerce/admin/extra-fee-templates/{$template->id}", [
                'zipcode' => '가나다',
                'fee' => 3000,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['zipcode']);
    }

    /**
     * 일괄 등록 시 한글 우편번호 검증 실패 테스트
     */
    public function test_bulk_create_fails_with_korean_zipcode(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/extra-fee-templates/bulk', [
                'items' => [
                    ['zipcode' => 'ㅁㅁㅁ', 'fee' => 3000],
                ],
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['items.0.zipcode']);
    }

    /**
     * 일괄 등록 시 정상 우편번호 성공 테스트
     */
    public function test_bulk_create_succeeds_with_valid_zipcodes(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/extra-fee-templates/bulk', [
                'items' => [
                    ['zipcode' => '63200', 'fee' => 3000, 'region' => '제주'],
                    ['zipcode' => '23100-23116', 'fee' => 5000, 'region' => '백령도'],
                ],
            ]);

        $response->assertStatus(201);
    }

    // ────────────────────────────────────────────────────────
    // 인증/권한 테스트
    // ────────────────────────────────────────────────────────

    /**
     * 미인증 사용자 접근 거부 테스트
     */
    public function test_unauthenticated_user_gets_401(): void
    {
        $response = $this->getJson('/api/modules/sirsoft-ecommerce/admin/extra-fee-templates');
        $response->assertStatus(401);
    }
}
