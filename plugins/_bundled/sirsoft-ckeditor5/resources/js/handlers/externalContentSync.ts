/**
 * 편집기 ↔ 외부 폼 상태의 재동기화
 *
 * `html-editor.json` 은 `onMount` 에서만 `initEditor` 를 부른다. 그런데 관리자 화면에서
 * 글 A 수정 → 글 B 수정 으로 옮기는 이동은 **같은 레이아웃**이라 컴포넌트가 언마운트되지
 * 않는다. `onMount`/`onUnmount` 둘 다 발화하지 않으므로 편집기는 글 A 의 본문을 그대로
 * 들고 있고, 폼 상태만 글 B 의 값으로 바뀐다.
 *
 * 그 상태에서 한 글자라도 입력하면 `change:data` 가 **편집기에 보이는 글 A 의 본문**을
 * 글 B 의 `form.{name}` 에 써 넣는다. 저장하면 글 B 의 본문이 글 A 의 본문으로 통째로
 * 대체된다 — 예외도 경고도 없고, 화면에는 "원래 그런 내용이었던 글" 로 보인다.
 *
 * 이 모듈은 두 가지를 한다.
 *
 *  1. 외부(폼 데이터 로드·글 전환)에서 값이 바뀌면 편집기를 그 값으로 다시 세운다.
 *  2. 다시 세우기 전까지는 편집기가 폼으로 **내보내지 못하게** 막는다 — 재시딩보다 사용자의
 *     입력이 빠른 창에서 오염이 일어나기 때문이다.
 *
 * 타이핑 경합을 피하는 기준은 **경로(pathname)** 다. 값 비교만으로 판정하면 디바운스가
 * 반영되기 전의 이전 값이 "외부 변경" 으로 보여 입력 중인 내용을 되돌려버린다. 편집 대상이
 * 바뀌는 사건은 언제나 경로 변화를 동반하므로 그것을 재시딩 신호로 쓴다.
 *
 * @module externalContentSync
 */

import { setSyncSuppressed } from './editorInstances';

/** 외부 값 확인 주기(ms). 폼 쓰기 디바운스(300ms)보다 길게 둔다. */
const POLL_INTERVAL_MS = 400;

/**
 * 재시딩 대기 상한(ms).
 *
 * 대기 중에는 편집기가 폼으로 내보내지 못한다. 새 값이 끝내 오지 않는 화면에서 그 상태를
 * 영구히 두면 편집기가 통째로 먹통이 되므로 상한을 둔다.
 */
const RESEED_TIMEOUT_MS = 10000;

/** 편집기 인스턴스의 최소 계약 (CKEditor5) */
interface EditorLike {
    getData(): string;
    setData(value: string): void;
}

/**
 * 외부 동기화 핸들
 */
export interface ExternalContentSyncHandle {
    /**
     * 이 값을 폼에 내보내도 되는지 판정합니다.
     *
     * 편집 대상이 바뀐 뒤 아직 새 본문으로 다시 세우지 못한 창에서는 `false` — 그 창의
     * 편집기 내용은 **이전 글의 것**이므로 새 글의 폼에 써서는 안 된다.
     *
     * @return bool 내보내도 되면 true
     */
    shouldEmit(): boolean;

    /**
     * 폼으로 내보낸 값을 기록합니다 (되돌아온 값을 외부 변경으로 오인하지 않도록).
     *
     * @param html 내보낸 값
     * @return void
     */
    noteEmitted(html: string): void;

    /**
     * 감시를 멈춥니다.
     *
     * @return void
     */
    stop(): void;
}

/**
 * 편집기에 외부 폼 값 재동기화를 붙입니다.
 *
 * @param editor 편집기 인스턴스
 * @param options 폼 필드명 / 로케일 / 다국어 여부
 * @return 외부 동기화 핸들
 */
export function attachExternalContentSync(
    editor: EditorLike,
    options: { name: string; locale: string; multilingual: boolean }
): ExternalContentSyncHandle {
    const { name, locale, multilingual } = options;

    let pathAtSeed = location.pathname;
    let lastEmitted: string | null = null;
    let awaitingReseed = false;
    /** 재시딩을 기다리는 동안 편집기에 남아 있는 **이전 글**의 본문 */
    let staleData: string | null = null;
    /** 재시딩 대기 시작 시각 — 새 값이 끝내 오지 않는 경우를 위해 상한을 둔다 */
    let awaitingSince = 0;

    /**
     * 현재 폼 상태에서 이 편집기에 해당하는 값을 읽습니다.
     *
     * @return 문자열 값, 읽을 수 없으면 undefined
     */
    const readExternal = (): string | undefined => {
        const G7Core = (window as any).G7Core;
        const form = G7Core?.state?.getLocal?.()?.form;

        if (!form || !name) {
            return undefined;
        }

        const value = multilingual ? form[name]?.[locale] : form[name];

        return typeof value === 'string' ? value : undefined;
    };

    const timer = window.setInterval(() => {
        let current: string;

        try {
            current = editor.getData();
        } catch {
            // 파괴된 인스턴스 — 감시를 접는다
            window.clearInterval(timer);
            return;
        }

        if (location.pathname !== pathAtSeed) {
            // 편집 대상이 바뀌었다. 새 본문이 도착할 때까지 내보내기를 막고, 지금 편집기에
            // 남아 있는 **이전 글**의 본문을 기억해 둔다.
            pathAtSeed = location.pathname;
            lastEmitted = null;
            awaitingReseed = true;
            awaitingSince = Date.now();
            staleData = current;
        }

        const external = readExternal();

        if (external === undefined) {
            return;
        }

        if (awaitingReseed) {
            if (external === staleData) {
                // 폼이 아직 이전 글의 값을 들고 있다 — 새 데이터가 도착하지 않았다.
                // 여기서 "같으니 정상" 으로 판정하면 도착 전에 대기가 풀려, 정작 새 본문이
                // 왔을 때는 입력 중으로 오인해 영영 다시 세우지 않는다.
                if (Date.now() - awaitingSince > RESEED_TIMEOUT_MS) {
                    // 새 값이 끝내 오지 않는 화면 — 내보내기를 영구히 막아둘 수는 없다
                    awaitingReseed = false;
                    staleData = null;
                }
                return;
            }

            if (external !== current) {
                setSyncSuppressed(true);

                try {
                    editor.setData(external);
                } finally {
                    setSyncSuppressed(false);
                }
            }

            awaitingReseed = false;
            staleData = null;
            lastEmitted = external;

            return;
        }

        if (external === lastEmitted || external === current) {
            // 우리가 쓴 값이 되돌아온 것 — 외부 변경이 아니다
            lastEmitted = external;
            return;
        }

        // 경로가 그대로인데 값만 다르다 = 입력 중이라 폼이 아직 못 따라온 것.
        // 여기서 되돌리면 입력이 사라진다.
    }, POLL_INTERVAL_MS);

    return {
        shouldEmit: () => !awaitingReseed,
        noteEmitted: (html: string) => {
            lastEmitted = html;
        },
        stop: () => window.clearInterval(timer),
    };
}
