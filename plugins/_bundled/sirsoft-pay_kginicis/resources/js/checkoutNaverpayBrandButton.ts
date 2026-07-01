const PLUGIN_ID = 'sirsoft-pay_kginicis';
const FLAG = '__sirsoftKginicisCheckoutBrandPaymentButtonsInstalled';
const CHECKOUT_RE = /^\/shop\/checkout\/?$/;
const CLIENT_CONFIG_PATH = '/api/modules/sirsoft-ecommerce/payments/client-config/kginicis';
const TWO_LINE_COMPACT_WIDTH = 220;
const DEFAULT_PADDING_X = 12;
const COMPACT_PADDING_X = 10;

let observer: MutationObserver | null = null;
let cachedEnabled: Promise<boolean> | null = null;
let retryTimer: number | null = null;

interface ClientConfigBody {
    data?: {
        easy_pay_show_brand_button?: boolean;
    };
}

interface BrandPaymentCopy {
    heading: string;
    description: string;
    title: string;
}

interface BrandPaymentDefinition {
    id: string;
    labels: string[];
    ko: BrandPaymentCopy;
    en: BrandPaymentCopy;
    markSvg: string;
}

const BRAND_PAYMENT_DEFINITIONS: BrandPaymentDefinition[] = [
    {
        id: 'kginicis_naverpay',
        labels: ['네이버페이 (KG이니시스)', 'Naver Pay (KG Inicis)'],
        ko: {
            heading: '네이버페이',
            description: '네이버페이로 결제 (kg이니시스)',
            title: '네이버페이로 결제 (kg이니시스)',
        },
        en: {
            heading: 'Naver Pay',
            description: 'Pay with Naver Pay (KG Inicis)',
            title: 'Pay with Naver Pay (KG Inicis)',
        },
        markSvg: [
            '<svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 40 40" role="img" aria-label="Naver Pay">',
            '<rect width="40" height="40" rx="8" fill="#03C75A"/>',
            '<text x="20" y="17" text-anchor="middle" fill="#ffffff" font-family="Arial, sans-serif" font-size="12" font-weight="700">N</text>',
            '<text x="20" y="29" text-anchor="middle" fill="#ffffff" font-family="Arial, sans-serif" font-size="9" font-weight="700">Pay</text>',
            '</svg>',
        ].join(''),
    },
    {
        id: 'kginicis_samsung_pay',
        labels: ['삼성페이 (KG이니시스)', 'Samsung Pay (KG Inicis)'],
        ko: {
            heading: '삼성페이',
            description: '삼성페이로 결제 (kg이니시스)',
            title: '삼성페이로 결제 (kg이니시스)',
        },
        en: {
            heading: 'Samsung Pay',
            description: 'Pay with Samsung Pay (KG Inicis)',
            title: 'Pay with Samsung Pay (KG Inicis)',
        },
        markSvg: [
            '<svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 40 40" role="img" aria-label="Samsung Pay">',
            '<rect width="40" height="40" rx="8" fill="#1428A0"/>',
            '<text x="20" y="18" text-anchor="middle" fill="#ffffff" font-family="Arial, sans-serif" font-size="10" font-weight="700">S</text>',
            '<text x="20" y="29" text-anchor="middle" fill="#ffffff" font-family="Arial, sans-serif" font-size="9" font-weight="700">Pay</text>',
            '</svg>',
        ].join(''),
    },
    {
        id: 'kginicis_lpay',
        labels: ['L.pay (KG이니시스)', 'L.pay (KG Inicis)'],
        ko: {
            heading: 'L.pay',
            description: 'L.pay로 결제 (kg이니시스)',
            title: 'L.pay로 결제 (kg이니시스)',
        },
        en: {
            heading: 'L.pay',
            description: 'Pay with L.pay (KG Inicis)',
            title: 'Pay with L.pay (KG Inicis)',
        },
        markSvg: [
            '<svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 40 40" role="img" aria-label="L.pay">',
            '<rect width="40" height="40" rx="8" fill="#D71920"/>',
            '<text x="20" y="18" text-anchor="middle" fill="#ffffff" font-family="Arial, sans-serif" font-size="12" font-weight="700">L</text>',
            '<text x="20" y="29" text-anchor="middle" fill="#ffffff" font-family="Arial, sans-serif" font-size="8" font-weight="700">pay</text>',
            '</svg>',
        ].join(''),
    },
    {
        id: 'kginicis_kakaopay',
        labels: ['카카오페이 (KG이니시스)', 'Kakao Pay (KG Inicis)'],
        ko: {
            heading: '카카오페이',
            description: '카카오페이로 결제 (kg이니시스)',
            title: '카카오페이로 결제 (kg이니시스)',
        },
        en: {
            heading: 'Kakao Pay',
            description: 'Pay with Kakao Pay (KG Inicis)',
            title: 'Pay with Kakao Pay (KG Inicis)',
        },
        markSvg: [
            '<svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 40 40" role="img" aria-label="Kakao Pay">',
            '<rect width="40" height="40" rx="8" fill="#FEE500"/>',
            '<text x="20" y="18" text-anchor="middle" fill="#111111" font-family="Arial, sans-serif" font-size="9" font-weight="700">Kakao</text>',
            '<text x="20" y="29" text-anchor="middle" fill="#111111" font-family="Arial, sans-serif" font-size="9" font-weight="700">Pay</text>',
            '</svg>',
        ].join(''),
    },
    {
        id: 'kginicis_japan_paypay',
        labels: ['PayPay (일본 KG이니시스)', 'PayPay (KG Inicis Japan)'],
        ko: {
            heading: 'PayPay',
            description: 'PayPay로 결제 (일본 KG)',
            title: 'PayPay로 결제 (일본 KG이니시스)',
        },
        en: {
            heading: 'PayPay',
            description: 'Pay with PayPay (KG Japan)',
            title: 'Pay with PayPay (KG Inicis Japan)',
        },
        markSvg: [
            '<svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 40 40" role="img" aria-label="PayPay">',
            '<rect width="40" height="40" rx="8" fill="#E60012"/>',
            '<text x="20" y="18" text-anchor="middle" fill="#ffffff" font-family="Arial, sans-serif" font-size="8" font-weight="700">Pay</text>',
            '<text x="20" y="29" text-anchor="middle" fill="#ffffff" font-family="Arial, sans-serif" font-size="8" font-weight="700">Pay</text>',
            '</svg>',
        ].join(''),
    },
    {
        id: 'kginicis_japan_cvs',
        labels: ['일본 편의점결제 (KG이니시스)', 'Japan Convenience Store (KG Inicis)'],
        ko: {
            heading: '일본 편의점결제',
            description: '편의점 결제 (일본 KG)',
            title: '일본 편의점결제 (KG이니시스)',
        },
        en: {
            heading: 'Convenience Store',
            description: 'Pay at store (KG Japan)',
            title: 'Japan Convenience Store (KG Inicis)',
        },
        markSvg: [
            '<svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 40 40" role="img" aria-label="Convenience Store">',
            '<rect width="40" height="40" rx="8" fill="#0072CE"/>',
            '<text x="20" y="18" text-anchor="middle" fill="#ffffff" font-family="Arial, sans-serif" font-size="9" font-weight="700">CVS</text>',
            '<text x="20" y="29" text-anchor="middle" fill="#ffffff" font-family="Arial, sans-serif" font-size="8" font-weight="700">JP</text>',
            '</svg>',
        ].join(''),
    },
];

