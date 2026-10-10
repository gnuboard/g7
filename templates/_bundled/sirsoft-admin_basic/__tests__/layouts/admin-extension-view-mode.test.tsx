/**
 * 네 확장 화면이 보기 모드를 **prop 으로** 내려주는지 잠근다.
 * [case:components-misc-card-cell-has-no-global]
 *
 * 카드 셀 렌더 컨텍스트에는 전역 상태가 없어, 셀 안에서 _global 을 읽는 조건은 조용히
 * 거짓이 된다. 그래서 전역을 읽는 자리는 레이아웃이고 카드는 값만 받는다.
 */
import { describe, it, expect } from 'vitest';
import moduleList from '../../layouts/admin_module_list.json';
import pluginList from '../../layouts/admin_plugin_list.json';
import templateList from '../../layouts/admin_template_list.json';
import languagePackList from '../../layouts/admin_language_pack_list.json';
import settings from '../../layouts/admin_settings.json';
import tabUser from '../../layouts/partials/admin_template_list/_tab_user.json';
import tabAdmin from '../../layouts/partials/admin_template_list/_tab_admin.json';
import lpContent from '../../layouts/partials/admin_language_pack_list/_content.json';
import pluginDetail from '../../layouts/partials/admin_plugin_list/_modal_detail.json';
import moduleDetail from '../../layouts/partials/admin_module_list/_modal_detail.json';
import templateDetail from '../../layouts/partials/admin_template_list/_modal_detail.json';
import lpDetail from '../../layouts/partials/admin_language_pack_list/_modal_detail.json';
import pluginInstall from '../../layouts/partials/admin_plugin_list/_modal_install.json';
import pluginUpdate from '../../layouts/partials/admin_plugin_list/_modal_update.json';
import pluginUninstall from '../../layouts/partials/admin_plugin_list/_modal_uninstall.json';

/**
 * 확장 관리 4화면의 갤러리/목록 보기 전환을 고정한다.
 *
 * 세 가지가 눈으로 확인되지 않아 정적으로 잠근다.
 *
 * 1. **기본이 갤러리다.** 저장값이 없거나 깨졌을 때 목록으로 떨어지면 PO 가 정한 기본 보기가
 *    뒤집힌다. 삼항의 분기 방향은 화면에서 저장값을 지워 봐야 드러난다.
 * 2. **저장 직후 되읽는다.** GDPR 저장 인터셉터는 허용목록 밖 키를 예외 없이 버리므로,
 *    되읽기가 없으면 "저장했다고 믿었는데 새로고침하면 사라지는" 상태를 화면이 구분하지 못한다.
 * 3. **갤러리가 액션을 잃지 않는다.** 보기 전환은 배치만 바꾸는 것이라 설치·업데이트·토글의
 *    조건식에 보기 상태가 섞이면 안 된다. 섞이면 한쪽 보기에서만 버튼이 사라진다.
 */

const VIEW_MODE_KEY = 'g7_view_mode_extensions';
const GLOBAL_KEY = 'extensionViewMode';
const PERSISTED_KEY = 'extensionViewModePersisted';

type LayoutNode = Record<string, unknown>;

/** 레이아웃 트리를 깊이우선으로 훑어 조건에 맞는 노드를 모은다. */
function collect(node: unknown, predicate: (n: LayoutNode) => boolean, found: LayoutNode[] = []): LayoutNode[] {
  if (Array.isArray(node)) {
    node.forEach((child) => collect(child, predicate, found));
    return found;
  }
  if (node && typeof node === 'object') {
    const obj = node as LayoutNode;
    if (predicate(obj)) found.push(obj);
    Object.values(obj).forEach((value) => collect(value, predicate, found));
  }
  return found;
}

/** id 로 노드 하나를 찾는다. */
function byId(layout: unknown, id: string): LayoutNode | undefined {
  return collect(layout, (n) => n.id === id)[0];
}

const HOST_LAYOUTS = [
  { name: '모듈 관리', layout: moduleList },
  { name: '플러그인 관리', layout: pluginList },
  { name: '템플릿 관리', layout: templateList },
  { name: '언어팩 관리', layout: languagePackList },
  { name: '환경설정(언어팩 탭)', layout: settings },
];

const GRID_LAYOUTS = [
  { name: '모듈', layout: moduleList, ref: 'module_card_grid' },
  { name: '플러그인', layout: pluginList, ref: 'plugin_card_grid' },
  { name: '사용자 템플릿', layout: tabUser, ref: 'template_card_grid' },
  { name: '관리자 템플릿', layout: tabAdmin, ref: 'template_card_grid' },
  { name: '언어팩', layout: lpContent, ref: 'lp_card_grid' },
];

