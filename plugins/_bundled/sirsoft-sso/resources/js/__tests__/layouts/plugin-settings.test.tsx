// e2e:allow Playwright 스펙 없음(이 플러그인 tests/Playwright/specs/sso-*.spec.ts 는 로그인 흐름만 덮는다) — 브라우저 실측은 F1 Chrome MCP 계획서 §10 M10·M14(W40) — P4 는 Vitest 로 덮음
/**
 * @file plugin-settings.test.tsx
 * @description SSO 설정 화면(IdP 목록, IdP 편집, 정책, 보존)의 구운 JSON 계약 — 플러그인 API 만 쓰고(W38), 최고관리자 전용 칸을 잠그며,
 * 연결 정보 칸을 드라이버 칸 정의로 그리고, 저장 전 영향 미리보기를 부른다. 모든 문구 키가 ko/en 에 있다.
 */
import { describe, it, expect } from 'vitest';
import { load, flatten, pluginKeys, missingKeys, byId, text } from './p4-layout-helpers';
import { applyResponsiveClassMerge } from '@core/template-engine/helpers/StyleHelpers';

const shell = load('resources/layouts/admin/plugin_settings');
const list = load('resources/layouts/admin/partials/plugin_settings/_idp_list');
const edit = load('resources/layouts/admin/partials/plugin_settings/_idp_edit');
const policy = load('resources/layouts/admin/partials/plugin_settings/_policy_tab');
const retention = load('resources/layouts/admin/partials/plugin_settings/_retention_tab');

