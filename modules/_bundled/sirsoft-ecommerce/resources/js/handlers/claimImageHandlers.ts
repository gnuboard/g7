/**
 * 클레임 첨부 이미지 핸들러 (회원 · 비회원 공용)
 *
 * 신청서 작성 화면의 사진 첨부를 담당한다. 첨부는 신청서보다 먼저 만들어지므로
 * **주문에 임시로 묶어(pending) 올려 두고**, 신청 시 `image_ids` 로 넘겨 신청서에 귀속시킨다
 * (2단계 업로드 — `modules/_bundled/sirsoft-ecommerce/docs/api/orders.md` 의 `claims/images`).
 *
 * 업로드 시점은 **제출 직전**이다. 파일을 고를 때마다 올리면 FileUploader 가 change 마다
 * 선택 전체를 다시 넘기므로 같은 파일이 거듭 올라가고, 그 중복이 운영자 설정 장수 상한을
 * 소진해 정작 제출이 거절된다. 대신 이미 올라간 첨부(`_local.claimImages`)와 아직 올리지 않은
 * 파일(`_local.claimImageFiles`)을 나눠 들고, 제출이 실패해도 올라간 것은 그대로 남겨
 * 재시도가 다시 올리지 않게 한다.
 *
 * 상한(장수·용량)은 운영자 설정(`claim.max_images` / `claim.image_max_size_kb`)이 정한다.
 * 화면이 리터럴을 들고 있으면 운영자가 값을 바꿨을 때 안내 문구와 실제 거절선이 갈리므로,
 * 이 핸들러는 상한을 `claimable-options` 응답(`settings`)에서만 읽는다.
 *
 * 회원/비회원 분기는 셸이 준 `claimApiBase` / `imageDeleteBase` 로만 이뤄진다 — 핸들러가
 * 로그인 여부를 스스로 판단하지 않는다(공유 partial 규율).
 */

import type { ActionContext } from '../types';

const logger = ((window as any).G7Core?.createLogger?.('Ecom:ClaimImage')) ?? {
    log: (...args: unknown[]) => console.log('[Ecom:ClaimImage]', ...args),
    warn: (...args: unknown[]) => console.warn('[Ecom:ClaimImage]', ...args),
    error: (...args: unknown[]) => console.error('[Ecom:ClaimImage]', ...args),
};

interface ActionWithParams<T = Record<string, any>> {
    handler: string;
    params?: T;
    [key: string]: any;
}

/**
 * 모듈 문구를 번역하고, 번역이 없으면 폴백 문구를 돌려줍니다.
 *
 * 키는 **모듈 접두사**(`sirsoft-ecommerce.`)를 쓴다 — 접두사 없는 `claim.*` 는 특정 사용자
 * 템플릿(sirsoft-basic)의 lang 에만 있어, 다른 템플릿에서는 키 원문이 화면에 나온다.
 * `G7Core.t` 는 번역이 없으면 키 문자열을 그대로 돌려주므로 `t(...) ?? 폴백` 은 폴백까지
 * 가지 않는다 — 키와 같으면 폴백으로 본다(관리자 클레임 핸들러의 `t()` 와 같은 규칙).
 *
 * @param key 모듈 문구 키
 * @param fallback 번역이 없을 때의 문구
 * @param params 치환 파라미터
 * @returns 표시할 문구
 */
export function claimText(key: string, fallback: string, params?: Record<string, unknown>): string {
    const resolved = (window as any).G7Core?.t?.(key, params);

    return typeof resolved === 'string' && resolved !== '' && resolved !== key ? resolved : fallback;
}

/** 업로드된(귀속 대기) 첨부 — ClaimImageResource 형태 */
export interface ClaimPendingImage {
    id: number;
    hash?: string;
    original_name?: string;
    size?: number;
    download_url?: string;
}