const logger = {
    info: (...args: unknown[]) => console.info(`[${PLUGIN_ID}]`, ...args),
};

function windowRecord(): Record<string, unknown> {
    return window as unknown as Record<string, unknown>;
}

function isCheckoutPage(): boolean {
    return CHECKOUT_RE.test(window.location.pathname);
}

function isKoreanPage(): boolean {
    const lang = document.documentElement.lang || navigator.language || '';
    return lang.toLowerCase().startsWith('ko');
}

function copyFor(definition: BrandPaymentDefinition): BrandPaymentCopy {
    return isKoreanPage() ? definition.ko : definition.en;
}

function normalizedText(value: string | null | undefined): string {
    return (value ?? '').replace(/\s+/g, ' ').trim();
}

function getButtonWidth(button: HTMLButtonElement): number {
    const rectWidth = button.getBoundingClientRect().width;

    return rectWidth > 0 ? rectWidth : button.clientWidth;
}

function shouldUseTwoLineCompactLayout(button: HTMLButtonElement): boolean {
    const width = getButtonWidth(button);

    return width > 0 && width < TWO_LINE_COMPACT_WIDTH;
}

function compactDescriptionFontSize(button: HTMLButtonElement): string {
    const availableWidth = getButtonWidth(button) - (COMPACT_PADDING_X * 2);

    if (availableWidth > 0 && availableWidth < 144) {
        return '9.5px';
    }

    if (availableWidth > 0 && availableWidth < 160) {
        return '10px';
    }

    if (availableWidth > 0 && availableWidth < 176) {
        return '11px';
    }

    return '12px';
}

async function fetchEnabled(fetchImpl: typeof fetch): Promise<boolean> {
    if (cachedEnabled !== null) return cachedEnabled;

    cachedEnabled = (async () => {
        try {
            const response = await fetchImpl(CLIENT_CONFIG_PATH, {
                headers: { Accept: 'application/json' },
            });
            if (!response.ok) return false;

            const body = (await response.json()) as ClientConfigBody;
            return body.data?.easy_pay_show_brand_button === true;
        } catch {
            return false;
        }
    })();

    return cachedEnabled;
}

