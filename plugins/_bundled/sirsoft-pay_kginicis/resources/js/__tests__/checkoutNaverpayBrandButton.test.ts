import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    installCheckoutNaverpayBrandButton,
    patchRenderedNaverpayBrandButton,
    resetCheckoutNaverpayBrandButtonForTests,
} from '../checkoutNaverpayBrandButton';

function brandButton(label: string, description: string, icon = 'wallet'): string {
    return `
        <button type="button">
            <div class="flex items-center gap-2">
                <i class="fas fa-${icon}" data-original-icon="true" role="img"></i>
                <div>
                    <p>${label}</p>
                    <p>${description}</p>
                </div>
            </div>
        </button>
    `;
}

function renderPaymentButtons(): void {
    document.body.innerHTML = `
        ${brandButton('네이버페이 (KG이니시스)', '네이버페이로 결제')}
        ${brandButton('삼성페이 (KG이니시스)', '삼성페이로 결제 — KG 이니시스를 통해 처리', 'mobile-screen-button')}
        ${brandButton('L.pay (KG이니시스)', 'L.pay 로 결제 — KG 이니시스를 통해 처리', 'mobile-screen-button')}
        ${brandButton('카카오페이 (KG이니시스)', '카카오페이로 결제 — KG 이니시스를 통해 처리', 'mobile-screen-button')}
        ${brandButton('PayPay (일본 KG이니시스)', '일본 엔(JPY) 주문을 KG 이니시스 CBT PayPay로 결제')}
        ${brandButton('일본 편의점결제 (KG이니시스)', '일본 엔(JPY) 주문을 KG 이니시스 CBT 편의점 결제로 접수', 'store')}
        <button type="button">
            <div class="flex items-center gap-3">
                <svg data-original-icon="true"></svg>
                <div>
                    <p>신용카드</p>
                    <p>카드로 결제</p>
                </div>
            </div>
        </button>
    `;
}

