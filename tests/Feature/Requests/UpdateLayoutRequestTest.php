<?php

namespace Tests\Feature\Requests;

use App\Http\Requests\Layout\UpdateLayoutContentRequest;
use App\Models\Template;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * UpdateLayoutRequest 검증 테스트
 *
 * Custom Rule을 포함한 레이아웃 업데이트 요청 검증을 테스트합니다.
 */
class UpdateLayoutRequestTest extends TestCase
{
    use RefreshDatabase;

    private string $testTemplatePath;

    private string $testTemplateId;

    private Template $template;

    protected function setUp(): void
    {
        parent::setUp();

        // 한국어 로케일 설정
        App::setLocale('ko');

        // 테스트용 템플릿 생성
        $this->testTemplateId = 'test-template';
        $this->testTemplatePath = base_path("templates/{$this->testTemplateId}");

        // 템플릿 디렉토리 생성
        if (! File::exists($this->testTemplatePath)) {
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
        $this->template = Template::create([
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
     * 정상적인 레이아웃 content 데이터
     */
    private function getValidContentData(): array
    {
        return [
            'version' => '1.0.0',
            'layout_name' => 'test_layout',
            'endpoint' => '/api/admin/dashboard',
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
            'data_sources' => [],
            'metadata' => [
                'title' => 'Test Layout',
            ],
        ];
    }

    /**
     * 정상 데이터 검증 통과
     */
    public function test_passes_with_valid_data(): void
    {
        // Arrange
        $data = ['content' => $this->getValidContentData()];
        $request = new UpdateLayoutContentRequest;

        // Act
        $validator = Validator::make($data, $request->rules());

        // Debug
        if ($validator->fails()) {
            dump($validator->errors()->toArray());
        }

        // Assert
        $this->assertFalse($validator->fails());
    }

    /**
     * content 필드 누락 시 실패
     */
    public function test_fails_without_content(): void
    {
        // Arrange
        $data = [];
        $request = new UpdateLayoutContentRequest;

        // Act
        $validator = Validator::make($data, $request->rules());

        // Assert
        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('content', $validator->errors()->toArray());
    }

    /**
     * content.version 누락 시 실패
     */
    public function test_fails_without_version(): void
    {
        // Arrange
        $content = $this->getValidContentData();
        unset($content['version']);
        $data = ['content' => $content];
        $request = new UpdateLayoutContentRequest;

        // Act
        $validator = Validator::make($data, $request->rules());

        // Assert
        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('content.version', $validator->errors()->toArray());
    }

    /**
     * content.layout_name 누락 시 실패
     */
    public function test_fails_without_layout_name(): void
    {
        // Arrange
        $content = $this->getValidContentData();
        unset($content['layout_name']);
        $data = ['content' => $content];
        $request = new UpdateLayoutContentRequest;

        // Act
        $validator = Validator::make($data, $request->rules());

        // Assert
        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('content.layout_name', $validator->errors()->toArray());
    }

    /**
     * content.endpoint 누락 시 실패
     */
    public function test_fails_without_endpoint(): void
    {
        // Arrange
        $content = $this->getValidContentData();
        unset($content['endpoint']);
        $data = ['content' => $content];
        $request = new UpdateLayoutContentRequest;

        // Act
        $validator = Validator::make($data, $request->rules());

        // Assert
        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('content.endpoint', $validator->errors()->toArray());
    }

    /**
     * content.components 누락 시 실패
     */
    public function test_fails_without_components(): void
    {
        // Arrange
        $content = $this->getValidContentData();
        unset($content['components']);
        $data = ['content' => $content];
        $request = new UpdateLayoutContentRequest;

        // Act
        $validator = Validator::make($data, $request->rules());

        // Assert
        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('content.components', $validator->errors()->toArray());
    }

    /**
     * 외부 URL 엔드포인트 차단
     */
    public function test_fails_with_external_url_endpoint(): void
    {
        // Arrange
        $content = $this->getValidContentData();
        $content['endpoint'] = 'https://external.com/api';
        $data = ['content' => $content];
        $request = new UpdateLayoutContentRequest;

        // Act
        $validator = Validator::make($data, $request->rules());

        // Assert
        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('content.endpoint', $validator->errors()->toArray());
    }

    /**
     * 허용되지 않은 엔드포인트 패턴 차단
     */
    public function test_fails_with_non_whitelisted_endpoint(): void
    {
        // Arrange
        $content = $this->getValidContentData();
        $content['endpoint'] = '/unauthorized/endpoint';
        $data = ['content' => $content];
        $request = new UpdateLayoutContentRequest;

        // Act
        $validator = Validator::make($data, $request->rules());

        // Assert
        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('content.endpoint', $validator->errors()->toArray());
    }

    /**
     * ValidLayoutStructure - 컴포넌트 구조 검증
     */
    public function test_validates_component_structure(): void
    {
        // Arrange - 잘못된 컴포넌트 구조
        $content = $this->getValidContentData();
        $content['components'] = [
            [
                // 'component' 필드 누락
                'type' => 'basic',
                'props' => [],
            ],
        ];
        $data = ['content' => $content];
        $request = new UpdateLayoutContentRequest;

        // Act
        $validator = Validator::make($data, $request->rules());

        // Assert
        $this->assertTrue($validator->fails());
    }

    /**
     * 다국어 메시지 확인
     */
    public function test_returns_localized_messages(): void
    {
        // Arrange
        $data = [];
        $request = new UpdateLayoutContentRequest;

        // Act
        $validator = Validator::make($data, $request->rules(), $request->messages());

        // Assert
        $this->assertTrue($validator->fails());

        $errors = $validator->errors();
        $this->assertStringContainsString('레이아웃 content', $errors->first('content'));
    }
}