// @scenario view=gallery, icon_state=present
// @effects default_is_gallery, preference_shared_across_screens
describe('V1 — 보기 방식 복원(init)', () => {
  // @scenario view=gallery, icon_state=present
  // @effects default_is_gallery, preference_shared_across_screens
  it.each(HOST_LAYOUTS)('$name 은 부팅 때 저장된 보기를 읽는다', ({ layout }) => {
    const initActions = (layout as { init_actions?: LayoutNode[] }).init_actions ?? [];
    const load = initActions.find((a) => a.handler === 'loadFromLocalStorage');

    expect(load, 'init_actions 에 loadFromLocalStorage 가 없습니다').toBeDefined();
    expect(load?.params).toMatchObject({ key: VIEW_MODE_KEY, defaultValue: 'gallery' });
    expect(load?.resultTo).toMatchObject({ target: '_global', key: GLOBAL_KEY });
  });

  // @scenario view=gallery, icon_state=present
  // @effects default_is_gallery, preference_shared_across_screens
  it.each(HOST_LAYOUTS)('$name 의 복원 액션이 첫 액션이다', ({ layout }) => {
    const initActions = (layout as { init_actions?: LayoutNode[] }).init_actions ?? [];

    // 다른 액션이 먼저 상태를 만지면 첫 페인트가 목록으로 번쩍인다.
    expect(initActions[0]?.handler).toBe('loadFromLocalStorage');
  });
});

// @scenario view=list, icon_state=present
// @effects toggle_persists_to_local_storage, persisted_value_read_back_after_write
describe('V2 — 토글이 리터럴을 저장하고 되읽는다', () => {
  const TOGGLES = [
    { name: '모듈', layout: moduleList, prefix: '' },
    { name: '플러그인', layout: pluginList, prefix: '' },
    // 템플릿은 토글이 부모 화면이 아니라 탭 조각에 있다 — 검색이 거기 있어 같은 줄에
    // 두어야 하고, 두 탭은 `route.type` 으로 갈려 한 번에 하나만 그려진다.
    // 두 탭은 같은 화면의 조각이라 data-ref 가 겹치면 테스트·브라우저 도구가 어느 쪽을
    // 집었는지 구분하지 못한다 — 탭별 접두로 가른다.
    { name: '템플릿(사용자 탭)', layout: tabUser, prefix: 'tpl_user_' },
    { name: '템플릿(관리자 탭)', layout: tabAdmin, prefix: 'tpl_admin_' },
    { name: '언어팩', layout: lpContent, prefix: 'lp_' },
  ];

  // @scenario view=list, icon_state=present
  // @effects toggle_persists_to_local_storage, persisted_value_read_back_after_write
  it.each(TOGGLES)('$name 의 두 버튼이 리터럴 값을 저장한다', ({ layout, prefix }) => {
    (['gallery', 'list'] as const).forEach((mode) => {
      const button = byId(layout, `${prefix}view_mode_${mode}`);
      expect(button, `${prefix}view_mode_${mode} 버튼이 없습니다`).toBeDefined();

      const sequence = (button?.actions as LayoutNode[])?.[0];
      expect(sequence?.handler).toBe('sequence');

      const steps = (sequence?.actions ?? []) as LayoutNode[];
      const save = steps.find((s) => s.handler === 'saveToLocalStorage');

      expect(save?.params).toMatchObject({ key: VIEW_MODE_KEY, value: mode });
    });
  });

  // @scenario view=list, icon_state=present
  // @effects toggle_persists_to_local_storage, persisted_value_read_back_after_write
  it.each(TOGGLES)('$name 의 토글은 저장 직후 되읽어 성공을 확인한다', ({ layout, prefix }) => {
    (['gallery', 'list'] as const).forEach((mode) => {
      const button = byId(layout, `${prefix}view_mode_${mode}`);
      const steps = ((button?.actions as LayoutNode[])?.[0]?.actions ?? []) as LayoutNode[];

      const saveIndex = steps.findIndex((s) => s.handler === 'saveToLocalStorage');
      const loadIndex = steps.findIndex((s) => s.handler === 'loadFromLocalStorage');

      expect(loadIndex, '되읽기 액션이 없습니다').toBeGreaterThan(-1);
      expect(loadIndex, '되읽기는 저장 뒤에 와야 합니다').toBeGreaterThan(saveIndex);
      expect(steps[loadIndex]?.resultTo).toMatchObject({ target: '_global', key: PERSISTED_KEY });
    });
  });

  // @scenario view=list, icon_state=present
  // @effects toggle_persists_to_local_storage, persisted_value_read_back_after_write
  it.each(TOGGLES)('$name 의 시퀀스는 형제 상태를 다시 읽지 않는다', ({ layout, prefix }) => {
    (['gallery', 'list'] as const).forEach((mode) => {
      const button = byId(layout, `${prefix}view_mode_${mode}`);
      const steps = ((button?.actions as LayoutNode[])?.[0]?.actions ?? []) as LayoutNode[];
      const save = steps.find((s) => s.handler === 'saveToLocalStorage');

      // 저장 값이 표현식이면 같은 시퀀스 안에서 방금 쓴 형제 상태를 읽게 되어
      // 한 박자 밀린 값이 저장된다(AGENTS.md §6).
      expect(String((save?.params as LayoutNode)?.value)).not.toContain('{{');
    });
  });

  // @scenario view=list, icon_state=present
  // @effects toggle_persists_to_local_storage, persisted_value_read_back_after_write
  it.each(TOGGLES)('$name 의 토글은 aria-pressed 로 상태를 알린다', ({ layout, prefix }) => {
    const gallery = byId(layout, `${prefix}view_mode_gallery`);
    const list = byId(layout, `${prefix}view_mode_list`);

    expect((gallery?.props as LayoutNode)?.['aria-pressed']).toBe(
      `{{(_global.${GLOBAL_KEY} ?? 'gallery') !== 'list'}}`,
    );
    expect((list?.props as LayoutNode)?.['aria-pressed']).toBe(
      `{{(_global.${GLOBAL_KEY} ?? 'gallery') === 'list'}}`,
    );
  });
});

