/**
 * @file analyticsSettings.test.tsx
 * @description 방문자 통계 설정 화면과 소급·삭제 창, 알림 정의 카드의 런타임 계약 (이슈 #682 S3)
 *
 * 잠그는 것: 탭 5개가 `query.tab` 출처인 것, 폼이 자동 바인딩(`dataKey=form` + 변경 추적)인 것, 저장 본문이 받아 온 값과
 * 달라진 키만 담는 것(탭을 가리지 않는다), 더 엄격한 저장 뒤 소급 창이 열리는 것, 소급·삭제 창의 체크 게이트, 정의 상태 카드가 정의 API 를 따로 읽고
 * 「설정 켬 + 정의 꺼짐」을 경고하는 것(§6.3-17), opt-out 이 서버 쿠키 경로인 것.
 */

import fs from 'fs';
import path from 'path';
import { describe, it, expect } from 'vitest';
import { evaluateSafeExpression } from '@core/template-engine/SafeExpressionEvaluator';
import { TEMPLATE_DIR, loadLayout, loadLang, loadJaPack, langValue, dataSource, nodesNamed, nodeById, actionsWithHandler, stringify, collectObjects } from './analyticsLayoutTestUtils';

const REPO_ROOT = path.resolve(TEMPLATE_DIR, '../../..');

const layout = loadLayout('admin_analytics_settings.json');
const retro = loadLayout('partials/admin_analytics/_modal_retro_anonymize.json');
const purge = loadLayout('partials/admin_analytics/_modal_purge_all.json');
const defs = loadLayout('partials/admin_analytics/_partial_notification_definitions.json');

/**
 * 탭별 설정 키 — 어느 탭에 무엇을 두는지는 화면 설계라 여기 적는다. 다만 **모집단**은 서버 기본값
 * (`config/settings/defaults.json` 의 `defaults.analytics`)과 아래 첫 케이스가 양방향으로 대조한다 —
 * 서버가 키를 더하고 이 표를 안 늘리면 red 다(#682 S3 R7-D3·R11-D3·R12-D2).
 */
const TAB_KEYS: Record<string, string[]> = {
  collection: ['enabled', 'visit_timeout_minutes', 'max_actions_per_visit', 'excluded_url_patterns', 'site_search_enabled', 'track_downloads', 'track_outlinks', 'download_extensions', 'public_summary_enabled'],
  privacy: ['privacy_mode', 'respect_dnt', 'ip_mask_octets', 'geo_before_mask', 'anonymize_referrer', 'pseudonymize_user_id', 'visitor_cookie_days'],
  bots: ['bot_detector_enabled', 'bot_tracking_enabled', 'block_headless', 'exclude_prefetch', 'excluded_ips', 'ip_allowlist', 'excluded_user_agents', 'block_cloud_ip_ranges', 'country_filter_mode', 'country_filter_codes'],
  retention: ['retention_days', 'hourly_retention_days', 'bot_hits_retention_days', 'failure_log_retention_days', 'ledger_warning_actions', 'ledger_warning_visits', 'archive_chunk_size', 'archive_on_view_enabled', 'archive_on_view_max_visits', 'duration_bucket_bounds', 'pages_bucket_bounds', 'realtime_poll_seconds', 'realtime_window_minutes', 'top_n_overview', 'top_n_dashboard', 'top_n_detail'],
  reports: ['report_weekly_enabled', 'report_monthly_enabled'],
};

/**
 * 서버 기본값에는 있지만 폼 입력이 아닌 키와 그 이유. 사유 없이 빼면 새 키가 조용히 무가드가 된다.
 */
const DISPLAY_ONLY: Record<string, string> = {
  // 클라우드 IP 대역을 마지막으로 받아 온 시각 — 서버의 갱신 작업이 찍고, 화면은 봇 탭 힌트로 보여 줄 뿐
  // 운영자가 고치는 값이 아니다(입력 이름이 없고 저장 본문에도 싣지 않는다).
  cloud_ip_ranges_updated_at: 'server-stamped timestamp, shown as a hint only',
};

