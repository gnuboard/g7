// e2e:allow Playwright 스펙 없음(이 플러그인 tests/Playwright/specs/sso-*.spec.ts 는 로그인 흐름만 덮는다) — 브라우저 실측은 F1 Chrome MCP 계획서 §10 M1·M4·M6·M11(W40) — P4 는 Vitest 로 덮음
/**
 * @file injections.test.tsx
 * @description 다른 화면에 주입하는 P4 조각의 구운 JSON 계약 — 로그인 버튼(사용자, 관리자), 마이페이지 연결 카드, 회원 상세 SSO 탭,
 * 회원 목록 IdP 필터와 부서·직급 열(A1 A6t — 표와 열 선택기 둘 다). 모든 문구 키가 ko/en 에 있다.
 */
import { describe, it, expect, beforeAll } from 'vitest';
import fs from 'fs';
import path from 'path';
import { TranslationEngine } from '@core/template-engine/TranslationEngine';
import { DataBindingEngine } from '@core/template-engine/DataBindingEngine';
import { ROOT, load, flatten, pluginKeys, missingKeys, text, lang, dig } from './p4-layout-helpers';

const T = 'sirsoft-basic';

/**
 * 디렉터리 아래의 구운 JSON 경로를 모읍니다.
 *
 * @param dir - 디렉터리
 * @returns 경로 목록
 */
function jsonUnder(dir: string): string[] {
  return fs.readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) return jsonUnder(full);
    return entry.name.endsWith('.json') ? [full] : [];
  });
}

/**
 * 문구 트리의 잎을 `[점 경로, 문장]` 으로 펼칩니다.
 *
 * @param tree - 문구 트리
 * @param prefix - 앞 경로
 * @returns 잎 목록
 */
function leaves(tree: any, prefix = ''): Array<[string, string]> {
  return Object.entries(tree).flatMap(([key, value]) => {
    const full = prefix ? `${prefix}.${key}` : key;
    return typeof value === 'string' ? [[full, value] as [string, string]] : leaves(value, full);
  });
}

/**
 * 조각의 init_actions 를 실제 바인딩 엔진으로 돌려, 조건을 통과한 토스트의 종류와 문구를 돌려줍니다.
 *
 * @param json - 구운 조각 JSON
 * @param query - 화면 주소 쿼리
 * @param locale - ko, en
 * @returns 띄울 토스트 목록
 */
function initToasts(json: any, query: Record<string, string>, locale: 'ko' | 'en'): Array<{ type: string; message: string }> {
  const engine = new DataBindingEngine();
  const context = { query, $templateId: T, $locale: locale };
  const evaluate = (value: string): any => {
    const m = /^\{\{([\s\S]*)\}\}$/.exec(value);
    return m ? engine.evaluateExpression(m[1], context, { skipCache: true }) : value;
  };

  return (json.init_actions ?? [])
    .filter((action: any) => action.handler === 'toast' && (action.if === undefined || !!evaluate(action.if)))
    .map((action: any) => {
      const message = evaluate(action.params.message);
      const token = /^\$t:sirsoft-sso\.([a-z0-9_.]+)$/.exec(message);

      return { type: action.params.type, message: token ? String(dig(lang(locale), token[1])) : message };
    });
}

const userButtons = load('resources/extensions/auth-login-sso-buttons');
const adminButtons = load('resources/extensions/admin-login-sso-buttons');
const mypage = load('resources/extensions/mypage-sso-accounts-card');
const detail = load('resources/extensions/admin-user-detail-sso-tab');
const filter = load('resources/extensions/admin-user-list-sso-filter');
const columns = load('resources/extensions/admin-user-list-sso-columns');

