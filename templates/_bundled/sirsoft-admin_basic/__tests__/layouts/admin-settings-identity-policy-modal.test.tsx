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
import { asArray } from '@core/template-engine/__tests__/utils/layoutShapeUtils';

const adminSettings = require('../../layouts/admin_settings.json');
const policiesPartial = require('../../layouts/partials/admin_settings/_tab_identity_policies.json');
const modalPartial = require('../../layouts/partials/admin_settings/_modal_identity_policy_form.json');

interface AnyJson { [k: string]: any }

const collectChangeTargets = (node: AnyJson, acc: string[] = []): string[] => {
  if (!node || typeof node !== 'object') return acc;
  if (Array.isArray(node)) { node.forEach(item => collectChangeTargets(item, acc)); return acc; }
  if (asArray(node?.actions).length > 0) {
    for (const a of asArray(node?.actions)) {
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
    /** @scenario surface=identity_policy_modal
     *  @effects modal_partial_registered_in_admin_settings_modals */
    it('admin_settings.json 의 modals 에 모달 partial 등록', () => {
      expect(JSON.stringify(adminSettings.modals)).toContain('partials/admin_settings/_modal_identity_policy_form.json');
    });

    /** @scenario surface=identity_policy_modal
     *  @effects modal_uses_standard_modal_component_shape */
    it('표준 Modal 컴포넌트 구조', () => {
      expect(modalPartial.type).toBe('composite');
      expect(modalPartial.name).toBe('Modal');
      expect(modalPartial.id).toBe('identity_policy_form_modal');
    });

    /** @scenario surface=identity_policy_modal
     *  @effects modal_avoids_lifecycle_onmount_single_trigger_constraint */
    it('lifecycle.onMount 미사용 (modals 섹션 모달 1회 트리거 제약)', () => {
      expect(modalPartial.lifecycle).toBeUndefined();
    });

    /** @scenario surface=identity_policy_modal
     *  @effects modal_does_not_use_datakey_autobinding */
    it('dataKey 자동바인딩 미사용', () => {
      expect(JSON.stringify(modalPartial)).not.toContain('"dataKey"');
    });
  });

  describe('list 측 setState — _global namespace 통째 set', () => {
    /** @scenario surface=identity_policy_modal
     *  @effects list_entry_sets_whole_global_namespace_object */
    it('add/edit click setState 가 _global.identity_policy_form_modal 객체 통째 set', () => {
      const partialStr = JSON.stringify(policiesPartial);
      expect(partialStr).toContain('"target":"global"');
      expect(partialStr).toContain('"identity_policy_form_modal":');
      // 객체 통째 set 패턴 — form/isNew/errors/isSaving 구조
      const occurrences = (partialStr.match(/"identity_policy_form_modal":\s*\{/g) ?? []).length;
      expect(occurrences).toBeGreaterThanOrEqual(3); // add + edit-active + edit-disabled
    });

    /** @scenario surface=identity_policy_modal
     *  @effects entry_setstate_carries_form_and_isnew_errors_issaving */
    it('각 setState 에 form 객체와 isNew/errors/isSaving 키 포함', () => {
      const partialStr = JSON.stringify(policiesPartial);
      expect(partialStr).toContain('"form":');
      expect(partialStr).toContain('"isNew":');
      expect(partialStr).toContain('"errors":null');
      expect(partialStr).toContain('"isSaving":false');
    });

    /** @scenario surface=identity_policy_modal
     *  @effects entry_sequence_ends_with_openmodal */
    it('Edit/Add sequence 끝에 openModal(identity_policy_form_modal) 호출', () => {
      const partialStr = JSON.stringify(policiesPartial);
      expect(partialStr).toContain('"handler":"openModal"');
      expect(partialStr).toContain('"target":"identity_policy_form_modal"');
    });
  });

  describe('사례 13 — 키스트로크당 부모 _local 변경 0회', () => {
    /** @scenario surface=identity_policy_modal
     *  @effects change_actions_target_global_only */
    it('change 액션은 모두 target:"global"', () => {
      const targets = collectChangeTargets(modalPartial);
      expect(targets.length).toBeGreaterThan(0);
      expect(targets.every(t => t === 'global')).toBe(true);
    });

    /** @scenario surface=identity_policy_modal
     *  @effects modal_body_never_targets_parent_local */
    it('모달 본문에 target:"$parent._local" / "local" 사용 없음', () => {
      const modalStr = JSON.stringify(modalPartial);
      expect(modalStr).not.toContain('"target":"$parent._local"');
      expect(modalStr).not.toContain('"target":"local"');
    });
  });

  describe('옵션 K — _global namespace 단일 진실', () => {
    /** @scenario surface=identity_policy_modal
     *  @effects all_display_expressions_read_global_namespace */
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

    /** @scenario surface=identity_policy_modal
     *  @effects input_change_setstate_uses_dot_path_into_form */
    it('input change setState 가 dot path 로 form.X 변경', () => {
      const modalStr = JSON.stringify(modalPartial);
      const fields = ['key', 'scope', 'target', 'purpose'];
      for (const f of fields) {
        expect(modalStr).toContain(`"identity_policy_form_modal.form.${f}":`);
      }
    });
  });

  describe('저장 흐름 (sequence: setState saving → apiCall → onSuccess)', () => {
    /** @scenario surface=identity_policy_modal
     *  @effects save_onsuccess_closes_modal_refetches_and_clears_namespace */
    it('저장 onSuccess 에 closeModal + refetchDataSource + namespace null 정리', () => {
      const modalStr = JSON.stringify(modalPartial);
      expect(modalStr).toContain('"handler":"closeModal"');
      expect(modalStr).toContain('"handler":"refetchDataSource"');
      expect(modalStr).toContain('"identity_policy_form_modal":null');
    });

    /** @scenario surface=identity_policy_modal
     *  @effects apicall_body_fields_read_from_global_form */
    it('apiCall body 모든 필드가 _global.identity_policy_form_modal?.form?.X', () => {
      const modalStr = JSON.stringify(modalPartial);
      const fields = ['key', 'scope', 'target', 'purpose', 'fail_mode'];
      for (const f of fields) {
        expect(modalStr).toContain(`_global.identity_policy_form_modal?.form?.${f}`);
      }
    });
  });

  describe('출처별 편집 제한 — 선언형(코어/모듈/플러그인) 잠금 필드', () => {
    /**
     * 선언형 정책(source_type != admin) 편집 시 키(key)/시점(scope)/위치(target) 만 잠기고
     * 그 외 필드는 운영자가 자유로이 편집할 수 있어야 한다.
     *
     * 백엔드 화이트리스트: AdminIdentityPolicyController::LIMITED_EDITABLE_FIELDS
     *   = [enabled, grace_minutes, provider_id, fail_mode, conditions, purpose, applies_to, priority]
     * → key/scope/target 만 모달에서 disabled. 인증 목적(purpose)·적용 대상(applies_to) 포함 나머지는
     *   disabled 미부여(편집 가능). 안내 문구도 "키·시점·위치만 변경 불가" 로 정합.
     *
     * 배경: 과거 purpose/applies_to 까지 잠겼던 것은 "선언형 readonly" 원칙의 과확장이었으며,
     * 인증 목적은 어떤 정책이든 운영자가 자유 부여 가능해야 한다.
     */
    const LOCK_PREDICATE = "!_global.identity_policy_form_modal?.isNew && _global.identity_policy_form_modal?.form?.source_type !== 'admin'";

    // 컴포넌트 노드를 라벨 i18n 키로 찾는다.
    const findFieldControl = (node: AnyJson, labelKey: string): AnyJson | null => {
      if (!node || typeof node !== 'object') return null;
      if (Array.isArray(node)) {
        for (const item of node) { const r = findFieldControl(item, labelKey); if (r) return r; }
        return null;
      }
      // Div(그룹) 안에 Label(text === labelKey) + Input|Select 형제 구조
      if (Array.isArray(node.children)) {
        const hasLabel = node.children.some((c: AnyJson) => c?.name === 'Label' && c?.text === labelKey);
        if (hasLabel) {
          const control = node.children.find((c: AnyJson) => c?.name === 'Input' || c?.name === 'Select');
          if (control) return control;
        }
      }
      for (const k of Object.keys(node)) { const r = findFieldControl(node[k], labelKey); if (r) return r; }
      return null;
    };

    const lockedFields = [
      ['정책 키', '$t:admin.identity.policies.form.key'],
      ['강제 시점', '$t:admin.identity.policies.form.scope'],
      ['강제 위치', '$t:admin.identity.policies.form.target'],
    ] as const;

    /** @scenario surface=identity_policy_modal
     *  @effects declarative_source_locks_identity_fields */
    it.each(lockedFields)('선언형 잠금 필드 "%s" 는 disabled 바인딩 보유', (_label, labelKey) => {
      const control = findFieldControl(modalPartial, labelKey);
      expect(control).not.toBeNull();
      expect(control!.props?.disabled).toBe(`{{${LOCK_PREDICATE}}}`);
    });

    const editableFields = [
      ['인증 목적', '$t:admin.identity.policies.form.purpose'],
      ['적용 대상', '$t:admin.identity.policies.form.applies_to'],
      ['인증 수단', '$t:admin.identity.policies.form.provider_id'],
      ['재요구 주기', '$t:admin.identity.policies.form.grace_minutes'],
      ['실패 시 처리', '$t:admin.identity.policies.form.fail_mode'],
    ] as const;

    /** @scenario surface=identity_policy_modal
     *  @effects editable_fields_stay_unlocked_for_declarative_source */
    it.each(editableFields)('편집 가능 필드 "%s" 는 disabled 미부여 (선언형도 편집 가능)', (_label, labelKey) => {
      const control = findFieldControl(modalPartial, labelKey);
      expect(control).not.toBeNull();
      expect(control!.props?.disabled).toBeUndefined();
    });

    /** @scenario surface=identity_policy_modal
     *  @effects purpose_field_has_no_disabled_class_variant */
    it('인증 목적(purpose) 은 disabled className variant 도 제거 (편집 가능 회귀)', () => {
      const purpose = findFieldControl(modalPartial, '$t:admin.identity.policies.form.purpose');
      expect(purpose!.props?.className ?? '').not.toContain('disabled:bg-gray-100');
    });

    /** @scenario surface=identity_policy_modal
     *  @effects priority_input_exists_and_binds_form_priority */
    it('우선순위(priority) 입력칸 존재 + 편집 가능 + form.priority 바인딩', () => {
      const priority = findFieldControl(modalPartial, '$t:admin.identity.policies.form.priority');
      expect(priority).not.toBeNull();
      expect(priority!.name).toBe('Input');
      expect(priority!.props?.type).toBe('number');
      expect(priority!.props?.value).toContain('identity_policy_form_modal?.form?.priority');
      expect(priority!.props?.disabled).toBeUndefined();
    });
  });

  describe('우선순위 동률 결함 수정', () => {
    /** @scenario surface=identity_policy_modal
     *  @effects priority_change_setstate_targets_form_priority */
    it('모달에 우선순위 입력 change setState 가 form.priority 경로로 변경', () => {
      const modalStr = JSON.stringify(modalPartial);
      expect(modalStr).toContain('"identity_policy_form_modal.form.priority"');
    });

    /** @scenario surface=identity_policy_modal
     *  @effects policy_grid_exposes_priority_column */
    it('정책 목록(DataGrid) 에 우선순위 컬럼 헤더 + 값 바인딩 노출', () => {
      const partialStr = JSON.stringify(policiesPartial);
      expect(partialStr).toContain('$t:admin.identity.policies.col.priority');
      expect(partialStr).toContain('policy.priority ?? 100');
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
    /** @scenario surface=identity_policy_modal
     *  @effects new_policy_init_state_uses_empty_map_for_conditions */
    it('신규 추가 init 상태에서 conditions 가 빈 컨테이너', () => {
      // `conditions` 는 **이름표로 값을 꺼내는 자리**라 빈 값도 map(`{}`)이다 — 편집 모달 진입
      // setState 가 `policy.conditions ?? {}` 로 복사하고(아래 테스트), 저장은 그 묶음을 그대로
      // 본문에 싣는다.
      //
      // 종전 판본은 `[]` 를 단언하며 「마크업 소스가 `[]` 로 선언하므로 정본은 `[]` 이고, 설치는
      // 소스를 읽으니 실제로 돌던 값은 처음부터 `[]` 였다」고 적어 두었는데 그 전제가 사실과
      // 다르다 — 이 파셜의 짝 `.g7.html` 은 저작 트랙 이관(#476)에서 처음 생겼고, 그 전에는
      // 설치가 `.json` 을 읽었으므로 실제로 돌던 값은 `{}` 였다. `[]` 는 이관이 바꾼 값이다
      // (#599 15차 병합 전수 보존 검사 실측, 운영 결정 2026-09-21 「22곳 전부 되돌린다」).
      const partialStr = JSON.stringify(policiesPartial);
      expect(partialStr).toContain('"conditions":{}');
    });

    /** @scenario surface=identity_policy_modal
     *  @effects edit_entry_copies_policy_conditions_into_form */
    it('편집 모달 진입 setState 가 policy.conditions 를 form 상태로 복사', () => {
      const partialStr = JSON.stringify(policiesPartial);
      expect(partialStr).toContain('"conditions":"{{(policy.conditions ?? {})}}"');
    });

    /** @scenario surface=identity_policy_modal
     *  @effects signup_stage_change_writes_form_conditions_path */
    it('signup_stage Select 의 change 가 form.conditions.signup_stage 경로 setState', () => {
      const modalStr = JSON.stringify(modalPartial);
      expect(modalStr).toContain('"identity_policy_form_modal.form.conditions.signup_stage"');
    });

    /** @scenario surface=identity_policy_modal
     *  @effects apicall_body_sends_conditions_from_form */
    it('apiCall body 에 conditions 필드가 form.conditions 로 전송', () => {
      const modalStr = JSON.stringify(modalPartial);
      expect(modalStr).toContain('_global.identity_policy_form_modal?.form?.conditions');
    });
  });
});
