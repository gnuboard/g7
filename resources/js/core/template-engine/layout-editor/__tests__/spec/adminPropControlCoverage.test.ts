// e2e:allow(capability/control/i18n) 부여 + Vitest 커버리지 가드까지다.
// 라이브 E2E(편집기 진입→추가→각 propControl 편집→저장→실사용자화면)는 시나리오 매니페스트
// tests/scenarios/layout-editor-prop-controls.yaml 의 effects(capability_attachment_*,
// prop_edit_reflects_*)가 가리키는 **1-c-verify 세션**의 Playwright spec
// (property-edit-propcontrols.spec.ts)이 작성한다(1-a 가 1-b/1-c 로 위임한 것과 동형).
/**
 * adminPropControlCoverage.test.ts —sirsoft-admin_basic 전 draggable
 * 컴포넌트 편집 가능 커버리지 게이트 (no-editable 0).
 *
 * 배경: 단계 1-b 가 basic 45개를 전수 부여한 뒤, 1-c 는 부록1 정의표대로 admin 78개
 * draggable 전수에 propControls(속성 탭) + styleControls + events 를 부여해 no-editable
 * 을 0 으로 만든다. 본 테스트는 그 커버리지가 회귀하지 않도록 분할 editor-spec
 * 소스(SSoT)를 직접 읽어 가드한다. (basicPropControlCoverage 와 동형)
 *
 * 가드 항목:
 *  1. 모든 nesting.draggable 이 componentCapabilities 에 항목 보유.
 *  2. 모든 draggable 이 비-빈 편집 표면(propControls | styleControls | flexEditor |
 *     events | advanced | nodeEditor) 보유 → no-editable 0.
 *  3. 모든 propControls 키가 controls 의 apply.type==="propValue" 컨트롤로 해석.
 *  4. 모든 styleControls 키가 controls 에 존재.
 *  5. 모든 $t:editor.* 라벨이 ko/en editor.json 에서 해석.
 *  6. 소스검증 정정 회귀(EmptyState/Breadcrumb 무이벤트, 아이콘 prop=icon-picker).
 *
 * @since engine-v1.50.0
 */

import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const ROOT = process.cwd();
const SPEC = 'templates/_bundled/sirsoft-admin_basic/editor-spec';
const LANG = 'templates/_bundled/sirsoft-admin_basic/lang/partial';

const load = (rel: string): any => JSON.parse(readFileSync(resolve(ROOT, rel), 'utf8'));

const nesting = load(`${SPEC}/nesting.json`);
const caps = load(`${SPEC}/componentCapabilities.json`);
const controls = load(`${SPEC}/controls.json`);
const draggable: string[] = nesting.draggable ?? [];

const isPropValue = (key: string): boolean => {
  const a = controls[key]?.apply;
  return !!a && a.type === 'propValue';
};

const hasEditableSurface = (name: string): boolean => {
  const c = caps[name];
  if (!c || typeof c !== 'object') return false;
  return (
    (c.propControls?.length ?? 0) > 0 ||
    (c.styleControls?.length ?? 0) > 0 ||
    !!c.flexEditor ||
    (c.events?.length ?? 0) > 0 ||
    (c.advanced?.length ?? 0) > 0 ||
    !!c.nodeEditor
  );
};

describe('sirsoft-admin_basic draggable 커버리지', () => {
  it('draggable 목록이 비어있지 않다', () => {
    expect(draggable.length).toBeGreaterThan(0);
  });

  it('모든 draggable 이 componentCapabilities 항목을 보유한다', () => {
    const missing = draggable.filter((d) => !caps[d]);
    expect(missing).toEqual([]);
  });

  it('모든 draggable 이 편집 표면을 보유한다 (no-editable 0)', () => {
    const noEditable = draggable.filter((d) => !hasEditableSurface(d));
    expect(noEditable).toEqual([]);
  });

  it('모든 propControls 키가 propValue 컨트롤로 해석된다', () => {
    const unresolved: string[] = [];
    for (const d of draggable) {
      for (const pk of caps[d]?.propControls ?? []) {
        if (!isPropValue(pk)) unresolved.push(`${d}.${pk}`);
      }
    }
    expect(unresolved).toEqual([]);
  });

  it('모든 styleControls 키가 controls 에 존재한다', () => {
    const unresolved: string[] = [];
    for (const d of draggable) {
      for (const sk of caps[d]?.styleControls ?? []) {
        if (!controls[sk]) unresolved.push(`${d}.${sk}`);
      }
    }
    expect(unresolved).toEqual([]);
  });
});

