// e2e:allow 레이아웃을 읽는 Vitest 계약 파일이라 화면 동작을 바꾸지 않는다 — 같은 화면의 브라우저 검증은 tests/Playwright/specs/admin/social-provider-settings.spec.ts(마스크 표시 포함)와 social-user-surface.spec.ts 가 맡는다.
/**
 * @file admin.test.ts
 * @description 관리자 화면(설정·진단·통계)과 관리자 회원 표면 확장(상세 탭·목록 필터·목록 열)의 구운 JSON 계약.
 *
 * 렌더 대신 구운 레이아웃 JSON 을 읽어 요청 주소·탭 주소·조건·확장 주입 자리를 단언하고, 표현식 두 개(정렬 순서 바꾸기·가입경로 필터 원소)는
 * 실제로 계산해 본다. 모든 `$t:` 키가 ko/en 에 있는지도 본다.
 */
import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';

const ROOT = path.resolve(__dirname, '../../../..');
const read = (rel: string): any => JSON.parse(fs.readFileSync(path.join(ROOT, rel), 'utf8'));
const lang = (locale: string): any => read(`resources/lang/${locale}.json`);

const SETTINGS = [
  'resources/layouts/admin/plugin_settings.json',
  'resources/layouts/admin/partials/plugin_settings/_provider_editor.json',
  'resources/layouts/admin/partials/plugin_settings/_provider_impact.json',
  'resources/layouts/admin/partials/plugin_settings/_provider_add.json',
  'resources/layouts/admin/partials/plugin_settings/_policy_tab.json',
];
const DIAGNOSTICS = 'resources/layouts/admin/social_login_diagnostics.json';
const STATS = 'resources/layouts/admin/social_login_stats.json';
const DETAIL_TAB = 'resources/extensions/admin_user_detail_social_tab.json';
const LIST_FILTER = 'resources/extensions/admin_user_list_social_filter.json';
const LIST_COLUMN = 'resources/extensions/admin_user_list_social_column.json';

/**
 * 노드·액션 트리를 평탄화합니다.
 *
 * @param node 노드
 * @returns 객체 목록
 */
function flatten(node: any): any[] {
  if (!node || typeof node !== 'object') return [];
  if (Array.isArray(node)) return node.flatMap(flatten);
  return [node, ...Object.values(node).flatMap(flatten)];
}

/**
 * 여러 파일의 노드를 한데 모읍니다.
 *
 * @param files 파일 목록
 * @returns 평탄화한 객체
 */
function all(files: string[]): any[] {
  return files.flatMap((f) => flatten(read(f)));
}

/**
 * `id` 로 노드를 찾습니다 — 반복 안 노드는 행 키가 붙은 동적 id(`이름_{{키}}`)라 그 앞부분으로도 찾는다.
 *
 * @param nodes 평탄화한 객체
 * @param id 노드 id (동적 꼬리 제외)
 * @returns 노드
 */
function byId(nodes: any[], id: string): any {
  return nodes.find((n) => n.id === id) ?? nodes.find((n) => typeof n.id === 'string' && n.id.startsWith(`${id}_{{`));
}

/**
 * 플러그인 문구 키를 모두 모읍니다 (`$t:` 토큰과 `$t('…')` 호출의 고정 키, 매개변수 꼬리 제외).
 *
 * @param json 구운 JSON
 * @returns 키 목록 (플러그인 접두 제외)
 */
function pluginKeys(json: any): string[] {
  const text = JSON.stringify(json);
  const tokens = [...text.matchAll(/\$t:(?:defer:)?sirsoft-social_login\.([a-z0-9_.]+[a-z0-9_])/g)].map((m) => m[1]);
  const calls = [...text.matchAll(/\$t\('sirsoft-social_login\.([a-z0-9_.]+[a-z0-9_])'\)/g)].map((m) => m[1]);

  return [...new Set([...tokens, ...calls])];
}

/**
 * 점 경로로 값을 꺼냅니다.
 *
 * @param obj 객체
 * @param key 점 경로
 * @returns 값
 */
function dig(obj: any, key: string): unknown {
  return key.split('.').reduce((acc, part) => (acc == null ? undefined : acc[part]), obj);
}

/**
 * `{{…}}` 표현식을 주어진 변수로 계산합니다 (계약 확인용 — 엔진 평가기와 같은 JS 식).
 *
 * @param expr 표현식 문자열
 * @param scope 변수
 * @returns 결과
 */