/**
 * 비회원 주문 토큰 헤더를 만듭니다. (셸이 비회원이라고 선언했을 때만)
 *
 * ApiClient(axios) 인터셉터는 globalHeaders 를 적용하지 않으므로 비회원 endpoint 호출 시
 * 토큰 헤더를 명시적으로 첨부한다.
 *
 * @param isGuest 셸이 선언한 비회원 여부
 * @returns axios config (회원이면 undefined)
 */
export function claimRequestConfig(isGuest: boolean): { headers: Record<string, string> } | undefined {
    if (!isGuest) return undefined;

    const token = (window as any).G7Core?.state?.get?.('_global')?.guestOrderToken;

    return token ? { headers: { 'X-Guest-Order-Token': String(token) } } : undefined;
}

/**
 * FileUploader 가 넘긴 항목에서 실제 파일 객체를 꺼냅니다.
 *
 * `_local` 상태를 거치면 File 의 prototype 이 소실될 수 있어 `instanceof File` 만으로는
 * 유효한 파일이 조용히 버려진다 — 리뷰 작성 핸들러와 같은 방어를 쓴다.
 *
 * @param raw FileUploader 의 onChange 가 넘긴 배열
 * @returns 업로드 가능한 파일 목록
 */
export function extractClaimFiles(raw: any[]): (File | Blob)[] {
    return (raw ?? [])
        .map((item: any) => {
            if (item instanceof File) return item;
            if (item?.file instanceof File) return item.file;
            if (item?.file instanceof Blob) return item.file;
            if (item?.file && typeof item.file.name === 'string' && item.file.size > 0) return item.file;
            if (item instanceof Blob) return item;

            return null;
        })
        .filter((f: any): f is File | Blob => f !== null);
}

/**
 * 운영자 설정 상한으로 업로드 대기 파일을 거릅니다.
 *
 * @param files 업로드 후보
 * @param alreadyUploaded 이미 올라간 첨부 수
 * @param settings `claimable-options` 의 settings (max_images / image_max_size_kb)
 * @returns 통과한 파일과 거절 사유
 */
export function filterClaimFilesByLimits(
    files: (File | Blob)[],
    alreadyUploaded: number,
    settings: { max_images?: number; image_max_size_kb?: number }
): { accepted: (File | Blob)[]; countExceeded: boolean; sizeExceeded: boolean } {
    const maxImages = Number(settings?.max_images ?? 0);
    const maxSizeKb = Number(settings?.image_max_size_kb ?? 0);

    let accepted = files;
    let countExceeded = false;
    let sizeExceeded = false;

    if (maxSizeKb > 0) {
        const withinSize = accepted.filter((file) => file.size <= maxSizeKb * 1024);
        sizeExceeded = withinSize.length !== accepted.length;
        accepted = withinSize;
    }

    if (maxImages > 0 && alreadyUploaded + accepted.length > maxImages) {
        accepted = accepted.slice(0, Math.max(0, maxImages - alreadyUploaded));
        countExceeded = true;
    }

    return { accepted, countExceeded, sizeExceeded };
}

/**
 * `claimable-options` 응답의 settings 를 읽습니다.
 *
 * 진입 시 작성 핸들러가 `_local.claimableSnapshot` 에 박아 둔 사본이 1순위다. 데이터소스
 * store 는 화면에 따라 이 페이로드를 돌려주지 않는다 — 렌더 바인딩은 값을 그리는데 핸들러만
 * null 을 보는 상태가 실재한다(2026-08-31 브라우저 실측). 그러면 상한이 0 으로 읽혀 장수·용량
 * 가드가 통째로 사문화되고, 서버가 거절할 때까지 아무 안내도 나가지 않는다.
 *
 * @param G7Core G7Core 인스턴스
 * @returns settings (없으면 빈 객체)
 */
function claimSettings(G7Core: any): { max_images?: number; image_max_size_kb?: number } {
    const snapshot = G7Core.state?.getLocal?.()?.claimableSnapshot;

    return snapshot?.settings ?? G7Core.dataSource?.get?.('claimable')?.data?.settings ?? {};
}

