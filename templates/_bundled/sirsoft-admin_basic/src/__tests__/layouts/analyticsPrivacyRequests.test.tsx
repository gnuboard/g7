/**
 * @file analyticsPrivacyRequests.test.tsx
 * @description 정보주체 요청 화면 런타임 계약 (이슈 #682 S3)
 *
 * 잠그는 것: 조건이 질의에 있을 때만 조회하는 것, 기준 셋 중 하나만 싣는 것, 내보내기가 POST 본문 blob 다운로드인 것,
 * 삭제가 공통 창을 검색 기준으로 여는 것, 익명 모드 안내.
 */

import { describe, it, expect } from 'vitest';
import { loadLayout, dataSource, nodeById, actionsWithHandler, stringify, collectObjects } from './analyticsLayoutTestUtils';

const layout = loadLayout('admin_analytics_privacy_requests.json');

describe('정보주체 요청 화면', () => {
  // @scenario screen=privacy_requests, state=normal, viewport=1440, locale=ko
  // @effects subjects_are_queried_only_with_a_value, only_one_subject_criterion_is_sent
  it('열람 권한을 요구하고 값이 있을 때만 조회하며 기준 셋 중 하나에만 값을 싣는다', () => {
    expect(layout.permissions).toEqual(['core.analytics.privacy']);
    const ds = dataSource(layout, 'analytics_subjects');
    expect(ds?.endpoint).toBe('/api/admin/analytics/privacy/subjects');
    expect(ds?.if).toBe('{{!!query.value}}');
    expect(ds?.params?.ip).toContain("(query.subject || 'ip') === 'ip' ? query.value : undefined");
    expect(ds?.params?.user_id).toContain("query.subject === 'user_id' ? query.value : undefined");
    expect(ds?.params?.visitor_key).toContain("query.subject === 'visitor_key' ? query.value : undefined");
  });

  // @scenario screen=privacy_requests, state=normal, viewport=1440, locale=ko
  // @effects subject_search_moves_form_to_query
  it('검색은 명명 액션 하나가 폼 값을 질의로 옮긴다', () => {
    const named = layout.named_actions?.searchSubjects;
    expect(named?.handler).toBe('navigate');
    expect(named?.params).toMatchObject({ path: '/admin/analytics/privacy-requests', mergeQuery: true, replace: true });
    expect(named?.params?.query?.value).toBe("{{String(_local.search?.value ?? '').trim() || undefined}}");
    expect(stringify(layout).match(/"actionRef":"searchSubjects"/g)?.length).toBeGreaterThanOrEqual(2);
  });

  // @scenario screen=privacy_requests, state=empty, viewport=1440, locale=ko
  // @effects subject_search_is_locked_without_a_value
  it('값 칸이 비었거나 공백뿐이면 찾기 버튼을 잠그고 Enter 도 검색하지 않는다', () => {
    // 조건 없는 조회는 하지 않으므로 빈 값으로 눌러도 화면이 그대로였다 — 고장으로 읽혔다.
    const button = nodeById(layout, 'search_button');
    const disabled = new Function('_local', `return (${String(button?.props?.disabled).replace(/^\{\{|\}\}$/g, '')});`);
    expect(disabled({ search: { value: '' } })).toBe(true);
    expect(disabled({ search: { value: '   ' } })).toBe(true);
    expect(disabled({})).toBe(true);
    expect(disabled({ search: { value: '203.0.113.9' } })).toBe(false);

    const enter = (nodeById(layout, 'subject_value')?.actions ?? []).find((a: any) => a.type === 'keydown' && a.key === 'Enter');
    const guard = enter?.actions?.find((a: any) => a.handler === 'conditions');
    expect(guard?.conditions?.[0]?.then).toEqual({ actionRef: 'searchSubjects' });
    const pass = new Function('$event', `return (${String(guard?.conditions?.[0]?.if).replace(/^\{\{|\}\}$/g, '')});`);
    expect(pass({ target: { value: '' } })).toBe(false);
    expect(pass({ target: { value: '  ' } })).toBe(false);
    expect(pass({ target: { value: '7' } })).toBe(true);
  });

  // @scenario screen=privacy_requests, state=empty, viewport=1440, locale=ko
  // @effects subject_search_prompt_is_shown_before_query
  it('조회 전에는 결과 카드 대신 무엇을 넣으면 되는지 안내 카드를 띄운다', () => {
    const prompt = nodeById(layout, 'subjects_prompt');
    expect(prompt?.if).toBe('{{!query.value}}');
    const state = nodeById(prompt, 'subjects_prompt_state');
    expect(state?.name).toBe('EmptyState');
    expect(state?.props?.title).toBe('$t:admin.analytics.privacy_requests.prompt_title');
    expect(state?.props?.description).toBe('$t:admin.analytics.privacy_requests.prompt_description');
  });

  // @scenario screen=privacy_requests, state=normal, viewport=1440, locale=ko
  // @effects export_downloads_via_post_blob, subject_delete_opens_with_search_criteria
  it('내보내기는 POST 본문 blob 다운로드이고 삭제는 검색 기준을 담아 공통 창을 연다', () => {
    const exp = actionsWithHandler(nodeById(layout, 'export_button'), 'downloadAttachment')[0];
    expect(exp.params.url).toBe('/api/admin/analytics/privacy/subjects/export');
    expect(exp.params.method).toBe('POST');
    expect(Object.keys(exp.params.body)).toEqual(expect.arrayContaining(['ip', 'user_id', 'visitor_key', 'from', 'to']));

    const del = nodeById(layout, 'delete_button');
    const seq = actionsWithHandler(del, 'sequence')[0];
    expect(seq.actions[0].params.analyticsSubjectAfter).toBe('subjects');
    expect(seq.actions[1]).toMatchObject({ handler: 'openModal', target: 'analytics_subject_delete_modal' });
    expect(stringify(layout.modals)).toContain('_modal_subject_delete.json');
  });

  // @scenario screen=privacy_requests, state=empty, viewport=1440, locale=ko
  // @effects subject_actions_stay_available_without_core_visits
  it('검색 응답이 오면 코어 방문이 0건이어도 내보내기·삭제를 누를 수 있고, 응답 전에는 잠근다', () => {
    // 코어 원장이 보존 기간으로 비었어도 확장은 자기 표에 이 사람의 기록을 가질 수 있고 서버는 그때도
    // 확장의 정보주체 필터를 부른다 — 화면이 0건에서 잠그면 그 삭제·열람 요청을 처리할 길이 없다.
    const card = nodeById(layout, 'results_card');
    const buttons = collectObjects(card).filter((n) => n.name === 'Button' && typeof n.props?.disabled === 'string');
    expect(buttons.map((b) => b.id).sort()).toEqual(['delete_button', 'export_button']);

    const fallback = { data: dataSource(layout, 'analytics_subjects')?.fallback?.data };
    const empty = { data: { visits: [], notice: null, export_filename: 'analytics-subject-20260925.json' } };
    const found = { data: { visits: [{ visit_id: 1, actions_count: 2 }], export_filename: 'analytics-subject-20260925.json' } };

    for (const button of buttons) {
      const disabled = new Function('analytics_subjects', `return (${String(button.props.disabled).replace(/^\{\{|\}\}$/g, '')});`);
      expect(disabled(fallback), `${button.id} 응답 전`).toBe(true);
      expect(disabled(empty), `${button.id} 코어 0건`).toBe(false);
      expect(disabled(found), `${button.id} 방문 있음`).toBe(false);
    }
  });

  // @scenario screen=privacy_requests, state=anonymous, viewport=390, locale=en
  // @effects anonymous_mode_notice_is_shown
  it('익명 모드 안내와 서버 notice, 빈 결과 상태가 있다', () => {
    expect(nodeById(layout, 'anonymous_mode_alert')?.if).toContain("=== 'anonymous'");
    expect(nodeById(layout, 'subject_notice')?.if).toContain('analytics_subjects?.data?.notice');
    expect(nodeById(layout, 'subjects_empty')?.if).toContain('length === 0');
    expect(nodeById(layout, 'results_card')?.if).toBe('{{!!query.value}}');
  });
});
