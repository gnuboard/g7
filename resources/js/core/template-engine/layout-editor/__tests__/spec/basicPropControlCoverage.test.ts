/**
 * basicPropControlCoverage.test.ts —sirsoft-basic 전 draggable
 * 컴포넌트 편집 가능 커버리지 게이트 (no-editable 0).
 *
 * 배경: 레이아웃 편집기에서 컴포넌트를 추가해도 대부분 "편집 가능한 속성이 없습니다"
 * 로 떴다(비-스타일 prop 편집 표면 부재). 단계 1-b 는 부록2 정의표대로 basic 45개
 * draggable 전수에 propControls(속성 탭) + styleControls + events 를 부여해 no-editable
 * 을 0 으로 만든다. 본 테스트는 그 커버리지가 회귀하지 않도록 분할 editor-spec
 * 소스(SSoT)를 직접 읽어 가드한다.
 *
 * 가드 항목:
 *  1. 모든 nesting.draggable 이 componentCapabilities 에 항목 보유.
 *  2. 모든 draggable 이 비-빈 편집 표면(propControls | styleControls | flexEditor |
 *     events | advanced) 보유 → no-editable 0.
 *  3. 모든 propControls 키가 controls 의 apply.type==="propValue" 컨트롤로 해석.
 *  4. 모든 styleControls 키가 controls 에 존재.
 *  5. 모든 $t:editor.* 라벨이 ko/en editor.json 에서 해석.
 *
 * @since engine-v1.50.0
 */

import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const ROOT = process.cwd();
const SPEC = 'templates/_bundled/sirsoft-basic/editor-spec';
const LANG = 'templates/_bundled/sirsoft-basic/lang/partial';

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
    (c.advanced?.length ?? 0) > 0
  );
};

