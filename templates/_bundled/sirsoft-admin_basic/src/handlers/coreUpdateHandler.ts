/**
 * 코어 업데이트 웹 단계 실행 핸들러 (W6 · 이슈 #609 G6)
 *
 * 서버는 **한 요청에 한 단계**만 수행한다. 그 경계가 편의가 아니라 안전 요건인 이유는
 * 파일을 교체한 프로세스가 사라진 클래스를 참조하면 fatal 이 나기 때문이다(§7 R25).
 * 그래서 진행을 이어 가는 주체는 화면이고, 이 핸들러가 그 반복 호출을 맡는다.
 *
 * 레이아웃 JSON 의 액션 체인으로 같은 일을 하려면 자기 자신을 참조하는 재귀
 * `actionRef` 가 필요한데, 이 저장소에 그 선례가 없고 반복 횟수가 데이터에 따라
 * 달라진다(업그레이드 스텝 핸드오프가 같은 단계를 여러 번 요구할 수 있다).
 * 형제 사례(`detectAssetUrlMode`)와 같은 판단으로 핸들러에 둔다.
 *
 * 상태는 `_global.coreUpdateStatus` 하나에 싣는다 — 화면이 그 한 값만 보고 W6 의 네
 * 상태를 고르므로, 진행 중 다른 곳에서 부분 갱신이 끼어들 여지가 없다.
 */

const logger = ((window as any).G7Core?.createLogger?.('Handler:CoreUpdate')) ?? {
    log: (...args: unknown[]) => console.log('[Handler:CoreUpdate]', ...args),
    warn: (...args: unknown[]) => console.warn('[Handler:CoreUpdate]', ...args),
    error: (...args: unknown[]) => console.error('[Handler:CoreUpdate]', ...args),
};

/** 진행 상태 조회 경로. */
const STATUS_URL = '/api/admin/core-update/status';

/** 착수 경로. */
const START_URL = '/api/admin/core-update/start';

/** 단계 수행 경로. */
const ADVANCE_URL = '/api/admin/core-update/advance';

/** 기록 정리 경로. */
const DISMISS_URL = '/api/admin/core-update/dismiss';

/**
 * 반복 호출 상한.
 *
 * 정상 흐름은 단계 수(12) + 되돌리기 1 이면 끝난다. 업그레이드 스텝 핸드오프가 같은
 * 단계를 다시 요구할 수 있어 여유를 두되, **무한 루프가 되지는 않게** 상한을 둔다 —
 * 서버도 같은 사유로 핸드오프 이어받기에 상한이 있다.
 */
const MAX_ADVANCES = 60;

/** 아직 더 진행할 것이 남은 상태 값. */
const ACTIVE_STATUSES = ['running', 'rolling_back'];

/**
 * 한 페이지 수명에서 **자동** 이어받기를 시도할 최대 횟수.
 *
 * 진행 중인 실행이 남아 있으면 화면이 스스로 이어 간다 — 그러지 않으면 창을 닫은
 * 운영자의 사이트가 점검 모드에 멈춘 채로 남는다(W6 「새로고침해도 이어진다」).
 * 다만 상한 도달로 멈춘 진행이 곧바로 자동 재개되면 같은 왕복이 무한히 돌므로,
 * 자동 경로에만 별도 상한을 둔다. 운영자의 [이어서 진행] 은 이 상한을 쓰지 않는다.
 *
 * 이 예산은 **이어받을 것이 실제로 있을 때만** 줄어든다 — 유휴 상태의 화면 진입은
 * 세지 않는다. 그리고 새 실행을 착수하면 0 으로 되돌아간다.
 */
const MAX_AUTO_RESUMES = 3;

/** 지금 실행에서 자동 이어받기가 돈 횟수 (착수 시 0 으로 되돌아간다). */
let autoResumeCount = 0;

/**
 * 진행 상태를 전역에 싣는다.
 *
 * @param status 서버가 돌려준 상태 페이로드
 */
function publish(status: unknown): void {
    (window as any).G7Core?.state?.setGlobal?.({ coreUpdateStatus: status });
}

/**
 * 진행 중 여부를 전역에 싣는다 (버튼 비활성·스피너 표시용).
 *
 * @param busy 진행 중 여부
 */
