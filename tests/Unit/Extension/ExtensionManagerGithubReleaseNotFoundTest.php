<?php

namespace Tests\Unit\Extension;

use App\Extension\ExtensionManager;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 확장 GitHub 내려받기 — 그 버전의 배포본(태그)이 없을 때의 안내 문구 회귀 테스트 (7.1.1 출시 전).
 *
 * 태그가 없으면 모든 전략이 아카이브 주소를 못 얻고 한 번도 내려받지 않는다. 그런데 마지막에
 * 「모든 아카이브 추출 방법이 실패했습니다」로 끝나 원인(배포본 없음)을 추출 도구 문제로 잘못 짚게 했다.
 */
class ExtensionManagerGithubReleaseNotFoundTest extends TestCase
{
    private string $dest;

    /**
     * 임시 내려받기 폴더를 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('ko');
        $this->dest = storage_path('framework/testing/ext-release-missing-'.uniqid());
        File::ensureDirectoryExists($this->dest);
    }

    /**
     * 임시 폴더를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        File::deleteDirectory($this->dest);

        parent::tearDown();
    }

    /**
     * 태그가 없으면 「추출 실패」가 아니라 그 저장소·버전의 배포본을 찾지 못했다고 알리고, 아카이브를 내려받지 않는지 확인합니다.
     *
     * @scenario github_response=tag_not_found
     *
     * @effects release_not_found_message_names_repository_and_version, extract_failure_message_not_used, no_archive_download_attempted
     *
     * @return void 반환값 없음
     */
    public function test_missing_tag_is_reported_as_release_not_found(): void
    {
        Http::fake(['api.github.com/*' => Http::response(['message' => 'Not Found'], 404)]);

        try {
            app(ExtensionManager::class)->downloadAndExtractFromGitHub('acme', 'acme-board', '1.2.3', $this->dest);
            $this->fail('배포본이 없는데 예외가 나지 않았습니다');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('acme/acme-board', $e->getMessage());
            $this->assertStringContainsString('1.2.3 배포본을 찾지 못했습니다', $e->getMessage());
            $this->assertStringNotContainsString('추출 방법이 실패', $e->getMessage());
        }

        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/zipball/') || str_contains($request->url(), '/tarball/'));
    }
}
