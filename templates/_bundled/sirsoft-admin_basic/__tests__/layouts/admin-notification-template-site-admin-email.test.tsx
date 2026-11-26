/**
 * @file admin-notification-template-site-admin-email.test.tsx
 * @description 코어 알림 템플릿 편집 창 — 받는 사람 「사이트 관리자 이메일」 선택지와 안내
 *
 * 받는 사람 규칙의 종류에 「사이트 관리자 이메일」(환경설정 > 일반의 관리자 이메일, 메일만)이 있고, 고르면 값 칸 없이
 * 그 사실과 빈 값의 결과를 밝히는 안내(`rcpt_site_admin_email_hint_*`)만 보인다. 다른 종류에서는 안내가 없다.
 * 굽기 산출물(.json)의 실제 규칙 카드를 그대로 렌더해 확인한다.
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { createLayoutTest, screen } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';
import { dataBindingEngine } from '@core/template-engine/DataBindingEngine';
import { actionsOf } from '@core/template-engine/__tests__/utils/layoutShapeUtils';

type Json = Record<string, any>;

const NS = 'notification_template_form_modal';
const modal = JSON.parse(readFileSync(resolve(__dirname, '../../layouts/partials/admin_settings/_modal_notification_template_form.json'), 'utf-8'));
const adminKo = JSON.parse(readFileSync(resolve(__dirname, '../../lang/partial/ko/admin.json'), 'utf-8'));
const adminEn = JSON.parse(readFileSync(resolve(__dirname, '../../lang/partial/en/admin.json'), 'utf-8'));
const commonKo = JSON.parse(readFileSync(resolve(__dirname, '../../lang/partial/ko/common.json'), 'utf-8'));
const commonEn = JSON.parse(readFileSync(resolve(__dirname, '../../lang/partial/en/common.json'), 'utf-8'));

/**
 * 조건에 맞는 첫 노드를 찾습니다.
 *
 * @param node 트리
 * @param pred 조건
 * @returns 노드 (없으면 undefined)
 */
function findNode(node: unknown, pred: (n: Json) => boolean): Json | undefined {
  if (Array.isArray(node)) {
    for (const item of node) {
      const hit = findNode(item, pred);
      if (hit) return hit;
    }
    return undefined;
  }
  if (node && typeof node === 'object') {
    const obj = node as Json;
    if (pred(obj)) return obj;
    for (const v of Object.values(obj)) {
      const hit = findNode(v, pred);
      if (hit) return hit;
    }
  }
  return undefined;
}

/** 받는 사람 규칙 카드 한 장(반복 노드) */
const ruleCard = findNode(modal, (n) => String(n.iteration?.source ?? '').includes(`${NS}?.recipients`))!;
/** 규칙 종류 선택칸 */
const typeSelect = findNode(modal, (n) => n.name === 'Select' && JSON.stringify(actionsOf(n)).includes('type: $event.target.value'))!;

const Pass = (tag: string): React.FC<any> => ({ children, className, text, id }) => React.createElement(tag, { className, 'data-node-id': id }, children ?? text);
const TestSelect: React.FC<any> = ({ options, value }) => (
  <select data-testid="rcpt-type-select" value={value ?? ''} onChange={() => undefined}>
    {(Array.isArray(options) ? options : []).map((o: any) => (
      <option key={String(o.value)} value={o.value}>{o.label}</option>
    ))}
  </select>
);
const Nothing: React.FC<any> = () => null;

/**
 * 시험 레지스트리를 준비합니다.
 *
 * @returns 레지스트리
 */
function setupRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();
  const basic = (name: string, component: React.FC<any>) => ({ component, metadata: { name, type: 'basic' } });
  (registry as any).registry = {
    Div: basic('Div', Pass('div')),
    Span: basic('Span', Pass('span')),
    P: basic('P', Pass('p')),
    Label: basic('Label', Pass('label')),
    Button: basic('Button', Pass('button')),
    Icon: basic('Icon', Nothing),
    Select: basic('Select', TestSelect),
    SearchableDropdown: { component: Nothing, metadata: { name: 'SearchableDropdown', type: 'composite' } },
    Toggle: { component: Nothing, metadata: { name: 'Toggle', type: 'composite' } },
    Fragment: { component: ({ children }: any) => <>{children}</>, metadata: { name: 'Fragment', type: 'layout' } },
  };
  return registry;
}

