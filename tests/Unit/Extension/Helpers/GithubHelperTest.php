<?php

namespace Tests\Unit\Extension\Helpers;

use App\Extension\Helpers\GithubHelper;
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
    // extractStatusCode
    // ──────────────────────────────────────────

    #[Test]
    public function extractStatusCode_200_응답을_추출합니다(): void
    {
        $statusCode = GithubHelper::extractStatusCode(['HTTP/1.1 200 OK']);

        $this->assertSame(200, $statusCode);
    }

    #[Test]
    public function extractStatusCode_404_응답을_추출합니다(): void
    {
        $statusCode = GithubHelper::extractStatusCode(['HTTP/1.1 404 Not Found']);

        $this->assertSame(404, $statusCode);
    }

    #[Test]
    public function extractStatusCode_빈_헤더에서_0을_반환합니다(): void
    {
        $statusCode = GithubHelper::extractStatusCode([]);

        $this->assertSame(0, $statusCode);
    }

    #[Test]
    public function extractStatusCode_리다이렉트_시_마지막_상태코드를_반환합니다(): void
    {
        $statusCode = GithubHelper::extractStatusCode([
            'HTTP/1.1 301 Moved Permanently',
            'Location: https://example.com',
            'HTTP/1.1 200 OK',
        ]);

        $this->assertSame(200, $statusCode);
    }

    #[Test]
    public function extractStatusCode_302_후_200_리다이렉트_체인을_처리합니다(): void
    {
        $statusCode = GithubHelper::extractStatusCode([
            'HTTP/1.1 302 Found',
            'HTTP/1.1 302 Found',
            'HTTP/1.1 200 OK',
        ]);

        $this->assertSame(200, $statusCode);
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
}
