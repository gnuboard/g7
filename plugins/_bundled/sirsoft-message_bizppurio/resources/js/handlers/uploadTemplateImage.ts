/**
 * uploadTemplateImage 핸들러 (#597 §3.2 · 부록 A-7)
 *
 * 이미지형 알림톡 템플릿 작성 모달에서 파일 선택(change) 시, 선택한 이미지를 플러그인의
 * 이미지 업로드 프록시(`POST /admin/templates/image` — kapi 위임)로 전송하고, 반환된
 * 카카오 이미지 URL 을 params.statePathUrl 에, 파일명을 params.statePathName 에 설정한다.
 *
 * 업로드는 multipart/form-data 이므로 apiCall 핸들러로는 처리하기 번거로워 전용 핸들러로 둔다.
 * 진행 상태·오류는 params.statePathStatus(기본 bz_tpl_upload) 하위의
 * `{uploading, error}` 로 노출한다.
 *
 * 레이아웃 JSON 사용 예:
 * {
 *   "type": "change",
 *   "handler": "sirsoft-message_bizppurio.uploadTemplateImage",
 *   "params": {
 *     "stateTarget": "global",
 *     "statePathUrl": "bz_tpl_modal.content.templateImageUrl",
 *     "statePathName": "bz_tpl_modal.content.templateImageName",
 *     "statePathStatus": "bz_tpl_upload"
 *   }
 * }
 */

import type { ActionContext, ActionWithParams } from '../types';

const logger = ((window as any).G7Core?.createLogger?.('MessageBizppurio:UploadImage')) ?? {
    log: (...args: unknown[]) => console.log('[MessageBizppurio:UploadImage]', ...args),
    warn: (...args: unknown[]) => console.warn('[MessageBizppurio:UploadImage]', ...args),
    error: (...args: unknown[]) => console.error('[MessageBizppurio:UploadImage]', ...args),
};

const UPLOAD_URL = '/api/plugins/sirsoft-message_bizppurio/admin/templates/image';

/** 서버 사유가 없을 때 쓰는 최후 폴백 문구의 다국어 키 */
const FAILURE_KEY = 'sirsoft-message_bizppurio.template.form.image_upload_failed';

/**
 * 운영자 로케일을 Accept-Language 로 실어 보냅니다.
 *
 * 이 핸들러는 multipart 때문에 코어 ApiClient/ActionDispatcher 를 우회해 raw fetch 를
 * 쓰므로, 두 경로가 공통으로 붙이는 로케일 헤더를 여기서 직접 재현한다. 빠뜨리면 서버
 * 검증 메시지와 카카오 사유 원문이 운영자 언어가 아닌 앱 기본 로케일로 돌아온다.
 *
 * @returns Accept-Language 헤더 객체 (미설정 시 빈 객체)
 */
function localeHeader(): Record<string, string> {
    if (typeof window === 'undefined') {
        return {};
    }

    const locale = localStorage.getItem('g7_locale');

    return locale ? { 'Accept-Language': locale } : {};
}

/**
 * 업로드 실패 폴백 문구를 반환합니다.
 *
 * @param G7Core  전역 G7Core 객체
 * @returns 번역된 문구 (해석 실패 시 영문 폴백)
 */
function failureText(G7Core: any): string {
    const translated = G7Core?.t?.(FAILURE_KEY);

    return (typeof translated === 'string' && translated !== FAILURE_KEY)
        ? translated
        : 'Failed to upload the image.';
}

/**
 * stateTarget(global|local)에 맞는 상태 setter 를 반환합니다.
 *
 * @param G7Core  전역 G7Core 객체
 * @param target  'global' | 'local'
 * @returns 상태 setter 또는 null
 */
function resolveSetter(G7Core: any, target: string): ((updates: Record<string, unknown>) => void) | null {
    if (target === 'local') {
        return G7Core?.state?.setLocal ?? null;
    }

    return G7Core?.state?.setGlobal ?? G7Core?.state?.set ?? null;
}

