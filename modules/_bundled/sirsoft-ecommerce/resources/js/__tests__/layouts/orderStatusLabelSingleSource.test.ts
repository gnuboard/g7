import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * 주문 상태 라벨이 화면마다 갈리지 않는지 검증한다 (S1-D18)
 *
 * 주문 상태의 한국어 어휘는 `enums.json` 이 소유한다. 그런데 관리자 주문목록의
 * 일괄 변경 바와 확인 모달은 `modules/_bundled/sirsoft-ecommerce/resources/lang/partial/{ko,en}/admin/order.json` 의 `status.*` 라는 **별도 사본**을
 * 참조한다. 두 사본이 갈리면 **같은 상태가 화면마다 다른 이름으로 보인다**.
 *
 * 실측(#599 통합실측 세션1 · 1-O): 같은 운영자에게 같은 상태가 이렇게 보였다.
 *
 *     주문상세 상태 Select   : 결제대기 · 배송보류
 *     주문목록 일괄 Select   : 입금대기 · 발송보류
 *
 * 10개 중 8개는 일치하고 2개만 갈렸다 — 그래서 눈에 잘 띄지 않는다. 오류도 나지 않고
 * 기능도 정상이라, 운영자가 "다른 상태인가?" 하고 멈추는 것 말고는 드러나는 신호가 없다.
 *
 * 계약: `modules/_bundled/sirsoft-ecommerce/resources/lang/partial/{ko,en}/admin/order.json` 의 `status.*` 는 `enums.json` 의 `OrderStatusEnum` 라벨과
 * 글자 그대로 같아야 한다. 사본을 없애는 것이 더 낫지만 그 키는 4개 레이아웃이
 * 참조하므로, 최소한 **두 사본이 갈리지 않는다**를 계약으로 잠근다.
 */
describe('주문 상태 라벨 단일 어휘 (S1-D18)', () => {
    const base = resolve(__dirname, '../../../lang/partial');

    const readJson = (path: string): Record<string, unknown> =>
        JSON.parse(readFileSync(resolve(base, path), 'utf-8'));

    /** `modules/_bundled/sirsoft-ecommerce/resources/lang/partial/{ko,en}/admin/order.json` 의 status 키 → `enums.json` 의 OrderStatusEnum 키 */
    const KEY_MAP: Record<string, string> = {
        waiting: 'pending_order',
        payment_pending: 'pending_payment',
        payment_complete: 'payment_complete',
        shipping_hold: 'shipping_hold',
        preparing: 'preparing',
        shipping_ready: 'shipping_ready',
        shipping: 'shipping',
        delivered: 'delivered',
        cancelled: 'cancelled',
        confirmed: 'confirmed',
    };

    // @scenario label_copy=bulk_bar, locale=ko
    // @scenario label_copy=bulk_bar, locale=en
    // @effects order_status_labels_match_the_enum_vocabulary
    it.each(['ko', 'en'])('[%s] 주문목록 일괄 바의 상태 라벨이 enums 어휘와 글자 그대로 같다', (locale) => {
        const adminStatus = (readJson(`${locale}/admin/order.json`).status ?? {}) as Record<string, string>;
        const enums = readJson(`${locale}/enums.json`);

        // enums.json 은 여러 열거형을 담으므로 OrderStatusEnum 블록을 찾는다.
        const orderStatus = Object.values(enums).find(
            (block): block is Record<string, string> =>
                typeof block === 'object'
                && block !== null
                && 'pending_payment' in (block as Record<string, unknown>)
                && 'shipping_hold' in (block as Record<string, unknown>),
        );

        expect(orderStatus, 'enums.json 에 주문 상태 블록이 있어야 한다').toBeDefined();

        const mismatched = Object.entries(KEY_MAP)
            .filter(([adminKey, enumKey]) => adminStatus[adminKey] !== orderStatus![enumKey])
            .map(([adminKey, enumKey]) =>
                `${enumKey}: admin/order.json="${adminStatus[adminKey]}" ≠ enums.json="${orderStatus![enumKey]}"`);

        expect(
            mismatched,
            `같은 주문 상태가 화면마다 다른 이름으로 보인다:\n  ${mismatched.join('\n  ')}`,
        ).toEqual([]);
    });

    /**
     * 사본은 하나 더 있다 — 주문목록 **필터 칩**이 쓰는 `filter.order_status_options`.
     *
     * 첫 사본(`status.*`)만 맞추고 끝내면 같은 화면의 필터와 일괄 바가 다른 이름을
     * 말하게 된다 — 실측 중 `status.*` 를 고친 뒤에도 이 사본은 「입금대기」로 남아 있었다.
     */
    // @scenario label_copy=filter_chip, locale=ko
    // @scenario label_copy=filter_chip, locale=en
    // @effects order_status_labels_match_the_enum_vocabulary
    it.each(['ko', 'en'])('[%s] 주문목록 필터 칩의 상태 라벨도 enums 어휘와 같다', (locale) => {
        const filterOptions = (
            ((readJson(`${locale}/admin/order.json`).filter ?? {}) as Record<string, unknown>)
                .order_status_options ?? {}
        ) as Record<string, string>;
        const enums = readJson(`${locale}/enums.json`);

        const orderStatus = Object.values(enums).find(
            (block): block is Record<string, string> =>
                typeof block === 'object'
                && block !== null
                && 'pending_payment' in (block as Record<string, unknown>)
                && 'shipping_hold' in (block as Record<string, unknown>),
        );

        const mismatched = Object.entries(KEY_MAP)
            .filter(([adminKey, enumKey]) => filterOptions[adminKey] !== orderStatus![enumKey])
            .map(([adminKey, enumKey]) =>
                `${enumKey}: filter="${filterOptions[adminKey]}" ≠ enums="${orderStatus![enumKey]}"`);

        expect(
            mismatched,
            `필터 칩과 enums 어휘가 갈린다:\n  ${mismatched.join('\n  ')}`,
        ).toEqual([]);
    });
});