describe('sirsoft-admin_basic 라벨 i18n 해석 (ko/en)', () => {
  const collectTkeys = (obj: unknown, out = new Set<string>()): Set<string> => {
    if (typeof obj === 'string') {
      if (obj.startsWith('$t:')) out.add(obj.slice(3));
    } else if (obj && typeof obj === 'object') {
      for (const v of Object.values(obj)) collectTkeys(v, out);
    }
    return out;
  };

  const keys = collectTkeys(controls);
  collectTkeys(caps, keys);

  it.each(['ko', 'en'])('%s editor.json 이 모든 $t 키를 해석한다', (loc) => {
    // 실제 편집기 t() 해석 체인과 동일한 폴백 — 템플릿 editor.json(editor.*) →
    // 템플릿 layout_editor.json(layout_editor.palette.* 등) → 코어 layout_editor.json
    // (layout_editor.list_editor.* 등 코어 에디터 UI 키).children params
    // (childLabel/itemFields)가 팔레트·코어 에디터 키를 정당 참조한다.
    const sources: Array<{ prefix: RegExp; lang: any }> = [
      { prefix: /^editor\./, lang: load(`${LANG}/${loc}/editor.json`) },
      { prefix: /^layout_editor\./, lang: load(`${LANG}/${loc}/layout_editor.json`) },
      { prefix: /^layout_editor\./, lang: load(`lang/partial/${loc}/layout_editor.json`) },
    ];
    const resolves = (key: string): boolean =>
      sources.some(({ prefix, lang }) => {
        if (!prefix.test(key)) return false;
        const path = key.replace(prefix, '');
        return (
          path.split('.').reduce<any>((a, k) => (a == null ? undefined : a[k]), lang) !== undefined
        );
      });
    const missing = [...keys].filter((k) => !resolves(k));
    expect(missing).toEqual([]);
  });
});

describe('소스검증 정정 회귀 가드', () => {
  // 소스 검증: admin EmptyState 는 top-level 콜백(onAction)이 없다(actions[].onClick 사용).
  it('EmptyState 는 무이벤트(부록1 onAction 정정)', () => {
    expect(caps.EmptyState?.events ?? []).toEqual([]);
  });

  // 소스 검증: admin Breadcrumb 는 top-level 콜백(onBreadcrumbClick)이 없다(items[].onClick 사용).
  it('Breadcrumb 는 무이벤트(부록1 onBreadcrumbClick 정정)', () => {
    expect(caps.Breadcrumb?.events ?? []).toEqual([]);
  });

  // 아이콘 prop 컴포넌트는 icon-picker 위젯으로 편집(템플릿 소유 카탈로그 재사용).
  it.each([
    ['Icon', 'iconName'],
    ['StatusBadge', 'sbIconName'],
    ['EmptyState', 'esIconName'],
    ['StatCard', 'scIconName'],
    ['IconButton', 'ibIconName'],
    ['ActionMenu', 'amTriggerIcon'],
    ['ChipCheckbox', 'ccIcon'],
  ])('%s 의 아이콘 prop(%s)은 icon-picker 위젯', (comp, key) => {
    expect(caps[comp]?.propControls ?? []).toContain(key);
    expect(controls[key]?.widget).toBe('icon-picker');
  });

  // ImageGallery 는 Lightbox 모달 by-design — coreProps:true 로 편집 placeholder 도달.
  it('ImageGallery 는 coreProps:true (편집 placeholder 도달)', () => {
    expect(caps.ImageGallery?.coreProps).toBe(true);
  });

  // Table/Ul/Ol 은 구조 에디터(nodeEditor) 슬롯 보유.
  it('Table 은 nodeEditor kind=table, Ul/Ol 은 kind=children', () => {
    expect(caps.Table?.nodeEditor?.kind).toBe('table');
    expect(caps.Ul?.nodeEditor?.kind).toBe('children');
    expect(caps.Ol?.nodeEditor?.kind).toBe('children');
  });

  // Alert defaultNode 가 컴포넌트가 읽지 않는 `variant` 키를
  // seed 해 type 이 undefined → typeConfig[type] 크래시("컴포넌트 로드 실패")가 났다.
  // defaultNode 는 컴포넌트가 실제 읽는 `type` 을 seed 해야 한다.
  it('Alert defaultNode 는 type 을 seed(variant 오타 금지)', () => {
    const palette = load(`${SPEC}/componentPalette.json`);
    const alertProps = palette.entries?.Alert?.defaultNode?.props ?? {};
    expect(alertProps.variant).toBeUndefined();
    expect(['info', 'success', 'warning', 'error']).toContain(alertProps.type);
  });

  // admin ProductCard 는 flat props(title/price/imageUrl)를 읽는다.
  // defaultNode 가 nested `product` 객체를 seed 하면 price 가 undefined → toLocaleString 크래시.
  it('ProductCard defaultNode 는 flat props(title/price) seed(nested product 금지)', () => {
    const palette = load(`${SPEC}/componentPalette.json`);
    const pcProps = palette.entries?.ProductCard?.defaultNode?.props ?? {};
    expect(pcProps.product).toBeUndefined();
    expect(typeof pcProps.price).toBe('number');
    expect(pcProps.title).toBeTruthy();
  });
});

