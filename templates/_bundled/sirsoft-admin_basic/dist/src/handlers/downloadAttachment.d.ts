/**
 * downloadAttachment 핸들러 (관리자 템플릿)
 *
 * 관리자 게시글/답변 첨부파일을 토큰 동반 요청으로 다운로드한다 (이슈 #413 item 58b).
 *
 * 배경: 관리자 게시글/답변 첨부 카드도 사용자 라우트(optional.sanctum)의 download_url 을
 * <a href> 직접 링크로 호출했다. <a> 네비게이션에는 Authorization 헤더(토큰)가 실리지 않아
 * 요청이 guest 로 통과 → 서버의 Auth::id() 가 NULL → 활동이력(attachment.download)의
 * 행위자(user_id)가 비어 있었다(사용자 카드와 동일 원인).
 *
 * 해소: 코어 ApiClient(G7Core.api.get)로 blob 요청을 보내면 Authorization 헤더가 자동 첨부되어
 * 관리자 회원 ID 가 활동이력에 정상 기록된다. 받은 blob 을 objectURL 로 변환해 <a download>
 * 클릭으로 저장한다(composite/ImageGallery.tsx 의 downloadAuthenticatedFile 과 동일 패턴).
 */
/**
 * 첨부파일을 토큰 동반 요청으로 다운로드하는 핸들러
 *
 * ActionDispatcher 는 handler(action, context) 형태로 호출한다.
 * params 는 resolveParams 로 이미 표현식이 해석된 값을 받는다.
 *
 * @param action 액션 정의 (params.url=다운로드 URL, params.filename=저장 파일명)
 * @param _context 액션 컨텍스트 (미사용)
 * @return Promise<void>
 */
export declare function downloadAttachmentHandler(action?: any, _context?: any): Promise<void>;
