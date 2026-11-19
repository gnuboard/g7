/**
 * copyToClipboard 핸들러
 *
 * 값을 클립보드에 넣는다. 화면이 값을 **보여 주지 않은 채로도** 복사할 수 있어야 하는
 * 자리가 있어 핸들러로 둔다 — 웹 크론 호출 주소가 그렇다(가림 상태에서 [복사] 동작).
 * 값이 화면에 없으므로 "선택해서 Ctrl+C" 라는 대안이 성립하지 않는다.
 *
 * `navigator.clipboard` 는 보안 컨텍스트(HTTPS 또는 localhost)에서만 쓸 수 있다.
 * 사내망 http 로 접속하는 관리자가 실제로 있으므로, 그 경우 숨긴 textarea +
 * `document.execCommand('copy')` 로 내려간다. 둘 다 실패하면 복사되지 않았다는
 * 사실을 호출부가 알 수 있도록 false 를 돌려준다 — 조용히 성공한 척하면 운영자가
 * 빈 클립보드를 붙여넣는다.
 */

const logger = ((window as any).G7Core?.createLogger?.('Handler:CopyToClipboard')) ?? {
    warn: (...args: unknown[]) => console.warn('[Handler:CopyToClipboard]', ...args),
    error: (...args: unknown[]) => console.error('[Handler:CopyToClipboard]', ...args),
};

/**
 * 보안 컨텍스트가 아닌 환경의 폴백 복사.
 *
 * @param text 복사할 문자열
 * @returns 복사 성공 여부
 */
function copyWithExecCommand(text: string): boolean {
    const textarea = document.createElement('textarea');

    textarea.value = text;
    // 화면 밖에 두되 `display:none` 은 쓰지 않는다 — 선택이 불가능해져 복사가 실패한다.
    textarea.setAttribute('readonly', '');
    textarea.style.position = 'fixed';
    textarea.style.top = '-1000px';
    textarea.style.opacity = '0';

    document.body.appendChild(textarea);

    try {
        textarea.select();
        textarea.setSelectionRange(0, textarea.value.length);

        return document.execCommand('copy');
    } catch (e) {
        logger.error('클립보드 폴백 복사 실패:', e);

        return false;
    } finally {
        document.body.removeChild(textarea);
    }
}

/**
 * 값을 클립보드에 복사한다.
 *
 * @param action 액션 정의 (`params.text` 에 복사할 값)
 * @param _context 액션 컨텍스트 (미사용)
 * @returns 복사 성공 여부
 */
export async function copyToClipboardHandler(
    action: any,
    _context?: any,
): Promise<boolean> {
    const text = action?.params?.text;

    if (typeof text !== 'string' || text === '') {
        logger.warn('복사할 값이 비어 있습니다');

        return false;
    }

    if (navigator.clipboard && window.isSecureContext) {
        try {
            await navigator.clipboard.writeText(text);

            return true;
        } catch (e) {
            logger.warn('클립보드 API 복사 실패 — 폴백으로 시도합니다:', e);
        }
    }

    return copyWithExecCommand(text);
}