/**
 * 규칙 카드를 받는 사람 목록과 함께 렌더합니다.
 *
 * @param recipients 받는 사람 규칙
 * @param locale 언어
 * @returns 테스트 도구
 */
async function renderCard(recipients: Json[], locale: 'ko' | 'en', registry: ComponentRegistry) {
  const admin = locale === 'ko' ? adminKo : adminEn;
  const common = locale === 'ko' ? commonKo : commonEn;
  const t = createLayoutTest(
    { version: '1.0.0', layout_name: 'notification_site_admin_email_test', initGlobal: { [NS]: { recipients, definition: { relations: [] } } }, components: [ruleCard] },
    { componentRegistry: registry, translations: { admin: { settings: admin.settings }, common }, locale },
  );
  await t.render();
  return t;
}

describe('알림 템플릿 편집 창 — 받는 사람 「사이트 관리자 이메일」', () => {
  let registry: ComponentRegistry;

  beforeEach(() => {
    registry = setupRegistry();
  });

  afterEach(() => {
    (registry as any).registry = {};
  });

  /**
   * @scenario case=site_admin_email
   * @effects recipient_site_admin_email_offered_with_hint
   */
  it.each(['ko', 'en'] as const)('(%s) 종류 선택지에 「사이트 관리자 이메일」이 있고, 고른 규칙에는 안내만 보인다', async (locale) => {
    const recipients = locale === 'ko' ? adminKo.settings.notification_definitions.recipients : adminEn.settings.notification_definitions.recipients;
    const t = await renderCard([{ type: 'site_admin_email' }], locale, registry);

    const labels = Array.from(screen.getByTestId('rcpt-type-select').querySelectorAll('option')).map((o) => o.textContent);
    expect(labels).toContain(recipients.types.site_admin_email);
    expect((screen.getByTestId('rcpt-type-select') as HTMLSelectElement).value).toBe('site_admin_email');
    expect(document.querySelectorAll('[data-node-id="rcpt_site_admin_email_hint_0"]')).toHaveLength(1);
    expect(screen.getByText(recipients.site_admin_email_hint)).toBeTruthy();
    // 값 칸(역할·회원 고르기)과 관계 선택칸은 없다 — 이 종류는 값이 없다
    expect(screen.queryByText(recipients.role_label)).toBeNull();
    expect(screen.queryByText(recipients.relation_label)).toBeNull();
    t.cleanup();
  });

  /**
   * @scenario case=site_admin_email
   * @effects recipient_site_admin_email_offered_with_hint
   */
  it('다른 종류(역할·발생시킨 본인)의 규칙에는 안내가 없고, 줄마다 따로 판정한다', async () => {
    const t = await renderCard([{ type: 'role', value: [] }, { type: 'site_admin_email' }, { type: 'trigger_user' }], 'ko', registry);

    expect(document.querySelectorAll('[data-node-id="rcpt_site_admin_email_hint_0"]')).toHaveLength(0);
    expect(document.querySelectorAll('[data-node-id="rcpt_site_admin_email_hint_1"]')).toHaveLength(1);
    expect(document.querySelectorAll('[data-node-id="rcpt_site_admin_email_hint_2"]')).toHaveLength(0);
    expect(screen.getAllByText(adminKo.settings.notification_definitions.recipients.site_admin_email_hint)).toHaveLength(1);
    t.cleanup();
  });

  /**
   * @scenario case=site_admin_email
   * @effects recipient_site_admin_email_offered_with_hint
   */
  it('「사이트 관리자 이메일」로 바꾸면 그 줄은 값·관계·행위자 제외 없이 종류만 남는다', () => {
    const expr: string = actionsOf(typeSelect)[0].params[`${NS}.recipients`];
    const result = dataBindingEngine.evaluateExpression(expr.trim().replace(/^\{\{|\}\}$/g, ''), {
      _global: { [NS]: { recipients: [{ type: 'role', value: ['admin'], display_names: ['관리자'], exclude_trigger_user: true }], definition: { relations: ['post_author'] } } },
      rcptIdx: 0,
      $event: { target: { value: 'site_admin_email' } },
    }) as unknown as Json[];

    expect(result).toEqual([{ type: 'site_admin_email' }]);
  });
});
