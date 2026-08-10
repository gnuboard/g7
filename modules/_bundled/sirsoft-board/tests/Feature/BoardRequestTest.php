<?php

namespace Modules\Sirsoft\Board\Tests\Feature;

// 테스트 베이스 클래스 수동 require (autoload 전에 로드 필요)
require_once __DIR__.'/../ModuleTestCase.php';

use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Validator;
use Modules\Sirsoft\Board\Http\Requests\Admin\StoreBoardSettingsRequest;
use Modules\Sirsoft\Board\Http\Requests\CopyBoardRequest;
use Modules\Sirsoft\Board\Http\Requests\StoreBoardRequest;
use Modules\Sirsoft\Board\Http\Requests\UpdateBoardRequest;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\BoardType;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

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

        BoardType::firstOrCreate(
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
        $storeRequest = new StoreBoardRequest;
        $updateRequest = new UpdateBoardRequest;
        $copyRequest = new CopyBoardRequest;

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
            'slug' => 'test-notice-'.uniqid(),
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
        $request = new StoreBoardRequest;
        $validator = Validator::make($this->getValidStoreBoardData(), $request->rules());

        $this->assertTrue($validator->passes());
    }

    /**
     * 필수 필드 누락 시 검증 실패
     */
    public function test_store_request_required_fields_fail_when_missing(): void
    {
        $request = new StoreBoardRequest;

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

        $request = new StoreBoardRequest;

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
        $request = new StoreBoardRequest;

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
        $request = new StoreBoardRequest;
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
        $this->assertFalse($validator->passes(), 'max_reply_depth above max should fail');
    }

    /**
     * max_comment_depth 범위 검증 테스트 (rules만 독립 검증)
     */
    public function test_store_request_max_comment_depth_range_validation(): void
    {
        $request = new StoreBoardRequest;
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
        $this->assertFalse($validator->passes(), 'max_comment_depth below min should fail');

        // 범위 초과
        $validator = Validator::make(['max_comment_depth' => $max + 1], ['max_comment_depth' => $depthRules]);
        $this->assertFalse($validator->passes(), 'max_comment_depth above max should fail');
    }

    // ==========================================
    // UpdateBoardRequest 테스트
    // ==========================================

    /**
     * Mock Board 객체 생성
     */
    private function getMockBoard(bool $useFileUpload = true): object
    {
        return (object) [
            'slug' => 'notice',
            'categories' => ['일반', '중요'],
            // prepareForValidation() 이 exclude_if 판정 기준으로 주입하는 저장값
            'use_file_upload' => $useFileUpload,
        ];
    }

    /**
     * UpdateBoardRequest 인스턴스 생성 (route 파라미터 포함)
     *
     * @param  bool  $boardUseFileUpload  기존 게시판에 저장된 첨부 사용 여부
     */
    private function makeUpdateRequest(bool $boardUseFileUpload = true): UpdateBoardRequest
    {
        $request = new UpdateBoardRequest;

        $request->setRouteResolver(function () use ($boardUseFileUpload) {
            $route = \Mockery::mock(Route::class);
            $route->shouldReceive('parameter')
                ->with('board', null)
                ->andReturn($this->getMockBoard($boardUseFileUpload));

            return $route;
        });

        return $request;
    }

    /**
     * prepareForValidation() 을 거친 검증 입력을 만듭니다.
     *
     * `Validator::make($data, $request->rules())` 만으로는 전처리가 실행되지 않아
     * 조건 필드 주입(기존 게시판 값)을 검증할 수 없다.
     *
     * @param  UpdateBoardRequest  $request  대상 요청
     * @param  array<string, mixed>  $data  전송 페이로드
     * @return array<string, mixed> 전처리를 마친 입력
     */
    private function prepareUpdateInput(UpdateBoardRequest $request, array $data): array
    {
        $request->merge($data);

        $prepare = new \ReflectionMethod($request, 'prepareForValidation');
        $prepare->setAccessible(true);
        $prepare->invoke($request);

        return $request->all();
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

    /**
     * StoreBoardRequest - max_comment_length 상한 10000 검증 (회귀: issue#413)
     *
     * 환경설정에서 max_comment_length를 1001~10000으로 저장할 수 있으므로
     * 게시판 생성 Request도 동일 상한(10000)을 허용해야 함.
     * config('sirsoft-board.limits.max_comment_length_max') 가 10000 이어야 함.
     */
    public function test_store_request_max_comment_length_allows_up_to_10000(): void
    {
        $adminUser = $this->createAdminUser();
        $base = array_merge($this->getValidStoreBoardData(), [
            'board_manager_ids' => [$adminUser->uuid],
        ]);

        // 고정값 10000은 통과해야 함 (config 상한이 10000 이상이어야만 성립)
        $request = new StoreBoardRequest;
        $validator = Validator::make(array_merge($base, ['max_comment_length' => 10000]), $request->rules());
        $this->assertTrue($validator->passes(), 'max_comment_length=10000 should pass (config max_comment_length_max must be 10000)');

        // 10001은 실패해야 함
        $validator = Validator::make(array_merge($base, ['max_comment_length' => 10001]), $request->rules());
        $this->assertFalse($validator->passes(), 'max_comment_length=10001 should fail');
    }

    /**
     * UpdateBoardRequest - max_comment_length 상한 10000 검증 (회귀: issue#413)
     */
    public function test_update_request_max_comment_length_allows_up_to_10000(): void
    {
        $request = $this->makeUpdateRequest();

        // 고정값 10000은 통과해야 함 (config 상한이 10000 이상이어야만 성립)
        $validator = Validator::make(['max_comment_length' => 10000], $request->rules());
        $this->assertTrue($validator->passes(), 'max_comment_length=10000 should pass (config max_comment_length_max must be 10000)');

        // 10001은 실패해야 함
        $validator = Validator::make(['max_comment_length' => 10001], $request->rules());
        $this->assertFalse($validator->passes(), 'max_comment_length=10001 should fail');
    }

    /**
     * StoreBoardRequest - max_title_length 상한 1000 검증 (회귀: issue#413)
     *
     * 환경설정에서 max_title_length를 201~1000으로 저장할 수 있으므로
     * 게시판 생성 Request도 동일 상한(1000)을 허용해야 함.
     */
    public function test_store_request_max_title_length_allows_up_to_1000(): void
    {
        $adminUser = $this->createAdminUser();
        $base = array_merge($this->getValidStoreBoardData(), [
            'board_manager_ids' => [$adminUser->uuid],
        ]);

        $request = new StoreBoardRequest;
        $validator = Validator::make(array_merge($base, ['max_title_length' => 1000]), $request->rules());
        $this->assertTrue($validator->passes(), 'max_title_length=1000 should pass (config max_title_length_max must be 1000)');

        $validator = Validator::make(array_merge($base, ['max_title_length' => 1001]), $request->rules());
        $this->assertFalse($validator->passes(), 'max_title_length=1001 should fail');
    }

    /**
     * UpdateBoardRequest - max_title_length 상한 1000 검증 (회귀: issue#413)
     */
    public function test_update_request_max_title_length_allows_up_to_1000(): void
    {
        $request = $this->makeUpdateRequest();

        $validator = Validator::make(['max_title_length' => 1000], $request->rules());
        $this->assertTrue($validator->passes(), 'max_title_length=1000 should pass (config max_title_length_max must be 1000)');

        $validator = Validator::make(['max_title_length' => 1001], $request->rules());
        $this->assertFalse($validator->passes(), 'max_title_length=1001 should fail');
    }

    /**
     * StoreBoardRequest - max_content_length 상한 100000 검증 (회귀: issue#413)
     *
     * 환경설정에서 max_content_length를 50001~100000으로 저장할 수 있으므로
     * 게시판 생성 Request도 동일 상한(100000)을 허용해야 함.
     */
    public function test_store_request_max_content_length_allows_up_to_100000(): void
    {
        $adminUser = $this->createAdminUser();
        $base = array_merge($this->getValidStoreBoardData(), [
            'board_manager_ids' => [$adminUser->uuid],
        ]);

        $request = new StoreBoardRequest;
        $validator = Validator::make(array_merge($base, ['max_content_length' => 100000]), $request->rules());
        $this->assertTrue($validator->passes(), 'max_content_length=100000 should pass (config max_content_length_max must be 100000)');

        $validator = Validator::make(array_merge($base, ['max_content_length' => 100001]), $request->rules());
        $this->assertFalse($validator->passes(), 'max_content_length=100001 should fail');
    }

    /**
     * UpdateBoardRequest - max_content_length 상한 100000 검증 (회귀: issue#413)
     */
    public function test_update_request_max_content_length_allows_up_to_100000(): void
    {
        $request = $this->makeUpdateRequest();

        $validator = Validator::make(['max_content_length' => 100000], $request->rules());
        $this->assertTrue($validator->passes(), 'max_content_length=100000 should pass (config max_content_length_max must be 100000)');

        $validator = Validator::make(['max_content_length' => 100001], $request->rules());
        $this->assertFalse($validator->passes(), 'max_content_length=100001 should fail');
    }

    // ==========================================
    // 카테고리 최대 개수 / 빈 이름 검증 (회귀: issue#413 item 19-4a)
    // ==========================================

    /**
     * StoreBoardRequest - 카테고리 최대 개수 초과 시 검증 실패 (회귀: issue#413 19-4a)
     *
     * config('sirsoft-board.limits.category_max') 개수까지는 통과,
     * 초과하면 실패해야 함. (이전엔 max 규칙이 없어 51개 저장이 200 통과되던 버그)
     */
    public function test_store_request_categories_max_count_validation(): void
    {
        $adminUser = $this->createAdminUser();
        $base = array_merge($this->getValidStoreBoardData(), [
            'board_manager_ids' => [$adminUser->uuid],
        ]);
        $max = config('sirsoft-board.limits.category_max', 50);

        $request = new StoreBoardRequest;

        // 정확히 max 개수는 통과
        $atMax = array_map(fn ($i) => "분류{$i}", range(1, $max));
        $validator = Validator::make(array_merge($base, ['categories' => $atMax]), $request->rules());
        $this->assertTrue($validator->passes(), "categories count={$max} should pass");

        // max + 1 개수는 실패
        $overMax = array_map(fn ($i) => "분류{$i}", range(1, $max + 1));
        $validator = Validator::make(array_merge($base, ['categories' => $overMax]), $request->rules());
        $this->assertFalse($validator->passes(), 'categories count over max should fail');
        $this->assertArrayHasKey('categories', $validator->errors()->toArray());
    }

    /**
     * StoreBoardRequest - 빈 카테고리명 차단 (회귀: issue#413 19-4a)
     */
    public function test_store_request_categories_empty_name_fails(): void
    {
        $adminUser = $this->createAdminUser();
        $base = array_merge($this->getValidStoreBoardData(), [
            'board_manager_ids' => [$adminUser->uuid],
        ]);

        $request = new StoreBoardRequest;

        // 빈 문자열 카테고리 포함 시 실패
        $validator = Validator::make(array_merge($base, ['categories' => ['일반', '']]), $request->rules());
        $this->assertFalse($validator->passes(), 'empty category name should fail');
        $this->assertArrayHasKey('categories.1', $validator->errors()->toArray());

        // 공백만 있는 카테고리도 실패
        $validator = Validator::make(array_merge($base, ['categories' => ['일반', '   ']]), $request->rules());
        $this->assertFalse($validator->passes(), 'whitespace-only category name should fail');
    }

    /**
     * UpdateBoardRequest - 카테고리 최대 개수 초과 시 검증 실패 (회귀: issue#413 19-4a)
     */
    public function test_update_request_categories_max_count_validation(): void
    {
        $request = $this->makeUpdateRequest();
        $max = config('sirsoft-board.limits.category_max', 50);

        // 정확히 max 개수는 통과
        $atMax = array_map(fn ($i) => "분류{$i}", range(1, $max));
        $validator = Validator::make(['categories' => $atMax], $request->rules());
        $this->assertTrue($validator->passes(), "categories count={$max} should pass");

        // max + 1 개수는 실패
        $overMax = array_map(fn ($i) => "분류{$i}", range(1, $max + 1));
        $validator = Validator::make(['categories' => $overMax], $request->rules());
        $this->assertFalse($validator->passes(), 'categories count over max should fail');
        $this->assertArrayHasKey('categories', $validator->errors()->toArray());
    }

    /**
     * UpdateBoardRequest - 빈 카테고리명 차단 (회귀: issue#413 19-4a)
     */
    public function test_update_request_categories_empty_name_fails(): void
    {
        $request = $this->makeUpdateRequest();

        $validator = Validator::make(['categories' => ['일반', '']], $request->rules());
        $this->assertFalse($validator->passes(), 'empty category name should fail');
        $this->assertArrayHasKey('categories.1', $validator->errors()->toArray());
    }

    // ==========================================
    // StoreBoardSettingsRequest - report_permissions 검증 (회귀: issue#413)
    // ==========================================

    /**
     * report_permissions.view_roles 빈 배열은 검증 실패해야 함 (회귀: issue#413)
     *
     * nullable → required_with+min:1 변경으로 빈 배열 전송 시
     * syncReportPermissionRoles()가 모든 역할을 detach하는 버그 차단.
     */
    public function test_settings_request_report_permissions_empty_view_roles_fails(): void
    {
        $request = new StoreBoardSettingsRequest;
        $validator = Validator::make([
            'report_permissions' => [
                'view_roles' => [],
                'manage_roles' => ['admin'],
            ],
        ], $request->rules());

        $this->assertFalse($validator->passes(), 'view_roles=[] should fail with min:1');
        $this->assertTrue($validator->errors()->has('report_permissions.view_roles'));
    }

    /**
     * report_permissions.manage_roles 빈 배열은 검증 실패해야 함 (회귀: issue#413)
     */
    public function test_settings_request_report_permissions_empty_manage_roles_fails(): void
    {
        $request = new StoreBoardSettingsRequest;
        $validator = Validator::make([
            'report_permissions' => [
                'view_roles' => ['admin'],
                'manage_roles' => [],
            ],
        ], $request->rules());

        $this->assertFalse($validator->passes(), 'manage_roles=[] should fail with min:1');
        $this->assertTrue($validator->errors()->has('report_permissions.manage_roles'));
    }

    /**
     * default_board_permissions 의 nested 그룹 키는 저장 전 제거되어야 함 (회귀: issue#413)
     *
     * 레거시 오염 데이터로 flat 권한 키(값=역할 배열) 외에 그룹 키(admin/posts/comments/
     * attachments, 값=중첩 객체)가 섞여 들어오면, 권한 설정 화면에 raw i18n 키와
     * [object Object] 로 노출된다. prepareForValidation 에서 값이 배열인 flat 키만
     * 남기고 nested 객체 그룹 키는 걸러내 재오염을 차단한다.
     */
    public function test_settings_request_strips_nested_group_permission_keys(): void
    {
        $request = StoreBoardSettingsRequest::create('/api/modules/sirsoft-board/admin/settings', 'PUT', [
            '_tab' => 'basic_defaults',
            'basic_defaults' => [
                'default_board_permissions' => [
                    // 정상 flat 키 (값 = 역할 배열) — 유지되어야 함
                    'posts.read' => ['admin', 'user'],
                    'manager' => ['admin'],
                    // 오염 그룹 키 (값 = 중첩 객체) — 제거되어야 함
                    'admin' => ['posts' => ['read' => ['admin']]],
                    'posts' => ['read' => ['admin', 'user', 'guest']],
                    'comments' => ['read' => ['admin']],
                    'attachments' => ['upload' => ['admin']],
                ],
            ],
        ]);
        $request->setContainer(app())->setRedirector(app('redirect'));
        $request->validateResolved();

        $perms = $request->input('basic_defaults.default_board_permissions');

        // 정상 flat 키는 유지
        $this->assertArrayHasKey('posts.read', $perms);
        $this->assertArrayHasKey('manager', $perms);
        // nested 그룹 키는 제거
        $this->assertArrayNotHasKey('admin', $perms);
        $this->assertArrayNotHasKey('posts', $perms);
        $this->assertArrayNotHasKey('comments', $perms);
        $this->assertArrayNotHasKey('attachments', $perms);
    }

    /**
     * 유효한 역할 식별자가 포함된 report_permissions는 통과해야 함 (회귀: issue#413)
     */
    public function test_settings_request_report_permissions_valid_roles_passes(): void
    {
        $role = Role::first();
        $this->assertNotNull($role, 'At least one Role must exist in the test DB');

        $request = new StoreBoardSettingsRequest;
        $validator = Validator::make([
            'report_permissions' => [
                'view_roles' => [$role->identifier],
                'manage_roles' => [$role->identifier],
            ],
        ], $request->rules());

        $this->assertTrue($validator->passes(), 'Valid role identifiers should pass');
    }

    /**
     * UpdateBoardRequest - 정수 설정 필드의 .integer 위반 시 한국어 라벨 표시 검증
     *
     * 회귀 (issue#413): messages() 에 .integer 키가 없어 코어 기본 메시지로 폴백할 때,
     * attributes() 에 필드 라벨이 없으면 :attribute 가 영문 snake_case ("per page")
     * 로 노출되던 문제. attributes() 에서 settings.basic_defaults 라벨을 재사용해
     * 한국어 라벨이 표시되어야 한다.
     */
    public function test_update_request_integer_fields_show_localized_attribute_on_type_violation(): void
    {
        app()->setLocale('ko');

        $request = $this->makeUpdateRequest();

        $integerFields = [
            'per_page', 'per_page_mobile', 'new_display_hours',
            'min_title_length', 'max_title_length',
            'min_content_length', 'max_content_length',
            'min_comment_length', 'max_comment_length',
            'max_file_size', 'max_file_count',
            'max_reply_depth', 'max_comment_depth',
        ];

        $payload = [];
        foreach ($integerFields as $field) {
            $payload[$field] = 'NOTINT';
        }

        $validator = Validator::make(
            $payload,
            $request->rules(),
            $request->messages(),
            $request->attributes()
        );

        $this->assertTrue($validator->fails());

        foreach ($integerFields as $field) {
            $message = $validator->errors()->first($field);
            $this->assertStringContainsString('정수', $message, "{$field} 메시지가 정수 위반이어야 함");

            // 영문 snake_case 가 단어로 변환된 형태("per page" 등)가 노출되면 안 됨
            $this->assertDoesNotMatchRegularExpression(
                '/^[a-z_ ]+ 필드는 정수/u',
                $message,
                "{$field} 메시지에 영문 필드명이 노출됨: {$message}"
            );
        }
    }

    /**
     * UpdateBoardRequest - per_page .integer 위반 메시지의 영문 로케일 라벨 검증
     *
     * 회귀 (issue#413): en 로케일에서도 attributes() 라벨("Posts Per Page")이
     * 적용되어 Laravel 의 자동 변환("per page") 대신 정의된 라벨이 표시되어야 한다.
     */
    public function test_update_request_per_page_integer_uses_defined_label_in_english(): void
    {
        app()->setLocale('en');

        $request = $this->makeUpdateRequest();

        $validator = Validator::make(
            ['per_page' => 'NOTINT'],
            $request->rules(),
            $request->messages(),
            $request->attributes()
        );

        $this->assertTrue($validator->fails());
        $this->assertStringContainsString(
            'Posts Per Page',
            $validator->errors()->first('per_page')
        );
    }

    // ==========================================
    // 길이 최소/최대 교차 검증 (회귀: issue#413-22-3)
    // min_*_length > max_*_length 조합이 저장되던 문제
    // ==========================================

    /**
     * 검증기에 withValidator(교차검증)까지 적용하여 통과 여부를 반환합니다.
     *
     * @param  FormRequest  $request  대상 Request
     * @param  array  $data  검증 데이터
     * @return \Illuminate\Validation\Validator 교차검증까지 적용된 검증기
     */
    private function validateWithCrossRules($request, array $data): \Illuminate\Validation\Validator
    {
        $validator = Validator::make($data, $request->rules(), $request->messages(), $request->attributes());
        $request->withValidator($validator);

        return $validator;
    }

    /**
     * StoreBoardRequest - 제목/본문/댓글 길이의 min > max 조합은 거부되어야 한다.
     *
     * @scenario case=store_min_gt_max
     *
     * @effects min_gt_max_rejected_on_store, error_attached_to_max_field
     */
    public function test_store_request_rejects_min_length_greater_than_max(): void
    {
        $request = new StoreBoardRequest;

        $cases = [
            ['min_title_length' => 200, 'max_title_length' => 1, 'field' => 'max_title_length'],
            ['min_content_length' => 10000, 'max_content_length' => 1, 'field' => 'max_content_length'],
            ['min_comment_length' => 1000, 'max_comment_length' => 1, 'field' => 'max_comment_length'],
        ];

        foreach ($cases as $case) {
            $field = $case['field'];
            unset($case['field']);
            $validator = $this->validateWithCrossRules($request, array_merge($this->getValidStoreBoardData(), $case));

            $this->assertTrue(
                $validator->fails(),
                "min > max 조합 ({$field})은 검증 실패해야 한다."
            );
            $this->assertArrayHasKey(
                $field,
                $validator->errors()->toArray(),
                "교차검증 오류는 {$field} 필드에 추가되어야 한다."
            );
        }
    }

    /**
     * StoreBoardRequest - min == max 정상 조합은 통과해야 한다.
     *
     * @scenario case=store_min_eq_max
     *
     * @effects min_le_max_passes
     */
    public function test_store_request_allows_min_length_equal_to_max(): void
    {
        $request = new StoreBoardRequest;

        $validator = $this->validateWithCrossRules($request, array_merge($this->getValidStoreBoardData(), [
            'min_title_length' => 10, 'max_title_length' => 10,
        ]));
        $this->assertTrue($validator->passes(), 'min == max 조합은 통과해야 한다.');
    }

    /**
     * StoreBoardRequest - min < max 정상 조합은 통과해야 한다.
     *
     * @scenario case=store_min_lt_max
     *
     * @effects min_le_max_passes
     */
    public function test_store_request_allows_min_length_less_than_max(): void
    {
        $request = new StoreBoardRequest;

        $validator = $this->validateWithCrossRules($request, array_merge($this->getValidStoreBoardData(), [
            'min_title_length' => 2, 'max_title_length' => 100,
            'min_content_length' => 10, 'max_content_length' => 5000,
            'min_comment_length' => 2, 'max_comment_length' => 500,
        ]));
        $this->assertTrue($validator->passes(), 'min < max 조합은 통과해야 한다.');
    }

    /**
     * UpdateBoardRequest - 두 값이 함께 전송되면 min > max 조합은 거부되어야 한다.
     *
     * @scenario case=update_both_min_gt_max
     *
     * @effects min_gt_max_rejected_on_update_when_both_present
     */
    public function test_update_request_rejects_min_length_greater_than_max(): void
    {
        $request = $this->makeUpdateRequest();

        $validator = $this->validateWithCrossRules($request, [
            'min_title_length' => 200, 'max_title_length' => 1,
        ]);

        $this->assertTrue($validator->fails(), 'Update 에서도 min > max 조합은 실패해야 한다.');
        $this->assertArrayHasKey('max_title_length', $validator->errors()->toArray());
    }

    /**
     * UpdateBoardRequest - 한쪽 값만 부분 전송 시에는 교차검증을 적용하지 않는다.
     *
     * 동시 제출이 아닌 부분 수정에서는 DB 기존값과의 비교 없이 통과시킨다.
     *
     * @scenario case=update_partial_skip
     *
     * @effects cross_validation_skipped_on_partial_update
     */
    public function test_update_request_skips_cross_validation_on_partial_submit(): void
    {
        $request = $this->makeUpdateRequest();

        // max_title_length 만 전송 (min 미전송) → 교차검증 미적용
        $validator = $this->validateWithCrossRules($request, [
            'max_title_length' => 1,
        ]);

        $this->assertTrue($validator->passes(), '한쪽 값만 전송 시 교차검증은 적용되지 않아야 한다.');
    }

    // ==========================================
    // allowed_extensions 빈 값 차단 (회귀)
    // 빈 배열 저장 시 안내("빈 값=모든 확장자")와 반대로 전 파일 업로드가 거부되던 문제.
    // → 빈 값 금지(최소 1개 필수). 빈 배열이 실제로 차단되는지(규칙이 skip 되지 않는지)
    //   와 첨부 미사용 게시판은 빈 값이 허용되는지를 직접 단언한다.
    // ==========================================

    /**
     * StoreBoardRequest - 첨부 사용 게시판에서 allowed_extensions 빈 배열은 검증 실패해야 함 (회귀)
     *
     * use_file_upload=true 인데 빈 배열을 보내면 required + min:1 로 차단되어야 한다.
     */
    public function test_store_request_empty_allowed_extensions_fails_when_upload_enabled(): void
    {
        $request = new StoreBoardRequest;

        $data = array_merge($this->getValidStoreBoardData(), [
            'use_file_upload' => true,
            'allowed_extensions' => [],
        ]);
        $validator = Validator::make($data, $request->rules());

        $this->assertFalse($validator->passes(), 'use_file_upload=true + allowed_extensions=[] should fail');
        $this->assertArrayHasKey('allowed_extensions', $validator->errors()->toArray());
    }

    /**
     * StoreBoardRequest - 빈 확장자 거부 시 한국어 안내 메시지가 노출되어야 함 (회귀)
     *
     * messages() ↔ validation.allowed_extensions.min 매핑이 연결되어 코어 기본 메시지가
     * 아닌 정의된 메시지가 표시되는지 확인한다.
     */
    public function test_store_request_empty_allowed_extensions_shows_localized_message(): void
    {
        app()->setLocale('ko');

        $request = new StoreBoardRequest;

        $data = array_merge($this->getValidStoreBoardData(), [
            'use_file_upload' => true,
            'allowed_extensions' => [],
        ]);
        $validator = Validator::make($data, $request->rules(), $request->messages(), $request->attributes());

        $this->assertTrue($validator->fails());
        $this->assertStringContainsString(
            '허용 파일 확장자를 최소 1개 이상',
            $validator->errors()->first('allowed_extensions')
        );
    }

    /**
     * StoreBoardRequest - 정상 확장자 1개 이상 지정 시 통과 (회귀)
     */
    public function test_store_request_nonempty_allowed_extensions_passes_when_upload_enabled(): void
    {
        $request = new StoreBoardRequest;

        $data = array_merge($this->getValidStoreBoardData(), [
            'use_file_upload' => true,
            'allowed_extensions' => ['jpg', 'png'],
        ]);
        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($validator->passes(), 'use_file_upload=true + 확장자 지정 시 통과해야 함');
    }

    /**
     * StoreBoardRequest - 첨부 미사용 게시판은 빈 확장자 허용 (회귀 방지)
     *
     * use_file_upload=false 이면 exclude_if 로 검증에서 제외되어 빈 배열도 통과해야 한다.
     * (required_if + min:1 만으로는 min:1 이 빈 배열에 무조건 걸려 실패하므로 exclude_if 사용)
     */
    public function test_store_request_empty_allowed_extensions_passes_when_upload_disabled(): void
    {
        $request = new StoreBoardRequest;

        $data = array_merge($this->getValidStoreBoardData(), [
            'use_file_upload' => false,
            'allowed_extensions' => [],
        ]);
        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($validator->passes(), 'use_file_upload=false 이면 빈 확장자도 통과해야 함');
    }

    /**
     * UpdateBoardRequest - 첨부 사용 게시판에서 allowed_extensions 빈 배열은 실패 (케이스 2)
     *
     * 첨부를 쓰는 게시판에 확장자를 비워 저장하면 이후 모든 파일이 거부되므로 차단한다.
     * 첨부 미사용 게시판은 exclude_if 로 배제되어 통과한다(케이스 3 참조) — 이 테스트는
     * 그 경계 중 "차단되어야 하는 쪽"만 고정한다.
     */
    public function test_update_request_empty_allowed_extensions_fails_when_upload_enabled(): void
    {
        $request = $this->makeUpdateRequest();

        $validator = Validator::make(
            ['use_file_upload' => true, 'allowed_extensions' => []],
            $request->rules()
        );

        $this->assertFalse($validator->passes(), 'use_file_upload=true + [] 는 실패해야 함');
        $this->assertArrayHasKey('allowed_extensions', $validator->errors()->toArray());
    }

    /**
     * UpdateBoardRequest - 첨부 미사용 게시판의 빈 확장자는 통과 (케이스 3 · GH #78)
     */
    public function test_update_request_empty_allowed_extensions_passes_when_upload_disabled(): void
    {
        $request = $this->makeUpdateRequest();

        $validator = Validator::make(
            ['use_file_upload' => false, 'allowed_extensions' => []],
            $request->rules()
        );

        $this->assertTrue($validator->passes(), 'use_file_upload=false 이면 빈 확장자도 통과해야 함');
    }

    /**
     * UpdateBoardRequest - 토글 미전송 + 확장자 전송 시 기존 게시판 값을 따른다 (케이스 5)
     *
     * exclude_if 는 조건 필드가 데이터에 없으면 배제하지 않는다. 부분 수정 요청이
     * use_file_upload 없이 allowed_extensions 만 보내면 첨부 미사용 게시판인데도
     * 확장자 필수 규칙이 발화하므로, prepareForValidation() 이 저장값을 주입해야 한다.
     */
    public function test_update_request_omitted_toggle_follows_stored_board_value(): void
    {
        // 저장된 게시판이 첨부 미사용 → 빈 확장자 통과
        $disabled = $this->makeUpdateRequest(boardUseFileUpload: false);
        $disabledInput = $this->prepareUpdateInput($disabled, ['allowed_extensions' => []]);

        $this->assertFalse(
            $disabledInput['use_file_upload'],
            '토글 미전송 시 기존 게시판의 use_file_upload 가 주입되어야 함'
        );
        $this->assertTrue(
            Validator::make($disabledInput, $disabled->rules())->passes(),
            '첨부 미사용 게시판은 토글 미전송 + 빈 확장자도 통과해야 함'
        );

        // 저장된 게시판이 첨부 사용 → 빈 확장자 차단
        $enabled = $this->makeUpdateRequest(boardUseFileUpload: true);
        $enabledInput = $this->prepareUpdateInput($enabled, ['allowed_extensions' => []]);

        $this->assertTrue($enabledInput['use_file_upload']);
        $this->assertFalse(
            Validator::make($enabledInput, $enabled->rules())->passes(),
            '첨부 사용 게시판은 토글 미전송이어도 빈 확장자를 차단해야 함'
        );
    }

    /**
     * UpdateBoardRequest - 토글·확장자 모두 미전송이면 주입하지 않는다 (케이스 4 인접)
     *
     * 확장자를 건드리지 않는 부분 수정에 조건 필드를 주입하면, 보내지도 않은 토글이
     * 검증 대상으로 올라온다.
     */
    public function test_update_request_does_not_inject_toggle_when_extensions_omitted(): void
    {
        $request = $this->makeUpdateRequest(boardUseFileUpload: false);

        $input = $this->prepareUpdateInput($request, ['per_page' => 30]);

        $this->assertArrayNotHasKey('use_file_upload', $input);
        $this->assertTrue(Validator::make($input, $request->rules())->passes());
    }

    /**
     * UpdateBoardRequest - allowed_extensions 미전송(부분 수정)은 통과해야 함 (회귀 방지)
     *
     * sometimes 규칙으로 키가 없으면 검증을 건너뛴다.
     */
    public function test_update_request_omitted_allowed_extensions_passes(): void
    {
        $request = $this->makeUpdateRequest();

        // allowed_extensions 미포함 부분 수정
        $validator = Validator::make(['per_page' => 30], $request->rules());

        $this->assertTrue($validator->passes(), 'allowed_extensions 미전송 시 통과해야 함');
    }

    /**
     * UpdateBoardRequest - 정상 확장자 전송 시 통과 (회귀)
     */
    public function test_update_request_nonempty_allowed_extensions_passes(): void
    {
        $request = $this->makeUpdateRequest();

        $validator = Validator::make(['allowed_extensions' => ['pdf', 'zip']], $request->rules());

        $this->assertTrue($validator->passes(), '확장자 1개 이상 전송 시 통과해야 함');
    }

    /**
     * StoreBoardSettingsRequest - basic_defaults.allowed_extensions 빈 배열 차단 (회귀)
     *
     * 설정 탭 저장에서 빈 배열은 sometimes + array + min:1 로 차단되어야 한다.
     */
    public function test_settings_request_empty_allowed_extensions_fails(): void
    {
        $request = new StoreBoardSettingsRequest;

        $validator = Validator::make([
            'basic_defaults' => [
                'allowed_extensions' => [],
            ],
        ], $request->rules());

        $this->assertFalse($validator->passes(), 'basic_defaults.allowed_extensions=[] should fail');
        $this->assertArrayHasKey('basic_defaults.allowed_extensions', $validator->errors()->toArray());
    }

    /**
     * StoreBoardSettingsRequest - 정상 확장자 지정 시 통과 (회귀)
     */
    public function test_settings_request_nonempty_allowed_extensions_passes(): void
    {
        $request = new StoreBoardSettingsRequest;

        $validator = Validator::make([
            'basic_defaults' => [
                'allowed_extensions' => ['jpg', 'png', 'pdf'],
            ],
        ], $request->rules());

        $this->assertTrue($validator->passes(), '확장자 지정 시 통과해야 함');
    }

    /**
     * StoreBoardSettingsRequest - allowed_extensions 미포함 부분 탭 저장은 통과 (회귀 방지)
     *
     * 다른 탭(예: report_policy)만 저장할 때 allowed_extensions 키가 없어도
     * sometimes 로 검증을 건너뛰어 통과해야 한다.
     */
    public function test_settings_request_partial_tab_save_without_extensions_passes(): void
    {
        $request = new StoreBoardSettingsRequest;

        // report_policy 탭만 저장 (basic_defaults 미포함)
        $validator = Validator::make([
            'report_policy' => [
                'auto_hide_threshold' => 5,
            ],
        ], $request->rules());

        $this->assertTrue($validator->passes(), '다른 탭만 부분 저장 시 통과해야 함');
    }

    // ==========================================
    // 조건부 검증 매트릭스 (이슈 #78)
    //
    // 관리자 폼은 GET 응답 전체를 그대로 PUT 하므로 요청에는 항상 모든 키가 존재한다.
    // 따라서 sometimes 는 아무 보호도 하지 못하며, 토글 OFF 상태의 종속 필드는
    // exclude_if 로 검증 자체에서 제외되어야 한다.
    // ==========================================

    /**
     * UpdateBoardRequest - 첨부 사용 게시판에서 빈 확장자는 차단되어야 함
     *
     * @scenario case=upload_enabled_extensions_empty
     *
     * @effects upload_enabled_empty_extensions_fails
     */
    public function test_update_matrix_upload_enabled_with_empty_extensions_fails(): void
    {
        $request = $this->makeUpdateRequest();

        $validator = Validator::make([
            'use_file_upload' => true,
            'allowed_extensions' => [],
        ], $request->rules());

        $this->assertFalse($validator->passes(), '첨부 사용 + 빈 확장자는 실패해야 함');
        $this->assertArrayHasKey('allowed_extensions', $validator->errors()->toArray());
    }

    /**
     * UpdateBoardRequest - 첨부 사용 게시판에서 확장자 1개 이상은 통과
     *
     * @scenario case=upload_enabled_extensions_nonempty
     *
     * @effects upload_enabled_nonempty_extensions_passes
     */
    public function test_update_matrix_upload_enabled_with_extensions_passes(): void
    {
        $request = $this->makeUpdateRequest();

        $validator = Validator::make([
            'use_file_upload' => true,
            'allowed_extensions' => ['jpg'],
        ], $request->rules());

        $this->assertTrue($validator->passes(), '첨부 사용 + 확장자 지정은 통과해야 함');
    }

    /**
     * UpdateBoardRequest - 첨부 미사용 게시판은 빈 배열 확장자도 통과해야 함 (이슈 #78)
     *
     * 첨부를 쓰지 않는 게시판을 무변경 저장할 때 422 로 막히던 결함의 회귀 테스트.
     *
     * @scenario case=upload_disabled_extensions_empty
     *
     * @effects upload_disabled_empty_extensions_passes
     */
    public function test_update_matrix_upload_disabled_with_empty_extensions_passes(): void
    {
        $request = $this->makeUpdateRequest();

        $validator = Validator::make([
            'use_file_upload' => false,
            'allowed_extensions' => [],
        ], $request->rules());

        $this->assertTrue(
            $validator->passes(),
            '첨부 미사용 게시판의 빈 확장자는 통과해야 함: '.json_encode($validator->errors()->toArray(), JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * UpdateBoardRequest - 첨부 미사용 게시판은 확장자 null 도 통과해야 함 (이슈 #78)
     *
     * allowed_extensions 컬럼이 NULL 인 레거시 게시판(notice 등) 재현 케이스.
     *
     * @scenario case=upload_disabled_extensions_null
     *
     * @effects upload_disabled_empty_extensions_passes
     */
    public function test_update_matrix_upload_disabled_with_null_extensions_passes(): void
    {
        $request = $this->makeUpdateRequest();

        $validator = Validator::make([
            'use_file_upload' => false,
            'allowed_extensions' => null,
        ], $request->rules());

        $this->assertTrue(
            $validator->passes(),
            '첨부 미사용 게시판의 null 확장자는 통과해야 함: '.json_encode($validator->errors()->toArray(), JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * UpdateBoardRequest - 확장자 키 자체가 없으면 통과 (sometimes 보존)
     *
     * @scenario case=extensions_key_absent
     *
     * @effects extensions_key_absent_passes
     */
    public function test_update_matrix_absent_extensions_key_passes(): void
    {
        $request = $this->makeUpdateRequest();

        $validator = Validator::make(['use_file_upload' => false], $request->rules());

        $this->assertTrue($validator->passes(), '확장자 키 부재 시 통과해야 함');
    }

    /**
     * UpdateBoardRequest - 첨부 미사용 시 확장자는 validated() 에서 제외되어야 함
     *
     * pass/fail 만 단언하면 exclude_if 를 nullable 로 바꿔도 통과하므로,
     * "검증에서 배제된다"는 시맨틱 자체를 고정한다.
     *
     * @scenario case=upload_disabled_extensions_empty
     *
     * @effects upload_disabled_extensions_excluded_from_validated
     */
    public function test_update_matrix_upload_disabled_excludes_extensions_from_validated(): void
    {
        $request = $this->makeUpdateRequest();

        $validator = Validator::make([
            'use_file_upload' => false,
            'allowed_extensions' => [],
        ], $request->rules());

        $this->assertTrue($validator->passes());
        $this->assertArrayNotHasKey(
            'allowed_extensions',
            $validator->validated(),
            '첨부 미사용 시 allowed_extensions 는 검증 통과 데이터에서 배제되어야 함'
        );
    }

    /**
     * UpdateBoardRequest - new_display_hours 경계값 검증
     *
     * 0 = NEW 배지 표시 안 함 (Post::isNew() 가 0 을 정상 처리).
     *
     * @scenario case=new_display_hours_zero
     *
     * @effects new_display_hours_zero_passes, new_display_hours_out_of_range_fails
     *
     * @param  mixed  $value  new_display_hours 값
     * @param  bool  $shouldPass  통과 기대 여부
     */
    #[DataProvider('newDisplayHoursProvider')]
    public function test_update_matrix_new_display_hours_boundaries(mixed $value, bool $shouldPass): void
    {
        $request = $this->makeUpdateRequest();

        $validator = Validator::make(['new_display_hours' => $value], $request->rules());

        $this->assertSame(
            $shouldPass,
            $validator->passes(),
            "new_display_hours={$value} 판정 불일치: ".json_encode($validator->errors()->toArray(), JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * new_display_hours 경계값 데이터.
     *
     * @return array<string, array{mixed, bool}> 값과 통과 기대 여부
     */
    public static function newDisplayHoursProvider(): array
    {
        return [
            '0 (NEW 배지 끄기)' => [0, true],
            '1 (최소 표시)' => [1, true],
            '720 (최대 경계)' => [720, true],
            '-1 (음수)' => [-1, false],
            '721 (상한 초과)' => [721, false],
        ];
    }

    /**
     * StoreBoardRequest - new_display_hours 0 은 통과해야 함
     *
     * @scenario case=new_display_hours_zero
     *
     * @effects new_display_hours_zero_passes
     */
    public function test_store_request_new_display_hours_zero_passes(): void
    {
        $request = new StoreBoardRequest;

        $data = array_merge($this->getValidStoreBoardData(), ['new_display_hours' => 0]);
        $validator = Validator::make($data, $request->rules());

        $this->assertTrue(
            $validator->passes(),
            'new_display_hours=0 은 통과해야 함: '.json_encode($validator->errors()->toArray(), JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * StoreBoardSettingsRequest - 첨부 사용 기본값에서 빈 확장자는 차단
     *
     * @scenario case=upload_enabled_extensions_empty
     *
     * @effects upload_enabled_empty_extensions_fails
     */
    public function test_settings_matrix_upload_enabled_with_empty_extensions_fails(): void
    {
        $request = new StoreBoardSettingsRequest;

        $validator = Validator::make([
            'basic_defaults' => [
                'use_file_upload' => true,
                'allowed_extensions' => [],
            ],
        ], $request->rules());

        $this->assertFalse($validator->passes(), '첨부 사용 기본값 + 빈 확장자는 실패해야 함');
        $this->assertArrayHasKey('basic_defaults.allowed_extensions', $validator->errors()->toArray());
    }

    /**
     * StoreBoardSettingsRequest - 첨부 사용 기본값에서 확장자 지정은 통과
     *
     * @scenario case=upload_enabled_extensions_nonempty
     *
     * @effects upload_enabled_nonempty_extensions_passes
     */
    public function test_settings_matrix_upload_enabled_with_extensions_passes(): void
    {
        $request = new StoreBoardSettingsRequest;

        $validator = Validator::make([
            'basic_defaults' => [
                'use_file_upload' => true,
                'allowed_extensions' => ['jpg'],
            ],
        ], $request->rules());

        $this->assertTrue($validator->passes(), '첨부 사용 기본값 + 확장자 지정은 통과해야 함');
    }

    /**
     * StoreBoardSettingsRequest - 첨부 미사용 기본값은 빈 확장자도 통과해야 함
     *
     * @scenario case=upload_disabled_extensions_empty
     *
     * @effects upload_disabled_empty_extensions_passes
     */
    public function test_settings_matrix_upload_disabled_with_empty_extensions_passes(): void
    {
        $request = new StoreBoardSettingsRequest;

        $validator = Validator::make([
            'basic_defaults' => [
                'use_file_upload' => false,
                'allowed_extensions' => [],
            ],
        ], $request->rules());

        $this->assertTrue(
            $validator->passes(),
            '첨부 미사용 기본값의 빈 확장자는 통과해야 함: '.json_encode($validator->errors()->toArray(), JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * StoreBoardSettingsRequest - use_file_upload 가 null 이어도 빈 확장자는 통과해야 함
     *
     * prepareForValidation() 의 boolean 캐스팅은 isset() 가드를 쓰므로 null 은
     * 캐스팅되지 않고 그대로 통과한다. 이 경로도 배제 대상이어야 한다.
     *
     * @scenario case=upload_disabled_extensions_null
     *
     * @effects settings_upload_null_empty_extensions_passes
     */
    public function test_settings_matrix_upload_null_with_empty_extensions_passes(): void
    {
        $request = new StoreBoardSettingsRequest;

        $validator = Validator::make([
            'basic_defaults' => [
                'use_file_upload' => null,
                'allowed_extensions' => [],
            ],
        ], $request->rules());

        $this->assertTrue(
            $validator->passes(),
            'use_file_upload=null 의 빈 확장자는 통과해야 함: '.json_encode($validator->errors()->toArray(), JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * StoreBoardSettingsRequest - 하한값 0 은 config 계약대로 통과해야 함
     *
     * config('sirsoft-board.limits') 는 min_title_length_min / min_comment_length_min 을
     * 0 으로 선언하고 있으므로 하드코딩된 min:1 은 계약 위반이다.
     *
     * @scenario case=zero_lower_bound_length_fields
     *
     * @effects zero_lower_bound_length_fields_pass
     *
     * @param  string  $field  basic_defaults 하위 필드명
     */
    #[DataProvider('settingsZeroLowerBoundProvider')]
    public function test_settings_matrix_zero_lower_bounds_pass(string $field): void
    {
        $request = new StoreBoardSettingsRequest;

        $validator = Validator::make([
            'basic_defaults' => [$field => 0],
        ], $request->rules());

        $this->assertTrue(
            $validator->passes(),
            "basic_defaults.{$field}=0 은 통과해야 함: ".json_encode($validator->errors()->toArray(), JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * 0 이 허용되어야 하는 basic_defaults 필드 목록.
     *
     * @return array<string, array{string}> 필드명
     */
    public static function settingsZeroLowerBoundProvider(): array
    {
        return [
            'min_title_length' => ['min_title_length'],
            'min_comment_length' => ['min_comment_length'],
            'new_display_hours' => ['new_display_hours'],
        ];
    }

    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }
}