describe('sirsoft-admin_basic children 노드 에디터(STRUCT-TREE)', () => {
  // 목록/컨테이너 컴포넌트는 nodeEditor:{kind:"children",params:{childComponent}} 로
  // 자식 추가/삭제/정렬을 속성 모달 본체에서 편집한다(부록4-ter 일반 슬롯).
  const expected: Record<string, string> = {
    Ul: 'Li',
    Ol: 'Li',
    Nav: 'A',
    Form: 'Input',
    Li: 'Span',
  };
  it.each(Object.entries(expected))(
    '%s 가 nodeEditor:{kind:children,params:{childComponent:%s}} 를 보유한다',
    (comp, child) => {
      const ne = caps[comp]?.nodeEditor;
      expect(ne?.kind).toBe('children');
      expect(ne?.params?.childComponent).toBe(child);
    },
  );

  it('children 노드 에디터 보유 컴포넌트는 전부 draggable 이다', () => {
    const withChildren = Object.keys(caps).filter((k) => caps[k]?.nodeEditor?.kind === 'children');
    const notDraggable = withChildren.filter((c) => !draggable.includes(c));
    expect(notDraggable).toEqual([]);
  });
});

describe('sirsoft-admin_basic dataProps 전수 선언 + 직교 가드', () => {
  // 6-b: 데이터형 prop(배열 컬렉션 + 입력 value(scalar) + 단일 객체(object))을 capability.
  // dataProps 로 선언한다. propControls/nodeEditor(정적 구조 편집)와 직교 — 한 prop 이 둘 다
  // 오지 않는다(부록6). 구조/수치/enum/boolean prop 에 데이터연결 0(회귀 가드).
  const dpComponents = Object.keys(caps).filter((k) => Array.isArray(caps[k]?.dataProps));

  it('대표 컬렉션 컴포넌트가 data/items 등 배열 dataProps 를 보유한다', () => {
    const expectArray: Array<[string, string]> = [
      ['DataGrid', 'data'],
      ['CardGrid', 'data'],
      ['DonutChart', 'data'],
      ['ImageGallery', 'images'],
      ['SortableMenuList', 'items'],
      ['FilterGroup', 'filters'],
      ['BarChart', 'datasets'],
    ];
    for (const [comp, key] of expectArray) {
      const dp = (caps[comp]?.dataProps ?? []).find((d: { propKey: string }) => d.propKey === key);
      expect(dp, `${comp}.${key}`).toBeTruthy();
      expect(dp.shape).toBe('array');
    }
  });

  it('입력 value 는 scalar dataProp, UserProfile.user 는 object dataProp', () => {
    expect((caps.Input?.dataProps ?? []).find((d: { propKey: string }) => d.propKey === 'value')?.shape).toBe('scalar');
    expect((caps.Textarea?.dataProps ?? []).find((d: { propKey: string }) => d.propKey === 'value')?.shape).toBe('scalar');
    expect((caps.UserProfile?.dataProps ?? []).find((d: { propKey: string }) => d.propKey === 'user')?.shape).toBe('object');
  });

  it('dataProps shape 는 scalar/array/object enum 만', () => {
    const bad: string[] = [];
    for (const c of dpComponents) {
      for (const d of caps[c].dataProps) {
        if (!['scalar', 'array', 'object'].includes(d.shape)) bad.push(`${c}.${d.propKey}:${d.shape}`);
      }
    }
    expect(bad).toEqual([]);
  });

  // 입력/선택 계열 누락 메움. 선택 컴포넌트 value + options,
  // PermissionTree data+value, SearchBar value. 6-b 가 선택/입력 축을 누락했던 결함을 메운다.
  it('선택 컴포넌트가 value + options dataProp 을 보유한다 (Select/SearchableDropdown/RichSelect/RadioGroup/TagInput/TagSelect)', () => {
    const missing: string[] = [];
    for (const comp of ['Select', 'SearchableDropdown', 'RichSelect', 'RadioGroup', 'TagInput', 'TagSelect']) {
      const dp = caps[comp]?.dataProps ?? [];
      if (!dp.some((d: { propKey: string }) => d.propKey === 'value')) missing.push(`${comp}.value`);
      if (!dp.some((d: { propKey: string }) => d.propKey === 'options')) missing.push(`${comp}.options`);
    }
    expect(missing).toEqual([]);
  });

  it('PermissionTree 가 data(array) + value(array) dataProp 을 보유한다', () => {
    const dp = caps.PermissionTree?.dataProps ?? [];
    expect(dp.find((d: { propKey: string }) => d.propKey === 'data')?.shape).toBe('array');
    expect(dp.find((d: { propKey: string }) => d.propKey === 'value')?.shape).toBe('array');
  });

  it('SearchBar.value 가 scalar dataProp (suggestions 와 별도)', () => {
    const dp = caps.SearchBar?.dataProps ?? [];
    expect(dp.find((d: { propKey: string }) => d.propKey === 'value')?.shape).toBe('scalar');
    expect(dp.find((d: { propKey: string }) => d.propKey === 'suggestions')?.shape).toBe('array');
  });

  it('options 직교 완화: Select.options 가 dataProps(array)와 propControls(selectOptions) 둘 다에 노출', () => {
    expect((caps.Select?.dataProps ?? []).some((d: { propKey: string }) => d.propKey === 'options')).toBe(true);
    expect((caps.Select?.propControls ?? []).includes('selectOptions')).toBe(true);
  });

  // 직교 완화(계획서 (G), + 90aec9470): 데이터 prop 은 정적 편집
  // (nodeEditor.arrayProp)과 바인딩(dataProps[propKey])을 **공존**한다(상호배타 아님). 정적값이면
  // nodeEditor 로 편집, `{{...}}` 바인딩이면 nodeEditor 디그레이드 + dataProps 데이터연결로 —
  // 한 컴포넌트가 양쪽 표면 보유(options 계열이 먼저 공존, 8-b 가 tabs/data 등으로 확장).
  // 종전 "충돌 0" 가드는 직교 완화로 무효 → 공존이 의도임을 명시 잠금(회귀로 한쪽이 사라지지 않게).
  it('직교 완화: 정적 편집 데이터 prop 은 nodeEditor.arrayProp + dataProps 공존(TabNavigationScroll.tabs/DonutChart.data)', () => {
    const coexist = (c: string, prop: string): boolean => {
      const ap = caps[c]?.nodeEditor?.params?.arrayProp;
      const hasDp = (caps[c]?.dataProps ?? []).some((d: { propKey: string }) => d.propKey === prop);
      return ap === prop && hasDp;
    };
    expect(coexist('TabNavigationScroll', 'tabs')).toBe(true);
    expect(coexist('DonutChart', 'data')).toBe(true);
  });

  it('회귀 가드: 구조/수치 prop 은 dataProps 에 없다 (열 수·간격·페이지크기·rows·maxItems)', () => {
    const forbidden = ['gridColumns', 'gap', 'pageSize', 'cols', 'rows', 'maxItems', 'pagination', 'width', 'height'];
    const leaked: string[] = [];
    for (const c of dpComponents) {
      for (const d of caps[c].dataProps) {
        if (forbidden.includes(d.propKey)) leaked.push(`${c}.${d.propKey}`);
      }
    }
    expect(leaked).toEqual([]);
  });

  it('dataProps.label 키가 ko/en editor.json 에서 해석된다', () => {
    for (const loc of ['ko', 'en']) {
      const lang = load(`${LANG}/${loc}/editor.json`);
      const get = (path: string): unknown =>
        path.split('.').reduce<any>((a, k) => (a == null ? undefined : a[k]), lang);
      const missing: string[] = [];
      for (const c of dpComponents) {
        for (const d of caps[c].dataProps) {
          if (typeof d.label === 'string' && d.label.startsWith('$t:')) {
            if (get(d.label.slice(3).replace(/^editor\./, '')) === undefined) missing.push(`${loc}:${d.label}`);
          }
        }
      }
      expect(missing).toEqual([]);
    }
  });
});

