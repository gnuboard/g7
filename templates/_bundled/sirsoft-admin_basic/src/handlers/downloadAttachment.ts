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

// Logger 설정 (G7Core 초기화 전에도 동작하도록 폴백 포함)
const logger = (window as any).G7Core?.createLogger?.('Handler:DownloadAttachment') ?? {
  log: (...args: unknown[]) => console.log('[Handler:DownloadAttachment]', ...args),
  warn: (...args: unknown[]) => console.warn('[Handler:DownloadAttachment]', ...args),
  error: (...args: unknown[]) => console.error('[Handler:DownloadAttachment]', ...args),
};

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
export async function downloadAttachmentHandler(action?: any, _context?: any): Promise<void> {
  const G7Core = (window as any).G7Core;
  const { url, filename } = action?.params || {};

  if (!url) {
    logger.warn('다운로드 URL 이 없어 요청을 건너뜁니다.');
    return;
  }

  if (!G7Core?.api?.get) {
    logger.error('G7Core.api.get 이 초기화되지 않아 다운로드를 실행할 수 없습니다.');
    return;
  }

  try {
    // 코어 ApiClient 경로 → Authorization(Bearer) 헤더 자동 첨부 → 관리자 토큰이 실린다.
    const blob = await G7Core.api.get(url, { responseType: 'blob' });

    if (blob) {
      const objectUrl = URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = objectUrl;
      link.download = filename || '';
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
      URL.revokeObjectURL(objectUrl);
    }
  } catch (error) {
    logger.error('첨부파일 다운로드 실패', error);
    G7Core?.toast?.error?.(G7Core?.t?.('common.download_failed') ?? 'Download failed');
  }
}