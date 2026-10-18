/**
 * @file admin-settings-ip-restriction.test.tsx
 * @description 환경설정 > 보안 — IP 접근 제한 카드와 규칙 편집 창 (#636 트랙 2, 디자인 D)
 *
 * 합성 레이아웃이 아니라 **실제 배포 JSON 노드**를 렌더한다 — 카드는
 * `_tab_security_ip_restriction.json` 의 루트를, 폼 자동 바인딩은 `admin_settings.json` 의
 * 실제 래퍼(`settings_content`, dataKey=form)를, 흐림 판정은 같은 레이아웃의 실제 computed
 * (`ipRestrictionEnabled`)를 그대로 가져온다. 사본으로 검증하면 배포 레이아웃이 바뀌어도
 * 초록으로 남는다.
 *
 * 규칙 편집 창은 서버에 저장하지 않고 부모 폼 배열(`$parent._local`)을 **한 번** 고친다. 엔진은
 * 그 대상을 레이아웃 컨텍스트 스택의 부모 슬롯에서 찾으므로, 테스트는 부모 슬롯을 스택에 올려
 * 두고 「적용」이 그 슬롯에 무엇을 썼는지 본다(모달 테두리는 G7Core 전역이 그리므로 떼고 본문과
 * 하단 버튼만 렌더한다).
 *
 * 하네스 사정 두 가지를 감안한다 — ⓐ `_computed` 는 운영에서 TemplateApp 이 계산해 데이터
 * 컨텍스트에 싣지만 이 하네스는 싣지 않는다. 카드의 흐림·잠금이 전부 그 값에 달려 있어 값이
 * 없으면 버튼이 **늘 disabled** 라 클릭 검증이 조용히 통과한다. 그래서 레이아웃이 선언한 그
 * 식(`admin_settings.json` 의 computed)을 같은 바인딩 엔진으로 직접 계산해 싣는다 — 식 자체는
 * 여전히 레이아웃이 정본이다. 그 식 중 `isReadOnly` 는 설정 응답의 `abilities.can_update` 가
 * `true` 일 때만 풀리므로(응답 전·권한 없음은 잠금 — fail-closed) 수정 권한이 있는 관리자로
 * `_global.settingsAbilities` 를 싣고 계산한다. ⓑ `_local` 변경은 곧바로 다시 그려지지만 `_global` 변경은
 * 다음 렌더에 반영되므로, 전역을 보는 단언 앞에는 `rerender()` 를 둔다.
 *
 * @scenario master_toggle=on, master_toggle=off, surface=admin_shell, admin_list=excludes_client, admin_list=contains_client, rule=disabled, rule=blocks_client, rule=none, rule=allows_client, ip_source=forwarded_untrusted
 *
 * @effects settings_card_warns_when_proxy_untrusted, settings_card_mounts_with_current_ip, settings_card_dims_lists_while_toggle_off, settings_card_adds_current_ip_once, settings_card_renders_rules_with_row_toggle_and_delete, settings_card_always_shows_callback_warning_and_rescue_hint, rule_modal_opens_with_copied_rules_and_row_index, settings_card_desktop_and_mobile_rows_share_wiring, rule_modal_requires_pattern_then_ips, rule_modal_commits_once_to_parent_form, rule_modal_edit_replaces_only_that_row, applied_rule_survives_save
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { fireEvent, screen, waitFor } from '@testing-library/react';
import { createLayoutTest } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { actionsOf, callbacksOf } from '@core/template-engine/__tests__/utils/layoutShapeUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';
import { DataBindingEngine } from '@core/template-engine/DataBindingEngine';
import adminSettings from '../../layouts/admin_settings.json';
import cardPartial from '../../layouts/partials/admin_settings/_tab_security_ip_restriction.json';
import modalPartial from '../../layouts/partials/admin_settings/_modal_ip_restriction_rule_form.json';
import adminKo from '../../lang/partial/ko/admin.json';

type Json = Record<string, any>;

const CLIENT_IP = '203.0.113.24';

/**
 * 설정 조회 응답이 실어 온 abilities — 수정 권한이 있는 관리자. `admin_settings` 의 `isReadOnly` 는
 * 이 값의 `can_update === true` 일 때만 풀린다(응답 전에는 잠금).
 */
const SETTINGS_ABILITIES = { can_update: true };

