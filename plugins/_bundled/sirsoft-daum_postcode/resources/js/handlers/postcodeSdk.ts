/**
 * 다음 우편번호 SDK 확보 상태 판정
 *
 * 이 SDK 는 라이브러리가 아니라 Daum 이 운영하는 **서비스 SDK** 라 동봉할 수 없다.
 * 스크립트가 Daum 서버와 통신하므로 자체 호스팅해도 오프라인에서 동작하지 않는다.
 * 그래서 이 플러그인만은 외부 호스트 의존이 남으며, 대신 **못 불러왔을 때 주소를
 * 직접 입력할 수 있어야** 한다.
 *
 * 종전에는 그 반대였다: `setFieldReadOnly` 가 SDK 확보 여부와 무관하게 마운트 시점에
 * 우편번호·주소 필드를 읽기 전용으로 고정했고, 검색 버튼은 SDK 가 없으면 아무 반응이
 * 없었다. 둘이 겹쳐 **주소를 검색할 수도, 직접 입력할 수도 없는** 상태가 됐다 —
 * 주문을 끝낼 방법이 사라진다.
 *
 * @module postcodeSdk
 */

/** SDK 스크립트 URL (manifest `trusted_script_hosts` 로 선언한 호스트) */
export const DAUM_POSTCODE_SDK_URL = '//t1.daumcdn.net/mapjsapi/bundle/postcode/prod/postcode.v2.js';

/** 레이아웃 확장이 삽입하는 스크립트 element id */
export const DAUM_POSTCODE_SCRIPT_ID = 'daum_postcode_script';

/** 자산 실패 안내의 식별자 */
const FAILURE_ID = 'daum-postcode-sdk';

/** SDK 확보를 기다리는 최대 시간(ms) — 코어 스크립트 로더의 재시도 예산과 맞춘다 */
const DEFAULT_WAIT_MS = 12000;

/** 폴링 간격(ms) */
const POLL_INTERVAL_MS = 100;

/**
 * 번역 문자열을 얻습니다.
 *
 * @param key 번역 키 (플러그인 네임스페이스 이하)
 * @param fallback 번역 엔진 부재 시 사용할 문구
 * @return string 번역된 문자열
 */
function t(key: string, fallback: string): string {
    const translate = (window as any)?.G7Core?.t;

    if (typeof translate !== 'function') {
        return fallback;
    }

    const full = `sirsoft-daum_postcode.${key}`;
    const result = translate(full);

    return typeof result === 'string' && result !== full ? result : fallback;
}

/**
 * SDK 가 지금 사용 가능한지 판정합니다.
 *
 * @return bool 사용 가능하면 true
 */
export function isPostcodeSdkReady(): boolean {
    return Boolean((window as any).daum?.Postcode);
}

/**
 * SDK 확보를 기다립니다.
 *
 * 레이아웃 `scripts[]` 로더가 비동기로 스크립트를 넣고 재시도까지 하므로, 마운트
 * 시점에 아직 없는 것은 정상이다. "지금 없다" 와 "끝내 못 왔다" 를 구분해야
 * 읽기 전용을 걸지 말지 판단할 수 있다.
 *
 * @param timeoutMs 최대 대기 시간(ms)
 * @return Promise<bool> 확보되면 true, 시간 안에 못 오면 false
 */
export function waitForPostcodeSdk(timeoutMs: number = DEFAULT_WAIT_MS): Promise<boolean> {
    if (isPostcodeSdkReady()) {
        return Promise.resolve(true);
    }

    return new Promise<boolean>((resolve) => {
        const startedAt = Date.now();

        const tick = () => {
            if (isPostcodeSdkReady()) {
                resolve(true);

                return;
            }

            if (Date.now() - startedAt >= timeoutMs) {
                resolve(false);

                return;
            }

            setTimeout(tick, POLL_INTERVAL_MS);
        };

        setTimeout(tick, POLL_INTERVAL_MS);
    });
}

/**
 * SDK 스크립트를 다시 로드합니다.
 *
 * 코어 로더가 최종 실패하면 스크립트 element 를 제거하므로, 재시도는 새로 넣어야 한다.
 *
 * @return Promise<bool> 로드 후 SDK 가 확보되면 true
 */
export async function reloadPostcodeSdk(): Promise<boolean> {
    if (isPostcodeSdkReady()) {
        return true;
    }

    document.getElementById(DAUM_POSTCODE_SCRIPT_ID)?.remove();

    const loadScript = (window as any)?.G7Core?.asset?.loadScript;

    try {
        if (typeof loadScript === 'function') {
            await loadScript(
                DAUM_POSTCODE_SDK_URL,
                { id: DAUM_POSTCODE_SCRIPT_ID },
                { label: 'daum postcode SDK' }
            );
        } else {
            await new Promise<void>((resolve, reject) => {
                const script = document.createElement('script');
                script.id = DAUM_POSTCODE_SCRIPT_ID;
                script.src = DAUM_POSTCODE_SDK_URL;
                script.onload = () => resolve();
                script.onerror = () => reject(new Error('daum postcode SDK load failed'));
                document.head.appendChild(script);
            });
        }
    } catch {
        return false;
    }

    return isPostcodeSdkReady();
}

/**
 * SDK 미확보를 사용자에게 알립니다.
 *
 * @param onRetry 재시도 성공 시 수행할 후속 동작
 * @return void
 */
export function notifyPostcodeSdkFailure(onRetry: () => void | Promise<void>): void {
    const notify = (window as any)?.G7Core?.assets?.notifyFailure;

    const message = t(
        'postcode.error.sdk_unavailable',
        '주소 검색을 불러오지 못했습니다. 주소를 직접 입력해 주세요.'
    );

    if (typeof notify !== 'function') {
        console.warn(`[sirsoft-daum_postcode] ${message}`);

        return;
    }

    notify({
        id: FAILURE_ID,
        label: t('postcode.search', '주소 검색'),
        message,
        retry: async () => {
            if (!(await reloadPostcodeSdk())) {
                throw new Error('[sirsoft-daum_postcode] 주소 검색을 여전히 불러올 수 없습니다.');
            }

            await onRetry();
        },
    });
}

/**
 * SDK 미확보 안내를 해제합니다.
 *
 * @return void
 */
export function clearPostcodeSdkFailure(): void {
    (window as any)?.G7Core?.assets?.clearFailure?.(FAILURE_ID);
}
