<?php

namespace App\Support\LayoutMarkup;

use Illuminate\Support\Facades\File;
use Tests\Feature\LayoutMarkup\LayoutDecompileSyncTest;

/**
 * 컴파일 산출 JSON 을 짝 `.json` 으로 **굽는** 직렬화기 (S18.5 축 A — 쌍둥이 기계화).
 *
 * ## 왜 이 클래스가 있는가
 *
 * S10 이후 소스 SSoT 는 `.g7.html` 이고 설치는 그것을 인메모리로 컴파일해 DB 에 넣는다. 그런데
 * 짝 `.json` 은 **손유지**로 남아 있었다 — 소스를 고치면 사람이 `.json` 도 같이 고쳐야 했고,
 * 그 동기 의무를 강제하는 것은 코퍼스 게이트의 사후 red 하나뿐이었다(고치는 방법은 알려 주지
 * 않는다). 이 클래스가 그 손유지를 굽기로 바꾼다: `.json` 은 이제 **기계 산출물**이고 소스가
 * 유일한 편집 표면이다.
 *
 * ## 호출 주체는 개발자뿐이다 (D38)
 *
 * 설치·서빙 경로는 이 클래스를 부르지 않는다. 설치는 소스를 **인메모리로** 컴파일해 DB 에
 * 등록하고 디스크에 쓰지 않는다는 것이 S10 재아키텍처의 결정이며, 굽기가 설치에 끼어들면
 * 프로덕션 배포가 저장소 파일을 수정하게 된다. 이 경계는
 * {@see LayoutDecompileSyncTest::test_install_and_serving_paths_do_not_bake_json()}
 * 이 코어 전량 스캔으로 잠근다.
 *
 * ## 직렬화 관례 (S18.5 착수 실측으로 확정)
 *
 * `JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES` + 끝 개행 1 · 개행 LF.
 *
 * 착수 실측: 코퍼스 634 의 실물 들여쓰기는 2-space 514 · 4-space 120 으로 갈려 있었고, 그
 * **4-space 120 은 정확히 (지금은 삭제된) `layout:add-permissions` 가 `JSON_PRETTY_PRINT` 로 이미 다시 쓴 최상위
 * 레이아웃들**이다(그 커맨드는 `partials/` 를 건너뛴다). 즉 이 저장소에서 "기계가 쓴 레이아웃
 * JSON" 의 선례는 4-space 이고, 2-space 는 손 작성 잔재다. 첫 churn 은 2-space 를 택하면 근사
 * 변경 줄이 431,583 → 181,543 으로 줄지만 그 이득은 **일회성**인 반면 사용자 정의 들여쓰기는
 * 산출물이 기계 소유가 된 뒤에도 영구히 유지해야 하는 사본이므로, 표준 방출기를 그대로 쓴다.
 *
 * 키 순서는 **컴파일 산출 그대로**다. {@see DocumentCompiler} 의 `KEY_ORDER` 가 이미 정렬해서
 * 내보내므로 여기서 다시 정렬하지 않는다(그 메서드는 `private` 이라 부를 수도 없다 — 38차 정정).
 *
 * ## 개행이 LF 여야 하는 이유
 *
 * Windows 에서 CRLF 가 섞이면 문서 인덱스 생성기가 제목을 basename 으로 퇴화시키는 사고가 이미
 * 있었다(㊼ N4). `json_encode` 는 CR 을 방출하지 않고 `File::put` 은 바이트를 그대로 쓰므로
 * 산출은 구조적으로 LF 지만, 그 성질에 기대지 않고 {@see assertLineFeedOnly()} 로 확인한다.
 */
final class LayoutJsonWriter
{
    /**
     * 굽기 인코딩 플래그 — 이 상수가 관례의 정본이다.
     *
     * 값을 두 곳에 적지 않는다(34차 규율). `--out` 덤프도 이 상수를 쓴다.
     */
    public const ENCODE_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    /**
     * 컴파일 산출을 짝 `.json` 파일 내용으로 직렬화합니다.
     *
     * @param  array<string, mixed>  $compiled  컴파일 산출 JSON
     * @return string 파일에 쓸 내용 (끝 개행 1 포함)
     */
    public static function encode(array $compiled): string
    {
        return json_encode($compiled, self::ENCODE_FLAGS)."\n";
    }

    /**
     * `.g7.html` 소스 경로에 대응하는 짝 `.json` 경로를 돌려줍니다.
     *
     * @param  string  $markupFile  `.g7.html` 경로
     * @return string 짝 `.json` 경로
     */
    public static function jsonSiblingOf(string $markupFile): string
    {
        return (string) preg_replace('/\.g7\.html$/i', '.json', $markupFile);
    }

    /**
     * 짝 `.json` 이 컴파일 산출과 일치하는지 판정합니다 (`--check` 의 술어).
     *
     * **바이트 비교**다. 의미 비교(정규화 deep-equal)로 하면 키 순서·들여쓰기가 어긋난 채로
     * 통과해 굽기의 목적인 "기계 산출과 디스크가 같다" 를 증명하지 못한다.
     *
     * @param  string  $jsonFile  짝 `.json` 경로
     * @param  array<string, mixed>  $compiled  컴파일 산출 JSON
     * @return bool 파일이 없으면 false
     */
    public static function isUpToDate(string $jsonFile, array $compiled): bool
    {
        return File::exists($jsonFile) && File::get($jsonFile) === self::encode($compiled);
    }

    /**
     * 짝 `.json` 을 컴파일 산출로 굽습니다 (내용이 이미 같으면 쓰지 않습니다).
     *
     * 같을 때 쓰지 않는 이유는 mtime 때문이다 — 조각 컴파일 캐시가 `경로|mtime|크기` 로 키를
     * 잡으므로(34차 ⓔ), 내용이 같은데 mtime 만 바뀌면 캐시가 통째로 무효화된다.
     *
     * @param  string  $jsonFile  짝 `.json` 경로
     * @param  array<string, mixed>  $compiled  컴파일 산출 JSON
     * @return string `'created'`(짝 신규 생성) · `'updated'`(내용 변경) · `'unchanged'`
     */
    public static function write(string $jsonFile, array $compiled): string
    {
        $contents = self::encode($compiled);
        $exists = File::exists($jsonFile);

        if ($exists && File::get($jsonFile) === $contents) {
            return 'unchanged';
        }

        File::ensureDirectoryExists(dirname($jsonFile));
        File::put($jsonFile, $contents);

        return $exists ? 'updated' : 'created';
    }

    /**
     * 직렬화 산출에 CR 이 섞이지 않았는지 확인합니다.
     *
     * @param  string  $contents  직렬화 산출
     * @return bool CR 이 하나도 없으면 true
     */
    public static function assertLineFeedOnly(string $contents): bool
    {
        return ! str_contains($contents, "\r");
    }
}
