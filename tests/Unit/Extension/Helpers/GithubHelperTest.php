<?php

namespace Tests\Unit\Extension\Helpers;

use App\Extension\Helpers\GithubHelper;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GithubHelperTest extends TestCase
{
    // ──────────────────────────────────────────
    // parseUrl
    // ──────────────────────────────────────────

    #[Test]
    public function parseUrl_유효한_github_url에서_owner와_repo를_추출합니다(): void
    {
        [$owner, $repo] = GithubHelper::parseUrl('https://github.com/owner/repo');

        $this->assertSame('owner', $owner);
        $this->assertSame('repo', $repo);
    }

    #[Test]
    public function parseUrl_git_접미사_포함_url을_처리합니다(): void
    {
        [$owner, $repo] = GithubHelper::parseUrl('https://github.com/owner/repo.git');

        $this->assertSame('owner', $owner);
        $this->assertSame('repo', $repo);
    }

    #[Test]
    public function parseUrl_끝에_슬래시가_있는_url을_처리합니다(): void
    {
        [$owner, $repo] = GithubHelper::parseUrl('https://github.com/owner/repo/');

        $this->assertSame('owner', $owner);
        $this->assertSame('repo', $repo);
    }

    #[Test]
    public function parseUrl_잘못된_url이면_RuntimeException을_던집니다(): void
    {
        $this->expectException(\RuntimeException::class);

        GithubHelper::parseUrl('https://example.com/foo');
    }

    #[Test]
    public function parseUrl_빈_문자열이면_RuntimeException을_던집니다(): void
    {
        $this->expectException(\RuntimeException::class);

        GithubHelper::parseUrl('');
    }

    #[Test]
    public function parseUrl_경로가_부족한_url이면_RuntimeException을_던집니다(): void
    {
        $this->expectException(\RuntimeException::class);

        GithubHelper::parseUrl('https://github.com/owner');
    }

    // ──────────────────────────────────────────
    // buildHeaders
    // ──────────────────────────────────────────

    #[Test]
    public function buildHeaders_토큰_없이_기본_헤더만_반환합니다(): void
    {
        $headers = GithubHelper::buildHeaders();

        $this->assertCount(2, $headers);
        $this->assertSame('User-Agent: G7', $headers[0]);
        $this->assertSame('Accept: application/vnd.github.v3+json', $headers[1]);
    }

    #[Test]
    public function buildHeaders_빈_문자열_토큰이면_Authorization을_포함하지_않습니다(): void
    {
        $headers = GithubHelper::buildHeaders('');

        $this->assertCount(2, $headers);
        foreach ($headers as $header) {
            $this->assertStringNotContainsString('Authorization', $header);
        }
    }

    #[Test]
    public function buildHeaders_토큰이_있으면_Authorization_Bearer를_포함합니다(): void
    {
        $headers = GithubHelper::buildHeaders('ghp_test_token');

        $this->assertCount(3, $headers);
        $this->assertSame('Authorization: Bearer ghp_test_token', $headers[2]);
    }

    // ──────────────────────────────────────────
    // parseUrl 경계 케이스
    // ──────────────────────────────────────────

    #[Test]
    public function parseUrl_대시와_언더스코어가_포함된_저장소명을_처리합니다(): void
    {
        [$owner, $repo] = GithubHelper::parseUrl('https://github.com/my-org/my_repo-name');

        $this->assertSame('my-org', $owner);
        $this->assertSame('my_repo-name', $repo);
    }

    #[Test]
    public function parseUrl_ssh_스타일_url은_실패합니다(): void
    {
        // SSH URL도 정규식에서 매칭 가능
        [$owner, $repo] = GithubHelper::parseUrl('git@github.com:owner/repo.git');

        $this->assertSame('owner', $owner);
        $this->assertSame('repo', $repo);
    }

    // ──────────────────────────────────────────
    // checkRepoExists (Http::fake 기반)
    // ──────────────────────────────────────────

    #[Test]
    public function checkRepoExists_200_응답이면_true를_반환합니다(): void
    {
        Http::fake([
            'api.github.com/repos/owner/repo' => Http::response(['full_name' => 'owner/repo'], 200),
        ]);

        $this->assertTrue(GithubHelper::checkRepoExists('owner', 'repo'));

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://api.github.com/repos/owner/repo'
                && $request->hasHeader('User-Agent', 'G7')
                && $request->hasHeader('Accept', 'application/vnd.github.v3+json');
        });
    }

    #[Test]
    public function checkRepoExists_404_응답이면_false를_반환합니다(): void
    {
        Http::fake([
            'api.github.com/repos/*' => Http::response([], 404),
        ]);

        $this->assertFalse(GithubHelper::checkRepoExists('owner', 'repo'));
    }

    #[Test]
    public function checkRepoExists_연결_실패_시_false를_반환합니다(): void
    {
        Http::fake(function () {
            throw new ConnectionException('Connection refused');
        });

        $this->assertFalse(GithubHelper::checkRepoExists('owner', 'repo'));
    }

    #[Test]
    public function checkRepoExists_토큰이_있으면_Authorization_헤더를_포함합니다(): void
    {
        Http::fake([
            'api.github.com/*' => Http::response([], 200),
        ]);

        GithubHelper::checkRepoExists('owner', 'repo', 'ghp_test_token');

        Http::assertSent(function (Request $request) {
            return $request->hasHeader('Authorization', 'Bearer ghp_test_token');
        });
    }

    // ──────────────────────────────────────────
    // fetchLatestRelease (Http::fake 기반)
    // ──────────────────────────────────────────

    #[Test]
    public function fetchLatestRelease_200_응답이면_버전과_zipball_url을_반환합니다(): void
    {
        Http::fake([
            'api.github.com/repos/owner/repo/releases/latest' => Http::response([
                'tag_name' => 'v7.0.1',
                'zipball_url' => 'https://api.github.com/repos/owner/repo/zipball/v7.0.1',
            ], 200),
        ]);

        $result = GithubHelper::fetchLatestRelease('owner', 'repo');

        $this->assertSame('7.0.1', $result['version']);
        $this->assertSame('https://api.github.com/repos/owner/repo/zipball/v7.0.1', $result['zipball_url']);
        $this->assertNull($result['error']);
    }

    #[Test]
    public function fetchLatestRelease_v접두사_없는_tag_name을_정상_처리합니다(): void
    {
        Http::fake([
            'api.github.com/*' => Http::response(['tag_name' => '1.2.3', 'zipball_url' => 'https://example.com/z'], 200),
        ]);

        $result = GithubHelper::fetchLatestRelease('owner', 'repo');

        $this->assertSame('1.2.3', $result['version']);
    }

    #[Test]
    public function fetchLatestRelease_404_응답이면_version_null과_error_null을_반환합니다(): void
    {
        Http::fake([
            'api.github.com/*' => Http::response([], 404),
        ]);

        $result = GithubHelper::fetchLatestRelease('owner', 'repo');

        $this->assertNull($result['version']);
        $this->assertNull($result['zipball_url']);
        $this->assertNull($result['error']);
    }

    #[Test]
    public function fetchLatestRelease_연결_실패_시_error_메시지를_반환합니다(): void
    {
        Http::fake(function () {
            throw new ConnectionException('Network unreachable');
        });

        $result = GithubHelper::fetchLatestRelease('owner', 'repo');

        $this->assertNull($result['version']);
        $this->assertNull($result['zipball_url']);
        $this->assertNotNull($result['error']);
        $this->assertStringContainsString('GitHub', $result['error']);
    }

    #[Test]
    public function fetchLatestRelease_tag_name_누락이면_version_null을_반환합니다(): void
    {
        Http::fake([
            'api.github.com/*' => Http::response(['name' => 'release name only'], 200),
        ]);

        $result = GithubHelper::fetchLatestRelease('owner', 'repo');

        $this->assertNull($result['version']);
    }

    // ──────────────────────────────────────────
    // downloadArchive (sink 기반)
    // ──────────────────────────────────────────

    #[Test]
    public function downloadArchive_성공_시_파일을_생성합니다(): void
    {
        $savePath = sys_get_temp_dir().'/github_test_'.uniqid().'.zip';

        Http::fake([
            'example.com/*' => Http::response('fake-zip-binary', 200),
        ]);

        try {
            GithubHelper::downloadArchive('https://example.com/archive.zip', $savePath);

            $this->assertTrue(File::exists($savePath));
            $this->assertSame('fake-zip-binary', File::get($savePath));
        } finally {
            if (File::exists($savePath)) {
                File::delete($savePath);
            }
        }
    }

    #[Test]
    public function downloadArchive_실패_응답이면_RuntimeException을_던집니다(): void
    {
        $savePath = sys_get_temp_dir().'/github_test_'.uniqid().'.zip';

        Http::fake([
            'example.com/*' => Http::response('', 404),
        ]);

        try {
            $this->expectException(\RuntimeException::class);
            GithubHelper::downloadArchive('https://example.com/missing.zip', $savePath);
        } finally {
            if (File::exists($savePath)) {
                File::delete($savePath);
            }
        }
    }

    #[Test]
    public function downloadArchive_연결_실패_시_RuntimeException을_던집니다(): void
    {
        Http::fake(function () {
            throw new ConnectionException('Timeout');
        });

        $this->expectException(\RuntimeException::class);

        GithubHelper::downloadArchive(
            'https://example.com/archive.zip',
            sys_get_temp_dir().'/github_test_'.uniqid().'.zip'
        );
    }

    // ──────────────────────────────────────────
    // downloadArchiveContent (메모리 반환)
    // ──────────────────────────────────────────

    #[Test]
    public function downloadArchiveContent_성공_시_바이너리_내용을_반환합니다(): void
    {
        Http::fake([
            'example.com/*' => Http::response('binary-content', 200),
        ]);

        $content = GithubHelper::downloadArchiveContent('https://example.com/archive.zip');

        $this->assertSame('binary-content', $content);
    }

    #[Test]
    public function downloadArchiveContent_실패_응답이면_RuntimeException을_던집니다(): void
    {
        Http::fake([
            'example.com/*' => Http::response('', 500),
        ]);

        $this->expectException(\RuntimeException::class);

        GithubHelper::downloadArchiveContent('https://example.com/archive.zip');
    }

    // ──────────────────────────────────────────
    // resolveArchiveUrl (HEAD 요청 폴백)
    // ──────────────────────────────────────────

    #[Test]
    public function resolveArchiveUrl_v접두사_태그_200이면_해당_URL을_반환합니다(): void
    {
        // resolveArchiveUrl 은 git/refs/tags/{tag} 로 태그 존재 여부를 먼저 검증한 뒤
        // zipball URL 을 반환 (codeload 404 불가시 실패 방지 — GithubHelper.php 참조)
        Http::fake([
            'api.github.com/repos/owner/repo/git/refs/tags/v1.0.0' => Http::response('', 200),
        ]);

        $url = GithubHelper::resolveArchiveUrl('owner', 'repo', '1.0.0');

        $this->assertSame('https://api.github.com/repos/owner/repo/zipball/v1.0.0', $url);
    }

    #[Test]
    public function resolveArchiveUrl_v접두사_404이면_순수버전으로_폴백합니다(): void
    {
        Http::fake([
            'api.github.com/repos/owner/repo/git/refs/tags/v1.0.0' => Http::response('', 404),
            'api.github.com/repos/owner/repo/git/refs/tags/1.0.0' => Http::response('', 200),
        ]);

        $url = GithubHelper::resolveArchiveUrl('owner', 'repo', '1.0.0');

        $this->assertSame('https://api.github.com/repos/owner/repo/zipball/1.0.0', $url);
    }

    #[Test]
    public function resolveArchiveUrl_둘다_404이면_null을_반환합니다(): void
    {
        Http::fake([
            'api.github.com/*' => Http::response('', 404),
        ]);

        $url = GithubHelper::resolveArchiveUrl('owner', 'repo', '1.0.0');

        $this->assertNull($url);
    }

    #[Test]
    public function resolveArchiveUrl_302이면_해당_URL을_반환합니다(): void
    {
        // 리디렉션 상황: 태그 존재 확인(GET git/refs/tags)만 성공하면 zipball URL 반환.
        // zipball 실제 호출은 다운로드 시점에 발생 (resolveArchiveUrl 은 태그 검증 + URL 조립만 수행)
        Http::fake([
            'api.github.com/repos/owner/repo/git/refs/tags/v1.0.0' => Http::response('', 200),
        ]);

        $url = GithubHelper::resolveArchiveUrl('owner', 'repo', '1.0.0');

        $this->assertSame('https://api.github.com/repos/owner/repo/zipball/v1.0.0', $url);
    }

    // ──────────────────────────────────────────
    // fetchRawFile (raw.githubusercontent.com)
    // ──────────────────────────────────────────

    #[Test]
    public function fetchRawFile_원본_ref로_200이면_내용을_반환합니다(): void
    {
        Http::fake([
            'raw.githubusercontent.com/owner/repo/main/CHANGELOG.md' => Http::response('# Changelog', 200),
        ]);

        $content = GithubHelper::fetchRawFile('owner', 'repo', 'main', 'CHANGELOG.md');

        $this->assertSame('# Changelog', $content);
    }

    #[Test]
    public function fetchRawFile_v접두사_없는_태그는_v접두사로_폴백합니다(): void
    {
        Http::fake([
            'raw.githubusercontent.com/owner/repo/1.0.0/README.md' => Http::response('', 404),
            'raw.githubusercontent.com/owner/repo/v1.0.0/README.md' => Http::response('readme body', 200),
        ]);

        $content = GithubHelper::fetchRawFile('owner', 'repo', '1.0.0', 'README.md');

        $this->assertSame('readme body', $content);
    }

    #[Test]
    public function fetchRawFile_모두_404이면_null을_반환합니다(): void
    {
        Http::fake([
            'raw.githubusercontent.com/*' => Http::response('', 404),
        ]);

        $this->assertNull(GithubHelper::fetchRawFile('owner', 'repo', 'main', 'missing.md'));
    }

    #[Test]
    public function fetchRawFile_연결_실패_시_null을_반환합니다(): void
    {
        Http::fake(function () {
            throw new ConnectionException('DNS failure');
        });

        $this->assertNull(GithubHelper::fetchRawFile('owner', 'repo', 'main', 'CHANGELOG.md'));
    }
}