const TestDiv: React.FC<any> = ({ id, className, children }) => (
  <div id={id} className={className}>
    {children}
  </div>
);
const TestSpan: React.FC<any> = ({ id, className, children, text }) => (
  <span id={id} className={className}>
    {children ?? text}
  </span>
);
const TestP: React.FC<any> = ({ id, className, children, text }) => (
  <p id={id} className={className}>
    {children ?? text}
  </p>
);
const TestH3: React.FC<any> = ({ children, text }) => <h3>{children ?? text}</h3>;
const TestLabel: React.FC<any> = ({ children, text }) => <label>{children ?? text}</label>;
const TestIcon: React.FC<any> = ({ name }) => <i data-icon={name} />;
const TestButton: React.FC<any> = ({ id, onClick, disabled, className, children, text }) => (
  <button type="button" id={id} onClick={onClick} disabled={disabled} className={className}>
    {children ?? text}
  </button>
);
const TestToggle: React.FC<any> = ({ id, name, checked, value, disabled, onChange }) => (
  <input
    type="checkbox"
    role="switch"
    id={id}
    name={name}
    checked={!!(checked ?? value)}
    disabled={disabled}
    onChange={(e) => onChange?.(e)}
  />
);
const TestInput: React.FC<any> = ({ id, value, onChange, placeholder }) => (
  <input id={id} value={value ?? ''} placeholder={placeholder} onChange={(e) => onChange?.(e)} />
);
/**
 * 태그 입력 대역 — 값은 쉼표로 이어 보여 주고, 쉼표로 쓴 문자열을 넣으면 실제 컴포넌트처럼
 * 배열을 `target.value` 에 실어 change 를 낸다.
 */
const TestTagInput: React.FC<any> = ({ id, value, disabled, onChange }) => (
  <input
    id={id}
    data-tags={(Array.isArray(value) ? value : []).join(',')}
    disabled={disabled}
    onChange={(e) =>
      onChange?.({
        target: {
          value: e.target.value
            .split(',')
            .map((s: string) => s.trim())
            .filter(Boolean),
        },
      })
    }
  />
);
const TestTable: React.FC<any> = ({ children }) => <table>{children}</table>;
const TestThead: React.FC<any> = ({ children }) => <thead>{children}</thead>;
const TestTbody: React.FC<any> = ({ children }) => <tbody>{children}</tbody>;
const TestTr: React.FC<any> = ({ children }) => <tr data-row="rule">{children}</tr>;
const TestTh: React.FC<any> = ({ children, text }) => <th>{children ?? text}</th>;
const TestTd: React.FC<any> = ({ children, text }) => <td>{children ?? text}</td>;
const TestFragment: React.FC<any> = ({ children }) => <>{children}</>;

/**
 * 테스트용 컴포넌트 레지스트리를 구성합니다.
 *
 * Icon 은 실제 매니페스트처럼 자동 바인딩 제외를 선언한다 — `name` 이 글리프 이름이다.
 *
 * @returns 구성된 레지스트리
 */
function setupRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();

  (registry as any).registry = {
    Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
    Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
    P: { component: TestP, metadata: { name: 'P', type: 'basic' } },
    H3: { component: TestH3, metadata: { name: 'H3', type: 'basic' } },
    Label: { component: TestLabel, metadata: { name: 'Label', type: 'basic' } },
    Icon: { component: TestIcon, metadata: { name: 'Icon', type: 'basic', autoBinding: false } },
    Button: { component: TestButton, metadata: { name: 'Button', type: 'basic' } },
    Toggle: { component: TestToggle, metadata: { name: 'Toggle', type: 'composite', bindingType: 'checked' } },
    Input: { component: TestInput, metadata: { name: 'Input', type: 'basic' } },
    TagInput: { component: TestTagInput, metadata: { name: 'TagInput', type: 'composite' } },
    Table: { component: TestTable, metadata: { name: 'Table', type: 'basic' } },
    Thead: { component: TestThead, metadata: { name: 'Thead', type: 'basic' } },
    Tbody: { component: TestTbody, metadata: { name: 'Tbody', type: 'basic' } },
    Tr: { component: TestTr, metadata: { name: 'Tr', type: 'basic' } },
    Th: { component: TestTh, metadata: { name: 'Th', type: 'basic' } },
    Td: { component: TestTd, metadata: { name: 'Td', type: 'basic' } },
    Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
  };

  return registry;
}

/**
 * 레이아웃 트리에서 id 로 노드를 찾는다.
 *
 * @param node 탐색 시작 노드
 * @param id 찾을 노드 id
 * @returns 찾은 노드 또는 null
 */
