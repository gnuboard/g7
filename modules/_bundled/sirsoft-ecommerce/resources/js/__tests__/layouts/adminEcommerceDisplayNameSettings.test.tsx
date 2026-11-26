/**
 * @file adminEcommerceDisplayNameSettings.test.tsx
 * @description 쇼핑몰 설정의 기능별 「작성자 표시 이름」 칸 두 곳 — 리뷰 설정 탭(후기)과 기본 정보 탭의 문의 설정 카드(문의)
 *
 * 방식은 「상위 영역 따름 / 특정 종류 고정 / 회원이 고른 값」이고 종류 칸은 고정일 때만 나온다. 값은 설정 폼(`form.display_name`)에
 * 담겨 하단 [저장]으로 나간다(주문서 항목 표와 달리 즉시 저장이 아니다). 종류 목록과 지금 적용되는 값은 설정 응답의 읽기 전용
 * `display_name_options` 에서 읽고 저장에 싣지 않는다.
 *
 * 두 칸과 하단 저장 단추의 노드를 굽기 산출 JSON 에서 그대로 꺼내 한 화면에 그리고, 선택칸을 실제로 바꾸고 저장을 눌러
 * 엔진이 보낸 요청을 가짜 fetch 로 받는다.
 */

import { describe, it, expect, afterEach, beforeEach, vi } from 'vitest';
import { fireEvent } from '@testing-library/react';
import { createLayoutTest } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import {
  buildSettingsLayout,
  lines,
  loadLayout,
  loadSettingsPartial,
  nodeById,
  setupRegistry,
  settle,
  stubFetch,
  stubRefetch,
  translations,
  watch,
  type FetchRoute,
  type Json,
} from './support/checkoutFieldsHarness';

const settingsLayout = loadLayout('admin_ecommerce_settings.json');
const reviewTab = loadSettingsPartial('_tab_review_settings.json');
const basicTab = loadSettingsPartial('_tab_basic_info.json');
const reviewCard = nodeById(reviewTab, 'review_display_name_card');
const inquiryField = nodeById(basicTab, 'inquiry_display_name_field');
const saveButton = nodeById(settingsLayout, 'save_button');

const SETTINGS = '/api/modules/sirsoft-ecommerce/admin/settings';
const KIND_REQUIRED = '고정할 표시 이름 종류를 골라 주세요.';

/** 설정 조회 응답 표본. 후기는 상위 영역을 따르고, 문의는 가린 이름으로 고정돼 있다. */
function settingsData(overrides: Json = {}): Json {
  return {
    abilities: { can_update: true },
    basic_info: { shop_name: '테스트몰' },
    review_settings: { write_deadline_days: 30, max_images: 5, max_image_size_mb: 5 },
    order_settings: { auto_cancel_expired: true },
    inquiry: { board_slug: 'qna' },
    display_name: { review: { mode: 'inherit', kind: null }, inquiry: { mode: 'fixed', kind: 'masked_name' } },
    display_name_options: {
      kinds: [
        { key: 'name', label: '이름', preview: '홍길동', requires_nickname: false },
        { key: 'masked_name', label: '가린 이름', preview: '홍*동', requires_nickname: false },
        { key: 'nickname', label: '닉네임', preview: '길동이', requires_nickname: true },
        { key: 'company', label: '회사명', preview: null, requires_nickname: false },
      ],
      effective: {
        review: { mode: 'fixed', kind: 'name', kind_label: '이름', source_label: '사이트 기본' },
        inquiry: { mode: 'fixed', kind: 'masked_name', kind_label: '가린 이름', source_label: '상품 문의' },
      },
    },
    ...overrides,
  };
}

let active: ReturnType<typeof createLayoutTest> | null = null;

interface Rendered {
  t: ReturnType<typeof createLayoutTest>;
  container: HTMLElement;
  requests: ReturnType<typeof stubFetch>;
  refetched: string[];
  /** 선택칸을 바꾸고 액션이 끝난 뒤 다시 그린다 */
  choose: (id: string, value: string) => Promise<void>;
  /** 하단 [저장]을 누르고 액션이 끝난 뒤 다시 그린다 */
  save: () => Promise<void>;
}

