<?php

namespace Tests\Feature\Settings;

use App\Contracts\Repositories\ConfigRepositoryInterface;
use App\Http\Requests\Settings\SaveSettingsRequest;
use App\Providers\SettingsServiceProvider;
use App\Repositories\JsonConfigRepository;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/**
 * 업로드 허용 확장자 저장 계약 테스트 (#493 C2)
 *
 * 허용 확장자 칸은 콤마로 구분한 한 줄 텍스트다. 즉 관리자가 업로드 탭을 저장하면 이 값은
 * 문자열로 들어온다. 그런데 설정을 실제 업로드 제한으로 옮기는 지점은 배열만 받아들여,
 * 문자열이 오면 아무 말 없이 버렸다 — 관리자가 확장자를 바꿔도 업로드는 종전 목록으로
 * 계속 동작한다. 저장도 성공하고 화면에도 값이 보이므로 어긋난 것을 알 방법이 없다.
 *
 * 저장 단계에서 배열로 정규화하고, 이미 문자열로 저장된 기존 설치도 반영되도록
 * 적용 단계에서 문자열을 수용하는지 양쪽에서 확인한다.
 */
class UploadAllowedExtensionsContractTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 업로드 탭 저장 요청을 검증까지 통과시키고 검증 결과를 반환합니다.
     *
     * @param  mixed  $allowedExtensions  허용 확장자 입력값
     * @return array 검증을 통과한 입력 데이터
     */
    private function validatedUploadPayload(mixed $allowedExtensions): array
    {
        $request = SaveSettingsRequest::create('/api/admin/settings', 'POST', [
            '_tab' => 'upload',
            'upload' => [
                'max_file_size' => 20,
                'allowed_extensions' => $allowedExtensions,
            ],
        ]);

        $request->setContainer($this->app)->validateResolved();

        return $request->validated();
    }

    /**
     * applyUploadConfig 를 리플렉션으로 호출합니다.
     *
     * @param  array  $uploadSettings  upload 카테고리 설정 데이터
     */
    private function applyUploadConfig(array $uploadSettings): void
    {
        $configRepository = $this->createMock(JsonConfigRepository::class);
        $configRepository->method('getCategory')
            ->with('upload')
            ->willReturn($uploadSettings);

        $provider = new SettingsServiceProvider($this->app);
        $method = new ReflectionMethod($provider, 'applyUploadConfig');
        $method->invoke($provider, $configRepository);
    }

    /**
     * 화면이 보내는 콤마 문자열이 저장 단계에서 배열로 정규화되는지 확인합니다.
     */
    #[Test]
    public function comma_string_is_normalized_to_an_array_before_saving(): void
    {
        $validated = $this->validatedUploadPayload('JPG, .png ,pdf,,');

        $this->assertSame(
            ['jpg', 'png', 'pdf'],
            $validated['upload']['allowed_extensions'],
            '허용 확장자 문자열이 배열로 정규화되지 않았습니다 — 저장돼도 업로드 제한에 반영되지 않습니다.'
        );
    }

    /**
     * 배열로 보내는 경로(API 직접 호출 등)도 같은 형태로 정규화되는지 확인합니다.
     */
    #[Test]
    public function array_input_is_normalized_the_same_way(): void
    {
        $validated = $this->validatedUploadPayload(['JPG', '.PNG', ' pdf ']);

        $this->assertSame(['jpg', 'png', 'pdf'], $validated['upload']['allowed_extensions']);
    }

    /**
     * 저장한 확장자 목록이 실제 업로드 제한으로 이어지는지 확인합니다 (저장 → 적용 전 구간).
     */
    #[Test]
    public function saved_extensions_reach_the_upload_restriction(): void
    {
        config(['attachment.allowed_extensions' => ['gif']]);

        $validated = $this->validatedUploadPayload('jpg,png');
        app(SettingsService::class)->saveSettings($validated);

        $stored = app(ConfigRepositoryInterface::class)->getCategory('upload');

        $this->assertSame(['jpg', 'png'], $stored['allowed_extensions']);

        $this->applyUploadConfig($stored);

        $this->assertSame(['jpg', 'png'], config('attachment.allowed_extensions'));
    }

    /**
     * 이미 문자열로 저장된 기존 설치도 업로드 제한에 반영되는지 확인합니다.
     */
    #[Test]
    public function legacy_string_value_already_on_disk_is_still_applied(): void
    {
        config(['attachment.allowed_extensions' => ['gif']]);

        $this->applyUploadConfig(['allowed_extensions' => 'JPG, .png , pdf']);

        $this->assertSame(
            ['jpg', 'png', 'pdf'],
            config('attachment.allowed_extensions'),
            '문자열로 저장된 기존 설정이 업로드 제한에 반영되지 않았습니다.'
        );
    }
}
