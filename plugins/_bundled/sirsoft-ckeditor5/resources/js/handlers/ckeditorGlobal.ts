/**
 * CKEditor5 본체 전역 확보 — 한 화면에서 본체(UMD)를 한 번만 실행한다
 *
 * 레이아웃 `scripts[]`(`resources/extensions/html-editor.json`)가 본체를 싣지만, 그 로드가 끝내 실패했을 수 있다.
 * 편집기 초기화는 이 모듈로 전역을 확인하고, 없으면 한 번 더 싣는다.
 *
 * 본체는 두 번 실행되면 안 된다. UMD 는 실행 첫머리에 `window.CKEDITOR = {}` 를 대입한 뒤 내용을 채우고,
 * 이미 실행된 적이 있으면(`CKEDITOR_VERSION`) 중복 모듈 오류로 멈춘다. 그래서 두 번째 실행은 첫 번째가
 * 채운 전역을 빈 객체로 덮고 끝나며, 그 뒤 모든 편집기 생성이 실패한다. 다른 로더(레이아웃 스크립트,
 * 레이아웃 편집기 미리보기)가 싣는 중인 본체가 있으면 새로 싣지 않고 그 실행을 기다린다.
 *
 * @module ckeditorGlobal
 */

import { ckeditorScriptUrl } from './ckeditorAssets';

/** 플러그인이 본체를 직접 실을 때 붙이는 id */
const VENDOR_SCRIPT_ID = 'ckeditor5-vendor-umd';

/** 다른 로더가 싣는 중인 본체를 기다리는 최대 시간(ms) — 넘겨도 그 로더가 아직 싣는 중이면 다시 싣지 않는다 */
const PENDING_WAIT_MS = 15000;

/** 싣던 태그가 실패한 뒤 코어 로더의 재시도 태그를 기다리는 시간(ms) — 코어 백오프 최댓값(2초)보다 길게 */
const RETRY_GRACE_MS = 2500;

/** 기다리는 동안 전역을 다시 확인하는 간격(ms) */
const POLL_INTERVAL_MS = 50;

/** 진행 중인 확보 작업 — 동시에 들어온 초기화 요청을 하나로 합친다 */
let inflight: Promise<boolean> | null = null;

/**
 * 본체 전역을 쓸 수 있는가 — 전역의 존재가 아니라 `ClassicEditor` 의 존재로 판정한다
 * (실행 첫머리의 `window.CKEDITOR = {}` 만으로는 쓸 수 없다).
 *
 * @return bool 편집기를 만들 수 있으면 true
 */
function isReady(): boolean {
    return typeof (window as any).CKEDITOR?.ClassicEditor === 'function';
}

/**
 * 본체가 이미 실행됐는가 — 실행됐는데 쓸 수 없으면 중복 실행으로 전역이 덮인 것이다.
 *
 * @return bool 실행 흔적이 있으면 true
 */
function hasExecuted(): boolean {
    return typeof (window as any).CKEDITOR_VERSION === 'string';
}

/**
 * 다른 로더가 DOM 에 넣은 본체 스크립트 태그를 찾는다 — 주소 형태(확장자·`?file=`)와 id 를 가리지 않는다.
 * 번역 파일(`translations/{locale}.umd.js`)은 본체가 아니다.
 *
 * @return HTMLScriptElement[] 본체 태그 목록
 */
function findUmdScripts(): HTMLScriptElement[] {
    return Array.from(document.querySelectorAll('script[src]')).filter((el) =>
        (el.getAttribute('src') ?? '').toLowerCase().includes('ckeditor5.umd.js')
    ) as HTMLScriptElement[];
}

/** 기다림의 결과 — 쓸 수 있음 / 싣던 태그가 모두 실패하고 재시도도 오지 않음 / 아직 싣는 중인데 시간이 지남 */
type PendingOutcome = 'ready' | 'failed' | 'timeout';

