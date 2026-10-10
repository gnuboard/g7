/**
 * 관리자 회원 **상세 ↔ 수정** 항목 정합 (#599).
 *
 * 요구사항(2026-09-21): 「회원상세보기에 나오는 것은 수정하기 화면에서 모두 수정이 가능해야 한다.
 * 위치도 동일하고 양식도 동일해야 한다.」 — 상세를 정본으로 삼아 수정 화면을 맞췄다.
 *
 * 이 파일이 막는 것은 **한쪽만 고치는 일**이다. 두 화면은 파일이 따로라, 상세에 칸을 하나 더하고
 * 수정 화면을 잊으면 아무 검사도 걸리지 않은 채 「보이는데 못 고치는 값」 이 생긴다. 실제로 그렇게
 * 이메일 인증 여부·잠금 상태·탈퇴일시·차단일시와 동의정보·알림함 두 탭이 수정 화면에서 빠져
 * 있었다. 여기서는 **탭 목록 · 구획 순서 · 구획별 칸 순서** 셋을 상세 기준으로 잠근다.
 *
 * 「양식도 동일」 은 위젯 종류까지 같게 하라는 뜻이 아니다 — 수정 화면의 이름 칸은 입력칸이고
 * 상세의 이름 칸은 글자다. 고칠 수 있는 값은 입력칸으로, 시스템이 적는 기록값은 같은 자리에
 * 잠근 채로 둔다. 그 판정은 아래 RECORD_ONLY 가 이름으로 적는다.
 *
 * @vitest-environment jsdom
 */
import fs from 'node:fs';
import path from 'node:path';
import { describe, expect, it } from 'vitest';

const root = path.resolve(__dirname, '../../layouts');
const read = (file: string) => JSON.parse(fs.readFileSync(path.join(root, file), 'utf8'));

const detail = read('admin_user_detail.json');
const form = read('admin_user_form.json');

/** 트리를 훑어 `data-ref` 로 붙은 id 를 **나온 순서대로** 모은다. */
const refsInOrder = (node: unknown, out: string[] = []): string[] => {
  if (!node || typeof node !== 'object') return out;
  if (Array.isArray(node)) {
    node.forEach((n) => refsInOrder(n, out));
    return out;
  }
  const n = node as Record<string, unknown>;
  if (typeof n.id === 'string') out.push(n.id);
  Object.values(n).forEach((v) => refsInOrder(v, out));
  return out;
};

const sectionsAndFields = (layout: unknown) =>
  refsInOrder(layout).filter((id) => id.startsWith('section_') || id.startsWith('field_'));

/** 탭 네비게이션이 들고 있는 탭 id 를 순서대로. */
const tabIds = (layout: unknown): string[] => {
  const found: string[] = [];
  const walk = (node: unknown): void => {
    if (!node || typeof node !== 'object') return;
    if (Array.isArray(node)) return node.forEach(walk);
    const n = node as Record<string, any>;
    if (n.name === 'TabNavigationScroll' && Array.isArray(n.props?.tabs)) {
      n.props.tabs.forEach((t: any) => found.push(t.id));
    }
    Object.values(n).forEach(walk);
  };
  walk(layout);
  return found;
};

/**
 * 수정 화면에만 있는 것이 허용되는 칸 — 상세에 짝이 없어도 된다.
 * 비밀번호는 **쓰기 전용**이라 상세가 보여 줄 값 자체가 없다(해시를 화면에 낼 수는 없다).
 */
const FORM_ONLY = [
  'field_password_group',
  'field_password',
  'field_password_confirmation',
  // 본인 계정의 비밀번호를 바꿀 때만 묻는 확인 칸(#736 D19) — 비밀번호처럼 쓰기 전용이다.
  'field_current_password',
];

/**
 * 수정 화면에서 잠긴 채로 서는 칸 — 시스템이 적는 기록이라 운영자가 정할 값이 아니다.
 * 자리를 비우지 않는 이유는 위 파일 주석에 적었다.
 */
const RECORD_ONLY = [
  'field_uuid',
  'field_email_verified',
  'field_created_at',
  'field_last_login_at',
  'field_lock_status',
  'field_ip_address',
  'field_withdrawn_at',
  'field_blocked_at',
  'field_dormant_at',
  // 비밀번호 구획(#736) — 변경일·만료 예정·변경 요구·연기 종료는 서버가 적는 기록이다(만료 개별 설정만 고친다).
  'field_password_changed_at',
  'field_password_expires_at',
  'field_password_change_required',
  'field_password_postponed_until',
  'field_terms_consent',
  'field_privacy_consent',
  'field_notifications_total',
  'field_notifications_unread',
];

