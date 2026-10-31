/**
 * @file admin_template_list.banner.test.tsx
 * @description 템플릿 목록의 활성화 성공 뒤 자동 비활성화(호환성) 배너 재조회 테스트
 *
 * 템플릿을 켜도 같은 화면의 호환성 배너가 새로 고치기 전까지 남았다(#145 F8). 활성 확인 창·강제 활성 창의 성공 분기가
 * 배너 데이터 소스를 다시 받는지 실제 굽기 산출 레이아웃으로 확인한다.
 *
 * @scenario-manifest tests/scenarios/admin-extension-banner-refetch.yaml
 */

import { describe, it, expect } from 'vitest';
import templateActivateModal from '../../layouts/partials/admin_template_list/_modal_activate.json';
import templateForceActivateModal from '../../layouts/partials/admin_template_list/_modal_force_activate.json';

type Json = Record<string, any>;

/** 트리 전체를 돌며 조건에 맞는 노드를 모두 모은다. */
function collect(node: unknown, match: (n: Json) => boolean): Json[] {
  const out: Json[] = [];
  const walk = (n: unknown): void => {
    if (Array.isArray(n)) {
      n.forEach(walk);
      return;
    }
    if (n && typeof n === 'object') {
      const obj = n as Json;
      if (match(obj)) out.push(obj);
      Object.values(obj).forEach(walk);
    }
  };
  walk(node);
  return out;
}

/** 확장 활성화 API 호출(토글·강제 활성·활성 확인 창)만 고른다 — 언어팩 일괄 활성은 제외. */
function activationCalls(layout: unknown): Json[] {
  return collect(layout, (n) => n.handler === 'apiCall'
    && typeof n.target === 'string'
    && /(modules|plugins|templates)\/activate/.test(n.target));
}

/** 성공 분기 안에 호환성 배너 데이터 소스 재조회가 있는지. */
function refetchesBanner(call: Json): boolean {
  return collect(call.onSuccess ?? [], (n) => n.handler === 'refetchDataSource'
    && n.params?.dataSourceId === 'auto_deactivated_banner').length > 0;
}

describe('템플릿 목록 - 활성화 성공 뒤 호환성 배너 재조회 (#145 F8)', () => {
  /**
   * @scenario case=template_activate
   * @effects activation_success_refetches_compatibility_banner
   */
  it('템플릿 활성 확인 창 의 활성화 성공 분기가 호환성 배너를 다시 받는다', () => {
    const calls = activationCalls(templateActivateModal);
    expect(calls.length, '활성화 API 호출이 있어야 한다').toBeGreaterThan(0);
    calls.forEach((call) => expect(refetchesBanner(call), `${call.target} 성공 뒤 auto_deactivated_banner 재조회`).toBe(true));
  });

  /**
   * @scenario case=template_force_activate
   * @effects activation_success_refetches_compatibility_banner
   */
  it('템플릿 강제 활성 창 의 활성화 성공 분기가 호환성 배너를 다시 받는다', () => {
    const calls = activationCalls(templateForceActivateModal);
    expect(calls.length, '활성화 API 호출이 있어야 한다').toBeGreaterThan(0);
    calls.forEach((call) => expect(refetchesBanner(call), `${call.target} 성공 뒤 auto_deactivated_banner 재조회`).toBe(true));
  });
});
