/**
 * @file admin-settings-identity-policy-modal.test.tsx
 * @description 환경설정 > 본인인증 정책 모달 회귀 테스트 (옵션 K — _global 단일 진실)
 *
 * 패턴:
 * - list 측 add/edit click → setState target:"global", identity_policy_form_modal: { form, isNew, errors, isSaving } (객체 통째 set)
 * - 모달 표시 — _global.identity_policy_form_modal?.X 직접 참조 (가드/fallback 불필요)
 * - 모달 input change — setState target:"global", "identity_policy_form_modal.form.X": "..." (dot path)
 * - 저장 onSuccess — closeModal + refetchDataSource + setState identity_policy_form_modal: null (정리)
 *
 * 사례 13 회피: 키스트로크당 부모 _local 변경 0회 (target:"global" 만 사용, 모달 외부는 _global.identity_policy_form_modal 미참조)
 * Stale 회피: list 측 매 진입마다 객체 통째 set + 저장 후 명시적 null 리셋
 */

import { describe, it, expect } from 'vitest';

const adminSettings = require('../../layouts/admin_settings.json');
const policiesPartial = require('../../layouts/partials/admin_settings/_tab_identity_policies.json');
const modalPartial = require('../../layouts/partials/admin_settings/_modal_identity_policy_form.json');

interface AnyJson { [k: string]: any }

const collectChangeTargets = (node: AnyJson, acc: string[] = []): string[] => {
  if (!node || typeof node !== 'object') return acc;
  if (Array.isArray(node)) { node.forEach(item => collectChangeTargets(item, acc)); return acc; }
  if (Array.isArray(node.actions)) {
    for (const a of node.actions) {
      const isChange = a?.type === 'change' || a?.event === 'onChange' || a?.event === 'onSearch';
      if (isChange && a?.handler === 'setState' && a?.params?.target) {
        acc.push(a.params.target);
      }
    }
  }
  for (const k of Object.keys(node)) collectChangeTargets(node[k], acc);
  return acc;
};

