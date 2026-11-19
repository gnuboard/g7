/**
 * @file member-fields-markup-parity.test.ts
 * @description 공용 회원 항목 조각과, 그 항목 반복 구획을 옮겨 적은 오버라이드 조각의 구조가 같은지
 *
 * 확장 조각은 include 를 풀지 않는다. 그래서 소셜 가입 창과 SSO 동의 창의 오버라이드
 * (extensions/sirsoft-social_login/auth_register_social_modals, extensions/sirsoft-sso/auth-login-sso-modals)는
 * 공용 조각 partials/member/_member_fields 의 묶음 노드(항목 반복 구획)를 파일 안에 옮겨 적었다 — 창마다 두 번(항목 묶음, 약관 묶음).
 * 공용 조각만 고치면 두 창은 낡은 구획을 그린다. 이 테스트는 옮겨 적은 네 벌이 공용 조각과 같은 구조인지 잠근다.
 *
 * 대조 범위는 「항목 반복 구획」(공용 조각의 루트 = 묶음 노드와 그 안의 항목 행)뿐이다. 묶음을 감싸는 노드와 창의 나머지는 보지 않는다.
 * 허용한 차이는 아래 다섯 가지이고, 그 밖의 차이는 실패다 — 공용 조각을 고쳤으면 오버라이드 두 파일의 구획도 같은 모양으로 고친다.
 *
 * | 차이 | 까닭 |
 * | --- | --- |
 * | 화면 문맥(computed memberFieldCtx)의 오류, 비활성, 폼 이름이 창의 전역 상태 이름으로 바뀜 | 확장 조각은 computed 를 선언하지 못하고, 모달은 화면 지역 상태의 낡은 값을 본다 |
 * | 노드 id 와 오류 줄 id 에 창의 접두(social_, sso_)가 붙음 | 가입 화면 본문의 같은 칸과 DOM id 가 겹치지 않게 |
 * | 주소 검색 단추 자리(확장점 address_search_slot)가 없음 | 검색 단추는 고른 주소를 화면 지역 상태에 쓰는데 창의 폼 값은 전역 상태다 |
 * | 주소 칸의 읽기 전용이 잠금만 따름(우편번호 플러그인 조건 없음) | 검색 단추가 없으니 직접 적어야 한다 |
 * | 파일 갈래(mf_files_)가 없음 | 가입 표면의 파일 항목은 늘 deferred 라 안내 줄만 나온다 |
 *
 * @scenario-manifest tests/scenarios/member-fields-markup-parity.yaml
 */

import { describe, it, expect } from 'vitest';

import sharedPartial from '../../layouts/partials/member/_member_fields.json';
import socialOverride from '../../extensions/sirsoft-social_login/auth_register_social_modals.json';
import ssoOverride from '../../extensions/sirsoft-sso/auth-login-sso-modals.json';
import { collect, collectFragment, mapStrings, withoutComments, type Node } from './support/authModalOverrides';

interface OverrideCase {
  label: string;
  fragment: Node;
  /** 옮겨 적은 구획의 노드 id 접두 */
  prefix: string;
  /** 칸별 검증 오류가 있는 전역 상태 */
  errors: string;
  /** 제출 중 여부가 있는 전역 상태 */
  busy: string;
  /** 항목 값이 자동 바인딩되는 전역 폼 */
  formKey: string;
}

const CASES: OverrideCase[] = [
  { label: '소셜 가입 창', fragment: socialOverride as Node, prefix: 'social_', errors: '_global.socialLoginErrors', busy: '_global.socialLoginBusy', formKey: '_global.socialLoginFieldForm' },
  { label: 'SSO 동의 창', fragment: ssoOverride as Node, prefix: 'sso_', errors: '_global.ssoErrors', busy: '_global.ssoBusy', formKey: '_global.ssoFieldForm' },
];

const PLUGIN_READONLY = "{{!!field.readonly || (_global.activePlugins?.includes('sirsoft-daum_postcode') ?? false)}}";
const LOCK_ONLY_READONLY = '{{!!field.readonly}}';

/** 공용 조각의 묶음 노드(항목 반복 구획) — 조각 루트에서 조각 봉투 키(meta)를 뺀 것 */
function sharedGroup(): Node {
  const { meta: _meta, ...group } = sharedPartial as Node;
  return group;
}