function findById(node: unknown, id: string): Json | null {
  if (Array.isArray(node)) {
    for (const child of node) {
      const found = findById(child, id);
      if (found) return found;
    }
    return null;
  }
  if (node && typeof node === 'object') {
    const obj = node as Json;
    if (obj.id === id) return obj;
    for (const value of Object.values(obj)) {
      const found = findById(value, id);
      if (found) return found;
    }
  }
  return null;
}

/** partial 봉투(meta·comment)를 뗀 루트 노드 */
function partialRoot(partial: Json): Json {
  const { meta: _meta, comment: _comment, ...root } = partial;
  return root;
}

/**
 * 보안 탭 폼 상태 하나를 만든다(규칙은 사용 1·미사용 1).
 *
 * @param overrides security 필드 덮어쓰기
 * @returns `_local.form` 값
 */
function formWith(overrides: Json = {}): Json {
  return {
    security: {
      ip_restriction_enabled: true,
      admin_allowed_ips: ['198.51.100.0/24'],
      ip_restriction_rules: [
        { pattern: '/api/admin/users*', ips: ['203.0.113.0/24'], memo: '본사망', enabled: true },
        { pattern: '/api/modules/sirsoft-ecommerce/admin/*', ips: ['198.51.100.7'], memo: '주문 담당', enabled: false },
      ],
      ...overrides,
    },
  };
}

/**
 * 실제 카드를 실제 폼 래퍼 안에 넣어 렌더할 테스트 레이아웃을 만든다.
 *
 * @returns 레이아웃 JSON
 */
function buildCardLayout(): Json {
  const wrapper = findById((adminSettings as Json).slots, 'settings_content');
  if (!wrapper) {
    throw new Error('admin_settings.json 에서 settings_content 래퍼를 찾지 못했습니다.');
  }
  const { children: _children, ...wrapperShell } = wrapper;

  return {
    version: '1.0.0',
    layout_name: 'test_admin_settings_ip_restriction_card',
    computed: (adminSettings as Json).computed,
    components: [{ ...wrapperShell, children: [partialRoot(cardPartial as Json)] }],
  };
}

/**
 * 레이아웃이 선언한 computed 식을 같은 바인딩 엔진으로 계산한다(하네스 보완 ⓐ).
 *
 * @param form `_local.form`
 * @returns `_computed` 로 실을 값
 */
function computedFor(form: Json): Json {
  const engine = new DataBindingEngine();
  const context = { _local: { form }, _global: { settingsAbilities: SETTINGS_ABILITIES } };
  const computed = (adminSettings as Json).computed as Record<string, string>;

  return Object.fromEntries(
    Object.entries(computed).map(([key, expression]) => [
      key,
      engine.evaluateExpression(expression.replace(/^\{\{|\}\}$/g, ''), context as any),
    ])
  );
}

/**
 * 카드를 렌더한다.
 *
 * @param form `_local.form`
 * @param proxy 서버 진단(trustedProxy.data)
 * @returns 레이아웃 테스트 유틸
 */
async function renderCard(form: Json, proxy: Json = { status: 'ok', client_ip: CLIENT_IP }) {
  const testUtils = createLayoutTest(buildCardLayout() as any, {
    componentRegistry: setupRegistry(),
    translations: { admin: adminKo },
    initialState: { _local: { form }, _global: { settingsAbilities: SETTINGS_ABILITIES } },
    initialData: { trustedProxy: { data: proxy }, _computed: computedFor(form) },
  });
  await testUtils.render();

  return testUtils;
}

