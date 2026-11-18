/**
 * @file admin-extension-check-updates-failures.test.tsx
 * @description [업데이트 확인]이 GitHub 최신 판 조회 실패를 「모두 최신」으로 알리지 않는다
 *
 * 모듈·플러그인·템플릿 일괄 확인 응답은 GitHub 조회에 실패한 확장을 `failures`(식별자·사유)로 돌려준다. 화면은
 * `updated_count` 만 읽어, 토큰이 틀려 GitHub 가 401 을 돌려준 실측(실패 7건)에서도 성공 색 「모든 플러그인이 최신
 * 상태입니다.」 하나만 띄웠다. 언어팩 확인 응답은 팩별 결과(`details[].error`)에 같은 사유를 싣는다.
 *
 * 실패가 있으면 경고 토스트에 개수·식별자·첫 사유를 싣고, 「모두 최신」 문구는 실패가 없을 때만 띄운다.
 * 새 판을 찾은 경우의 성공 토스트는 실패와 함께 남긴다.
 */

import { describe, it, expect } from 'vitest';
import moduleList from '../../layouts/admin_module_list.json';
import pluginList from '../../layouts/admin_plugin_list.json';
import templateList from '../../layouts/admin_template_list.json';
import languagePackContent from '../../layouts/partials/admin_language_pack_list/_content.json';

type Json = Record<string, any>;

/** 업데이트 확인 apiCall 의 onSuccess 액션 목록을 모두 찾는다(언어팩은 넓은·좁은 화면 단추 두 벌). */
function checkUpdatesSuccessLists(tree: unknown): Json[][] {
  const found: Json[][] = [];
  const walk = (n: unknown): void => {
    if (Array.isArray(n)) return n.forEach(walk);
    if (n && typeof n === 'object') {
      const obj = n as Json;
      if (obj.handler === 'apiCall' && typeof obj.target === 'string' && /\/check-updates$/.test(obj.target) && Array.isArray(obj.onSuccess)) {
        found.push(obj.onSuccess);
        return;
      }
      Object.values(obj).forEach(walk);
    }
  };
  walk(tree);
  expect(found.length, '업데이트 확인 apiCall 의 onSuccess 가 없습니다.').toBeGreaterThan(0);
  return found;
}

/** 바인딩 식을 평가한다(식이 없으면 그대로의 문자열). */
function evalBinding(expr: string, response: Json): unknown {
  const m = /^\{\{([\s\S]*)\}\}$/.exec(expr.trim());
  // eslint-disable-next-line no-new-func
  return new Function('response', `return (${m ? m[1] : JSON.stringify(expr)});`)(response);
}

/** 토스트의 표시 조건을 평가한다 — `if` 가 없으면 늘 뜬다. */
function shows(toast: Json, response: Json): boolean {
  return toast.if === undefined ? true : Boolean(evalBinding(toast.if, response));
}

/** 메시지의 `|name={{...}}` 치환 값을 평가한다. */
function param(message: string, name: string, response: Json): unknown {
  const m = new RegExp(`\\|${name}=(\\{\\{[\\s\\S]*?\\}\\})`).exec(message);
  expect(m, `${name} 치환이 없습니다.`).not.toBeNull();
  return evalBinding(m![1], response);
}

