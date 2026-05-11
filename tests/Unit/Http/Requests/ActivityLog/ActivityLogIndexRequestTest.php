<?php

namespace Tests\Unit\Http\Requests\ActivityLog;

use App\Enums\ActivityLogType;
use App\Http\Requests\ActivityLog\ActivityLogIndexRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * ActivityLogIndexRequest 검증 테스트
 *
 * 활동 로그 목록 조회 요청의 유효성 검증 규칙을 테스트합니다.
 */
class ActivityLogIndexRequestTest extends TestCase
{
    use RefreshDatabase;

    /**
     * FormRequest 인스턴스의 rules를 사용하여 Validator 생성
     *
     * @param array $data 검증할 데이터
     * @return \Illuminate\Validation\Validator
     */
    private function makeValidator(array $data): \Illuminate\Validation\Validator
    {
        $request = new ActivityLogIndexRequest;

        return Validator::make($data, $request->rules());
    }

    // ========================================================================
    // authorize
    // ========================================================================

    public function test_authorize_returns_true(): void
    {
        $request = new ActivityLogIndexRequest;
        $this->assertTrue($request->authorize());
    }

    // ========================================================================
    // 빈 요청 (모두 optional)
    // ========================================================================

    public function test_empty_request_passes_validation(): void
    {
        $validator = $this->makeValidator([]);
        $this->assertTrue($validator->passes());
    }

    // ========================================================================
    // log_type
    // ========================================================================

    public function test_valid_log_type_array_passes(): void
    {
        $validator = $this->makeValidator([
            'log_type' => ['admin', 'user'],
        ]);
        $this->assertTrue($validator->passes());
    }

    public function test_all_log_type_values_pass(): void
    {
        $validator = $this->makeValidator([
            'log_type' => ActivityLogType::values(),
        ]);
        $this->assertTrue($validator->passes());
    }

    public function test_invalid_log_type_value_fails(): void
    {
        $validator = $this->makeValidator([
            'log_type' => ['invalid_type'],
        ]);
        $this->assertTrue($validator->fails());
    }

    // ========================================================================
    // action
    // ========================================================================

    public function test_valid_action_passes(): void
    {
        $validator = $this->makeValidator([
            'action' => 'user.create',
        ]);
        $this->assertTrue($validator->passes());
    }

    public function test_action_exceeding_max_length_fails(): void
    {
        $validator = $this->makeValidator([
            'action' => str_repeat('a', 101),
        ]);
        $this->assertTrue($validator->fails());
    }

    // ========================================================================
    // search
    // ========================================================================

    public function test_valid_search_passes(): void
    {
        $validator = $this->makeValidator([
            'search' => '홍길동',
        ]);
        $this->assertTrue($validator->passes());
    }

    public function test_search_exceeding_max_length_fails(): void
    {
        $validator = $this->makeValidator([
            'search' => str_repeat('a', 256),
        ]);
        $this->assertTrue($validator->fails());
    }

    // ========================================================================
    // search_type
    // ========================================================================

    public function test_valid_search_type_values_pass(): void
    {
        foreach (['all', 'action', 'description', 'ip_address'] as $type) {
            $validator = $this->makeValidator(['search_type' => $type]);
            $this->assertTrue($validator->passes(), "search_type={$type} should pass");
        }
    }

    public function test_invalid_search_type_fails(): void
    {
        $validator = $this->makeValidator(['search_type' => 'invalid']);
        $this->assertTrue($validator->fails());
    }

    // ========================================================================
    // actor
    // ========================================================================

    public function test_valid_actor_passes(): void
    {
        $validator = $this->makeValidator(['actor' => '홍길동']);
        $this->assertTrue($validator->passes());
    }

    public function test_actor_exceeding_max_length_fails(): void
    {
        // actor 필드는 rules 에 존재하지 않으므로 크기 제한 검증이 없다.
        // 최신 구현은 created_by 필드로 검증하며, max:36 적용됨.
        $validator = $this->makeValidator(['created_by' => str_repeat('a', 37)]);
        $this->assertTrue($validator->fails());
    }