describe('환경설정 보안 — IP 접근 제한 카드', () => {
  let testUtils: ReturnType<typeof createLayoutTest> | undefined;

  afterEach(() => {
    testUtils?.cleanup();
    testUtils = undefined;
  });

  /**
   * @scenario master_toggle=on, surface=admin_shell, ip_source=forwarded_untrusted
   *
   * @effects settings_card_warns_when_proxy_untrusted
   */
  it('서버 진단이 warning 이면 카드 맨 위에 프록시 경고를 띄우고, 정상이면 띄우지 않는다', async () => {
    testUtils = await renderCard(formWith(), { status: 'warning', client_ip: '10.0.0.5' });
    expect(document.querySelector('#ip_restriction_proxy_warning')).not.toBeNull();
    expect(document.body.textContent).toContain('신뢰 프록시가 설정되지 않은 상태');
    testUtils.cleanup();

    testUtils = await renderCard(formWith(), { status: 'ok', client_ip: CLIENT_IP });
    expect(document.querySelector('#ip_restriction_proxy_warning')).toBeNull();
  });

  /**
   * 카드가 현재 접속 IP·프록시 경고를 그리려면 그 진단 데이터소스가 **보안 탭에서도** 받아져야
   * 한다. 원래 고급 탭 전용 게이트였고, 보안 탭이 빠지면 뱃지와 경고가 빈 채로 그려진다.
   *
   * @scenario master_toggle=on, surface=admin_shell, ip_source=forwarded_untrusted
   *
   * @effects settings_card_mounts_with_current_ip, settings_card_warns_when_proxy_untrusted
   */
  it('리버스 프록시 진단 데이터소스가 보안 탭에서도 받아진다', () => {
    const source = ((adminSettings as Json).data_sources as Json[]).find((s) => s.id === 'trustedProxy');

    expect(source, 'trustedProxy 데이터소스가 사라졌습니다').toBeTruthy();
    expect(source!.if, '탭 게이트가 사라졌습니다 — 모든 탭에서 받게 되면 다른 탭이 불필요한 요청을 낸다').toContain("'security'");
    expect(source!.if).toContain("'advanced'");
    expect(source!.auto_fetch).not.toBe(false);
  });

  /**
   * @scenario master_toggle=on, master_toggle=off, surface=admin_shell
   *
   * @effects settings_card_dims_lists_while_toggle_off
   */
  it.each([
    [false, true],
    ['0', true],
    ['false', true],
    [null, true],
    [true, false],
    ['1', false],
  ])('토글 값 %j 이면 허용 목록·규칙 칸 흐림=%s (적용되지 않는 값은 고치지 못하게)', async (enabled, dimmed) => {
    testUtils = await renderCard(formWith({ ip_restriction_enabled: enabled }));

    for (const id of ['field_admin_allowed_ips', 'field_ip_restriction_rules']) {
      const cls = document.querySelector(`#${id}`)?.getAttribute('class') ?? '';
      expect(cls.includes('pointer-events-none'), `${id} class="${cls}"`).toBe(dimmed);
    }
    const addRule = document.querySelector('#ip_restriction_add_rule') as HTMLButtonElement;
    expect(addRule.disabled).toBe(dimmed);
  });

  /**
   * @scenario master_toggle=on, surface=admin_shell, admin_list=excludes_client
   *
   * @effects settings_card_adds_current_ip_once, settings_card_mounts_with_current_ip
   */
  it('현재 접속 IP 를 보여 주고, 「현재 IP 추가」는 목록 끝에 한 번만 넣은 뒤 사라진다', async () => {
    testUtils = await renderCard(formWith({ admin_allowed_ips: ['198.51.100.0/24'] }));

    expect(document.querySelector('#ip_restriction_current_ip')?.textContent).toContain(CLIENT_IP);
    const tags = () => document.querySelector('#ip_restriction_admin_allowed_ips')?.getAttribute('data-tags');
    expect(tags()).toBe('198.51.100.0/24');

    fireEvent.click(document.querySelector('#ip_restriction_add_current_ip') as HTMLElement);

    await waitFor(() => {
      expect(tags()).toBe(`198.51.100.0/24,${CLIENT_IP}`);
    });
    expect(document.querySelector('#ip_restriction_add_current_ip'), '이미 들어 있는데 추가 버튼이 남았습니다').toBeNull();
  });

  /**
   * @scenario master_toggle=on, surface=admin_shell, admin_list=contains_client
   *
   * @effects settings_card_adds_current_ip_once
   */
  it('현재 IP 가 이미 목록에 있으면 추가 버튼을 그리지 않는다', async () => {
    testUtils = await renderCard(formWith({ admin_allowed_ips: [CLIENT_IP] }));

    expect(document.querySelector('#ip_restriction_current_ip')).not.toBeNull();
    expect(document.querySelector('#ip_restriction_add_current_ip')).toBeNull();
  });

  /**
   * @scenario master_toggle=on, surface=admin_shell, rule=disabled
   *
   * @effects settings_card_renders_rules_with_row_toggle_and_delete
   */
  it('규칙을 표로 그리고, 행의 사용 토글은 그 행의 enabled 만 뒤집는다', async () => {
    testUtils = await renderCard(formWith());

    const rows = () => Array.from(document.querySelectorAll('tbody tr[data-row="rule"]'));
    expect(rows()).toHaveLength(2);
    expect(rows()[0].textContent).toContain('/api/admin/users*');
    expect(rows()[0].textContent).toContain('203.0.113.0/24');
    expect(rows()[0].textContent).toContain('본사망');
    expect(document.querySelector('#ip_rules_empty')).toBeNull();

    const switches = () => rows().map((r) => (r.querySelector('input[role="switch"]') as HTMLInputElement).checked);
    expect(switches()).toEqual([true, false]);

    fireEvent.click(rows()[1].querySelector('input[role="switch"]') as HTMLElement);

    await waitFor(() => {
      expect(switches()).toEqual([true, true]);
    });
    // 다른 필드는 그대로다 — 행을 통째로 다시 쓰지만 값은 보존한다.
    expect(rows()[1].textContent).toContain('/api/modules/sirsoft-ecommerce/admin/*');
    expect(rows()[1].textContent).toContain('198.51.100.7');
  });

  /**
   * @scenario master_toggle=on, surface=admin_shell, rule=blocks_client
   *
   * @effects settings_card_renders_rules_with_row_toggle_and_delete
   */
  it('행의 삭제는 그 행만 폼에서 빼고, 다 지우면 빈 목록 안내가 뜬다', async () => {
    testUtils = await renderCard(formWith());

    fireEvent.click(document.querySelector('#ip_rule_delete_0') as HTMLElement);

    await waitFor(() => {
      expect(document.querySelectorAll('tbody tr[data-row="rule"]')).toHaveLength(1);
    });
    expect(document.querySelector('tbody')?.textContent).toContain('/api/modules/sirsoft-ecommerce/admin/*');
    expect(document.querySelector('tbody')?.textContent).not.toContain('/api/admin/users*');

    fireEvent.click(document.querySelector('#ip_rule_delete_0') as HTMLElement);

    await waitFor(() => {
      expect(document.querySelector('#ip_rules_empty')).not.toBeNull();
    });
  });

  /**
   * @scenario master_toggle=on, surface=admin_shell, rule=none
   *
   * @effects settings_card_always_shows_callback_warning_and_rescue_hint
   */
  it('외부 콜백 적색 고지와 서버 해제 안내는 규칙 유무와 무관하게 늘 보인다', async () => {
    testUtils = await renderCard(formWith({ ip_restriction_rules: [] }));

    expect(document.querySelector('#ip_rules_empty')).not.toBeNull();
    expect(document.querySelector('#ip_restriction_callback_warning')?.getAttribute('class')).toContain('alert-danger');
    expect(document.querySelector('#ip_restriction_emergency_hint')?.textContent).toContain(
      'php artisan security:ip-restriction --disable'
    );
  });

  /**
   * @scenario master_toggle=on, surface=admin_shell, rule=none
   *
   * @effects rule_modal_opens_with_copied_rules_and_row_index
   */
  it('「규칙 추가」는 규칙 목록 사본·row_index=null 로 창 상태를 통째로 만들고 창을 연다', async () => {
    testUtils = await renderCard(formWith());

    fireEvent.click(document.querySelector('#ip_restriction_add_rule') as HTMLElement);

    await waitFor(() => {
      expect(testUtils!.getState()._global.activeModal).toBe('ip_restriction_rule_form_modal');
    });
    const modalState = testUtils.getState()._global.ip_restriction_rule_form_modal;
    expect(modalState.row_index).toBeNull();
    expect(modalState.error).toBeNull();
    expect(modalState.form).toEqual({ pattern: '', ips: [], memo: '', enabled: true });
    expect(modalState.rules).toEqual(formWith().security.ip_restriction_rules);
  });

  /**
   * @scenario master_toggle=on, surface=admin_shell, rule=disabled
   *
   * @effects rule_modal_opens_with_copied_rules_and_row_index
   */
  it('행의 「편집」은 그 행 값과 행 번호로 창 상태를 채우고 창을 연다', async () => {
    testUtils = await renderCard(formWith());

    fireEvent.click(document.querySelector('#ip_rule_edit_1') as HTMLElement);

    await waitFor(() => {
      expect(testUtils!.getState()._global.activeModal).toBe('ip_restriction_rule_form_modal');
    });
    const modalState = testUtils.getState()._global.ip_restriction_rule_form_modal;
    expect(modalState.row_index).toBe(1);
    expect(modalState.form).toEqual({
      pattern: '/api/modules/sirsoft-ecommerce/admin/*',
      ips: ['198.51.100.7'],
      memo: '주문 담당',
      enabled: false,
    });
    expect(modalState.rules).toHaveLength(2);
  });

  /**
   * @scenario master_toggle=on, surface=admin_shell, rule=allows_client
   *
   * @effects settings_card_desktop_and_mobile_rows_share_wiring
   */
  it('넓은 화면 표와 좁은 화면 카드가 같은 배선(사용 토글·편집·삭제)을 쓴다', () => {
    const root = partialRoot(cardPartial as Json);
    const desktop = findById(root, 'ip_rules_table_desktop');
    const mobile = findById(root, 'ip_rules_cards_mobile');
    expect(desktop && mobile).toBeTruthy();

    /** 노드 안의 모든 액션을 id 접미(_m)와 주석을 걷어 직렬화한다. */
    const wiring = (node: Json): string[] => {
      const out: string[] = [];
      const walk = (n: unknown): void => {
        if (Array.isArray(n)) {
          n.forEach(walk);
          return;
        }
        if (n && typeof n === 'object') {
          const obj = n as Json;
          for (const action of Array.isArray(obj.actions) ? obj.actions : []) {
            out.push(JSON.stringify(action, (k, v) => (k === 'comment' ? undefined : v)));
          }
          walk(obj.children);
        }
      };
      walk(node);
      return out;
    };

    expect(wiring(mobile!)).toEqual(wiring(desktop!));
    expect(wiring(desktop!)).toHaveLength(3);
  });
});

