<?php

namespace App\Extension\Helpers;

/**
 * 루트 `web.config` 의 코어 관리 블록(XML 주석 마커 `BEGIN G7` ~ `END G7`)만 교체하는 갱신기 (G3 회차 16 W2 C-1).
 *
 * 종전에는 이 파일이 「코어 소유, 통째 교체」였다 — 운영자가 IIS 규칙을 덧붙이면 다음 업데이트가 그것을
 * 말없이 지웠고(오류 없음), 형제 `.htaccess` 와 같은 사이트에서 두 파일이 정반대로 동작했다. XML 에서도
 * 마커 기법은 성립한다 — 블록을 `<configuration>` 의 자식 자리(`<system.webServer>` 전체)에 두면, 운영자가
 * 블록 밖에 다른 자식 요소(`<system.web>`·`<location>`)를 두어도 교체 결과가 유효한 XML 로 남는다.
 *
 * 마커는 XML 주석 한 줄이고 컬럼 0 에 있어야 한다(추상화의 줄머리 판정). 판정 로직은
 * `AbstractRootMarkerFileUpdater` 가 소유한다 — 형제 `.htaccess` 갱신기와 같은 케이스 표를 지난다.
 */
class RootWebConfigUpdater extends AbstractRootMarkerFileUpdater
{
    /**
     * 코어 관리 블록 시작 마커 (XML 주석 한 줄, 컬럼 0).
     */
    public const BEGIN_MARKER = '<!-- BEGIN G7 -->';

    /**
     * 코어 관리 블록 종료 마커.
     */
    public const END_MARKER = '<!-- END G7 -->';

    protected const LOG_TAG = 'root-web-config';

    protected const MESSAGE_KEY_PREFIX = 'root_web_config_';

    /**
     * IIS 관리 도구가 web.config 를 저장하며 자식 요소를 들여쓰므로, 마커에 선행 공백을 허용한다.
     *
     * XML 주석은 중첩할 수 없어(`<!-- ... -->` 안에 `<!--` 를 둘 수 없다) 선행 공백을 허용해도
     * 블록 경계가 모호해지지 않는다. `.htaccess`(형제)는 이 기본값(불허)을 그대로 쓴다.
     *
     * @return bool 언제나 true
     */
    protected static function markerAllowsLeadingWhitespace(): bool
    {
        return true;
    }

    /**
     * 교체 결과가 적형 XML 인지 판정합니다.
     *
     * 운영자가 블록 **밖**에 닫히지 않은 태그를 두면 교체 결과가 파싱 불가가 되고, IIS 는
     * web.config 파싱에 실패하면 사이트 전체를 500 으로 만든다 — 손상본을 디스크에 쓰기 전에 막는다.
     * XML 파서 함수가 막힌 환경(하드닝)에서는 판정할 수 없으므로 통과시킨다(가드 없는 호출은
     * 치명 종료다) — 그 경우 종전과 같은 동작(무검사)이다.
     *
     * @param  string  $content  교체가 끝난 파일 전체 내용
     * @return bool 적형이면 true (판정 불가 시에도 true)
     */
    protected static function validateSpliced(string $content): bool
    {
        // 검증할 XML 요소(`<태그`)가 하나도 없으면 판정 대상이 아니다 — 실제 web.config 는 언제나
        // `<configuration>` 등 요소를 담으므로, 요소가 없는 내용은 web.config 가 아니다. `<!--`
        // (주석)·`<?`(선언)는 `<[A-Za-z]` 가 아니라 매치되지 않는다.
        if (preg_match('/<[A-Za-z]/', $content) !== 1) {
            return true;
        }

        if (! function_exists('simplexml_load_string') || ! function_exists('libxml_use_internal_errors')) {
            return true;
        }

        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($content);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $xml !== false;
    }
}
