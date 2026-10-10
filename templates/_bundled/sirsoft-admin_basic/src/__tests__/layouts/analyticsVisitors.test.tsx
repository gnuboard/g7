/**
 * @file analyticsVisitors.test.tsx
 * @description 방문자 목록·상세 화면과 정보주체 삭제 창의 런타임 계약 (이슈 #682 S3)
 *
 * 잠그는 것: 두 화면이 열람 권한(`core.analytics.privacy`)을 요구하는 것, 상세가 라우트 키로 조회하고 404 를 에러 화면으로
 * 돌리는 것, 익명 모드 안내, 삭제가 체크 게이트 창을 거치고 방문자 키를 싣는 것, 삭제 뒤 목록으로 돌아가는 것.
 */

import { describe, it, expect } from 'vitest';
import { loadLayout, dataSource, nodesNamed, nodeById, actionsWithHandler, stringify, loadLang, loadJaPack, langValue, collectObjects } from './analyticsLayoutTestUtils';

const list = loadLayout('admin_analytics_visitors.json');
const detail = loadLayout('admin_analytics_visitor_detail.json');
const modal = loadLayout('partials/admin_analytics/_modal_subject_delete.json');

describe('방문자 목록', () => {
  // @scenario screen=visitors, state=normal, viewport=1440, locale=ko
  // @effects visitor_screens_require_privacy_permission, visitor_list_filters_via_query
  it('열람 권한을 요구하고 검색·유형·쪽이 질의 이동이며 합산 제외 토글이 없다', () => {
    expect(list.permissions).toEqual(['core.analytics.privacy']);
    const ds = dataSource(list, 'analytics_visitors');
    expect(ds?.endpoint).toBe('/api/admin/analytics/visitors');
    expect(ds?.params).toMatchObject({ q: '{{query.q}}', type: '{{query.type}}', page: '{{query.page || 1}}' });
    expect(stringify(ds?.params)).not.toContain('exclude');
    expect(list.initLocal?.showExclude).toBe(false);

    const navs = actionsWithHandler(list, 'navigate');
    expect(navs.some((n) => typeof n.params.query?.q === 'string' && n.params.query.page === 1)).toBe(true);
    expect(navs.some((n) => typeof n.params.query?.type === 'string')).toBe(true);
    expect(navs.some((n) => n.params.path === '/admin/analytics/visitors/{{$args[1].visitor_key}}' && n.params.mergeQuery === true)).toBe(true);
  });

  // @scenario screen=visitors, state=anonymous, viewport=1440, locale=ko
  // @effects anonymous_mode_notice_is_shown
  it('익명 모드면 하루 단위로만 묶인다는 안내를 띄운다', () => {
    const alert = nodeById(list, 'anonymous_mode_alert');
    expect(alert?.if).toContain("mode === 'anonymous'");
    expect(stringify(alert)).toContain('mode_notice');
  });
});

describe('방문자 상세', () => {
  // @scenario screen=visitor_detail, state=forbidden, viewport=1440, locale=ko
  // @effects visitor_detail_reads_route_key, visitor_screens_require_privacy_permission
  it('라우트 키로 조회하고 403·404 모두 에러 화면이다', () => {
    expect(detail.permissions).toEqual(['core.analytics.privacy']);
    const ds = dataSource(detail, 'analytics_visitor');
    expect(ds?.endpoint).toBe('/api/admin/analytics/visitors/{{route?.key}}');
    const codes = (ds?.errorHandling?.onCode ?? ds?.errorHandling?.on_code ?? []).map((c: any) => String(c.code));
    expect(stringify(ds?.errorHandling)).toContain('"403"');
    expect(stringify(ds?.errorHandling)).toContain('"404"');
    expect(codes.length === 0 || codes.includes('404')).toBe(true);
  });

  // @scenario screen=visitor_detail, state=normal, viewport=390, locale=en
  // @effects visitor_detail_csv_and_delete_use_the_key
  it('CSV 는 방문자 키 경로로 받고 삭제는 키를 싣고 창을 연다', () => {
    expect(detail.computed?.csvUrl).toContain("'/api/admin/analytics/visitors/' + (route?.key ?? '') + '?format=csv'");
    const del = nodeById(detail, 'visitor_delete_button');
    const seq = actionsWithHandler(del, 'sequence')[0];
    const [setState, open] = seq.actions;
    expect(setState.handler).toBe('setState');
    expect(setState.params.analyticsSubjectAfter).toBe('visitors');
    expect(setState.params.analyticsSubject?.visitor_key).toBe("{{route?.key ?? ''}}");
    expect(open).toMatchObject({ handler: 'openModal', target: 'analytics_subject_delete_modal' });
    expect(stringify(detail.modals)).toContain('partials/admin_analytics/_modal_subject_delete.json');
  });

  // @scenario screen=visitor_detail, state=normal, viewport=1440, locale=ko
  // @effects visitor_timeline_shows_action_types
  it('방문 아코디언은 방문 id 로 열고 타임라인 배지 유형이 다섯 종 이상이다', () => {
    expect(stringify(detail)).toContain('openVisits');
    for (const t of ['pageview', 'download', 'outlink', 'site_search', 'goal']) {
      expect(stringify(detail), t).toContain(`'${t}'`);
    }
  });
});

