/**
 * 주문서 입금 기한 안내 — 서버 SSoT 바인딩 계약 테스트 (#493 E1)
 *
 * 배경: 입금 기한은 결제수단과 무관하게 관리자 환경설정의 미입금 자동취소 기한
 *   (`order_settings.auto_cancel_days`) 하나만 사용한다. 서버는 가상계좌·무통장입금
 *   양쪽 모두 이 값으로 `vbank_due_at` / `deposit_due_at` 을 계산한다
 *   (`OrderProcessingService`). 클라이언트가 보낸 기한 값은 무시된다.
 *
 * 결함: 주문서 화면은 서버에 존재하지 않는 `vbank_due_days` / `dbank_due_days` 를
 *   참조하고 있었다. 두 키는 설정 응답에 없으므로 표현식이 항상 `undefined` 가 되고
 *   하드코딩된 폴백(가상계좌 3, 무통장입금 7)이 그대로 노출됐다.
 *
 *   실측(2026-07-29): `auto_cancel_days = 3` 인 사이트에서 무통장입금 주문서가
 *   "입금 기한: 7일 이내" 로 안내했다. 실제로는 3일 뒤 자동취소된다 —
 *   안내를 믿은 구매자는 주문이 취소된 뒤에야 알게 된다.
 *
 * 회귀 차단 포인트:
 *   1. 두 안내 모두 `order_settings.auto_cancel_days` 를 참조한다.
 *   2. 서버에 없는 `*_due_days` 키를 참조하지 않는다 (되살아나면 폴백으로 조용히 회귀).
 *   3. 폴백 값은 서버 기본치(3)와 같다 — 설정 응답이 늦거나 실패해도 안내가 어긋나지 않도록.
 *
 * 후속 결함(브라우저 실측 2026-08-01): 폴백이 `?? 3`(nullish) 이라 `null`/`undefined` 만
 *   막고 **빈 문자열은 통과**했다. 설정에 `auto_cancel_days: ""` 가 남아 있으면
 *   `days=` 가 빈 값으로 넘어가 치환이 스킵되고 주문서에 `입금 기한: {{days}}일 이내` 가
 *   그대로 노출된다(실측: `/api/modules/sirsoft-ecommerce/settings/payment` 가 `""` 를 그대로 응답).
 *   서버(`OrderProcessingService::resolveAutoCancelDays`)는 같은 값에서 3일로 폴백하므로,
 *   화면만 깨지고 실제 기한은 정상이라 조용한 불일치가 된다.
 *
 *   따라서 폴백 판정은 서버와 같은 규칙(숫자로 해석되는 양수만 사용, 그 외 기본치 3)이어야 한다.
 */

import { describe, it, expect } from 'vitest';

import checkoutPayment from '../../layouts/partials/shop/_checkout_payment.json';

const raw = JSON.stringify(checkoutPayment);

/** 서버 SSoT 키 — 결제수단 무관 단일 기준 */
const SSOT_KEY = 'order_settings?.auto_cancel_days';

/** 서버 기본치 (`OrderProcessingService::AUTO_CANCEL_DAYS_DEFAULT`) */
const SERVER_DEFAULT = 3;

/**
 * 안내 문구의 `days=` 바인딩 표현식을 레이아웃에서 추출합니다.
 *
 * @param translationKey 다국어 키 (vbank_due_notice / dbank_due_notice)
 * @returns `{{ }}` 안쪽 표현식 본문
 */
function extractDaysExpression(translationKey: string): string {
    const entry = raw.match(new RegExp(`\\$t:shop\\.checkout\\.${translationKey}\\|days=\\{\\{(.+?)\\}\\}`));

    expect(entry, `${translationKey} 안내의 days 바인딩을 찾지 못했습니다`).not.toBeNull();

    return entry![1];
}

/**
 * 추출한 표현식을 실제로 평가합니다 — 문자열 형태가 아닌 **동작**을 잠급니다.
 *
 * @param expression 레이아웃에서 추출한 표현식
 * @param autoCancelDays 설정 응답이 담고 있는 원본 값
 * @returns 안내 문구에 치환될 값
 */
function evaluateDays(expression: string, autoCancelDays: unknown): unknown {
    const fn = new Function('paymentSettings', `return (${expression});`);

    return fn({ data: { order_settings: { auto_cancel_days: autoCancelDays } } });
}

const NOTICES: Array<[string, string]> = [
    ['가상계좌', 'vbank_due_notice'],
    ['무통장입금', 'dbank_due_notice'],
];

describe('주문서 입금 기한 안내 — 서버 SSoT 바인딩 (#493 E1)', () => {
    it.each(NOTICES)('%s 안내가 auto_cancel_days 를 참조한다', (_label, translationKey) => {
        expect(extractDaysExpression(translationKey)).toContain(SSOT_KEY);
    });

    it.each([
        ['vbank_due_days'],
        ['dbank_due_days'],
    ])('서버에 없는 %s 키를 참조하지 않는다', (orphanKey) => {
        expect(
            raw.includes(orphanKey),
            `${orphanKey} 는 설정 응답에 존재하지 않는 키입니다 — 표현식이 항상 undefined 가 되어 하드코딩 폴백이 노출됩니다.`
        ).toBe(false);
    });
});

describe('주문서 입금 기한 안내 — 오염된 설정값 폴백 (서버 규칙 일치)', () => {
    /** [라벨, 저장된 값, 기대 표시값] — 서버 resolveAutoCancelDays 와 동일 규칙 */
    const CASES: Array<[string, unknown, number]> = [
        ['빈 문자열 (검증 강화 이전 저장분)', '', SERVER_DEFAULT],
        ['null', null, SERVER_DEFAULT],
        ['undefined (키 부재)', undefined, SERVER_DEFAULT],
        ['0 (즉시 만료 — 실질 사용 불가)', 0, SERVER_DEFAULT],
        ['음수', -1, SERVER_DEFAULT],
        ['비숫자 문자열', 'abc', SERVER_DEFAULT],
        ['숫자 문자열', '5', 5],
        ['정수', 5, 5],
    ];

    describe.each(NOTICES)('%s 안내', (_label, translationKey) => {
        it.each(CASES)('%s → %s 일로 안내한다', (_caseLabel, stored, expected) => {
            expect(evaluateDays(extractDaysExpression(translationKey), stored)).toBe(expected);
        });
    });
});
