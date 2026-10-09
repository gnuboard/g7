/**
 * 사이트 기본 언어 조회
 *
 * 관리자 다국어 입력(MultilingualInput·HtmlEditor·MultilingualTagInput)이 어느 언어 칸을 필수(*)로
 * 볼지 정한다. 서버 다국어 필수 규칙(LocaleRequiredTranslatable)은 요청 언어가 아니라 사이트 기본
 * 언어(`app.site_locale`)를 필수로 보므로, 화면도 보는 사람의 언어가 아니라 같은 값을 써야 한다 —
 * 그렇지 않으면 영어로 보는 관리자가 EN 칸만 채우고 저장해 422 를 받는다.
 *
 * 출처: 전역 상태 `appConfig.siteLocale`(서버 `config/frontend.php` 의 app_config) → 부팅 전에는
 * `window.G7Config.appConfig.siteLocale`. 없거나 지원 언어 밖이면 첫 지원 언어로 — 서버 규칙의 폴백과 같다.
 *
 * @returns 사이트 기본 언어 코드
 */
export declare function getSiteLocale(): string;
