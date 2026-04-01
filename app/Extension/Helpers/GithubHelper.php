<?php

namespace App\Extension\Helpers;

use Illuminate\Support\Facades\Log;

/**
 * GitHub API 연동 공통 유틸리티.
 *
 * 코어 업데이트(CoreUpdateService)와 확장 수동 설치(ModuleService, PluginService, TemplateService)
 * 모두에서 사용할 수 있는 GitHub API 관련 공통 메서드를 제공합니다.
 */
class GithubHelper
{
    /**
     * GitHub URL에서 owner/repo를 추출합니다.
     *
     * @param  string  $githubUrl  GitHub 저장소 URL
     * @return array{0: string, 1: string} [owner, repo]
     *
     * @throws \RuntimeException URL 파싱 실패 시
     */
    public static function parseUrl(string $githubUrl): array
    {
        if (empty($githubUrl)) {
            throw new \RuntimeException(__('common.errors.github_url_empty'));
        }

        if (! preg_match('#github\.com[/:]([^/]+)/([^/\.]+)(?:\.git)?/?$#', $githubUrl, $matches)) {
            throw new \RuntimeException(__('common.errors.github_url_invalid'));
        }

        return [$matches[1], $matches[2]];
    }

    /**
     * GitHub 저장소 존재 여부를 확인합니다.
     *
     * @param  string  $owner  저장소 소유자
     * @param  string  $repo  저장소 이름
     * @param  string  $token  GitHub Personal Access Token (빈 문자열이면 인증 없음)
     * @return bool 저장소가 존재하면 true
     */
    public static function checkRepoExists(string $owner, string $repo, string $token = ''): bool
    {
        $apiUrl = "https://api.github.com/repos/{$owner}/{$repo}";

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => static::buildHeaders($token),
                'timeout' => 5,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($apiUrl, false, $context);

        if ($response === false) {
            return false;
        }

        $statusCode = static::extractStatusCode($http_response_header ?? []);

        return $statusCode === 200;
    }

    /**
     * 최신 릴리스 정보를 조회합니다.
     *
     * @param  string  $owner  저장소 소유자
     * @param  string  $repo  저장소 이름
     * @param  string  $token  GitHub Personal Access Token
     * @return array{version: string|null, zipball_url: string|null, error: string|null}
     */
    public static function fetchLatestRelease(string $owner, string $repo, string $token = ''): array
    {
        $apiUrl = "https://api.github.com/repos/{$owner}/{$repo}/releases/latest";

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => static::buildHeaders($token),
                'timeout' => 10,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($apiUrl, false, $context);

        if ($response === false) {
            return ['version' => null, 'zipball_url' => null, 'error' => __('common.errors.github_api_failed')];
        }

        $statusCode = static::extractStatusCode($http_response_header ?? []);

        if ($statusCode !== 200) {
            return ['version' => null, 'zipball_url' => null, 'error' => null];
        }

        $data = json_decode($response, true);

        if (! isset($data['tag_name'])) {
            return ['version' => null, 'zipball_url' => null, 'error' => null];
        }

        return [
            'version' => ltrim($data['tag_name'], 'v'),
            'zipball_url' => $data['zipball_url'] ?? null,
            'error' => null,
        ];
    }