describe('SSO 주입 조각', () => {
  beforeAll(() => {
    // 식 안 $t() 는 번역 엔진 싱글턴을 읽는다
    const engine = TranslationEngine.getInstance({ defaultLocale: 'ko', fallbackLocale: 'en' });
    for (const locale of ['ko', 'en'] as const) {
      for (const [key, value] of leaves(lang(locale))) {
        engine.setTranslationValue(T, locale, `sirsoft-sso.${key}`, value);
      }
    }
  });

  /**
   * @scenario extension=all, locale=ko|en
   * @effects injection_i18n_keys_present
   */
  it('문구 키가 ko·en 에 모두 있다', () => {
    const keys = [userButtons, adminButtons, mypage, detail, filter, columns].flatMap(pluginKeys);
    expect(missingKeys(keys)).toEqual([]);
  });

  /**
   * @scenario surface=user|admin
   * @effects buttons_above_social, guard_param, start_login_handler
   */
  it.each([
    { json: userButtons, point: 'auth_login_extensions', guard: 'user' },
    { json: adminButtons, point: 'admin_login_extensions', guard: 'admin' },
  ])('$point 에 회사 계정 버튼을 priority 10 으로 붙이고 guard=$guard 로 목록을 받는다', ({ json, point, guard }) => {
    expect(json.extension_point).toBe(point);
    expect(json.priority).toBe(10);
    const ds = (json.data_sources ?? [])[0];
    expect(ds.endpoint).toBe('/api/plugins/sirsoft-sso/idps');
    expect(text(ds)).toContain(`"guard":"${guard}"`);
    expect(text(json)).toContain('sirsoft-sso.startLogin');
  });

  /**
   * @scenario surface=mypage, action=link|unlink
   * @effects ticket_start, unlink_block_shown, priority_before_marketing
   */
  it('마이페이지 카드는 시작 티켓 API 로 연결하고 해제 차단 사유를 보인다(W25)', () => {
    expect(mypage.target_layout).toBe('mypage/profile');
    expect(mypage.priority).toBeLessThan(100);
    const s = text(mypage);
    expect(s).toContain('sirsoft-sso.startWithTicket');
    expect(s).toContain('/plugins/sirsoft-sso/me/links/start');
    expect(s).toContain('unlink_block');
    expect(s).toContain('/api/plugins/sirsoft-sso/me/accounts/');
  });

  /**
   * @scenario surface=mypage, result=already_linked|unknown_code|linked
   * @effects link_error_toast, unknown_code_generic
   */
  it('마이페이지 카드에서 시작한 연결이 실패해 ?sso_error= 로 돌아오면 사유 문구를 오류 토스트로 띄우고, 모르는 코드는 일반 실패 문구다', () => {
    // 연결(LINK)·재확인 의도의 실패는 출발 화면(/mypage/profile)으로 sso_error 를 붙여 돌아온다(LoginFlowService::failureUrl).
    // 카드가 sso_linked(성공)만 읽어 실패 안내가 0 이었다(F1 M4 T7 실측)
    for (const locale of ['ko', 'en'] as const) {
      const errors = lang(locale).errors;

      expect(initToasts(mypage, { sso_error: 'already_linked' }, locale), locale).toEqual([{ type: 'error', message: errors.already_linked }]);
      for (const code of ['access_denied', 'ticket_invalid', 'state_expired']) {
        expect(initToasts(mypage, { sso_error: code }, locale), `${locale} ${code}`).toEqual([{ type: 'error', message: errors[code] }]);
      }
      expect(initToasts(mypage, { sso_error: 'idp_said_something_else' }, locale), locale).toEqual([{ type: 'error', message: errors.generic }]);
      expect(initToasts(mypage, { sso_linked: 'mock_oidc_b' }, locale), locale).toEqual([{ type: 'success', message: lang(locale).mypage.linked_toast }]);
      expect(initToasts(mypage, {}, locale), locale).toEqual([]);
    }
  });

  /**
   * @scenario surface=reauth_return, result=access_denied|ticket_invalid|unknown_code
   * @effects reauth_error_toast, reauth_screens_all_covered, unknown_code_generic
   */
  it('회사 계정 재확인을 시작하는 코어 화면 세 곳도 실패해 ?sso_error= 로 돌아오면 오류 토스트를 띄운다', () => {
    // 재확인(REAUTH) 실패도 출발 화면(코어 challenge 의 return_path)으로 sso_error 를 붙여 돌아온다 — 코어 화면은 플러그인 쿼리 이름을 모른다
    const templates = path.resolve(ROOT, '../../../templates/_bundled');
    const screens = [
      { name: 'mypage-profile-edit-sso-reauth-result', layout: 'mypage/profile-edit', source: 'sirsoft-basic/layouts/partials/mypage/profile/_password_verify_section.json', returnPath: '/mypage/profile/edit' },
      { name: 'admin-user-form-sso-reauth-result', layout: 'admin_user_form', source: 'sirsoft-admin_basic/layouts/admin_user_form.json', returnPath: "{{'/admin/users/' + route.id + '/edit'}}" },
      { name: 'admin-settings-sso-reauth-result', layout: 'admin_settings', source: 'sirsoft-admin_basic/layouts/partials/admin_settings/_modal_password_confirm.json', returnPath: '/admin/settings?tab=security&reauth=app_key' },
    ];

    // 두 템플릿에서 재확인 복귀 경로를 싣는 파일이 이 표와 같다 — 새 화면이 생기면 여기서 걸린다.
    // 한 파일에 선언이 여럿일 수 있다(basic 회원정보 수정은 메일 없는 회원용 「본인 확인 계속하기」가 두 번째 선언 — A1 메일 없는 재확인).
    // 그래서 파일은 중복 없이 비교하고, 선언마다 복귀 경로가 그 화면의 경로와 같은지(= 같은 확장 대상 레이아웃으로 돌아오는지) 본다.
    const declared = ['sirsoft-basic', 'sirsoft-admin_basic'].flatMap((t) => jsonUnder(path.join(templates, t, 'layouts'))).flatMap((file) =>
      flatten(JSON.parse(fs.readFileSync(file, 'utf8')))
        .filter((n) => typeof n?.return_path === 'string')
        .map((n) => ({ source: path.relative(templates, file).replace(/\\/g, '/'), returnPath: n.return_path as string })),
    );
    expect([...new Set(declared.map((d) => d.source))].sort()).toEqual(screens.map((s) => s.source).sort());
    for (const { source, returnPath } of declared) {
      const screen = screens.find((s) => s.source === source)!;
      expect(returnPath, source).toBe(screen.returnPath);
    }

    for (const screen of screens) {
      expect(fs.readFileSync(path.join(templates, screen.source), 'utf8')).toContain(screen.returnPath);
      const json = load(`resources/extensions/${screen.name}`);
      expect(json.target_layout, screen.name).toBe(screen.layout);
      expect(pluginKeys(json).length, screen.name).toBeGreaterThan(0);

      for (const locale of ['ko', 'en'] as const) {
        const errors = lang(locale).errors;
        expect(initToasts(json, { sso_error: 'access_denied' }, locale), `${screen.name} ${locale}`).toEqual([{ type: 'error', message: errors.access_denied }]);
        expect(initToasts(json, { sso_error: 'ticket_invalid' }, locale), `${screen.name} ${locale}`).toEqual([{ type: 'error', message: errors.ticket_invalid }]);
        expect(initToasts(json, { sso_error: 'idp_said_something_else' }, locale), `${screen.name} ${locale}`).toEqual([{ type: 'error', message: errors.generic }]);
        expect(initToasts(json, { verification_token: 'x' }, locale), `${screen.name} ${locale}`).toEqual([]);
      }
    }
  });

  /**
   * @scenario surface=admin_user_detail
   * @effects tab_injected, reads_user_sso, email_differs_badge
   */
  it('회원 상세에 SSO 탭을 더하고 회원 응답의 sso 키를 읽는다', () => {
    expect(detail.target_layout).toBe('admin_user_detail');
    const s = text(detail);
    expect(s).toContain('"id":"ext_sso"');
    expect(s).toContain('user?.data?.sso');
    expect(s).toContain('email_differs');
    expect(s).toContain('mapped_roles');
  });

  /**
   * @scenario surface=admin_user_list, filter=idp
   * @effects ext_filter_token
   */
  it('회원 목록 필터는 ext_filters 원소 sirsoft-sso.idp= 를 넣고 뺀다', () => {
    expect(filter.extension_point).toBe('admin_user_list_filters');
    expect(text(filter)).toContain("'sirsoft-sso.idp='");
  });

  /**
   * @scenario surface=admin_user_list, filter=unlinked
   * @effects unlinked_option_maps_to_ext_token
   */
  it('「SSO 연결 없음」 선택지는 sirsoft-sso.unlinked=1 원소로 바뀌고, IdP 원소와 함께 넣지 않는다(디자인 Ⓐ6)', () => {
    const select = flatten(filter).find((n: any) => n?.id === 'sso_idp_filter');
    const options = (select?.children ?? []).filter((n: any) => n?.name === 'Option');
    expect(options.map((o: any) => o?.props?.value).slice(0, 2)).toEqual(['', '__unlinked__']);
    const s = text(filter);
    expect(s).toContain("'sirsoft-sso.unlinked=1'");
    expect(s).toContain('sirsoft-sso.admin.user_list.filter_unlinked');
    expect(s).toContain("e !== 'sirsoft-sso.unlinked=1'");
  });

  /**
   * @scenario surface=admin_user_list, columns=department|title
   * @effects grid_and_selector_both, not_default_column
   */
  it('부서, 직급 열을 표(users_data_grid)와 열 선택기(column_selector) 둘 다에 덧붙이고 기본 열에는 넣지 않는다', () => {
    const injects = (columns.injections ?? []).map((i: any) => i.target_id);
    expect(injects).toEqual(expect.arrayContaining(['users_data_grid', 'column_selector']));
    const s = text(columns);
    expect(s).toContain('sso_department');
    expect(s).toContain('sso_title');
    expect(s).not.toContain('defaultColumns');
    expect(flatten(columns).some((n) => n?.text === "{{row.sso_department ?? '-'}}")).toBe(true);
  });
});
