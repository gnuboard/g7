/**
 * 비밀글 열람 확인 토큰 요청 헤더 (sirsoft-basic 공용)
 *
 * 레이아웃의 globalHeaders 배선은 데이터소스(DataSourceManager)와 apiCall 핸들러
 * (ActionDispatcher)에만 적용된다. 코어 ApiClient(G7Core.api)를 직접 호출하는 경로는
 * 그 배선을 타지 않아 Authorization·Accept-Language 만 실린다.
 *
 * 그래서 비밀번호로 원문을 연 사용자가 첨부파일을 받으려 하면, 화면은 다운로드 버튼을
 * 내주는데(서버가 can_download=true 를 돌려준다) 실제 요청은 열람 사실을 증명하지 못해
 * 403 으로 거부된다. 예외도 콘솔 오류도 남지 않고 다운로드만 조용히 실패한다.
 *
 * 직접 호출 경로가 늘어날 때 같은 결함이 재발하지 않도록, 그런 호출부는 이 한 곳을 통해
 * 헤더를 만든다.
 */

/**
 * 서버(SecretContentGate)가 읽는 열람 확인 토큰 헤더 이름.
 */
export const SECRET_VIEW_TOKEN_HEADER = 'X-Board-Secret-View-Token';

/**
 * 현재 전역 상태에 열람 확인 토큰이 있으면 요청 헤더 객체로 만들어 돌려줍니다.
 *
 * 토큰은 비밀번호 검증 응답으로만 발급되고 게시글 단위로 결속되므로, 무관한 요청에
 * 실려도 서버 판정에 영향을 주지 않습니다.
 *
 * @return 토큰이 있으면 헤더 1개짜리 객체, 없으면 빈 객체
 */
export function secretContentHeaders(): Record<string, string> {
  const token = (window as any).G7Core?.state?.get?.('_global')?.secretViewToken;

  return typeof token === 'string' && token !== ''
    ? { [SECRET_VIEW_TOKEN_HEADER]: token }
    : {};
}
