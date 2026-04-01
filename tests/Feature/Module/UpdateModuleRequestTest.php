<?php

namespace Tests\Feature\Module;

use App\Enums\ExtensionStatus;
use App\Http\Requests\Module\UpdateModuleRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * UpdateModuleRequest 검증 테스트
 *
 * TranslatableField 규칙과 역호환성 처리를 테스트합니다.
 */
class UpdateModuleRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // 한국어 로케일 설정
        App::setLocale('ko');
    }

    /**
     * 다국어 배열로 업데이트 요청 시 성공
     */
    public function test_passes_with_translatable_array(): void
    {
        // Arrange
        $data = [
            'name' => [
                'ko' => '테스트 모듈',
                'en' => 'Test Module',
            ],
            'description' => [
                'ko' => '테스트 모듈 설명',
                'en' => 'Test module description',
            ],
            'status' => ExtensionStatus::Active->value,
        ];
        $request = new UpdateModuleRequest;

        // Act
        $validator = Validator::make($data, $request->rules());

        // Assert
        $this->assertFalse($validator->fails());
    }

    /**
     * 문자열로 업데이트 요청 시 배열로 변환되어 통과
     */
    public function test_converts_string_to_array(): void
    {
        // Arrange
        $data = [
            'name' => '테스트 모듈',
            'description' => '테스트 설명',
        ];

        // UpdateModuleRequest 인스턴스 생성 및 데이터 주입
        $request = UpdateModuleRequest::create('/test', 'POST', $data);
        $request->setContainer(app());
        $request->validateResolved();

        // Act - prepareForValidation이 실행된 후 데이터 확인
        $validatedData = $request->validated();

        // Assert
        $this->assertIsArray($validatedData['name']);
        $this->assertIsArray($validatedData['description']);
        $this->assertEquals('테스트 모듈', $validatedData['name']['ko']);
        $this->assertEquals('테스트 모듈', $validatedData['name']['en']);
    }

    /**
     * name이 maxLength를 초과하면 실패
     */
    public function test_fails_when_name_exceeds_max_length(): void
    {
        // Arrange
        $longString = str_repeat('가', 256); // 255자 초과
        $data = [
            'name' => [
                'ko' => $longString,
                'en' => 'Test',
            ],
        ];
        $request = new UpdateModuleRequest;

        // Act
        $validator = Validator::make($data, $request->rules());

        // Assert
        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('name', $validator->errors()->toArray());
    }

    /**
     * description이 maxLength를 초과하면 실패
     */
    public function test_fails_when_description_exceeds_max_length(): void
    {
        // Arrange
        $longString = str_repeat('가', 1001); // 1000자 초과
        $data = [
            'description' => [
                'ko' => $longString,
                'en' => 'Test',
            ],
        ];
        $request = new UpdateModuleRequest;

        // Act
        $validator = Validator::make($data, $request->rules());

        // Assert
        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('description', $validator->errors()->toArray());
    }

    /**
     * status가 허용되지 않은 값이면 실패
     */
    public function test_fails_with_invalid_status(): void
    {
        // Arrange
        $data = [
            'status' => 'invalid_status',
        ];
        $request = new UpdateModuleRequest;

        // Act
        $validator = Validator::make($data, $request->rules());

        // Assert
        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('status', $validator->errors()->toArray());
    }

    /**
     * status가 ExtensionStatus enum 값이면 성공
     */
    public function test_passes_with_valid_status_values(): void
    {
        // Arrange
        $request = new UpdateModuleRequest;

        // Act & Assert - 모든 유효한 status 값 테스트
        foreach (ExtensionStatus::values() as $statusValue) {
            $data = ['status' => $statusValue];
            $validator = Validator::make($data, $request->rules());
            $this->assertFalse($validator->fails(), "Failed with status: {$statusValue}");
        }
    }

    /**
     * 모든 필드가 nullable이므로 빈 데이터도 성공
     */
    public function test_passes_with_empty_data(): void
    {
        // Arrange
        $data = [];
        $request = new UpdateModuleRequest;

        // Act
        $validator = Validator::make($data, $request->rules());

        // Assert
        $this->assertFalse($validator->fails());
    }

    /**
     * name만 포함된 업데이트 요청 성공
     */
    public function test_passes_with_only_name(): void
    {
        // Arrange
        $data = [
            'name' => [
                'ko' => '새 이름',
                'en' => 'New Name',
            ],
        ];
        $request = new UpdateModuleRequest;

        // Act
        $validator = Validator::make($data, $request->rules());

        // Assert
        $this->assertFalse($validator->fails());
    }

    /**
     * description만 포함된 업데이트 요청 성공
     */
    public function test_passes_with_only_description(): void
    {
        // Arrange
        $data = [
            'description' => [
                'ko' => '새 설명',
                'en' => 'New Description',
            ],
        ];
        $request = new UpdateModuleRequest;

        // Act
        $validator = Validator::make($data, $request->rules());

        // Assert
        $this->assertFalse($validator->fails());
    }

    /**
     * status만 포함된 업데이트 요청 성공
     */
    public function test_passes_with_only_status(): void
    {
        // Arrange
        $data = [
            'status' => ExtensionStatus::Inactive->value,
        ];
        $request = new UpdateModuleRequest;

        // Act
        $validator = Validator::make($data, $request->rules());

        // Assert
        $this->assertFalse($validator->fails());
    }
}