function findBrandPaymentDefinition(button: HTMLButtonElement): BrandPaymentDefinition | null {
    const methodId = button.dataset.kginicisBrandPaymentMethod;
    const existing = BRAND_PAYMENT_DEFINITIONS.find((definition) => definition.id === methodId);
    if (existing) return existing;

    if (button.dataset.kginicisNaverpayBrandButton === 'true') {
        return BRAND_PAYMENT_DEFINITIONS.find((definition) => definition.id === 'kginicis_naverpay') ?? null;
    }

    const text = normalizedText(button.textContent);

    return BRAND_PAYMENT_DEFINITIONS.find((definition) => {
        const copy = copyFor(definition);

        return definition.labels.some((label) => text.includes(label))
            || text.includes(definition.ko.heading)
            || text.includes(definition.en.heading)
            || text.includes(copy.heading);
    }) ?? null;
}

function findHeading(
    paragraphs: HTMLParagraphElement[] | HTMLElement[],
    definition: BrandPaymentDefinition,
): HTMLElement | null {
    const copy = copyFor(definition);

    return paragraphs.find((element) => {
        const text = normalizedText(element.textContent);

        return element.dataset.kginicisBrandPaymentHeading === definition.id
            || definition.labels.some((label) => text.includes(label))
            || text.includes(definition.ko.heading)
            || text.includes(definition.en.heading)
            || text.includes(copy.heading);
    }) ?? null;
}

function formatBrandPaymentText(button: HTMLButtonElement, definition: BrandPaymentDefinition): void {
    const paragraphs = Array.from(button.querySelectorAll<HTMLParagraphElement>('p'));
    const heading = findHeading(paragraphs, definition);

    if (!heading) return;

    const copy = copyFor(definition);
    if (heading.textContent !== copy.heading) {
        heading.textContent = copy.heading;
    }
    heading.dataset.kginicisBrandPaymentHeading = definition.id;
    if (definition.id === 'kginicis_naverpay') {
        heading.dataset.kginicisNaverpayHeading = copy.heading;
    }
    if (heading.getAttribute('aria-label') !== copy.heading) {
        heading.setAttribute('aria-label', copy.heading);
    }

    const description = paragraphs[paragraphs.indexOf(heading as HTMLParagraphElement) + 1]
        ?? paragraphs.find((element) => element !== heading);
    if (!description) return;

    if (description.textContent !== copy.description) {
        description.textContent = copy.description;
    }
    description.dataset.kginicisBrandPaymentDescription = definition.id;
    if (definition.id === 'kginicis_naverpay') {
        description.dataset.kginicisNaverpayDescription = copy.description;
    }
}

function applyCompactBrandPaymentLayout(button: HTMLButtonElement, definition: BrandPaymentDefinition): void {
    const useTwoLineCompact = shouldUseTwoLineCompactLayout(button);
    const paddingX = useTwoLineCompact ? COMPACT_PADDING_X : DEFAULT_PADDING_X;

    button.style.paddingLeft = `${paddingX}px`;
    button.style.paddingRight = `${paddingX}px`;
    button.style.boxSizing = 'border-box';
    button.style.minWidth = '0';

    const row = button.querySelector<HTMLElement>('.flex.items-center.gap-2, .flex.items-center.gap-3')
        ?? button.querySelector<HTMLElement>('.flex.items-center');
    if (row) {
        row.style.gap = useTwoLineCompact ? '6px' : '8px';
        row.style.width = '100%';
        row.style.minWidth = '0';
        row.style.maxWidth = '100%';
        row.style.boxSizing = 'border-box';
        row.style.flexWrap = useTwoLineCompact ? 'wrap' : 'nowrap';
    }

    const heading = findHeading(
        Array.from(button.querySelectorAll<HTMLElement>('p')),
        definition,
    );

    if (!heading) return;

    heading.style.whiteSpace = 'normal';
    heading.style.wordBreak = 'keep-all';
    heading.style.overflowWrap = 'anywhere';
    heading.style.removeProperty('font-size');
    heading.style.removeProperty('line-height');
    heading.style.maxWidth = '100%';

    const textWrapper = heading.parentElement;
    if (textWrapper instanceof HTMLElement) {
        textWrapper.style.display = useTwoLineCompact ? 'contents' : '';
        textWrapper.style.flex = useTwoLineCompact ? '' : '1 1 0px';
        textWrapper.style.minWidth = '0';
        textWrapper.style.maxWidth = '100%';

        Array.from(textWrapper.querySelectorAll<HTMLElement>('p')).forEach((paragraph) => {
            paragraph.style.minWidth = '0';
            paragraph.style.maxWidth = '100%';
            paragraph.style.whiteSpace = 'normal';
            paragraph.style.wordBreak = 'keep-all';
            paragraph.style.overflowWrap = 'anywhere';
            paragraph.style.removeProperty('order');
            paragraph.style.removeProperty('flex');
        });

        const description = Array.from(textWrapper.querySelectorAll<HTMLElement>('p')).find((paragraph) => (
            paragraph.dataset.kginicisBrandPaymentDescription === definition.id
                || paragraph !== heading
        ));

        if (description) {
            if (useTwoLineCompact) {
                heading.style.order = '2';
                heading.style.flex = '1 1 0px';

                description.style.order = '3';
                description.style.flex = '0 0 100%';
                description.style.whiteSpace = 'nowrap';
                description.style.wordBreak = 'normal';
                description.style.overflowWrap = 'normal';
            }

            description.style.fontSize = useTwoLineCompact
                ? compactDescriptionFontSize(button)
                : '12px';
            description.style.lineHeight = '1rem';
        }
    }
}

