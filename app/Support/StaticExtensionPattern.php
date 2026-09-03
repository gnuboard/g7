<?php

namespace App\Support;

use App\Rules\AllowedModuleFileType;
use App\Rules\AllowedPluginFileType;
use App\Rules\AllowedTemplateFileType;

/**
 * SPA catch-all 라우트의 정적 확장자 제외 패턴 (#122).
 *
 * catch-all 은 등록되지 않은 경로에 SPA 셸 HTML 을 돌려준다. 그런데 **정적 자산 요청**이
 * 거기 걸리면 존재하지 않는 `.mjs` 가 `Content-Type: text/html` 인 200 을 받고, 브라우저는
 * 그것을 스크립트로 파싱하다 죽는다. `onerror` 는 발화하지 않으므로(응답은 성공이다)
 * 태그 복구기도 뜨지 않는다 — 예외도 404 도 없이 화면만 조용히 비는 결함이다.
 *
 * 제외 목록을 라우트 파일에 손으로 적어 두면 에셋 서빙이 허용하는 확장자와 갈라진다.
 * 실제로 갈라져 있었다 — `mjs` · `webp` · `otf` 세 가지가 서빙은 되는데 제외 목록에는
 * 없었다. `.json` 이 무사했던 것은 lookahead 에 끝 앵커가 없어 `.js` 에 **부분일치**한
 * 우연이었을 뿐이다. 그래서 목록을 손으로 두지 않고 에셋 서빙 화이트리스트에서 **파생**시켜
 * 우연을 제거한다.
 *
 * 모집단은 세 화이트리스트의 **합집합**이다. 게시 트리(`public/build/ext/{v}/`)에는
 * 템플릿 dist 뿐 아니라 모듈·플러그인의 사용자 추가 에셋도 함께 실리므로, 한 종류만
 * 기준으로 삼으면 나머지가 서빙하는 확장자가 다시 빠진다(`ico` 가 그 경우다 — 모듈·
 * 플러그인은 허용하고 템플릿은 허용하지 않는다).
 *
 * 라우트 캐시 안전: `where()` 인자는 **정의 시점**에 평가되어 컴파일된 정규식으로 캐시에
 * 박히므로, 캐시 로드 경로는 이 클래스를 참조하지 않는다. 라우트 파일에 전역 함수를
 * 선언하지도 않는다 (CLAUDE.md "라우트 캐시 안전성").
 *
 * 환경 의존 게터(`getAllowedExtensions()`)를 쓰지 않는 이유도 같다 — 그것은 로컬에서
 * 소스맵(`map`)을 덧붙이므로, 패턴이 **캐시를 구운 환경**에 따라 달라진다.
 */
final class StaticExtensionPattern
{
    /**
     * SPA catch-all `where()` 에 넣을 제외 lookahead 를 반환합니다.
     *
     * 끝 앵커(`$`)를 붙이지 않는다 — 쿼리스트링이 아닌 경로 뒤 세그먼트가 붙은 형태
     * (`/a.js/b`)까지 함께 제외하는 종전 동작을 유지하기 위함이며, 앵커를 붙이면
     * 정적 미스가 SPA 풀 렌더를 경유해 조용히 비싸진다.
     *
     * @return string `(?!.*\.(js|css|…)).*` 형태의 라우트 패턴 조각
     */
    public static function catchAllExclusion(): string
    {
        return '(?!.*\.('.implode('|', self::servedExtensions()).')).*';
    }

    /**
     * 정적으로 서빙될 수 있는 확장자 전체(세 화이트리스트 합집합)를 반환합니다.
     *
     * @return array<int, string> 중복 없는 확장자 목록
     */
    public static function servedExtensions(): array
    {
        return array_values(array_unique([
            ...AllowedTemplateFileType::allowedExtensions(),
            ...AllowedModuleFileType::allowedExtensions(),
            ...AllowedPluginFileType::allowedExtensions(),
        ]));
    }
}
