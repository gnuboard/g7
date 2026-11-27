// e2e:allow PG 영수증 팝업 — 외부 PG(이니시스) 결제 데이터 의존으로 브라우저 E2E 재현 불가, Vitest 회귀(__tests__/handlers/openReceipt.test.ts)로 검증
/**
 * KG이니시스 영수증 창 열기 (플러그인 공용)
 *
 * 영수증 조회는 주문 화면 조각(resources/extensions/*.g7.html)이 플러그인 API 를 불러 화면 상태에 담는다.
 * 이 모듈은 그 응답을 받아 창만 연다 — 외부(PG) 영수증 주소는 크기를 지정한 팝업으로, CBT 결제확인서는
 * 응답 항목으로 그린 인쇄용 문서로. 조각은 `sirsoft-pay_kginicis.openReceipt` 핸들러로 이곳을 부른다.
 */
const PLUGIN_ID = 'sirsoft-pay_kginicis';

/** CBT 결제확인서 팝업의 CSS 파일 — 플러그인 루트 기준 경로 (빌드 대상이 아닌 정적 파일) */
const RECEIPT_STYLESHEET = 'assets/bridge/receipt-popup.css';

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
        // base-path:allow 바로 위에서 https 로 시작함을 확인한 PG 영수증 절대 URL 이다 — 사이트 경로가 아니다.
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

/**
 * CBT 결제확인서 팝업이 실을 CSS 파일의 절대 주소를 만듭니다.
 *
 * 주소는 코어의 자산 주소 API 로만 만든다(손으로 조립하지 않는다). 팝업은 `about:blank` 라 상대 주소가 풀리지
 * 않으므로 여는 창의 출처를 붙인다. API 가 없는 코어이거나 주소를 만들지 못하면 null — 팝업은 링크 없이 그려진다
 * (무스타일이지만 내용과 인쇄는 된다).
 *
 * @returns CSS 절대 주소 또는 null
 */
function receiptStylesheetHref(): string | null {
    const build = (window as any)?.G7Core?.asset?.plugin;
    if (typeof build !== 'function') {
        return null;
    }

    try {
        const path = build(PLUGIN_ID, RECEIPT_STYLESHEET);
        if (typeof path !== 'string' || path === '') {
            return null;
        }

        return new URL(path, window.location.origin).href;
    } catch {
        return null;
    }
}

function openCbtConfirmation(info: KginicisReceiptInfo): void {
    const popup = window.open('', 'kginicis_receipt', 'width=800,height=700,scrollbars=yes,resizable=yes');
    if (!popup) return;

    const title = info.receipt_title || translateKginicis('user.cbt_confirmation_title', 'KG 이니시스 CBT 결제확인서');
    const printLabel = translateKginicis('user.receipt_print', '인쇄');
    const lang = document.documentElement.lang || 'ko';
    const notice = info.receipt_notice || '';
    // 모양은 같은 출처 CSS 파일로 싣는다 — 빈 팝업은 여는 문서의 스타일 보안 정책을 물려받아 `<style>` 블록이 막힌다.
    const stylesheetHref = receiptStylesheetHref();
    const stylesheetLink = stylesheetHref ? `<link rel="stylesheet" href="${escapeHtml(stylesheetHref)}">` : '';
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
    ${stylesheetLink}
</head>
<body>
    <main>
        <h1>${escapeHtml(title)}</h1>
        ${notice ? `<p class="notice">${escapeHtml(notice)}</p>` : ''}
        <dl>${rows}</dl>
        <div class="actions"><button type="button" data-print="1">${escapeHtml(printLabel)}</button></div>
    </main>
</body>
</html>`);
    popup.document.close();

    // 인쇄 버튼은 인라인 onclick 없이 리스너로 묶는다 — 빈 팝업은 여는 문서의 CSP 를 물려받아
    // strict CSP(`script-src 'self'`) 사이트에서는 인라인 핸들러가 실행되지 않는다(공개 #158).
    popup.document.querySelector('[data-print]')?.addEventListener('click', () => popup.print());
}

function escapeHtml(value: string): string {
    return value
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}
