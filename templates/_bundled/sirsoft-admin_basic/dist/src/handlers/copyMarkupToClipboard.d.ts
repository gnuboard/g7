/**
 * copyMarkupToClipboard 핸들러 (관리자 템플릿)
 *
 * 코드 편집기 HTML 탭의 본문(`.g7.html` 소스)을 클립보드에 복사한다 (#476 C5).
 *
 * `navigator.clipboard.writeText` 를 먼저 쓰고, 보안 컨텍스트가 아니거나 권한이 없어 실패하면
 * 숨긴 textarea + `document.execCommand('copy')` 로 폴백한다 — 사내 HTTP 환경에서도 복사가 되어야 한다.
 */
/**
 * 편집기 본문을 클립보드에 복사하는 핸들러
 *
 * @param action 액션 정의 (params.content=마크업 본문, params.successMessage/failureMessage=토스트 문구)
 * @param _context 액션 컨텍스트 (미사용)
 * @return 복사 성공 여부
 */
export declare function copyMarkupToClipboardHandler(action?: any, _context?: any): Promise<boolean>;