describe('설정 화면 — 탭·폼·저장', () => {
  // @scenario screen=settings, state=normal, viewport=1440, locale=ko
  // @effects settings_keys_match_server_defaults
  it('탭별 키 모집단이 서버 기본값(defaults.analytics)과 같고 저장 본문의 묶음도 같은 표다 — 표시 전용 키만 사유와 함께 뺀다', () => {
    const defaults = JSON.parse(fs.readFileSync(path.join(REPO_ROOT, 'config/settings/defaults.json'), 'utf8'));
    const serverKeys = Object.keys(defaults?.defaults?.analytics ?? {});
    // 모집단이 비면 아래 차집합은 빈 배열끼리라 조용히 통과한다.
    expect(serverKeys.length, '서버 기본값의 통계 설정 키').toBeGreaterThan(30);

    const tabKeys = Object.values(TAB_KEYS).flat();
    expect(tabKeys.length, '탭별 키').toBeGreaterThan(30);
    expect(tabKeys.filter((k, i) => tabKeys.indexOf(k) !== i), '두 탭에 걸친 키').toEqual([]);

    // 표시 전용 예외는 실재하는 서버 키이고, 입력으로 바인딩되지 않으며, 화면이 실제로 읽는다.
    const names = stringify(layout).match(/"name":"settings\.[a-z_]+"/g) ?? [];
    for (const key of Object.keys(DISPLAY_ONLY)) {
      expect(serverKeys, key).toContain(key);
      expect(names, `${key} 는 입력이 아니다`).not.toContain(`"name":"settings.${key}"`);
      expect(stringify(layout), `${key} 를 읽는 자리`).toContain(`settings?.${key}`);
    }

    expect(serverKeys.filter((k) => !tabKeys.includes(k) && !(k in DISPLAY_ONLY)), '폼 어느 탭에도 없는 서버 키').toEqual([]);
    expect(tabKeys.filter((k) => !serverKeys.includes(k)), '서버가 모르는 폼 키').toEqual([]);

    // 저장 본문이 쓰는 묶음 표(`const groups = {…}`)를 실제로 평가해 이 표와 대조한다 — 문자열 포함으로 재면
    // 키가 다른 탭으로 옮겨 가도 초록이다.
    const call = actionsWithHandler(nodeById(layout, 'save_button'), 'apiCall')[0];
    const literal = String(call?.params?.body ?? '').match(/const groups = (\{[^;]*\});/)?.[1];
    expect(literal, '저장 본문의 묶음 표').toBeTruthy();
    expect(evaluateSafeExpression(`(${literal})`, {})).toEqual(TAB_KEYS);
  });

  // @scenario screen=settings, state=normal, viewport=1440, locale=ko
  // @effects settings_tab_is_query_only
  it('update 권한을 요구하고 탭 5개는 query.tab 하나가 출처다', () => {
    expect(layout.permissions).toEqual(['core.analytics.update']);
    const tabs = nodesNamed(layout, 'TabNavigation')[0];
    expect(tabs.props.activeTabId).toBe("{{query.tab || 'collection'}}");
    expect(tabs.props.tabs.map((t: any) => t.id)).toEqual(['collection', 'privacy', 'bots', 'retention', 'reports']);
    const nav = actionsWithHandler(tabs, 'navigate')[0];
    // 탭 전환은 병합하지 않는다(목록 컨텍스트 왕복 규약) — 설정 화면의 질의는 탭 하나뿐이다.
    expect(nav.params).toMatchObject({ path: '/admin/analytics/settings', replace: true, query: { tab: '{{$args[0]}}' } });
    expect(nav.params.mergeQuery).toBeUndefined();
    for (const tab of Object.keys(TAB_KEYS)) {
      expect(nodeById(layout, `tab_${tab}`)?.if, tab).toContain(`=== '${tab}'`);
    }
  });

  // @scenario screen=settings, state=normal, viewport=1440, locale=ko
  // @effects settings_form_binds_every_key
  it('폼은 응답의 settings 를 초기값으로 자동 바인딩하고 변경을 추적한다', () => {
    expect(dataSource(layout, 'analytics_settings')?.initLocal).toBe('form');
    const root = nodeById(layout, 'analytics_settings_content');
    expect(root?.dataKey).toBe('form');
    expect(root?.trackChanges).toBe(true);
    const names = stringify(layout).match(/"name":"settings\.[a-z_]+"/g) ?? [];
    for (const keys of Object.values(TAB_KEYS)) {
      for (const k of keys) {
        if (k === 'privacy_mode') continue; // 라디오 카드는 set-state 로 쓴다
        expect(names, k).toContain(`"name":"settings.${k}"`);
      }
    }
    expect(stringify(layout)).toContain('form.settings.privacy_mode');
  });

  // @scenario screen=settings, state=loading, viewport=1440, locale=ko
  // @effects settings_form_waits_for_first_response
  it('첫 응답 전에는 탭 본문을 흐리게 막아 초기값이 덮어쓸 입력을 받지 않는다', () => {
    // 응답이 늦게 오면 initLocal 이 폼을 서버 값으로 덮어쓴다 — 그 전에 누른 모드 카드는 저장 버튼만
    // 켠 채 사라지고, 저장은 서버 값을 그대로 보낸다(브라우저 실측으로 확인한 경합).
    expect(dataSource(layout, 'analytics_settings')?.loading_strategy).toBe('progressive');
    const content = nodeById(layout, 'analytics_settings_tab_content');
    expect(content?.blur_until_loaded).toEqual({ enabled: true, data_sources: 'analytics_settings' });
    const footer = nodeById(layout, 'save_button');
    expect(footer?.props?.disabled).toBe('{{!_local.hasChanges || _local.isSaving}}');
  });

  // @scenario screen=settings, state=normal, viewport=1440, locale=ko
  // @effects settings_save_sends_changed_keys_across_tabs
  it('저장은 PUT 이고 본문은 탭을 가리지 않고 바뀐 키만 보낸다', () => {
    // 저장 버튼은 화면에 하나뿐이라 다섯 탭을 고쳐 두고 한 번 누른다. 지금 탭의 키만 보내면
    // 나머지 탭의 입력이 성공 토스트와 함께 말없이 사라진다(브라우저 실측 M6-T4).
    // 안 본 탭을 덮어쓰지 않으려던 본래 뜻은 「받아 온 값과 다른 키만」으로 그대로 지킨다.
    const save = nodeById(layout, 'save_button');
    expect(save?.props?.disabled).toContain('!_local.hasChanges');
    const call = actionsWithHandler(save, 'apiCall')[0];
    expect(call.target).toBe('/api/admin/analytics/settings');
    expect(call.params.method).toBe('PUT');
    const body = String(call.params.body);
    for (const [tab, keys] of Object.entries(TAB_KEYS)) {
      expect(body, tab).toContain(`${tab}: [`);
      for (const k of keys) expect(body, k).toContain(`'${k}'`);
    }
    // 다섯 묶음을 한 줄로 모아 훑는다 — 탭 하나로 자르지 않는다.
    expect(body).toContain('groups.collection');
    expect(body).toContain('groups.reports');
    // 비교 기준은 받아 온 설정이고, 배열 값도 같은 잣대로 본다.
    expect(body).toContain('analytics_settings?.data?.settings');
    expect(body).toContain('JSON.stringify');
    // 바뀐 키가 없으면(되돌린 입력 등) 지금 탭의 키로 물러선다.
    expect(body).toContain("const tab = query.tab || 'collection'");
  });

  // @scenario screen=settings, state=normal, viewport=1440, locale=ko
  // @effects privacy_mode_cards_expose_pressed_state
  it('개인정보 수준 카드 3장은 상호배타 라디오로 읽힌다 — 독립 토글 3개가 아니다', () => {
    // 계획서 §4.11 은 이 세 장을 `◉`/`○` 라디오 카드로 그렸다. `aria-pressed` 버튼이면 읽어 주는
    // 도구가 「선택 1/3」이 아니라 독립 누름 토글 셋으로 읽고 화살표 키 그룹 이동도 없다
    // (#682 S3 라운드 5, §6.5-12 접근성). 묶음은 radiogroup, 카드는 radio + aria-checked 다.
    const group = nodeById(layout, 'privacy_mode_cards');
    expect(group?.props?.role, '묶음').toBe('radiogroup');
    expect(group?.props?.['aria-label'], '묶음 이름').toBeTruthy();

    // 모집단을 손으로 적지 않는다 — 묶음 안의 카드 전량을 뽑아 대조한다.
    const cards = collectObjects(group).filter((o) => typeof o.id === 'string' && o.id.startsWith('mode_'));
    expect(cards.length, 'radiogroup 안의 모드 카드').toBe(3);

    for (const card of cards) {
      const mode = String(card.id).replace('mode_', '');
      expect(card.props?.role, card.id).toBe('radio');
      expect(card.props?.['aria-checked'], card.id).toContain(`=== '${mode}'`);
      expect(card.props?.['aria-pressed'], `${card.id} 은 누름 토글이 아니다`).toBeUndefined();
      expect(card.props?.className, card.id).toContain('admin-card-selected');
    }
  });

  // @scenario screen=settings, state=normal, viewport=1440, locale=ko
  // @effects stricter_privacy_opens_retro_modal, retro_and_purge_modals_are_gated_by_checkbox
  it('저장 성공 뒤 서버가 소급 가능이라 하면 소급 창을 열고 창은 체크 없이는 잠긴다', () => {
    const call = actionsWithHandler(nodeById(layout, 'save_button'), 'apiCall')[0];
    const success = stringify(call.onSuccess);
    expect(success).toContain('"target":"analytics_retro_anonymize_modal"');
    expect(success).toContain('retro_anonymize_available === true');
    expect(success).toContain('ledger_rows');

    const apply = nodeById(retro, 'retro_apply_button');
    expect(apply?.props?.disabled).toContain('analyticsRetroConfirm !== true');
    const anonymize = actionsWithHandler(retro, 'apiCall')[0];
    expect(anonymize.target).toBe('/api/admin/analytics/privacy/anonymize');
    expect(anonymize.params.method).toBe('POST');
    expect(anonymize.params.body.confirm).toBe(true);
    expect(stringify(anonymize.params.body)).toContain('mask_octets');
    expect(stringify(anonymize.params.body)).toContain('hash');
  });

  // @scenario screen=settings, state=normal, viewport=1440, locale=ko
  // @effects retro_and_purge_modals_are_gated_by_checkbox
  it('전체 삭제 창은 체크 없이는 잠기고 DELETE 뒤 저장소 현황을 다시 받는다', () => {
    const btn = nodeById(purge, 'purge_execute_button');
    expect(btn?.props?.disabled).toContain('analyticsPurgeConfirm !== true');
    const call = actionsWithHandler(purge, 'apiCall')[0];
    expect(call.target).toBe('/api/admin/analytics/data');
    expect(call.params.method).toBe('DELETE');
    expect(call.params.body).toEqual({ confirm: true });
    expect(stringify(call.onSuccess)).toContain('"dataSourceId":"analytics_storage"');
    expect(actionsWithHandler(nodeById(layout, 'purge_all_button'), 'openModal')[0]?.target).toBe('analytics_purge_all_modal');
  });
});

