/**
 * 백업 코드를 클립보드에 담는 핸들러.
 *
 * 백업 코드는 등록을 마친 그 화면에서 **한 번만** 보여 준다. 그 자리를 벗어나면 다시
 * 볼 수 없으므로, 옮겨 적다 놓치면 휴대폰을 잃었을 때 로그인할 길이 사라진다. 그래서
 * 눈으로 받아 적는 것 말고 한 번에 가져갈 수단을 함께 둔다.
 *
 * `navigator.clipboard` 는 보안 컨텍스트(HTTPS·localhost)에서만 동작한다. 사내망을
 * HTTP 로 쓰는 설치본이 있어 실패하면 숨긴 textarea + `execCommand('copy')` 로 내려간다
 * — 형제 플러그인(sirsoft-pay_nhnkcp)이 같은 이유로 같은 폴백을 둔다.
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
 * 성공 여부를 돌려주긴 하지만 현재 레이아웃 문법(`<sequence>` 안의 `<action/>`)은 그 값으로
 * 분기하지 못한다 — 형제 플러그인도 같은 한계 아래 성공 토스트만 띄운다. 그래도 반환값을
 * 두는 것은 테스트가 폴백 동작을 단언할 수 있어야 하기 때문이다.
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