describe('SSO 설정 화면', () => {
  /**
   * @scenario screen=plugin_settings, locale=ko|en
   * @effects settings_i18n_keys_present
   */
  it('문구 키가 ko·en 에 모두 있다', () => {
    const keys = [shell, list, edit, policy, retention].flatMap(pluginKeys);
    expect(keys.length).toBeGreaterThan(150);
    expect(missingKeys(keys)).toEqual([]);
  });

  /**
   * @scenario screen=plugin_settings, storage=plugin_api
   * @effects uses_plugin_api_only, no_core_settings_save
   */
  it('플러그인 API 만 읽고 쓴다 — 코어 플러그인 설정 저장 경로를 부르지 않는다(W38)', () => {
    const all = [shell, list, edit, policy, retention].map(text).join('\n');
    expect(all).toContain('/api/plugins/sirsoft-sso/admin/idps');
    expect(all).toContain('/api/plugins/sirsoft-sso/admin/policy');
    expect(all).not.toContain('/api/admin/plugins/{{route.identifier}}/settings');
    expect(shell.permissions).toEqual(expect.arrayContaining(['sirsoft-sso.settings.view', 'core.plugins.read']));
  });

  /**
   * @scenario tab=idps, view=list|edit
   * @effects list_hidden_while_editing, edit_view_by_query
   */
  it('IdP 목록은 ?idp 가 없을 때만, 편집 보기는 ?idp 가 있을 때 보인다', () => {
    expect(byId(list, 'idp_list_tab')?.if).toContain('!query.idp');
    expect(byId(edit, 'idp_edit_view')?.if).toContain('!!query.idp');
    const dataSources = shell.data_sources ?? [];
    const idp = dataSources.find((d: any) => d.id === 'ssoIdp');
    expect(idp?.endpoint).toBe('/api/plugins/sirsoft-sso/admin/idps/{{query.idp}}');
    expect(idp?.initLocal).toBe('idpForm');
  });

  /**
   * @scenario tab=idps, subtabs=8
   * @effects eight_subtabs
   */
  it('IdP 편집 하위 탭 8개(기본, 연결 정보, 계정과 연결, 속성, 역할 매핑, 보안, 프로비저닝, 진단)', () => {
    const tabs = byId(edit, 'idp_edit_tabs')?.props?.tabs?.map((t: any) => t.id);
    expect(tabs).toEqual(['basic', 'connection', 'accounts', 'attributes', 'roles', 'security', 'provisioning', 'diagnostics']);
  });

  /**
   * @scenario tab=connection, schema=driver
   * @effects schema_driven_fields, secrets_separate
   */
  it('연결 정보 칸은 드라이버 칸 정의(idpForm.schema)로 그리고 비밀 칸은 secrets 에 쓴다', () => {
    const field = byId(edit, 'idp_config_field_{{field.key}}');
    expect(field?.iteration?.source).toContain('_local.idpForm?.schema');
    const s = text(edit);
    expect(s).toContain('idpForm.secrets');
    expect(s).toContain('idpForm.config');
    expect(s).toContain('sirsoft-sso.copyToClipboard');
  });

  /**
   * @scenario tab=roles, viewer=delegate|super
   * @effects admin_rule_locked_for_delegate, role_rules_locked_notice
   */
  it('관리자 역할 규칙과 잠긴 IdP 의 역할 방식은 최고관리자가 아니면 잠긴다(W14, W35)', () => {
    const s = text(edit);
    expect(s).toContain("rule.role === 'admin' && !_computed.isSuper");
    expect(byId(edit, 'role_rules_locked_notice')?.if).toContain('role_rules_locked');
  });

  /**
   * @scenario tab=policy, viewer=delegate
   * @effects super_only_fields_disabled
   */
  it('정책 탭의 사이트 전체 강제, 역할 강제, 비상 계정 편집은 최고관리자가 아니면 잠근다(D26)', () => {
    const nodes = flatten(policy).filter((n) => ['policy.enforce_site', 'policy.enforce_roles'].includes(n?.props?.name));
    expect(nodes).toHaveLength(2);
    for (const node of nodes) {
      expect(String(node.props.disabled)).toContain('is_super !== true');
    }
    expect(byId(policy, 'emergency_search')?.if).toContain('is_super === true');
  });

  /**
   * @scenario tab=policy, action=preview_impact|save
   * @effects impact_endpoint_called, save_uses_policy_body
   */
  it('저장 전 영향 미리보기를 부르고 저장은 policyForm.policy 를 PUT 한다(W34)', () => {
    expect(text(policy)).toContain('/api/plugins/sirsoft-sso/admin/policy/impact');
    const save = shell.named_actions?.savePolicy ?? flatten(shell).find((n) => n?.name === 'savePolicy');
    expect(text(save ?? shell)).toContain('"method":"PUT"');
    expect(text(shell)).toContain('_local.policyForm?.policy');
  });

  /**
   * @scenario tab=retention
   * @effects retention_fields_present
   */
  it('보존 탭은 보존일 3종과 알림 기준을 다룬다', () => {
    const s = text(retention);
    for (const key of ['attempts_retention_days', 'scim_retention_days', 'session_retention_days', 'policy.failure_alert_count', 'policy.cert_expiry_notice_days']) {
      expect(s).toContain(key);
    }
  });

  /**
   * @scenario tab=connection, action=preview_impact|save
   * @effects scim_first_issue_without_confirm
   */
  it('SCIM 토큰은 첫 발급이면 확인 없이, 재발급이면 무효화 확인 뒤 요청한다 — confirm 은 식이 아니라 고정 문구다', () => {
    // 엔진은 confirm 을 식 결과가 아니라 원문 유무로 판정해, 식이 '' 를 내도 빈 확인창을 띄운다(F1 M8 T2 실측)
    for (const node of [shell, list, edit, policy, retention].flatMap(flatten)) {
      if (typeof node?.confirm === 'string') expect(node.confirm.startsWith('{{'), node.confirm).toBe(false);
    }

    const first = byId(edit, 'issue_scim_token');
    const again = byId(edit, 'reissue_scim_token');
    expect(first.if).toBe('{{!_local.idpForm?.scim?.has_token}}');
    expect(again.if).toBe('{{!!_local.idpForm?.scim?.has_token}}');

    const call = (button: any) => button.actions.find((a: any) => a.handler === 'apiCall');
    expect(call(first).confirm).toBeUndefined();
    expect(call(again).confirm).toBe('$t:sirsoft-sso.admin.settings.idp.scim_token_reissue_confirm');
    for (const button of [first, again]) {
      expect(call(button).target).toBe('/api/plugins/sirsoft-sso/admin/idps/{{query.idp}}/scim-token');
      expect(call(button).onSuccess.map((a: any) => a.handler)).toEqual(['setState', 'refetchDataSource']);
    }
  });

  /**
   * @scenario tab=provisioning, field=deprovision_on_login
   * @effects on_login_fixed_on, on_login_not_submitted
   */
  it('「다음 로그인 때 비활성 계정 막기」는 켬 고정 표시이고 폼 값으로 보내지 않는다(D7 ③)', () => {
    const fixed = byId(edit, 'deprovision_on_login_fixed');
    const toggle = flatten(fixed).find((n: any) => n?.name === 'Toggle');
    expect(toggle?.props?.checked).toBe(true);
    expect(toggle?.props?.disabled).toBe(true);
    expect(toggle?.props?.name).toBeUndefined();
    expect(text(edit)).not.toContain('deprovision.on_login');
  });

  /**
   * @scenario tab=diagnostics, attempt=failed_with_summary
   * @effects response_summary_shown_when_present
   */
  it('진단의 최근 시도는 실패한 IdP 응답 요약(서명 여부, 유효 시각, 대상)이 있을 때만 한 줄로 보인다(§7 L-3)', () => {
    const summary = flatten(edit).find((n: any) => typeof n?.id === 'string' && n.id.startsWith('attempt_response_summary_'));
    expect(summary?.id).toBe('attempt_response_summary_{{attempt.id}}');
    expect(summary?.if).toContain('attempt.response_summary');
    const s = JSON.stringify(summary);
    for (const key of ['signed', 'unsigned', 'not_on_or_after', 'audiences']) {
      expect(s).toContain(`sirsoft-sso.admin.settings.idp.response_summary.${key}`);
    }
  });

  /**
   * @scenario tab=policy, notice=deactivate
   * @effects deactivate_notice_always_shown
   */
  it('정책 탭은 플러그인을 끄면 강제가 풀린다는 경고를 늘 보인다(W6, §7 L-13)', () => {
    const notice = byId(policy, 'policy_deactivate_notice');
    expect(notice).toBeDefined();
    expect(notice?.if).toBeUndefined();
    expect(JSON.stringify(notice)).toContain('sirsoft-sso.admin.settings.policy.deactivate_notice');
  });
});

