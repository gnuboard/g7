<?php

namespace Tests\Feature\Extension;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 구동 에셋의 외부 CDN 의존 전수 차단
 *
 * 브라우저가 화면을 그리기 위해 제3자 origin 에 도달해야 하면, 그 도달 실패는 예외도
 * 로그도 남기지 않고 **화면 기능만 조용히 사라진다.** 폐쇄망·방화벽·광고차단기에서
 * 재현되며 자체 서버 로그에 흔적이 없어 운영자가 원인을 특정할 수 없다.
 *
 * 판정 모집단을 한 확장 안에 두지 않고 **코어까지 넓힌다** — 판정기를 한 확장에 두면
 * 그 확장 밖의 동형 결함이 red 가 되지 않는다(`GenericCatchStatusCodeContractTest` 선례).
 *
 * 예외는 두 가지뿐이다:
 *  1. 확장 manifest 가 `trusted_script_hosts` + `trusted_script_hosts_reason` 을 **함께**
 *     선언한 호스트 (자체 호스팅이 불가능한 서비스 SDK 등)
 *  2. 이 테스트가 사유와 함께 명시한 코어 예외 (운영자가 켜야만 출력되는 외부 분석 등)
 *
 * DB 를 쓰지 않는다 — 저장소 소스 스캔이다.
 */
class BundledExtensionNoExternalAssetTest extends TestCase
{
    /**
     * 자산으로 간주하는 확장자
     *
     * API 엔드포인트·문서 링크를 결함으로 오인하지 않도록 **브라우저가 실행/적용하는
     * 파일**만 본다.
     */
    private const ASSET_EXTENSIONS = 'js|mjs|css|woff2?|ttf|otf|eot';

    /**
     * 코어의 명시 예외 (사유 필수)
     *
     * @var array<string, string>
     */
    private const CORE_EXEMPTIONS = [
        // 운영자가 seo.google_analytics_id 를 설정했을 때만 출력되는 외부 분석 서비스다.
        // 자체 제공 대상이 아니며 gdpr 차단 카탈로그가 다루는 영역이다.
        'googletagmanager.com' => '운영자 설정 시에만 출력되는 외부 분석 서비스',
        'google-analytics.com' => '운영자 설정 시에만 출력되는 외부 분석 서비스',
    ];

    /**
     * 스캔 대상 파일 목록을 만듭니다.
     *
     * @return array<int, string> 절대 경로 목록
     */
    private function runtimeSources(): array
    {
        $patterns = [
            // 번들 확장 런타임 소스
            'modules/_bundled/*/resources/**/*.{ts,tsx,json}',
            'modules/_bundled/*/src/**/*.{ts,tsx}',
            'plugins/_bundled/*/resources/**/*.{ts,tsx,json}',
            'plugins/_bundled/*/src/**/*.{ts,tsx}',
            'templates/_bundled/*/src/**/*.{ts,tsx}',
            'templates/_bundled/*/layouts/**/*.json',
            'templates/_bundled/*/template.json',
            'templates/_bundled/*/seo-config.json',
            // 코어 런타임 소스
            'resources/js/core/**/*.{ts,tsx}',
            'resources/layouts/**/*.json',
            'resources/views/*.blade.php',
        ];

        $files = [];

        foreach ($patterns as $pattern) {
            foreach (glob(base_path($pattern), GLOB_BRACE) as $path) {
                $files[] = $path;
            }

            // glob 은 `**` 를 재귀로 해석하지 않으므로 디렉토리 재귀를 따로 돈다
            if (str_contains($pattern, '**')) {
                $files = array_merge($files, $this->globRecursive($pattern));
            }
        }

        // 설치 마법사 (SPA 부팅 전 화면)
        $files[] = base_path('public/install/index.php');

        // 테스트 파일은 제외한다 — 차단 대상 URL 을 픽스처로 담는 것이 그 테스트의 일이다
        $files = array_filter($files, function (string $path): bool {
            $normalized = str_replace('\\', '/', $path);

            return is_file($path)
                && ! str_contains($normalized, '/__tests__/')
                && ! str_contains($normalized, '/tests/');
        });

        return array_values(array_unique($files));
    }

