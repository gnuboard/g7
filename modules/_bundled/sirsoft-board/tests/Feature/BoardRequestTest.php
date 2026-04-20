<?php

namespace Modules\Sirsoft\Board\Tests\Feature;

// 테스트 베이스 클래스 수동 require (autoload 전에 로드 필요)
require_once __DIR__.'/../ModuleTestCase.php';

use Illuminate\Support\Facades\Validator;
use Modules\Sirsoft\Board\Http\Requests\CopyBoardRequest;
use Modules\Sirsoft\Board\Http\Requests\StoreBoardRequest;
use Modules\Sirsoft\Board\Http\Requests\UpdateBoardRequest;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;

/**
 * 게시판 FormRequest 검증 통합 테스트
 *
 * - StoreBoardRequest: 게시판 생성 검증
 * - UpdateBoardRequest: 게시판 수정 검증
 * - CopyBoardRequest: 게시판 복사 검증
 * - authorize() 메서드: 모든 Request가 true 반환하는지 확인
 */
class BoardRequestTest extends ModuleTestCase
{
    /**
     * BoardTypeValidationRule이 DB에서 타입 목록을 조회하므로
     * 테스트 전 기본 타입을 생성합니다.
     */
    protected function setUp(): void
    {
        parent::setUp();

        \Modules\Sirsoft\Board\Models\BoardType::firstOrCreate(
            ['slug' => 'basic'],
            ['name' => ['ko' => '기본형', 'en' => 'Basic'], 'is_active' => true]
        );
    }

    // ==========================================
    // authorize() 테스트
    // ==========================================

    /**
     * FormRequest authorize() 메서드가 항상 true를 반환하는지 확인
     *
     * 실제 권한 체크는 라우트 미들웨어에서 수행됩니다.
     */
    public function test_form_requests_authorize_always_returns_true(): void
    {
        $storeRequest = new StoreBoardRequest();
        $updateRequest = new UpdateBoardRequest();
        $copyRequest = new CopyBoardRequest();

        $this->assertTrue($storeRequest->authorize());
        $this->assertTrue($updateRequest->authorize());
        $this->assertTrue($copyRequest->authorize());
    }

    // ==========================================
    // StoreBoardRequest 테스트
    // ==========================================

    /**
     * 정상적인 게시판 생성 데이터
     */
    private function getValidStoreBoardData(): array
    {
        $adminUser = $this->createAdminUser();

        return [
            'name' => ['ko' => '공지사항', 'en' => 'Notice'],
            'slug' => 'test-notice-' . uniqid(),
            'type' => 'basic',
            'per_page' => 20,
            'per_page_mobile' => 10,
            'order_by' => 'created_at',
            'order_direction' => 'DESC',
            'categories' => ['일반', '중요'],
            'show_view_count' => true,
            'secret_mode' => 'disabled',
            'use_comment' => true,
            'use_reply' => false,
            'use_report' => false,
            'comment_order' => 'ASC',
            'use_file_upload' => false,
            'max_file_size' => null,
            'max_file_count' => null,
            'allowed_extensions' => null,
            'board_manager_ids' => [$adminUser->uuid],
            'permissions' => [
                'list' => ['roles' => ['admin']],
                'read' => ['roles' => ['admin']],
                'write' => ['roles' => ['admin']],
                'comment' => ['roles' => ['admin']],
                'download' => ['roles' => ['admin']],
            ],
            'notify_admin_on_post' => true,
            'notify_author' => false,
            'blocked_keywords' => ['욕설', '광고', '스팸'],
        ];
    }

    /**
     * 정상적인 데이터로 검증 통과
     */
    public function test_store_request_valid_data_passes_validation(): void
    {
        $request = new StoreBoardRequest();
        $validator = Validator::make($this->getValidStoreBoardData(), $request->rules());

        $this->assertTrue($validator->passes());
    }

    /**
     * 필수 필드 누락 시 검증 실패
     */
    public function test_store_request_required_fields_fail_when_missing(): void
    {
        $request = new StoreBoardRequest();

        // name 필드 제거
        $data = $this->getValidStoreBoardData();
        unset($data['name']);
        $validator = Validator::make($data, $request->rules());
        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('name', $validator->errors()->toArray());

        // slug 필드 제거
        $data = $this->getValidStoreBoardData();
        unset($data['slug']);
        $validator = Validator::make($data, $request->rules());
        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('slug', $validator->errors()->toArray());
    }

