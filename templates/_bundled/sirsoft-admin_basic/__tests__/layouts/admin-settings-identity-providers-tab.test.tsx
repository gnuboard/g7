/**
 * @file admin-settings-identity-providers-tab.test.tsx
 * @description 환경설정 > 본인인증 > 목적별 프로바이더 sub-tab 회귀 테스트
 *
 * 회귀 사례:
 * 1. (D1) purpose id 에 점(.)이 포함된 매핑(예: KG이니시스 `inicis.adult_verification`) 저장 시
 *    프론트엔드 폼 바인딩이 dot-notation name 을 중첩 객체로 풀어 전송 → 백엔드 422.
 *    → Select name 은 `identity.purpose_providers.{{purpose.id}}` dot-path 를 유지하되,
 *      백엔드 validation 이 nested leaf 를 허용하도록 수정됨 (SaveSettingsRequest).
 * 2. (D2) 저장 실패(422) 시 목적별 프로바이더 Select 에 필드별 강조/하단 문구가
 *    전혀 없었음. → 기본 sub-tab 과 동일하게 _local.errors 키 기반 강조 + form-error 추가.
 *
 * 검증 포인트:
 * - 각 purpose 행의 Select name 이 `identity.purpose_providers.{{purpose.id}}`
 * - 에러 발생 시 className 이 _local.errors 키(`identity.purpose_providers.` + purpose.id)로 분기
 * - 에러 메시지 Span(form-error)이 동일 키 + if 조건으로 렌더
 * - 에러 키 참조가 백엔드가 반환하는 dot-path 키와 문자 단위로 일치
 */

import { describe, it, expect } from 'vitest';

const providersPartial = require('../../layouts/partials/admin_settings/_tab_identity_providers.json');

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

describe('환경설정 > 본인인증 > 목적별 프로바이더 sub-tab', () => {
  describe('D1: Select name 은 purpose id dot-path 유지', () => {
    it('목적별 프로바이더 Select 의 name 이 identity.purpose_providers.{{purpose.id}} 이다', () => {
      const selects = collectNodes(
        providersPartial,
        (n) =>
          n.name === 'Select' &&
          typeof n?.props?.name === 'string' &&
          n.props.name.startsWith('identity.purpose_providers.')
      );

      expect(selects.length).toBeGreaterThan(0);
      const target = selects.find(
        (s) => s.props.name === 'identity.purpose_providers.{{purpose.id}}'
      );
      expect(target).toBeDefined();
    });
  });

  describe('D2: 저장 실패 시 필드별 강조 + 하단 문구', () => {
    it('목적별 프로바이더 Select className 이 _local.errors 키 기반으로 빨강 테두리 분기한다', () => {
      const select = collectNodes(
        providersPartial,
        (n) =>
          n.name === 'Select' &&
          n?.props?.name === 'identity.purpose_providers.{{purpose.id}}'
      )[0];

      expect(select).toBeDefined();
      const className = select.props.className as string;

      // _local.errors 의 dot-path 키 참조 + 빨강 테두리 분기
      expect(className).toContain("_local.errors?.['identity.purpose_providers.' + purpose.id]");
      expect(className).toMatch(/border-red-500/);
    });

    it('에러 메시지 Span(form-error)이 동일 키 + if 조건으로 존재한다', () => {
      const errorSpans = collectNodes(
        providersPartial,
        (n) =>
          n.name === 'Span' &&
          n?.props?.className === 'form-error' &&
          typeof n?.if === 'string' &&
          n.if.includes("_local.errors?.['identity.purpose_providers.' + purpose.id]")
      );

      expect(errorSpans.length).toBeGreaterThan(0);

      const span = errorSpans[0];
      // 첫 번째 에러 메시지를 텍스트로 표시
      expect(span.text).toContain(
        "_local.errors?.['identity.purpose_providers.' + purpose.id]?.[0]"
      );
    });

    it('에러 키 참조가 백엔드 dot-path 키 형식과 일치한다 (점 포함 purpose id 대응)', () => {
      // 백엔드(SaveSettingsRequest)가 반환하는 키: identity.purpose_providers.<purpose.id>
      // purpose.id 가 inicis.adult_verification 이면
      // → identity.purpose_providers.inicis.adult_verification
      // 프론트는 'identity.purpose_providers.' + purpose.id 로 동일하게 결합하므로 일치.
      const partialStr = JSON.stringify(providersPartial);
      expect(partialStr).toContain("'identity.purpose_providers.' + purpose.id");
      // 단일 깊이 와일드카드 가정(잘린 키)로 참조하지 않아야 함
      expect(partialStr).not.toContain('purpose_providers.inicis]');
    });
  });
});
