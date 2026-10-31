/**
 * 토스페이먼츠 플러그인 부팅은 코어의 화면 이동 API 와 남의 화면 DOM 을 건드리지 않는다 (#639)
 *
 * 관리자 쇼핑몰 설정의 결제수단 목록에 브랜드 배지를 붙이려고 `history.pushState`·`replaceState` 를
 * 덮어쓰고 이커머스 화면의 아이콘을 DOM 에서 바꿔 끼우던 주입기를 지웠다. 브랜드 배지는 이 플러그인의
 * 결제수단 카탈로그 선언(`brand_mark` → 이커머스 `_cached_brand_mark`)이 단일 출처이고, 그 값을 그리는
 * 일은 화면을 소유한 쪽이 한다. 덮어쓰기는 확장끼리 서로를 감싸 순서에 따라 동작이 바뀐다.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

describe('토스페이먼츠 플러그인 부팅', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.resetModules();
        vi.spyOn(console, 'info').mockImplementation(() => undefined);
        vi.spyOn(console, 'warn').mockImplementation(() => undefined);
        window.history.pushState({}, '', '/admin/ecommerce/settings?tab=order_settings');
    });

    afterEach(() => {
        vi.useRealTimers();
        vi.restoreAllMocks();
        document.body.innerHTML = '';
        delete (window as any).__SirsoftTosspayments;
    });

    /**
     * @scenario surface=history_api
     * @effects history_api_not_overridden
     */
    it('history.pushState / replaceState 를 덮어쓰지 않는다', async () => {
        const pushState = window.history.pushState;
        const replaceState = window.history.replaceState;

        await import('../index');
        await vi.advanceTimersByTimeAsync(6000);

        expect(window.history.pushState).toBe(pushState);
        expect(window.history.replaceState).toBe(replaceState);
    });

    /**
     * @scenario surface=admin_payment_method_dom
     * @effects admin_payment_method_dom_untouched
     */
    it('관리자 결제수단 목록의 아이콘을 DOM 에서 바꿔 끼우지 않는다', async () => {
        document.body.innerHTML = '<div data-payment-method="toss_kakaopay"><svg data-original-icon="true"></svg><span class="font-medium">카카오페이</span></div>';
        const before = document.body.innerHTML;

        await import('../index');
        await vi.advanceTimersByTimeAsync(6000);
        window.history.replaceState({}, '', '/admin/ecommerce/settings?tab=order_settings');
        await vi.advanceTimersByTimeAsync(500);

        expect(document.body.innerHTML).toBe(before);
    });
});
