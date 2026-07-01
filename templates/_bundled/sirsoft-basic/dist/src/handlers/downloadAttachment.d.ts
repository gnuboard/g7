/**
 * downloadAttachment 핸들러
 *
 * 게시판 첨부파일을 토큰 동반 요청으로 다운로드한다 (이슈 #413 item 58b).
 *
 * 배경: 기존 다운로드는 레이아웃의 <a href="{{download_url}}"> 브라우저 직접 링크(GET)였다.
 * G7 은 Sanctum 토큰 전용 인증인데 <a> 네비게이션에는 Authorization 헤더(토큰)가 실리지 않아,
 * download_url 이 가리키는 optional.sanctum 라우트가 요청을 guest 로 통과시켰다.
 * 그 결과 회원이 받아도 서버의 Auth::id() 가 NULL 이 되어 활동이력(attachment.download)의
 * 행위자(user_id)가 비어 있었다(upload/board.update 는 정상이었다).
 *
 * 해소: 코어 ApiClient(G7Core.api.get)로 blob 요청을 보내면 Authorization 헤더가 자동 첨부되어
 * 토큰이 실린다. 받은 blob 을 objectURL 로 변환해 <a download> 클릭으로 저장한다
 * (composite/ImageGallery.tsx 의 downloadAuthenticatedFile 과 동일 패턴).
 *
 * 비회원: 토큰이 없으므로 종전과 동일하게 user_id 가 NULL 로 남는다(현행 정책 유지).
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