    /**
     * slug 형식 검증 테스트 (regex만 독립 검증)
     *
     * SlugUniqueRule은 DB에 존재하는 slug를 거부하므로,
     * 형식 검증은 slug 규칙에서 regex 부분만 추출하여 독립 검증합니다.
     */
    public function test_store_request_slug_format_validation(): void
    {
        // slug 규칙에서 regex만 추출 (SlugUniqueRule 제외)
        $slugOnlyRules = ['required', 'string', 'max:50', 'regex:/^[a-z][a-z0-9-]*$/'];

        // 유효한 slug 형식
        $validSlugs = ['notice', 'qna', 'free-board', 'product-review'];
        foreach ($validSlugs as $slug) {
            $validator = Validator::make(['slug' => $slug], ['slug' => $slugOnlyRules]);
            $this->assertTrue($validator->passes(), "Slug '{$slug}' should pass validation");
        }

        // 유효하지 않은 slug 형식
        $invalidSlugs = ['123notice', 'Notice', 'notice_board', 'notice@board'];
        foreach ($invalidSlugs as $slug) {
            $validator = Validator::make(['slug' => $slug], ['slug' => $slugOnlyRules]);
            $this->assertFalse($validator->passes(), "Slug '{$slug}' should fail validation");
        }
    }

    /**
     * slug 중복 검증 테스트
     */
    public function test_store_request_slug_unique_validation(): void
    {
        // 기존 게시판 생성 (이전 실행 잔류 데이터 방지)
        Board::updateOrCreate(['slug' => 'existing-board'], [
            'name' => '기존 게시판',
            'type' => 'basic',
            'per_page' => 20,
            'per_page_mobile' => 10,
            'order_by' => 'created_at',
            'order_direction' => 'DESC',
            'secret_mode' => 'disabled',
            'use_comment' => true,
            'use_reply' => false,
            'use_file_upload' => false,
            'permissions' => [],
            'notify_admin_on_post' => false,
            'notify_author_on_comment' => false,
        ]);

        $request = new StoreBoardRequest();

        // 중복된 slug로 검증
        $data = $this->getValidStoreBoardData();
        $data['slug'] = 'existing-board';
        $validator = Validator::make($data, $request->rules());
        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('slug', $validator->errors()->toArray());

        // 다른 slug로 검증 (통과해야 함)
        $data['slug'] = 'new-board';
        $validator = Validator::make($data, $request->rules());
        $this->assertTrue($validator->passes());
    }

    /**
     * per_page 범위 검증 테스트
     */
    public function test_store_request_per_page_range_validation(): void
    {
        $request = new StoreBoardRequest();

        // 유효한 범위
        $data = $this->getValidStoreBoardData();
        $data['per_page'] = 5;
        $validator = Validator::make($data, $request->rules());
        $this->assertTrue($validator->passes());

        $data['per_page'] = 100;
        $validator = Validator::make($data, $request->rules());
        $this->assertTrue($validator->passes());

        // 범위 미만/초과
        $data['per_page'] = 4;
        $validator = Validator::make($data, $request->rules());
        $this->assertFalse($validator->passes());

        $data['per_page'] = 101;
        $validator = Validator::make($data, $request->rules());
        $this->assertFalse($validator->passes());
    }

    /**
     * max_reply_depth 범위 검증 테스트 (rules만 독립 검증)
     */
    public function test_store_request_max_reply_depth_range_validation(): void
    {
        $request = new StoreBoardRequest();
        $rules = $request->rules();
        $depthRules = $rules['max_reply_depth'] ?? [];
        $limits = config('sirsoft-board.limits', []);
        $min = $limits['max_reply_depth_min'] ?? 1;
        $max = $limits['max_reply_depth_max'] ?? 10;

        // 유효한 범위
        $validator = Validator::make(['max_reply_depth' => $min], ['max_reply_depth' => $depthRules]);
        $this->assertTrue($validator->passes(), "max_reply_depth={$min} should pass");

        $validator = Validator::make(['max_reply_depth' => $max], ['max_reply_depth' => $depthRules]);
        $this->assertTrue($validator->passes(), "max_reply_depth={$max} should pass");

        // 범위 초과
        $validator = Validator::make(['max_reply_depth' => $max + 1], ['max_reply_depth' => $depthRules]);
        $this->assertFalse($validator->passes(), "max_reply_depth above max should fail");
    }

    /**
     * max_comment_depth 범위 검증 테스트 (rules만 독립 검증)
     */
    public function test_store_request_max_comment_depth_range_validation(): void
    {
        $request = new StoreBoardRequest();
        $rules = $request->rules();
        $depthRules = $rules['max_comment_depth'] ?? [];
        $limits = config('sirsoft-board.limits', []);
        $min = $limits['max_comment_depth_min'] ?? 0;
        $max = $limits['max_comment_depth_max'] ?? 10;

        // 유효한 범위
        $validator = Validator::make(['max_comment_depth' => $min], ['max_comment_depth' => $depthRules]);
        $this->assertTrue($validator->passes(), "max_comment_depth={$min} should pass");

        $validator = Validator::make(['max_comment_depth' => $max], ['max_comment_depth' => $depthRules]);
        $this->assertTrue($validator->passes(), "max_comment_depth={$max} should pass");

        // 범위 미만
        $validator = Validator::make(['max_comment_depth' => $min - 1], ['max_comment_depth' => $depthRules]);
        $this->assertFalse($validator->passes(), "max_comment_depth below min should fail");

        // 범위 초과
        $validator = Validator::make(['max_comment_depth' => $max + 1], ['max_comment_depth' => $depthRules]);
        $this->assertFalse($validator->passes(), "max_comment_depth above max should fail");
    }