/**
 * 싣는 중인 본체의 실행을 기다린다.
 *
 * 다른 로더가 실은 태그가 실패해도 곧바로 「실패」로 보지 않는다. 코어 로더(loadScriptWithRetry)는 실패한 태그를
 * 지우고 백오프(최대 2초) 뒤 새 태그로 다시 싣기 때문에, 여기서 바로 직접 실으면 그 재시도와 겹쳐 본체가 두 번
 * 실행된다. 그래서 새로 나타나는 본체 태그를 계속 지켜보고, 살아 있는 태그 없이 재시도 유예가 지나야 「실패」다.
 * 이미 끝난 태그는 이벤트가 다시 오지 않으므로 전역을 주기적으로 확인한다.
 *
 * @param scripts 처음 찾은 본체 태그
 * @return Promise<PendingOutcome> 기다림의 결과
 */
function waitForPending(scripts: HTMLScriptElement[]): Promise<PendingOutcome> {
    return new Promise<PendingOutcome>((resolve) => {
        let settled = false;
        const tracked = new Set<HTMLScriptElement>();
        const failed = new Set<HTMLScriptElement>();
        let lastFailureAt: number | null = null;
        const startedAt = Date.now();

        const finish = (value: PendingOutcome): void => {
            if (settled) return;
            settled = true;
            clearInterval(poll);
            resolve(value);
        };
        const track = (script: HTMLScriptElement): void => {
            if (tracked.has(script)) return;
            tracked.add(script);
            script.addEventListener('load', () => {
                if (isReady()) finish('ready');
            });
            script.addEventListener('error', () => {
                failed.add(script);
                lastFailureAt = Date.now();
            });
        };
        const poll = setInterval(() => {
            if (isReady()) {
                finish('ready');
                return;
            }
            findUmdScripts().forEach(track);
            const live = findUmdScripts().some((el) => !failed.has(el));
            const now = Date.now();
            if (!live && lastFailureAt !== null && now - lastFailureAt >= RETRY_GRACE_MS) {
                finish('failed');
                return;
            }
            if (now - startedAt >= PENDING_WAIT_MS) {
                finish(live ? 'timeout' : 'failed');
            }
        }, POLL_INTERVAL_MS);

        scripts.forEach(track);
    });
}

/**
 * 본체를 직접 싣는다.
 *
 * @return Promise<bool> 전역을 쓸 수 있으면 true
 */
async function loadVendorScript(): Promise<boolean> {
    const loadScript = (window as any)?.G7Core?.asset?.loadScript;

    if (typeof loadScript !== 'function') {
        return false;
    }

    try {
        await loadScript(
            ckeditorScriptUrl(),
            { id: VENDOR_SCRIPT_ID },
            { label: 'ckeditor5 UMD' }
        );
    } catch (error) {
        console.warn('[ckeditor5] 편집기 본체를 불러오지 못했습니다:', error);

        return false;
    }

    return isReady();
}

/**
 * CKEditor 전역이 확보되었는지 확인하고, 없으면 본체를 한 번 싣습니다.
 *
 * 순서: 쓸 수 있으면 그대로 → 이미 실행됐는데 쓸 수 없으면(중복 실행으로 덮임) 다시 실어도 같은 일이
 * 생기므로 실패 → 다른 로더가 싣는 중이면 그 실행(재시도 포함)을 기다림 → 그 로더가 끝내 실패했을 때만 직접 싣고,
 * 시간이 지나도 아직 싣는 중이면 다시 싣지 않는다.
 * 동시에 들어온 요청은 진행 중인 작업 하나를 함께 기다린다.
 *
 * @return Promise<bool> 전역이 확보되면 true
 */
export function ensureCkeditorGlobal(): Promise<boolean> {
    if (isReady()) {
        return Promise.resolve(true);
    }

    if (inflight) {
        return inflight;
    }

    inflight = (async (): Promise<boolean> => {
        if (hasExecuted()) {
            console.warn('[ckeditor5] 편집기 본체가 두 번 실행되어 전역이 비었습니다 — 다시 싣지 않습니다.');

            return false;
        }

        const pending = findUmdScripts();

        if (pending.length > 0) {
            const outcome = await waitForPending(pending);

            if (outcome === 'ready') {
                return true;
            }

            // 다른 로더가 아직 싣는 중이면 다시 싣지 않는다 — 겹치면 본체가 두 번 실행되어 전역이 비고,
            // 그 뒤로는 어떤 편집기도 만들 수 없다. 이 편집기는 임시 입력창으로 내려간다.
            if (outcome === 'timeout' || hasExecuted()) {
                return false;
            }
        }

        return loadVendorScript();
    })().finally(() => {
        inflight = null;
    });

    return inflight;
}