describe('checkoutNaverpayBrandButton', () => {
    beforeEach(() => {
        document.documentElement.lang = 'ko';
        window.history.pushState({}, '', '/shop/checkout');
        vi.spyOn(console, 'info').mockImplementation(() => {});
    });

    afterEach(() => {
        resetCheckoutNaverpayBrandButtonForTests();
        document.body.innerHTML = '';
        vi.restoreAllMocks();
    });

    it('네이버페이 버튼에 title 과 브랜드 마크를 적용한다', () => {
        renderPaymentButtons();

        expect(patchRenderedNaverpayBrandButton()).toBe(true);

        const naverpayButton = document.querySelector<HTMLButtonElement>('button[data-kginicis-brand-payment-method="kginicis_naverpay"]');
        expect(naverpayButton).not.toBeNull();
        expect(naverpayButton?.title).toBe('네이버페이로 결제 (kg이니시스)');
        expect(naverpayButton?.dataset.kginicisNaverpayBrandButton).toBe('true');
        expect(naverpayButton?.querySelector('[data-kginicis-brand-payment-mark="true"]')).not.toBeNull();
        expect(naverpayButton?.querySelector('[data-kginicis-naverpay-mark="true"]')).not.toBeNull();
        expect(naverpayButton?.querySelector('[data-original-icon="true"]')).toBeNull();
        const heading = naverpayButton?.querySelector<HTMLElement>('p');
        expect(heading?.getAttribute('aria-label')).toBe('네이버페이');
        expect(heading?.textContent).toBe('네이버페이');
        expect(heading?.querySelector('span')).toBeNull();
        expect(heading?.style.fontSize).toBe('');
        expect(heading?.style.whiteSpace).toBe('normal');
        expect(heading?.style.wordBreak).toBe('keep-all');
        expect(heading?.style.overflowWrap).toBe('anywhere');
        const description = naverpayButton?.querySelectorAll<HTMLElement>('p')[1];
        expect(description?.textContent).toBe('네이버페이로 결제 (kg이니시스)');
        expect(description?.style.fontSize).toBe('12px');
        expect(description?.style.lineHeight).toBe('1rem');
        const row = naverpayButton?.querySelector<HTMLElement>('.flex.items-center');
        expect(row?.style.width).toBe('100%');
        expect(row?.style.minWidth).toBe('0');
        expect(row?.style.maxWidth).toBe('100%');
        const textWrapper = heading?.parentElement;
        expect(textWrapper?.style.flex).toBe('1 1 0px');
        expect(textWrapper?.style.minWidth).toBe('0');
        expect(textWrapper?.style.maxWidth).toBe('100%');
        expect(naverpayButton?.querySelector<HTMLElement>('[data-kginicis-brand-payment-mark="true"]')?.style.width).toBe('32px');

        const cardButton = Array.from(document.querySelectorAll<HTMLButtonElement>('button'))
            .find((button) => button.textContent?.includes('신용카드'));
        expect(cardButton?.querySelector('[data-original-icon="true"]')).not.toBeNull();
    });

    it('브랜드 버튼 설정 대상 결제수단을 모두 아이콘과 짧은 문구로 표시한다', () => {
        renderPaymentButtons();

        expect(patchRenderedNaverpayBrandButton()).toBe(true);

        const expected = [
            ['kginicis_samsung_pay', '삼성페이', '삼성페이로 결제 (kg이니시스)'],
            ['kginicis_naverpay', '네이버페이', '네이버페이로 결제 (kg이니시스)'],
            ['kginicis_lpay', 'L.pay', 'L.pay로 결제 (kg이니시스)'],
            ['kginicis_kakaopay', '카카오페이', '카카오페이로 결제 (kg이니시스)'],
            ['kginicis_japan_paypay', 'PayPay', 'PayPay로 결제 (일본 KG)'],
            ['kginicis_japan_cvs', '일본 편의점결제', '편의점 결제 (일본 KG)'],
        ];

        expected.forEach(([methodId, heading, description]) => {
            const button = document.querySelector<HTMLButtonElement>(`button[data-kginicis-brand-payment-method="${methodId}"]`);
            expect(button).not.toBeNull();
            expect(button?.querySelector('[data-kginicis-brand-payment-mark="true"]')).not.toBeNull();
            expect(button?.querySelector('[data-original-icon="true"]')).toBeNull();
            expect(button?.querySelectorAll<HTMLElement>('p')[0]?.textContent).toBe(heading);
            expect(button?.querySelectorAll<HTMLElement>('p')[1]?.textContent).toBe(description);
        });

        expect(document.querySelectorAll('button[data-kginicis-brand-payment-button="true"]')).toHaveLength(6);
        expect(document.querySelectorAll('[data-kginicis-brand-payment-mark="true"]')).toHaveLength(6);
    });

    it('좁은 카드에서는 제목과 설명을 총 2줄 compact layout 으로 정렬한다', () => {
        renderPaymentButtons();

        vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockImplementation(function (this: HTMLElement): DOMRect {
            const isNaverpayButton = this instanceof HTMLButtonElement
                && (this.textContent ?? '').includes('네이버페이');
            const width = isNaverpayButton ? 180 : 0;

            return {
                bottom: 80,
                height: 80,
                left: 0,
                right: width,
                top: 0,
                width,
                x: 0,
                y: 0,
                toJSON: () => ({}),
            } as DOMRect;
        });

        expect(patchRenderedNaverpayBrandButton()).toBe(true);

        const naverpayButton = document.querySelector<HTMLButtonElement>('button[data-kginicis-brand-payment-method="kginicis_naverpay"]');
        const row = naverpayButton?.querySelector<HTMLElement>('.flex.items-center');
        const paragraphs = naverpayButton?.querySelectorAll<HTMLElement>('p');
        const heading = paragraphs?.[0];
        const description = paragraphs?.[1];
        const textWrapper = heading?.parentElement;

        expect(naverpayButton?.style.paddingLeft).toBe('10px');
        expect(naverpayButton?.style.paddingRight).toBe('10px');
        expect(row?.style.gap).toBe('6px');
        expect(row?.style.maxWidth).toBe('100%');
        expect(row?.style.flexWrap).toBe('wrap');
        expect(textWrapper?.style.display).toBe('contents');
        expect(heading?.style.order).toBe('2');
        expect(heading?.style.flex).toBe('1 1 0px');
        expect(description?.style.order).toBe('3');
        expect(description?.style.flex).toBe('0 0 100%');
        expect(description?.style.whiteSpace).toBe('nowrap');
        expect(description?.style.wordBreak).toBe('normal');
        expect(description?.style.overflowWrap).toBe('normal');
        expect(description?.style.fontSize).toBe('11px');
    });

    it('클라이언트 설정이 비활성화면 버튼을 건드리지 않는다', async () => {
        renderPaymentButtons();

        const fetchSpy = vi.fn(async () => new Response(JSON.stringify({
            data: { easy_pay_show_brand_button: false },
        }), { status: 200, headers: { 'Content-Type': 'application/json' } }));

        installCheckoutNaverpayBrandButton(fetchSpy as unknown as typeof fetch);
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(document.querySelector('[data-kginicis-brand-payment-button="true"]')).toBeNull();
        expect(document.querySelector('[data-kginicis-brand-payment-mark="true"]')).toBeNull();
    });

    it('클라이언트 설정이 활성화면 체크아웃에서 자동 적용한다', async () => {
        renderPaymentButtons();

        const fetchSpy = vi.fn(async () => new Response(JSON.stringify({
            data: { easy_pay_show_brand_button: true },
        }), { status: 200, headers: { 'Content-Type': 'application/json' } }));

        installCheckoutNaverpayBrandButton(fetchSpy as unknown as typeof fetch);
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(document.querySelector('[data-kginicis-brand-payment-button="true"]')).not.toBeNull();
        expect(document.querySelector('[data-kginicis-brand-payment-mark="true"]')).not.toBeNull();
    });

    it('결제수단 텍스트가 늦게 채워져도 자동 적용한다', async () => {
        document.body.innerHTML = `
            <button>
                <div class="flex items-center gap-3">
                    <i class="fas fa-wallet" data-original-icon="true" role="img"></i>
                    <div>
                        <p id="late-heading"></p>
                        <p id="late-description"></p>
                    </div>
                </div>
            </button>
        `;

        const heading = document.getElementById('late-heading');
        const description = document.getElementById('late-description');
        heading?.appendChild(document.createTextNode(''));
        description?.appendChild(document.createTextNode(''));

        const fetchSpy = vi.fn(async () => new Response(JSON.stringify({
            data: { easy_pay_show_brand_button: true },
        }), { status: 200, headers: { 'Content-Type': 'application/json' } }));

        installCheckoutNaverpayBrandButton(fetchSpy as unknown as typeof fetch);
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(document.querySelector('[data-kginicis-brand-payment-button="true"]')).toBeNull();

        if (heading?.firstChild) heading.firstChild.nodeValue = '네이버페이 (KG이니시스)';
        if (description?.firstChild) description.firstChild.nodeValue = '네이버페이로 결제';
        await new Promise((resolve) => setTimeout(resolve, 0));

        const naverpayButton = document.querySelector<HTMLButtonElement>('button[data-kginicis-brand-payment-method="kginicis_naverpay"]');
        expect(naverpayButton).not.toBeNull();
        expect(naverpayButton?.title).toBe('네이버페이로 결제 (kg이니시스)');
        expect(naverpayButton?.querySelector('[data-kginicis-brand-payment-mark="true"]')).not.toBeNull();
        expect(naverpayButton?.querySelector<HTMLElement>('p')?.textContent).toBe('네이버페이');
        expect(naverpayButton?.querySelectorAll<HTMLElement>('p')[1]?.textContent).toBe('네이버페이로 결제 (kg이니시스)');
    });
});
