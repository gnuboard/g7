<?php

namespace Tests\Unit\Extension\Helpers;

use App\Extension\Helpers\GithubHelper;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * GitHub 설치는 태그 릴리스만 받는다 — main·master 브랜치 폴백 제거 (#916 ④, D18)
 *
 * 릴리스가 없으면 `github_release_not_found` 로 실패하고 브랜치 아카이브(`main.zip`·`master.zip`) 요청이 0 이다. 릴리스
 * 응답의 자산 목록과 서명 자산 짝 찾기, 태그로 릴리스 찾기를 함께 잠근다.
 */
class GithubHelperReleaseOnlyTest extends TestCase
{
    /**
     * 릴리스가 없으면 예외이고 브랜치 아카이브를 요청하지 않는다.
     *
     * @scenario case=github_release_only
     *
     * @effects github_install_without_release_fails_without_branch_fallback
     *
     * @return void 반환값 없음
     */
    public function test_no_release_throws_without_branch_fallback(): void
    {
        Http::fake([
            'api.github.com/repos/acme/no-release/releases/latest' => Http::response(['message' => 'Not Found'], 404),
            '*' => Http::response('should-not-be-called', 200),
        ]);

        $dir = storage_path('framework/testing/gh-release-only-'.uniqid());
        File::ensureDirectoryExists($dir);

        try {
            GithubHelper::downloadZip('acme', 'no-release', $dir);
            $this->fail('릴리스가 없는데 다운로드가 성공했다');
        } catch (\RuntimeException $e) {
            $this->assertSame(__('common.errors.github_release_not_found', ['repository' => 'acme/no-release']), $e->getMessage());
        } finally {
            File::deleteDirectory($dir);
        }

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/archive/refs/heads/'));
        Http::assertSentCount(1);
    }

    /**
     * 릴리스 응답의 자산 목록이 실리고, 서명 자산 짝을 이름으로 찾는다(우선 이름 → 아무 짝).
     *
     * @scenario case=github_release_assets
     *
     * @effects release_assets_listed_and_signed_pair_found
     *
     * @return void 반환값 없음
     */
    public function test_release_assets_and_signed_pair(): void
    {
        $assets = [
            ['name' => 'notes.txt', 'size' => 3, 'url' => 'https://api.github.com/a/1', 'browser_download_url' => 'https://github.com/d/1'],
            ['name' => 'acme-board-1.2.0.zip', 'size' => 100, 'url' => 'https://api.github.com/a/2', 'browser_download_url' => 'https://github.com/d/2'],
            ['name' => 'acme-board-1.2.0.zip.sig.json', 'size' => 500, 'url' => 'https://api.github.com/a/3', 'browser_download_url' => 'https://github.com/d/3'],
            ['name' => 'unsigned-extra.zip', 'size' => 10, 'url' => 'https://api.github.com/a/4', 'browser_download_url' => 'https://github.com/d/4'],
        ];
        Http::fake([
            'api.github.com/repos/acme/board/releases/latest' => Http::response(['tag_name' => 'v1.2.0', 'zipball_url' => 'https://api.github.com/repos/acme/board/zipball/v1.2.0', 'assets' => $assets], 200),
            'api.github.com/repos/acme/board/releases/tags/v1.2.0' => Http::response(['tag_name' => 'v1.2.0', 'assets' => $assets], 200),
        ]);

        $latest = GithubHelper::fetchLatestRelease('acme', 'board');
        $this->assertSame('1.2.0', $latest['version']);
        $this->assertCount(4, $latest['assets']);

        $pair = GithubHelper::findSignedAssetPair($latest['assets'], 'acme-board-1.2.0');
        $this->assertSame('acme-board-1.2.0.zip', $pair['zip']['name']);
        $this->assertSame('acme-board-1.2.0.zip.sig.json', $pair['signature']['name']);

        $this->assertSame('acme-board-1.2.0.zip', GithubHelper::findSignedAssetPair($latest['assets'])['zip']['name']);
        $this->assertNull(GithubHelper::findSignedAssetPair([$assets[0], $assets[3]]));

        $byTag = GithubHelper::fetchReleaseByTag('acme', 'board', '1.2.0');
        $this->assertSame('v1.2.0', $byTag['tag']);
    }

    /**
     * 비공개 저장소 자산은 API 주소에 octet-stream 과 토큰을 실어 받는다.
     *
     * @scenario case=github_private_asset_download
     *
     * @effects private_release_asset_downloaded_with_octet_stream_and_token
     *
     * @return void 반환값 없음
     */
    public function test_asset_download_uses_octet_stream_and_token(): void
    {
        Http::fake(['api.github.com/repos/acme/board/releases/assets/9' => Http::response('ZIPBYTES', 200)]);
        $path = storage_path('framework/testing/gh-asset-'.uniqid().'.zip');

        try {
            GithubHelper::downloadAsset(['name' => 'x.zip', 'url' => 'https://api.github.com/repos/acme/board/releases/assets/9'], $path, 'secret-token');
            $this->assertSame('ZIPBYTES', File::get($path));
        } finally {
            @unlink($path);
        }

        Http::assertSent(fn (Request $request) => $request->hasHeader('Accept', 'application/octet-stream')
            && $request->hasHeader('Authorization', 'Bearer secret-token'));
    }
}
