/**
 * @file analyticsPrivacyRequests.test.tsx
 * @description 정보주체 요청 화면 런타임 계약 (이슈 #682 S3)
 *
 * 잠그는 것: 조건이 질의에 있을 때만 조회하는 것, 기준 셋 중 하나만 싣는 것, 내보내기가 POST 본문 blob 다운로드인 것,
 * 삭제가 공통 창을 검색 기준으로 여는 것, 익명 모드 안내.
 */

import { describe, it, expect } from 'vitest';
import { loadLayout, dataSource, nodeById, actionsWithHandler, stringify } from './analyticsLayoutTestUtils';

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
    expect(named?.params?.query?.value).toContain('_local.search?.value || undefined');
    expect(stringify(layout).match(/"actionRef":"searchSubjects"/g)?.length).toBeGreaterThanOrEqual(2);
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

  // @scenario screen=privacy_requests, state=anonymous, viewport=390, locale=en
  // @effects anonymous_mode_notice_is_shown
  it('익명 모드 안내와 서버 notice, 빈 결과 상태가 있다', () => {
    expect(nodeById(layout, 'anonymous_mode_alert')?.if).toContain("=== 'anonymous'");
    expect(nodeById(layout, 'subject_notice')?.if).toContain('analytics_subjects?.data?.notice');
    expect(nodeById(layout, 'subjects_empty')?.if).toContain('length === 0');
    expect(nodeById(layout, 'results_card')?.if).toBe('{{!!query.value}}');
  });
});