// @scenario view=gallery, device=desktop_1440
// @effects grid_columns_follow_view_mode, columns_follow_container_width
describe('V3 — 보기 상태가 컴포넌트로 전달된다', () => {
  /**
   * 종전에는 카드 안에 `_global.extensionViewMode` 조건을 두어 배치를 갈랐다. 그 자리는
   * 반복 렌더 하위 트리라 전역 상태가 없어(CardGrid.renderCellChildren 의 컨텍스트는
   * `{row, $value, index}` 뿐) 조건이 아예 평가되지 않았다. 그래서 보기 분기를 prop 으로
   * 끌어올렸고, 여기서는 **레이아웃이 그 prop 을 실제로 넘기는지**를 잠근다.
   */
  // @scenario view=gallery, device=desktop_1440
  // @effects grid_columns_follow_view_mode
  it.each(GRID_LAYOUTS)('$name 그리드가 보기 상태를 viewMode 로 넘긴다', ({ layout, ref }) => {
    const grid = byId(layout, ref);

    expect(grid, `${ref} 가 없습니다`).toBeDefined();
    expect(grid?.name).toBe('ExtensionCardGrid');

    const viewMode = String((grid?.props as LayoutNode)?.viewMode ?? '');
    expect(viewMode).toContain(GLOBAL_KEY);

    // 문자열에 `'list'`·`'gallery'` 가 둘 다 있는지만 보면 삼항을 뒤집어도 초록이다.
    // 그래서 식을 실제로 **평가해** 저장값별 결과를 잠근다 — 계획 §8.2 V3 가 지정한
    // `'list'`→list · 그 밖의 모든 값(미저장·오염)→gallery 세 갈래다.
    const evaluate = (stored: string | undefined) =>
      Function('_global', `return (${viewMode.replace(/^\{\{|\}\}$/g, '')});`)({
        extensionViewMode: stored,
      });

    expect(evaluate('list')).toBe('list');
    expect(evaluate('gallery')).toBe('gallery');
    // 저장값이 없거나 오염되면 갤러리다 — else 가지가 뒤집히면 여기서 red 가 난다.
    expect(evaluate(undefined)).toBe('gallery');
    expect(evaluate('foo')).toBe('gallery');
  });

  // @scenario view=gallery, device=desktop_1440
  // @effects columns_follow_container_width, columns_respect_max_columns
  it.each(GRID_LAYOUTS)('$name 그리드가 열 수 재료를 넘긴다', ({ layout, ref }) => {
    const props = (byId(layout, ref)?.props ?? {}) as LayoutNode;

    // 열 수는 중단점이 아니라 컨테이너 실측 폭에서 나온다 — 컴포넌트가 계산하고,
    // 레이아웃은 최소 카드 폭과 상한만 준다.
    expect(Number(props.minCardWidth)).toBeGreaterThanOrEqual(200);
    expect(Number(props.maxColumns)).toBeGreaterThanOrEqual(2);
    expect(props.gridColumns, 'gridColumns 는 더 쓰지 않습니다').toBeUndefined();
    expect(props.responsiveColumns, 'responsiveColumns 는 더 쓰지 않습니다').toBeUndefined();
  });

  // @scenario view=gallery
  // @effects grid_columns_follow_view_mode
  it.each(GRID_LAYOUTS)('$name 그리드가 마지막 페이지를 1 로 접지 않는다', ({ layout, ref }) => {
    const props = (byId(layout, ref)?.props ?? {}) as LayoutNode;

    // 상한까지만 센 목록은 last_page 가 null 로 온다. 1 로 채우면 "1페이지뿐" 이라고
    // 잘못 말하므로 null 을 그대로 넘기고 hasMorePages 로 '다음' 을 연다.
    expect(String(props.totalPages)).not.toMatch(/last_page\s*\?\?\s*1(?![0-9])/);
    expect(String(props.hasMorePages)).toContain('has_more_pages');
  });
});

