// e2e:allow 행 스위치 접근 이름(ariaLabel 식) 단언 전용 — 브라우저 축은 재실측(환경설정 › 본인인증 정책 탭 접근성 트리)이 덮는다
/**
 * @file adminIdentityPolicyToggleName.test.ts
 * @description 이커머스 환경설정 › 본인인증 정책 — 행 스위치 이름이 정책 키만이 아닌지 (7.1.1 출시 전)
 *
 * 코어 환경설정의 같은 표(admin_basic)는 행 스위치를 「{인증 목적} ({정책 키}) 활성」 으로 읽게 고쳤다. 이 화면은
 * 그 표의 사본이라 스위치가 「sirsoft-ecommerce.checkout.before_pay 활성」 처럼 내부 키로만 읽혔다. 같은 행에 보이는
 * 「인증 목적」 이름(이 화면의 목적 소스 `ecommerceIdentityPurposes`)을 앞에 두고 키는 괄호로 잇는다.
 * 표(넓은 화면)와 카드(좁은 화면) 두 판 모두 같은 이름이다.
 *
 * @vitest-environment node
 */

import { describe, it, expect, beforeAll } from 'vitest';
import { TranslationEngine } from '@core/template-engine/TranslationEngine';
import policyTab from '../../../layouts/admin/partials/admin_ecommerce_settings/_tab_identity_policies.json';

/** 트리에서 조건을 만족하는 노드를 모두 모은다. */
function collect(node: unknown, predicate: (n: any) => boolean, out: any[] = []): any[] {
    if (Array.isArray(node)) {
        node.forEach((child) => collect(child, predicate, out));

        return out;
    }
    if (node && typeof node === 'object') {
        if (predicate(node)) out.push(node);
        Object.values(node as Record<string, unknown>).forEach((v) => collect(v, predicate, out));
    }

    return out;
}

const templateId = 'sirsoft-admin_basic';
const toggles = collect(policyTab, (n) => n.name === 'Toggle' && typeof n.props?.ariaLabel === 'string' && n.props.ariaLabel.includes('row_cell'));
const purposes = { data: [{ id: 'checkout_verification', label: '결제 전 본인 확인' }, { id: 'sensitive_action', label: '민감 작업' }] };

describe('이커머스 본인인증 정책 행 스위치 이름', () => {
    let engine: TranslationEngine;

    beforeAll(() => {
        engine = TranslationEngine.getInstance();
        // 관리자 템플릿 공용 문구 두 개만 싣는다(값은 admin_basic ko 와 같다).
        (engine as any).translations.set(`${templateId}:ko`, {
            common: { row_cell: '{{row}} {{column}}' },
            admin: { identity: { policies: { col: { enabled: '활성' } } } },
        });
    });

    const nameOf = (ariaLabel: string, policy: Record<string, unknown>) =>
        engine.resolveTranslations(ariaLabel, { templateId, locale: 'ko' }, { policy, ecommerceIdentityPurposes: purposes, $templateId: templateId, $locale: 'ko' } as any);

    /**
     * @scenario surface=admin_row
     */
    it('표·카드 두 판에 행 스위치가 있다', () => {
        expect(toggles.length).toBe(2);
    });

    /**
     * @scenario surface=admin_row
     * @effects row_controls_read_row_and_column
     */
    it.each([0, 1])('%i번째 판: 「{인증 목적} ({정책 키}) 활성」 으로 읽힌다 — 키만이 아니다', (i) => {
        const name = nameOf(toggles[i].props.ariaLabel, { key: 'sirsoft-ecommerce.checkout.before_pay', purpose: 'checkout_verification' });

        expect(name).toBe('결제 전 본인 확인 (sirsoft-ecommerce.checkout.before_pay) 활성');
    });

    /**
     * @scenario surface=admin_row
     * @effects row_controls_read_row_and_column
     */
    it('목적 이름을 모르면 목적 값, 그것도 없으면 키만 쓴다', () => {
        const label = toggles[0].props.ariaLabel;

        expect(nameOf(label, { key: 'vendor.x.y', purpose: 'unknown_purpose' })).toBe('unknown_purpose (vendor.x.y) 활성');
        expect(nameOf(label, { key: 'vendor.x.y', purpose: null })).toBe('vendor.x.y 활성');
    });
});
