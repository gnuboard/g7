// e2e:allow 화면 실측은 F1 Chrome MCP 계획서 §10 M1·M4·M10·M11(W40), 로그인 흐름은 Playwright tests/Playwright/specs/sso-*.spec.ts — P4 는 템플릿 컴포넌트 목록 대조로 덮음
/**
 * @file template-components.test.tsx
 * @description 레이아웃이 쓰는 컴포넌트 이름이 대상 템플릿 components.json 에 있다.
 * 없는 이름(예: `<dl>` 이 구워진 Dl·Dt·Dd)은 오류 없이 렌더에서 빠져 그 항목이 화면에서 통째로 사라진다(F1 M1 T3 실측 결함).
 * 관리자 화면과 관리자 주입 조각은 sirsoft-admin_basic, 사용자 주입 조각은 sirsoft-basic 을 대상으로 본다.
 * P1 소유 동의 모달 조각 두 개(auth-login-sso-modals, admin-login-sso-modals)는 P1 시험이 같은 대조를 한다.
 */
import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';
import { ROOT, load, flatten } from './p4-layout-helpers';

const REPO = path.resolve(ROOT, '../../..');

/**
 * 템플릿이 등록한 컴포넌트 이름을 모읍니다.
 *
 * @param templateId - 템플릿 식별자
 * @returns 이름 집합
 */
function templateComponents(templateId: string): Set<string> {
  const manifest = JSON.parse(fs.readFileSync(path.join(REPO, `templates/_bundled/${templateId}/components.json`), 'utf8'));

  return new Set(Object.values(manifest.components).flat().map((c: any) => c.name));
}

const ADMIN = 'sirsoft-admin_basic';
const USER = 'sirsoft-basic';

/** P4 소유 레이아웃과 주입 조각 → 대상 템플릿 */
const TARGETS: Array<[string, string]> = [
  ['resources/layouts/admin/plugin_settings', ADMIN],
  ['resources/layouts/admin/sso_accounts', ADMIN],
  ['resources/layouts/admin/sso_logs', ADMIN],
  ['resources/layouts/admin/partials/plugin_settings/_idp_list', ADMIN],
  ['resources/layouts/admin/partials/plugin_settings/_idp_edit', ADMIN],
  ['resources/layouts/admin/partials/plugin_settings/_policy_tab', ADMIN],
  ['resources/layouts/admin/partials/plugin_settings/_retention_tab', ADMIN],
  ['resources/extensions/admin-login-sso-buttons', ADMIN],
  ['resources/extensions/admin-user-detail-sso-tab', ADMIN],
  ['resources/extensions/admin-user-list-sso-filter', ADMIN],
  ['resources/extensions/admin-user-list-sso-columns', ADMIN],
  ['resources/extensions/auth-login-sso-buttons', USER],
  ['resources/extensions/mypage-sso-accounts-card', USER],
];

describe('대상 템플릿 컴포넌트', () => {
  /**
   * @scenario screen=plugin_settings|accounts|logs, surface=user|admin
   * @effects components_exist_in_template
   */
  it('P4 레이아웃과 주입 조각의 컴포넌트 이름이 모두 대상 템플릿에 있다', () => {
    const registry = { [ADMIN]: templateComponents(ADMIN), [USER]: templateComponents(USER) };
    const missing: string[] = [];

    for (const [file, templateId] of TARGETS) {
      for (const node of flatten(load(file))) {
        if (['basic', 'composite', 'layout'].includes(node?.type) && typeof node.name === 'string' && !registry[templateId].has(node.name)) {
          missing.push(`${file} → ${templateId}: ${node.name}`);
        }
      }
    }

    expect([...new Set(missing)]).toEqual([]);
  });

  /**
   * @scenario surface=admin_user_detail, tab=connection
   * @effects detail_rows_rendered
   */
  it('회원 상세 탭과 IdP 진단의 항목 표는 div 격자이고 이름과 값 칸이 남아 있다', () => {
    const tab = flatten(load('resources/extensions/admin-user-detail-sso-tab'));
    const diag = flatten(load('resources/layouts/admin/partials/plugin_settings/_idp_edit'));

    for (const [nodes, labels] of [
      [tab, ['admin.accounts.col.via', 'admin.accounts.col.linked_at', 'admin.accounts.col.last_login_at', 'admin.accounts.col.deprovisioned_at']],
      [diag, ['admin.settings.idp.last_success', 'admin.settings.idp.last_error', 'admin.settings.idp.periodic_checked_at', 'admin.settings.idp.cert_expires_at']],
    ] as const) {
      // 클래스는 이 플러그인 자기 CSS 의 앞글자 이름이다(sso: 접두 유틸리티)
      // 화면 폭 덮어쓰기(responsive 구간 값)에도 같은 클래스가 있을 수 있으니 이름 있는 노드만 본다
      const grid = nodes.find((n) => typeof n?.name === 'string' && typeof n?.props?.className === 'string' && n.props.className.startsWith('sso:grid sso:grid-cols-3'));
      expect(grid?.name).toBe('Div');
      const cells = grid.children as any[];
      expect(cells.map((c) => c.text).filter((t: string) => t?.startsWith('$t:'))).toEqual(labels.map((l) => `$t:sirsoft-sso.${l}`));
      expect(cells.filter((c) => c.props?.className?.includes('sso:col-span-2'))).toHaveLength(labels.length);
    }
  });
});
