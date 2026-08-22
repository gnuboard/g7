/**
 * 토스페이먼츠 결제창 닫힘·결제 실패 보고
 *
 * 브라우저 리턴 콜백(`/payment/fail`)은 인증도 서명도 없고 주문번호가 쿼리스트링으로 오므로
 * 주문 상태를 바꾸지 않는다. 정당한 결제 실패를 기록하는 것은 구매자 정보를 대조하는
 * close-report 엔드포인트뿐이며, 이 모듈이 그 호출을 담당한다.
 *
 * 결제창은 전체 페이지 이동으로 열리고 돌아오므로, 결제 요청 직전에 구매자 정보를
 * sessionStorage 에 남겨 두었다가 실패 화면으로 돌아왔을 때 꺼내 쓴다. sessionStorage 는
 * 같은 탭에서 외부 도메인을 다녀와도 유지된다.
 */

const PLUGIN_IDENTIFIER = 'sirsoft-tosspayments';

const STORAGE_KEY = 'g7:sirsoft-tosspayments:pendingClose';

const CLOSE_REPORT_PATH = '/api/plugins/sirsoft-tosspayments/payment/close-report';

export interface PaymentCloseReportContext {
    orderId: string;
    amount: number;
    buyer_email?: string;
    buyer_phone?: string;
}

/**
 * sessionStorage 접근은 브라우저 설정(사이트 데이터 차단·시크릿 모드)에 따라 예외를 던진다.
 * 보고는 편의 장치이므로 실패해도 결제 흐름을 막지 않는다.
 */
function safeSessionStorage(): Storage | null {
    try {
        return window.sessionStorage ?? null;
    } catch {
        return null;
    }
}

/**
 * 결제창을 열기 직전에 구매자 정보를 저장합니다.
 *
 * @param context 결제창 닫힘 보고에 필요한 주문·구매자 정보
 */
export function rememberPendingClose(context: PaymentCloseReportContext): void {
    const storage = safeSessionStorage();
    if (!storage) {
        return;
    }

    try {
        storage.setItem(STORAGE_KEY, JSON.stringify(context));
    } catch {
        // 저장 실패는 무시 — 만료 자동 정리가 최종 안전망이다.
    }
}

/**
 * 저장해 둔 구매자 정보를 지웁니다 (보고 완료 또는 결제 성공 시).
 */
export function forgetPendingClose(): void {
    const storage = safeSessionStorage();
    if (!storage) {
        return;
    }

    try {
        storage.removeItem(STORAGE_KEY);
    } catch {
        // 무시
    }
}

/**
 * 저장해 둔 구매자 정보를 읽습니다.
 *
 * @returns 저장된 정보, 없거나 형식이 깨졌으면 null
 */
function readPendingClose(): PaymentCloseReportContext | null {
    const storage = safeSessionStorage();
    if (!storage) {
        return null;
    }

    try {
        const raw = storage.getItem(STORAGE_KEY);
        if (!raw) {
            return null;
        }

        const parsed = JSON.parse(raw) as PaymentCloseReportContext;

        return parsed && typeof parsed.orderId === 'string' && parsed.orderId !== ''
            ? parsed
            : null;
    } catch {
        return null;
    }
}

/**
 * close-report 엔드포인트에 보고합니다.
 *
 * @param context 주문·구매자 정보
 * @param code 결제 실패 코드 (결제창을 닫은 경우 빈 값)
 * @param reason 사람이 읽을 실패 사유
 */
async function postCloseReport(
    context: PaymentCloseReportContext,
    code: string,
    reason: string,
): Promise<void> {
    const payload = {
        orderId: context.orderId,
        amount: Number(context.amount),
        buyer_email: context.buyer_email ?? '',
        buyer_phone: context.buyer_phone ?? '',
        code: code.slice(0, 60),
        reason: reason.trim().slice(0, 160),
    };

    const apiClient = ((window as any).G7Core)?.api;
    if (typeof apiClient?.post === 'function') {
        await apiClient.post(CLOSE_REPORT_PATH, payload);

        return;
    }

    await fetch(CLOSE_REPORT_PATH, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify(payload),
        keepalive: true,
    });
}

/**
 * 결제 실패 화면으로 돌아왔으면 저장해 둔 정보로 서버에 보고합니다.
 *
 * 결제 성공으로 돌아온 경우에는 보고하지 않고 저장분만 지운다 — 성공 확정은 서버의
 * `confirmPayment` 가 담당하므로 여기서 관여할 것이 없다.
 *
 * 플러그인 부팅 시 1회 호출한다.
 */
export async function reportPaymentFailureOnReturn(): Promise<void> {
    const pending = readPendingClose();
    if (!pending) {
        return;
    }

    let params: URLSearchParams;
    try {
        params = new URLSearchParams(window.location.search);
    } catch {
        return;
    }

    const orderIdInUrl = params.get('orderId') ?? '';

    // 저장분과 화면의 주문번호가 다르면 이번 이동과 무관한 잔여물이다 — 보고하지 않는다.
    if (orderIdInUrl !== '' && orderIdInUrl !== pending.orderId) {
        return;
    }

    // 결제 완료 화면으로 돌아왔으면 보고 대상이 아니다.
    if (/\/(complete|success)(\/|$|\?)/.test(window.location.pathname)) {
        forgetPendingClose();

        return;
    }

    const code = params.get('error') ?? '';
    const message = params.get('message') ?? '';

    // 실패 표시가 전혀 없으면 결제창을 열기만 하고 돌아온 경우일 수 있다 — 판단하지 않는다.
    if (code === '' && orderIdInUrl === '') {
        return;
    }

    // 중복 보고를 막기 위해 요청 전에 먼저 지운다. 서버도 멱등하게 처리하지만
    // (이미 취소된 주문은 order_not_payable 로 무시) 불필요한 요청 자체를 줄인다.
    forgetPendingClose();

    try {
        await postCloseReport(pending, code, message !== '' ? message : code);
    } catch (error) {
        console.warn(`[${PLUGIN_IDENTIFIER}] failed to report payment failure`, error);
    }
}
