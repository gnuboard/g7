// e2e:allow PG 영수증 팝업 — 외부 PG(이니시스) 결제 데이터 의존으로 브라우저 E2E 재현 불가, Vitest 회귀(__tests__/handlers/openReceipt.test.ts)로 검증
/**
 * KG이니시스 영수증 창 열기 (플러그인 공용)
 *
 * 영수증 조회는 주문 화면 조각(resources/extensions/*.g7.html)이 플러그인 API 를 불러 화면 상태에 담는다.
 * 이 모듈은 그 응답을 받아 창만 연다 — 외부(PG) 영수증 주소는 크기를 지정한 팝업으로, CBT 결제확인서는
 * 응답 항목으로 그린 인쇄용 문서로. 조각은 `sirsoft-pay_kginicis.openReceipt` 핸들러로 이곳을 부른다.
 */
const PLUGIN_ID = 'sirsoft-pay_kginicis';

export interface KginicisReceiptField {
    /** 항목의 고정 이름 — 화면 스크립트는 번역되는 label 대신 이 값으로 항목을 찾는다 */
    key?: string;
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
    cbt_pay_method?: string | null;
}

/**
 * 플러그인 번역 문구를 얻습니다.
 *
 * @param key 번역 키 (플러그인 네임스페이스 이하)
 * @param fallback 번역 엔진이 없을 때 쓸 문구
 * @returns 번역된 문구
 */
export function translateKginicis(key: string, fallback: string): string {
    const translate = (window as any)?.G7Core?.t;

    if (typeof translate !== 'function') {
        return fallback;
    }

    const full = `${PLUGIN_ID}.${key}`;
    const result = translate(full);

    return typeof result === 'string' && result !== full ? result : fallback;
}

/**
 * 영수증 조회 응답으로 열 수 있는 창이 있는지 판정합니다.
 *
 * @param info 영수증 조회 응답(data)
 * @returns 외부 영수증 주소가 있거나 CBT 확인서 항목이 있으면 true
 */
export function canOpenKginicisReceipt(
    info: KginicisReceiptInfo | null | undefined,
): info is KginicisReceiptInfo {
    if (!info) return false;
    if (info.receipt_url) return true;

    return info.receipt_type === 'cbt_confirmation'
        && Array.isArray(info.receipt_fields)
        && info.receipt_fields.length > 0;
}

/**
 * 외부(PG) 영수증 주소를 팝업으로 엽니다.
 *
 * 보안: https 주소만 열고, 새 창이 이 창(window.opener)을 조작하지 못하게 opener 를 끊은 뒤 주소를 싣는다
 * (역방향 탭내빙 차단). 빈 창을 먼저 여는 이유 — `noopener` 를 주면 팝업 크기 지정이 무시되는 브라우저가 있다.
 *
 * @param url 영수증 주소
 * @param name 창 이름
 * @param features 창 속성
 * @returns 열었으면 true
 */
export function openExternalReceiptWindow(url: unknown, name: string, features: string): boolean {
    if (typeof url !== 'string' || !/^https:\/\//i.test(url)) {
        return false;
    }
    const popup = window.open('', name, features);
    if (popup) {
        popup.opener = null;
        popup.location.href = url;
    }

    return true;
}

/**
 * 영수증 조회 응답으로 영수증 창을 엽니다 — 외부 주소가 있으면 그 주소를, CBT 결제는 확인서 문서를 연다.
 *
 * @param info 영수증 조회 응답(data)
 */
export function openKginicisReceipt(info: KginicisReceiptInfo): void {
    if (info.receipt_url) {
        openExternalReceiptWindow(info.receipt_url, 'kginicis_receipt', 'width=800,height=600,scrollbars=yes,resizable=yes');
        return;
    }

    if (info.receipt_type === 'cbt_confirmation') {
        openCbtConfirmation(info);
    }
}

function openCbtConfirmation(info: KginicisReceiptInfo): void {
    const popup = window.open('', 'kginicis_receipt', 'width=800,height=700,scrollbars=yes,resizable=yes');
    if (!popup) return;

    const title = info.receipt_title || translateKginicis('user.cbt_confirmation_title', 'KG 이니시스 CBT 결제확인서');
    const printLabel = translateKginicis('user.receipt_print', '인쇄');
    const lang = document.documentElement.lang || 'ko';
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
<html lang="${escapeHtml(lang)}">
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
        <div class="actions"><button type="button" onclick="window.print()">${escapeHtml(printLabel)}</button></div>
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
