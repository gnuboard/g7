<?php

namespace App\Services\Concerns;

/**
 * 확장 자산 서빙의 **2차 봉쇄** (템플릿·모듈·플러그인 공유).
 *
 * 세 요청 클래스(`Serve{Template,Module,Plugin}AssetRequest`)는 공통으로 「파일시스템
 * 화이트리스트가 이 표면의 유일한 방어선이고, 훅이 그 경로를 바꾸면 경로 탈출·임의 파일
 * 읽기가 열린다」는 근거로 훅 필터 면제를 받는다. 규칙 한 줄이 무력해지면 그 통로에는
 * **남는 방어선이 없다** — 그래서 서비스 계층이 정제와 realpath 재확인을 한 번 더 한다.
 *
 * 이력(사실 관계를 두 번 정정했다):
 *   · 이 trait 은 모듈·플러그인이 활성 여부만 확인하고 곧바로 경로를 열던 것을 닫으려고
 *     만들어졌고, 그 근거로 「템플릿에는 2차 봉쇄가 있다」를 들었다.
 *   · 11차 G3 라운드 실측에서 **그 근거가 틀렸음**이 확인됐다. 템플릿이 가진 것은
 *     문자열 정제 하나뿐이었고 realpath 재확인은 없었다(그 파일의 주석이 근거로 든
 *     「FormRequest 의 realpath 검사」는 요청 규칙 축이라 서비스 직접 호출에는 걸리지
 *     않는다). 즉 trait 도입 시점에 2차 봉쇄를 **온전히** 가진 통로는 하나도 없었다.
 *   · 그 라운드에서 템플릿도 이 trait 으로 옮겨 세 통로가 실제로 공유하게 됐다.
 *
 * 세 통로가 이 trait 을 쓰는지는 `ExtensionAssetServiceContainmentParityTest` 가 잰다 —
 * 사본이 갈리면 그 사실 자체가 red 다(위 이력이 정확히 그 방식으로 어긋나 있었다).
 */
trait SecuresExtensionAssetPath
{
    /**
     * 자산 상대 경로에서 탈출 시퀀스를 제거합니다.
     *
     * 반복 치환인 이유: 1회 치환은 겹친 패턴을 복원한다 (`....//` → 가운데 `../` 제거 →
     * `../`). 더 바뀌지 않을 때까지 돈다.
     *
     * **널바이트와 숨김 세그먼트도 여기서 막는다.** 이 trait 은 요청 규칙(1차층)이
     * 무력해졌을 때 남는 마지막 방어선인데, 그 두 축은 1차층에만 있었다. 서비스는
     * 확장에 열려 있어 「현재 호출부가 FormRequest 를 거친다」는 방어가 되지 못한다:
     *  - 널바이트: `file_exists()` 가 PHP 8 에서 `ValueError` 를 던져 무인증 표면이 500
     *  - 숨김 세그먼트: "dist/../../../.secrets.json" 이 정제 후 "dist/.secrets.json" 이
     *    되어 확장 트리 안의 점 파일이 확장자 화이트리스트(`json`)를 통과한다
     *
     * 형제 통로 `PublicStorageService` 는 같은 두 축을 이미 **서비스 계층**에 갖고
     * 있었다 — 한쪽에만 있는 성질이 곧 결함이다 (15차 G3 라운드).
     *
     * 거부는 빈 문자열로 표현한다. 호출부는 정제 결과를 기준 디렉토리에 이어 붙인 뒤
     * `extensionAssetPathIsContained()` 로 재확인하므로, 빈 값은 디렉토리 자신을
     * 가리켜 `is_file()` 에서 걸러진다(= 파일 없음).
     *
     * @param  string  $path  요청이 넘긴 상대 경로
     * @return string 정제된 상대 경로 (거부 시 빈 문자열)
     */
    protected function sanitizeExtensionAssetPath(string $path): string
    {
        if (str_contains($path, "\0")) {
            return '';
        }

        do {
            $previous = $path;
            $path = str_replace(['../', '..\\'], '', $path);
        } while ($path !== $previous);

        $path = ltrim($path, '/\\');

        // 분할 전에 구분자를 통일한다 — `/` 로만 나누면 `dist\.hidden/app.js` 의 숨김
        // 세그먼트를 보지 못한 채 봉쇄만 통과한다(1차층이 같은 이유로 통일한다).
        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            if ($segment !== '' && str_starts_with($segment, '.')) {
                return '';
            }
        }

        return $path;
    }

    /**
     * 해석된 절대 경로가 기준 디렉토리 안에 있는지 재확인합니다.
     *
     * 정제만으로는 심볼릭 링크를 경유해 밖을 가리키는 경로를 걸러내지 못한다.
     * 접두 비교에는 구분자를 붙인다 — 붙이지 않으면 `/base` 기준에서 형제 디렉토리
     * `/base-other/...` 가 접두 일치로 통과한다.
     *
     * @param  string  $filePath  해석 대상 절대 경로
     * @param  string  $basePath  기준 디렉토리
     * @return bool 기준 안이면 true
     */
    protected function extensionAssetPathIsContained(string $filePath, string $basePath): bool
    {
        $realFile = realpath($filePath);
        $realBase = realpath($basePath);

        if ($realFile === false || $realBase === false) {
            // 둘 중 하나라도 해석할 수 없으면 서빙하지 않는다 — 이 자리는 파일이 실재한다고
            // 판정된 **뒤**에 불리므로, 해석 실패는 정상 상태가 아니다.
            return false;
        }

        $normalizedFile = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $realFile);
        $normalizedBase = rtrim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $realBase), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return str_starts_with($normalizedFile, $normalizedBase);
    }
}
