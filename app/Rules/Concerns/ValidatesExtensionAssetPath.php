<?php

namespace App\Rules\Concerns;

use Closure;
use Illuminate\Support\Str;

/**
 * 확장 자산 경로 검증의 공통 규칙 (템플릿·모듈·플러그인 3통로 공유).
 *
 * 세 규칙 클래스(`SafeTemplatePath`·`SafeModulePath`·`SafePluginPath`)는 주석까지 같은
 * 사본이었다. 사본이 셋이면 한쪽만 고쳐지고, 실제로 그렇게 됐다 — 앞서
 * `SafeTemplatePath` 에만 넣은 두 보강(구분자 정규화 · 접두 비교의 후행 구분자)이 형제
 * 둘에는 반영되지 않아, Windows 에서 **부재 파일이 404 가 아니라 422** 로 나가고
 * (`base_path()` 가 만드는 혼합 구분자 때문) 형제 디렉토리 접두 일치도 그 둘에만 남았다.
 *
 * 그래서 판정을 여기 한 곳으로 접는다. 각 규칙은 자기 다국어 키 접두사만 넘긴다.
 *
 * 점(`.`)으로 시작하는 세그먼트를 거부하는 것도 이 자리다 — 서버 3종 설정
 * (`.htaccess`·`web.config`·nginx)의 숨김 파일 차단은 **URL 경로**에만 걸리는데, 확장자
 * 없는 모드는 그 경로를 `?file=` 쿼리로 옮기므로 그 방어선을 통째로 지나간다. 같은 규칙을
 * 애플리케이션 계층이 갖지 않으면 같은 파일이 주소 형태에 따라 403·200 으로 갈린다
 * (형제 통로 `PublicStorageService` 는 이미 이 규칙을 갖고 있다).
 */
trait ValidatesExtensionAssetPath
{
    /**
     * Path Traversal 패턴 목록.
     *
     * @var list<string>
     */
    private const TRAVERSAL_PATTERNS = [
        '../',
        '..\\',
        '//',
        '%2e%2e%2f',  // URL encoded ../
        '%2e%2e/',    // Partial URL encoded
        '%2e%2e%5c',  // URL encoded ..\
        '%2e%2e\\',   // Partial URL encoded
        '..%2f',      // Mixed encoding
        '..%5c',      // Mixed encoding
        '.%2e/',      // Partial encoding
        '.%2e\\',     // Partial encoding
    ];

    /**
     * 확장 자산 경로를 검증합니다.
     *
     * @param  mixed  $value  검증 대상 값
     * @param  Closure  $fail  실패 콜백
     * @param  string  $basePath  봉쇄 기준 디렉토리
     * @param  string  $messagePrefix  다국어 키 접두사 (예: `validation.template_path`)
     * @param  bool  $rejectHiddenSegments  점으로 시작하는 세그먼트를 이 계층에서 거부할지 여부
     * @return void 반환값 없음
     */
    protected function validateExtensionAssetPath(
        mixed $value,
        Closure $fail,
        string $basePath,
        string $messagePrefix,
        bool $rejectHiddenSegments = true
    ): void {
        if (! is_string($value)) {
            $fail(__($messagePrefix.'.must_be_string'));

            return;
        }

        // URL 디코딩 (다중 인코딩 공격 방지). 최대 5회 — 무한 루프 방지.
        $decodedPath = $value;
        $previousPath = '';

        for ($i = 0; $i < 5 && $decodedPath !== $previousPath; $i++) {
            $previousPath = $decodedPath;
            $decodedPath = urldecode($decodedPath);
        }

        $lowerPath = strtolower($decodedPath);
        foreach (self::TRAVERSAL_PATTERNS as $pattern) {
            if (Str::contains($lowerPath, strtolower($pattern))) {
                $fail(__($messagePrefix.'.traversal_detected', ['pattern' => $pattern]));

                return;
            }
        }

        // 절대 경로 방지 (Windows·Linux 모두)
        if (
            Str::startsWith($decodedPath, '/') ||
            Str::startsWith($decodedPath, '\\') ||
            preg_match('/^[a-zA-Z]:/', $decodedPath)
        ) {
            $fail(__($messagePrefix.'.absolute_path_not_allowed'));

            return;
        }

        // NULL 바이트 공격 방지
        if (Str::contains($decodedPath, "\0")) {
            $fail(__($messagePrefix.'.null_byte_detected'));

            return;
        }

        // 경로 정규화를 **분할보다 먼저** 한다. 구분자 정의가 한 벌이어야 하기 때문이다 —
        // 아래 봉쇄 판정은 `\` 와 `/` 를 모두 구분자로 취급하는데(`$normalizedPath`),
        // 분할만 `/` 로 하면 `dist\.hidden/app.js` 의 숨김 세그먼트를 못 본 채 봉쇄는
        // 통과시켜 그 파일이 그대로 나간다. 이 규칙을 처음 넣을 때 분할 정규식을
        // `'#[\\/]+#'` 로 적었는데 그것은 PHP 파싱 후 `#[\/]+#` 라 클래스 원소가 `/` 뿐이다.
        $normalizedPath = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $decodedPath);

