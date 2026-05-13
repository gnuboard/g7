/**
 * @file admin-settings-identity-policy-list.test.tsx
 * @description 환경설정 > 본인인증 정책 목록 (S1d) 회귀 테스트
 *
 * 회귀 사례 (#297):
 * 1. PC 는 기존 테이블 그대로 유지, 모바일에서만 카드 목록 (계획서 1281~1305 라인)
 *    — 분기 방식은 G7 `responsive` 속성으로 처리 (tailwind `hidden md:block` 금지)
 * 2. 활성 상태(enabled) 가 토글로 즉시 변경 가능해야 함 (모든 디바이스 공통)
 *    — 기존 구현은 Span 배지(ON/OFF) 로만 표시, 토글 동작 X
 *
 * 검증 포인트:
 * - 데스크탑 Table 컨테이너에 responsive.mobile.if = "{{false}}" 분기
 * - 모바일 카드 컨테이너는 기본 if = "{{false}}" + responsive.mobile.if = "{{true}}"
 * - tailwind `hidden md:block` / `md:hidden` 사용 금지
 * - enabled 컬럼/카드에 composite Toggle 컴포넌트 사용 (PC 테이블, 모바일 카드 모두)
 * - Toggle change 액션이 PUT /api/admin/identity/policies/{id} 호출 + body.enabled 전송
 */

import { describe, it, expect } from 'vitest';

const policiesPartial = require('../../layouts/partials/admin_settings/_tab_identity_policies.json');

/**
 * children 트리에서 조건에 맞는 노드를 모두 수집.
 *
 * @param node 시작 노드
 * @param predicate 노드 매칭 조건
 * @returns 매칭된 노드 배열
 */
function collectNodes(node: any, predicate: (n: any) => boolean): any[] {
  const result: any[] = [];
  const visit = (n: any) => {
    if (!n || typeof n !== 'object') return;
    if (Array.isArray(n)) {
      n.forEach(visit);
      return;
    }
    if (predicate(n)) result.push(n);
    if (n.children) visit(n.children);
    if (n.cellChildren) visit(n.cellChildren);
    if (n.actions) visit(n.actions);
    if (n.params) visit(n.params);
    if (n.onSuccess) visit(n.onSuccess);
    if (n.onError) visit(n.onError);
  };
  visit(node);
  return result;
}

