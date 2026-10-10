/**
 * downloadMarkupFile 핸들러 (관리자 템플릿)
 *
 * 코드 편집기 HTML 탭의 본문(`.g7.html` 소스)을 파일로 내려받는다 (#476 C5).
 *
 * 편집기가 여는 것은 설치본(DB 행)이라 저장소 파일과 다를 수 있다(조각이 인라인된 평탄 문서).
 * 그 본문을 저장소로 옮기거나 다른 곳에서 열어 보려면 파일이 필요한데, 서버에 파일이 없으므로
 * 브라우저에서 Blob 으로 만들어 `<a download>` 로 저장한다(downloadAttachment 의 저장 관용구와 같다).
 */

// Logger 설정 (G7Core 초기화 전에도 동작하도록 폴백 포함)
const logger = (window as any).G7Core?.createLogger?.('Handler:DownloadMarkupFile') ?? {
  log: (...args: unknown[]) => console.log('[Handler:DownloadMarkupFile]', ...args),
  warn: (...args: unknown[]) => console.warn('[Handler:DownloadMarkupFile]', ...args),
  error: (...args: unknown[]) => console.error('[Handler:DownloadMarkupFile]', ...args),
};

/**
 * 파일명을 안전한 `.g7.html` 이름으로 정리합니다 — 경로 구분자는 `_` 로, 확장자는 하나만.
 *
 * @param filename 요청 파일명 (`board/form.g7.html` 등)
 * @return 저장 파일명
 */
export function normalizeMarkupFilename(filename: unknown): string {
  const base = String(filename ?? '').trim().replace(/\.g7\.html$/i, '').replace(/[\/]+/g, '_');

  return `${base || 'layout'}.g7.html`;
}

/**
 * 편집기 본문을 `.g7.html` 파일로 내려받는 핸들러
 *
 * ActionDispatcher 는 handler(action, context) 형태로 호출한다.
 * params 는 resolveParams 로 이미 표현식이 해석된 값을 받는다.
 *
 * @param action 액션 정의 (params.filename=저장 파일명, params.content=마크업 본문)
 * @param _context 액션 컨텍스트 (미사용)
 */
export async function downloadMarkupFileHandler(action?: any, _context?: any): Promise<void> {
  const { filename, content } = action?.params || {};

  if (typeof content !== 'string' || content === '') {
    logger.warn('내려받을 마크업 본문이 비어 있어 건너뜁니다.');
    return;
  }

  try {
    const blob = new Blob([content], { type: 'text/html;charset=utf-8' });
    const objectUrl = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = objectUrl;
    link.download = normalizeMarkupFilename(filename);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(objectUrl);
  } catch (error) {
    logger.error('마크업 파일 내려받기 실패', error);
    const G7Core = (window as any).G7Core;
    G7Core?.toast?.error?.(G7Core?.t?.('common.download_failed') ?? 'Download failed');
  }
}