        // 점으로 시작하는 세그먼트 거부 — 서버 설정의 숨김 파일 차단은 URL 경로에만
        // 걸리므로, 확장자 없는 모드(`?file=`)가 그 방어선을 우회한다.
        // 공개 스토리지 통로는 이 검사를 끈다 — 그 서비스가 같은 규칙을 **404** 로
        // 강제하고 그 코드가 계약이다(정적 서빙이 같은 상황에서 404 를 주므로 링크
        // 유무로 결과가 갈리지 않게 맞춘 것). 여기서 422 를 내면 그 계약이 뒤집힌다.
        $segments = $rejectHiddenSegments
            ? explode(DIRECTORY_SEPARATOR, $normalizedPath)
            : [];

        foreach ($segments as $segment) {
            if ($segment !== '' && str_starts_with($segment, '.')) {
                $fail(__($messagePrefix.'.hidden_segment_not_allowed'));

                return;
            }
        }

        $fullPath = realpath($basePath.DIRECTORY_SEPARATOR.$normalizedPath);

        if ($fullPath === false) {
            // 파일이 아직 없을 수 있다 — 경로 구성만 검증하고 존재 판정은 소비 계층에 맡긴다.
            $fullPath = $basePath.DIRECTORY_SEPARATOR.$normalizedPath;
        }

        // 기준 경로 외부 접근 방지.
        //
        // realpath 산출물은 OS 구분자로 정규화되지만 폴백 경로는 호출부가 넘긴 basePath 의
        // 구분자를 그대로 갖는다. `base_path('modules/foo')` 는 Windows 에서 **반드시**
        // 혼합 구분자(`C:\…\g7\modules/foo`)라, 정규화 없이 비교하면 부재 파일이 전부
        // 「기준 밖」으로 오판되어 404 대신 422 가 된다.
        //
        // 접두 비교에는 구분자를 붙인다 — 붙이지 않으면 `/base` 기준에서 형제 디렉토리
        // `/base-other/...` 가 접두 일치로 통과한다.
        //
        // 기준 디렉토리 자체를 해석하지 못하는 것은 **경로 밖 접근이 아니다** — 아직 없거나
        // 권한·`open_basedir` 로 막힌 상태다. 그것을 검증 실패로 접으면 링크가 되는 서버의
        // 정적 서빙(404)과 이 통로(422)가 갈리고, 「왜 전부 404 인가」를 알리는 진단이
        // 도달 불가가 된다. 여기서는 문자열 접두 비교로 봉쇄만 유지한다.
        $realBasePath = realpath($basePath);
        $comparableBase = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $realBasePath === false ? $basePath : $realBasePath);
        $comparableFullPath = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $fullPath);

        if (! Str::startsWith($comparableFullPath, rtrim($comparableBase, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)) {
            $fail(__($messagePrefix.'.outside_base_directory'));

            return;
        }
    }
}