describe('정보주체 삭제 창', () => {
  // @scenario screen=visitor_detail, state=normal, viewport=1440, locale=ko
  // @effects subject_delete_modal_is_gated_by_checkbox
  it('체크 없이는 버튼이 잠기고 DELETE 는 confirm 과 기준 셋을 싣는다', () => {
    const button = nodeById(modal, 'subject_delete_button');
    expect(button?.props?.disabled).toContain('analyticsSubjectConfirm !== true');
    const call = actionsWithHandler(modal, 'apiCall')[0];
    expect(call.target).toBe('/api/admin/analytics/privacy/subjects');
    expect(call.params.method).toBe('DELETE');
    expect(call.params.body).toMatchObject({ confirm: true });
    expect(Object.keys(call.params.body)).toEqual(expect.arrayContaining(['ip', 'user_id', 'visitor_key', 'from', 'to']));
  });

  // @scenario screen=visitor_detail, state=normal, viewport=1440, locale=ko
  // @effects subject_delete_returns_to_list
  it('성공하면 부른 쪽에 따라 목록으로 가거나 검색 결과를 다시 받는다', () => {
    const call = actionsWithHandler(modal, 'apiCall')[0];
    const success = stringify(call.onSuccess);
    expect(success).toContain('"handler":"navigate"');
    expect(success).toContain("analyticsSubjectAfter === 'visitors'");
    expect(success).toContain('"dataSourceId":"analytics_subjects"');
    expect(nodesNamed(modal, 'Modal')[0]?.id).toBe('analytics_subject_delete_modal');
  });

  // @scenario screen=visitor_detail, state=normal, viewport=1440, locale=ko
  // @effects deferred_translation_needs_leading_text
  it('반복 안의 번역 토큰은 글자의 전부가 아니다 — 전부이면 키가 그대로 보인다', () => {
    // `$t:defer:` 가 글자의 전부이면 반복 서브트리에서 해소되지 않아 화면에 키가 남는다
    // (브라우저 실측 M12 — 방문 요약 11칸이 `$t:defer:…|pages=1|minutes=` 로 노출).
    // 앞에 고정 글자가 하나라도 있으면 값까지 채워 해소된다.
    const bare: string[] = [];
    const walk = (n: any): void => {
      if (!n || typeof n !== 'object') return;
      if (Array.isArray(n)) { n.forEach(walk); return; }
      if (typeof n.text === 'string' && /^\$t:defer:[a-zA-Z0-9._]+\|/.test(n.text)) bare.push(n.text);
      // prop 자리(`props.text`)도 본다 — 「다른 경로라 정상 해소된다」던 옛 전제는 브라우저 실측이 뒤집었다
      // (전환 배지 `props.text` 가 키를 그대로 노출, #682 S3 라운드 8). 모든 값 자리의 전수 가드는
      // `analyticsStateBranches.test.ts` 가 통계 화면 전체로 잰다.
      for (const v of Object.values(n)) walk(v);
    };
    walk(detail);
    expect(stringify(detail)).toContain('$t:defer:');
    expect(bare, '토큰이 글자의 전부인 텍스트 노드').toEqual([]);
  });
  // @scenario screen=visitor_detail, state=normal, viewport=1440, locale=ko
  // @effects timeline_conversions_show_the_declared_goal_name
  it('타임라인 전환은 서버가 푼 목표 이름(goal_label)을 먼저 읽고 전환 속성 label 은 이름 자리에 쓰지 않는다', () => {
    // `conv.label` 은 목표 이름이 아니라 전환 속성(주문번호 등)이다 — 이름 자리에 쓰면 코어 목표가
    // `core.user_registered` 원키로 찍힌다. 모집단은 원키로 떨어지는 이름 노드 전부(트리에서 판다).
    const nameNodes = collectObjects(detail).filter(
      (o) => typeof o.text === 'string' && o.text.includes('conv.goal_key'),
    );
    expect(nameNodes.length, '전환 이름 노드').toBeGreaterThan(0);
    for (const node of nameNodes) {
      expect(node.text.startsWith('{{conv.goal_label ??'), node.text).toBe(true);
    }
    expect(stringify(detail)).not.toContain('conv.label ?? conv.goal_key');
  });

  // @scenario screen=visitor_detail, state=normal, viewport=1440, locale=ko
  // @effects timeline_search_row_shows_result_count
  it('타임라인의 검색 행이 결과 건수를 붙이고 세 로케일에 그 문구가 있다', () => {
    // 모집단은 상세 화면 트리에서 판다 — 「검색 행」이 사라지면 이 검사 자체가 red 다.
    // 반복 안이라 id 가 index_var 로 동적화돼 있다(audit `layout-iteration-static-id`).
    // 접두로 찾되, 접두가 바뀌면 red 가 되도록 모집단을 트리에서 판다.
    const node = collectObjects(detail).find(
      (o) => typeof o.id === 'string' && o.id.startsWith('timeline_search_result_count'),
    );
    expect(node, '타임라인 검색 결과 건수 노드').toBeTruthy();
    if (!node) throw new Error('unreachable');
    // 검색 액션에서만 뜨고, 0 건도 떠야 한다(빈손 검색을 이 화면에서 가려내는 값이다).
    expect(node.if).toContain("action_type === 'site_search'");
    expect(node.if).toContain('action.search_result_count !== null');
    expect(node.if).not.toContain('search_result_count > 0');
    expect(stringify(node)).toContain('{{action.search_result_count}}');

    const key = 'admin.analytics.visitor_detail.timeline.search_result_count';
    const packs: [string, any][] = [
      ['ko', loadLang('ko', 'admin')],
      ['en', loadLang('en', 'admin')],
      ['ja', loadJaPack('admin')],
    ];
    expect(packs.length, '검사한 로케일 수').toBeGreaterThan(2);
    const missing = packs.filter(([, tree]) => {
      const v = langValue(tree, key);
      return typeof v !== 'string' || v === '' || !v.includes('{{count}}');
    }).map(([loc]) => loc);
    expect(missing, '결과 건수 문구가 없는 로케일').toEqual([]);
  });
});