/**
 * stateTarget(global|local)에 맞는 상태 getter 를 반환합니다.
 *
 * @param G7Core  전역 G7Core 객체
 * @param target  'global' | 'local'
 * @returns 상태 getter 또는 null
 */
function resolveGetter(G7Core: any, target: string): (() => Record<string, any>) | null {
    if (target === 'local') {
        return G7Core?.state?.getLocal ?? null;
    }

    return G7Core?.state?.getGlobal ?? G7Core?.state?.get ?? null;
}

/**
 * dot notation 경로로 객체에서 값을 읽습니다.
 *
 * @param obj  대상 객체
 * @param path  dot 경로
 * @returns 경로 값(없으면 undefined)
 */
function readPath(obj: Record<string, any>, path: string): unknown {
    return path.split('.').reduce<any>((acc, key) => (acc == null ? undefined : acc[key]), obj);
}

/**
 * 이 업로드의 결과를 아직 써도 되는지 판정합니다.
 *
 * 응답이 도착했을 때 모달이 이미 다른 알림으로 바뀌었거나 다시 열렸다면, 그 결과는 지금
 * 화면이 편집 중인 템플릿의 것이 아니다. 그대로 쓰면 A 알림의 이미지가 B 알림 폼에 기입되고
 * (실패 경로에서는 B 가 방금 시딩한 값이 지워진다) 운영자에게는 아무 단서도 남지 않는다.
 *
 * 판정 신호는 진행 플래그 자신이다 — 모달을 여는 지점은 모두 statePathStatus 를
 * `{uploading:false, error:null}` 로 리시드하므로, 우리가 켜 둔 uploading 이 사라졌다면
 * 그 사이에 모달이 다시 시딩된 것이다.
 *
 * @param G7Core  전역 G7Core 객체
 * @param stateTarget  'global' | 'local'
 * @param pathStatus  업로드 상태 경로
 * @returns 결과를 반영해도 되면 true
 */
function stillOwnsUpload(G7Core: any, stateTarget: string, pathStatus: string): boolean {
    const getter = resolveGetter(G7Core, stateTarget);

    // getter 를 못 쓰면 판정 불가 — 기존 동작(그대로 반영)을 유지한다.
    if (!getter) {
        return true;
    }

    try {
        return readPath(getter() ?? {}, `${pathStatus}.uploading`) === true;
    } catch {
        return true;
    }
}

/** 동시 업로드 차단 플래그 — 진행 중에는 새 파일 선택을 받지 않는다. */
let uploadInFlight = false;

/**
 * 선택한 이미지 파일을 업로드하고 결과 URL 을 상태에 반영합니다.
 *
 * @param action  액션 객체(params.stateTarget/statePathUrl/statePathName/statePathStatus)
 * @param context  액션 컨텍스트($event 로 파일 접근)
 */