describe('[업데이트 확인] — GitHub 조회 실패 알림', () => {
  const reason = 'GitHub 최신 판 조회에 실패했습니다 (HTTP 401).';

  /**
   * @scenario case=extension_bulk_failures
   * @effects check_updates_failures_surfaced, check_updates_up_to_date_only_without_failures
   */
  it.each([
    ['모듈', moduleList, 'modules'],
    ['플러그인', pluginList, 'plugins'],
    ['템플릿', templateList, 'templates'],
  ])('%s: failures 가 있으면 경고 토스트를 띄우고 「모두 최신」은 띄우지 않는다', (_label, tree, type) => {
    for (const onSuccess of checkUpdatesSuccessLists(tree)) {
      const toasts = onSuccess.filter((a) => a.handler === 'toast');
      const warning = toasts.filter((a) => a.params?.type === 'warning'
        && String(a.params?.message ?? '').includes('$t:extensions.alerts.check_updates_github_failed'));
      expect(warning.length, '조회 실패 경고 토스트가 없습니다.').toBe(1);
      const success = toasts.filter((a) => a.params?.type === 'success');
      expect(success.length).toBe(1);

      const failedOnly = { data: { updated_count: 0, checked_count: 15, details: [], failures: [
        { identifier: 'sirsoft-ckeditor5', reason },
        { identifier: 'sirsoft-pay_nhnkcp', reason },
      ] } };
      const clean = { data: { updated_count: 0, checked_count: 15, details: [], failures: [] } };
      const legacy = { data: { updated_count: 0, checked_count: 15, details: [] } };
      const foundAndFailed = { data: { updated_count: 2, checked_count: 15, details: [], failures: [{ identifier: 'sirsoft-ckeditor5', reason }] } };

      expect(shows(warning[0], failedOnly)).toBe(true);
      expect(shows(warning[0], clean)).toBe(false);
      expect(shows(warning[0], legacy), 'failures 가 없는 응답(옛 서버)').toBe(false);

      expect(shows(success[0], failedOnly), '조회 실패만 있는데 「모두 최신」을 띄웠다').toBe(false);
      expect(shows(success[0], clean)).toBe(true);
      expect(shows(success[0], legacy)).toBe(true);
      expect(shows(success[0], foundAndFailed), '새 판을 찾은 결과는 실패와 함께 알린다').toBe(true);
      expect(evalBinding(success[0].params.message, foundAndFailed)).toBe(`$t:admin.${type}.check_updates_success|count=2`);
      expect(evalBinding(success[0].params.message, clean)).toBe(`$t:admin.${type}.check_updates_no_updates`);

      const message = String(warning[0].params.message);
      expect(param(message, 'count', failedOnly)).toBe(2);
      expect(param(message, 'names', failedOnly)).toBe('sirsoft-ckeditor5, sirsoft-pay_nhnkcp');
      expect(param(message, 'reason', failedOnly)).toBe(reason);
    }
  });

  /**
   * @scenario case=language_pack_detail_errors
   * @effects check_updates_failures_surfaced, check_updates_up_to_date_only_without_failures
   */
  it('언어팩: details[].error 가 있으면 넓은·좁은 화면 단추 모두 경고 토스트를 띄우고 「모두 최신」은 띄우지 않는다', () => {
    const lists = checkUpdatesSuccessLists(languagePackContent);
    expect(lists.length, '넓은 화면·좁은 화면 단추 두 벌').toBe(2);

    for (const onSuccess of lists) {
      const toasts = onSuccess.filter((a) => a.handler === 'toast');
      const warning = toasts.filter((a) => a.params?.type === 'warning'
        && String(a.params?.message ?? '').includes('$t:admin.language_packs.messages.check_updates_github_failed'));
      expect(warning.length, '조회 실패 경고 토스트가 없습니다.').toBe(1);
      const success = toasts.filter((a) => a.params?.type === 'success');
      expect(success.length).toBe(1);

      const failedOnly = { data: { checked: 3, updates: 0, details: [
        { identifier: 'g7-core-ja', current: '1.0.0', latest: null, has_update: false, error: reason },
        { identifier: 'g7-module-x-ja', current: '1.0.0', latest: '1.0.0', has_update: false, error: null },
      ] } };
      const clean = { data: { checked: 1, updates: 0, details: [{ identifier: 'g7-core-ja', has_update: false, error: null }] } };
      const foundAndFailed = { data: { checked: 2, updates: 1, details: [
        { identifier: 'g7-core-ja', has_update: true, error: reason },
        { identifier: 'g7-module-x-ja', has_update: true, error: null },
      ] } };

      expect(shows(warning[0], failedOnly)).toBe(true);
      expect(shows(warning[0], clean)).toBe(false);
      expect(shows(warning[0], { data: {} })).toBe(false);

      expect(shows(success[0], failedOnly), '조회 실패만 있는데 「모두 최신」을 띄웠다').toBe(false);
      expect(shows(success[0], clean)).toBe(true);
      expect(shows(success[0], foundAndFailed)).toBe(true);

      const message = String(warning[0].params.message);
      expect(param(message, 'count', failedOnly)).toBe(1);
      expect(param(message, 'names', failedOnly)).toBe('g7-core-ja');
      expect(param(message, 'reason', failedOnly)).toBe(reason);
    }
  });
});