/** 허용한 차이를 공용 구획에 적용해, 오버라이드가 가져야 할 구획을 만든다 */
function expectedGroup(testCase: OverrideCase): Node {
  const dropped = (node: Node): boolean =>
    (node.type === 'extension_point' && node.name === 'address_search_slot') || node.id === 'mf_files_{{field.key}}';

  const prune = (node: Node): Node => ({
    ...node,
    ...(Array.isArray(node.children) ? { children: (node.children as Node[]).filter((child) => !dropped(child)).map(prune) } : {}),
  });

  const transformed = mapStrings(prune(withoutComments(sharedGroup())), (text, key) => {
    if (key === 'id') return testCase.prefix + text;
    if (key === 'errorId') return testCase.prefix + text;
    if (key === 'readOnly' && text === PLUGIN_READONLY) return LOCK_ONLY_READONLY;
    return text
      .split("($computed.memberFieldCtx?.formKey ?? 'form')").join(`'${testCase.formKey}'`)
      .split("$computed.memberFieldCtx?.formKey ?? 'form'").join(`'${testCase.formKey}'`)
      .split('$computed.memberFieldCtx?.errors').join(testCase.errors)
      .split('$computed.memberFieldCtx?.disabled').join(testCase.busy);
  });

  return transformed;
}

/** 오버라이드 안에 옮겨 적은 묶음 노드들 */
function copiedGroups(testCase: OverrideCase): Node[] {
  return collectFragment(testCase.fragment).filter(
    (node) => typeof node.id === 'string' && node.id.startsWith(`${testCase.prefix}member_fields_group_`),
  );
}

describe('회원 항목 반복 구획 — 공용 조각과 오버라이드 조각의 구조 대조', () => {
  /**
   * @scenario subject=shared_partial
   * @effects shared_group_is_the_partial_root_with_one_field_iteration
   */
  it('공용 조각의 루트가 묶음 노드이고 그 안에 항목 반복이 하나 있다 (대조 기준)', () => {
    const group = sharedGroup();
    expect(group.id).toBe('member_fields_group_{{memberFieldGroup.key}}');
    const rows = (group.children as Node[]).filter((node) => node.iteration);
    expect(rows.map((node) => node.iteration.item_var)).toEqual(['field']);
    expect(rows[0].id).toBe('field_{{field.key}}');
    // 허용한 차이의 대상이 공용 조각에 실제로 있다 — 없어졌으면 이 테스트의 허용 목록을 다시 정한다.
    const nodes = collect(group);
    expect(nodes.filter((node) => node.type === 'extension_point' && node.name === 'address_search_slot')).toHaveLength(1);
    expect(nodes.filter((node) => node.id === 'mf_files_{{field.key}}')).toHaveLength(1);
    expect(JSON.stringify(group)).toContain('$computed.memberFieldCtx?.errors');
    expect(JSON.stringify(group).split(PLUGIN_READONLY).length - 1).toBe(2);
  });

  describe.each(CASES)('$label', (testCase) => {
    /**
     * @scenario subject=override_copy
     * @effects override_copies_group_twice_per_modal
     */
    it('구획을 두 번 옮겨 적었다 — 항목 묶음과 약관 묶음', () => {
      const groups = copiedGroups(testCase);
      expect(groups).toHaveLength(2);
      for (const group of groups) {
        expect(group.id).toBe(`${testCase.prefix}member_fields_group_{{memberFieldGroup.key}}`);
      }
    });

    /**
     * @scenario subject=override_copy
     * @effects copied_group_equals_shared_group_modulo_allowed_differences
     */
    it('옮겨 적은 구획은 허용한 차이만 빼고 공용 구획과 같다', () => {
      const expected = expectedGroup(testCase);
      for (const group of copiedGroups(testCase)) {
        expect(withoutComments(group)).toEqual(expected);
      }
    });

    /**
     * @scenario subject=override_copy
     * @effects copied_group_does_not_read_screen_context
     */
    it('옮겨 적은 구획은 화면 문맥(computed, 지역 상태, 내 정보 API)을 읽지 않는다', () => {
      for (const group of copiedGroups(testCase)) {
        const text = JSON.stringify(group);
        expect(text).not.toContain('$computed');
        expect(text).not.toContain('_local.');
        expect(text).not.toContain('/api/me');
        expect(text).not.toContain('profileFields');
        expect(text).toContain(testCase.errors);
        expect(text).toContain(testCase.busy);
      }
    });

    /**
     * @scenario subject=override_copy
     * @effects copied_group_is_fed_by_member_field_group_iteration
     */
    it('구획을 감싼 반복이 공용 조각이 읽는 반복 변수 memberFieldGroup 을 준다', () => {
      const wrappers = collectFragment(testCase.fragment).filter((node) => node.iteration?.item_var === 'memberFieldGroup');
      expect(wrappers).toHaveLength(2);
      for (const wrapper of wrappers) {
        expect((wrapper.children as Node[]).map((child) => child.id)).toEqual([`${testCase.prefix}member_fields_group_{{memberFieldGroup.key}}`]);
      }
    });
  });
});