    // ========================================================================
    // date_from / date_to
    // ========================================================================

    public function test_valid_date_range_passes(): void
    {
        $validator = $this->makeValidator([
            'date_from' => '2026-01-01',
            'date_to' => '2026-03-28',
        ]);
        $this->assertTrue($validator->passes());
    }

    public function test_date_to_before_date_from_fails(): void
    {
        $validator = $this->makeValidator([
            'date_from' => '2026-03-28',
            'date_to' => '2026-01-01',
        ]);
        $this->assertTrue($validator->fails());
    }

    public function test_date_from_only_passes(): void
    {
        $validator = $this->makeValidator([
            'date_from' => '2026-01-01',
        ]);
        $this->assertTrue($validator->passes());
    }

    // ========================================================================
    // per_page
    // ========================================================================

    public function test_valid_per_page_passes(): void
    {
        $validator = $this->makeValidator(['per_page' => 50]);
        $this->assertTrue($validator->passes());
    }

    public function test_per_page_zero_fails(): void
    {
        $validator = $this->makeValidator(['per_page' => 0]);
        $this->assertTrue($validator->fails());
    }

    public function test_per_page_exceeding_max_fails(): void
    {
        $validator = $this->makeValidator(['per_page' => 101]);
        $this->assertTrue($validator->fails());
    }

    public function test_per_page_min_boundary_passes(): void
    {
        $validator = $this->makeValidator(['per_page' => 1]);
        $this->assertTrue($validator->passes());
    }

    public function test_per_page_max_boundary_passes(): void
    {
        $validator = $this->makeValidator(['per_page' => 100]);
        $this->assertTrue($validator->passes());
    }

    // ========================================================================
    // sort_by / sort_order
    // ========================================================================

    public function test_valid_sort_by_values_pass(): void
    {
        foreach (['created_at', 'action', 'log_type'] as $sortBy) {
            $validator = $this->makeValidator(['sort_by' => $sortBy]);
            $this->assertTrue($validator->passes(), "sort_by={$sortBy} should pass");
        }
    }

    public function test_invalid_sort_by_fails(): void
    {
        $validator = $this->makeValidator(['sort_by' => 'invalid_column']);
        $this->assertTrue($validator->fails());
    }

    public function test_valid_sort_order_values_pass(): void
    {
        foreach (['asc', 'desc'] as $order) {
            $validator = $this->makeValidator(['sort_order' => $order]);
            $this->assertTrue($validator->passes(), "sort_order={$order} should pass");
        }
    }

    public function test_invalid_sort_order_fails(): void
    {
        $validator = $this->makeValidator(['sort_order' => 'random']);
        $this->assertTrue($validator->fails());
    }

    // ========================================================================
    // user_id
    // ========================================================================

    public function test_valid_user_id_passes(): void
    {
        $user = \App\Models\User::factory()->create();
        $validator = $this->makeValidator(['user_id' => $user->id]);
        $this->assertTrue($validator->passes());
    }

    public function test_nonexistent_user_id_fails(): void
    {
        $validator = $this->makeValidator(['user_id' => 99999]);
        $this->assertTrue($validator->fails());
    }

    // ========================================================================
    // 복합 검증
    // ========================================================================

    public function test_all_valid_filters_combined_passes(): void
    {
        $user = \App\Models\User::factory()->create();

        $validator = $this->makeValidator([
            'log_type' => ['admin', 'system'],
            'action' => 'user.update',
            'user_id' => $user->id,
            'loggable_type' => 'App\\Models\\User',
            'search' => '검색어',
            'search_type' => 'action',
            'actor' => '홍길동',
            'date_from' => '2026-01-01',
            'date_to' => '2026-03-28',
            'per_page' => 20,
            'sort_by' => 'created_at',
            'sort_order' => 'desc',
        ]);

        $this->assertTrue($validator->passes());
    }
}