/**
 * 대기 중인 파일을 순차 업로드하고 귀속 대기 첨부 목록을 갱신합니다.
 *
 * 신청 제출 직전에 `claimApplyHandlers` 가 호출한다. 실패한 파일은 토스트로 알리고
 * 성공분만 `_local.claimImages` 에 누적한다 — 실패를 이유로 이미 올라간 첨부를 버리면
 * 재시도에서 다시 올라가 장수 상한을 소진한다.
 *
 * 돌려주는 것은 **아직 신청서에 귀속되지 않은** 첨부의 ID 뿐이다. 수정 화면은 이미 귀속된
 * 첨부로 `_local.claimImages` 를 채워 열리는데(`buildClaimEditImages()` 가 `attached: true`
 * 를 세운다), 그 ID 까지 돌려주면 호출부가 `add_image_ids` 로 실어 보내고 서버는 그것을
 * "이 주문의 미귀속 첨부" 로 찾다가 실패해 422 를 던진다 — 첨부가 한 장이라도 있는 신청서는
 * 사유조차 고칠 수 없게 된다.
 *
 * @param G7Core G7Core 인스턴스
 * @param options 업로드 옵션 (claimApiBase / isGuest)
 * @returns 신청서에 새로 귀속시킬(아직 미귀속인) 첨부 ID 목록
 */
export async function uploadPendingClaimFiles(
    G7Core: any,
    options: { claimApiBase: string; isGuest: boolean }
): Promise<number[]> {
    const local = G7Core.state.getLocal();
    const already: ClaimPendingImage[] = local.claimImages ?? [];
    const files = extractClaimFiles(local.claimImageFiles ?? []);

    if (files.length === 0) {
        return pendingImageIds(already);
    }

    const settings = claimSettings(G7Core);
    const { accepted, countExceeded, sizeExceeded } = filterClaimFilesByLimits(files, already.length, settings);

    if (sizeExceeded) {
        G7Core.toast?.warning?.(
            claimText('sirsoft-ecommerce.user.claim.image.size_exceeded', '첨부 용량 상한을 초과한 파일은 제외했습니다.', { size: Number(settings.image_max_size_kb ?? 0) })
        );
    }

    if (countExceeded) {
        G7Core.toast?.warning?.(
            claimText('sirsoft-ecommerce.user.claim.image.limit_exceeded', '첨부 장수 상한을 초과한 파일은 제외했습니다.', { max: Number(settings.max_images ?? 0), count: Number(settings.max_images ?? 0) })
        );
    }

    const config = claimRequestConfig(options.isGuest);
    const uploaded: ClaimPendingImage[] = [];
    let failed = 0;
    /** 서버가 준 첫 거절 사유 — 요약에 덧붙인다(같은 사유가 반복되는 것이 보통이다). */
    let firstFailureMessage: string | null = null;

    for (const file of accepted) {
        const formData = new FormData();
        formData.append('image', file);

        try {
            const response = await G7Core.api.post(
                options.claimApiBase + '/claims/images',
                formData,
                {
                    ...(config ?? {}),
                    headers: { ...(config?.headers ?? {}), 'Content-Type': 'multipart/form-data' },
                }
            );

            if (response?.success && response.data) {
                uploaded.push(response.data as ClaimPendingImage);
            }
        } catch (error: any) {
            // 파일마다 띄우지 않고 **끝나고 한 번** 장수와 함께 알린다 — 아래 요약 참조.
            logger.error('[uploadPendingClaimFiles] 실패:', error);
            failed += 1;
            firstFailureMessage = firstFailureMessage
                ?? error?.response?.data?.message
                ?? error?.data?.message
                ?? null;
        }
    }

    // **몇 장 중 몇 장이 빠졌는지** 한 줄로 말한다 (T2-D4 · #599 3차 실측).
    //
    // 종전에는 실패한 파일마다 같은 문구를 한 번씩 띄웠고, 그 문구가 장수를 말하지 않았다.
    // 접수는 성공하므로 마지막에 남는 토스트는 「신청이 접수되었습니다.」였고, 구매자는 사진이
    // 다 붙은 줄 알았다 — 3장 중 1장이 빠진 것을 알 방법이 화면 어디에도 없었다.
    //
    // 서버가 사유를 줬으면 그것을 덧붙인다(용량·형식 등 구매자가 고칠 수 있는 사유다).
    // 다시 붙이는 자리는 이미 있으므로, 빠졌다는 사실과 장수만 알면 스스로 빠져나올 수 있다.
    if (failed > 0) {
        // 분모는 **올리려 한 장수**(`accepted`)다. 고른 장수를 쓰면 상한으로 이미 제외된 것까지
        // 「못 올렸다」로 세어, 바로 위에서 이유를 말한 그 파일을 두 번 세는 셈이 된다.
        const summary = claimText(
            'sirsoft-ecommerce.user.claim.image.upload_partial_failed',
            `사진 ${accepted.length}장 중 ${failed}장을 올리지 못했습니다.`,
            { failed, total: accepted.length, count: accepted.length },
        );

        G7Core.toast?.error?.(firstFailureMessage ? `${summary} (${firstFailureMessage})` : summary);
    }

    // await 뒤의 로컬 상태는 다시 읽는다 — 업로드 도중 다른 편집이 있었으면 캡처본이 낡았다.
    const latest = G7Core.state.getLocal();
    const merged: ClaimPendingImage[] = [...(latest.claimImages ?? []), ...uploaded];

    G7Core.state.setLocal({ claimImages: merged, claimImageFiles: [] });

    return pendingImageIds(merged);
}