// @scenario icon_state=present, view=gallery
// @effects icon_rendered_from_icon_url, icon_box_is_square
describe('V4 — items 가 행을 카드 데이터로 옮긴다', () => {
  // @scenario icon_state=present, view=gallery
  // @effects icon_rendered_from_icon_url
  it.each(GRID_LAYOUTS)('$name 은 아이콘 주소와 대체 글리프를 함께 싣는다', ({ layout, ref }) => {
    const items = String((byId(layout, ref)?.props as LayoutNode)?.items ?? '');

    expect(items).toContain('iconUrl');
    expect(items).toContain('icon_url');
    // 아이콘이 없거나 깨졌을 때 드러날 글리프를 종류별로 지정한다.
    expect(items).toContain('fallbackIcon');
  });

  // @scenario view=gallery, install_state=active
  // @effects callbacks_return_source_row
  it.each(GRID_LAYOUTS)('$name 은 원본 행을 row 로 실어 콜백이 되받게 한다', ({ layout, ref }) => {
    const items = String((byId(layout, ref)?.props as LayoutNode)?.items ?? '');

    // 콜백이 원본 행을 그대로 돌려주므로 레이아웃이 목록에서 다시 찾지 않아도 된다.
    expect(items).toMatch(/row:\s*p\b/);
  });

  // @scenario view=gallery
  // @effects gallery_meta_splits_vendor_and_dependencies
  it.each(GRID_LAYOUTS)('$name 은 이름·버전·설명·작성자를 옮긴다', ({ layout, ref }) => {
    const items = String((byId(layout, ref)?.props as LayoutNode)?.items ?? '');

    ['name:', 'version:', 'description:', 'vendor:'].forEach((field) => {
      expect(items, `${field} 가 없습니다`).toContain(field);
    });
  });
});

// @scenario view=gallery, install_state=active
// @effects gallery_actions_parity_with_list
describe('V4-b — 카드 동작이 레이아웃에 붙어 있다', () => {
  // @scenario view=gallery, install_state=active
  // @effects gallery_actions_parity_with_list
  it.each(GRID_LAYOUTS)('$name 그리드에 본문 클릭·더보기 처리가 있다', ({ layout, ref }) => {
    const grid = byId(layout, ref);
    const events = ((grid?.actions ?? []) as LayoutNode[]).map((a) => a.event);

    // 본문 클릭(상세)과 더보기 메뉴는 보기와 무관하게 늘 있어야 한다.
    expect(events).toContain('onItemClick');
    expect(events).toContain('onAction');
    expect(events).toContain('onPageChange');
  });

  // @scenario view=gallery, install_state=inactive
  // @effects gallery_actions_parity_with_list
  it.each(GRID_LAYOUTS)('$name 의 items 식에 보기 상태가 섞이지 않는다', ({ layout, ref }) => {
    const items = String((byId(layout, ref)?.props as LayoutNode)?.items ?? '');

    // 보기 전환은 배치만 바꾼다. 카드 데이터 계산에 보기가 섞이면 한쪽 보기에서만
    // 버튼·배지가 사라져 권한·상태 분기가 조용히 갈린다.
    expect(items).not.toContain(GLOBAL_KEY);
  });
});

