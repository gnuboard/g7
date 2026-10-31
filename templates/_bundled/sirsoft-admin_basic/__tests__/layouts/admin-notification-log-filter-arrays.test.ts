/**
 * @file admin-notification-log-filter-arrays.test.ts
 * @description 알림 발송 로그 다중 선택 필터가 고른 값 전부를 서버로 보낸다 (#17)
 *
 * 화면은 상태·확장 유형을 여러 개 고를 수 있었지만 검색 액션이 첫 값(`[0]`)만 주소에 실었다.
 * 이제 배열 전체를 싣고(`status[]=…`), 데이터소스는 그 배열(과 옛 단수 주소)을 서버로 넘기며,
 * 새로 고침하면 주소에서 같은 선택을 되살린다. 행의 수신자·발신자 링크가 싣는
 * recipient_user_id·sender_user_id 도 데이터소스가 서버로 넘긴다.
 *
 * 시나리오 매니페스트: tests/scenarios/notification-log-search-filter.yaml
 */
import { describe, it, expect } from 'vitest';
import { dataBindingEngine } from '@core/template-engine/DataBindingEngine';
import { asArray } from '@core/template-engine/__tests__/utils/layoutShapeUtils';
import notificationLogList from '../../layouts/admin_notification_log_list.json';

type Json = Record<string, any>;

const layout = notificationLogList as Json;

/** `{{식}}` 바인딩을 주어진 컨텍스트로 평가한다. */
function evalBinding(binding: string, context: Json): unknown {
  return dataBindingEngine.evaluateExpression(binding.trim().replace(/^\{\{|\}\}$/g, ''), context);
}

describe('알림 발송 로그 다중 선택 필터', () => {
  const query = layout.named_actions.searchNotificationLogs.params.query as Json;
  const params = asArray<Json>(layout.data_sources).find((d) => d.id === 'notificationLogs')!.params as Json;
  const filter = asArray<Json>(layout.init_actions).find((a) => a.params?._local?.filter)!.params._local.filter as Json;

  /**
   * @scenario case=layout_sends_all_values
   * @effects multi_value_filter_applied
   */
  it('검색 액션은 고른 상태·확장 유형 전부를 싣고, 비었으면 빈 배열로 지운다', () => {
    const picked = { _local: { filter: { statuses: ['sent', 'failed'], extensionTypes: ['core', 'module'] } } };
    expect(evalBinding(query.status, picked)).toEqual(['sent', 'failed']);
    expect(evalBinding(query.extension_type, picked)).toEqual(['core', 'module']);

    const none = { _local: { filter: {} } };
    expect(evalBinding(query.status, none)).toEqual([]);
    expect(evalBinding(query.extension_type, none)).toEqual([]);
  });

  /**
   * @scenario case=layout_sends_all_values
   * @effects multi_value_filter_applied
   */
  it('데이터소스는 배열 주소와 옛 단수 주소를 모두 서버로 넘긴다', () => {
    expect(evalBinding(params.status, { query: { 'status[]': ['sent', 'failed'] } })).toEqual(['sent', 'failed']);
    expect(evalBinding(params.status, { query: { status: 'failed' } })).toBe('failed');
    expect(evalBinding(params.extension_type, { query: { 'extension_type[]': ['plugin'] } })).toEqual(['plugin']);
    expect(params.recipient_user_id).toBe('{{query.recipient_user_id}}');
    expect(params.sender_user_id).toBe('{{query.sender_user_id}}');
  });

  /**
   * @scenario case=layout_sends_all_values
   * @effects multi_value_filter_applied
   */
  it('새로 고침하면 주소에서 다중 선택을 되살린다', () => {
    expect(evalBinding(filter.statuses, { query: { 'status[]': ['sent', 'failed'] } })).toEqual(['sent', 'failed']);
    expect(evalBinding(filter.statuses, { query: { 'status[]': 'sent' } })).toEqual(['sent']);
    expect(evalBinding(filter.statuses, { query: { status: 'failed' } })).toEqual(['failed']);
    expect(evalBinding(filter.extensionTypes, { query: {} })).toEqual([]);
  });
});