/** 표시 이름 칸 둘과 하단 저장 단추를 렌더한다. */
async function renderFields(opts: { tab?: string; data?: Json; routes?: FetchRoute[]; local?: Json } = {}): Promise<Rendered> {
  const data = opts.data ?? settingsData();
  active = createLayoutTest(buildSettingsLayout([reviewCard, inquiryField, saveButton]) as any, {
    componentRegistry: setupRegistry(),
    translations: translations('ko'),
    locale: 'ko',
    queryParams: { tab: opts.tab ?? 'review_settings' },
    initialState: { _local: { form: JSON.parse(JSON.stringify(data)), ...(opts.local ?? {}) }, _global: {} },
    initialData: { settings: { data } },
  } as any);
  const t = active;
  const result = await t.render();
  watch(t);
  const requests = stubFetch(opts.routes ?? []);
  const refetched = stubRefetch();
  const container = result.container;

  return {
    t,
    container,
    requests,
    refetched,
    choose: async (id, value) => {
      fireEvent.change(container.querySelector(`#${id}`)!, { target: { value } });
      await settle();
      await t.rerender();
    },
    save: async () => {
      fireEvent.click(container.querySelector('#save_button')!);
      await settle();
      await t.rerender();
    },
  };
}

const textOf = (el: Element | null) => (el?.textContent ?? '').replace(/\s+/g, ' ').trim();
const optionsOf = (el: Element | null) => Array.from(el?.querySelectorAll('option') ?? []).map((o) => [o.getAttribute('value'), o.textContent]);

vi.setConfig({ testTimeout: 30_000 });

beforeEach(() => {
  (window as any).__templateApp = { getConfig: () => ({ templateId: 'test-template', locale: 'ko' }) };
});

afterEach(() => {
  active?.cleanup();
  active = null;
  watch(null);
  vi.unstubAllGlobals();
  delete (window as any).__templateApp;
});

