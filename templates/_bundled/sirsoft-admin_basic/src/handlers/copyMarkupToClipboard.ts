/**
 * copyMarkupToClipboard 핸들러 (관리자 템플릿)
 *
 * 코드 편집기 HTML 탭의 본문(`.g7.html` 소스)을 클립보드에 복사한다 (#476 C5).
 *
 * `navigator.clipboard.writeText` 를 먼저 쓰고, 보안 컨텍스트가 아니거나 권한이 없어 실패하면
 * 숨긴 textarea + `document.execCommand('copy')` 로 폴백한다 — 사내 HTTP 환경에서도 복사가 되어야 한다.
 */

// Logger 설정 (G7Core 초기화 전에도 동작하도록 폴백 포함)
const logger = (window as any).G7Core?.createLogger?.('Handler:CopyMarkupToClipboard') ?? {
  log: (...args: unknown[]) => console.log('[Handler:CopyMarkupToClipboard]', ...args),
  warn: (...args: unknown[]) => console.warn('[Handler:CopyMarkupToClipboard]', ...args),
  error: (...args: unknown[]) => console.error('[Handler:CopyMarkupToClipboard]', ...args),
};

/**
 * execCommand 폴백 — 클립보드 API 가 없는 컨텍스트용.
 *
 * @param text 복사할 텍스트
 * @return 복사 성공 여부
 */
function copyViaExecCommand(text: string): boolean {
  const textarea = document.createElement('textarea');
  textarea.value = text;
  textarea.setAttribute('readonly', '');
  textarea.style.position = 'fixed';
  textarea.style.opacity = '0';
  document.body.appendChild(textarea);
  textarea.select();

  let copied = false;

  try {
    copied = typeof document.execCommand === 'function' && document.execCommand('copy');
  } finally {
    document.body.removeChild(textarea);
  }

  return copied;
}

/**
 * 편집기 본문을 클립보드에 복사하는 핸들러
 *
 * @param action 액션 정의 (params.content=마크업 본문, params.successMessage/failureMessage=토스트 문구)
 * @param _context 액션 컨텍스트 (미사용)
 * @return 복사 성공 여부
 */
export async function copyMarkupToClipboardHandler(action?: any, _context?: any): Promise<boolean> {
  const G7Core = (window as any).G7Core;
  const { content, successMessage, failureMessage } = action?.params || {};

  if (typeof content !== 'string' || content === '') {
    logger.warn('복사할 마크업 본문이 비어 있어 건너뜁니다.');
    return false;
  }

  let copied = false;

  try {
    if (navigator?.clipboard?.writeText) {
      await navigator.clipboard.writeText(content);
      copied = true;
    }
  } catch (error) {
    logger.warn('클립보드 API 복사 실패 — execCommand 폴백을 시도합니다.', error);
  }

  if (!copied) {
    try {
      copied = copyViaExecCommand(content);
    } catch (error) {
      logger.error('execCommand 복사 실패', error);
    }
  }

  if (copied) {
    G7Core?.toast?.success?.(successMessage ?? G7Core?.t?.('common.copied') ?? 'Copied');
  } else {
    G7Core?.toast?.error?.(failureMessage ?? G7Core?.t?.('common.copy_failed') ?? 'Copy failed');
  }

  return copied;
}
