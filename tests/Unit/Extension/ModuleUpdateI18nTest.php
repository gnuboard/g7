<?php

namespace Tests\Unit\Extension;

use Illuminate\Support\Facades\App;
use Tests\TestCase;

/**
 * 모듈 업데이트 다국어 키 테스트
 *
 * Phase 7-1: 백엔드 모듈 다국어 키가 ko/en 모두 존재하는지 검증합니다.
 */
class ModuleUpdateI18nTest extends TestCase
{
    /**
     * 업데이트 관련 필수 다국어 키 목록
     */
    public static function updateTranslationKeysProvider(): array
    {
        return [
            // 상태
            ['modules.status.updating'],

            // 업데이트 관련
            ['modules.update_success'],
            ['modules.update_failed'],
            ['modules.update_hook_failed'],
            ['modules.no_update_available'],
            ['modules.check_updates_success'],
            ['modules.check_updates_failed'],
            ['modules.not_installed'],

            // _pending 관련
            ['modules.pending_not_found'],
            ['modules.already_exists'],
            ['modules.move_failed'],

            // errors 하위
            ['modules.errors.operation_in_progress'],
            ['modules.errors.github_api_failed'],
            ['modules.errors.invalid_github_url'],
            ['modules.errors.zip_url_not_found'],
            ['modules.errors.download_failed'],
            ['modules.errors.zip_extract_failed'],
            ['modules.errors.extracted_dir_not_found'],
            ['modules.errors.reload_failed'],
            ['modules.errors.delete_directory_failed'],
        ];
    }

    /**
     * @dataProvider updateTranslationKeysProvider
     */
    public function test_ko_translation_key_exists(string $key): void
    {
        App::setLocale('ko');

        $translated = __($key);

        // 번역되지 않은 키는 키 자체가 반환됨
        $this->assertNotEquals(
            $key,
            $translated,
            "한국어 번역 키 '{$key}'가 존재하지 않습니다."
        );
    }

    /**
     * @dataProvider updateTranslationKeysProvider
     */
    public function test_en_translation_key_exists(string $key): void
    {
        App::setLocale('en');

        $translated = __($key);

        $this->assertNotEquals(
            $key,
            $translated,
            "영어 번역 키 '{$key}'가 존재하지 않습니다."
        );
    }

    /**
     * ko/en 번역이 동일하지 않은지 확인 (실제로 번역된 것인지 검증)
     *
     * @dataProvider updateTranslationKeysProvider
     */
    public function test_ko_and_en_translations_differ(string $key): void
    {
        App::setLocale('ko');
        $ko = __($key);

        App::setLocale('en');
        $en = __($key);

        // 둘 다 키 자체가 아닌 실제 번역이어야 함
        $this->assertNotEquals($key, $ko, "한국어 번역 누락: {$key}");
        $this->assertNotEquals($key, $en, "영어 번역 누락: {$key}");

        // ko와 en이 다른지 확인 (같으면 번역이 안 된 것)
        $this->assertNotEquals(
            $ko,
            $en,
            "한국어와 영어 번역이 동일합니다 (번역 누락 가능성): {$key}"
        );
    }

    /**
     * 플레이스홀더가 있는 키의 치환이 정상 동작하는지 확인
     */
    public function test_placeholder_substitution_works(): void
    {
        App::setLocale('ko');

        // :module 플레이스홀더
        $this->assertStringContainsString(
            'test-module',
            __('modules.not_installed', ['module' => 'test-module'])
        );

        // :name, :status 플레이스홀더
        $translated = __('modules.errors.operation_in_progress', [
            'name' => 'test-mod',
            'status' => '업데이트 중',
        ]);
        $this->assertStringContainsString('test-mod', $translated);
        $this->assertStringContainsString('업데이트 중', $translated);

        // :error 플레이스홀더
        $this->assertStringContainsString(
            '네트워크 오류',
            __('modules.update_failed', ['error' => '네트워크 오류'])
        );
    }

    /**
     * 영어 플레이스홀더 치환 확인
     */
    public function test_en_placeholder_substitution_works(): void
    {
        App::setLocale('en');

        $this->assertStringContainsString(
            'test-module',
            __('modules.not_installed', ['module' => 'test-module'])
        );

        $translated = __('modules.errors.operation_in_progress', [
            'name' => 'test-mod',
            'status' => 'updating',
        ]);
        $this->assertStringContainsString('test-mod', $translated);
        $this->assertStringContainsString('updating', $translated);
    }
}