describe('기능별 작성자 표시 이름 — 방식에 따른 종류 칸', () => {
  // @scenario case=placement
  // @effects display_name_fields_placed_in_review_tab_and_inquiry_card
  it('후기 칸은 리뷰 설정 탭의 별도 카드에, 문의 칸은 기본 정보 탭의 문의 설정 카드 안에 있다', () => {
    const reviewTabRoot = nodeById(reviewTab, 'tab_content_review_settings');
    expect(reviewTabRoot.children.map((c: Json) => c.id)).toEqual(['review_settings_card', 'review_display_name_card']);

    const inquiryCard = nodeById(basicTab, 'inquiry_settings_card');
    expect(inquiryCard.children.map((c: Json) => c.id)).toContain('inquiry_display_name_field');
    // 문의 게시판 설정(확장점)이 있는 그 카드다
    expect(JSON.stringify(inquiryCard)).toContain('inquiry_board_setting');
  });

  // @scenario case=mode_inherit
  // @effects inherit_mode_hides_kind_and_shows_effective_kind
  it('상위 영역을 따르는 칸은 종류 칸이 없고 「지금 적용: ○○」를 곁들인다', async () => {
    const { container } = await renderFields();

    const mode = container.querySelector('#review_display_name_mode') as HTMLSelectElement;
    expect(mode.value).toBe('inherit');
    expect(mode.getAttribute('aria-label')).toBe('후기 작성자 표시 방식');
    expect(optionsOf(mode)).toEqual([
      ['inherit', '상위 영역 따름'],
      ['fixed', '특정 종류 고정'],
      ['member', '회원이 고른 값'],
    ]);
    expect(container.querySelector('#review_display_name_kind')).toBeNull();
    expect(textOf(container.querySelector('#review_display_name_effective'))).toBe('지금 적용: 이름 (사이트 기본 설정 기준)');
  });

  // @scenario case=mode_inherit
  // @effects inherit_mode_shows_member_choice_when_parent_uses_member_mode
  it('따르는 상위 영역이 「회원이 고른 값」이면(사이트 기본의 처음 방식) 종류 대신 그 방식을 알린다', async () => {
    const data = settingsData();
    data.display_name_options.effective.review = { mode: 'member', kind: null, kind_label: null, source_label: '사이트 기본' };
    const { container } = await renderFields({ data });

    expect(textOf(container.querySelector('#review_display_name_effective'))).toBe('지금 적용: 회원이 고른 값 (사이트 기본 설정 기준)');
  });

  // @scenario case=mode_fixed
  // @effects fixed_mode_shows_kind_select_with_previews
  it('고정된 칸은 종류 칸을 보이고, 선택지는 종류 이름과 미리보기다', async () => {
    const { container } = await renderFields();

    expect((container.querySelector('#inquiry_display_name_mode') as HTMLSelectElement).value).toBe('fixed');
    const kind = container.querySelector('#inquiry_display_name_kind') as HTMLSelectElement;
    expect(kind.value).toBe('masked_name');
    expect(kind.getAttribute('aria-label')).toBe('문의 작성자 표시 이름 종류');
    expect(optionsOf(kind)).toEqual([
      ['', '종류를 고르세요'],
      ['name', '이름 (예: 홍길동)'],
      ['masked_name', '가린 이름 (예: 홍*동)'],
      ['nickname', '닉네임 (예: 길동이)'],
      // 미리보기가 없는 종류는 이름만
      ['company', '회사명'],
    ]);
    // 고정일 때는 「지금은 …」 안내가 없다
    expect(container.querySelector('#inquiry_display_name_effective')).toBeNull();
  });

  // @scenario case=mode_change
  // @effects mode_change_writes_form_marks_dirty_and_resets_kind
  it('방식을 고정으로 바꾸면 종류 칸이 나오고, 고정이 아닌 방식으로 바꾸면 종류가 비워진다', async () => {
    const { t, container, choose, requests } = await renderFields();

    await choose('review_display_name_mode', 'fixed');
    expect(t.getState()._local.form.display_name.review).toEqual({ mode: 'fixed', kind: null });
    expect(t.getState()._local.hasChanges).toBe(true);
    expect(container.querySelector('#review_display_name_kind')).not.toBeNull();
    expect(container.querySelector('#review_display_name_effective')).toBeNull();

    await choose('review_display_name_kind', 'nickname');
    expect(t.getState()._local.form.display_name.review).toEqual({ mode: 'fixed', kind: 'nickname' });
    // 닉네임이 필요한 종류를 고르면 안내가 붙는다
    expect(textOf(container.querySelector('#review_display_name_nickname_hint'))).toContain('닉네임이 필요한 종류입니다.');

    await choose('review_display_name_mode', 'member');
    expect(t.getState()._local.form.display_name.review).toEqual({ mode: 'member', kind: null });
    expect(container.querySelector('#review_display_name_kind')).toBeNull();
    expect(container.querySelector('#review_display_name_nickname_hint')).toBeNull();
    // 문의 칸은 그대로다(두 칸이 서로의 값을 건드리지 않는다)
    expect(t.getState()._local.form.display_name.inquiry).toEqual({ mode: 'fixed', kind: 'masked_name' });
    // 칸을 바꾼 것만으로는 아무 요청도 나가지 않는다(하단 [저장]을 따른다)
    expect(requests).toEqual([]);
  });

  // @scenario case=effective_line
  // @effects effective_line_only_for_saved_inherit
  it('「지금은 …」 안내는 저장된 방식도 상위 영역 따름일 때만 보인다(저장 전에 바꾼 방식에는 붙이지 않는다)', async () => {
    const data = settingsData();
    data.display_name_options.effective.review.source_label = null;
    const { container, choose } = await renderFields({ data });

    // 출처가 없으면 종류만 알린다
    expect(textOf(container.querySelector('#review_display_name_effective'))).toBe('지금 적용: 이름');

    // 문의는 가린 이름으로 고정 저장돼 있다 — 상위 영역 따름으로 바꿔도, 서버가 준 「지금 값」은 고정 값이라 안내하지 않는다
    await choose('inquiry_display_name_mode', 'inherit');
    expect(container.querySelector('#inquiry_display_name_kind')).toBeNull();
    expect(container.querySelector('#inquiry_display_name_effective')).toBeNull();
  });

  // @scenario case=options_missing
  // @effects fields_hidden_without_options_and_locked_when_read_only
  it('서버가 종류 목록을 주지 않으면 칸을 그리지 않고, 수정 권한이 없으면 칸이 잠긴다', async () => {
    const without = settingsData();
    delete without.display_name_options;
    const missing = await renderFields({ data: without });
    expect(missing.container.querySelector('#review_display_name_card')).toBeNull();
    expect(missing.container.querySelector('#inquiry_display_name_field')).toBeNull();
    missing.t.cleanup();

    const readOnly = await renderFields({ data: settingsData({ abilities: { can_update: false } }) });
    expect(readOnly.container.querySelector('#review_display_name_mode')).toBeDisabled();
    expect(readOnly.container.querySelector('#inquiry_display_name_mode')).toBeDisabled();
    expect(readOnly.container.querySelector('#inquiry_display_name_kind')).toBeDisabled();
  });
});