describe('관리자 회원 상세 ↔ 수정 정합 (#599)', () => {
  // @scenario surface=admin_user_detail_form
  // @effects admin_user_form_tabs_match_detail_tabs
  it('수정 화면의 탭이 상세와 같은 이름·같은 순서다', () => {
    // 상세에만 있던 동의정보·알림함 두 탭이 수정 화면에서 빠져 있었다(검수 지적).
    expect(tabIds(form)).toEqual(tabIds(detail));
  });

  // @scenario surface=admin_user_detail_form
  // @effects admin_user_form_carries_every_detail_field_in_the_same_order
  it('상세의 모든 칸이 수정 화면에도 같은 순서로 선다', () => {
    const detailOrder = sectionsAndFields(detail);
    const formOrder = sectionsAndFields(form).filter((id) => !FORM_ONLY.includes(id));

    expect(formOrder).toEqual(detailOrder);
  });

  // @scenario surface=admin_user_detail_form
  // @effects admin_user_form_record_only_fields_are_locked
  it('기록성 칸은 수정 화면에서도 잠겨 있다 — 자리는 있고 고칠 수는 없다', () => {
    const locked = (id: string): boolean => {
      let hit: any = null;
      const walk = (node: unknown): void => {
        if (hit || !node || typeof node !== 'object') return;
        if (Array.isArray(node)) return node.forEach(walk);
        const n = node as Record<string, any>;
        if (n.id === id) hit = n;
        else Object.values(n).forEach(walk);
      };
      walk(form);
      if (!hit) return false;
      const json = JSON.stringify(hit);
      // 입력칸이면 disabled/readOnly 가 걸려 있어야 하고, 글자면 애초에 고칠 수 없다.
      return /"disabled":\s*true/.test(json) || /"readOnly":\s*true/.test(json) || !/"name":\s*"Input"/.test(json);
    };

    const unlocked = RECORD_ONLY.filter((id) => !locked(id));

    expect(unlocked).toEqual([]);
  });

  // @scenario surface=admin_user_detail_form
  // @effects admin_user_form_editable_fields_carry_a_form_name
  it('고칠 수 있는 칸은 저장에 실린다 — name 바인딩이 있다', () => {
    // 칸만 세우고 `name` 을 빠뜨리면 화면에는 보이는데 저장 body 에 담기지 않는다.
    // `field_country` 는 제외한다 — 두 화면 모두 `:data-if="false"` 로 꺼둔 칸이고, 되살릴 때를
    // 위해 배선만 남겨 둔 상태다. 그 배선은 `name` 이 아니라 change 에서 `form.country` 를
    // 직접 세우는 방식이라(searchable-dropdown 은 폼 바인딩을 받지 않는다) 여기 규칙이 닿지 않는다.
    const editable = sectionsAndFields(form)
      .filter((id) => id.startsWith('field_'))
      .filter((id) => !RECORD_ONLY.includes(id) && id !== 'field_password_group' && id !== 'field_country');

    const missing = editable.filter((id) => {
      let hit: any = null;
      const walk = (node: unknown): void => {
        if (hit || !node || typeof node !== 'object') return;
        if (Array.isArray(node)) return node.forEach(walk);
        const n = node as Record<string, any>;
        if (n.id === id) hit = n;
        else Object.values(n).forEach(walk);
      };
      walk(form);
      return hit ? !/"name":\s*"(email|name|nickname|password|password_confirmation|current_password|password_expiry_policy|role_ids|status|mobile|phone|homepage|zipcode|address|address_detail|country|language|timezone|signature|bio|admin_memo)"/.test(JSON.stringify(hit)) : true;
    });

    expect(missing).toEqual([]);
  });

  // @scenario surface=admin_user_detail_form
  // @effects admin_user_screens_have_no_tailwind_breakpoint_layout_branch
  it('두 화면 모두 md:/lg: 로 구조를 가르지 않는다 (§6-0)', () => {
    const offenders = [
      ['admin_user_detail.json', detail],
      ['admin_user_form.json', form],
    ].filter(([, layout]) => /"className":\s*"[^"]*\b(?:sm|md|lg|xl):(?:grid-cols|flex-|hidden|block)/.test(JSON.stringify(layout)))
      .map(([file]) => file);

    expect(offenders).toEqual([]);
  });
});
