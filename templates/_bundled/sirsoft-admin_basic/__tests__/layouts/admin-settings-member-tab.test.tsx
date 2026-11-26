/**
 * @file admin-settings-member-tab.test.tsx
 * @description 환경설정 「회원」 탭의 뼈대와 렌더 계약
 *
 * 탭 목록에 「회원」이 보안 뒤에 들어가 16개가 되고, 하위 탭 막대는 「가입 항목」「표시 이름」「닉네임 정책」 셋이다
 * (「표시 이름」 구획의 내용은 admin-settings-member-display-name 테스트가 잠근다). 항목 정의는 회원 탭에서만 받는다. 화면에 번역 키가 그대로 보이면 안 되고,
 * 조회 권한만 있는 관리자에게는 스위치, 손잡이, 「항목 추가」가 잠기고 행 단추는 [보기]만 나온다.
 *
 * 레이아웃 JSON 은 파일에서 그대로 읽어 렌더한다(사본 금지).
 */

import * as fs from 'fs';
import * as path from 'path';
import { describe, it, expect, afterEach, beforeEach, vi } from 'vitest';
import { fireEvent, screen, within } from '@testing-library/react';
import { createLayoutTest } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import {
  buildLayout,
  collect,
  fieldDefs,
  koTranslations,
  loadLayout,
  NICKNAME_LOCK,
  setupRegistry,
  settle,
  stubFetch,
  stubRefetch,
  watch,
  type Json,
} from './support/memberTabHarness';

const settings = loadLayout('admin_settings.json');
const PARTIALS_DIR = path.resolve(__dirname, '../../layouts/partials/admin_settings');
const LANG_DIR = path.resolve(__dirname, '../../lang/partial');

/** 표의 항목 행 — 정렬 목록을 단 노드(tbody)는 자기 자신을 그리지 않고 행 래퍼만 놓는다 */
const ROWS = 'tr[data-sortable-item]';

let active: ReturnType<typeof createLayoutTest> | null = null;

/** 회원 탭 조각을 렌더한다. */
async function renderTab(opts: { defs?: Json; tab?: string; local?: Json; global?: Json; partial?: string; recompute?: Json } = {}) {
  const initialData: Json = {
    memberFieldDefs: opts.defs ?? fieldDefs(),
    memberNicknameRecompute: { data: opts.recompute ?? { status: 'idle', processed: 0, total: 0 } },
  };
  active = createLayoutTest(buildLayout(opts.partial ?? '_tab_member.json') as any, {
    componentRegistry: setupRegistry(),
    translations: koTranslations(),
    locale: 'ko',
    queryParams: { tab: opts.tab ?? 'member' },
    initialState: {
      _local: opts.local ?? { form: { member: { nickname_unique: false, nickname_banned_words: [], nickname_change_interval_days: 0 } } },
      _global: { settingsAbilities: { can_update: true }, ...(opts.global ?? {}) },
    },
    initialData,
  } as any);
  const result = await active.render();
  watch(active);

  return { t: active, container: result.container };
}

// 단추를 여러 번 누르고 그때마다 액션 사슬이 끝나기를 기다린다 — 다른 테스트 파일과 함께 돌 때 기본 5초로는 모자란다
vi.setConfig({ testTimeout: 30_000 });

beforeEach(() => {
  // 식 안의 $t() 는 런타임처럼 앱 설정에서 템플릿과 로케일을 찾는다
  (window as any).__templateApp = { getConfig: () => ({ templateId: 'test-template', locale: 'ko' }) };
});

afterEach(() => {
  active?.cleanup();
  active = null;
  watch(null);
  vi.unstubAllGlobals();
  delete (window as any).__templateApp;
});

