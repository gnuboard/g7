/**
 * 관리자 회원 **상세 ↔ 수정** 항목 정합.
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
 * 이름, 닉네임, 연락처, 위치, 서명, 자기소개와 운영자가 추가한 항목은 두 화면 모두 공용 조각이 그린다 —
 * 수정 화면은 입력 조각(partials/member/_member_fields), 상세는 보기 조각(partials/member/_member_fields_view).
 * 두 조각은 칸에 같은 id(`field_{키}`)를 주므로, 조각 참조를 실제 조각으로 풀어 놓고 종전과 같은 방법으로 순서를 견준다.
 * 어느 묶음을 어느 자리에 놓는지(반복 원본 식)도 두 화면이 같아야 한다.
 *
 * @vitest-environment jsdom
 */
import fs from 'node:fs';
import path from 'node:path';
import { describe, expect, it } from 'vitest';

const root = path.resolve(__dirname, '../../layouts');
const read = (file: string) => JSON.parse(fs.readFileSync(path.join(root, file), 'utf8'));

/** `partial` 참조 노드를 실제 조각으로 바꿔 한 트리로 만든다(설치가 하는 인라인과 같은 모양). */
const resolveIncludes = (node: unknown): unknown => {
  if (Array.isArray(node)) return node.map(resolveIncludes);
  if (!node || typeof node !== 'object') return node;
  const n = node as Record<string, unknown>;
  if (typeof n.partial === 'string' && !n.name) return resolveIncludes(read(n.partial));
  return Object.fromEntries(Object.entries(n).map(([k, v]) => [k, resolveIncludes(v)]));
};

const detailRaw = read('admin_user_detail.json');
const formRaw = read('admin_user_form.json');
const detail = resolveIncludes(detailRaw) as Record<string, any>;
const form = resolveIncludes(formRaw) as Record<string, any>;

/** 회원 항목 조각을 놓은 자리 — 조각 파일과 그 자리의 묶음 반복 원본 식을 나온 순서대로 모은다. */
const memberFieldSlots = (node: unknown, out: Array<{ partial: string; source: string }> = []): Array<{ partial: string; source: string }> => {
  if (!node || typeof node !== 'object') return out;
  if (Array.isArray(node)) {
    node.forEach((n) => memberFieldSlots(n, out));
    return out;
  }
  const n = node as Record<string, any>;
  const child = Array.isArray(n.children) ? n.children.find((c: any) => typeof c?.partial === 'string' && c.partial.startsWith('partials/member/')) : null;
  if (child) out.push({ partial: child.partial, source: String(n.iteration?.source ?? '') });
  Object.values(n).forEach((v) => memberFieldSlots(v, out));
  return out;
};

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
  // 본인 계정의 비밀번호를 바꿀 때만 묻는 확인 칸 — 비밀번호처럼 쓰기 전용이다.
  'field_current_password',
  // 「비밀번호 없이 만들기」(7.2.0 A2t, K11) — 등록 화면에만 있는 생성 방식 선택이라 상세에 짝이 없다.
  'field_create_without_password',
];

/**
 * 수정 화면에서 잠긴 채로 서는 칸 — 시스템이 적는 기록이라 운영자가 정할 값이 아니다.
 * 자리를 비우지 않는 이유는 위 파일 주석에 적었다.
 */
const RECORD_ONLY = [
  'field_uuid',
  // 비밀번호 설정 여부(#40) — 회원이 비밀번호를 정했는지의 기록이다(값은 비밀번호 칸으로 정한다).
  'field_has_password',
  'field_email_verified',
  'field_created_at',
  'field_last_login_at',
  'field_lock_status',
  'field_ip_address',
  'field_withdrawn_at',
  'field_blocked_at',
  'field_dormant_at',
  // 비밀번호 구획 — 변경일·만료 예정·변경 요구·연기 종료는 서버가 적는 기록이다(만료 개별 설정만 고친다).
  'field_password_changed_at',
  'field_password_expires_at',
  'field_password_change_required',
  'field_password_postponed_until',
  'field_terms_consent',
  'field_privacy_consent',
  'field_notifications_total',
  'field_notifications_unread',
  // 최근 검색어(코어 7.1.5) — 회원이 남긴 기록과 회원 본인이 정하는 자동 저장 여부라 운영자가 고칠 값이 아니다.
  'field_search_history_auto_save',
  'field_search_history_site',
];

describe('관리자 회원 상세 ↔ 수정 정합', () => {
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
    // 조각이 그리는 칸도 순서에 들어 있다 — 조각 참조를 풀지 않으면 이 칸들은 보이지 않아 어긋나도 잡히지 않는다
    expect(detailOrder.filter((id) => id === 'field_{{field.key}}')).toHaveLength(6);
  });

  // @scenario surface=admin_user_detail_form
  // @effects admin_user_form_and_detail_place_same_member_field_groups
  it('회원 항목 묶음을 놓은 자리와 순서가 두 화면에서 같다 — 수정은 입력 조각, 상세는 보기 조각', () => {
    const formSlots = memberFieldSlots(formRaw);
    const detailSlots = memberFieldSlots(detailRaw);

    expect(formSlots.map((slot) => slot.partial)).toEqual(Array(6).fill('partials/member/_member_fields.json'));
    expect(detailSlots.map((slot) => slot.partial)).toEqual(Array(6).fill('partials/member/_member_fields_view.json'));
    // 묶음을 고르는 식(어느 묶음을, 어떤 배치로)이 자리마다 같다
    expect(formSlots.map((slot) => slot.source)).toEqual(detailSlots.map((slot) => slot.source));
    const groupOf = (source: string) => /g\.key === '([a-z]+)'/.exec(source)?.[1];
    expect(formSlots.map((slot) => groupOf(slot.source))).toEqual(['basic', 'contact', 'location', 'profile', 'extra', 'extension']);
    // 묶음을 가르는 계산식도 한 벌이다
    expect(formRaw.computed.memberFieldGroups).toBe(detailRaw.computed.memberFieldGroups);
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
    // 회원 항목 칸(`field_{{field.key}}`)은 조각이 항목의 폼 경로(`{{field.name}}`)로 잇는다 — 국가 칸도 입력 컴포넌트가
    // 같은 경로로 값을 낸다(종전에는 change 에서 `form.country` 를 직접 세웠다).
    const editable = sectionsAndFields(form)
      .filter((id) => id.startsWith('field_'))
      .filter((id) => !RECORD_ONLY.includes(id) && id !== 'field_password_group')
      // `field_create_without_password` 는 자동 바인딩을 끄고 change 에서 `form.create_without_password` 를 boolean 으로 직접 세운다
      // (빈 문자열이 실리지 않게, 7.2.0 A2t) — name 규칙 대신 아래에서 그 배선을 따로 확인한다.
      .filter((id) => id !== 'field_create_without_password');

    const passwordlessWiring = JSON.stringify(form);
    expect(passwordlessWiring).toContain('"form.create_without_password":"{{$event.target.checked}}"');

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
      return hit ? !/"name":\s*"(email|password|password_confirmation|current_password|password_expiry_policy|role_ids|status|admin_memo|\{\{field\.name\}\})"/.test(JSON.stringify(hit)) : true;
    });

    expect(missing).toEqual([]);
    // 주소 항목의 세 칸은 항목이 준 부분별 폼 경로로 잇는다
    const partialJson = JSON.stringify(read('partials/member/_member_fields.json'));
    for (const part of ['zipcode', 'address', 'address_detail']) {
      expect(partialJson).toContain(`"name":"{{field.parts?.${part}}}"`);
    }
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
