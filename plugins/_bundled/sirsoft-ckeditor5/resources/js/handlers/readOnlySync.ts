/**
 * 편집기 읽기 전용 상태를 레이아웃 값 변화에 맞춰 따라가게 합니다.
 *
 * `html-editor.json` 은 `onMount` 에서만 `initEditor` 를 부르므로 편집기는 처음 그려질 때의
 * `readOnly` 값으로 굳었다. 권한 판정처럼 로드 뒤에 바뀌는 값(예: 작성 화면의 등록 권한 확인,
 * 게시판 글 수정의 `form_meta` 도착)이 늦게 오면, 권한 있는 관리자에게도 잠긴 편집기가 남거나
 * 반대로 읽기 전용이어야 할 사람에게 입력 가능한 편집기가 남았다(7.1.0 전수 실측 O-F1-2).
 *
 * 레이아웃 조각은 같은 읽기 전용 식으로 컨테이너의 상태 표식 `data-readonly="true|false"` 를
 * 바꾼다. 엔진은 상태가 바뀌면 그 속성을 다시 그리므로, 여기서는 속성 변화를 지켜보다가
 * 편집기(와 평문 폴백)의 읽기 전용을 맞춘다. 표식이 없으면(예전 조각) 초기화 때 받은 값을
 * 그대로 쓴다. 표식은 스타일이 아니라 상태이므로 클래스로 두지 않는다 — 클래스로 두면 템플릿
 * CSS 어휘·safelist 검사에서 스타일 없는 이름으로 잡힌다.
 *
 * @module readOnlySync
 */

/** 컨테이너의 읽기 전용 상태 표식 속성 (값: 'true' 읽기 전용 / 'false' 입력 가능) */
export const READONLY_ATTR = 'data-readonly';

/** CKEditor 읽기 전용 잠금 식별자 (initEditor 가 쓰는 것과 같다) */
export const READONLY_LOCK_ID = 'read-only';

/** 편집기 인스턴스의 최소 계약 (CKEditor5) */
interface ReadOnlyCapableEditor {
    enableReadOnlyMode?(lockId: string): void;
    disableReadOnlyMode?(lockId: string): void;
    isReadOnly?: boolean;
}

/**
 * 컨테이너 상태 표식으로 지금의 읽기 전용 여부를 판정합니다.
 *
 * @param container 편집기 컨테이너
 * @param fallback 표식이 없거나 'true'/'false' 가 아닐 때 쓸 값 (초기화 파라미터)
 * @return 읽기 전용이면 true
 */
export function readOnlyFromContainer(container: HTMLElement, fallback: boolean): boolean {
    const marker = container.getAttribute(READONLY_ATTR);

    if (marker === 'true') {
        return true;
    }

    if (marker === 'false') {
        return false;
    }

    return fallback;
}

/**
 * 편집기 하나의 읽기 전용 상태를 맞춥니다.
 *
 * @param editor 편집기 인스턴스
 * @param readOnly 읽기 전용 여부
 * @return void
 */
export function applyEditorReadOnly(editor: unknown, readOnly: boolean): void {
    const target = editor as ReadOnlyCapableEditor | null;

    if (!target || typeof target.enableReadOnlyMode !== 'function' || typeof target.disableReadOnlyMode !== 'function') {
        return;
    }

    if (readOnly) {
        target.enableReadOnlyMode(READONLY_LOCK_ID);
    } else {
        target.disableReadOnlyMode(READONLY_LOCK_ID);
    }
}

/**
 * 컨테이너 안 평문 폴백(편집기를 못 불러왔을 때의 textarea)의 읽기 전용을 맞춥니다.
 *
 * @param container 편집기 컨테이너
 * @param readOnly 읽기 전용 여부
 * @return void
 */
function applyFallbackReadOnly(container: HTMLElement, readOnly: boolean): void {
    container.querySelectorAll('textarea').forEach((textarea) => {
        textarea.readOnly = readOnly;
    });
}

/**
 * 컨테이너 상태 표식 변화를 지켜보며 편집기 읽기 전용을 맞춥니다.
 *
 * 붙이는 즉시 한 번 맞춘다 — 편집기를 만드는 동안(비동기) 값이 이미 바뀌었을 수 있다.
 *
 * @param container 편집기 컨테이너
 * @param getEditors 지금 컨테이너에 붙은 편집기 목록을 돌려주는 함수
 * @param fallback 표식이 없을 때 쓸 값 (초기화 파라미터)
 * @return 감시를 멈추는 핸들
 */
export function attachReadOnlySync(
    container: HTMLElement,
    getEditors: () => Iterable<unknown>,
    fallback: boolean
): { stop(): void } {
    let last: boolean | null = null;

    const sync = (): void => {
        const readOnly = readOnlyFromContainer(container, fallback);

        if (readOnly === last) {
            return;
        }

        last = readOnly;

        for (const editor of getEditors()) {
            applyEditorReadOnly(editor, readOnly);
        }

        applyFallbackReadOnly(container, readOnly);
    };

    sync();

    if (typeof MutationObserver === 'undefined') {
        return { stop: () => {} };
    }

    const observer = new MutationObserver(sync);
    observer.observe(container, { attributes: true, attributeFilter: [READONLY_ATTR] });

    return {
        stop: () => observer.disconnect(),
    };
}
