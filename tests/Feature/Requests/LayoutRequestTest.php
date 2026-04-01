<?php

namespace Tests\Feature\Requests;

use App\Http\Requests\Layout\GetLayoutRequest;
use App\Http\Requests\Layout\StoreLayoutRequest;
use App\Http\Requests\Layout\UpdateLayoutRequest;
use App\Models\Template;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * FormRequest 통합 테스트
 *
 * StoreLayoutRequest, UpdateLayoutRequest, GetLayoutRequest의
 * 검증 로직을 테스트합니다.
 */
class LayoutRequestTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 테스트 템플릿 디렉토리
     */
    private string $testTemplatePath;

    /**
     * 테스트 템플릿 ID
     */
    private string $testTemplateId;

    protected function setUp(): void
    {
        parent::setUp();

        // 한국어 로케일 설정
        App::setLocale('ko');

        // 테스트용 템플릿 생성
        $this->testTemplateId = 'test-template';
        $this->testTemplatePath = base_path("templates/{$this->testTemplateId}");

        // 템플릿 디렉토리 생성
        if (!File::exists($this->testTemplatePath)) {
            File::makeDirectory($this->testTemplatePath, 0755, true);
        }

        // components.json 생성
        $componentsManifest = [
            'basic' => ['Button', 'Input', 'p', 'div'],
            'composite' => ['Card', 'Modal'],
            'layout' => ['Container', 'Section'],
        ];

        File::put(
            "{$this->testTemplatePath}/components.json",
            json_encode($componentsManifest, JSON_PRETTY_PRINT)
        );

        // 데이터베이스에 템플릿 레코드 생성
        Template::create([
            'identifier' => $this->testTemplateId,
            'vendor' => 'test',
            'name' => 'Test Template',
            'version' => '1.0.0',
            'type' => 'user',
            'status' => 'active',
        ]);

        // 캐시 클리어
        Cache::flush();
    }

    protected function tearDown(): void
    {
        // 테스트 템플릿 디렉토리 삭제
        if (File::exists($this->testTemplatePath)) {
            File::deleteDirectory($this->testTemplatePath);
        }

        parent::tearDown();
    }

    /**
     * 정상적인 레이아웃 JSON 데이터
     */
    private function getValidLayoutData(): array
    {
        $template = Template::where('identifier', $this->testTemplateId)->first();

        return [
            'template_id' => $template->id,
            'name' => 'test_layout',
            'content' => [
                'version' => '1.0.0',
                'layout_name' => 'test_layout',
                'components' => [
                    [
                        'id' => 'button-1',
                        'type' => 'basic',
                        'name' => 'Button',
                        'props' => [
                            'label' => 'Click me',
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * StoreLayoutRequest - 정상 데이터 통과
     */
    public function test_store_layout_request_passes_with_valid_data(): void
    {
        // Arrange
        $data = $this->getValidLayoutData();
        $request = new StoreLayoutRequest;

        // Act
        $validator = Validator::make($data, $request->rules());

        // Debug: 검증 실패 시 에러 메시지 출력
        if ($validator->fails()) {
            dump($validator->errors()->toArray());
        }

        // Assert
        $this->assertFalse($validator->fails());
    }

    /**
     * StoreLayoutRequest - 필수 필드 누락 시 실패
     */
    public function test_store_layout_request_fails_without_required_fields(): void
    {
        // Arrange
        $data = [];
        $request = new StoreLayoutRequest;

        // Act
        $validator = Validator::make($data, $request->rules());

        // Assert
        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('template_id', $validator->errors()->toArray());
        $this->assertArrayHasKey('name', $validator->errors()->toArray());
        $this->assertArrayHasKey('content', $validator->errors()->toArray());
    }

    /**
     * StoreLayoutRequest - 잘못된 타입 시 실패
     */
    public function test_store_layout_request_fails_with_invalid_types(): void
    {
        // Arrange
        $data = [
            'template_id' => 'invalid',  // 정수 대신 문자열
            'name' => 12345,             // 문자열 대신 정수
            'content' => 'invalid',      // 배열 대신 문자열
        ];
        $request = new StoreLayoutRequest;

        // Act
        $validator = Validator::make($data, $request->rules());

        // Assert
        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('template_id', $validator->errors()->toArray());
        $this->assertArrayHasKey('name', $validator->errors()->toArray());
        $this->assertArrayHasKey('content', $validator->errors()->toArray());
    }

    /**
     * UpdateLayoutRequest - 부분 업데이트 허용
     */
    public function test_update_layout_request_allows_partial_update(): void
    {
        // Arrange - name만 업데이트
        $data = [
            'name' => 'updated_layout',
        ];
        $request = new UpdateLayoutRequest;

        // Act
        $validator = Validator::make($data, $request->rules());

        // Assert
        $this->assertFalse($validator->fails());
    }

    /**
     * UpdateLayoutRequest - content만 업데이트
     */
    public function test_update_layout_request_allows_content_only_update(): void
    {
        // Arrange - content만 업데이트 (template_id는 ComponentExists 검증을 위해 필요)
        $template = Template::where('identifier', $this->testTemplateId)->first();

        $data = [
            'template_id' => $template->id,
            'content' => [
                'version' => '1.0.0',
                'layout_name' => 'test',
                'components' => [
                    [
                        'id' => 'input-1',
                        'type' => 'basic',
                        'name' => 'Input',
                        'props' => ['placeholder' => 'Enter text'],
                    ],
                ],
            ],
        ];
        $request = new UpdateLayoutRequest;

        // Act
        $validator = Validator::make($data, $request->rules());

        // Assert
        $this->assertFalse($validator->fails());
    }

    /**
     * UpdateLayoutRequest - 빈 데이터도 허용 (부분 업데이트)
     */
    public function test_update_layout_request_allows_empty_data(): void
    {
        // Arrange - 아무것도 업데이트하지 않음 (valid)
        $data = [];
        $request = new UpdateLayoutRequest;

        // Act
        $validator = Validator::make($data, $request->rules());

        // Assert - sometimes 규칙이므로 빈 데이터도 통과
        $this->assertFalse($validator->fails());
    }

    /**
     * GetLayoutRequest - 항상 통과 (권한만 체크)
     */
    public function test_get_layout_request_always_passes(): void
    {
        // Arrange
        $data = [];
        $request = new GetLayoutRequest;

        // Act
        $validator = Validator::make($data, $request->rules());

        // Assert - 검증 규칙이 없으므로 항상 통과
        $this->assertFalse($validator->fails());
    }

    /**
     * GetLayoutRequest - authorize 메서드 테스트
     */
    public function test_get_layout_request_authorize_returns_true(): void
    {
        // Arrange
        $request = new GetLayoutRequest;

        // Act & Assert
        $this->assertTrue($request->authorize());
    }

    /**
     * StoreLayoutRequest - 다국어 메시지 확인
     */
    public function test_store_layout_request_returns_localized_messages(): void
    {
        // Arrange
        $data = [];
        $request = new StoreLayoutRequest;

        // Act
        $validator = Validator::make($data, $request->rules(), $request->messages());

        // Assert
        $this->assertTrue($validator->fails());

        $errors = $validator->errors();
        $this->assertStringContainsString('템플릿 ID', $errors->first('template_id'));
        $this->assertStringContainsString('레이아웃 이름', $errors->first('name'));
        $this->assertStringContainsString('레이아웃 내용', $errors->first('content'));
    }
}
