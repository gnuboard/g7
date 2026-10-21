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
    options: {
        name: string;
        locale: string;
        multilingual: boolean;
        /**
         * 이 편집기의 값을 폼에서 읽는 방법. 기본은 `_local.form` 이다.
         *
         * 쓰기 대상을 `_local.form` 밖으로 돌린 편집기(알림 템플릿 모달 등)는 여기서도 그
         * 경로를 읽어야 한다 — 아니면 "편집 대상이 바뀌었다" 판정이 언제나 값을 못 찾아,
         * 다른 템플릿으로 갈아탄 뒤에도 **이전 본문이 그대로 남는다**.
         */
        readExternal?: () => string | undefined;
        /**
         * 편집기에 넣은 초기값이 속한 화면의 경로. 기본은 지금 경로다.
         *
         * 편집기(또는 평문 입력칸)는 마운트 뒤 자산·생성을 기다린 다음에 선다. 그 사이 같은 레이아웃의 다른 경로로
         * 옮기면 초기값은 **앞 화면** 것인데 지금 경로를 기준으로 붙이면 「편집 대상이 바뀌었다」를 영영 모른다
         * (편집기 본체 404 로 평문 입력칸이 몇 초 뒤에 서는 동안 글 수정 → 새 글 쓰기 — 새 글에 앞 글 본문).
         */
        seedPath?: string;
        /** `seedPath` 화면에서 초기값으로 쓴 원문 — 그 화면의 「이전 글 값」으로 기억한다 */
        seedValue?: string;
    }
): ExternalContentSyncHandle {
    const { name, locale, multilingual } = options;

    let pathAtSeed = options.seedPath ?? location.pathname;
    let lastEmitted: string | null = null;
    let awaitingReseed = false;
    /**
     * 재시딩을 기다리는 동안 「아직 이전 글의 값」으로 볼 값들.
     *
     * 편집기에 보이는 값만으로는 모자란다 — 편집기는 받은 본문을 다듬어 돌려주므로(평문 → `<p>…</p>`, 서버가 정리한
     * HTML 의 공백·속성 순서) 폼에 남아 있는 이전 글의 **원문**과 글자가 다르다. 그 원문을 「새 값」으로 오인하면
     * 이전 글 본문으로 다시 세우고 대기를 끝내, 정작 새 글 값이 왔을 때 입력 중으로 보아 다시 세우지 않는다
     * (글 수정 → 새 글 쓰기에서 새 글 화면 편집기에 앞 글 본문이 남음).
     * 그래서 ① 편집기 값 ② 이전 경로에서 마지막으로 본 폼 값 ③ 마지막으로 내보낸 값을 모두 담는다.
     */
    let staleValues: Set<string> | null = null;
    /** 이전 경로에서 마지막으로 읽은 폼 값 — 경로가 바뀐 순간의 「이전 글 원문」 */
    let lastSeenExternal: string | undefined;
    /** 재시딩 대기 시작 시각 — 새 값이 끝내 오지 않는 경우를 위해 상한을 둔다 */
    let awaitingSince = 0;

    /**
     * 현재 폼 상태에서 이 편집기에 해당하는 값을 읽습니다.
     *
     * @return 문자열 값, 읽을 수 없으면 undefined
     */
    const readExternal = (): string | undefined => {
        if (options.readExternal) {
            return options.readExternal();
        }

        const G7Core = (window as any).G7Core;
        const form = G7Core?.state?.getLocal?.()?.form;

        if (!form || !name) {
            return undefined;
        }

        const value = multilingual ? form[name]?.[locale] : form[name];

        return typeof value === 'string' ? value : undefined;
    };

    lastSeenExternal = pathAtSeed !== location.pathname && options.seedValue !== undefined
        ? options.seedValue
        : readExternal();

    /**
     * 경로가 바뀌었으면 재시딩 대기로 들어갑니다.
     *
     * 감시 주기를 기다리지 않고 내보내기 판정(`shouldEmit`)에서도 부른다 — 주소가 바뀐 뒤 다음 감시 전에 편집기가
     * 값을 내면(그 사이 입력) 이전 글 본문을 실은 값이 새 화면 폼으로 나가기 때문이다.
     *
     * @param current 지금 편집기에 보이는 값
     * @return void
     */
    const enterAwaitingIfPathChanged = (current: string): void => {
        if (location.pathname === pathAtSeed) {
            return;
        }

        // 편집 대상이 바뀌었다. 새 본문이 도착할 때까지 내보내기를 막고, 「이전 글의 값」으로 볼 값들을 기억해 둔다.
        pathAtSeed = location.pathname;
        staleValues = new Set([current]);
        if (lastSeenExternal !== undefined) staleValues.add(lastSeenExternal);
        if (lastEmitted !== null) staleValues.add(lastEmitted);
        lastEmitted = null;
        awaitingReseed = true;
        awaitingSince = Date.now();
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

        enterAwaitingIfPathChanged(current);

        const external = readExternal();

        if (external === undefined) {
            // 새 화면 폼에 이 칸 값이 없다 — 내보내기를 영구히 막아둘 수는 없다(다국어 칸 하나가 빠진 경우 등)
            if (awaitingReseed && Date.now() - awaitingSince > RESEED_TIMEOUT_MS) {
                awaitingReseed = false;
                staleValues = null;
            }
            return;
        }

        if (awaitingReseed) {
            if (staleValues?.has(external)) {
                // 폼이 아직 이전 글의 값을 들고 있다 — 새 데이터가 도착하지 않았다.
                // 여기서 "같으니 정상" 으로 판정하면 도착 전에 대기가 풀려, 정작 새 본문이
                // 왔을 때는 입력 중으로 오인해 영영 다시 세우지 않는다.
                if (Date.now() - awaitingSince > RESEED_TIMEOUT_MS) {
                    // 새 값이 끝내 오지 않는 화면 — 내보내기를 영구히 막아둘 수는 없다
                    awaitingReseed = false;
                    staleValues = null;
                    lastSeenExternal = external;
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
            staleValues = null;
            lastEmitted = external;
            lastSeenExternal = external;

            return;
        }

        lastSeenExternal = external;

        if (external === lastEmitted || external === current) {
            // 우리가 쓴 값이 되돌아온 것 — 외부 변경이 아니다
            lastEmitted = external;
            return;
        }

        // 경로가 그대로인데 값만 다르다 = 입력 중이라 폼이 아직 못 따라온 것.
        // 여기서 되돌리면 입력이 사라진다.
    }, POLL_INTERVAL_MS);

    return {
        shouldEmit: () => {
            if (!awaitingReseed) {
                try {
                    enterAwaitingIfPathChanged(editor.getData());
                } catch {
                    // 파괴된 인스턴스 — 판정은 감시가 접는다
                }
            }

            return !awaitingReseed;
        },
        noteEmitted: (html: string) => {
            lastEmitted = html;
        },
        stop: () => window.clearInterval(timer),
    };
}