// @scenario view=gallery, install_state=active
// @effects gallery_actions_parity_with_list, permission_gates_unchanged
describe('V7 — 액션·권한 조건식에 보기 상태가 없다', () => {
  /**
   * `items` 식에서 액션 하나의 **값 전체**를 잘라 낸다 — 키 이름 뒤부터 그 액션을 끝내는
   * 최상위 콤마(또는 객체를 닫는 괄호)까지.
   *
   * 종전 구현은 「키 뒤 첫 여는 괄호부터 짝이 맞는 닫는 괄호까지」였다. 실 레이아웃의 액션은
   * `toggle: p.status !== 'uninstalled' ? { … } : null` 형태라 **삼항 조건과 else 가지가
   * 슬라이스 밖으로 떨어졌고**, 그 자리에 보기 상태를 넣은 회귀는 초록으로 지나갔다
   * (`toggle: p.status !== 'uninstalled' && _global.extensionViewMode !== 'list' ? …`).
   * 계획 §8.2 V7 이 잠그라 한 것은 「이 액션이 보기에 따라 달라지지 않는다」이므로
   * 조건·본문·모든 가지를 한 조각으로 본다.
   */
  function sliceExpression(items: string, key: string): string[] {
    const found: string[] = [];
    const opener: Record<string, string> = { '(': ')', '[': ']', '{': '}' };
    let from = 0;
    for (;;) {
      const at = items.indexOf(`${key}:`, from);
      if (at < 0) return found;
      const start = at + key.length + 1;
      from = start;
      let depth = 0;
      let end = items.length;
      for (let i = start; i < items.length; i += 1) {
        const ch = items[i];
        if (opener[ch]) {
          depth += 1;
        } else if (ch === ')' || ch === ']' || ch === '}') {
          // 우리를 감싼 객체가 닫히면 이 액션의 값도 거기서 끝난다.
          if (depth === 0) {
            end = i;
            break;
          }
          depth -= 1;
        } else if (depth === 0 && ch === ',') {
          end = i;
          break;
        }
      }
      const slice = items.slice(start, end).trim();
      if (slice !== '') found.push(slice);
      from = end;
    }
  }

  // 계획 §8.2 V7 이 지정한 네 자리. 배치는 보기를 따라도 "무엇을 할 수 있는가" 는 따르면 안 된다.
  const ACTION_KEYS = ['toggle', 'primaryAction', 'secondaryAction', 'actions'];

  // @scenario view=gallery, install_state=active
  // @effects gallery_actions_parity_with_list
  it.each(GRID_LAYOUTS)('$name 의 액션 조건식이 보기와 무관하다', ({ layout, ref }) => {
    const items = String((byId(layout, ref)?.props as LayoutNode)?.items ?? '');
    const slices = ACTION_KEYS.flatMap((key) => sliceExpression(items, key));

    // 모집단이 비면 이 검사는 아무것도 재지 않는다 — 화면마다 액션이 최소 하나는 있어야 한다.
    expect(slices.length, `${ref} 의 items 에서 액션 조건식을 찾지 못했습니다`).toBeGreaterThan(0);

    for (const slice of slices) {
      // 보기가 섞이면 갤러리에서만 설치 버튼이 사라지거나 권한 잠금이 한쪽에서 풀린다.
      expect(slice).not.toContain(GLOBAL_KEY);
      expect(slice).not.toContain(VIEW_MODE_KEY);
    }
  });

  // @scenario view=gallery, install_state=active
  // @effects gallery_actions_parity_with_list
  it('네 자리 중 실제로 쓰이는 키를 화면 전체에서 찾아낸다', () => {
    // 위 검사가 키 이름 오타로 0건을 세고도 통과하는 것을 막는다.
    const seen = new Set<string>();
    for (const { layout, ref } of GRID_LAYOUTS) {
      const items = String((byId(layout, ref)?.props as LayoutNode)?.items ?? '');
      ACTION_KEYS.forEach((key) => {
        if (sliceExpression(items, key).length > 0) seen.add(key);
      });
    }

    expect([...seen].sort()).toEqual(['actions', 'primaryAction', 'secondaryAction', 'toggle']);
  });
});

