<?php

namespace App\Extension\Helpers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
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
     * `assets` 는 릴리스에 올린 파일 목록이다(서명 자산 짝 찾기 — `findSignedAssetPair()`).
     * `status` 는 응답을 받았을 때의 HTTP 상태 코드다(연결 실패면 null). 404 는 「릴리스가 아직 없음」이고, 그 밖의 실패
     * 상태(403 요청 한도 초과·5xx 등)는 조회 실패다 — 호출자가 둘을 가를 수 있게 싣는다(7.2.0 부터).
     *
     * @param  string  $owner  저장소 소유자
     * @param  string  $repo  저장소 이름
     * @param  string  $token  GitHub Personal Access Token
     * @return array{version: string|null, tag: string|null, zipball_url: string|null, assets: array<int, array{name: string, size: int, url: string|null, browser_download_url: string|null}>, error: string|null, status: int|null} 릴리스 정보 (조회 실패면 version null, API 연결 실패면 error 문장, 응답을 받았으면 HTTP 상태)
     */
    public static function fetchLatestRelease(string $owner, string $repo, string $token = ''): array
    {
        return static::fetchRelease("https://api.github.com/repos/{$owner}/{$repo}/releases/latest", $token);
    }

    /**
     * 태그로 릴리스 정보를 조회합니다 (`v` 접두 유/무 모두 시도).
     *
     * 태그는 있는데 릴리스가 없으면(태그만 푸시) version 이 null 이다 — 그 경우 호출부는 태그 zipball 로 내려받고
     * 미서명으로 다룬다.
     *
     * @param  string  $owner  저장소 소유자
     * @param  string  $repo  저장소 이름
     * @param  string  $version  버전 (v 접두 유/무 무관)
     * @param  string  $token  GitHub Personal Access Token
     * @return array{version: string|null, tag: string|null, zipball_url: string|null, assets: array<int, array{name: string, size: int, url: string|null, browser_download_url: string|null}>, error: string|null, status: int|null} 릴리스 정보 (릴리스가 없으면 version null, API 연결 실패면 error 문장, 응답을 받았으면 HTTP 상태)
     */
    public static function fetchReleaseByTag(string $owner, string $repo, string $version, string $token = ''): array
    {
        $plain = ltrim($version, 'vV');
        $last = ['version' => null, 'tag' => null, 'zipball_url' => null, 'assets' => [], 'error' => null, 'status' => null];

        foreach (["v{$plain}", $plain] as $tag) {
            $result = static::fetchRelease("https://api.github.com/repos/{$owner}/{$repo}/releases/tags/".rawurlencode($tag), $token);
            if ($result['version'] !== null) {
                return $result;
            }
            $last = $result;
        }

        return $last;
    }

    /**
     * 릴리스 자산 목록에서 서명된 ZIP 과 서명 파일 짝을 찾습니다.
     *
     * `{base}.zip` 과 `{base}.zip.sig.json` 이 함께 있어야 짝이다. `$preferredBase`(예 `sirsoft-board-1.3.0`)가 있으면 그
     * 이름을 먼저 찾고, 없으면(GitHub 주소로 처음 설치 — 식별자를 모름) 짝이 맞는 첫 ZIP 을 쓴다.
     *
     * @param  array<int, array<string, mixed>>  $assets  릴리스 자산 목록
     * @param  string|null  $preferredBase  우선 찾을 자산 이름 (`.zip` 앞부분)
     * @return array{zip: array<string, mixed>, signature: array<string, mixed>}|null 짝 (없으면 null)
     */
    public static function findSignedAssetPair(array $assets, ?string $preferredBase = null): ?array
    {
        $byName = [];
        foreach ($assets as $asset) {
            if (is_array($asset) && is_string($asset['name'] ?? null)) {
                $byName[$asset['name']] = $asset;
            }
        }

        if ($preferredBase !== null && $preferredBase !== '') {
            $zipName = $preferredBase.'.zip';
            if (isset($byName[$zipName], $byName[$zipName.'.sig.json'])) {
                return ['zip' => $byName[$zipName], 'signature' => $byName[$zipName.'.sig.json']];
            }
        }

        foreach ($byName as $name => $asset) {
            if (str_ends_with(strtolower($name), '.zip') && isset($byName[$name.'.sig.json'])) {
                return ['zip' => $asset, 'signature' => $byName[$name.'.sig.json']];
            }
        }

        return null;
    }

    /**
     * 릴리스 자산 하나를 파일로 내려받습니다.
     *
     * 비공개 저장소 자산은 브라우저 주소로 받을 수 없어 API 주소(`url`)에 `Accept: application/octet-stream` 과 토큰을
     * 실어 받는다. API 주소가 없으면 브라우저 주소를 쓴다.
     *
     * @param  array<string, mixed>  $asset  자산 (fetchLatestRelease 의 assets 항목)
     * @param  string  $savePath  저장할 경로
     * @param  string  $token  GitHub Personal Access Token
     * @return void 반환값 없음
     *
     * @throws \RuntimeException 다운로드 실패 시
     */
    public static function downloadAsset(array $asset, string $savePath, string $token = ''): void
    {
        $url = is_string($asset['url'] ?? null) && $asset['url'] !== ''
            ? $asset['url']
            : (string) ($asset['browser_download_url'] ?? '');

        if ($url === '') {
            throw new \RuntimeException(__('common.errors.github_archive_download_failed', ['url' => (string) ($asset['name'] ?? '')]));
        }

        try {
            $response = static::request($token, 120)
                ->withHeaders(['Accept' => 'application/octet-stream'])
                ->sink($savePath)
                ->get($url);
        } catch (ConnectionException $e) {
            if (File::exists($savePath)) {
                File::delete($savePath);
            }

            Log::warning('GitHub 릴리스 자산 다운로드 연결 실패', ['url' => $url, 'error' => $e->getMessage()]);

            throw new \RuntimeException(__('common.errors.github_archive_download_failed', ['url' => $url]));
        }

        if (! $response->successful()) {
            if (File::exists($savePath)) {
                File::delete($savePath);
            }

            throw new \RuntimeException(__('common.errors.github_archive_download_failed', ['url' => $url]));
        }
    }

    /**
     * GitHub에서 최신 릴리스 ZIP(zipball)을 다운로드합니다.
     *
     * 태그 릴리스만 받는다 — 릴리스가 없으면 `main`·`master` 브랜치를 받지 않고 `github_release_not_found` 로 실패한다
     * (7.2.0 전에는 브랜치 머리를 그대로 받아 검토되지 않은 코드가 설치됐다). 서명 자산을 쓰는 경로는
     * `PackageAcquirer::fromGithubRelease()` 다.
     *
     * @param  string  $owner  저장소 소유자
     * @param  string  $repo  저장소 이름
     * @param  string  $tempPath  ZIP을 저장할 임시 디렉토리
     * @param  string  $token  GitHub Personal Access Token
     * @return string 다운로드된 ZIP 파일 경로
     *
     * @throws \RuntimeException 릴리스가 없거나 다운로드 실패 시
     */
    public static function downloadZip(string $owner, string $repo, string $tempPath, string $token = ''): string
    {
        $zipPath = $tempPath.'/'.uniqid('github_').'.zip';

        $release = static::fetchLatestRelease($owner, $repo, $token);
        if ($release['error'] !== null) {
            throw new \RuntimeException($release['error']);
        }

        if (! $release['zipball_url']) {
            throw new \RuntimeException(__('common.errors.github_release_not_found', ['repository' => $owner.'/'.$repo]));
        }

        try {
            static::downloadArchive($release['zipball_url'], $zipPath, $token);
        } catch (\RuntimeException $e) {
            throw new \RuntimeException(__('common.errors.github_download_failed'), 0, $e);
        }

        return $zipPath;
    }

    /**
     * 릴리스 API 응답을 공통 모양으로 바꿉니다.
     *
     * @param  string  $apiUrl  릴리스 API 주소
     * @param  string  $token  GitHub Personal Access Token
     * @return array{version: string|null, tag: string|null, zipball_url: string|null, assets: array<int, array{name: string, size: int, url: string|null, browser_download_url: string|null}>, error: string|null, status: int|null} 릴리스 정보 (릴리스가 없으면 version null, API 연결 실패면 error 문장, 응답을 받았으면 HTTP 상태)
     */
    protected static function fetchRelease(string $apiUrl, string $token = ''): array
    {
        $empty = ['version' => null, 'tag' => null, 'zipball_url' => null, 'assets' => [], 'error' => null, 'status' => null];

        try {
            $response = static::request($token, 10)->get($apiUrl);
        } catch (ConnectionException $e) {
            Log::warning('GitHub API 연결 실패', ['url' => $apiUrl, 'error' => $e->getMessage()]);

            return ['error' => __('common.errors.github_api_failed')] + $empty;
        }

        if (! $response->successful()) {
            return ['status' => $response->status()] + $empty;
        }

        $data = $response->json();

        if (! is_array($data) || ! isset($data['tag_name'])) {
            return ['status' => $response->status()] + $empty;
        }

        $assets = [];
        foreach ((array) ($data['assets'] ?? []) as $asset) {
            if (! is_array($asset) || ! is_string($asset['name'] ?? null)) {
                continue;
            }
            $assets[] = [
                'name' => $asset['name'],
                'size' => (int) ($asset['size'] ?? 0),
                'url' => is_string($asset['url'] ?? null) ? $asset['url'] : null,
                'browser_download_url' => is_string($asset['browser_download_url'] ?? null) ? $asset['browser_download_url'] : null,
            ];
        }

        return [
            'version' => ltrim((string) $data['tag_name'], 'v'),
            'tag' => (string) $data['tag_name'],
            'zipball_url' => $data['zipball_url'] ?? null,
            'assets' => $assets,
            'error' => null,
            'status' => $response->status(),
        ];
    }

    /**
     * 특정 URL에서 아카이브를 다운로드하여 파일로 저장합니다.
     *
     * Http 파사드의 `sink()`를 사용하여 메모리 사용을 최소화합니다.
     *
     * @param  string  $url  다운로드 URL
     * @param  string  $savePath  저장할 파일 경로
     * @param  string  $token  GitHub Personal Access Token
     * @return void 반환값 없음
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
     * @return PendingRequest 헤더·토큰·타임아웃을 갖춘 요청
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