describe('환경설정 > 본인인증 정책 모달 회귀 테스트 (옵션 K)', () => {
  describe('모달 등록 / 구조', () => {
    it('admin_settings.json 의 modals 에 모달 partial 등록', () => {
      expect(JSON.stringify(adminSettings.modals)).toContain('partials/admin_settings/_modal_identity_policy_form.json');
    });

    it('표준 Modal 컴포넌트 구조', () => {
      expect(modalPartial.type).toBe('composite');
      expect(modalPartial.name).toBe('Modal');
      expect(modalPartial.id).toBe('identity_policy_form_modal');
    });

    it('lifecycle.onMount 미사용 (modals 섹션 모달 1회 트리거 제약)', () => {
      expect(modalPartial.lifecycle).toBeUndefined();
    });

    it('dataKey 자동바인딩 미사용', () => {
      expect(JSON.stringify(modalPartial)).not.toContain('"dataKey"');
    });
  });

  describe('list 측 setState — _global namespace 통째 set', () => {
    it('add/edit click setState 가 _global.identity_policy_form_modal 객체 통째 set', () => {
      const partialStr = JSON.stringify(policiesPartial);
      expect(partialStr).toContain('"target":"global"');
      expect(partialStr).toContain('"identity_policy_form_modal":');
      // 객체 통째 set 패턴 — form/isNew/errors/isSaving 구조
      const occurrences = (partialStr.match(/"identity_policy_form_modal":\s*\{/g) ?? []).length;
      expect(occurrences).toBeGreaterThanOrEqual(3); // add + edit-active + edit-disabled
    });

    it('각 setState 에 form 객체와 isNew/errors/isSaving 키 포함', () => {
      const partialStr = JSON.stringify(policiesPartial);
      expect(partialStr).toContain('"form":');
      expect(partialStr).toContain('"isNew":');
      expect(partialStr).toContain('"errors":null');
      expect(partialStr).toContain('"isSaving":false');
    });

    it('Edit/Add sequence 끝에 openModal(identity_policy_form_modal) 호출', () => {
      const partialStr = JSON.stringify(policiesPartial);
      expect(partialStr).toContain('"handler":"openModal"');
      expect(partialStr).toContain('"target":"identity_policy_form_modal"');
    });
  });

  describe('사례 13 — 키스트로크당 부모 _local 변경 0회', () => {
    it('change 액션은 모두 target:"global"', () => {
      const targets = collectChangeTargets(modalPartial);
      expect(targets.length).toBeGreaterThan(0);
      expect(targets.every(t => t === 'global')).toBe(true);
    });

    it('모달 본문에 target:"$parent._local" / "local" 사용 없음', () => {
      const modalStr = JSON.stringify(modalPartial);
      expect(modalStr).not.toContain('"target":"$parent._local"');
      expect(modalStr).not.toContain('"target":"local"');
    });
  });

  describe('옵션 K — _global namespace 단일 진실', () => {
    it('모든 표시 표현식이 _global.identity_policy_form_modal 경로 참조', () => {
      const modalStr = JSON.stringify(modalPartial);
      const fields = ['key', 'scope', 'target', 'purpose', 'provider_id', 'grace_minutes', 'applies_to', 'fail_mode', 'enabled'];
      for (const f of fields) {
        expect(modalStr).toContain(`_global.identity_policy_form_modal?.form?.${f}`);
      }
      expect(modalStr).toContain('_global.identity_policy_form_modal?.errors');
      expect(modalStr).toContain('_global.identity_policy_form_modal?.isNew');
      expect(modalStr).toContain('_global.identity_policy_form_modal?.isSaving');
    });

    it('input change setState 가 dot path 로 form.X 변경', () => {
      const modalStr = JSON.stringify(modalPartial);
      const fields = ['key', 'scope', 'target', 'purpose'];
      for (const f of fields) {
        expect(modalStr).toContain(`"identity_policy_form_modal.form.${f}":`);
      }
    });
  });

  describe('저장 흐름 (sequence: setState saving → apiCall → onSuccess)', () => {
    it('저장 onSuccess 에 closeModal + refetchDataSource + namespace null 정리', () => {
      const modalStr = JSON.stringify(modalPartial);
      expect(modalStr).toContain('"handler":"closeModal"');
      expect(modalStr).toContain('"handler":"refetchDataSource"');
      expect(modalStr).toContain('"identity_policy_form_modal":null');
    });

    it('apiCall body 모든 필드가 _global.identity_policy_form_modal?.form?.X', () => {
      const modalStr = JSON.stringify(modalPartial);
      const fields = ['key', 'scope', 'target', 'purpose', 'fail_mode'];
      for (const f of fields) {
        expect(modalStr).toContain(`_global.identity_policy_form_modal?.form?.${f}`);
      }
    });
  });

  describe('conditions 운영자 편집 (B안 리팩토링)', () => {
    /**
     * 회원가입 단계 등 정책 조건은 폼에서 편집 가능해야 한다.
     * - 신규 추가 init: conditions 가 빈 객체 {} 로 시작 (중첩 키 setState 가능하도록)
     * - 편집 모달 진입: setState 가 policy.conditions 를 폼 상태로 복사
     * - signup_stage Select 의 setState 가 form.conditions.signup_stage 경로로 변경
     * - apiCall body 가 form.conditions 를 그대로 전송
     */
    it('신규 추가 init 상태에서 conditions 가 빈 객체 {}', () => {
      const partialStr = JSON.stringify(policiesPartial);
      expect(partialStr).toContain('"conditions":{}');
    });

    it('편집 모달 진입 setState 가 policy.conditions 를 form 상태로 복사', () => {
      const partialStr = JSON.stringify(policiesPartial);
      expect(partialStr).toContain('"conditions":"{{(policy.conditions ?? {})}}"');
    });

    it('signup_stage Select 의 change 가 form.conditions.signup_stage 경로 setState', () => {
      const modalStr = JSON.stringify(modalPartial);
      expect(modalStr).toContain('"identity_policy_form_modal.form.conditions.signup_stage"');
    });

    it('apiCall body 에 conditions 필드가 form.conditions 로 전송', () => {
      const modalStr = JSON.stringify(modalPartial);
      expect(modalStr).toContain('_global.identity_policy_form_modal?.form?.conditions');
    });
  });
});