describe('환경설정 보안 — IP 접근 제한 규칙 편집 창', () => {
  let testUtils: ReturnType<typeof createLayoutTest> | undefined;
  let parentWrites: Json[];
  let parentSlot: { state: Json; setState: (update: Json) => void; dataContext: Json };

  beforeEach(() => {
    parentWrites = [];
    parentSlot = {
      state: { form: formWith(), hasChanges: false },
      setState: (update: Json) => {
        parentWrites.push(update);
      },
      dataContext: {},
    };
    (window as any).__g7LayoutContextStack = [parentSlot];
  });

  afterEach(() => {
    testUtils?.cleanup();
    testUtils = undefined;
    delete (window as any).__g7LayoutContextStack;
  });

  /**
   * 창 본문을 렌더한다(테두리는 G7Core 전역이 그리므로 뗀다. 취소·적용 단추는 본문 안에 있다).
   *
   * @param modalState `_global.ip_restriction_rule_form_modal`
   * @returns 레이아웃 테스트 유틸
   */
  async function renderModal(modalState: Json) {
    const modal = partialRoot(modalPartial as Json);
    expect(modal.name).toBe('Modal');

    testUtils = createLayoutTest(
      {
        version: '1.0.0',
        layout_name: 'test_ip_restriction_rule_form_modal',
        components: [...modal.children],
      } as any,
      {
        componentRegistry: setupRegistry(),
        translations: { admin: adminKo },
        initialState: {
          _global: {
            ip_restriction_rule_form_modal: modalState,
            // 창은 이미 열린 상태에서 시작한다 — 「적용」이 닫는지 보려면 열림 표시가 있어야 한다.
            activeModal: 'ip_restriction_rule_form_modal',
            modalStack: ['ip_restriction_rule_form_modal'],
          },
        },
      }
    );
    await testUtils.render();

    return testUtils;
  }

  /** 창이 아직 열려 있는가(엔진은 전역 상태에 열림 표시를 둔다). */
  const modalIsOpen = (): boolean =>
    (testUtils!.getState()._global.modalStack ?? []).includes('ip_restriction_rule_form_modal');

  /** 추가 모드 초기 상태(목록이 여는 순간 만드는 모양) */
  function createState(form: Json = {}): Json {
    return {
      form: { pattern: '', ips: [], memo: '', enabled: true, ...form },
      row_index: null,
      rules: formWith().security.ip_restriction_rules,
      error: null,
    };
  }

  const applyButton = () => document.querySelector('#ip_rule_apply_button') as HTMLElement;

  /**
   * @scenario master_toggle=on, surface=admin_shell, rule=none
   *
   * @effects rule_modal_requires_pattern_then_ips
   */
  it('패턴이 비면 패턴 안내를 띄우고 부모 폼은 건드리지 않은 채 창을 연 채로 둔다', async () => {
    await renderModal(createState({ pattern: '   ', ips: ['203.0.113.9'] }));

    fireEvent.click(applyButton());

    await waitFor(() => {
      expect(testUtils!.getState()._global.ip_restriction_rule_form_modal.error).toBe('pattern_required');
    });
    await testUtils!.rerender();
    expect(document.querySelector('#ip_rule_form_error')?.textContent).toContain('URI 패턴을 입력하세요.');
    expect(parentWrites).toHaveLength(0);
    expect(modalIsOpen(), '입력이 덜 찼는데 창이 닫혔습니다').toBe(true);
  });

  /**
   * @scenario master_toggle=on, surface=admin_shell, rule=none
   *
   * @effects rule_modal_requires_pattern_then_ips
   */
  it('패턴은 있고 IP 가 없으면 IP 안내를 띄운다', async () => {
    await renderModal(createState({ pattern: '/api/__probe*' }));

    fireEvent.click(applyButton());

    await waitFor(() => {
      expect(testUtils!.getState()._global.ip_restriction_rule_form_modal.error).toBe('ips_required');
    });
    await testUtils!.rerender();
    expect(document.querySelector('#ip_rule_form_error')?.textContent).toContain('허용 IP 를 하나 이상 입력하세요.');
    expect(parentWrites).toHaveLength(0);
    expect(modalIsOpen()).toBe(true);
  });

  /**
   * @scenario master_toggle=on, surface=admin_shell, rule=blocks_client
   *
   * @effects rule_modal_commits_once_to_parent_form
   */
  it('입력값은 창 상태에만 담기고, 적용하면 목록 끝에 붙인 배열을 부모 폼에 한 번 쓴 뒤 닫고 비운다', async () => {
    await renderModal(createState());

    fireEvent.change(document.querySelector('#ip_rule_pattern_input') as HTMLElement, {
      target: { value: '  /api/__ip-probe*  ' },
    });
    fireEvent.change(document.querySelector('#ip_rule_ips_input') as HTMLElement, {
      target: { value: '203.0.113.9, 198.51.100.0/24' },
    });
    fireEvent.change(document.querySelector('#ip_rule_memo_input') as HTMLElement, {
      target: { value: '측정용' },
    });

    await waitFor(() => {
      expect(testUtils!.getState()._global.ip_restriction_rule_form_modal.form).toEqual({
        pattern: '  /api/__ip-probe*  ',
        ips: ['203.0.113.9', '198.51.100.0/24'],
        memo: '측정용',
        enabled: true,
      });
    });
    // 키 입력 동안 부모 폼은 한 번도 고치지 않는다(사례 13 — 키스트로크당 설정 화면 전체 재렌더 회피).
    expect(parentWrites).toHaveLength(0);

    // 전역 변경은 다음 렌더에 실린다 — 「적용」이 방금 친 값을 읽도록 한 번 다시 그린다.
    await testUtils!.rerender();
    fireEvent.click(applyButton());

    await waitFor(() => {
      expect(parentWrites).toHaveLength(2);
    });
    // 고칠 자리를 target 에 적고 값을 주므로, 부모 슬롯에는 **중첩된 모양**으로 도착한다.
    expect(parentWrites[0].form.security.ip_restriction_rules).toEqual([
      ...formWith().security.ip_restriction_rules,
      { pattern: '/api/__ip-probe*', ips: ['203.0.113.9', '198.51.100.0/24'], memo: '측정용', enabled: true },
    ]);
    // 나머지 보안 값은 그대로 남는다 — 배열 한 칸만 갈아 끼운다.
    expect(parentWrites[0].form.security.admin_allowed_ips).toEqual(formWith().security.admin_allowed_ips);
    expect(parentWrites[1].hasChanges).toBe(true);
    await waitFor(() => {
      expect(modalIsOpen()).toBe(false);
    });
    expect(testUtils.getState()._global.ip_restriction_rule_form_modal).toBeNull();
  });

  /**
   * @scenario master_toggle=on, surface=admin_shell, rule=disabled
   *
   * @effects rule_modal_edit_replaces_only_that_row
   */
  it('편집 모드의 적용은 그 행만 바꾸고 다른 행은 그대로 둔다', async () => {
    await renderModal({
      form: { pattern: '/api/admin/users* ', ips: ['203.0.113.0/25'], memo: '축소', enabled: false },
      row_index: 0,
      rules: formWith().security.ip_restriction_rules,
      error: null,
    });

    fireEvent.click(applyButton());

    await waitFor(() => {
      expect(parentWrites).toHaveLength(2);
    });
    const rules = parentWrites[0].form.security.ip_restriction_rules;
    expect(rules).toHaveLength(2);
    expect(rules[0]).toEqual({ pattern: '/api/admin/users*', ips: ['203.0.113.0/25'], memo: '축소', enabled: false });
    expect(rules[1]).toEqual(formWith().security.ip_restriction_rules[1]);
  });

  describe('[case:state-advanced-modal-parent-form-not-mirrored] 부모 폼 커밋은 두 자리에 쓴다', () => {
    /**
     * 「적용」은 폼을 **두 자리**에 써야 한다.
     *
     * 창에서 부모 폼만 고치면(`$parent._local`) 화면 표에는 규칙이 보이는데 저장 요청 본문에는
     * 실리지 않아 「저장했는데 규칙이 사라지는」 손실이 난다(브라우저 실측). 그래서 저장이 읽는
     * 자리(`target="global"` 의 `_local.form…`)와 화면이 읽는 자리를 함께 쓴다.
     *
     * @scenario master_toggle=on, surface=admin_shell, rule=blocks_client
     *
     * @effects applied_rule_survives_save, rule_modal_commits_once_to_parent_form
     */
    it('적용은 저장이 읽는 자리와 화면이 읽는 자리에 같은 배열을 쓴다', () => {
      const apply = findById(partialRoot(modalPartial as Json), 'ip_rule_apply_button');
      // 컴파일러가 단일 원소를 객체로 접으므로 모양에 기대지 않고 헬퍼로 읽는다.
      const sequence = actionsOf(apply)[0];
      const writes = callbacksOf(sequence, 'actions').filter((action) => action.handler === 'setState');

      const toSaveSlot = writes.find((w) => '_local.form.security.ip_restriction_rules' in (w.params ?? {}));
      const toScreenSlot = writes.find((w) => w.params?.target === '$parent._local.form.security.ip_restriction_rules');

      expect(toSaveSlot, '저장이 읽는 자리에 쓰지 않습니다 — 저장하면 규칙이 사라집니다').toBeTruthy();
      expect(toSaveSlot!.params.target).toBe('global');
      expect(toScreenSlot, '화면이 읽는 자리에 쓰지 않습니다 — 표와 저장 버튼이 반응하지 않습니다').toBeTruthy();
      // 두 자리에 **같은 배열식**이 들어가야 한다 — 갈라지면 화면과 저장이 어긋난다.
      expect(toSaveSlot!.params['_local.form.security.ip_restriction_rules']).toBe(toScreenSlot!.params.value);
    });
  });

  describe('[case:state-advanced-modal-footer-stale-context] 입력을 읽는 단추는 본문에 둔다', () => {
    /**
     * 취소·적용은 모달 고정 하단(`props.footer`)이 아니라 본문에 있어야 한다.
     *
     * 고정 하단은 모달 컴포넌트가 자기 렌더러로 그리며 그 데이터 컨텍스트에는 **창을 연 시점의**
     * 전역 상태가 실린다. 그 자리에서 방금 친 패턴·IP 를 읽으면 늘 빈 값이라 「적용」이 영원히
     * 「패턴을 입력하세요」로 되돌아간다(브라우저 실측으로 확인하고 본문으로 옮겼다).
     *
     * @scenario master_toggle=on, surface=admin_shell, rule=blocks_client
     *
     * @effects rule_modal_requires_pattern_then_ips
     */
    it('취소·적용 단추는 본문에 있다 (고정 하단은 방금 친 값을 읽지 못한다)', () => {
      const modal = partialRoot(modalPartial as Json);

      expect(modal.props.footer, '단추가 고정 하단으로 돌아갔습니다').toBeUndefined();
      expect(findById(modal.children, 'ip_rule_apply_button'), '적용 단추가 본문에 없습니다').not.toBeNull();
      expect(findById(modal.children, 'ip_rule_form_footer')).not.toBeNull();
    });
  });

  /**
   * @scenario master_toggle=on, surface=admin_shell, rule=none
   *
   * @effects rule_modal_opens_with_copied_rules_and_row_index
   */
  it('창 제목은 row_index 로 추가/편집이 갈린다', () => {
    const title: string = partialRoot(modalPartial as Json).props.title;
    expect(title).toContain('row_index');
    expect(title).toContain('create_title');
    expect(title).toContain('edit_title');
  });
});
