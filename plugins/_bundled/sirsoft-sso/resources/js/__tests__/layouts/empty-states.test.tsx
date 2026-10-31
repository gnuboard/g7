// e2e:allow Playwright 스펙 없음(이 플러그인 tests/Playwright/specs/sso-*.spec.ts 는 로그인 흐름만 덮는다) — 브라우저 실측은 F1 Chrome MCP 계획서 §10 M4~M6·M10~M11(W40) — P4 는 Vitest 로 덮음
/**
 * @file empty-states.test.tsx
 * @description 데이터 0건일 때의 표시 계약 — 목록마다 빈 문구가 있고, 0건 응답에서 그 문구 조건이 참이 되며 목록 조건은 거짓이 된다.
 * 빈 문구 키는 ko/en 모두 비어 있지 않은 문장이다.
 */
import { describe, it, expect } from 'vitest';
import { load, byId, flatten, dig, lang } from './p4-layout-helpers';

/**
 * 구운 `{{…}}` 식을 주어진 이름들로 평가합니다.
 *
 * @param expression - `{{식}}`
 * @param scope - 식이 읽는 이름과 값
 * @returns 평가 결과
 */
function evaluate(expression: string, scope: Record<string, unknown>): unknown {
  const body = expression.replace(/^\{\{|\}\}$/g, '');
  const names = Object.keys(scope);

  return new Function(...names, `return (${body});`)(...names.map((name) => scope[name]));
}

/**
 * 문구 토큰(`$t:sirsoft-sso.…`)의 ko/en 문장을 꺼냅니다.
 *
 * @param token - 문구 토큰
 * @returns [ko, en]
 */
function sentences(token: string): unknown[] {
  const key = token.replace(/^\$t:sirsoft-sso\./, '');

  return ['ko', 'en'].map((locale) => dig(lang(locale), key));
}

/**
 * 문구로 빈 상태 노드를 찾습니다.
 *
 * @param json - 구운 JSON
 * @param token - 문구 토큰
 * @returns 노드
 */
const byText = (json: any, token: string): any => flatten(json).find((n) => n?.text === token);

describe('SSO 화면의 0건 표시', () => {
  /**
   * @scenario screen=accounts|logs, text=present|empty
   * @effects empty_message_on_zero_rows
   */
  it('회원 연결, 이력 표는 0건이면 빈 문구를 보인다', () => {
    const accounts = load('resources/layouts/admin/sso_accounts');
    const logs = load('resources/layouts/admin/sso_logs');

    const grid = byId(accounts, 'accounts_datagrid');
    expect(grid.if).toBeUndefined();
    expect(grid.props.emptyMessage).toBe('$t:sirsoft-sso.admin.accounts.empty');

    for (const [id, query] of [['attempts_datagrid', {}], ['scim_datagrid', { tab: 'scim' }]] as const) {
      const node = byId(logs, id);
      expect(node.props.emptyMessage).toBe('$t:sirsoft-sso.admin.logs.empty');
      expect(evaluate(node.if, { query })).toBe(true);
    }

    for (const token of ['$t:sirsoft-sso.admin.accounts.empty', '$t:sirsoft-sso.admin.logs.empty']) {
      for (const sentence of sentences(token)) {
        expect(typeof sentence === 'string' && sentence.trim().length > 0).toBe(true);
      }
    }
  });

  /**
   * @scenario tab=idps, text=present|empty
   * @effects empty_message_on_zero_rows
   */
  it('IdP 목록은 0건이면 표를 숨기고 빈 문구를, 받기 전이면 로딩 문구를 보인다', () => {
    const list = load('resources/layouts/admin/partials/plugin_settings/_idp_list');
    const empty = byId(list, 'idp_list_empty');
    const grid = byId(list, 'idp_datagrid');
    const loading = byId(list, 'idp_list_loading');

    const zero = { ssoIdps: { data: { idps: [] } } };
    expect(evaluate(empty.if, zero)).toBe(true);
    expect(evaluate(grid.if, zero)).toBe(false);
    expect(evaluate(loading.if, zero)).toBe(false);

    const pending = { ssoIdps: undefined };
    expect(evaluate(empty.if, pending)).toBe(false);
    expect(evaluate(loading.if, pending)).toBe(true);

    const one = { ssoIdps: { data: { idps: [{ id: 1 }] } } };
    expect(evaluate(empty.if, one)).toBe(false);
    expect(evaluate(grid.if, one)).toBe(true);
    expect(empty.text).toBe('$t:sirsoft-sso.admin.settings.idp.empty');
  });

  /**
   * @scenario tab=connection, text=present|empty
   * @effects empty_message_on_zero_rows
   */
  it('IdP 편집의 최근 시도 목록은 0건이면 빈 문구를 보인다', () => {
    const edit = load('resources/layouts/admin/partials/plugin_settings/_idp_edit');
    const node = byText(edit, '$t:sirsoft-sso.admin.logs.empty');

    expect(evaluate(node.if, { _local: { idpForm: { recent_attempts: [] } } })).toBe(true);
    expect(evaluate(node.if, { _local: { idpForm: {} } })).toBe(true);
    expect(evaluate(node.if, { _local: { idpForm: { recent_attempts: [{ id: 1 }] } } })).toBe(false);
  });

  /**
   * @scenario surface=mypage, text=present|empty
   * @effects empty_message_on_zero_rows
   */
  it('마이페이지 카드는 연결이 0건이면 목록 대신 빈 문구를 보인다', () => {
    const card = load('resources/extensions/mypage-sso-accounts-card');
    const empty = byText(card, '$t:sirsoft-sso.mypage.empty');
    const list = byId(card, 'sso_accounts_list');

    const zero = { ssoMyAccounts: { data: { accounts: [], linkable: [], enforced: false } } };
    expect(evaluate(empty.if, zero)).toBe(true);
    expect(evaluate(list.if, zero)).toBe(false);
    expect(evaluate(empty.if, { ssoMyAccounts: { data: { accounts: [{ id: 1 }] } } })).toBe(false);

    for (const sentence of sentences('$t:sirsoft-sso.mypage.empty')) {
      expect(typeof sentence === 'string' && sentence.trim().length > 0).toBe(true);
    }
  });

  /**
   * @scenario surface=admin_user_detail, text=present|empty
   * @effects empty_message_on_zero_rows
   */
  it('회원 상세 탭은 연결 0건과 열람 권한 없음을 다른 문구로 구분한다', () => {
    const tab = load('resources/extensions/admin-user-detail-sso-tab');
    const empty = byId(tab, 'admin_user_sso_empty');
    const forbidden = byId(tab, 'admin_user_sso_forbidden');

    const zero = { user: { data: { sso: { accounts: [] } } } };
    expect(evaluate(empty.if, zero)).toBe(true);
    expect(evaluate(forbidden.if, zero)).toBe(false);

    const hidden = { user: { data: { id: 1 } } };
    expect(evaluate(empty.if, hidden)).toBe(false);
    expect(evaluate(forbidden.if, hidden)).toBe(true);
    expect(empty.text).toBe('$t:sirsoft-sso.admin.user_tab.empty');
  });
});