describe('sirsoft-basic draggable 커버리지', () => {
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

describe('sirsoft-basic 라벨 i18n 해석 (ko/en)', () => {
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

describe('무용 propControl 제거 회귀 가드 (브라우저 실측 정정)', () => {
  // 1-b-verify-1 브라우저 실측: basic `Button.tsx` 는 `variant`/`size` prop 을
  // 인터페이스에만 선언하고 render 에서 미사용(baseClasses+className 만 출력).
  // 따라서 `buttonVariant`/`componentSize` propControl 은 편집해도 캔버스/실사용자
  // 화면에 반영되지 않는 무용 컨트롤이었다(분류 ② — 미렌더). 제거 후 회귀 방지.
  // 버튼 외형은 styleControls(textColor/backgroundColor/fontBold 등) + 작성자
  // className 이 SSoT. `componentDisabled` 만 유효(disabled 는 {...props} 로 DOM 도달).
  it('basic Button 은 무용 prop(variant/size)을 propControl 로 노출하지 않는다', () => {
    const pc: string[] = caps.Button?.propControls ?? [];
    expect(pc).not.toContain('buttonVariant');
    expect(pc).not.toContain('componentSize');
  });

  it('basic Button 은 유효 prop(componentDisabled)은 유지한다', () => {
    const pc: string[] = caps.Button?.propControls ?? [];
    expect(pc).toContain('componentDisabled');
  });
});

describe('sirsoft-basic children 노드 에디터(STRUCT-TREE)', () => {
  // 목록/컨테이너 컴포넌트는 nodeEditor:{kind:"children",params:{childComponent}} 로
  // 자식 추가/삭제/정렬을 속성 모달 본체에서 편집한다(부록4-ter 일반 슬롯). Ol 은
  // sirsoft-basic 에 미등록(컴포넌트 부재)이라 대상에서 제외.
  const expected: Record<string, string> = { Ul: 'Li', Nav: 'A', Form: 'Input', Li: 'Span' };
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

describe('sirsoft-basic dataProps 전수 선언 + 직교 가드', () => {
  const dpComponents = Object.keys(caps).filter((k) => Array.isArray(caps[k]?.dataProps));

  it('대표 컬렉션이 배열 dataProps 를 보유한다 (images/reactions/suggestions)', () => {
    const expectArray: Array<[string, string]> = [
      ['ImageGallery', 'images'],
      ['ProductImageViewer', 'images'],
      ['PostReactions', 'reactions'],
      ['SearchBar', 'suggestions'],
    ];
    for (const [comp, key] of expectArray) {
      const dp = (caps[comp]?.dataProps ?? []).find((d: { propKey: string }) => d.propKey === key);
      expect(dp, `${comp}.${key}`).toBeTruthy();
      expect(dp.shape).toBe('array');
    }
  });

  it('단일 객체 컴포넌트는 object dataProp (ProductCard.product / Avatar.author / UserInfo.author)', () => {
    expect((caps.ProductCard?.dataProps ?? []).find((d: { propKey: string }) => d.propKey === 'product')?.shape).toBe('object');
    expect((caps.Avatar?.dataProps ?? []).find((d: { propKey: string }) => d.propKey === 'author')?.shape).toBe('object');
    expect((caps.UserInfo?.dataProps ?? []).find((d: { propKey: string }) => d.propKey === 'author')?.shape).toBe('object');
  });

  it('입력 value 는 scalar dataProp (Input/Textarea/QuantitySelector)', () => {
    for (const comp of ['Input', 'Textarea', 'QuantitySelector']) {
      expect((caps[comp]?.dataProps ?? []).find((d: { propKey: string }) => d.propKey === 'value')?.shape).toBe('scalar');
    }
  });

  // 입력/선택 계열 누락 메움. Select/PasswordInput value + Select options,
  // SearchBar value. basic 미보유 컴포넌트(SearchableDropdown 등)는 admin 가드에서 검증.
  it('Select 가 value(scalar) + options(array) dataProp 을 보유한다', () => {
    const sel = caps.Select?.dataProps ?? [];
    expect(sel.find((d: { propKey: string }) => d.propKey === 'value')?.shape).toBe('scalar');
    expect(sel.find((d: { propKey: string }) => d.propKey === 'options')?.shape).toBe('array');
  });

  it('PasswordInput.value / SearchBar.value 가 scalar dataProp', () => {
    expect((caps.PasswordInput?.dataProps ?? []).find((d: { propKey: string }) => d.propKey === 'value')?.shape).toBe('scalar');
    expect((caps.SearchBar?.dataProps ?? []).find((d: { propKey: string }) => d.propKey === 'value')?.shape).toBe('scalar');
  });

  it('options 직교 완화: Select.options 가 dataProps(array)와 propControls(selectOptions) 둘 다에 노출', () => {
    expect((caps.Select?.dataProps ?? []).some((d: { propKey: string }) => d.propKey === 'options')).toBe(true);
    expect((caps.Select?.propControls ?? []).includes('selectOptions')).toBe(true);
  });

  it('TabNavigation.tabs 는 dataProps 가 아니다 (nodeEditor 정적 편집 — 직교)', () => {
    const tabsDp = (caps.TabNavigation?.dataProps ?? []).find((d: { propKey: string }) => d.propKey === 'tabs');
    expect(tabsDp).toBeUndefined();
    expect(caps.TabNavigation?.nodeEditor?.params?.arrayProp).toBe('tabs');
  });

  it('직교: dataProps.propKey 가 nodeEditor.arrayProp 와 충돌하지 않는다', () => {
    const conflicts: string[] = [];
    for (const c of dpComponents) {
      const arrayProp = caps[c]?.nodeEditor?.params?.arrayProp;
      if (!arrayProp) continue;
      for (const d of caps[c].dataProps) {
        if (d.propKey === arrayProp) conflicts.push(`${c}.${d.propKey}`);
      }
    }
    expect(conflicts).toEqual([]);
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

describe('sirsoft-basic stateLabels 카탈로그', () => {
  const spec = load('templates/_bundled/sirsoft-basic/editor-spec/stateLabels.json');
  it('stateLabels 가 배열이고 state_label 키가 ko/en 에서 해석된다', () => {
    expect(Array.isArray(spec)).toBe(true);
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