/**
 * 아직 신청서에 귀속되지 않은 첨부의 ID 만 골라 돌려줍니다.
 *
 * 수정 화면이 채워 둔 귀속분은 `attached: true` 로 표시돼 있다. 서버의 `add_image_ids` 는
 * 미귀속 첨부만 받으므로 그 표식이 선 것은 제외한다. 귀속분의 삭제는 이 목록이 아니라
 * `remove_image_ids` 가 맡는다.
 *
 * @param images 화면이 들고 있는 첨부 목록
 * @returns 미귀속 첨부 ID 목록
 */
function pendingImageIds(images: ClaimPendingImage[]): number[] {
    return images
        .filter((image) => (image as Record<string, any>).attached !== true)
        .map((image) => Number(image.id))
        .filter((id) => id > 0);
}

/**
 * 첨부 선택 변경 핸들러 (FileUploader change)
 *
 * 파일은 아직 올리지 않고 대기열에만 담는다. 이미 올라간 첨부와 합쳐 상한을 넘으면
 * 그 자리에서 안내한다 — 제출 시점에 처음 알리면 사용자가 무엇이 빠졌는지 알 수 없다.
 *
 * @example
 * {
 *   "handler": "sirsoft-ecommerce.setClaimImageFiles",
 *   "params": { "files": "{{$event.target.files ?? []}}" }
 * }
 *
 * @param action 액션 객체
 * @param _context 액션 컨텍스트
 */
export function setClaimImageFilesHandler(
    action: ActionWithParams<{ files?: any[] }>,
    _context: ActionContext
): void {
    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) return;

    const files = extractClaimFiles(action.params?.files ?? []);
    const local = G7Core.state.getLocal();
    const already: ClaimPendingImage[] = local.claimImages ?? [];
    const settings = claimSettings(G7Core);
    const { accepted, countExceeded, sizeExceeded } = filterClaimFilesByLimits(files, already.length, settings);

    if (sizeExceeded) {
        G7Core.toast?.warning?.(
            claimText('sirsoft-ecommerce.user.claim.image.size_exceeded', '첨부 용량 상한을 초과한 파일이 있습니다.', { size: Number(settings.image_max_size_kb ?? 0) })
        );
    }

    if (countExceeded) {
        G7Core.toast?.warning?.(
            claimText('sirsoft-ecommerce.user.claim.image.limit_exceeded', '첨부 장수 상한을 초과했습니다.', { max: Number(settings.max_images ?? 0), count: Number(settings.max_images ?? 0) })
        );
    }

    // **거른 결과를 담는다** — 고른 원본을 그대로 담으면 `_local.claimImageFiles` 가
    // 「고른 것」과 「보낼 것」 둘을 뜻하게 되고, 그 차이가 화면 어디에도 드러나지 않는다.
    // 업로드 경로(`uploadPendingClaimFiles`)가 같은 술어로 한 번 더 거르므로 실제로 올라가는
    // 파일은 종전에도 옳았지만, 상태가 거짓을 들고 있으면 그 값을 읽는 다음 화면이 틀린다
    // (2026-09-22 #599 3차 실측 T2-D2 — 거절된 파일이 첨부된 것처럼 남았다).
    G7Core.state.setLocal({ claimImageFiles: accepted });
}