describe('리포트 탭 정의 상태 카드 (§6.3-17)', () => {
  // @scenario screen=settings, state=normal, viewport=1440, locale=ko
  // @effects report_definition_state_comes_from_definitions_api
  it('정의 상태는 알림 정의 API 를 따로 불러 읽고 설정 응답에 기대지 않는다', () => {
    const ds = dataSource(layout, 'analytics_notification_definitions');
    expect(ds?.endpoint).toBe('/api/admin/notification-definitions');
    expect(ds?.if).toContain("=== 'reports'");
    expect(stringify(dataSource(layout, 'analytics_settings'))).not.toContain('definition');
  });

  // @scenario screen=settings, state=normal, viewport=1440, locale=ko
  // @effects definition_inactive_is_warned
  it('리포트를 켰는데 정의가 꺼져 있으면 카드 머리에 경고를 띄우고 토글은 정의 API 로 켠다', () => {
    const alert = nodeById(defs, 'definition_state_alert');
    expect(alert?.if).toContain("report_weekly_enabled === true");
    expect(alert?.if).toContain('d.is_active !== true');

    // 정의를 고르는 열은 `type` 이다. `id` 는 테이블 자동증가 정수 PK 라
    // (`database/migrations/2026_04_08_000001_create_notification_definitions_table.php` 의
    // `$table->id()`) 문자열과 `===` 로 비교하면 **어떤 데이터에서도 참이 되지 않는다** —
    // 경고가 사문이 되어 「리포트는 켰는데 메일이 안 온다」의 이유를 영영 못 보여 준다(#682 라운드 5).
    // 모집단을 `config/core.php` 에서 파생해, 리포트 정의를 하나 더 만들면 이 검사가 그것도 요구한다.
    const php = fs.readFileSync(path.join(REPO_ROOT, 'config/core.php'), 'utf8');
    const reportTypes = [...php.matchAll(/^\s{8}'(analytics_(?:weekly|monthly)_report)' => \[$/gm)].map((m) => m[1]);
    expect(reportTypes.length, 'config/core.php 의 리포트 알림 정의 수').toBe(2);

    for (const type of reportTypes) {
      expect(alert?.if, `${type} 은 정수 PK 가 아니라 type 열로 골라야 한다`).toContain(`d.type === '${type}'`);
      expect(alert?.if, `${type} 을 d.id 와 비교하면 조건이 영원히 거짓이다`).not.toContain(`d.id === '${type}'`);
    }

    // `d.id` 자체는 죽은 값이 아니다 — 라우트 모델 바인딩에 쓰인다. 그 사실이 위 단언의 근거다.
    expect(stringify(defs), 'id 는 라우트 파라미터로만 쓴다').toContain('/{{def.id}}/toggle-active');
    const toggle = actionsWithHandler(defs, 'apiCall').find((c) => String(c.target).includes('toggle-active'));
    expect(toggle?.params?.method).toBe('PATCH');
    expect(stringify(toggle?.onSuccess)).toContain('analytics_notification_definitions');
    expect(stringify(defs)).toContain("d.hook_prefix === 'core.analytics'");
  });
});

describe('내 방문 제외(opt-out)', () => {
  // @scenario screen=settings, state=consent_fallback, viewport=1440, locale=ko
  // @effects opt_out_goes_through_server_cookie
  it('상태 배지는 GET 을 읽고 버튼은 상태에 따라 POST/DELETE 를 보낸 뒤 다시 읽는다', () => {
    expect(dataSource(layout, 'analytics_opt_out')?.endpoint).toBe('/api/admin/analytics/opt-out');
    const call = actionsWithHandler(nodeById(layout, 'opt_out_button'), 'apiCall')[0];
    expect(call.target).toBe('/api/admin/analytics/opt-out');
    expect(call.params.method).toContain("'DELETE' : 'POST'");
    expect(stringify(call.onSuccess)).toContain('"dataSourceId":"analytics_opt_out"');
  });

  // @scenario screen=settings, state=forbidden, viewport=1440, locale=ko
  // @effects every_analytics_screen_only_calls_its_own_permission
  it('교차 소스(진단)로 입력을 잠그는 자리는 전부 조회 권한을 먼저 본다 — 403 을 「꺼짐」으로 읽지 않는다', () => {
    // 설정 화면의 권한은 update 다. 조회 권한이 없으면 `analytics_status` 는 403 이고 fallback 이
    // `geoip.enabled:false` 로 굳는다 — 그 값으로 입력을 잠그면 GeoIP 가 켜진 사이트에서도
    // 국가 필터를 영영 못 고르고, 이유로 거짓 안내를 본다(#682 S3 라운드 3).
    const gated: string[] = [];
    const ungated: string[] = [];
    const walk = (value: any): void => {
      if (Array.isArray(value)) {
        value.forEach(walk);
        return;
      }
      if (!value || typeof value !== 'object') return;
      for (const [key, child] of Object.entries(value)) {
        // 모집단을 `geoip` 계열·특정 prop 이름으로 좁히면 같은 소스의 다른 자리가 새어 나간다 —
        // 라운드 4 까지 이 가드가 초록인 채로 `consent_source` 두 자리(computed 와 badge)가 가드
        // 없이 남아 「GDPR 연동 고장」을 거짓으로 띄우고 있었다(#682 S3 라운드 5). prop 이름을
        // 열거하지 않고 **교차 소스를 읽는 문자열 전부**를 모집단으로 둔다 — `computed` 는 이름이
        // 곧 키라 prop 목록으로는 영영 안 걸린다.
        if (typeof child === 'string' && child.includes('analytics_status?.data?.')) {
          (child.includes('canReadAnalytics') ? gated : ungated).push(`${key}: ${child}`);
        }
        walk(child);
      }
    };
    walk(layout);

    // 모집단이 비면 아래 비교는 빈 배열끼리라 조용히 통과한다.
    expect(gated.length + ungated.length, '교차 소스를 읽는 자리').toBeGreaterThanOrEqual(5);
    expect(ungated, '조회 권한을 먼저 보지 않고 교차 소스를 읽는 자리').toEqual([]);
  });

  // @scenario screen=settings, state=normal, viewport=1440, locale=ko
  // @effects opt_out_button_says_what_pressing_it_does
  it('「내 방문은 세지 않기」 버튼 글자가 호출 메서드와 같은 방향이다', () => {
    // 버튼 글자는 「지금 상태」가 아니라 「누르면 일어나는 일」이다. 배지가 상태를 말하고
    // 버튼이 행동을 말해야 하는데 삼항이 뒤집혀 있어, 세는 중인데 「제외 끄기」(en: Count me
    // again)가 보였다(#682 S3 라운드 8 실측: 누르니 opted_out false→true). 동작은 맞고 글자만
    // 반대였으므로, 글자 삼항과 메서드 삼항이 **같은 분기에 같은 뜻**을 두는지 잰다.
    const found: { method?: string; label?: string } = {};
    const walk = (node: unknown): void => {
      if (Array.isArray(node)) { node.forEach(walk); return; }
      if (!node || typeof node !== 'object') return;
      const rec = node as Record<string, unknown>;
      // 구운 JSON 에서 method 는 액션 노드가 아니라 그 `params` 아래에 앉는다.
      if (typeof rec.target === 'string' && rec.target.includes('/analytics/opt-out')) {
        const method = (rec.params as Record<string, unknown> | undefined)?.method;
        if (typeof method === 'string') found.method = method;
      }
      for (const [key, child] of Object.entries(rec)) {
        if (typeof child === 'string' && child.includes('admin.analytics.settings.opt_out.enable')) {
          found.label = `${key}: ${child}`;
        }
        walk(child);
      }
    };
    walk(layout);

    // 모집단이 비면 아래 단언이 전부 조용히 통과한다 — 두 자리가 실재하는지 먼저 잰다.
    expect(found.method, 'opt-out 호출의 method 삼항').toBeTruthy();
    expect(found.label, 'enable 문구를 고르는 자리').toBeTruthy();

    // 제외 중(`opted_out === true`)이면 거는 쪽(POST·enable)이 아니라 푸는 쪽이어야 한다.
    expect(found.method, '제외 중이면 DELETE 로 푼다').toMatch(/opted_out === true \? 'DELETE'/);
    expect(found.label, '제외 중에 「제외 켜기」를 내밀면 이미 켜진 것을 또 켜라는 말이 된다')
      .not.toMatch(/opted_out === true \? \$t\('admin\.analytics\.settings\.opt_out\.enable'\)/);
  });

  // @scenario screen=settings, state=normal, viewport=1440, locale=ko
  // @effects save_failure_header_tells_which_kind_of_failure
  it('저장 실패 경고의 머리글이 실패 종류로 갈린다 — 500 에 입력 탓을 하면 없는 오타를 뒤진다', () => {
    // `<fail>` 은 errors 에 `error.errors ?? error.message` 를 담는다. 422 는 필드별 객체라 「입력값을
    // 확인하세요」가 맞지만, 500·네트워크 실패는 문자열이고 고칠 입력이 없다. 토스트는 자동 소멸하므로
    // 화면에 남는 이 줄이 사유를 말해야 한다(S3 라운드 11 브라우저 실측).
    const box = nodeById(layout, 'validation_error');
    expect(box, '저장 실패 경고 상자').toBeTruthy();

    // 모집단을 상자에서 판다 — 머리글 개수를 손으로 적으면 하나로 합쳐져도 통과한다.
    const heads = nodesNamed(box, 'H3');
    expect(heads.length, '머리글이 하나뿐이면 두 실패를 같은 말로 덮는다').toBeGreaterThan(1);
    expect(heads.every((h) => typeof h.if === 'string' && h.if.includes('_local.errors')), '머리글마다 어느 실패인지 가리는 조건').toBe(true);

    const validation = heads.find((h) => /typeof _local\.errors === 'object'/.test(String(h.if)));
    const other = heads.find((h) => /typeof _local\.errors !== 'object'/.test(String(h.if)));
    expect(validation?.text, '필드별 오류에는 입력 확인 문구').toBe('$t:admin.analytics.settings.validation_error');
    expect(other?.text, '그 밖의 실패에는 서버가 준 사유').toContain('_local.errors');
    expect(other?.text, '사유가 비면 일반 저장 실패 문구로 떨어진다').toContain('admin.analytics.settings.save_error');
  });

  // @scenario screen=settings, state=normal, viewport=1440, locale=ko
  // @effects ip_mask_hint_explains_every_octet_level
  it('IP 마스킹 힌트가 옥텟 네 단계의 지역 품질을 전부 말한다 — 국가만 말하면 0·1 을 고를 근거가 없다', () => {
    // 계획 D18: 0=도시 정확 · 1(/24)=도시 대체로 유지 · 2(/16)=국가만 신뢰 · 3(/8)=국가도 부정확.
    // 옵션 라벨은 마스킹된 주소 예시만 보여 주므로, 운영자가 「마스킹 없음」과 「1옥텟」을 고를 때
    // 도시가 남는지는 이 힌트에서만 알 수 있다. 국가 얘기만 남기면 그 두 단계가 설명되지 않는다.
    const HINT_KEY = 'admin.analytics.settings.privacy.ip_mask_hint';
    // 로케일 모집단을 디렉터리에서 판다 — 손으로 적으면 로케일이 늘 때 조용히 지나간다.
    const localeDir = path.join(TEMPLATE_DIR, 'lang/partial');
    const locales = fs.readdirSync(localeDir).filter((d) => fs.statSync(path.join(localeDir, d)).isDirectory());
    expect(locales.length, '로케일 모집단이 비면 아래 단언이 전부 조용히 통과한다').toBeGreaterThan(1);

    // 「도시」를 가리키는 말은 로케일마다 다르다. 모집단에 있는 로케일이 여기 없으면 그 자체가 결함이다.
    const CITY_WORD: Record<string, RegExp> = { ko: /도시/, en: /\bcity\b/i, ja: /都市/ };
    const unmapped = locales.filter((l) => !(l in CITY_WORD));
    expect(unmapped, '새 로케일이 생기면 그 말도 함께 정해야 한다').toEqual([]);

    const trees: Record<string, any> = Object.fromEntries(locales.map((l) => [l, loadLang(l, 'admin')]));
    trees.ja = loadJaPack('admin');

    const silent = Object.entries(trees)
      .filter(([, tree]) => typeof langValue(tree, HINT_KEY) !== 'string')
      .map(([locale]) => locale);
    expect(silent, '힌트 자체가 없는 로케일').toEqual([]);

    const missing = Object.entries(trees)
      .filter(([locale, tree]) => !CITY_WORD[locale]?.test(String(langValue(tree, HINT_KEY) ?? '')))
      .map(([locale]) => locale);
    expect(missing, '마스킹 0·1 의 도시 품질이 힌트에 없는 로케일').toEqual([]);
  });
  // @scenario screen=settings, state=consent_fallback, viewport=1440, locale=ko
  // @effects settings_screen_warns_on_server_reported_anonymous_fallback
  it('서버가 폴백 사유를 실으면 저장된 모드가 무엇이든 알리고, 쿠키 차단은 그 사유로 말한다', () => {
    // 모집단은 화면 트리에서 판다 — 경고 상자가 사라지면 이 검사 자체가 red 다.
    const warn = nodeById(layout, 'consent_warning');
    expect(warn, '익명 폴백 경고 상자').toBeTruthy();

    const computed = layout.computed ?? {};
    const expr = String(computed.consentFallback ?? '');
    expect(expr, 'consentFallback 식').not.toBe('');

    // 계획 §4.10: 두 조건을 OR 로 본다. 라디오 선택을 AND 로 함께 요구하면 서버가
    // `consent_plugin_blocks_cookies` 를 full 모드에서만 만들기 때문에 이 화면만 도달 불가다.
    const reasonIdx = expr.indexOf('consentFallbackReason');
    const radioIdx = expr.indexOf("privacy_mode === 'consent'");
    expect(reasonIdx, '폴백 사유 항').toBeGreaterThan(-1);
    expect(radioIdx, '라디오 선택 항').toBeGreaterThan(-1);
    // 사유 항과 라디오 항 사이가 OR 여야 한다 — 그 사이에 `&&` 가 있으면 AND 로 묶인 것이다.
    const between = expr.slice(Math.min(reasonIdx, radioIdx), Math.max(reasonIdx, radioIdx));
    expect(between).toContain('||');
    expect(between.replace(/&amp;/g, '&')).not.toContain('&&');

    // 사유별 문구 분기 — 쿠키 차단에 「플러그인 비활성」이라고 하면 켜 둔 운영자가 헤맨다.
    const text = stringify(warn);
    expect(text).toContain('consent_plugin_blocks_cookies');
    expect(text).toContain('admin.analytics.alerts.consent_cookie_fallback');
    expect(text).toContain('admin.analytics.alerts.consent_fallback');

    // 두 문구가 세 로케일에 다 있어야 한다.
    const trees: Record<string, any> = { ko: loadLang('ko', 'admin'), en: loadLang('en', 'admin'), ja: loadJaPack('admin') };
    expect(Object.keys(trees).length, '검사한 로케일 수').toBeGreaterThan(2);
    const keys = ['admin.analytics.alerts.consent_fallback', 'admin.analytics.alerts.consent_cookie_fallback'];
    const missing: string[] = [];
    for (const [locale, tree] of Object.entries(trees)) {
      for (const key of keys) {
        const v = langValue(tree, key);
        if (typeof v !== 'string' || v === '') missing.push(`${locale}:${key}`);
      }
    }
    expect(missing, '폴백 문구가 없는 로케일·키').toEqual([]);
  });
});