function publishBusy(busy: boolean): void {
    (window as any).G7Core?.state?.setGlobal?.({ coreUpdateBusy: busy });
}

/**
 * 응답 봉투에서 데이터부를 꺼낸다.
 *
 * @param response API 응답
 * @returns 데이터부 (없으면 null)
 */
function unwrap(response: any): any {
    return response?.data ?? null;
}

/**
 * 지금 진행 중인 실행이 있는지 판정한다.
 *
 * @param status 상태 페이로드
 * @returns 다음 단계 호출이 필요하면 true
 */
function isActive(status: any): boolean {
    return ACTIVE_STATUSES.includes(status?.run?.status);
}

/**
 * 오류 메시지를 사람이 읽는 한 줄로 만든다.
 *
 * @param error 예외 또는 응답 오류
 * @returns 메시지
 */
function messageOf(error: any): string {
    const fromServer = error?.response?.data?.message ?? error?.data?.message;

    if (typeof fromServer === 'string' && fromServer !== '') {
        return fromServer;
    }

    // 응답 자체가 없었던 경우(`TypeError: Failed to fetch`)는 HTTP 에러가 아니다 —
    // 브라우저가 만든 영어 원문이라 로케일과 무관하게 그대로 노출된다. 코어가 이미
    // 가진 문구로 바꾼다.
    if (isNetworkFailure(error)) {
        return (
            (window as any).G7Core?.t?.('core.errors.network_request_failed')
            ?? '요청을 보내지 못했습니다. 네트워크 연결을 확인한 뒤 다시 시도해 주세요.'
        );
    }

    return error?.message ?? String(error ?? '');
}

/**
 * 응답이 오지 않은 실패인지 판정한다.
 *
 * 상태 코드가 없고 `TypeError` 인 것이 그 형태다 — HTTP 에러라면 응답과 코드가 있다.
 *
 * @param error 예외
 * @returns 네트워크 단계에서 끊겼으면 true
 */
function isNetworkFailure(error: any): boolean {
    if (error?.response?.status || error?.status) {
        return false;
    }

    return error instanceof TypeError
        || /Failed to fetch|NetworkError|Network request failed/i.test(String(error?.message ?? ''));
}

/**
 * 상태를 다시 읽어 화면에 반영한다.
 *
 * 화면 진입 시(진행 중이던 실행을 이어받는 경우 포함)와 [업데이트 확인] 이후에
 * 호출한다.
 *
 * @returns 조회한 상태 (실패 시 null)
 */
export async function refreshCoreUpdateStatusHandler(): Promise<any> {
    const G7Core = (window as any).G7Core;

    if (!G7Core?.api?.get) {
        logger.error('G7Core.api.get 이 초기화되지 않아 상태를 읽을 수 없습니다.');

        return null;
    }

    try {
        const status = unwrap(await G7Core.api.get(STATUS_URL));
        publish(status);

        return status;
    } catch (error) {
        logger.error('코어 업데이트 상태 조회 실패', error);

        return null;
    }
}

/**
 * 남은 단계를 끝까지 수행한다.
 *
 * 한 단계가 실패하면 서버가 **되돌리기 대기** 상태로 전이하므로, 이 반복은 되돌리기가
 * 끝날 때까지 계속 돈다 — 운영자가 버튼을 한 번 더 누르게 하지 않는 것이 D10 의
 * 「조작 없이 자동」이다.
 *
 * @param initial 이미 알고 있는 상태 (없으면 먼저 조회)
 * @returns 마지막 상태
 */
async function drive(initial?: any): Promise<any> {
    const G7Core = (window as any).G7Core;
    let status = initial ?? (await refreshCoreUpdateStatusHandler());

    for (let i = 0; i < MAX_ADVANCES && isActive(status); i++) {
        try {
            status = unwrap(await G7Core.api.post(ADVANCE_URL, {}));
            publish(status);
        } catch (error) {
            // 단계 호출 자체가 실패했다(네트워크·409 점유·500). 서버의 진행 상태는
            // 그대로 남아 있으므로 마지막으로 읽은 상태를 화면에 두고 멈춘다 —
            // 운영자가 [이어서 진행] 으로 같은 지점부터 다시 부를 수 있다.
            logger.error('코어 업데이트 단계 수행 실패', error);
            G7Core?.toast?.error?.(messageOf(error));

            return await refreshCoreUpdateStatusHandler();
        }
    }

    if (isActive(status)) {
        logger.warn('반복 호출 상한에 도달했습니다 — 진행 상태는 서버에 남아 있습니다.');
        G7Core?.toast?.error?.(
            G7Core?.t?.('admin.settings.core_update.advance_limit')
            ?? '진행이 예상보다 오래 걸립니다. [이어서 진행] 을 눌러 계속하세요.',
        );
    }

    return status;
}