/**
 * 귀속 대기 첨부 삭제 핸들러
 *
 * @example
 * {
 *   "handler": "sirsoft-ecommerce.removeClaimImage",
 *   "params": { "imageId": 9, "imageDeleteBase": "/api/modules/sirsoft-ecommerce/user/claims/images", "isGuest": false }
 * }
 *
 * @param action 액션 객체
 * @param _context 액션 컨텍스트
 */
export async function removeClaimImageHandler(
    action: ActionWithParams<{ imageId?: number; imageDeleteBase?: string; isGuest?: boolean }>,
    _context: ActionContext
): Promise<void> {
    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) return;

    const imageId = Number(action.params?.imageId ?? 0);
    const imageDeleteBase = action.params?.imageDeleteBase ?? '';

    if (!imageId || !imageDeleteBase) return;

    // 이미 신청서에 귀속된 첨부(수정 화면의 기존 사진)는 그 자리에서 지우지 않는다. 삭제
    // 엔드포인트는 소유만 확인하고 바로 파일까지 지우므로, 저장을 취소하고 나가도 첨부만
    // 영구히 사라진다. 대신 삭제 목록에 담아 두었다가 저장할 때 `remove_image_ids` 로
    // 넘긴다 — 서버가 같은 흐름에서 처리해야 장수 상한 판정(삭제 후 남을 장수)이 화면과
    // 갈리지 않는다.
    const current = G7Core.state.getLocal();
    if (current.removingClaimImageId != null || current.isSubmittingClaim === true) return;
    const target = (current.claimImages ?? []).find(
        (image: ClaimPendingImage & { attached?: boolean }) => Number(image.id) === imageId
    );

    // 고객이 지울 수 없는 첨부(운영자·주체 미상)는 삭제 목록에 담지 않는다 — 버튼을 숨겨도
    // 이 핸들러가 담으면 저장이 서버에서 통째로 거절된다.
    if ((target as { removable?: boolean } | undefined)?.removable === false) return;

    if (target?.attached === true) {
        G7Core.state.setLocal({
            claimImages: (current.claimImages ?? []).filter(
                (image: ClaimPendingImage) => Number(image.id) !== imageId
            ),
            claimRemoveImageIds: [
                ...(current.claimRemoveImageIds ?? []).filter((value: unknown) => Number(value) !== imageId),
                imageId,
            ],
        });

        return;
    }

    G7Core.state.setLocal({ removingClaimImageId: imageId });
    try {
        await G7Core.api.delete(imageDeleteBase + '/' + imageId, claimRequestConfig(action.params?.isGuest === true));
    } catch (error: any) {
        logger.error('[removeClaimImage] 실패:', error);
        G7Core.toast?.error?.(
            error?.response?.data?.message
            ?? error?.data?.message
            ?? claimText('sirsoft-ecommerce.user.claim.image.remove_failed', '첨부 삭제에 실패했습니다.')
        );

        return;
    } finally {
        G7Core.state.setLocal({ removingClaimImageId: null });
    }

    const latest = G7Core.state.getLocal();
    G7Core.state.setLocal({
        claimImages: (latest.claimImages ?? []).filter((image: ClaimPendingImage) => Number(image.id) !== imageId),
    });
}
