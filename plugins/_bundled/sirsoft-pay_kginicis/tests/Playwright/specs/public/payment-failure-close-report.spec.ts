/**
 * E2E: 결제 실패 화면 복귀 시 close-report 보고 회귀 가드
 *
 * @scenario payment_failure_return_reports_to_close_report
 * @effects close_report_posted_on_failure_return, no_report_without_pending_context,
 *          no_report_on_success_return, close_report_omits_buyer_contact
 *
 * 배경: 브라우저 리턴 콜백(PC·모바일·해외결제)은 PG 서명도 IP 증명도 없고 주문번호가
 * 요청자가 고른 값이라, 승인 실패를 근거로 주문을 취소하면 남의 주문번호를 아는 것만으로
 * 그 주문이 취소된다. 그래서 콜백에서 주문 상태 변경을 걷어냈고, 정당한 결제 실패는
 * 주문 소유자(회원 로그인 / 비회원 조회 토큰)를 확인하는 close-report 가 기록하도록 바꿨다.
 * 주문자 이메일·전화번호는 제3자도 알 수 있어 소유 증명이 아니므로 보내지 않는다 (KVE-2026-2434).
 *
 * 결제창은 전체 페이지 이동으로 열리고 돌아와 JS 컨텍스트가 소실되므로, 결제 요청 직전에
 * sessionStorage 에 남긴 정보로 실패 화면에서 보고한다. 이 spec 은 그 복귀 보고가 실제
 * 브라우저에서 발화하는지를 지킨다 — 발화하지 않으면 정당한 결제 실패가 어디에도 기록되지
 * 않는데, 화면에는 아무 증상도 나타나지 않는다.
 *
 * PG 결제창 자체는 자동화할 수 없으므로, 결제 요청 직전 상태(sessionStorage 마커)를 직접
 * 심어 "실패 화면으로 돌아온 순간" 부터를 재현한다. 마커는 **돌아온 문서가 로드되기 직전에**
 * 심는다(`addInitScript`) — 이전 화면에서 심고 이동하면 그 화면의 `beforeunload` 정리가 마커를
 * 지워 보고가 발화하지 않는다. 서버 응답(403 등)과 비회원 토큰 헤더는 이 spec 의 관심사가
 * 아니다 — 헤더 부착은 Vitest(paymentCloseMessageListener)가 잠근다.
 */
import { expect, test, type Page } from '@playwright/test';

const STORAGE_KEY = 'g7:sirsoft-pay_kginicis:pendingClose';

const CLOSE_REPORT_URL = '/api/plugins/sirsoft-pay_kginicis/payment/close-report';

const ORDER_NUMBER = 'E2E-KGI-FAILRETURN-0001';

/**
 * 결제 요청 직전에 저장되는 보고용 컨텍스트.
 */
const PENDING_CONTEXT = {
    closeReportUrl: '/plugins/sirsoft-pay_kginicis/payment/close-report',
    oid: ORDER_NUMBER,
    price: 10000,
    payment_method: 'card',
    reported: false,
};

/**
 * 다음 문서 로드 직전에 보고용 컨텍스트를 한 번만 심는다 (같은 탭 재방문에는 다시 심지 않는다).
 *
 * @param page 대상 페이지
 */
async function seedPendingContextOnce(page: Page): Promise<void> {
    await page.addInitScript(
        ([key, value]) => {
            if (window.name === 'e2e-kgi-pending-seeded') {
                return;
            }
            window.name = 'e2e-kgi-pending-seeded';
            window.sessionStorage.setItem(key, value);
        },
        [STORAGE_KEY, JSON.stringify(PENDING_CONTEXT)] as const,
    );
}

test.describe('KG이니시스 결제 실패 복귀 보고', () => {
    /** @effects close_report_posted_on_failure_return, close_report_omits_buyer_contact */
    test('실패 화면으로 돌아오면 close-report 로 보고한다', async ({ page }) => {
        await seedPendingContextOnce(page);

        const reportRequest = page.waitForRequest(
            (request) => request.url().includes(CLOSE_REPORT_URL) && request.method() === 'POST',
            { timeout: 20_000 },
        );

        // 결제 승인이 거절되어 실패 URL 로 돌아온 상황.
        await page.goto(`/shop/checkout?error=9999&message=fail&orderId=${ORDER_NUMBER}`);

        const request = await reportRequest;
        const body = request.postDataJSON() as Record<string, unknown>;

        expect(body.oid).toBe(ORDER_NUMBER);
        expect(body.price).toBe(10000);
        // 소유 증명은 로그인·비회원 조회 토큰이다. 주문자 연락처는 싣지 않는다.
        expect(body).not.toHaveProperty('buyer_email');
        expect(body).not.toHaveProperty('buyer_phone');
    });

    /** @effects no_report_without_pending_context */
    test('저장해 둔 정보가 없으면 보고하지 않는다', async ({ page }) => {
        await page.goto('/shop/checkout');
        await page.evaluate((key) => window.sessionStorage.removeItem(key), STORAGE_KEY);

        let reported = false;
        page.on('request', (request) => {
            if (request.url().includes(CLOSE_REPORT_URL) && request.method() === 'POST') {
                reported = true;
            }
        });

        await page.goto(`/shop/checkout?error=9999&orderId=${ORDER_NUMBER}`);
        await page.waitForLoadState('networkidle', { timeout: 20_000 });

        // 결제창을 연 적이 없는데 실패 파라미터만 붙은 주소로 들어온 경우다 — 보고 대상이 아니다.
        expect(reported).toBe(false);
    });

    /** @effects close_report_posted_on_failure_return */
    test('보고 후에는 저장분이 지워져 중복 보고하지 않는다', async ({ page }) => {
        await seedPendingContextOnce(page);

        let reportCount = 0;
        page.on('request', (request) => {
            if (request.url().includes(CLOSE_REPORT_URL) && request.method() === 'POST') {
                reportCount++;
            }
        });

        await page.goto(`/shop/checkout?error=9999&orderId=${ORDER_NUMBER}`);
        await page.waitForLoadState('networkidle', { timeout: 20_000 });

        const remaining = await page.evaluate((key) => window.sessionStorage.getItem(key), STORAGE_KEY);
        expect(remaining).toBeNull();
        expect(reportCount).toBe(1);

        // 같은 주소를 다시 열어도 보고가 반복되지 않는다.
        await page.goto(`/shop/checkout?error=9999&orderId=${ORDER_NUMBER}`);
        await page.waitForLoadState('networkidle', { timeout: 20_000 });

        expect(reportCount).toBe(1);
    });
});