function evaluate(expr: string, scope: Record<string, unknown>): unknown {
  const body = expr.replace(/^\{\{/, '').replace(/\}\}$/, '');
  // eslint-disable-next-line no-new-func
  return new Function(...Object.keys(scope), `return (${body});`)(...Object.values(scope));
}

describe('관리자 설정 화면', () => {
  const nodes = all(SETTINGS);
  const root = read(SETTINGS[0]);

  /**
   * @scenario surface=settings, tab=providers|policy
   * @effects settings_sources_and_tabs
   */
  it('정책·제공자 데이터소스를 받고, 탭은 주소의 tab 으로 열리며 바꾸면 tab 만 바꾼다', () => {
    const sources = Object.fromEntries((root.data_sources ?? []).map((d: any) => [d.id, d.endpoint]));
    expect(sources.settings).toBe('/api/admin/plugins/{{route.identifier}}/settings');
    expect(sources.providers).toBe('/api/plugins/sirsoft-social_login/admin/providers');

    const tabs = byId(nodes, 'settings_tabs');
    expect(tabs.props.activeTabId).toBe("{{query.tab || 'providers'}}");
    expect(tabs.props.tabs.map((t: any) => t.id)).toEqual(['providers', 'policy']);
    const nav = flatten(tabs.actions).find((a) => a.handler === 'navigate');
    // 탭마다 다른 화면이라 이전 탭 주소 조건을 물려받지 않는다(병합하지 않음).
    expect(nav.params.mergeQuery).toBeUndefined();
    expect(nav.params.query).toEqual({ tab: '{{$args[0]}}' });

    expect(byId(nodes, 'providers_tab').if).toContain("(query.tab || 'providers') === 'providers'");
    expect(byId(nodes, 'policy_tab').if).toContain("query.tab === 'policy'");
  });

  /**
   * 설정 열람 권한(settings.view)만 있는 운영자도 화면에 들어와 읽기 전용으로 본다 — 화면이 쓰는 두 GET(코어 설정 core.plugins.read,
   * 제공자 settings.view)과 같은 권한으로 연다. 변경 권한(core.plugins.update)을 요구하면 읽기 전용 배너·비활성 단추에 닿을 수 없다.
   *
   * @scenario surface=settings, role=settings_view_only
   * @effects settings_opens_read_only_for_view_permission
   */
  it('설정 화면은 열람 권한으로 열리고, 변경 단추는 변경 권한이 없으면 잠긴다', () => {
    expect(root.permissions).toEqual(['sirsoft-social_login.settings.view', 'core.plugins.read']);

    expect(root.computed.canEditProviders).toBe('{{providers?.data?.abilities?.can_update === true}}');
    for (const id of ['edit_provider_button_{{provider.key}}', 'test_provider_button_{{provider.key}}', 'test_login_button_{{provider.key}}']) {
      expect(byId(nodes, id).props.disabled, id).toContain('!_computed.canEditProviders');
    }
    expect(byId(nodes, 'save_button').props.disabled).toContain('_computed.isPluginReadOnly');
    expect(byId(nodes, 'providers_read_only_banner').if).toContain('!_computed.canEditProviders');
  });

  /**
   * @scenario surface=provider_card
   * @effects card_copy_test_impact_actions
   */
  it('카드마다 Redirect URI 복사·연결 테스트·브라우저 테스트·영향 보기를 플러그인 API 로 건다', () => {
    const copy = flatten(byId(nodes, 'copy_redirect_uri_button').actions).find((a) => a.handler === 'sirsoft-social_login.copyToClipboard');
    expect(copy.params.text).toBe('{{provider.redirect_uri}}');

    const test = flatten(byId(nodes, 'test_provider_button').actions).find((a) => a.handler === 'apiCall');
    expect([test.target, test.params.method]).toEqual(['/api/plugins/sirsoft-social_login/admin/providers/{{provider.key}}/test', 'POST']);

    const login = flatten(byId(nodes, 'test_login_button').actions).find((a) => a.handler === 'sirsoft-social_login.openTestLogin');
    expect(login.params.provider).toBe('{{provider.key}}');

    const impact = flatten(byId(nodes, 'impact_button').actions).find((a) => a.handler === 'apiCall');
    expect([impact.target, impact.params.method]).toEqual(['/api/plugins/sirsoft-social_login/admin/providers/{{provider.key}}/impact', 'GET']);
    expect(byId(nodes, 'provider_impact_panel').if).toBe('{{_local.impact?.key === provider.key}}');
  });

  /**
   * @scenario surface=reorder, direction=up|down
   * @effects reorder_swaps_neighbour
   */
  it('위·아래 화살표는 바로 옆 제공자와 자리를 바꾼 순서를 보낸다', () => {
    const keys = ['kakao', 'naver', 'google', 'facebook'];
    const up = flatten(byId(nodes, 'move_up_button').actions).find((a) => a.handler === 'apiCall');
    const down = flatten(byId(nodes, 'move_down_button').actions).find((a) => a.handler === 'apiCall');
    const scope = { _computed: { sortableKeys: keys }, provider: { key: 'google' } };

    expect(up.target).toBe('/api/plugins/sirsoft-social_login/admin/providers/reorder');
    expect(evaluate(up.params.body.keys, scope)).toEqual(['kakao', 'google', 'naver', 'facebook']);
    expect(evaluate(down.params.body.keys, scope)).toEqual(['kakao', 'naver', 'facebook', 'google']);
    expect(evaluate(byId(nodes, 'move_up_button').props.disabled, { ...scope, provider: { key: 'kakao' } })).toBe(true);
    expect(evaluate(byId(nodes, 'move_down_button').props.disabled, { ...scope, provider: { key: 'facebook' } })).toBe(true);
  });

  /**
   * @scenario surface=editor, change=disable_enabled|credentials|other
   * @effects save_confirms_impact_first, save_sends_scopes_array
   */
  it('켜진 제공자를 끄거나 자격증명을 바꾸면 영향을 먼저 보이고, 그 밖에는 바로 저장한다(범위는 배열로 보냄)', () => {
    const save = flatten(byId(nodes, 'editor_save_button').actions).find((a) => a.handler === 'conditions');
    const condition = save.conditions[0].if;
    const provider = { enabled: true, client_id: 'a', client_secret: '••••••••' };
    expect(evaluate(condition, { provider, _local: { providerForm: { enabled: false, client_id: 'a', client_secret: '••••••••' } } })).toBe(true);
    expect(evaluate(condition, { provider, _local: { providerForm: { enabled: true, client_id: 'b', client_secret: '••••••••' } } })).toBe(true);
    expect(evaluate(condition, { provider, _local: { providerForm: { enabled: true, client_id: 'a', client_secret: '••••••••' } } })).toBe(false);
    expect(JSON.stringify(save.conditions[1] ?? save)).toContain('saveProvider');

    const put = flatten(root.named_actions?.saveProvider).find((a) => a.handler === 'apiCall');
    expect([put.target, put.params.method]).toEqual(['/api/plugins/sirsoft-social_login/admin/providers/{{_local.editingKey}}', 'PUT']);
    // 편집 칸을 그대로 보낸다 — 요청 범위 문자열(scopes_text)은 서버가 나눈다(표현식 평가기는 정규식 리터럴을 거부해 식이 원문으로 나갔다).
    expect(put.params.body).toBe('{{_local.providerForm}}');
  });

  /**
   * @scenario surface=expressions
   * @effects no_regex_literal_in_expressions
   */
  it('관리자 화면 표현식에 정규식 리터럴이 없다 — 평가기가 조용히 거부해 식 원문이 그대로 나간다', () => {
    const files = [...SETTINGS, DIAGNOSTICS, STATS, DETAIL_TAB, LIST_FILTER, LIST_COLUMN];
    const expressions = files.flatMap((f) => [...JSON.stringify(read(f)).matchAll(/\{\{(.*?)\}\}/g)].map((m) => m[1]));
    const offenders = expressions.filter((e) => /(?:\(|,|=|:)\s*\/(?![/*])[^/]+\/[gimsuy]*\s*[,)]/.test(e.replace(/\\\\/g, '\\')));
    expect(offenders).toEqual([]);
  });

  /**
   * @scenario surface=warnings
   * @effects risk_warnings_conditions
   */
  it('위험 선택에 빨간 경고: 검증 이메일 자동 연결 · 2단계 인증 끔 · 대체 이메일', () => {
    expect(byId(nodes, 'auto_link_warning').if).toBe("{{_local.form?.email_conflict_policy === 'auto_link_verified'}}");
    expect(byId(nodes, 'two_factor_off_warning').if).toBe('{{_local.form?.two_factor_enabled === false}}');
    expect(byId(nodes, 'placeholder_warning').if).toBe("{{_local.providerForm?.email_missing_policy === 'placeholder'}}");
  });

  /**
   * @scenario surface=add_custom
   * @effects add_custom_posts_and_limits
   */
  it('일반 OIDC 추가는 POST 이고 한도에 닿으면 열 수 없다', () => {
    const submit = flatten(byId(nodes, 'add_submit_button').actions).find((a) => a.handler === 'apiCall');
    expect([submit.target, submit.params.method]).toEqual(['/api/plugins/sirsoft-social_login/admin/providers', 'POST']);
    const disabled = byId(nodes, 'toggle_add_button').props.disabled;
    expect(evaluate(disabled, { _computed: { canEditProviders: true }, providers: { data: { limits: { custom_count: 10, custom_limit: 10 } } } })).toBe(true);
    expect(evaluate(disabled, { _computed: { canEditProviders: true }, providers: { data: { limits: { custom_count: 3, custom_limit: 10 } } } })).toBe(false);
  });

  /**
   * @scenario surface=editor
   * @effects editor_state_replaced_not_merged
   */
  it('편집 칸을 열고 닫고 저장한 뒤의 상태는 얕은 병합으로 통째로 바뀐다 — 앞서 연 카드의 이름 언어·버튼 색이 섞이지 않는다', () => {
    const resets = flatten(SETTINGS.map(read)).filter((a) => a.handler === 'setState' && a.params && 'providerFormOrigin' in a.params);
    // 열기(plugin_settings), 저장 성공(plugin_settings), 취소(_provider_editor)
    expect(resets).toHaveLength(3);
    for (const action of resets) {
      expect(action.params.merge).toBe('shallow');
    }
  });

  /**
   * @scenario surface=add_custom
   * @effects add_form_cleared_on_close
   */
  it('일반 OIDC 추가 폼은 [추가] 단추로 열고 닫을 때, [취소], 추가 성공 때 화면 초기값으로 통째로 비워진다 — 앞 제공자의 비밀값·주소가 다음 추가로 가지 않는다', () => {
    const initial = read(SETTINGS[0]).initLocal.newProvider;
    expect(Object.keys(initial).length).toBeGreaterThan(0);
    const closers = flatten(SETTINGS.map(read)).filter((a) => a.handler === 'setState' && a.params && 'showAddForm' in a.params);
    // [추가] 단추(열기·닫기), [취소], 추가 성공
    expect(closers).toHaveLength(3);
    for (const action of closers) {
      expect(action.params.merge).toBe('shallow');
      expect(action.params.newProvider).toEqual(initial);
    }
  });

  /**
   * @scenario surface=editor, change=disable_enabled|credentials
   * @effects impact_confirm_closed_when_editor_changes
   */
  it('[저장]이 띄운 영향 확인은 편집 칸이 바뀌면(다른 카드 열기·취소·저장 성공) 닫히고, [계속 저장]은 그 카드 편집 칸이 열렸을 때만 보인다', () => {
    // 모집단: 편집 칸 대상(editingKey)을 바꾸는 setState 전량 — 열기(plugin_settings), 저장 성공(plugin_settings), 취소(_provider_editor)
    const switches = flatten(SETTINGS.map(read)).filter((a) => a.handler === 'setState' && a.params && 'editingKey' in a.params);
    expect(switches).toHaveLength(3);
    const pending = { key: 'kakao', confirm: true, social_only_users: 1 };
    const numbersOnly = { key: 'naver', confirm: false, social_only_users: 1 };
    for (const action of switches) {
      expect(action.params).toHaveProperty('impact');
      const next = (impact: unknown): unknown =>
        typeof action.params.impact === 'string' ? evaluate(action.params.impact, { _local: { impact } }) : action.params.impact;
      expect(next(pending)).toBeNull();
      // [영향 보기]로 연 수치 패널은 열기·취소로 닫지 않는다(저장 성공은 종전대로 모두 닫는다)
      expect([null, numbersOnly]).toContainEqual(next(numbersOnly));
    }

    const confirm = byId(nodes, 'impact_confirm_button').if;
    expect(evaluate(confirm, { provider: { key: 'kakao' }, _local: { impact: pending, editingKey: 'kakao' } })).toBe(true);
    expect(evaluate(confirm, { provider: { key: 'kakao' }, _local: { impact: pending, editingKey: 'google' } })).toBe(false);
    expect(evaluate(confirm, { provider: { key: 'kakao' }, _local: { impact: pending, editingKey: null } })).toBe(false);
  });

  /**
   * @scenario surface=editor, field=client_secret|admin_key
   * @effects secret_hint_shown
   */
  it('비밀값 칸은 저장된 마스크를 그대로 보이고, 그대로 두면 유지·비우면 삭제라는 안내를 붙인다', () => {
    // [설정 편집]이 제공자 응답을 편집 칸으로 옮긴다 — 마스크는 마스크대로, 미설정(null)은 빈 칸으로 남는다.
    const open = flatten(root).find((a) => a.handler === 'setState' && a.params?.editingKey === '{{provider.key}}');
    const provider = { key: 'kakao', client_id: 'a', client_secret: '••••••••', admin_key: null, scopes: ['profile'], label: { ko: '카카오' } };
    const form = evaluate(open.params.providerForm, { provider }) as Record<string, unknown>;
    expect([form.client_secret, form.admin_key]).toEqual(['••••••••', null]);

    // 편집 칸은 그 값에 묶이고, 비밀값 칸은 값을 덮어쓰지 않는 비밀번호 칸이다.
    const editor = flatten(read(SETTINGS[1]));
    expect(byId(editor, 'provider_editor').dataKey).toBe('providerForm');
    for (const name of ['client_secret', 'admin_key']) {
      const input = editor.find((n) => n.props?.name === name);
      expect(input.props.type).toBe('password');
      expect(input.props).not.toHaveProperty('value');
    }

    // 안내 문구는 마스크 모양과 유지·삭제 규칙을 말한다.
    expect(JSON.stringify(editor)).toContain('$t:sirsoft-social_login.admin.providers.hints.secret');
    for (const locale of ['ko', 'en']) {
      expect(String(dig(lang(locale), 'admin.providers.hints.secret'))).toContain('••••••••');
    }
  });
});

describe('진단·통계 화면', () => {
  const diag = read(DIAGNOSTICS);
  const stats = read(STATS);

  /**
   * @scenario surface=diagnostics
   * @effects diagnostics_sources_and_filters
   */
  it('진단은 요약·시도(기본 실패만)·웹훅을 받고 필터는 주소에 담는다', () => {
    const sources = Object.fromEntries(diag.data_sources.map((d: any) => [d.id, d]));
    expect(sources.diagnostics.endpoint).toBe('/api/plugins/sirsoft-social_login/admin/diagnostics');
    // 기본은 실패만, 「모든 결과」는 빈 값 대신 all 로 주소에 남아 새로고침해도 유지된다(빈 쿼리 값은 주소에서 빠진다).
    const outcome = sources.attempts.params.outcome;
    expect(evaluate(outcome, { query: {} })).toBe('failed');
    expect(evaluate(outcome, { query: { attempt_outcome: 'all' } })).toBe('');
    expect(evaluate(outcome, { query: { attempt_outcome: 'link' } })).toBe('link');
    expect(JSON.stringify(read(DIAGNOSTICS))).toContain('"value":"all"');
    expect(sources.webhookEvents.params.status).toBe("{{query.webhook_status || ''}}");
    expect(diag.permissions).toEqual(['sirsoft-social_login.settings.view']);

    const nodes = flatten(diag);
    const nav = flatten(byId(nodes, 'webhook_status_filter').actions).find((a) => a.handler === 'navigate');
    expect(nav.params.mergeQuery).toBe(true);
    expect(nav.params.query.webhook_status).toBe('{{$event.target.value}}');
    for (const id of ['queue_card', 'mail_card', 'mock_card', 'members_card', 'providers_status_table', 'attempts_datagrid', 'webhooks_datagrid']) {
      expect(byId(nodes, id), id).toBeTruthy();
    }
  });

  /**
   * @scenario surface=stats, days=7|30|90
   * @effects stats_period_in_query
   */
  it('통계는 기간을 주소(days, 기본 30)에서 읽고 버튼이 기간만 바꾼다', () => {
    expect(stats.data_sources[0].params.days).toBe('{{query.days || 30}}');
    const nodes = flatten(stats);
    for (const days of [7, 30, 90]) {
      const nav = flatten(byId(nodes, `period_${days}`).actions).find((a) => a.handler === 'navigate');
      expect(nav.params.query.days).toBe(days);
    }
  });

  /**
   * 고른 기간은 색만이 아니라 aria-pressed 로도 알린다 — 화면 낭독기는 버튼 색을 읽지 못한다.
   *
   * @scenario surface=stats, days=7|30|90
   * @effects stats_period_pressed_state
   */
  it('기간 버튼은 고른 기간만 aria-pressed=true 다(기본 30)', () => {
    const nodes = flatten(stats);
    const pressed = (query: Record<string, unknown>) => [7, 30, 90].map((d) => evaluate(String(byId(nodes, `period_${d}`).props['aria-pressed']), { query, String }));
    expect(pressed({})).toEqual([false, true, false]);
    expect(pressed({ days: '7' })).toEqual([true, false, false]);
    expect(pressed({ days: '90' })).toEqual([false, false, true]);
  });
});

describe('관리자 회원 표면 확장', () => {
  /**
   * @scenario surface=user_detail_tab
   * @effects detail_tab_and_content_linked, unlink_button_by_ability
   */
  it('회원 상세 탭과 본문이 같은 id 로 이어지고, 강제 해제는 응답의 해제 권한으로만 보인다', () => {
    const ext = read(DETAIL_TAB);
    expect(ext.target_layout).toBe('admin_user_detail');
    const tabItem = flatten(ext).find((n) => n.id === 'ext_social_login' && n.label);
    const nodes = flatten(ext);
    expect(tabItem).toBeTruthy();
    expect(byId(nodes, 'ext_social_login_content').props.id).toBe('ext_social_login');
    expect(ext.data_sources[0].endpoint).toBe('/api/plugins/sirsoft-social_login/admin/users/{{route?.id}}/accounts');
    const unlink = byId(nodes, 'admin_user_social_unlink');
    expect(unlink.if).toBe('{{socialUserAccounts?.data?.abilities?.can_unlink === true}}');
    const call = flatten(unlink.actions).find((a) => a.handler === 'apiCall');
    expect(call.params.method).toBe('DELETE');
  });

  /**
   * @scenario surface=user_detail_tab, viewport=mobile|desktop
   * @effects mobile_cards_replace_table, card_unlink_same_call
   */
  it('휴대폰 폭에서는 연결 표 대신 세로 카드가 보이고, 카드의 강제 해제는 표 단추와 같은 조건과 요청이다', () => {
    const nodes = flatten(read(DETAIL_TAB));
    const tableWrap = byId(nodes, 'admin_user_social_table_wrap');
    const cards = byId(nodes, 'admin_user_social_cards');
    expect(cards).toBeTruthy();
    // 데스크톱·태블릿은 표, 모바일은 카드 — 둘 중 하나만 그린다
    expect(tableWrap.responsive?.mobile?.if).toBe('{{false}}');
    expect(cards.if).toBe('{{false}}');
    expect(cards.responsive?.mobile?.if).toBe(tableWrap.if);

    const tableUnlink = byId(nodes, 'admin_user_social_unlink');
    const cardUnlink = byId(nodes, 'admin_user_social_card_unlink');
    expect(cardUnlink.if).toBe(tableUnlink.if);
    const call = (n: any) => flatten(n.actions).find((a) => a.handler === 'apiCall');
    expect(call(cardUnlink).params.method).toBe('DELETE');
    expect(call(cardUnlink).target ?? call(cardUnlink).params.target).toBe(call(tableUnlink).target ?? call(tableUnlink).params.target);
    // 카드 안 항목은 표 열과 같은 값을 보인다
    const text = JSON.stringify(cards);
    for (const v of ['account.provider_user_id_prefix', 'account.email', 'account.linked_at', 'account.last_login_at']) {
      expect(text).toContain(v);
    }
  });

  /**
   * @scenario surface=user_list_filter, value=kakao|empty
   * @effects filter_row_permission, filter_element_replaced
   */
  it('가입경로 필터는 연결 열람 권한일 때만 보이고, 같은 키의 원소를 바꿔 끼운다', () => {
    const ext = read(LIST_FILTER);
    expect(ext.extension_point).toBe('admin_user_list_filters');
    const nodes = flatten(ext);
    expect(JSON.stringify(byId(nodes, 'ext_social_login_provider_filter_row').permissions)).toContain('sirsoft-social_login.accounts.view');

    const set = flatten(byId(nodes, 'social_login_provider_filter').actions).find((a) => a.handler === 'setState');
    const current = ['sirsoft-ecommerce.mileage_min=1', 'sirsoft-social_login.provider=naver'];
    expect(evaluate(set.params.userFilterExtraQuery, { _local: { userFilterExtraQuery: current }, $event: { target: { value: 'kakao' } } }))
      .toEqual(['sirsoft-ecommerce.mileage_min=1', 'sirsoft-social_login.provider=kakao']);
    expect(evaluate(set.params.userFilterExtraQuery, { _local: { userFilterExtraQuery: current }, $event: { target: { value: '' } } }))
      .toEqual(['sirsoft-ecommerce.mileage_min=1']);
  });

  /**
   * @scenario surface=user_list_column
   * @effects column_appended_hidden_by_default
   */
  it('「소셜 연결」 열은 표와 열 선택기 끝에 덧붙고 기본 열에는 없다', () => {
    const ext = read(LIST_COLUMN);
    expect(ext.target_layout).toBe('admin_user_list');
    const [grid, selector] = ext.injections;
    expect(grid.target_id).toBe('users_data_grid');
    expect(grid.props.columns._append[0].field).toBe('social_providers');
    expect(selector.target_id).toBe('column_selector');
    expect(selector.props.columns._append[0].field).toBe('social_providers');
    expect(JSON.stringify(selector.props)).not.toContain('defaultColumns');
  });

  /**
   * 셀의 제공자 표시는 장식(aria-hidden)이고 옆에 이름 글이 없다 — 이름을 마우스 설명(title)과 화면 낭독용 글로 함께 둔다.
   * 모의 카카오와 카카오처럼 같은 모양의 표시도 이름으로 가려진다.
   *
   * @scenario surface=user_list_column
   * @effects column_cell_names_providers
   */
  it('「소셜 연결」 셀은 제공자 이름을 title 과 화면 낭독용 글로 함께 둔다', () => {
    const cell = read(LIST_COLUMN).injections[0].props.columns._append[0].cellChildren[0];
    const row = { social_providers: [{ key: 'kakao', brand: 'kakao', label: '카카오' }, { key: 'mock_kakao', brand: 'kakao', label: '모의 kakao' }] };
    expect(evaluate(cell.props.title, { row })).toBe('카카오, 모의 kakao');
    const names = cell.children.find((c: any) => c.name === 'Span');
    expect(names.props.className).toBe('sr-only');
    expect(evaluate(names.text, { row })).toBe('카카오, 모의 kakao');
  });
});

describe('렌더 가능성', () => {
  /**
   * @scenario surface=components
   * @effects admin_nodes_exist_in_admin_template
   */
  it('관리자 화면 노드는 관리자 템플릿에 있는 컴포넌트(또는 이 플러그인 컴포넌트)만 쓴다 — 없는 이름은 조용히 사라진다', () => {
    const registry = JSON.parse(fs.readFileSync(path.resolve(ROOT, '../../../templates/_bundled/sirsoft-admin_basic/components.json'), 'utf8'));
    const known = new Set<string>();
    const walk = (o: any): void => {
      if (Array.isArray(o)) o.forEach(walk);
      else if (o && typeof o === 'object') {
        if (typeof o.name === 'string' && typeof o.path === 'string') known.add(o.name);
        Object.values(o).forEach(walk);
      }
    };
    walk(registry);
    const files = [...SETTINGS, DIAGNOSTICS, STATS, DETAIL_TAB, LIST_FILTER, LIST_COLUMN];
    const used = new Set(all(files).filter((n) => typeof n.name === 'string' && typeof n.type === 'string' && ['basic', 'composite', 'layout'].includes(n.type)).map((n) => n.name));
    const missing = [...used].filter((n) => !known.has(n) && !n.startsWith('sirsoft-social_login.'));
    expect(missing).toEqual([]);
  });

  /**
   * @scenario surface=data_sources
   * @effects data_source_condition_uses_if
   */
  it('데이터소스 조건은 `if` 키다 — `data-if` 로 구우면 엔진이 읽지 않아 조건이 사라진다', () => {
    const files = [...SETTINGS, DIAGNOSTICS, STATS, DETAIL_TAB, LIST_FILTER, LIST_COLUMN];
    const sources = files.flatMap((f) => read(f).data_sources ?? []);
    expect(sources.length).toBeGreaterThan(0);
    expect(sources.filter((s: any) => 'data-if' in s).map((s: any) => s.id)).toEqual([]);
    expect(read(DETAIL_TAB).data_sources[0].if).toBe('{{!!route?.id}}');
  });

  /**
   * @scenario surface=editor, change=other
   * @effects editor_save_disabled_until_changed, editor_policy_fields_present
   */
  it('편집 칸 [저장] 은 바꾸기 전에는 꺼져 있고(열 때 찍은 사본과 비교), 가입·이메일·웹훅 칸이 모두 있다', () => {
    const nodes = all(SETTINGS);
    const save = byId(nodes, 'editor_save_button');
    const disabled = save.props.disabled;
    const form = { key: 'kakao', client_id: 'a', label: { ko: '카카오' } };
    const base = { isSavingProvider: false };
    const scope = (local: any) => ({ _local: { ...base, ...local }, _computed: { canEditProviders: true } });
    expect(evaluate(disabled, scope({ providerForm: form, providerFormOrigin: { ...form } }))).toBe(true);
    expect(evaluate(disabled, scope({ providerForm: { ...form, client_id: 'b' }, providerFormOrigin: form }))).toBe(false);
    const open = JSON.stringify(byId(nodes, 'edit_provider_button'));
    expect(open).toContain('providerFormOrigin');
    const editor = JSON.stringify(read(SETTINGS[1]));
    for (const name of ['signup_allowed', 'email_missing_policy', 'email_trust', 'webhook_ip_allowlist_enabled', 'webhook_app_id']) {
      expect(editor).toContain(`"name":"${name}"`);
    }
  });

  /**
   * @scenario surface=settings, locale=site_locales
   * @effects provider_label_follows_site_locales
   */
  it('제공자 표시 이름은 사이트 언어를 따르는 다국어 입력이다 — ko·en 두 칸으로 고정하지 않는다', () => {
    const nodes = all(SETTINGS);
    const inputs = nodes.filter((n) => n.name === 'MultilingualInput' && n.props?.name === 'label');
    expect(inputs).toHaveLength(2);
    const text = JSON.stringify(SETTINGS.map(read));
    expect(text).not.toMatch(/label\.(ko|en)/);
    expect(text).not.toContain("ko: '', en: ''");
  });
});

describe('문구 키', () => {
  /**
   * @scenario locale=ko|en
   * @effects admin_lang_params_use_braces
   */
  it('관리자 문구의 매개변수는 프론트 표기 {{이름}} 이다 — :이름 은 치환되지 않고 그대로 보인다', () => {
    for (const locale of ['ko', 'en']) {
      const text = JSON.stringify(lang(locale).admin ?? {});
      expect(text.match(/(^|[^a-zA-Z0-9]):[a-z_]+/g) ?? [], locale).toEqual([]);
    }
  });

  /**
   * @scenario locale=ko|en
   * @effects admin_lang_keys_exist
   */
  it('관리자 화면의 플러그인 문구 키가 ko·en 모두에 있다', () => {
    const files = [...SETTINGS, DIAGNOSTICS, STATS, DETAIL_TAB, LIST_FILTER, LIST_COLUMN];
    const keys = [...new Set(files.flatMap((f) => pluginKeys(read(f))))];
    const dynamic = [
      ...['builtin', 'custom', 'extension'].map((t) => `admin.providers.types.${t}`),
      ...['kakao', 'naver', 'google', 'facebook', 'oidc'].flatMap((d) => [`admin.providers.fields.client_id_${d}`, `admin.providers.fields.client_secret_${d}`]),
      ...['credentials_ok', 'invalid_client', 'redirect_mismatch', 'unreachable', 'unexpected', 'credentials_missing', 'credentials_unreadable', 'discovery_failed', 'discovery_incomplete', 'jwks_unreachable'].map((r) => `admin.providers.test_results.${r}`),
      ...['received', 'processed', 'ignored', 'failed'].map((s) => `admin.diagnostics.statuses.${s}`),
      ...['login', 'link', 'test', 'reauth'].map((i) => `admin.diagnostics.intents.${i}`),
    ];
    for (const locale of ['ko', 'en']) {
      const dict = lang(locale);
      const missing = [...keys, ...dynamic].filter((k) => !k.endsWith('_') && typeof dig(dict, k) !== 'string' && typeof dig(dict, k) !== 'object');
      expect(missing, locale).toEqual([]);
    }
  });
});
