<?php

namespace Tests\Unit\Http\Requests;

use App\Http\Requests\Plugin\InstallPluginFromFileRequest;
use App\Http\Requests\Plugin\InstallPluginFromGithubRequest;
use App\Http\Requests\Plugin\PreviewPluginManifestRequest;
use Tests\TestCase;

/**
 * 플러그인 설치 요청의 검증 안내 문구가 실제 문장으로 해석되는지 검증.
 *
 * `lang/{ko,en}/plugins.php` 에 `validation` 블록이 두 번 선언돼 있어, PHP 가 뒤 선언으로
 * 앞 블록을 덮으면서 GitHub/ZIP 설치 관련 7키가 런타임에 존재하지 않던 회귀가 있었다.
 * `__()` 는 미정의 키에 대해 **키 문자열을 그대로 반환**하므로, 설치 검증 실패 화면에
 * `plugins.validation.file_must_be_zip` 같은 원문 키가 사용자에게 노출됐다.
 *
 * 정적 검출은 audit 룰 `lang-duplicate-array-key` 가 담당하고, 이 테스트는 소비 지점에서
 * 실제 해석 결과를 확인해 같은 회귀를 이중으로 차단한다.
 */
class PluginInstallRequestMessagesTest extends TestCase
{
    /**
     * 메시지 배열의 모든 값이 원문 키가 아닌 해석된 문장이어야 한다.
     *
     * @param  array<string, string>  $messages  FormRequest 가 반환한 메시지 배열
     */
    private function assertAllMessagesResolved(array $messages, string $context): void
    {
        $this->assertNotEmpty($messages, "{$context}: 메시지 배열이 비어 있습니다");

        foreach ($messages as $rule => $message) {
            $this->assertStringNotContainsString(
                'plugins.validation.',
                $message,
                "{$context}: '{$rule}' 안내가 해석되지 않은 원문 키입니다 — {$message}"
            );
            $this->assertNotSame('', trim($message), "{$context}: '{$rule}' 안내가 비어 있습니다");
        }
    }

    /**
     * GitHub 주소 설치 요청의 안내 문구가 모두 해석된다.
     */
    public function test_github_install_messages_are_resolved(): void
    {
        $this->assertAllMessagesResolved(
            (new InstallPluginFromGithubRequest)->messages(),
            'InstallPluginFromGithubRequest'
        );
    }

    /**
     * ZIP 파일 업로드 설치 요청의 안내 문구가 모두 해석된다.
     */
    public function test_file_install_messages_are_resolved(): void
    {
        $this->assertAllMessagesResolved(
            (new InstallPluginFromFileRequest)->messages(),
            'InstallPluginFromFileRequest'
        );
    }

    /**
     * manifest 미리보기 요청의 안내 문구가 모두 해석된다.
     */
    public function test_manifest_preview_messages_are_resolved(): void
    {
        $this->assertAllMessagesResolved(
            (new PreviewPluginManifestRequest)->messages(),
            'PreviewPluginManifestRequest'
        );
    }

    /**
     * ko/en 양쪽에 7키가 모두 정의되어 있어야 한다 (한쪽만 있으면 로케일에 따라 회귀).
     *
     * @dataProvider localeProvider
     */
    public function test_install_validation_keys_defined_in_all_base_locales(string $locale): void
    {
        $keys = [
            'github_url_required',
            'github_url_invalid',
            'github_url_format',
            'file_required',
            'file_invalid',
            'file_must_be_zip',
            'file_max_size',
        ];

        foreach ($keys as $key) {
            $full = "plugins.validation.{$key}";
            $this->assertNotSame(
                $full,
                __($full, [], $locale),
                "[{$locale}] '{$full}' 가 정의되지 않아 원문 키가 그대로 반환됩니다"
            );
        }
    }

    /**
     * 기준 로케일 목록.
     *
     * @return array<string, array{string}> 로케일 데이터셋
     */
    public static function localeProvider(): array
    {
        return [
            'ko' => ['ko'],
            'en' => ['en'],
        ];
    }
}
