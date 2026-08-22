// e2e:allow PG 영수증 조회/팝업 — 외부 PG(이니시스) 결제 데이터 의존으로 브라우저 E2E 재현 불가, Vitest 회귀(__tests__/receiptPopup.test.ts)로 검증
const PLUGIN_ID = 'sirsoft-pay_kginicis';

export interface KginicisReceiptField {
    label: string;
    value: string;
}

export interface KginicisReceiptInfo {
    receipt_type?: 'inicis_receipt' | 'cbt_confirmation' | string;
    receipt_url?: string | null;
    receipt_label?: string | null;
    receipt_view_label?: string | null;
    receipt_title?: string | null;
    receipt_notice?: string | null;
    receipt_fields?: KginicisReceiptField[];
    payment_method_display_label?: string | null;
}

function getAuthToken(): string | null {
    return localStorage.getItem('auth_token');
}

function getGuestOrderToken(): string | null {
    // 코어 storageHandlers.initGuestOrderTokenHandler 가 sessionStorage 에 저장한 토큰.
    // sessionStorage 미접근 환경(private/iframe) fallback 으로 _global.guestOrderToken 도 확인.
    try {
        const sessionToken = sessionStorage.getItem('g7_guest_order_token');
        if (sessionToken) return sessionToken;
    } catch {
        // sessionStorage 접근 불가
    }
    const globalToken = (window as any).G7Core?.state?.get?.('_global')?.guestOrderToken;
    return typeof globalToken === 'string' && globalToken !== '' ? globalToken : null;
}

async function requestKginicisReceiptInfo(
    orderNumber: string,
    headers: Record<string, string>,
): Promise<{ status: number; data: KginicisReceiptInfo | null }> {
    try {
        const res = await fetch(`/api/plugins/${PLUGIN_ID}/user/orders/${orderNumber}/receipt`, {
            headers,
            credentials: 'same-origin',
        });
        if (!res.ok) return { status: res.status, data: null };
        return { status: res.status, data: (await res.json()) as KginicisReceiptInfo };
    } catch {
        return { status: 0, data: null };
    }
}

export interface KginicisReceiptFetchResult {
    /** 최종 시도의 HTTP 상태 코드 (네트워크 오류 시 0) */
    status: number;
    info: KginicisReceiptInfo | null;
}

export async function fetchKginicisReceiptInfoDetailed(
    orderNumber: string,
): Promise<KginicisReceiptFetchResult> {
    const authToken = getAuthToken();
    const guestToken = getGuestOrderToken();

    const headers: Record<string, string> = { Accept: 'application/json' };
    if (authToken) {
        headers.Authorization = `Bearer ${authToken}`;
    } else if (guestToken) {
        // 백엔드 UserReceiptController 가 Auth::check() 실패 시 X-Guest-Order-Token 으로 분기.
        headers['X-Guest-Order-Token'] = guestToken;
    }

    const first = await requestKginicisReceiptInfo(orderNumber, headers);
    if (first.data || first.status !== 401 || !authToken) {
        return { status: first.status, info: first.data };
    }

    // 오래된 localStorage 토큰이 남아 401 이 난 경우 비회원 주문 토큰 또는
    // PG callback 이 발급한 HttpOnly receipt cookie 경로로 한 번 더 시도한다.
    const fallbackHeaders: Record<string, string> = { Accept: 'application/json' };
    if (guestToken) {
        fallbackHeaders['X-Guest-Order-Token'] = guestToken;
    }

    const second = await requestKginicisReceiptInfo(orderNumber, fallbackHeaders);
    return { status: second.status, info: second.data };
}

export async function fetchKginicisReceiptInfo(orderNumber: string): Promise<KginicisReceiptInfo | null> {
    return (await fetchKginicisReceiptInfoDetailed(orderNumber)).info;
}

export function canOpenKginicisReceipt(
    info: KginicisReceiptInfo | null | undefined,
): info is KginicisReceiptInfo {
    if (!info) return false;
    if (info.receipt_url) return true;

    return info.receipt_type === 'cbt_confirmation'
        && Array.isArray(info.receipt_fields)
        && info.receipt_fields.length > 0;
}

export function receiptButtonLabel(info: KginicisReceiptInfo | null | undefined): string {
    return info?.receipt_view_label || '영수증 조회';
}

export function receiptRowLabel(info: KginicisReceiptInfo | null | undefined): string {
    return info?.receipt_label || '영수증';
}

export function openKginicisReceipt(info: KginicisReceiptInfo): void {
    if (info.receipt_url) {
        window.open(info.receipt_url, 'kginicis_receipt', 'width=800,height=600,scrollbars=yes,resizable=yes');
        return;
    }

    if (info.receipt_type === 'cbt_confirmation') {
        openCbtConfirmation(info);
    }
}

function openCbtConfirmation(info: KginicisReceiptInfo): void {
    const popup = window.open('', 'kginicis_receipt', 'width=800,height=700,scrollbars=yes,resizable=yes');
    if (!popup) return;

    const title = info.receipt_title || 'KG 이니시스 CBT 결제확인서';
    const notice = info.receipt_notice || '';
    const rows = (info.receipt_fields ?? [])
        .map(field => `
            <div class="row">
                <dt>${escapeHtml(field.label)}</dt>
                <dd>${escapeHtml(field.value)}</dd>
            </div>
        `)
        .join('');

    popup.document.open();
    popup.document.write(`<!doctype html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>${escapeHtml(title)}</title>
    <style>
        body {
            margin: 0;
            padding: 32px;
            background: #f8fafc;
            color: #111827;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }
        main {
            max-width: 720px;
            margin: 0 auto;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 28px;
            box-shadow: 0 10px 30px rgba(15, 23, 42, .08);
        }
        h1 {
            margin: 0 0 8px;
            font-size: 22px;
            line-height: 1.35;
        }
        .notice {
            margin: 0 0 24px;
            color: #4b5563;
            font-size: 14px;
            line-height: 1.6;
        }
        dl {
            margin: 0;
            border-top: 1px solid #e5e7eb;
        }
        .row {
            display: grid;
            grid-template-columns: 160px minmax(0, 1fr);
            gap: 16px;
            padding: 14px 0;
            border-bottom: 1px solid #e5e7eb;
        }
        dt {
            color: #6b7280;
            font-size: 14px;
        }
        dd {
            margin: 0;
            color: #111827;
            font-size: 14px;
            font-weight: 600;
            overflow-wrap: anywhere;
        }
        .actions {
            margin-top: 24px;
            text-align: right;
        }
        button {
            border: 0;
            border-radius: 6px;
            background: #2563eb;
            color: #fff;
            padding: 10px 14px;
            font-size: 14px;
            cursor: pointer;
        }
        @media (max-width: 520px) {
            body { padding: 16px; }
            main { padding: 20px; }
            .row { grid-template-columns: 1fr; gap: 6px; }
        }
    </style>
</head>
<body>
    <main>
        <h1>${escapeHtml(title)}</h1>
        ${notice ? `<p class="notice">${escapeHtml(notice)}</p>` : ''}
        <dl>${rows}</dl>
        <div class="actions"><button type="button" onclick="window.print()">인쇄</button></div>
    </main>
</body>
</html>`);
    popup.document.close();
}

function escapeHtml(value: string): string {
    return value
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}