describe('S1d 정책 목록 — PC 테이블 + 모바일 카드 (G7 responsive) (#297)', () => {
  describe('responsive 속성 분기 (tailwind hidden md:block 금지)', () => {
    it('tailwind hidden md:block / md:hidden 디바이스 분기 패턴은 사용하지 않는다', () => {
      const partialStr = JSON.stringify(policiesPartial);
      expect(partialStr).not.toMatch(/hidden md:block/);
      expect(partialStr).not.toMatch(/md:hidden/);
    });

    it('데스크탑 Table 컨테이너는 responsive.mobile.if = false 로 모바일에서 비표시', () => {
      const tables = collectNodes(policiesPartial, (n) => n.name === 'Table');
      expect(tables.length).toBeGreaterThan(0);

      // Table 의 조상 중 responsive.mobile.if 가 false 인 컨테이너가 있어야 함
      const allDivs = collectNodes(policiesPartial, (n) => {
        if (n.name !== 'Div') return false;
        const mobileIf = n?.responsive?.mobile?.if;
        if (typeof mobileIf !== 'string') return false;
        if (!/false/.test(mobileIf)) return false;
        // 이 Div 내부에 Table 이 포함되어야 함
        const innerTables = collectNodes(n, (m) => m.name === 'Table');
        return innerTables.length > 0;
      });
      expect(allDivs.length).toBeGreaterThan(0);
    });

    it('모바일 카드 컨테이너는 기본 if = false + responsive.mobile.if = true', () => {
      const mobileCardContainers = collectNodes(policiesPartial, (n) => {
        if (n.name !== 'Div') return false;
        const baseIf = n.if;
        const mobileIf = n?.responsive?.mobile?.if;
        if (typeof baseIf !== 'string' || typeof mobileIf !== 'string') return false;
        return /false/.test(baseIf) && /true/.test(mobileIf);
      });
      expect(mobileCardContainers.length).toBeGreaterThan(0);

      // 그 컨테이너 안에 policies iteration 이 있어야 함
      const policyIterations = collectNodes(mobileCardContainers[0], (n) =>
        n.iteration?.source === 'policies?.data?.data ?? []'
      );
      expect(policyIterations.length).toBeGreaterThan(0);
    });
  });

  describe('Enabled 토글 컴포넌트', () => {
    it('Toggle composite 컴포넌트가 사용되어야 함 (Span 배지 단독 사용 금지)', () => {
      const toggles = collectNodes(policiesPartial, (n) => n.type === 'composite' && n.name === 'Toggle');
      expect(toggles.length).toBeGreaterThan(0);
    });

    it('Toggle 의 checked prop 은 policy.enabled 에 바인딩', () => {
      const toggles = collectNodes(policiesPartial, (n) => n.type === 'composite' && n.name === 'Toggle');
      const checkedProps = toggles.map((t) => t.props?.checked);
      const hasPolicyEnabled = checkedProps.some(
        (v) => typeof v === 'string' && v.includes('policy.enabled')
      );
      expect(hasPolicyEnabled).toBe(true);
    });

    it('Toggle change 액션이 PUT /api/admin/identity/policies/{policy.id} 호출', () => {
      const toggles = collectNodes(policiesPartial, (n) => n.type === 'composite' && n.name === 'Toggle');
      const apiCalls = toggles.flatMap((t) =>
        collectNodes(t, (n) => n.handler === 'apiCall')
      );

      expect(apiCalls.length).toBeGreaterThan(0);

      const matchingCalls = apiCalls.filter(
        (c) =>
          typeof c.target === 'string' &&
          c.target.includes('/api/admin/identity/policies/') &&
          c.target.includes('{{policy.id}}') &&
          c.params?.method === 'PUT'
      );
      expect(matchingCalls.length).toBeGreaterThan(0);
    });

    it('Toggle apiCall body 에 enabled 키 전송', () => {
      const toggles = collectNodes(policiesPartial, (n) => n.type === 'composite' && n.name === 'Toggle');
      const apiCalls = toggles.flatMap((t) =>
        collectNodes(t, (n) => n.handler === 'apiCall' && typeof n.target === 'string' && n.target.includes('/api/admin/identity/policies/'))
      );
      expect(apiCalls.length).toBeGreaterThan(0);

      const hasEnabledBody = apiCalls.some((c) => {
        const body = c.params?.body ?? c.params?.data;
        return body && Object.prototype.hasOwnProperty.call(body, 'enabled');
      });
      expect(hasEnabledBody).toBe(true);
    });

    it('Toggle 변경 후 onSuccess 에서 policies 데이터소스 refetch', () => {
      const toggles = collectNodes(policiesPartial, (n) => n.type === 'composite' && n.name === 'Toggle');
      const refetches = toggles.flatMap((t) =>
        collectNodes(t, (n) => n.handler === 'refetchDataSource' && n.params?.dataSourceId === 'policies')
      );
      expect(refetches.length).toBeGreaterThan(0);
    });
  });

  describe('삭제 버튼 — confirm 핸들러 미사용, 모달 + 스피너 패턴 (#297)', () => {
    /**
     * 회귀 사례: confirm 핸들러는 등록되어 있지 않아 "Unknown action handler: confirm" 에러로 삭제 자체 실행 불가.
     * 기존 admin_role_list/_modal_delete.json 과 동일한 setState + openModal 패턴으로 교체 + 모달 측에서 스피너/disabled 처리.
     */
    it('삭제 버튼 actions 에 confirm 핸들러 미사용', () => {
      const partialStr = JSON.stringify(policiesPartial);
      expect(partialStr).not.toMatch(/"handler"\s*:\s*"confirm"/);
    });

    it('삭제 버튼은 sequence(setState identityPolicyDeleteId + openModal identity_policy_delete_modal) 패턴 사용', () => {
      const sequences = collectNodes(policiesPartial, (n) => n.handler === 'sequence');
      const deleteSequences = sequences.filter((seq) => {
        const actions = seq.actions ?? [];
        const hasSetDeleteId = actions.some(
          (a: any) =>
            a.handler === 'setState' &&
            a.params?.identityPolicyDeleteId !== undefined
        );
        const hasOpenDeleteModal = actions.some(
          (a: any) =>
            a.handler === 'openModal' && a.target === 'identity_policy_delete_modal'
        );
        return hasSetDeleteId && hasOpenDeleteModal;
      });
      expect(deleteSequences.length).toBeGreaterThanOrEqual(2);
    });
  });

  describe('모바일 카드 내용 — 키/타깃/토글 노출', () => {
    it('모바일 카드 컨테이너 안에 policy.key 바인딩 텍스트 존재', () => {
      const mobileContainers = collectNodes(policiesPartial, (n) => {
        if (n.name !== 'Div') return false;
        const baseIf = n.if;
        const mobileIf = n?.responsive?.mobile?.if;
        if (typeof baseIf !== 'string' || typeof mobileIf !== 'string') return false;
        return /false/.test(baseIf) && /true/.test(mobileIf);
      });
      expect(mobileContainers.length).toBeGreaterThan(0);

      const keyTexts = collectNodes(mobileContainers[0], (n) =>
        typeof n.text === 'string' && n.text.includes('policy.key')
      );
      expect(keyTexts.length).toBeGreaterThan(0);
    });

    it('모바일 카드 컨테이너 안에 Toggle 컴포넌트 포함', () => {
      const mobileContainers = collectNodes(policiesPartial, (n) => {
        if (n.name !== 'Div') return false;
        const baseIf = n.if;
        const mobileIf = n?.responsive?.mobile?.if;
        if (typeof baseIf !== 'string' || typeof mobileIf !== 'string') return false;
        return /false/.test(baseIf) && /true/.test(mobileIf);
      });
      expect(mobileContainers.length).toBeGreaterThan(0);

      const toggles = collectNodes(mobileContainers[0], (n) => n.type === 'composite' && n.name === 'Toggle');
      expect(toggles.length).toBeGreaterThan(0);
    });
  });
});