export async function uploadTemplateImageHandler(
    action: ActionWithParams,
    context: ActionContext,
): Promise<void> {
    const G7Core = (window as any).G7Core;
    const params = action.params ?? {};
    const stateTarget = String(params.stateTarget ?? 'global');
    const pathUrl = String(params.statePathUrl ?? 'bz_tpl_modal.content.templateImageUrl');
    const pathName = String(params.statePathName ?? 'bz_tpl_modal.content.templateImageName');
    const pathStatus = String(params.statePathStatus ?? 'bz_tpl_upload');

    const setState = resolveSetter(G7Core, stateTarget);
    if (!setState) {
        logger.error('G7Core.state setter 를 사용할 수 없습니다.');
        return;
    }

    const event = (context?.event ?? (action as any)?.event) as Event | undefined;
    const input = event?.target as HTMLInputElement | undefined;
    const file = input?.files?.[0];

    if (!file) {
        return;
    }

    // 진행 중인 업로드가 있으면 새 선택을 받지 않는다. 두 요청이 겹치면 먼저 끝난 쪽이
    // uploading:false 를 써서 저장 버튼 잠금이 풀리고, 나중에 끝나는 업로드가 저장 이후에
    // templateImageUrl 을 덮는다 — 잠금이 막으려던 "빈/엉뚱한 URL 저장" 이 그대로 재현된다.
    if (uploadInFlight) {
        logger.warn('이미 업로드가 진행 중입니다 — 새 파일 선택을 무시합니다.');
        if (input) {
            input.value = '';
        }

        return;
    }

    uploadInFlight = true;
    setState({ [`${pathStatus}.uploading`]: true, [`${pathStatus}.error`]: null });

    try {
        const token = localStorage.getItem('auth_token') ?? '';
        const form = new FormData();
        form.append('image', file, file.name);

        const res = await fetch(UPLOAD_URL, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                ...(token ? { Authorization: `Bearer ${token}` } : {}),
                ...localeHeader(),
            },
            body: form,
        });

        const json = await res.json().catch(() => ({}));

        if (!res.ok || json?.success === false) {
            // 서버가 내려준 사유(카카오 원문·검증 메시지)는 그대로 보여준다 —
            // Accept-Language 를 실어 보냈으므로 운영자 로케일로 도착한다.
            const message = json?.errors?.bizppurio_message
                || json?.errors?.image?.[0]
                || json?.message
                || failureText(G7Core);
            if (stillOwnsUpload(G7Core, stateTarget, pathStatus)) {
                failUpload(setState, pathStatus, pathUrl, pathName, message);
            }
            logger.warn('이미지 업로드 실패:', message);
            return;
        }

        const url = String(json?.data?.url ?? '').trim();

        // 성공 응답인데 url 이 비어 있으면 성공이 아니다 — 그대로 두면 배너는 성공으로 보이는데
        // templateImageUrl 이 빈 채로 저장돼 검수 신청 시점에야 드러난다.
        if (url === '') {
            if (stillOwnsUpload(G7Core, stateTarget, pathStatus)) {
                failUpload(setState, pathStatus, pathUrl, pathName, failureText(G7Core));
            }
            logger.warn('이미지 업로드 응답에 url 이 없습니다:', json);
            return;
        }

        if (!stillOwnsUpload(G7Core, stateTarget, pathStatus)) {
            logger.warn('모달이 다시 시딩되어 업로드 결과를 버립니다:', url);

            return;
        }

        setState({
            [`${pathStatus}.uploading`]: false,
            [`${pathStatus}.error`]: null,
            [pathUrl]: url,
            [pathName]: file.name,
        });

        logger.log('이미지 업로드 완료:', url);
    } catch (e) {
        // 예외 원문(TypeError 등)은 운영자에게 의미가 없고 내부 구현을 노출한다 —
        // 화면에는 번역 문구만 싣고 원문은 콘솔로만 남긴다.
        if (stillOwnsUpload(G7Core, stateTarget, pathStatus)) {
            failUpload(setState, pathStatus, pathUrl, pathName, failureText(G7Core));
        }
        logger.error('이미지 업로드 예외:', e);
    } finally {
        uploadInFlight = false;

        // 같은 파일 재선택이 가능하도록 input 값 초기화
        if (input) {
            input.value = '';
        }
    }
}

/**
 * 업로드 실패 상태를 기록하고 직전 이미지 값을 무효화합니다.
 *
 * 실패 경로가 url/파일명을 그대로 두면, 배너는 "실패" 인데 폼에는 직전에 성공한 이미지가
 * 남아 그대로 저장된다 — 운영자는 방금 고른 이미지가 저장된다고 믿는다. 실패했으면
 * 화면에 보이는 이미지도 없어야 한다.
 *
 * @param setState  상태 setter
 * @param pathStatus  업로드 상태 경로 (uploading/error)
 * @param pathUrl  이미지 URL 상태 경로
 * @param pathName  이미지 파일명 상태 경로
 * @param message  화면에 표시할 실패 사유
 */
function failUpload(
    setState: (patch: Record<string, unknown>) => void,
    pathStatus: string,
    pathUrl: string,
    pathName: string,
    message: string,
): void {
    setState({
        [`${pathStatus}.uploading`]: false,
        [`${pathStatus}.error`]: message,
        [pathUrl]: '',
        [pathName]: '',
    });
}
