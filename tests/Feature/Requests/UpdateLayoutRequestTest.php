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
        // 낙관적 잠금 인프라 도입으로 expected_lock_version 이 required 가 됨
        $data = [
            'expected_lock_version' => 0,
            'content' => $this->getValidContentData(),
        ];
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
     * content.endpoint 누락 시에도 통과 (standalone endpoint-less 레이아웃)
     *
     * 최상위 content.endpoint 는 데이터를 주로 fetch 하는 화면에서만 쓰는 레거시 필드로,
     * 로그인/대시보드/정적 페이지처럼 주 데이터 fetch 가 없는 standalone 레이아웃은
     * 정당하게 endpoint 가 없다(번들 admin 103 + basic 39 = 전 142 레이아웃이 endpoint 부재
     * 상태로 정상 렌더). 구조 SSoT(ValidLayoutStructure) 도 endpoint 를 필수로 요구하지 않는다.
     * 따라서 endpoint 미존재는 저장을 막아서는 안 된다.
     */
    public function test_passes_without_endpoint_for_standalone_layout(): void
    {
        // Arrange — getValidContentData 는 endpoint 를 포함하므로 제거하여 endpoint-less 재현
        $content = $this->getValidContentData();
        unset($content['endpoint']);
        $data = [
            'expected_lock_version' => 0,
            'content' => $content,
        ];
        $request = new UpdateLayoutContentRequest;

        // Act
        $validator = Validator::make($data, $request->rules());

        // Assert
        if ($validator->fails()) {
            dump($validator->errors()->toArray());
        }
        $this->assertFalse($validator->fails());
        $this->assertArrayNotHasKey('content.endpoint', $validator->errors()->toArray());
    }

    /**
     * 실 admin_login 구조(endpoint·extends·slots 모두 없음 + meta/init_actions)로 저장 통과
     *
     * 번들 로그인 레이아웃과 동일한 shape 으로, 편집기에서 컴포넌트만 추가해도
     * 저장이 막히지 않아야 한다.
     */
    public function test_passes_with_real_login_layout_shape(): void
    {
        // Arrange — admin_login 과 동형: endpoint/extends/slots 없음
        $content = [
            'version' => '1.0.0',
            'layout_name' => 'admin_login',
            'meta' => [
                'title' => '$t:auth.login.title',
                'description' => '관리자 로그인 페이지',
                'auth_required' => false,
            ],
            'data_sources' => [],
            'init_actions' => [],
            'components' => [
                [
                    'id' => 'logo',
                    'type' => 'basic',
                    'name' => 'H1',
                    'text' => '$t:auth.login.title',
                ],
            ],
        ];
        $data = [
            'expected_lock_version' => 0,
            'content' => $content,
        ];
        $request = new UpdateLayoutContentRequest;

        // Act
        $validator = Validator::make($data, $request->rules());

        // Assert
        if ($validator->fails()) {
            dump($validator->errors()->toArray());
        }
        $this->assertFalse($validator->fails());
    }

    /**
     * endpoint 가 명시되면 여전히 검증된다 (whitelist/외부URL 차단 유지 — 회귀 가드)
     */
    public function test_still_validates_endpoint_when_present(): void
    {
        // Arrange — 외부 URL 은 endpoint 가 없어도 통과시키는 변경과 무관하게 차단되어야 함
        $content = $this->getValidContentData();
        $content['endpoint'] = 'https://external.com/api';
        $data = [
            'expected_lock_version' => 0,
            'content' => $content,
        ];
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

    /**
     * data_sources 의 label_key 와 임의 신규 필드가 validated 에서 보존된다.
     *
     * content.data_sources 는 bare 'array' 규칙(하위 키 미명시)이므로 Laravel validated()
     * 가 배열 전체를 그대로 통과시킨다. 따라서 6-c 편집기가 추가하는 label_key 및
     * endpoint/method/auth_mode/params/auto_fetch/loading_strategy/fallback 등 모든 필드가
     * 백엔드 별도 동기 없이 저장 경로로 보존되어야 한다.
     *
     * 시나리오 매니페스트: tests/scenarios/layout-editor-data-sources.yaml
     *
     * @effects validated_preserves_label_key_and_all_data_source_fields,
     *   all_bundled_layouts_data_sources_have_label_key_zero_missing,
     *   audit_data_source_label_key_coverage_blocks_future_omission
     */
    public function test_validated_preserves_data_source_label_key_and_fields(): void
    {
        // Arrange
        $content = $this->getValidContentData();
        $content['data_sources'] = [
            [
                'id' => 'products',
                'label_key' => '$t:editor.data_source.products',
                'type' => 'api',
                'endpoint' => '/api/products',
                'method' => 'GET',
                'auth_mode' => 'optional',
                'loading_strategy' => 'progressive',
                'auto_fetch' => true,
                'params' => ['page' => '{{query.page ?? 1}}'],
                'fallback' => ['data' => []],
            ],
        ];
        $data = [
            'expected_lock_version' => 0,
            'content' => $content,
        ];
        $request = new UpdateLayoutContentRequest;

        // Act
        $validator = Validator::make($data, $request->rules());
        if ($validator->fails()) {
            dump($validator->errors()->toArray());
        }
        $validated = $validator->validated();

        // Assert — data_sources 항목의 모든 필드가 보존됨
        $this->assertFalse($validator->fails());
        $ds = $validated['content']['data_sources'][0];
        $this->assertSame('products', $ds['id']);
        $this->assertSame('$t:editor.data_source.products', $ds['label_key']);
        $this->assertSame('/api/products', $ds['endpoint']);
        $this->assertSame('optional', $ds['auth_mode']);
        $this->assertSame('progressive', $ds['loading_strategy']);
        $this->assertTrue($ds['auto_fetch']);
        $this->assertSame(['page' => '{{query.page ?? 1}}'], $ds['params']);
        $this->assertSame(['data' => []], $ds['fallback']);
    }

    /**
     * data_sources 에 id 누락 시 ValidDataSourceMerge 가 실패시킨다.
     *
     * @effects missing_id_rejected_by_valid_data_source_merge
     */
    public function test_fails_when_data_source_missing_id(): void
    {
        // Arrange
        $content = $this->getValidContentData();
        $content['data_sources'] = [
            ['label_key' => '$t:editor.data_source.noid', 'type' => 'api'],
        ];
        $data = [
            'expected_lock_version' => 0,
            'content' => $content,
        ];
        $request = new UpdateLayoutContentRequest;

        // Act
        $validator = Validator::make($data, $request->rules());

        // Assert
        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('content.data_sources', $validator->errors()->toArray());
    }
}