describe('기능별 작성자 표시 이름 — 저장', () => {
  // @scenario case=save_review_tab
  // @scenario case=save_basic_tab
  // @effects save_body_carries_display_name_without_options
  it.each([
    ['review_settings', 'review_display_name_mode', 'review_display_name_kind'],
    ['basic_info', 'inquiry_display_name_mode', 'inquiry_display_name_kind'],
  ] as const)('%s 탭의 저장은 display_name 을 같은 모양으로 싣고 읽기 전용 목록은 싣지 않는다', async (tab, modeId, kindId) => {
    const { t, choose, save, requests, refetched } = await renderFields({ tab });

    await choose(modeId, 'fixed');
    await choose(kindId, 'nickname');
    await save();

    expect(lines(requests)).toEqual([`PUT ${SETTINGS}`]);
    const body = requests[0].body;
    expect(body._tab).toBe(tab);
    expect(body.display_name).toEqual(
      tab === 'review_settings'
        ? { review: { mode: 'fixed', kind: 'nickname' }, inquiry: { mode: 'fixed', kind: 'masked_name' } }
        : { review: { mode: 'inherit', kind: null }, inquiry: { mode: 'fixed', kind: 'nickname' } },
    );
    expect(body[tab]).toEqual(settingsData()[tab]);
    expect(Object.keys(body).sort()).toEqual(['_tab', 'display_name', 'inquiry', tab].sort());
    expect(JSON.stringify(body)).not.toContain('display_name_options');
    expect(JSON.stringify(body)).not.toContain('kind_label');
    // 저장 뒤에는 설정을 다시 받아 서버 값으로 되맞춘다
    expect(refetched).toEqual(['settings']);
    expect(t.getState()._local.hasChanges).toBe(false);
  });

  // @scenario case=save_other_tab
  // @effects other_tabs_do_not_send_display_name
  it('표시 이름 칸이 없는 탭의 저장에는 display_name 이 실리지 않는다', async () => {
    const { save, requests } = await renderFields({ tab: 'order_settings', local: { hasChanges: true } });

    await save();

    expect(lines(requests)).toEqual([`PUT ${SETTINGS}`]);
    expect(Object.keys(requests[0].body).sort()).toEqual(['_tab', 'inquiry', 'order_settings']);
  });

  // @scenario case=save_validation
  // @effects kind_validation_error_shown_under_field
  it('고정인데 종류가 비어 서버가 422 를 주면 그 칸 아래에 문구를 보이고 변경은 그대로 남는다', async () => {
    const { t, container, choose, save } = await renderFields({
      routes: [{ method: 'PUT', endsWith: '/admin/settings', status: 422, body: { success: false, message: '입력 값을 확인해 주세요.', errors: { 'display_name.review.kind': [KIND_REQUIRED] } } }],
    });

    await choose('review_display_name_mode', 'fixed');
    await save();

    expect(t.getState()._local.errors).toEqual({ 'display_name.review.kind': [KIND_REQUIRED] });
    expect(textOf(container.querySelector('#review_display_name_kind_error'))).toBe(KIND_REQUIRED);
    expect(container.querySelector('#review_display_name_kind')!.className).toContain('ecommerce:border-red-500');
    // 문의 칸에는 오류가 붙지 않는다
    expect(container.querySelector('#inquiry_display_name_kind_error')).toBeNull();
    expect(t.getState()._local.hasChanges).toBe(true);
    expect(t.getState()._local.form.display_name.review).toEqual({ mode: 'fixed', kind: null });
  });

  // @scenario case=save_validation
  // @effects kind_validation_error_shown_under_field
  it('문의 칸의 422(`display_name.inquiry.kind`)도 그 칸 아래에 그린다', async () => {
    const { container } = await renderFields({ tab: 'basic_info', local: { errors: { 'display_name.inquiry.kind': [KIND_REQUIRED], 'display_name.inquiry.mode': ['방식이 올바르지 않습니다.'] } } });

    expect(textOf(container.querySelector('#inquiry_display_name_kind_error'))).toBe(KIND_REQUIRED);
    expect(textOf(container.querySelector('#inquiry_display_name_mode_error'))).toBe('방식이 올바르지 않습니다.');
    expect(container.querySelector('#review_display_name_kind_error')).toBeNull();
  });
});
