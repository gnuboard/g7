// e2e:allow Playwright 스펙 없음(이 플러그인 tests/Playwright/specs/sso-*.spec.ts 는 로그인 흐름만 덮는다) — 브라우저 실측은 F1 Chrome MCP 계획서 §10 M11·M14(W40) — P4 는 Vitest 로 덮음
/**
 * @file admin-screens.test.tsx
 * @description 「회원 연결」, 「이력」 화면과 routes.json 의 구운 JSON 계약 — 권한, 연결 추가와 사전 등록, CSV 미리보기와 실행, 마지막 수단 해제 경고,
 * 이력 탭과 필터. 모든 문구 키가 ko/en 에 있다.
 */
import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';
import { load, pluginKeys, missingKeys, byId, text, flatten, ROOT } from './p4-layout-helpers';

const accounts = load('resources/layouts/admin/sso_accounts');
const logs = load('resources/layouts/admin/sso_logs');
const routes = JSON.parse(fs.readFileSync(path.join(ROOT, 'resources/routes.json'), 'utf8'));

describe('SSO 운영 화면', () => {
  /**
   * @scenario screen=accounts|logs, locale=ko|en
   * @effects operations_i18n_keys_present
   */
  it('문구 키가 ko·en 에 모두 있다', () => {
    const keys = [accounts, logs, routes].flatMap(pluginKeys);
    expect(keys.length).toBeGreaterThan(60);
    expect(missingKeys(keys)).toEqual([]);
  });

  /**
   * @scenario routes=accounts|logs
   * @effects routes_declared_with_permission
   */
  it('routes.json 이 두 화면을 권한과 함께 선언한다', () => {
    const map = Object.fromEntries(routes.routes.map((r: any) => [r.layout, r]));
    expect(map.sso_accounts.path).toBe('*/admin/plugins/sirsoft-sso/accounts');
    expect(map.sso_accounts.meta.permission).toBe('sirsoft-sso.accounts.view');
    expect(map.sso_logs.path).toBe('*/admin/plugins/sirsoft-sso/logs');
    expect(map.sso_logs.meta.permission).toBe('sirsoft-sso.logs.view');
  });

  /**
   * @scenario screen=accounts, action=add_existing|preregister
   * @effects add_posts_user_or_new_user, provision_gated
   */
  it('연결 추가는 기존 회원(user_id) 또는 새 회원(new_user)을 보내고 provision 권한으로만 열린다', () => {
    const s = text(accounts);
    expect(s).toContain('new_user:');
    expect(s).toContain('user_id:');
    expect(byId(accounts, 'open_add_link')?.props?.disabled).toContain('canProvision');

    // 모드 라디오는 폼 자동 바인딩을 끄고 checked 식과 change 액션으로 addForm.mode 를 직접 다룬다
    const radios = flatten(accounts).filter((n) => n?.props?.type === 'radio' && n?.props?.name === 'mode');
    expect(radios.map((n) => n.props.value)).toEqual(['existing', 'new']);
    for (const radio of radios) {
      expect(radio.props.autoBinding).toBe(false);
      expect(radio.props.checked).toContain(`=== '${radio.props.value}'`);
      expect(radio.actions).toEqual([
        expect.objectContaining({ type: 'change', handler: 'setState', params: { target: '_local', 'addForm.mode': radio.props.value } }),
      ]);
    }
  });

  /**
   * @scenario screen=accounts, viewer=delegate|super
   * @effects unlink_enabled_by_ability
   */
  it('표 셀 안은 _computed 를 읽지 않고, 행의 「연결 해제」는 응답 abilities.can_unlink 로 켜고 끈다', () => {
    // 엔진의 셀 렌더 문맥(G7Core.renderItemChildren)은 _computed 를 빈 객체로 둔다 — 셀에서 _computed 를 읽으면 늘 비어 있다(F1 M11 T2 실측)
    const grid = byId(accounts, 'accounts_datagrid');
    expect(JSON.stringify(grid.props.columns)).not.toContain('_computed');

    const button = flatten(grid.props.columns).find((n) => n?.id === 'unlink_{{row.id}}');
    const disabled: string = button.props.disabled;
    const evaluate = (abilities: unknown) =>
      new Function('ssoAccounts', '_computed', `return (${disabled.replace(/^\{\{|\}\}$/g, '')});`)({ data: { abilities } }, {});
    expect(evaluate({ can_unlink: true })).toBe(false);
    expect(evaluate({ can_unlink: false })).toBe(true);
    expect(evaluate(undefined)).toBe(true);
  });

  /**
   * @scenario screen=accounts, csv=preview|execute|download
   * @effects preview_then_execute, result_download
   */
  it('CSV 는 미리보기 뒤에만 가져오기를 누를 수 있고 결과를 내려받는다', () => {
    const s = text(accounts);
    expect(s).toContain("mode: 'preview'");
    expect(s).toContain("mode: 'execute'");
    expect(byId(accounts, 'csv_execute_button')?.props?.disabled).toContain('!_local.csvPreview');
    expect(s).toContain('sirsoft-sso.readCsvFile');
    expect(s).toContain('sirsoft-sso.downloadCsvResult');
  });

  /**
   * @scenario screen=accounts, unlink=last_method
   * @effects last_method_warning_then_confirm
   */
  it('마지막 수단 해제는 서버의 last_method 를 받아 경고 뒤 confirm_last_method 로 다시 지운다', () => {
    const s = text(accounts);
    expect(s).toContain("error.errors?.code === 'last_method'");
    expect(s).toContain('confirm_last_method: true');
  });

  /**
   * @scenario screen=logs, tab=attempts|scim
   * @effects two_tabs, filters_by_query
   */
  it('이력은 로그인 시도와 SCIM 수신 두 탭이고 주소 쿼리로 거른다', () => {
    expect(byId(logs, 'logs_tabs')?.props?.tabs?.map((t: any) => t.id)).toEqual(['attempts', 'scim']);
    const s = text(logs);
    expect(s).toContain('/api/plugins/sirsoft-sso/admin/logs/attempts');
    expect(s).toContain('/api/plugins/sirsoft-sso/admin/logs/scim');
    expect(logs.permissions).toEqual(['sirsoft-sso.logs.view']);
  });
});
