/**
 * 서버가 준 사이트 주소(절대 URL)를 브라우저 요청 경로로 바꾸는 도우미.
 *
 * 서버는 결과·보고·서명 주소를 사이트 주소 기준 절대 URL(SiteUrl::absolute)로 준다. G7Core.api 는 받은 경로
 * 앞에 `/api` 를 붙이고 절대 URL 이면 그대로 보내므로, 절대 URL 을 그대로 넘기면 `/plugins/...`(접두 없음)로
 * 나가 404 가 난다. 같은 출처 주소만 경로로 줄인다 — 다른 출처로 회원 인증 헤더를 보내지 않기 위해서다.
 */

/**
 * 같은 출처 절대 URL 을 경로로 줄입니다.
 *
 * @param url 서버가 준 주소
 * @returns 같은 출처면 경로(+쿼리), 아니면 원래 주소
 */
export function toSameOriginPath(url: string): string {
    if (!url.startsWith('http://') && !url.startsWith('https://')) {
        return url;
    }

    try {
        const parsed = new URL(url);
        return parsed.origin === window.location.origin ? parsed.pathname + parsed.search : url;
    } catch {
        return url;
    }
}

/**
 * G7Core.api 에 넘길 경로를 만듭니다.
 *
 * @param url 서버가 준 주소
 * @returns G7Core.api 용 경로(같은 출처면 경로, 다른 출처면 원래 주소). `/api` 접두는 G7Core.api 가 스스로 처리한다
 */
export function toApiClientPath(url: string): string {
    return toSameOriginPath(url);
}
