<?php

namespace Tests\Unit\Http\Requests\Module;

use App\Http\Requests\Module\IndexModuleRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * IndexModuleRequest 유닛 테스트
 *
 * with[] 파라미터 검증 규칙을 테스트합니다.
 */
class IndexModuleRequestTest extends TestCase
{
    /**
     * 유효한 with 파라미터가 통과함
     */
    public function test_valid_with_param_passes(): void
    {
        $request = new IndexModuleRequest();
        $rules = $request->rules();

        $validator = Validator::make([
            'with' => ['custom_menus'],
        ], $rules);

        $this->assertFalse($validator->fails());
    }

    /**
     * 유효하지 않은 with 파라미터가 실패함
     */
    public function test_invalid_with_param_fails(): void
    {
        $request = new IndexModuleRequest();
        $rules = $request->rules();

        $validator = Validator::make([
            'with' => ['invalid_option'],
        ], $rules);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('with.0', $validator->errors()->toArray());
    }

    /**
     * 빈 with 파라미터가 통과함
     */
    public function test_empty_with_param_passes(): void
    {
        $request = new IndexModuleRequest();
        $rules = $request->rules();

        $validator = Validator::make([
            'with' => [],
        ], $rules);

        $this->assertFalse($validator->fails());
    }

    /**
     * with 파라미터 없이 요청이 통과함
     */
    public function test_request_without_with_param_passes(): void
    {
        $request = new IndexModuleRequest();
        $rules = $request->rules();

        $validator = Validator::make([], $rules);

        $this->assertFalse($validator->fails());
    }

    /**
     * ALLOWED_WITH_OPTIONS 상수가 정의됨
     */
    public function test_allowed_with_options_constant_exists(): void
    {
        $this->assertTrue(defined(IndexModuleRequest::class.'::ALLOWED_WITH_OPTIONS'));
        $this->assertContains('custom_menus', IndexModuleRequest::ALLOWED_WITH_OPTIONS);
    }

    /**
     * hasWithOption 메서드가 존재함
     */
    public function test_has_with_option_method_exists(): void
    {
        $request = new IndexModuleRequest();
        $this->assertTrue(method_exists($request, 'hasWithOption'));
    }

    /**
     * with 배열이 최대 5개까지 허용됨
     */
    public function test_with_param_max_5_items(): void
    {
        $request = new IndexModuleRequest();
        $rules = $request->rules();

        // 6개 항목 - 실패해야 함
        $validator = Validator::make([
            'with' => ['custom_menus', 'item2', 'item3', 'item4', 'item5', 'item6'],
        ], $rules);

        $this->assertTrue($validator->fails());
    }

    /**
     * search 파라미터 검증이 유지됨
     */
    public function test_search_param_validation(): void
    {
        $request = new IndexModuleRequest();
        $rules = $request->rules();

        $validator = Validator::make([
            'search' => 'test query',
        ], $rules);

        $this->assertFalse($validator->fails());
    }

    /**
     * status 파라미터 검증이 유지됨
     */
    public function test_status_param_validation(): void
    {
        $request = new IndexModuleRequest();
        $rules = $request->rules();

        // 유효한 상태
        $validator = Validator::make([
            'status' => 'active',
        ], $rules);
        $this->assertFalse($validator->fails());

        // 유효하지 않은 상태
        $validator = Validator::make([
            'status' => 'invalid_status',
        ], $rules);
        $this->assertTrue($validator->fails());
    }
}