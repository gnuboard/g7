<?php

namespace Tests\Unit\Providers;

use App\Providers\SettingsServiceProvider;
use App\Repositories\JsonConfigRepository;
use ReflectionMethod;
use Tests\TestCase;

/**
 * SettingsServiceProvider 업로드 설정 테스트 (C2)
 *
 * 관리자 환경설정 `upload.*`(MB) 를 `config/attachment.*`(KB) 로 옮기는 단일 변환 지점이
 * 실제로 동작하는지 검증합니다.
 *
 * 수정 전에는 존재하지 않는 키(`max_size`)를 읽어 어떤 설정도 반영되지 않았고,
 * 소비자가 없는 `g7.upload.max_size` 로 써 두 번째 SSoT 를 만들고 있었습니다.
 */
class SettingsUploadConfigTest extends TestCase
{
    /**
     * applyUploadConfig 를 리플렉션으로 호출합니다.
     *
     * @param  array  $uploadSettings  upload 카테고리 설정 데이터
     */
    private function callApplyUploadConfig(array $uploadSettings): void
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
     * 관리자 설정 MB 가 KB 로 변환되어 attachment config 에 반영되는지 테스트합니다.
     */
    public function test_max_file_size_mb_is_converted_to_kb(): void
    {
        $this->callApplyUploadConfig(['max_file_size' => 20]);

        $this->assertSame(20480, config('attachment.max_file_size'));
    }

    /**
     * 소비자가 없는 dead key 를 더 이상 쓰지 않는지 테스트합니다.
     */
    public function test_dead_g7_upload_key_is_not_written(): void
    {
        $this->callApplyUploadConfig(['max_file_size' => 20]);

        $this->assertNull(config('g7.upload.max_size'));
    }

    /**
     * 허용 확장자가 소문자로 정규화되어 반영되는지 테스트합니다.
     */
    public function test_allowed_extensions_are_normalized(): void
    {
        $this->callApplyUploadConfig(['allowed_extensions' => ['JPG', '.PNG', 'pdf']]);

        $this->assertSame(['jpg', 'png', 'pdf'], config('attachment.allowed_extensions'));
    }

    /**
     * 빈 배열(제한 없음)도 그대로 반영되는지 테스트합니다.
     */
    public function test_empty_allowed_extensions_is_applied_as_no_restriction(): void
    {
        config(['attachment.allowed_extensions' => ['png']]);

        $this->callApplyUploadConfig(['allowed_extensions' => []]);

        $this->assertSame([], config('attachment.allowed_extensions'));
    }

    /**
     * 설정이 비어 있으면 기본값을 건드리지 않는지 테스트합니다.
     */
    public function test_empty_settings_keep_defaults(): void
    {
        config(['attachment.max_file_size' => 10240]);

        $this->callApplyUploadConfig([]);

        $this->assertSame(10240, config('attachment.max_file_size'));
    }
}