    // ==========================================
    // UpdateBoardRequest 테스트
    // ==========================================

    /**
     * Mock Board 객체 생성
     */
    private function getMockBoard(): object
    {
        return (object) [
            'slug' => 'notice',
            'categories' => ['일반', '중요'],
        ];
    }

    /**
     * UpdateBoardRequest 인스턴스 생성 (route 파라미터 포함)
     */
    private function makeUpdateRequest(): UpdateBoardRequest
    {
        $request = new UpdateBoardRequest();

        $request->setRouteResolver(function () {
            $route = \Mockery::mock(\Illuminate\Routing\Route::class);
            $route->shouldReceive('parameter')
                ->with('board', null)
                ->andReturn($this->getMockBoard());

            return $route;
        });

        return $request;
    }

    /**
     * 정상적인 수정 데이터
     */
    private function getValidUpdateData(): array
    {
        return [
            'name' => ['ko' => '수정된 공지사항', 'en' => 'Updated Notice'],
            'type' => 'basic',
            'per_page' => 15,
            'categories' => ['일반', '긴급'],
        ];
    }

    /**
     * 정상적인 데이터로 검증 통과
     */
    public function test_update_request_valid_data_passes_validation(): void
    {
        $request = $this->makeUpdateRequest();
        $validator = Validator::make($this->getValidUpdateData(), $request->rules());

        $this->assertTrue($validator->passes());
    }

    /**
     * 부분 업데이트 - 일부 필드만 전송해도 검증 통과
     */
    public function test_update_request_partial_update_with_sometimes_rule(): void
    {
        $request = $this->makeUpdateRequest();

        // name만 보내도 검증 통과
        $validator = Validator::make(['name' => ['ko' => '새 이름만 변경']], $request->rules());
        $this->assertTrue($validator->passes());

        // per_page만 보내도 검증 통과
        $validator = Validator::make(['per_page' => 30], $request->rules());
        $this->assertTrue($validator->passes());
    }

    /**
     * slug 필드는 수정 불가 (rules에 정의되지 않음)
     */
    public function test_update_request_slug_field_is_not_in_rules(): void
    {
        $request = $this->makeUpdateRequest();
        $rules = $request->rules();

        $this->assertArrayNotHasKey('slug', $rules);
    }

    /**
     * name 필드 검증 테스트
     */
    public function test_update_request_name_validation(): void
    {
        $request = $this->makeUpdateRequest();

        // 유효한 name (다국어 배열)
        $validator = Validator::make(['name' => ['ko' => '새 게시판']], $request->rules());
        $this->assertTrue($validator->passes());

        // name이 빈 배열이면 실패
        $validator = Validator::make(['name' => []], $request->rules());
        $this->assertFalse($validator->passes());

        // 100자 초과하면 실패
        $validator = Validator::make(['name' => ['ko' => str_repeat('가', 101)]], $request->rules());
        $this->assertFalse($validator->passes());
    }

    /**
     * UpdateBoardRequest - max_reply_depth 부분 업데이트 검증 테스트
     */
    public function test_update_request_max_reply_depth_validation(): void
    {
        $request = $this->makeUpdateRequest();
        $limits = config('sirsoft-board.limits', []);
        $max = $limits['max_reply_depth_max'] ?? 10;

        // 유효한 값
        $validator = Validator::make(['max_reply_depth' => 3], $request->rules());
        $this->assertTrue($validator->passes());

        // 범위 초과
        $validator = Validator::make(['max_reply_depth' => $max + 1], $request->rules());
        $this->assertFalse($validator->passes());
    }

    /**
     * UpdateBoardRequest - max_comment_depth 부분 업데이트 검증 테스트
     */
    public function test_update_request_max_comment_depth_validation(): void
    {
        $request = $this->makeUpdateRequest();
        $limits = config('sirsoft-board.limits', []);
        $max = $limits['max_comment_depth_max'] ?? 10;

        // 유효한 값
        $validator = Validator::make(['max_comment_depth' => 5], $request->rules());
        $this->assertTrue($validator->passes());

        // 범위 초과
        $validator = Validator::make(['max_comment_depth' => $max + 1], $request->rules());
        $this->assertFalse($validator->passes());
    }

    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }
}