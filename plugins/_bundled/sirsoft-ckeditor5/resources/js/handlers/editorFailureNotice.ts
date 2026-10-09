/**
 * 편집기 자산 실패 안내
 *
 * 편집기 본체는 조각(`resources/extensions/html-editor.g7.html`)의 `<script id="ckeditor5_script">` 로 실린다.
 * 끝내 못 불러오면 코어 레이아웃 스크립트 로더가 `layout-script:ckeditor5_script` 로 실패를 따로 알리고,
 * 플러그인도 임시 입력창으로 내려가며 `ckeditor5-editor:{컨테이너}` 로 알린다. 두 건이 겹치면 배너가
 * 「2개 항목을 불러오지 못했습니다. 편집기, 편집기」 묶음 문구가 되고 플러그인의 안내 문장(임시 입력창 전환,
 * 작성 내용 보존)이 사라진다. 플러그인 안내의 재시도가 같은 본체를 다시 확보하므로 코어 쪽 안내는 걷는다.
 *
 * @module editorFailureNotice
 */

/** 편집기 본체 실패 안내 id 접두 — 컨테이너마다 한 건 */
const EDITOR_FAILURE_PREFIX = 'ckeditor5-editor:';

/** 같은 본체 스크립트에 대해 코어 레이아웃 스크립트 로더가 내는 실패 안내 id (스크립트 id 는 조각과 같다) */
const CORE_SCRIPT_FAILURE_ID = 'layout-script:ckeditor5_script';

/** 코어 안내가 플러그인 안내보다 늦게 올 수 있어 다시 살피는 간격(ms)과 최대 시간(ms) */
const CORE_DUPLICATE_SWEEP_MS = 500;
const CORE_DUPLICATE_SWEEP_LIMIT_MS = 30000;

/** 코어 중복 안내를 걷는 타이머 */
let coreDuplicateSweep: ReturnType<typeof setInterval> | null = null;

/**
 * 코어 중복 안내 감시를 멈춥니다.
 *
 * @return void
 */
function stopCoreDuplicateSweep(): void {
    if (coreDuplicateSweep !== null) {
        clearInterval(coreDuplicateSweep);
        coreDuplicateSweep = null;
    }
}

/**
 * 코어가 같은 본체 스크립트에 대해 낸 실패 안내를 걷습니다(플러그인 편집기 안내가 떠 있는 동안).
 *
 * 코어 로더의 재시도가 끝나는 시점과 플러그인 대기가 끝나는 시점이 엇갈려 코어 안내가 먼저 와 있을 수도
 * 나중에 올 수도 있다. 지금 한 번 걷고, 편집기 안내가 남아 있는 동안 짧게 다시 살핀다.
 *
 * @return void
 */
function suppressCoreDuplicateNotice(): void {
    const assets = (window as any)?.G7Core?.assets;

    if (typeof assets?.clearFailure !== 'function' || typeof assets?.getFailures !== 'function') {
        return;
    }

    const failures = (): Array<{ id?: string }> => assets.getFailures() ?? [];

    const sweep = (): void => {
        if (failures().some((failure) => failure?.id === CORE_SCRIPT_FAILURE_ID)) {
            assets.clearFailure(CORE_SCRIPT_FAILURE_ID);
        }
    };

    sweep();
    stopCoreDuplicateSweep();

    const startedAt = Date.now();
    coreDuplicateSweep = setInterval(() => {
        // 편집기 안내가 모두 해제됐으면(재시도 성공·사용자가 닫음) 코어 안내는 건드리지 않는다
        const editorNoticeLive = failures().some((failure) => String(failure?.id ?? '').startsWith(EDITOR_FAILURE_PREFIX));

        if (!editorNoticeLive || Date.now() - startedAt >= CORE_DUPLICATE_SWEEP_LIMIT_MS) {
            stopCoreDuplicateSweep();

            return;
        }

        sweep();
    }, CORE_DUPLICATE_SWEEP_MS);
}

/**
 * 자산 로드 실패를 코어 안내 배너에 알립니다.
 *
 * 코어 API 가 없으면(구 코어) 콘솔 경고로 떨어진다 — 안내를 못 띄운다고 에디터
 * 초기화를 중단시키지는 않는다. 편집기 본체 실패(`ckeditor5-editor:` id)면 같은 본체에 대한
 * 코어 레이아웃 스크립트 안내를 걷는다.
 *
 * @param id 중복 누적을 막는 식별자
 * @param label 사용자에게 보일 항목명
 * @param retry 다시 시도 동작
 * @param message 사용자에게 보일 안내 문장
 * @return void
 */
export function notifyEditorAssetFailure(
    id: string,
    label: string,
    retry: () => Promise<void> | void,
    message?: string
): void {
    const notify = (window as any)?.G7Core?.assets?.notifyFailure;

    if (typeof notify === 'function') {
        notify({ id, label, retry, message });

        if (id.startsWith(EDITOR_FAILURE_PREFIX)) {
            suppressCoreDuplicateNotice();
        }

        return;
    }

    console.warn(`[ckeditor5] 자산 로드 실패: ${label}`);
}
