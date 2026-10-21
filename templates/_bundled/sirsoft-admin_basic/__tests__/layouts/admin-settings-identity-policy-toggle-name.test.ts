/**
 * @file admin-settings-identity-policy-toggle-name.test.ts
 * @description 환경설정 › 본인인증 정책 — 행 스위치의 접근 이름이 정책 키만이 아닌지
 *
 * 정책에는 사람용 이름이 없어 표의 행 이름(`common.row_cell` 의 row)을 정책 키로만 줬다. 그래서 화면 읽기 프로그램이
 * 스위치를 「sirsoft-ecommerce.checkout.before_pay 활성」 처럼 내부 키로 읽었다(7.1.1 출시 전 실측 — 
 * 게시판 정책도 「sirsoft-board.post.user_create 활성」). 같은 행에 보이는 「인증 목적」 이름을 앞에 두고
 * 키는 괄호로 잇는다 — 목적이 같은 정책이 여럿이라(민감 작업 3건 등) 키가 있어야 서로 갈린다.
 *
 * 표(PC)와 카드(좁은 화면) 두 판 모두 같은 이름을 쓴다.
 *
 * @scenario-manifest tests/scenarios/control-accessible-names.yaml
 */

import { describe, it, expect, beforeAll } from 'vitest';
import { TranslationEngine } from '@core/template-engine/TranslationEngine';
import policiesPartial from '../../layouts/partials/admin_settings/_tab_identity_policies.json';
import adminKo from '../../lang/partial/ko/admin.json';
import commonKo from '../../lang/partial/ko/common.json';

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
const toggles = collect(policiesPartial, (n) => n.name === 'Toggle' && typeof n.props?.ariaLabel === 'string' && n.props.ariaLabel.includes('row_cell'));

const purposes = { data: [{ id: 'checkout_verification', label: '결제 전 본인 확인' }, { id: 'sensitive_action', label: '민감 작업' }] };

describe('본인인증 정책 행 스위치 이름', () => {
  let engine: TranslationEngine;

  beforeAll(() => {
    engine = TranslationEngine.getInstance();
    (engine as any).translations.set(`${templateId}:ko`, { admin: adminKo, common: commonKo });
  });

  const nameOf = (ariaLabel: string, policy: Record<string, unknown>) =>
    engine.resolveTranslations(ariaLabel, { templateId, locale: 'ko' }, { policy, identityPurposes: purposes, $templateId: templateId, $locale: 'ko' } as any);

  /**
   * 아래 판정의 전제 — 표(PC)·카드(좁은 화면) 두 판의 스위치를 모두 모았는지 고정한다.
   *
   * @scenario component=identity_policy_row_switch
   */
  it('표·카드 두 판에 행 스위치가 있다', () => {
    expect(toggles.length).toBe(2);
  });

  /**
   * @scenario component=identity_policy_row_switch
   * @effects policy_toggle_named_by_purpose
   */
  it.each([0, 1])('%i번째 판: 「{인증 목적} ({정책 키}) 활성」 으로 읽힌다 — 키만이 아니다', (i) => {
    const label = toggles[i].props.ariaLabel as string;
    const name = nameOf(label, { key: 'sirsoft-ecommerce.checkout.before_pay', purpose: 'checkout_verification' });

    expect(name).toBe('결제 전 본인 확인 (sirsoft-ecommerce.checkout.before_pay) 활성');
    expect(name).not.toBe('sirsoft-ecommerce.checkout.before_pay 활성');
  });

  /**
   * @scenario component=identity_policy_row_switch
   * @effects policy_toggle_named_by_purpose
   */
  it('목적 이름을 모르면 목적 값, 그것도 없으면 키만 쓴다', () => {
    const label = toggles[0].props.ariaLabel as string;

    expect(nameOf(label, { key: 'vendor.x.y', purpose: 'unknown_purpose' })).toBe('unknown_purpose (vendor.x.y) 활성');
    expect(nameOf(label, { key: 'vendor.x.y', purpose: null })).toBe('vendor.x.y 활성');
  });
});