describe('환경설정 회원 탭 — 뼈대', () => {
  // @scenario case=tab_registered
  // @effects member_tab_listed_after_security
  it('탭 목록은 16개이고 「회원」이 보안 바로 뒤에 있다', () => {
    const nav = collect(settings.slots, (n) => n.id === 'tab_navigation')[0];
    const ids: string[] = nav.props.tabs.map((t: Json) => t.id);

    expect(ids).toHaveLength(16);
    expect(ids.indexOf('member')).toBe(ids.indexOf('security') + 1);
    expect(nav.props.tabs.find((t: Json) => t.id === 'member')).toMatchObject({
      href: '/admin/settings?tab=member',
      label: '$t:admin.settings.tabs.member',
    });

    // 본문 조각도 보안 다음에 끌어온다
    const includes: string[] = collect(settings.slots, (n) => typeof n.partial === 'string').map((n) => n.partial);
    const at = includes.indexOf('partials/admin_settings/_tab_member.json');
    expect(at).toBeGreaterThan(-1);
    expect(includes[at - 1]).toBe('partials/admin_settings/_tab_security.json');
  });

  // @scenario case=sub_tabs
  // @effects member_sub_tabs_are_fields_and_nickname
  it('하위 탭 막대는 「가입 항목」「표시 이름」「닉네임 정책」 셋이고 member_section_ 구획으로 스크롤한다', () => {
    const sub = collect(settings.slots, (n) => n.id === 'member_sub_tab_navigation')[0];

    expect(sub.name).toBe('TabNavigationScroll');
    expect(String(sub.if)).toContain("'member'");
    expect(sub.props.sectionIdPrefix).toBe('member_section_');
    expect(sub.props.tabs.map((t: Json) => t.id)).toEqual(['fields', 'display_name', 'nickname']);

    // 탭 id 마다 같은 이름의 구획이 탭 본문에 있다
    const tab = loadLayout('partials/admin_settings/_tab_member.json');
    for (const id of ['fields', 'display_name', 'nickname']) {
      expect(collect(tab, (n) => n.id === `member_section_${id}`), `member_section_${id}`).toHaveLength(1);
    }
    // 구획 순서도 탭 순서와 같다(가입 항목, 표시 이름, 닉네임 정책)
    const sections: string[] = collect(tab, (n) => typeof n.id === 'string' && n.id.startsWith('member_section_')).map((n) => n.id);
    expect(sections).toEqual(['member_section_fields', 'member_section_display_name', 'member_section_nickname']);
    expect(fs.existsSync(path.join(PARTIALS_DIR, '_tab_member_display_name.g7.html'))).toBe(true);
  });

  // @scenario case=datasource_gate
  // @effects member_field_defs_fetched_only_on_member_tab
  it('항목 정의는 회원 탭에서만 자동으로 받고, 도착하면 표의 저장 중 표식과 임시 순서를 지운다', () => {
    const source = settings.data_sources.find((s: Json) => s.id === 'memberFieldDefs');

    expect(source.endpoint).toBe('/api/admin/field-sets/core.user/fields');
    expect(source.auto_fetch).toBe(true);
    expect(source.auth_required).toBe(true);
    expect(source.if).toBe("{{(query.tab || _global.activeSettingsTab || 'general') === 'member'}}");
    expect(settings.transition_overlay.wait_for).toContain('memberFieldDefs');
    // 응답이 없을 때 표가 「바꿀 수 있음」으로 그려지지 않는다
    expect(source.fallback.data.abilities.can_update).toBe(false);
    expect(source.fallback.data.fields).toEqual([]);

    for (const key of ['onSuccess', 'onError']) {
      const clears = collect(source[key], (n) => n.handler === 'setState');
      expect(clears.some((a) => a.params.target === 'global' && a.params.memberFieldBusy === null && a.params.memberFieldOrder === null), key).toBe(true);
    }
  });

  // @scenario case=modals_registered
  // @effects member_field_modals_registered
  it('항목 창 셋(추가와 수정, 삭제, 필수 확인)이 화면에 등록돼 있다', () => {
    const partials: string[] = settings.modals.map((m: Json) => String(m.partial ?? ''));

    for (const [file, id] of [
      ['_modal_member_field_form', 'member_field_form_modal'],
      ['_modal_member_field_delete', 'member_field_delete_modal'],
      ['_modal_member_field_required', 'member_field_required_modal'],
    ]) {
      expect(partials, file).toContain(`partials/admin_settings/${file}.json`);
      expect(loadLayout(`partials/admin_settings/${file}.json`).id).toBe(id);
    }
  });

  // @scenario case=i18n_parity
  // @effects member_tab_keys_exist_in_ko_and_en
  it('회원 탭 번역 키는 ko 와 en 이 같은 모양이고 조각이 쓰는 키가 모두 있다', () => {
    const read = (locale: string) => JSON.parse(fs.readFileSync(path.join(LANG_DIR, locale, 'admin.json'), 'utf-8'));
    const flatten = (node: any, prefix = ''): string[] =>
      node && typeof node === 'object'
        ? Object.entries(node).flatMap(([k, v]) => flatten(v, prefix ? `${prefix}.${k}` : k))
        : [prefix];
    const ko = read('ko');
    const en = read('en');

    expect(typeof ko.settings.tabs.member).toBe('string');
    expect(typeof en.settings.tabs.member).toBe('string');
    const koKeys = flatten(ko.settings.member).sort();
    expect(koKeys.length).toBeGreaterThan(100);
    // en 에만 있는 단수형(`키_one`)은 빼고 견준다 — 번역 엔진이 count 가 1 일 때 고르는 영어 전용 변형이다
    expect(flatten(en.settings.member).filter((k) => !k.endsWith('_one')).sort()).toEqual(koKeys);

    // 조각이 쓰는 `admin.settings.member.*` 키(고정 키)는 모두 번역에 있다. 끝이 식으로 이어지는 키는 묶음이 있어야 한다
    const known = new Set(koKeys.map((k) => `admin.settings.member.${k}`));
    const files = fs.readdirSync(PARTIALS_DIR).filter((f) => /^_(tab|modal)_member.*\.json$/.test(f));
    expect(files).toHaveLength(8);
    let usedTotal = 0;
    for (const file of files) {
      const text = fs.readFileSync(path.join(PARTIALS_DIR, file), 'utf-8');
      const used = [...text.matchAll(/\$t(?::|\(')(admin\.settings\.member\.[A-Za-z0-9_.]+)/g)].map((m) => m[1]);
      usedTotal += used.length;
      for (const key of used) {
        const ok = key.endsWith('.') ? [...known].some((k) => k.startsWith(key)) : known.has(key);
        expect(ok, `${file}: ${key}`).toBe(true);
      }
    }
    expect(usedTotal).toBeGreaterThan(150);
  });
});

describe('환경설정 회원 탭 — 가입 항목 표 렌더', () => {
  // @scenario case=render_rows
  // @effects member_fields_table_renders_without_raw_keys
  it('회원 탭에서 항목 행과 안내를 그리고 번역 키를 그대로 내보이지 않는다', async () => {
    const { container } = await renderTab();

    expect(container.querySelector('#tab_content_member')).not.toBeNull();
    expect(container.querySelector('#member_section_fields')).not.toBeNull();
    expect(container.querySelector('#member_section_nickname')).not.toBeNull();

    const rows = container.querySelectorAll(ROWS);
    expect(rows).toHaveLength(6);
    expect(within(rows[0] as HTMLElement).getByText('이름')).toBeInTheDocument();
    expect(within(rows[4] as HTMLElement).getByText('직업')).toBeInTheDocument();

    // 표 머리의 즉시 저장 안내와 고정 항목 줄
    expect(screen.getByText('이 표의 변경(사용, 필수, 가입 표시, 순서)은 바로 저장됩니다.')).toBeInTheDocument();
    expect(screen.getByText('고정: 이메일, 비밀번호, 약관 동의 (항상 사용)')).toBeInTheDocument();
    // 출처: 기본 / 확장 식별자 / 추가
    expect(within(rows[0] as HTMLElement).getByText('기본')).toBeInTheDocument();
    expect(within(rows[3] as HTMLElement).getByText('sirsoft-ecommerce')).toBeInTheDocument();
    expect(within(rows[4] as HTMLElement).getByText('추가')).toBeInTheDocument();
    // 범위
    expect(within(rows[0] as HTMLElement).getByText('본인 수정')).toBeInTheDocument();

    // 번역 키나 풀리지 않은 식이 화면에 남지 않는다
    const text = container.textContent ?? '';
    expect(text).not.toMatch(/\$t[:(]/);
    expect(text).not.toMatch(/admin\.settings\./);
    expect(text).not.toMatch(/\{\{|\}\}/);
    // 수정본 유지 경고는 응답이 알릴 때만 나온다
    expect(container.querySelector('#member_fields_layout_notice')).toBeNull();
  });

  // @scenario case=other_tab_hidden
  // @effects member_tab_body_gated_by_tab
  it('다른 탭에서는 회원 탭 본문을 그리지 않는다', async () => {
    const { container } = await renderTab({ tab: 'general' });

    expect(container.querySelector('#tab_content_member')).toBeNull();
    expect(container.querySelectorAll(ROWS)).toHaveLength(0);
  });

  // @scenario case=row_buttons
  // @effects declared_rows_get_settings_custom_rows_get_edit_delete
  it('선언 항목은 [설정]만, 운영자 항목은 [수정][삭제]가 나온다', async () => {
    await renderTab();

    expect(screen.getByTestId('member-field-table-open-name').textContent).toBe('설정');
    expect(screen.getByTestId('member-field-table-open-preferred_currency').textContent).toBe('설정');
    expect(screen.queryByTestId('member-field-table-delete-name')).toBeNull();
    expect(screen.queryByTestId('member-field-table-delete-preferred_currency')).toBeNull();

    expect(screen.getByTestId('member-field-table-open-job').textContent).toBe('수정');
    expect(screen.getByTestId('member-field-table-delete-job').textContent).toBe('삭제');
  });

  // @scenario case=locked_attributes
  // @effects locked_switches_disabled_with_reason
  it('선언한 쪽이 잠근 속성은 스위치가 잠기고 사유가 보인다', async () => {
    const { container } = await renderTab();

    expect(screen.getByTestId('member-field-table-enabled-nickname')).toBeDisabled();
    expect(screen.getByTestId('member-field-table-required-nickname')).toBeDisabled();
    // 잠그지 않은 속성과 다른 행은 열려 있다
    expect(screen.getByTestId('member-field-table-show_on_signup-nickname')).not.toBeDisabled();
    expect(screen.getByTestId('member-field-table-enabled-name')).not.toBeDisabled();

    const row = container.querySelectorAll(ROWS)[1] as HTMLElement;
    // 같은 사유는 한 번만 적는다
    expect(within(row).getAllByText(NICKNAME_LOCK)).toHaveLength(1);
    expect(row.querySelectorAll(`[role="img"][aria-label="${NICKNAME_LOCK}"]`)).toHaveLength(2);
  });

  // @scenario case=unavailable_type
  // @effects unavailable_type_row_shows_badge_instead_of_switches
  it('유형을 제공한 확장이 꺼진 항목은 스위치 대신 「사용 불가」 배지를 보인다', async () => {
    const { container } = await renderTab();
    const row = container.querySelectorAll(ROWS)[5] as HTMLElement;

    expect(within(row).getByText('사용 불가')).toBeInTheDocument();
    expect(row.querySelectorAll('[role="switch"]')).toHaveLength(0);
    // 설정을 고칠 수 없으니 여는 단추는 없고, 운영자 항목이라 지울 수는 있다
    expect(screen.queryByTestId('member-field-table-open-color')).toBeNull();
    expect(screen.getByTestId('member-field-table-delete-color')).toBeInTheDocument();
  });

  // @scenario case=layout_modified_notice
  // @effects signup_layout_modified_notice_shown
  it('가입 화면이 수정본으로 유지되면 표 위에 경고를 띄운다', async () => {
    const { container } = await renderTab({ defs: fieldDefs({ notices: { signup_layout_modified: true } }) });
    const notice = container.querySelector('#member_fields_layout_notice') as HTMLElement;

    expect(notice).not.toBeNull();
    expect(notice.getAttribute('role')).toBe('alert');
    expect(notice.textContent).toContain('가입 화면 레이아웃이 수정본으로 유지되고 있어 새 항목이 보이지 않을 수 있습니다.');
  });

  // @scenario case=empty_custom
  // @effects empty_custom_fields_message
  it('운영자가 추가한 항목이 없으면 안내 문구를 보인다', async () => {
    const defs = fieldDefs();
    defs.data.fields = defs.data.fields.filter((f: Json) => !f.is_custom);
    const { container } = await renderTab({ defs });

    expect(container.querySelectorAll(ROWS)).toHaveLength(4);
    expect(container.querySelector('#member_fields_empty_custom')?.textContent).toBe('추가한 항목이 없습니다. 「항목 추가」로 가입 항목을 만드세요.');
  });
});

describe('환경설정 회원 탭 — 읽기 전용(조회 권한만)', () => {
  // @scenario case=readonly
  // @effects readonly_controls_disabled_view_only
  it('스위치, 손잡이, 「항목 추가」가 잠기고 행 단추는 [보기]만 나온다', async () => {
    const { container } = await renderTab({ defs: fieldDefs({ abilities: { can_update: false } }), global: { settingsAbilities: { can_update: false } } });

    const switches = container.querySelectorAll(`${ROWS} [role="switch"]`);
    expect(switches.length).toBe(15);
    switches.forEach((s) => expect(s).toBeDisabled());

    // 「항목 추가」: disabled + 흐림 + 금지 커서 클래스
    const add = screen.getByTestId('member-field-add');
    expect(add).toBeDisabled();
    expect(add.className).toContain('disabled:opacity-50');
    expect(add.className).toContain('disabled:cursor-not-allowed');

    // 손잡이: 끌 수 있는 손잡이는 없고 잠긴 모양만 있다
    expect(container.querySelectorAll('[data-drag-handle]')).toHaveLength(0);
    const handle = screen.getByTestId('member-field-table-handle-disabled-name');
    expect(handle).toBeDisabled();
    expect(handle.className).toContain('opacity-50');
    expect(handle.className).toContain('cursor-not-allowed');

    // 행 단추는 [보기]만
    expect(screen.getByTestId('member-field-table-open-name').textContent).toBe('보기');
    expect(screen.getByTestId('member-field-table-open-job').textContent).toBe('보기');
    expect(container.querySelectorAll('[data-testid^="member-field-table-delete-"]')).toHaveLength(0);
  });

  // @scenario case=readonly
  // @effects readonly_view_opens_modal_read_only
  it('[보기]는 창을 읽기 전용으로 연다', async () => {
    const { t } = await renderTab({ defs: fieldDefs({ abilities: { can_update: false } }) });
    stubFetch([{ method: 'GET', endsWith: '/fields/5', body: { success: true, data: { field: { ...fieldDefs().data.fields[4], values_count: 3 }, fields_version: 'v1' } } }]);

    fireEvent.click(screen.getByTestId('member-field-table-open-job'));
    await settle();

    const modal = t.getState()._global.member_field_form_modal;
    expect(t.getModalStack()).toEqual(['member_field_form_modal']);
    expect(modal.readOnly).toBe(true);
    expect(modal.isNew).toBe(false);
    expect(modal.step).toBe('form');
    expect(modal.loading).toBe(false);
    expect(modal.form.values_count).toBe(3);
  });

  // @scenario case=editable
  // @effects editable_controls_enabled
  it('수정 권한이 있으면 손잡이와 「항목 추가」가 열려 있다', async () => {
    const { container } = await renderTab();

    expect(screen.getByTestId('member-field-add')).not.toBeDisabled();
    expect(container.querySelectorAll(`${ROWS} [data-drag-handle]`)).toHaveLength(6);
    expect(screen.queryByTestId('member-field-table-handle-disabled-name')).toBeNull();
  });
});

describe('환경설정 회원 탭 — 좁은 화면 카드', () => {
  // @scenario case=cards
  // @effects cards_mirror_table_wiring_with_move_buttons
  it('카드는 표와 같은 스위치와 단추에 위로/아래로 단추를 더한다', async () => {
    const { container } = await renderTab({ partial: '_tab_member_fields_cards.json' });

    expect(container.querySelectorAll('[data-testid^="member-field-card-enabled-"]')).toHaveLength(5);
    expect(screen.getByTestId('member-field-card-open-name').textContent).toBe('설정');
    expect(screen.getByTestId('member-field-card-delete-job')).toBeInTheDocument();
    // 맨 위는 위로, 맨 아래는 아래로가 잠긴다
    expect(screen.getByTestId('member-field-card-up-name')).toBeDisabled();
    expect(screen.getByTestId('member-field-card-down-name')).not.toBeDisabled();
    expect(screen.getByTestId('member-field-card-down-color')).toBeDisabled();
    expect(screen.getByTestId('member-field-card-up-name').getAttribute('aria-label')).toBe('이름 위로');

    // 표 조각은 768px 미만에서만 카드 조각을 끌어온다
    const table = loadLayout('partials/admin_settings/_tab_member_fields.json');
    const desktop = collect(table, (n) => n.id === 'member_fields_table_desktop')[0];
    const mobile = collect(table, (n) => n.id === 'member_fields_cards_mobile')[0];
    expect(desktop.responsive.mobile.if).toBe('{{false}}');
    expect(mobile.if).toBe('{{false}}');
    expect(mobile.responsive.mobile.if).toBe('{{true}}');
    expect(collect(mobile, (n) => n.partial === 'partials/admin_settings/_tab_member_fields_cards.json')).toHaveLength(1);
  });
});

describe('환경설정 회원 탭 — 닉네임 정책', () => {
  // @scenario case=nickname_form_fields
  // @effects nickname_policy_bound_to_member_form
  it('금지어와 변경 주기는 설정 폼의 member 칸에 묶이고, 읽기 전용이면 잠긴다', async () => {
    const nickname = loadLayout('partials/admin_settings/_tab_member_nickname.json');
    const bound = collect(nickname, (n) => n.name !== 'Icon' && typeof n.props?.name === 'string');
    const named = bound.map((n) => n.props.name);

    expect(named.sort()).toEqual(['member.nickname_banned_words', 'member.nickname_change_interval_days']);
    // 표시 이름 키는 이 화면이 건드리지 않는다
    expect(JSON.stringify(nickname)).not.toContain('display_name_');
    for (const node of bound) {
      expect(node.props.disabled).toBe('{{_computed.isReadOnly}}');
    }

    // 저장은 화면 하단 「저장」이 지금 탭 몫을 통째로 보낸다 — member 탭은 저장하지 않는 지시 값(닉네임 입력 요구)만 가려 싣는 자기 갈래를 탄다
    const save = collect(settings.slots, (n) => n.id === 'save_button')[0];
    const call = collect(save, (n) => n.handler === 'apiCall' && n.target === '/api/admin/settings')[0];
    expect(call.params.body).toContain('return { _tab: tab, [tab]: unlocked(tab, form[tab]) }');
    expect(call.params.body).toContain("if (tab === 'member') { const member = Object.fromEntries(Object.entries(unlocked('member', form.member))");
    const refetches = collect(call.onSuccess, (n) => n.handler === 'refetchDataSource' && String(n.if ?? '').includes("'member'")).map((n) => n.params.dataSourceId);
    expect(refetches.sort()).toEqual(['memberDisplayNameOptions', 'memberFieldDefs', 'memberNicknameEmptyCount', 'memberNicknameRecompute']);
  });

  // @scenario case=nickname_unique_no_duplicates
  // @effects nickname_unique_turns_on_without_confirm_when_no_duplicates
  it('겹치는 닉네임이 없으면 확인 없이 폼에 켜고 「변경 있음」을 세운다', async () => {
    const { t, container } = await renderTab();
    const requests = stubFetch([{ method: 'GET', endsWith: '/member-fields/nickname-duplicates', body: { success: true, data: { duplicate_nicknames: 0, duplicate_members: 0 } } }]);

    fireEvent.click(screen.getByTestId('member-nickname-unique'));
    await settle();

    expect(requests.map((r) => `${r.method} ${r.url}`)).toEqual(['GET /api/admin/member-fields/nickname-duplicates']);
    const local = t.getState()._local;
    expect(local.form.member.nickname_unique).toBe(true);
    expect(local.hasChanges).toBe(true);
    expect(local.memberNicknameChecking).toBe(false);
    await t.rerender();
    expect(container.querySelector('#member_nickname_duplicates_notice')).toBeNull();
  });

  // @scenario case=nickname_unique_with_duplicates
  // @effects nickname_unique_asks_before_turning_on_when_duplicates_exist
  it('겹치는 닉네임이 있으면 종 수와 회원 수를 보이고, 「그래도 켜기」를 눌러야 폼에 켠다', async () => {
    const { t, container } = await renderTab();
    stubFetch([{ method: 'GET', endsWith: '/member-fields/nickname-duplicates', body: { success: true, data: { duplicate_nicknames: 2, duplicate_members: 5 } } }]);

    fireEvent.click(screen.getByTestId('member-nickname-unique'));
    await settle();

    // 아직 켜지 않았고 저장 단추도 열리지 않는다
    expect(t.getState()._local.form.member.nickname_unique).toBe(false);
    expect(t.getState()._local.hasChanges).toBeUndefined();
    await t.rerender();
    expect(screen.getByTestId('member-nickname-duplicates-message').textContent).toBe('지금 같은 닉네임을 쓰는 회원이 있습니다. 겹치는 닉네임 2종, 회원 5명.');
    expect(screen.getByTestId('member-nickname-duplicates-link').getAttribute('href')).toBe('/admin/users?nickname_duplicate=1');

    fireEvent.click(screen.getByTestId('member-nickname-duplicates-confirm'));
    await settle();

    expect(t.getState()._local.form.member.nickname_unique).toBe(true);
    expect(t.getState()._local.hasChanges).toBe(true);
    await t.rerender();
    expect(container.querySelector('#member_nickname_duplicates_notice')).toBeNull();
  });

  // @scenario case=nickname_unique_cancel
  // @effects nickname_unique_cancel_keeps_form
  it('안내에서 취소하면 폼을 건드리지 않는다', async () => {
    const { t } = await renderTab();
    stubFetch([{ method: 'GET', endsWith: '/member-fields/nickname-duplicates', body: { success: true, data: { duplicate_nicknames: 1, duplicate_members: 2 } } }]);

    fireEvent.click(screen.getByTestId('member-nickname-unique'));
    await settle();
    await t.rerender();
    fireEvent.click(screen.getByTestId('member-nickname-duplicates-cancel'));
    await settle();

    expect(t.getState()._local.form.member.nickname_unique).toBe(false);
    expect(t.getState()._local.hasChanges).toBeUndefined();
    expect(t.getState()._local.memberNicknameDuplicates).toBeNull();
  });

  // @scenario case=nickname_unique_off
  // @effects nickname_unique_turns_off_without_request
  it('끌 때는 요청 없이 폼에 담는다', async () => {
    const { t } = await renderTab({ local: { form: { member: { nickname_unique: true, nickname_banned_words: [], nickname_change_interval_days: 0 } } } });
    const requests = stubFetch();

    fireEvent.click(screen.getByTestId('member-nickname-unique'));
    await settle();

    expect(requests).toHaveLength(0);
    expect(t.getState()._local.form.member.nickname_unique).toBe(false);
    expect(t.getState()._local.hasChanges).toBe(true);
  });

  // @scenario case=nickname_recompute_progress
  // @effects nickname_recompute_progress_shown_while_running
  it('중복 검사 준비가 도는 동안 진행 막대를, 끝나면 완료 문구를 보인다', async () => {
    const running = await renderTab({ recompute: { status: 'running', processed: 30, total: 120 } });
    const bar = running.container.querySelector('#member_nickname_recompute [role="progressbar"]') as HTMLElement;

    expect(bar.getAttribute('aria-valuenow')).toBe('25');
    expect(screen.getByTestId('member-nickname-recompute-count').textContent).toBe('30 / 120명');
    expect(screen.queryByTestId('member-nickname-recompute-done')).toBeNull();
    running.t.cleanup();

    const done = await renderTab({ recompute: { status: 'done', processed: 120, total: 120 } });
    expect(screen.getByTestId('member-nickname-recompute-done').textContent).toBe('중복 검사 준비가 끝났습니다. 회원 120명을 확인했습니다.');
    expect(done.container.querySelector('[role="progressbar"]')).toBeNull();
    done.t.cleanup();

    const idle = await renderTab();
    expect(idle.container.querySelector('#member_nickname_recompute')).toBeNull();

    // 진행 상태는 회원 탭에서만 받고, 도는 동안만 3초마다 다시 받는다
    const source = settings.data_sources.find((s: Json) => s.id === 'memberNicknameRecompute');
    expect(source.endpoint).toBe('/api/admin/member-fields/nickname-recompute-status');
    expect(String(source.if)).toContain("'member'");
    const start = collect(source.onSuccess, (n) => n.handler === 'startInterval')[0];
    const stop = collect(source.onSuccess, (n) => n.handler === 'stopInterval')[0];
    expect(start.if).toBe("{{response?.data?.data?.status === 'running'}}");
    expect(stop.if).toBe("{{response?.data?.data?.status !== 'running'}}");
    expect(start.params.id).toBe(stop.params.id);
  });
});

// 재조회 도구는 이 파일에서 쓰지 않지만, 표의 요청 배선은 admin-settings-member-fields-immediate-save 가 잰다
void stubRefetch;
