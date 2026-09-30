/**
 * 설정 화면의 주소를 클립보드에 담는 핸들러.
 *
 * 가상계좌 입금통보 주소는 운영자가 KG이니시스 상점관리자에 그대로 붙여 넣어야 하는 값이라,
 * 눈으로 옮겨 적지 않도록 복사 버튼을 둔다.
 *
 * 이 플러그인이 직접 소유한다 — 설정 레이아웃은 어느 관리자 템플릿으로 편집·렌더되든 같은
 * 동작을 해야 하므로 특정 템플릿의 핸들러에 기대지 않는다. 코어에는 클립보드 핸들러가 없다.
 *
 * `navigator.clipboard` 는 보안 컨텍스트(HTTPS·localhost)에서만 동작한다. 사내망을 HTTP 로
 * 쓰는 설치본이 있어 실패하면 숨긴 textarea + `execCommand('copy')` 로 내려간다.
 */

/**
 * 보안 컨텍스트가 아닐 때 쓰는 폴백.
 *
 * @param text 클립보드에 담을 문자열
 * @returns 복사에 성공했으면 true
 */
function fallbackCopy(text: string): boolean {
    const textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.setAttribute('readonly', 'readonly');
    // 화면 밖에 두되 focus 는 받을 수 있어야 select() 가 먹는다.
    textarea.style.position = 'fixed';
    textarea.style.left = '-9999px';
    textarea.style.top = '0';

    document.body.appendChild(textarea);
    textarea.select();

    try {
        return typeof document.execCommand === 'function' && document.execCommand('copy');
    } catch {
        return false;
    } finally {
        textarea.remove();
    }
}

/**
 * 레이아웃이 넘긴 텍스트를 클립보드에 담는다.
 *
 * @param action `text` 파라미터를 실은 액션 객체
 * @returns 복사에 성공했으면 true
 */
export async function copyToClipboardHandler(action: {
    params?: { text?: unknown };
}): Promise<boolean> {
    const text = String(action?.params?.text ?? '');

    if (text === '') {
        return false;
    }

    if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
        try {
            await navigator.clipboard.writeText(text);

            return true;
        } catch {
            // 권한 거부·비보안 컨텍스트 — 아래 폴백으로 내려간다.
        }
    }

    return fallbackCopy(text);
}