// @scenario icon_state=broken_url, view=gallery
// @effects fallback_glyph_when_icon_broken
describe('V8 — 깨진 아이콘은 그 행만 글리프로 되돌린다', () => {
  // @scenario icon_state=broken_url, view=gallery
  // @effects fallback_glyph_when_icon_broken
  /**
   * 깨짐→글리프 교체라는 **행동**은 `ExtensionCardGrid.test.tsx` 가 `fireEvent.error` 로 잰다.
   * 여기서 잠글 것은 그 격리가 성립하는 조건이다 — 컴포넌트는 깨짐을 카드별 state 로 들고
   * (`ExtensionCardGrid.tsx:197`) 카드를 `key={item.id}` 로 그린다(:641,651). 그래서 행마다
   * **서로 다른 id** 가 오지 않으면 한 카드의 깨짐이 목록이 바뀔 때 옆 카드로 옮겨붙는다.
   *
   * 종전 단언(`toContain('iconUrl')`·`toContain('fallbackIcon')`)은 V4 의 단언과 부분집합이라
   * 이 축을 고유하게 재는 것이 하나도 없었다.
   */
  // @scenario icon_state=broken_url, view=gallery
  // @effects broken_icon_is_scoped_to_one_card
  it.each(GRID_LAYOUTS)('$name 카드가 행마다 다른 id 를 실어 깨짐이 옆 카드로 번지지 않는다', ({ layout, ref }) => {
    const items = String((byId(layout, ref)?.props as LayoutNode)?.items ?? '');

    // `id:` 는 map 콜백 안에서 행 값으로 만들어져야 한다 — 리터럴이면 전 행이 같은 key 를 쓴다.
    const idBinding = items.match(/\bid:\s*([^,\n]+)/)?.[1]?.trim();
    expect(idBinding, `${ref} 의 items 에 id 바인딩이 없습니다`).toBeTruthy();
    expect(idBinding).toMatch(/\bp\b/);
    expect(idBinding).not.toMatch(/^['"]/);
  });
});

// @scenario screen=language_packs, icon_state=lp_target_composite
// @effects language_pack_composite_badge
describe('V5 — 언어팩 합성 배지', () => {
  // @scenario screen=language_packs, icon_state=lp_target_composite
  // @effects language_pack_composite_badge
  it('자기 아이콘이 아닐 때만 로케일 배지를 얹는다', () => {
    const items = String((byId(lpContent, 'lp_card_grid')?.props as LayoutNode)?.items ?? '');

    // 언어팩은 대개 자기 그림이 없어 대상 확장·코어의 아이콘을 빌려 쓴다 — 무엇의 어떤
    // 언어인지 이 표식으로만 갈린다. 매니페스트가 제 그림을 담았으면 붙이지 않는다.
    expect(items).toContain('iconBadge');
    expect(items).toContain("icon_source !== 'manifest'");
    expect(items).toContain('locale');
  });
});

// @scenario view=gallery, install_state=active
// @effects gallery_actions_parity_with_list
describe('V6 — 카드 본문 클릭이 상세 창을 연다', () => {
  const BODY_CLICK = [
    { name: '모듈', layout: moduleList, ref: 'module_card_grid', modal: 'module_detail_modal' },
    { name: '플러그인', layout: pluginList, ref: 'plugin_card_grid', modal: 'plugin_detail_modal' },
    { name: '사용자 템플릿', layout: tabUser, ref: 'template_card_grid', modal: 'template_detail_modal' },
    { name: '관리자 템플릿', layout: tabAdmin, ref: 'template_card_grid', modal: 'template_detail_modal' },
    { name: '언어팩', layout: lpContent, ref: 'lp_card_grid', modal: 'language_pack_detail_modal' },
  ];

  // @scenario view=gallery, install_state=active
  // @effects card_body_opens_detail_modal
  it.each(BODY_CLICK)('$name 카드 본문 클릭이 더보기 메뉴와 같은 창을 연다', ({ layout, ref, modal }) => {
    const grid = byId(layout, ref);
    const onItemClick = ((grid?.actions ?? []) as LayoutNode[]).find((a) => a.event === 'onItemClick');
    const onAction = ((grid?.actions ?? []) as LayoutNode[]).find((a) => a.event === 'onAction');

    const opened = (node: unknown) => collect(node, (n) => n.handler === 'openModal').map((n) => n.target);

    // 본문 클릭과 메뉴의 상세 보기가 다른 창을 열면 두 경로가 갈린다.
    expect(opened(onItemClick), '본문 클릭이 상세 창을 열지 않습니다').toContain(modal);
    expect(opened(onAction), '더보기 메뉴가 상세 창을 열지 않습니다').toContain(modal);
  });
});

// @scenario icon_state=present, screen=plugins
// @effects icon_rendered_from_icon_url
describe('V10 — 창·서랍의 아이콘 자리', () => {
  const DETAILS = [
    { name: '모듈', layout: moduleDetail, ref: 'detail_ext_icon' },
    { name: '플러그인', layout: pluginDetail, ref: 'detail_ext_icon' },
    { name: '템플릿', layout: templateDetail, ref: 'detail_ext_icon' },
    { name: '언어팩', layout: lpDetail, ref: 'lp_detail_icon' },
  ];

  // @scenario icon_state=present, screen=plugins
  // @effects icon_rendered_from_icon_url
  it.each(DETAILS)('$name 상세 창에 아이콘 자리가 있다', ({ layout, ref }) => {
    const box = byId(layout, ref);
    expect(box, `${ref} 가 없습니다`).toBeDefined();
    expect(collect(box, (n) => n.name === 'Img').length).toBeGreaterThan(0);
    expect(collect(box, (n) => n.name === 'Icon').length).toBeGreaterThan(0);
  });

  // 모집단을 손으로 적지 않는다 — 플러그인 3개만 적어 두었다가 언어팩 3개가 아이콘 없이
  // 통과한 적이 있다. 확인 창이 하나 늘면 케이스도 같이 늘어야 한다.
  // 언어팩 `_modal_install` 은 제외한다 — ZIP·GitHub 업로드 창이라 대상 확장이 정해져 있지 않다.
  const CONFIRM_MODALS = Object.entries(
    import.meta.glob('../../layouts/partials/*/_modal_{install,install_bundled,update,uninstall}.json', {
      eager: true,
      import: 'default',
    }) as Record<string, LayoutNode>,
  )
    .filter(([path]) => !path.endsWith('admin_language_pack_list/_modal_install.json'))
    .map(([path, layout]) => ({ name: path.replace(/^.*\/partials\//, ''), layout }));

  // @scenario icon_state=present, screen=modules, screen=plugins, screen=templates, screen=language_packs
  // @effects modal_headers_show_icon
  it('확인 창 모집단이 비어 있지 않다', () => {
    expect(CONFIRM_MODALS.length).toBeGreaterThanOrEqual(12);
  });

  // @scenario icon_state=present, screen=modules, screen=plugins, screen=templates, screen=language_packs
  // @effects modal_headers_show_icon
  it.each(CONFIRM_MODALS)('$name 확인 창 문장에 24px 아이콘이 붙는다', ({ layout }) => {
    const box = collect(
      layout,
      (n) => /_icon$/.test(String(n.id ?? '')) && String((n.props as LayoutNode)?.className ?? '').includes('w-6 h-6'),
    )[0];
    expect(box, '24px 아이콘 상자(data-ref="*_icon", w-6 h-6)가 없습니다').toBeDefined();
    expect(collect(box, (n) => n.name === 'Img').length).toBeGreaterThan(0);
    expect(collect(box, (n) => n.name === 'Icon').length).toBeGreaterThan(0);
  });

  // 서랍도 모집단을 손으로 적지 않는다 — 플러그인 하나만 재고 있었고 모듈·템플릿 서랍은
  // 아무 단언도 받지 않았다. 언어팩 서랍은 제외한다(대상 확장이 정해지지 않아 아이콘 재료가 없다).
  const PREVIEW_DRAWERS = Object.entries(
    import.meta.glob('../../layouts/partials/*/_drawer_manifest_preview.json', {
      eager: true,
      import: 'default',
    }) as Record<string, LayoutNode>,
  )
    .filter(([path]) => !path.endsWith('admin_language_pack_list/_drawer_manifest_preview.json'))
    .map(([path, layout]) => ({ name: path.replace(/^.*\/partials\//, ''), layout }));

  // @scenario icon_state=present, screen=modules, screen=plugins, screen=templates
  // @effects preview_shows_icon_presence
  it('미리보기 서랍 모집단이 비어 있지 않다', () => {
    expect(PREVIEW_DRAWERS.length).toBeGreaterThanOrEqual(3);
  });

  // @scenario icon_state=present, screen=modules, screen=plugins, screen=templates
  // @effects preview_shows_icon_presence, preview_shows_icon_warning
  it.each(PREVIEW_DRAWERS)(
    '$name: 수동 설치 미리보기 서랍이 아이콘 검사 결과 3갈래를 알린다',
    ({ layout }) => {
      const box = collect(
        layout,
        (n) => typeof n.id === 'string' && /_preview_icon$/.test(n.id),
      )[0];
      expect(box, '*_preview_icon 상자가 없습니다').toBeDefined();

      const text = JSON.stringify(box);
      ['icon_present', 'icon_invalid', 'icon_missing'].forEach((key) => {
        expect(text, `${key} 문구가 없습니다`).toContain(`extensions.manifest_preview.${key}`);
      });
    },
  );
});

// 카드 아이콘은 §12.9 로 ExtensionCardGrid 안으로 들어갔다 — 레이아웃에 남은 드리프트 축은
// 「각 화면이 items 에 아이콘 재료를 실어 넘기는가」다. 종전 판정은 이미 사라진
// `data-ref="ext_icon"` 을 찾아 네 자리 모두 undefined 를 서로 비교했고, 그래서 아이콘 배선을
// 전부 지워도 초록이었다. 모집단·단언을 실재하는 자리로 옮긴다.
// @scenario icon_state=absent, view=list
// @effects icon_rendered_from_icon_url, fallback_glyph_when_icon_absent
describe('V9 — 5화면 아이콘 배선 드리프트 잠금', () => {
  const ITEM_WIRING = GRID_LAYOUTS.map(({ name, layout, ref }) => {
    const grid = collect(layout, (n) => n.name === 'ExtensionCardGrid' && n.id === ref)[0];
    const props = (grid?.props ?? {}) as LayoutNode;

    return { name, items: typeof props.items === 'string' ? props.items : '' };
  });

  // @scenario icon_state=absent
  // @effects icon_rendered_from_icon_url
  it('아이콘 배선을 재는 모집단이 비어 있지 않다', () => {
    expect(ITEM_WIRING.length).toBeGreaterThanOrEqual(5);
    ITEM_WIRING.forEach(({ name, items }) => {
      expect(items, `${name}: ExtensionCardGrid 의 items 식을 찾지 못했다`).not.toBe('');
    });
  });

  // @scenario icon_state=present
  // @effects icon_rendered_from_icon_url
  it.each(ITEM_WIRING)('$name: items 가 응답의 icon_url 을 그대로 싣는다', ({ items }) => {
    expect(items).toContain('icon_url');
  });

  // @scenario icon_state=absent
  // @effects fallback_glyph_when_icon_absent
  it.each(ITEM_WIRING)('$name: 아이콘이 없을 때 쓸 유형 글리프를 함께 싣는다', ({ items }) => {
    expect(items).toContain('fallbackIcon');
  });

  // 유형 글리프는 화면마다 달라야 한다 — 한 값으로 수렴하면 이 작업이 없앤 결함으로 되돌아간다.
  // @scenario icon_state=absent
  // @effects fallback_glyph_when_icon_absent
  it('유형 글리프가 화면마다 다르다', () => {
    const glyphs = ITEM_WIRING.map(({ items }) => /fallbackIcon:\s*'([^']+)'/.exec(items)?.[1]);

    glyphs.forEach((glyph, i) => {
      expect(glyph, `${ITEM_WIRING[i].name}: fallbackIcon 리터럴을 읽지 못했다`).toBeTruthy();
    });
    expect(new Set(glyphs).size, `유형 글리프가 겹친다: ${glyphs.join(', ')}`).toBe(glyphs.length);
  });
});

/**
 * V9 의 모집단은 5화면의 `items` 식뿐이라 **창·서랍 조각은 스캔에 들어오지 않는다**.
 * 그래서 카드가 관리자 템플릿에 `cog` 를 쓰는 동안 템플릿 창 5곳이 `palette` 를 고정으로
 * 박고 있어도 초록이었다(라운드 8 발견). 템플릿은 한 화면이 user·admin 두 유형을 함께
 * 다루는 유일한 유형이라, 창이 유형을 보지 않으면 카드와 그림이 어긋난다.
 */
// @scenario screen=templates_admin, icon_state=absent
// @effects fallback_glyph_when_icon_absent, modal_headers_show_icon
describe('V9-b — 템플릿 창·서랍의 유형 글리프가 user/admin 을 가른다', () => {
  const ICON_BOX_ID = /(_ext_icon|_install_icon|_update_icon|_uninstall_icon|_preview_icon)$/;

  // 모집단은 파일명이 아니라 **확장 아이콘 상자를 실제로 가진 조각**에서 파생한다 —
  // 같은 디렉터리의 활성화·라이선스 창 등은 이 축의 대상이 아니다.
  const TEMPLATE_FRAGMENTS = Object.entries(
    import.meta.glob('../../layouts/partials/admin_template_list/_{modal,drawer}*.json', {
      eager: true,
    }) as Record<string, { default: LayoutNode }>,
  )
    .map(([path, mod]) => ({ name: path.split('/').pop() ?? path, layout: mod.default }))
    .filter(
      ({ layout }) =>
        collect(layout, (n) => typeof n.id === 'string' && ICON_BOX_ID.test(n.id)).length > 0,
    );

  // @scenario screen=templates_admin, icon_state=absent
  // @effects modal_headers_show_icon
  it('창·서랍 모집단이 비어 있지 않다', () => {
    // §6.4 가 지정한 템플릿 표면: 상세 1 + 확인 3 + 서랍 1.
    expect(TEMPLATE_FRAGMENTS.length).toBeGreaterThanOrEqual(5);
  });

  // @scenario screen=templates_admin, icon_state=absent
  // @effects fallback_glyph_when_icon_absent, modal_headers_show_icon
  it.each(TEMPLATE_FRAGMENTS)(
    '$name: palette 를 고정으로 박지 않고 type 으로 가른다',
    ({ layout }) => {
      // 확장 아이콘 상자 안의 글리프만 본다 — 구획 제목의 장식 아이콘(「기본 정보」의 cog 등)은
      // 「확장 하나」를 대표하지 않으므로 이 축의 모집단이 아니다(계획 §4 F6 과 같은 구분).
      const boxes = collect(layout, (n) => typeof n.id === 'string' && ICON_BOX_ID.test(n.id));
      expect(boxes.length, '확장 아이콘 상자를 찾지 못했다').toBeGreaterThan(0);

      const glyphs = collect(
        boxes,
        (n) => n.name === 'Icon' && typeof (n.props as LayoutNode)?.name === 'string',
      ).map((n) => String((n.props as LayoutNode).name));
      expect(glyphs.length, '아이콘 상자 안에 폴백 글리프가 없다').toBeGreaterThan(0);

      glyphs.forEach((glyph) => {
        expect(glyph, 'palette 리터럴 — 관리자 템플릿에서 카드(cog)와 어긋난다').not.toBe('palette');
        expect(glyph).toContain("'cog'");
        expect(glyph).toContain("'palette'");
      });
    },
  );
});