describe('sirsoft-admin_basic stateLabels 카탈로그', () => {
  const spec = load('templates/_bundled/sirsoft-admin_basic/editor-spec/stateLabels.json');
  it('stateLabels 가 배열이고 key+scope+label_key 를 갖는다', () => {
    expect(Array.isArray(spec)).toBe(true);
    expect(spec.length).toBeGreaterThan(0);
    for (const e of spec) {
      expect(typeof e.key).toBe('string');
      expect(typeof e.scope).toBe('string');
      expect(e.label_key.startsWith('$t:editor.state_label.')).toBe(true);
    }
  });
  it('state_label 키가 ko/en editor.json 에서 해석된다', () => {
    for (const loc of ['ko', 'en']) {
      const lang = load(`${LANG}/${loc}/editor.json`);
      const get = (path: string): unknown =>
        path.split('.').reduce<any>((a, k) => (a == null ? undefined : a[k]), lang);
      const missing = spec
        .map((e: { label_key: string }) => e.label_key.slice(3).replace(/^editor\./, ''))
        .filter((k: string) => get(k) === undefined);
      expect(missing).toEqual([]);
    }
  });
});

describe('TagInput 계열 options 편집 커버리지', () => {
  // 재스캔(번들 레이아웃 전수)에서 TagInput 계열 5종에 options 편집기가 0 인 누락이 발견됐다.
  // 부록5.1 분류(ARRAY-PROP/options-list)대로 value/label 선택지는 Select/RadioGroup 과
  // 동일한 selectOptions(options-list propControl) 로 편집한다. IconSelect 만 항목에 faIcon
  // (icon-picker)이 있어 array 노드 에디터(label/value/faIcon)로 편집한다.
  /**
   * @scenario unit=taginput_family_options
   * @effects taginput_family_options_list_propcontrol_attached
   */
  it.each(['TagInput', 'TagSelect', 'SearchableDropdown', 'RichSelect'])(
    '%s 가 selectOptions(options-list) propControl 을 보유한다',
    (comp) => {
      expect(caps[comp]?.propControls ?? []).toContain('selectOptions');
      expect(controls.selectOptions?.widget).toBe('options-list');
      expect(controls.selectOptions?.apply?.propKey).toBe('options');
    },
  );

  /**
   * @scenario unit=iconselect_options_array
   * @effects iconselect_options_array_nodeeditor_with_faicon_field
   */
  it('IconSelect 는 array 노드 에디터(arrayProp=options, faIcon=icon 필드)를 보유한다', () => {
    const ne = caps.IconSelect?.nodeEditor;
    expect(ne?.kind).toBe('array');
    expect(ne?.params?.arrayProp).toBe('options');
    const fieldKeys = (ne?.params?.fields ?? []).map((f: { key: string }) => f.key);
    expect(fieldKeys).toContain('faIcon');
    const iconField = (ne?.params?.fields ?? []).find((f: { key: string }) => f.key === 'faIcon');
    expect(iconField?.widget).toBe('icon');
  });
});
