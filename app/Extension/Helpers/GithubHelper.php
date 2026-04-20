<?php

namespace App\Extension\Helpers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * GitHub API 연동 공통 유틸리티.
 *
 * 코어 업데이트(CoreUpdateService)와 확장 수동 설치(ModuleService, PluginService, TemplateService)
 * 모두에서 사용할 수 있는 GitHub API 관련 공통 메서드를 제공합니다.
 *
 * 모든 원격 HTTP 호출은 Laravel Http 파사드를 사용합니다.
 * `allow_url_fopen=Off` 환경(공유 호스팅)에서도 정상 동작합니다.
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
        try {
            $response = static::request($token, 5)
                ->get("https://api.github.com/repos/{$owner}/{$repo}");
        } catch (ConnectionException $e) {
            Log::warning('GitHub 저장소 확인 연결 실패', [
                'owner' => $owner,
                'repo' => $repo,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        return $response->successful();
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

        try {
            $response = static::request($token, 10)->get($apiUrl);
        } catch (ConnectionException $e) {
            Log::warning('GitHub API 연결 실패', ['url' => $apiUrl, 'error' => $e->getMessage()]);

            return ['version' => null, 'zipball_url' => null, 'error' => __('common.errors.github_api_failed')];
        }

        if (! $response->successful()) {
            return ['version' => null, 'zipball_url' => null, 'error' => null];
        }

        $data = $response->json();

        if (! is_array($data) || ! isset($data['tag_name'])) {
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
     * 특정 URL에서 아카이브를 다운로드하여 파일로 저장합니다.
     *
     * Http 파사드의 `sink()`를 사용하여 메모리 사용을 최소화합니다.
     *
     * @param  string  $url  다운로드 URL
     * @param  string  $savePath  저장할 파일 경로
     * @param  string  $token  GitHub Personal Access Token
     *
     * @throws \RuntimeException 다운로드 실패 시
     */
    public static function downloadArchive(string $url, string $savePath, string $token = ''): void
    {
        try {
            $response = static::request($token, 120)
                ->sink($savePath)
                ->get($url);
        } catch (ConnectionException $e) {
            // 연결 실패 시에도 sink가 생성했을 수 있는 빈/부분 파일 정리
            if (File::exists($savePath)) {
                File::delete($savePath);
            }

            Log::warning('GitHub 아카이브 다운로드 연결 실패', ['url' => $url, 'error' => $e->getMessage()]);

            throw new \RuntimeException(__('common.errors.github_archive_download_failed', ['url' => $url]));
        }

        if (! $response->successful()) {
            // sink로 저장된 미완성 파일 정리
            if (File::exists($savePath)) {
                File::delete($savePath);
            }

            throw new \RuntimeException(__('common.errors.github_archive_download_failed', ['url' => $url]));
        }
    }

    /**
     * 특정 URL에서 아카이브를 다운로드하여 바이너리 내용을 반환합니다.
     *
     * `downloadArchive()`와 달리 파일로 저장하지 않고 메모리로 내용을 반환합니다.
     * 호출부가 바이너리 내용을 직접 처리해야 하는 경우에만 사용하세요.
     *
     * @param  string  $url  다운로드 URL
     * @param  string  $token  GitHub Personal Access Token
     * @return string 다운로드된 바이너리 내용
     *
     * @throws \RuntimeException 다운로드 실패 시
     */
    public static function downloadArchiveContent(string $url, string $token = ''): string
    {
        try {
            $response = static::request($token, 120)->get($url);
        } catch (ConnectionException $e) {
            Log::warning('GitHub 아카이브 다운로드 연결 실패', ['url' => $url, 'error' => $e->getMessage()]);

            throw new \RuntimeException(__('settings.core_update.download_failed', ['version' => basename($url)]));
        }

        if (! $response->successful()) {
            throw new \RuntimeException(__('settings.core_update.download_failed', ['version' => basename($url)]));
        }

        return $response->body();
    }

    /**
     * GitHub 아카이브 URL을 해석합니다 (v 접두사 유/무 모두 시도).
     *
     * HEAD 요청으로 200/302 응답이 오는 첫 번째 URL을 반환합니다.
     *
     * @param  string  $owner  저장소 소유자
     * @param  string  $repo  저장소 이름
     * @param  string  $version  버전 태그 (v 접두사 유/무 무관)
     * @param  string  $archiveType  아카이브 타입 (zipball/tarball)
     * @param  string  $token  GitHub Personal Access Token
     * @return string|null 유효한 아카이브 URL 또는 null
     */
    public static function resolveArchiveUrl(string $owner, string $repo, string $version, string $archiveType = 'zipball', string $token = ''): ?string
    {
        $tagVariants = ["v{$version}", $version];

        foreach ($tagVariants as $tag) {
            // GitHub API 의 `zipball/{tag}` 엔드포인트는 태그가 존재하지 않아도 무조건 302 로
            // codeload 에 리다이렉트하며, 실제 다운로드 시점에 codeload 에서 404 가 반환되어
            // 다운로드 파일이 "404: Not Found" 14 byte 텍스트로 저장되는 불가시 실패가 발생한다.
            // 따라서 아카이브 URL 반환 전에 `git/refs/tags/{tag}` 로 태그 자체의 존재 여부를
            // 먼저 검증한다 (GitHub API: 존재=200, 부재=404).
            $tagCheckUrl = "https://api.github.com/repos/{$owner}/{$repo}/git/refs/tags/{$tag}";

            try {
                $tagResponse = static::request($token, 10)->get($tagCheckUrl);
            } catch (ConnectionException $e) {
                Log::info('GitHub 태그 존재 검증 실패', ['url' => $tagCheckUrl, 'error' => $e->getMessage()]);

                continue;
            }

            if ($tagResponse->status() !== 200) {
                continue;
            }

            return "https://api.github.com/repos/{$owner}/{$repo}/{$archiveType}/{$tag}";
        }

        return null;
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

            try {
                $response = static::request($token, 10)->get($url);
            } catch (ConnectionException $e) {
                continue;
            }

            if ($response->successful()) {
                return $response->body();
            }
        }

        return null;
    }

    /**
     * GitHub API 요청용 HTTP 헤더를 생성합니다 (file_get_contents 스트림 컨텍스트 호환).
     *
     * 레거시 호출부 호환을 위해 배열 형태의 헤더를 반환합니다.
     * 신규 코드는 `request()`를 사용하세요.
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
     * GitHub API 호출을 위한 표준화된 Http 파사드 PendingRequest를 생성합니다.
     *
     * 모든 원격 HTTP 호출의 단일 진입점입니다. User-Agent, Accept, 토큰 인증,
     * 타임아웃을 일괄 설정합니다.
     *
     * @param  string  $token  GitHub Personal Access Token (빈 문자열이면 인증 없음)
     * @param  int  $timeout  읽기 타임아웃(초)
     */
    protected static function request(string $token = '', int $timeout = 10): PendingRequest
    {
        $request = Http::withHeaders([
            'User-Agent' => 'G7',
            'Accept' => 'application/vnd.github.v3+json',
        ])
            ->timeout($timeout)
            ->connectTimeout(5);

        if ($token !== '') {
            $request = $request->withToken($token);
        }

        return $request;
    }
}
