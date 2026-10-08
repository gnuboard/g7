// e2e:allow 화면 언어 요청 헤더 — 외부 PG 결제 데이터에 기대는 주입기 조회라 브라우저 E2E 로 재현할 수 없다. 대체 검증: Vitest(requestLocaleHeaders) + 전용 Chrome 실측
/**
 * 화면 스크립트가 직접 보내는 조회의 언어 헤더.
 *
 * 코어 API 요청(ApiClient)은 화면 언어(g7_locale)를 Accept-Language 로 싣는데, 주입기·결제 스크립트가 `fetch` 로
 * 직접 보내는 조회는 그렇지 않아 서버가 브라우저 기본 언어로 글자(영수증 줄 이름·버튼, 결제수단 표시 이름)를
 * 만들었다 — 한국어·일본어 화면에 영어가 붙어 보였다. 같은 규칙으로 싣는다.
 *
 * @return 화면 언어가 정해져 있으면 { 'Accept-Language': 언어 }, 아니면 빈 객체(브라우저 기본값)
 */
export function localeHeaders(): Record<string, string> {
    try {
        const locale = localStorage.getItem('g7_locale');

        return locale ? { 'Accept-Language': locale } : {};
    } catch {
        return {};
    }
}
