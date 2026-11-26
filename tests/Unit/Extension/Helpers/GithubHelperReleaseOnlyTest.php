<?php

namespace Tests\Unit\Extension\Helpers;

use App\Extension\Helpers\GithubHelper;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * GitHub 설치는 태그 릴리스만 받는다 — main·master 브랜치 폴백 제거
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

        // 「포함」(hasHeader 두 인자)이 아니라 「정확히 하나」를 본다 — Accept 가 vnd.github.v3+json 과 함께 두 값이면
        // GitHub 은 자산 바이트 대신 메타데이터 JSON 을 주는데(7.2.0 결함), hasHeader 는 그 경우에도 참이었다.
        Http::assertSent(fn (Request $request) => $request->header('Accept') === ['application/octet-stream']
            && $request->hasHeader('Authorization', 'Bearer secret-token'));
    }

    /**
     * 릴리스 서명 파일은 `{base}.sig.json` 을 먼저, 옛 이름 `{base}.zip.sig.json` 을 그다음으로 찾는다.
     *
     * 7.2.0 이하 업데이터는 `{base}.zip.sig.json` 짝만 서명 자산으로 보므로, 새 이름으로 올린 릴리스는 7.2.0 에서
     * 미서명 zipball 경로로 진행되고 7.2.1 이상에서만 서명을 검증한다.
     *
     * @scenario case=github_signed_asset_new_name
     *
     * @effects release_signature_new_name_preferred_and_legacy_name_still_read
     *
     * @return void 반환값 없음
     */
    public function test_signed_pair_prefers_base_sig_json_and_reads_legacy_name(): void
    {
        $zip = ['name' => 'g7-7.2.1.zip', 'size' => 100];
        $newSig = ['name' => 'g7-7.2.1.sig.json', 'size' => 555];
        $legacySig = ['name' => 'g7-7.2.1.zip.sig.json', 'size' => 555];

        $this->assertSame('g7-7.2.1.sig.json', GithubHelper::findSignedAssetPair([$zip, $newSig], 'g7-7.2.1')['signature']['name']);
        $this->assertSame('g7-7.2.1.sig.json', GithubHelper::findSignedAssetPair([$zip, $legacySig, $newSig], 'g7-7.2.1')['signature']['name']);
        $this->assertSame('g7-7.2.1.zip.sig.json', GithubHelper::findSignedAssetPair([$zip, $legacySig], 'g7-7.2.1')['signature']['name']);
        $this->assertSame('g7-7.2.1.sig.json', GithubHelper::findSignedAssetPair([$zip, $newSig])['signature']['name']);
        $this->assertNull(GithubHelper::findSignedAssetPair([$zip]));
    }
}