describe('SSO IdP 편집 — 화면 폭에 따라 바뀌는 칸', () => {
  const nodes = flatten(edit);
  const tokens = (cls: unknown): string[] => (typeof cls === 'string' ? cls.split(/\s+/).filter(Boolean) : []);
  const byLabel = (key: string): any[] => nodes.filter((n) => n?.props?.['aria-label'] === `$t:sirsoft-sso.${key}`);
  /**
   * 폭 구간의 실효 클래스 토큰 — 구간 class(있으면 통째 교체) 위에 구간 class-merge 를 엔진 규칙으로 덧붙인 값 (engine-v1.98.0).
   * 접두 토큰(`sso:py-3`)끼리 충돌 교체가 되도록 서버가 내려 주는 접두 목록을 잠시 세운다.
   */
  const rangeTokens = (node: any, key: string): string[] => {
    const range = node?.responsive?.[key];
    if (!range) return [];
    const w = window as any;
    const prev = w.G7Config;
    w.G7Config = { ...(prev ?? {}), extensionClassPrefixes: ['sso'] };
    try {
      return tokens(applyResponsiveClassMerge(range.props?.className ?? node?.props?.className, range.classMerge));
    } finally {
      w.G7Config = prev;
    }
  };

  /**
   * @scenario screen=plugin_settings, tab=connection
   * @effects sp_info_copy_label_shown_from_640
   */
  it('SP 정보의 「복사」 글자는 휴대폰 폭에서 숨고 640px 이상에서 보인다', () => {
    const labels = nodes.filter((n) => n?.name === 'Span' && n?.text === '$t:sirsoft-sso.admin.common.copy');
    expect(labels.length).toBeGreaterThanOrEqual(1);
    for (const label of labels) {
      expect(tokens(label.props?.className)).toContain('sso:hidden');
      const wide = rangeTokens(label, '640-');
      expect(wide).toContain('sso:inline');
      expect(wide).not.toContain('sso:hidden');
    }
  });

  /**
   * @scenario screen=plugin_settings, tab=attributes|roles
   * @effects mapping_inputs_fixed_width_from_768
   */
  it('속성 매핑의 필드·원천 칸과 역할 매핑의 역할 칸은 768px 이상에서 12rem(w-48) 폭이다', () => {
    const inputs = ['admin.settings.idp.fields.attribute_field', 'admin.settings.idp.fields.attribute_source', 'admin.settings.idp.fields.role_role'].flatMap(byLabel);
    expect(inputs).toHaveLength(3);
    for (const input of inputs) {
      expect(tokens(input.props?.className)).toContain('sso-input');
      expect(tokens(input.props?.className)).not.toContain('sso:w-48');
      const wide = rangeTokens(input, '768-');
      expect(wide).toContain('sso-input');
      expect(wide).toContain('sso:w-48');
    }
  });

  /**
   * @scenario screen=plugin_settings, tab=diagnostics
   * @effects ldap_test_inputs_third_width_from_640
   */
  it('LDAP 연결 시험의 아이디·비밀번호 칸은 640px 이상에서 3분의 1 폭이다', () => {
    const inputs = ['admin.idp.ldap_test_identifier', 'admin.idp.ldap_test_password'].flatMap(byLabel);
    expect(inputs).toHaveLength(2);
    for (const input of inputs) {
      expect(tokens(input.props?.className)).toContain('sso-input');
      expect(tokens(input.props?.className)).not.toContain('sso:w-1/3');
      const wide = rangeTokens(input, '640-');
      expect(wide).toContain('sso-input');
      expect(wide).toContain('sso:w-1/3');
    }
  });
});
