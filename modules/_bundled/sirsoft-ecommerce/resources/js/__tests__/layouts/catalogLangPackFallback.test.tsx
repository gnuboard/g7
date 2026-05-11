/**
 * 이커머스 카탈로그 표시 layout JSON 다국어 fallback 마이그레이션 회귀 가드.
 *
 * Phase 6.6 (7.0.0-beta.4) — settings JSON 다국어 라벨이 활성 언어팩으로 자동 보강되도록
 * `$localized(value, 'sirsoft-ecommerce::settings.<section>.<code>.name')` 패턴으로 마이그레이션.
 *
 * 본 테스트는 다음 5종 레이아웃의 표시 표현식이 fallbackKey 인자를 보유하는지 검증.
 * fallbackKey 누락 시 ja 활성 환경에서 ko fallback 으로 노출되는 회귀가 재발한다.
 *
 * @vitest-environment node
 */

import { describe, it, expect } from 'vitest';

import shippingCountryTable from '../../../layouts/admin/partials/admin_ecommerce_settings/_shipping_country_table.json';
import shippingCountryCards from '../../../layouts/admin/partials/admin_ecommerce_settings/_shipping_country_cards.json';
import currencyExchangeTable from '../../../layouts/admin/partials/admin_ecommerce_settings/_currency_exchange_table.json';
import currencyExchangeCards from '../../../layouts/admin/partials/admin_ecommerce_settings/_currency_exchange_cards.json';
import paymentMethodsList from '../../../layouts/admin/partials/admin_ecommerce_settings/_payment_methods_list.json';
import paymentMethodsCards from '../../../layouts/admin/partials/admin_ecommerce_settings/_payment_methods_cards.json';

function collectStrings(node: any, acc: string[] = []): string[] {
    if (!node) return acc;
    if (typeof node === 'string') {
        acc.push(node);
        return acc;
    }
    if (Array.isArray(node)) {
        for (const item of node) collectStrings(item, acc);
        return acc;
    }
    if (typeof node === 'object') {
        for (const value of Object.values(node)) collectStrings(value, acc);
    }
    return acc;
}

describe('이커머스 카탈로그 표시 — $localized fallbackKey 마이그레이션 회귀 가드', () => {
    it('shipping_country_table: country.name 표시에 settings.countries.{code}.name fallbackKey 사용', () => {
        const strings = collectStrings(shippingCountryTable);
        const hasMigrated = strings.some(
            (s) =>
                s.includes('$localized(country.name') &&
                s.includes("sirsoft-ecommerce::settings.countries.") &&
                s.includes('country.code'),
        );
        expect(hasMigrated, 'fallbackKey 누락 시 ja 활성에서 ko fallback 회귀').toBe(true);
    });

    it('shipping_country_cards: country.name 표시에 fallbackKey 사용', () => {
        const strings = collectStrings(shippingCountryCards);
        expect(
            strings.some(
                (s) =>
                    s.includes('$localized(country.name') &&
                    s.includes("sirsoft-ecommerce::settings.countries."),
            ),
        ).toBe(true);
    });

    it('currency_exchange_table: currency.name 표시에 settings.currencies.{code}.name fallbackKey 사용', () => {
        const strings = collectStrings(currencyExchangeTable);
        expect(
            strings.some(
                (s) =>
                    s.includes('$localized(currency.name') &&
                    s.includes("sirsoft-ecommerce::settings.currencies.") &&
                    s.includes('currency.code'),
            ),
        ).toBe(true);
    });

    it('currency_exchange_cards: currency.name 표시에 fallbackKey 사용', () => {
        const strings = collectStrings(currencyExchangeCards);
        expect(
            strings.some(
                (s) =>
                    s.includes('$localized(currency.name') &&
                    s.includes("sirsoft-ecommerce::settings.currencies."),
            ),
        ).toBe(true);
    });

    it('payment_methods_list: $method._cached_name 표시에 settings.payment_methods.{id}.name fallbackKey 사용', () => {
        const strings = collectStrings(paymentMethodsList);
        expect(
            strings.some(
                (s) =>
                    s.includes('$localized($method._cached_name') &&
                    s.includes("sirsoft-ecommerce::settings.payment_methods.") &&
                    s.includes('$method.id'),
            ),
        ).toBe(true);
    });

    it('payment_methods_cards: $method._cached_name 표시에 fallbackKey 사용', () => {
        const strings = collectStrings(paymentMethodsCards);
        expect(
            strings.some(
                (s) =>
                    s.includes('$localized($method._cached_name') &&
                    s.includes("sirsoft-ecommerce::settings.payment_methods."),
            ),
        ).toBe(true);
    });

    it('마이그레이션된 5종 모두 country/currency/method.code 식별자 포함 (lang key 동적 조립)', () => {
        const layouts = [
            { name: 'shipping_country_table', layout: shippingCountryTable, idVar: 'country.code' },
            { name: 'shipping_country_cards', layout: shippingCountryCards, idVar: 'country.code' },
            { name: 'currency_exchange_table', layout: currencyExchangeTable, idVar: 'currency.code' },
            { name: 'currency_exchange_cards', layout: currencyExchangeCards, idVar: 'currency.code' },
            { name: 'payment_methods_list', layout: paymentMethodsList, idVar: '$method.id' },
        ];
        for (const { name, layout, idVar } of layouts) {
            const strings = collectStrings(layout);
            const hasIdConcat = strings.some((s) => s.includes(idVar));
            expect(hasIdConcat, `${name} 의 fallbackKey 조립에 ${idVar} 식별자 누락`).toBe(true);
        }
    });
});