function createBrandPaymentMark(definition: BrandPaymentDefinition): HTMLSpanElement {
    const mark = document.createElement('span');
    mark.dataset.kginicisBrandPaymentMark = 'true';
    mark.dataset.kginicisBrandPaymentMethod = definition.id;
    if (definition.id === 'kginicis_naverpay') {
        mark.dataset.kginicisNaverpayMark = 'true';
    }
    mark.setAttribute('aria-hidden', 'true');
    mark.style.display = 'inline-flex';
    mark.style.width = '32px';
    mark.style.height = '32px';
    mark.style.flex = '0 0 32px';
    mark.style.alignItems = 'center';
    mark.style.justifyContent = 'center';
    mark.innerHTML = definition.markSvg;

    return mark;
}

function findPaymentIcon(button: HTMLButtonElement): Element | null {
    return button.querySelector('svg')
        ?? button.querySelector('i[class*="fa-"], i[role="img"], i');
}

function findPaymentRow(button: HTMLButtonElement): HTMLElement | null {
    return button.querySelector<HTMLElement>('.flex.items-center.gap-2, .flex.items-center.gap-3')
        ?? button.querySelector<HTMLElement>('.flex.items-center');
}

export function patchRenderedNaverpayBrandButton(root: ParentNode = document): boolean {
    let patched = false;

    root.querySelectorAll<HTMLButtonElement>('button').forEach((button) => {
        const definition = findBrandPaymentDefinition(button);
        if (!definition) return;

        const copy = copyFor(definition);
        button.title = copy.title;
        button.dataset.kginicisBrandPaymentButton = 'true';
        button.dataset.kginicisBrandPaymentMethod = definition.id;
        if (definition.id === 'kginicis_naverpay') {
            button.dataset.kginicisNaverpayBrandButton = 'true';
        }

        formatBrandPaymentText(button, definition);
        applyCompactBrandPaymentLayout(button, definition);

        if (button.querySelector('[data-kginicis-brand-payment-mark="true"], [data-kginicis-naverpay-mark="true"]')) {
            return;
        }

        const mark = createBrandPaymentMark(definition);
        const icon = findPaymentIcon(button);
        if (icon && icon.parentElement) {
            icon.replaceWith(mark);
            patched = true;
            return;
        }

        const row = findPaymentRow(button);
        if (!row) return;

        row.prepend(mark);
        patched = true;
    });

    return patched;
}

function stopPatchRetries(): void {
    if (retryTimer === null) return;

    window.clearInterval(retryTimer);
    retryTimer = null;
}

function startPatchRetries(): void {
    stopPatchRetries();

    let attempts = 0;
    retryTimer = window.setInterval(() => {
        attempts += 1;
        patchRenderedNaverpayBrandButton();

        if (attempts >= 50) {
            stopPatchRetries();
        }
    }, 200);
}

async function startDomPatchLoop(fetchImpl: typeof fetch): Promise<void> {
    if (!isCheckoutPage()) return;
    if (!(await fetchEnabled(fetchImpl))) return;

    patchRenderedNaverpayBrandButton();
    startPatchRetries();

    if (observer === null) {
        observer = new MutationObserver(() => {
            patchRenderedNaverpayBrandButton();
        });
        observer.observe(document.body, { childList: true, subtree: true, characterData: true });
    }
}

export function installCheckoutNaverpayBrandButton(fetchImpl: typeof fetch = fetch): void {
    if (typeof window === 'undefined' || typeof document === 'undefined') return;
    if (windowRecord()[FLAG] === true) return;

    windowRecord()[FLAG] = true;

    void startDomPatchLoop(fetchImpl).then(() => {
        logger.info('checkout KG Inicis brand payment button patcher installed');
    });
}

export function resetCheckoutNaverpayBrandButtonForTests(): void {
    observer?.disconnect();
    observer = null;
    stopPatchRetries();
    cachedEnabled = null;
    delete windowRecord()[FLAG];
}