    /**
     * `**` 를 포함한 패턴을 재귀적으로 해석합니다.
     *
     * @param  string  $pattern  상대 패턴
     * @return array<int, string> 절대 경로 목록
     */
    private function globRecursive(string $pattern): array
    {
        [$prefix, $suffix] = explode('**', $pattern, 2);
        $suffix = ltrim($suffix, '/');
        $results = [];

        foreach (glob(base_path($prefix), GLOB_ONLYDIR | GLOB_BRACE) as $root) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (! $file->isFile()) {
                    continue;
                }

                if (fnmatch($suffix, $file->getBasename(), FNM_CASEFOLD)
                    || fnmatch(basename($suffix), $file->getBasename(), FNM_CASEFOLD)) {
                    $results[] = $file->getPathname();
                }
            }
        }

        return $results;
    }

    /**
     * 파일이 속한 확장의 manifest 에서 사유가 선언된 신뢰 호스트를 읽습니다.
     *
     * @param  string  $path  파일 절대 경로
     * @return array<int, string> 사유가 함께 선언된 호스트 목록
     */
    private function declaredHostsWithReason(string $path): array
    {
        $relative = str_replace('\\', '/', substr($path, strlen(base_path()) + 1));

        if (! preg_match('#^(modules|plugins|templates)/_bundled/([^/]+)/#', $relative, $m)) {
            return [];
        }

        $manifestName = match ($m[1]) {
            'modules' => 'module.json',
            'plugins' => 'plugin.json',
            default => 'template.json',
        };

        $manifestPath = base_path("{$m[1]}/_bundled/{$m[2]}/{$manifestName}");

        if (! is_file($manifestPath)) {
            return [];
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), true) ?: [];
        $hosts = $manifest['trusted_script_hosts'] ?? [];
        $reasons = $manifest['trusted_script_hosts_reason'] ?? [];

        if (! is_array($hosts) || ! is_array($reasons)) {
            return [];
        }

        // 사유 없이 선언된 호스트는 예외로 인정하지 않는다 — 왜 외부로 나가는지가
        // 코드에 남아야 "자체 제공이 원칙, 예외는 근거와 함께" 가 강제된다.
        return array_values(array_filter(
            $hosts,
            fn ($host) => is_string($host) && ! empty($reasons[$host])
        ));
    }

    /**
     * @scenario asset_class=vendored, outcome=loaded
     * @scenario asset_class=translation, outcome=loaded
     * @scenario asset_class=service_sdk, outcome=loaded
     *
     * @effects no_third_party_request_on_page_load, runtime_asset_served_same_origin
     */
    #[Test]
    public function 런타임_소스에_외부_자산_ur_l_이_없다(): void
    {
        // 구분자로 `~` 를 쓴다 — 패턴 안에 URL 프래그먼트(`#`)가 들어가 `#` 구분자와 충돌한다
        // 확장자 뒤에 경계를 둔다 — 새 탭으로 여는 외부 페이지(`.jsp`)가 `.js` 로 오탐되면
        // 자산이 아닌 링크를 결함으로 세게 된다 (KG이니시스 영수증 링크가 그 예다).
        $pattern = '~(?:https?:)?//([A-Za-z0-9._-]+)/[^"\'\s)]*\.(?:'.self::ASSET_EXTENSIONS.')(?![A-Za-z0-9])(?:[?\#][^"\'\s)]*)?~i';
        $violations = [];

        foreach ($this->runtimeSources() as $path) {
            $contents = (string) file_get_contents($path);

            if (! preg_match_all($pattern, $contents, $matches, PREG_SET_ORDER)) {
                continue;
            }

            $allowedHosts = array_merge(
                $this->declaredHostsWithReason($path),
                array_keys(self::CORE_EXEMPTIONS)
            );

            foreach ($matches as $match) {
                [$url, $host] = $match;

                if (in_array($host, $allowedHosts, true)) {
                    continue;
                }

                // 주석에 남은 설명 문구는 대상이 아니다 — 실제 로드 경로만 본다
                if ($this->isInsideComment($contents, $url)) {
                    continue;
                }

                $violations[] = str_replace('\\', '/', substr($path, strlen(base_path()) + 1)).' → '.$url;
            }
        }

        $this->assertSame(
            [],
            array_values(array_unique($violations)),
            "구동 자산을 외부 origin 에서 불러오는 지점이 남아 있습니다.\n"
            ."자체 제공으로 바꾸거나, 자체 제공이 불가능하면 manifest 에\n"
            ."`trusted_script_hosts` + `trusted_script_hosts_reason` 을 함께 선언하세요.\n"
            .implode("\n", array_unique($violations))
        );
    }

    /**
     * URL 이 주석 안에 있는지 판정합니다.
     *
     * @param  string  $contents  파일 내용
     * @param  string  $url  검출된 URL
     * @return bool 주석 안이면 true
     */
    private function isInsideComment(string $contents, string $url): bool
    {
        foreach (explode("\n", $contents) as $line) {
            if (! str_contains($line, $url)) {
                continue;
            }

            $trimmed = ltrim($line);

            if (str_starts_with($trimmed, '//')
                || str_starts_with($trimmed, '*')
                || str_starts_with($trimmed, '/*')
                || str_starts_with($trimmed, '#')) {
                continue;
            }

            return false;
        }

        return true;
    }

    #[Test]
    public function 신뢰_호스트를_선언한_확장은_사유도_함께_선언한다(): void
    {
        $missing = [];

        foreach (['modules' => 'module.json', 'plugins' => 'plugin.json', 'templates' => 'template.json'] as $type => $name) {
            foreach (glob(base_path("{$type}/_bundled/*/{$name}")) as $manifestPath) {
                $manifest = json_decode((string) file_get_contents($manifestPath), true) ?: [];
                $hosts = $manifest['trusted_script_hosts'] ?? [];

                if (! is_array($hosts) || $hosts === []) {
                    continue;
                }

                $reasons = $manifest['trusted_script_hosts_reason'] ?? [];

                foreach ($hosts as $host) {
                    if (empty($reasons[$host])) {
                        $missing[] = str_replace('\\', '/', substr($manifestPath, strlen(base_path()) + 1)).' → '.$host;
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $missing,
            "외부 호스트를 신뢰 출처로 선언했으면 그 사유도 manifest 에 남겨야 합니다.\n"
            .'`trusted_script_hosts_reason` 에 호스트별 사유를 적으세요.'."\n"
            .implode("\n", $missing)
        );
    }

    /**
     * @effects template_external_asset_field_resolves_to_asset_url
     */
    #[Test]
    public function 템플릿_externals_는_자체_제공_경로를_쓴다(): void
    {
        $violations = [];

        foreach (glob(base_path('templates/_bundled/*/template.json')) as $manifestPath) {
            $manifest = json_decode((string) file_get_contents($manifestPath), true) ?: [];

            foreach ($manifest['externals'] ?? [] as $external) {
                if (! is_array($external) || ! isset($external['url'])) {
                    continue;
                }

                if (preg_match('#^(https?:)?//#i', (string) $external['url']) === 1) {
                    $violations[] = str_replace('\\', '/', substr($manifestPath, strlen(base_path()) + 1))
                        .' → '.$external['id'].' ('.$external['url'].')';
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            "템플릿 externals 는 `asset`(자체 제공 경로) 으로 선언해야 합니다.\n"
            .implode("\n", $violations)
        );
    }
}