/**
 * 업데이트를 착수하고 끝까지 진행한다.
 *
 * 착수 선택(`action.params`)은 레이아웃이 폼 상태에서 채워 넘긴다. **여기 없는 값은
 * 서버에도 없다** — `force`·`prune`·백업 생략은 웹 동선에 존재하지 않는 선택이다(D27).
 *
 * @param action 액션 정의 (params: update_bundled_extensions · rebuild_search_index · layout_strategy · zip_file)
 */
export async function startCoreUpdateHandler(action?: any): Promise<void> {
    const G7Core = (window as any).G7Core;

    if (!G7Core?.api?.post) {
        logger.error('G7Core.api.post 이 초기화되지 않아 업데이트를 시작할 수 없습니다.');

        return;
    }

    const params = action?.params ?? {};
    const payload: Record<string, unknown> = {
        update_bundled_extensions: params.update_bundled_extensions !== false,
        rebuild_search_index: params.rebuild_search_index === true,
        layout_strategy: params.layout_strategy === 'keep' ? 'keep' : 'overwrite',
    };

    // 수동 ZIP 은 **고른 경우에만** 싣는다 — 빈 값을 보내면 서버가 그것을 지정으로
    // 읽어 자동 내려받기 경로가 사라진다 (㉯ — D18).
    if (typeof params.zip_file === 'string' && params.zip_file.trim() !== '') {
        payload.zip_file = params.zip_file.trim();
    }

    // 새 실행은 새 예산으로 시작한다 — 되돌리지 않으면 같은 화면에서 두 번째 업데이트를
    // 하는 운영자가 앞 실행이 쓴 만큼을 잃은 채 출발한다.
    autoResumeCount = 0;

    publishBusy(true);

    try {
        const status = unwrap(await G7Core.api.post(START_URL, payload));
        publish(status);

        await drive(status);
    } catch (error) {
        logger.error('코어 업데이트 착수 실패', error);
        G7Core?.toast?.error?.(messageOf(error));
        await refreshCoreUpdateStatusHandler();
    } finally {
        publishBusy(false);
    }
}

/**
 * 중단된 진행을 이어받는다.
 *
 * 브라우저를 닫았다 열었거나 한 단계가 네트워크 오류로 끊긴 뒤의 복귀 경로다.
 * 진행 지점은 서버가 갖고 있으므로 화면은 그저 다시 부르기만 하면 된다.
 */
export async function resumeCoreUpdateHandler(): Promise<void> {
    // **인자를 받지 않는다.** 디스패처는 등록된 핸들러를 부를 때 첫 인자로 **액션 정의**를
    // 넘긴다(`startCoreUpdateHandler(action)` 이 그 형태다). 여기에 「이미 읽은 상태」를
    // 받는 인자를 두면 그 액션 객체가 상태로 들어와 `isActive()` 가 언제나 거짓이 되고,
    // 운영자의 [이어서 진행] 이 **아무 일도 하지 않는다**(오류도 토스트도 없다).
    // 상태를 물려주는 경로는 아래 `driveFrom()` 이 따로 갖는다.
    await driveFrom();
}

/**
 * 이미 읽은 상태에서 이어간다.
 *
 * 디스패처가 부르는 핸들러가 아니라 **내부 호출 전용**이다 — 그래서 인자 자리에
 * 액션 객체가 들어올 일이 없다.
 *
 * @param initial 이미 알고 있는 상태 (없으면 먼저 조회한다)
 */
async function driveFrom(initial?: any): Promise<void> {
    publishBusy(true);

    try {
        await drive(initial);
    } finally {
        publishBusy(false);
    }
}