    /**
     * GitHub에서 ZIP을 다운로드합니다.
     *
     * 우선순위: 릴리스 zipball → main 브랜치 → master 브랜치
     *
     * @param  string  $owner  저장소 소유자
     * @param  string  $repo  저장소 이름
     * @param  string  $tempPath  ZIP을 저장할 임시 디렉토리
     * @param  string  $token  GitHub Personal Access Token
     * @return string 다운로드된 ZIP 파일 경로
     *
     * @throws \RuntimeException 다운로드 실패 시
     */
    public static function downloadZip(string $owner, string $repo, string $tempPath, string $token = ''): string
    {
        $zipPath = $tempPath.'/'.uniqid('github_').'.zip';

        // 1단계: 릴리스에서 다운로드 시도
        $release = static::fetchLatestRelease($owner, $repo, $token);
        if ($release['zipball_url']) {
            try {
                static::downloadArchive($release['zipball_url'], $zipPath, $token);

                return $zipPath;
            } catch (\RuntimeException $e) {
                Log::info('GitHub 릴리스 ZIP 다운로드 실패, 브랜치 폴백', [
                    'owner' => $owner,
                    'repo' => $repo,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // 2단계: main 브랜치 시도
        $mainUrl = "https://github.com/{$owner}/{$repo}/archive/refs/heads/main.zip";
        try {
            static::downloadArchive($mainUrl, $zipPath);

            return $zipPath;
        } catch (\RuntimeException $e) {
            // main 실패 → master 폴백
        }

        // 3단계: master 브랜치 시도
        $masterUrl = "https://github.com/{$owner}/{$repo}/archive/refs/heads/master.zip";
        try {
            static::downloadArchive($masterUrl, $zipPath);

            return $zipPath;
        } catch (\RuntimeException $e) {
            throw new \RuntimeException(__('common.errors.github_download_failed'));
        }
    }

    /**
     * 특정 URL에서 아카이브를 다운로드합니다.
     *
     * @param  string  $url  다운로드 URL
     * @param  string  $savePath  저장할 파일 경로
     * @param  string  $token  GitHub Personal Access Token
     *
     * @throws \RuntimeException 다운로드 실패 시
     */
    public static function downloadArchive(string $url, string $savePath, string $token = ''): void
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => static::buildHeaders($token),
                'follow_location' => true,
                'timeout' => 120,
            ],
        ]);

        $content = @file_get_contents($url, false, $context);

        if ($content === false) {
            throw new \RuntimeException(__('common.errors.github_archive_download_failed', ['url' => $url]));
        }

        \Illuminate\Support\Facades\File::put($savePath, $content);
    }

    /**
     * GitHub 저장소에서 특정 태그/브랜치의 파일 내용을 가져옵니다.
     *
     * @param  string  $owner  저장소 소유자
     * @param  string  $repo  저장소 이름
     * @param  string  $ref  태그 또는 브랜치 (예: 'v7.0.0-alpha.5', 'main')
     * @param  string  $filePath  파일 경로 (예: 'CHANGELOG.md')
     * @param  string  $token  GitHub Personal Access Token
     * @return string|null 파일 내용 (실패 시 null)
     */
    public static function fetchRawFile(string $owner, string $repo, string $ref, string $filePath, string $token = ''): ?string
    {
        // v 접두사 있는/없는 태그 모두 시도
        $refs = [$ref];
        if (! str_starts_with($ref, 'v')) {
            $refs[] = 'v'.$ref;
        }

        foreach ($refs as $tryRef) {
            $url = "https://raw.githubusercontent.com/{$owner}/{$repo}/{$tryRef}/{$filePath}";

            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'header' => static::buildHeaders($token),
                    'timeout' => 10,
                    'ignore_errors' => true,
                ],
            ]);

            $content = @file_get_contents($url, false, $context);

            if ($content === false) {
                continue;
            }

            $statusCode = static::extractStatusCode($http_response_header ?? []);

            if ($statusCode === 200) {
                return $content;
            }
        }

        return null;
    }

    /**
     * GitHub API 요청용 HTTP 헤더를 생성합니다.
     *
     * @param  string  $token  GitHub Personal Access Token (빈 문자열이면 인증 없음)
     * @return array HTTP 헤더 배열
     */
    public static function buildHeaders(string $token = ''): array
    {
        $headers = [
            'User-Agent: G7',
            'Accept: application/vnd.github.v3+json',
        ];

        if (! empty($token)) {
            $headers[] = 'Authorization: Bearer '.$token;
        }

        return $headers;
    }

    /**
     * HTTP 응답 헤더에서 상태 코드를 추출합니다.
     *
     * @param  array  $responseHeaders  $http_response_header 배열
     * @return int HTTP 상태 코드
     */
    public static function extractStatusCode(array $responseHeaders): int
    {
        $statusCode = 0;

        foreach ($responseHeaders as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $matches)) {
                // 리다이렉트 시 마지막 상태 코드를 사용하기 위해 계속 진행
                $statusCode = (int) $matches[1];
            }
        }

        return $statusCode;
    }
}