/**
 * 진행 중인 실행이 남아 있으면 **운영자 조작 없이** 이어받는다.
 *
 * 화면의 진행 블록이 마운트될 때(= 진행 중이거나 되돌리는 중인 실행을 화면이 처음
 * 그릴 때) 호출된다. 이 자동 경로가 없으면 창을 닫았다 들어온 운영자에게는 진행률만
 * 복원되고 아무도 다음 단계를 부르지 않아, **사이트가 점검 모드에 멈춘 채** [이어서
 * 진행] 을 누를 때까지 대기한다. 실패 처리(D10)의 「조작 없이 되돌린다」도 같은
 * 이유로 이 경로에 기댄다 — 되돌리기는 다음 호출이 수행하기 때문이다.
 *
 * 상한에 걸려 멈춘 진행이 곧바로 자동 재개되면 같은 왕복이 무한히 돌므로, 자동
 * 경로는 페이지 수명당 `MAX_AUTO_RESUMES` 회로 제한한다. 그 뒤로는 [이어서 진행] 이
 * 유일한 진행 수단이 되고, 그 버튼은 이 상한을 쓰지 않는다.
 */
export async function autoResumeCoreUpdateHandler(): Promise<void> {
    if ((window as any).G7Core?.state?.get?.()?.coreUpdateBusy === true) {
        // 이미 다른 호출이 몰고 있다 — 겹쳐 부르면 서버 점유(409)만 발생한다.
        return;
    }

    // 이 기능을 쓸 권한이 없는 관리자에게도 이 자리는 발사된다(화면 조건이 onMount 를
    // 막지 못한다) — 확인 없이 부르면 환경설정에 들어올 때마다 거부(403) 요청이 한 건씩
    // 쌓인다. 서버는 정상적으로 막고 있으므로 기능 문제는 아니고, 보낼 이유가 없는 요청이다.
    //
    // **권한을 아직 모르면 진행한다.** 권한 정보는 별도 요청으로 들어오므로 첫 마운트가
    // 그보다 빠를 수 있는데, 그때 막아 버리면 진행 중이던 업데이트를 이어받지 못해
    // 사이트가 점검 모드에 갇힌다 — 틀렸을 때의 대가가 양쪽에서 다르다(막는 쪽은 안전
    // 장치가 죽고, 보내는 쪽은 서버가 거부하는 요청 한 건이다).
    const abilities = (window as any).G7Core?.state?.get?.()?.settingsAbilities;

    if (abilities && abilities.can_core_update !== true) {
        return;
    }

    // **이어받을 것이 있는지 먼저 확인한다.** 화면 블록의 `if`(진행 중일 때만)는 이 발사를
    // 막지 못한다 — 조건이 거짓인 컴포넌트도 마운트되고 그 `lifecycle.onMount` 는 그대로
    // 실행된다(라이브 실측: 유휴 탭 1회 진입에 상태 조회가 2회 발생). 확인 없이 예산을
    // 먼저 쓰면 **아무 일도 없는 방문 3회로 한도가 소진돼**, 정작 업데이트가 중단됐을 때
    // 화면이 스스로 이어받지 못하고 사이트가 점검 모드에 멈춘 채 남는다 — 이 경로가
    // 막으려던 바로 그 상태다(D10 의 「조작 없이 되돌린다」도 여기에 기댄다).
    const status = await refreshCoreUpdateStatusHandler();

    if (! isActive(status)) {
        return;
    }

    if (autoResumeCount >= MAX_AUTO_RESUMES) {
        logger.warn('자동 이어받기 상한에 도달했습니다 — [이어서 진행] 으로 계속하세요.');

        return;
    }

    autoResumeCount++;

    // 방금 읽은 상태를 넘긴다 — 넘기지 않으면 `drive()` 가 같은 것을 한 번 더 조회한다.
    await driveFrom(status);
}

/**
 * 끝난 실행의 기록을 지운다 (운영자가 결과를 확인한 뒤).
 */
export async function dismissCoreUpdateHandler(): Promise<void> {
    const G7Core = (window as any).G7Core;

    if (!G7Core?.api?.post) {
        return;
    }

    try {
        publish(unwrap(await G7Core.api.post(DISMISS_URL, {})));
    } catch (error) {
        logger.error('코어 업데이트 기록 정리 실패', error);
        G7Core?.toast?.error?.(messageOf(error));
    }
}
