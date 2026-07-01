/**
 * @file editor-spec-default-node.test.tsx
 * @description 레이아웃 편집기 팔레트 defaultNode 공통 디자인 className 회귀 테스트 (sirsoft-admin_basic)
 *
 * 배경:
 *   팔레트에서 컴포넌트를 추가하면 editor-spec.json 의
 *   componentPalette.entries[name].defaultNode 가 깊은 복사되어 레이아웃 트리에 삽입된다.
 *   본 작업에서 각 defaultNode.props.className 을 이 admin 템플릿 운영 레이아웃의 대표
 *   Tailwind 패턴으로 채우고, 일부 prop 키 불일치 결함(HtmlContent html→content,
 *   DataGrid columns key/label→field/header, rows→data)을 정정했다.
 *
 * 본 테스트는:
 *   1. defaultNode 가 공통 디자인 className 을 보유함을 JSON 정합성으로 검증
 *   2. Button defaultNode 가 variant/size 를 더 이상 사용하지 않고 className 만 씀을 검증
 *   3. prop 키 정정(HtmlContent / DataGrid)이 회귀로 되돌아가지 않음을 검증
 *   4. A단계 신규 entries(Form/Table/Accordion 등 22개) 등록 + composite 샘플 props 유지
 *   5. defaultNode 를 실제 레이아웃으로 렌더했을 때 className 이 DOM 에 적용됨을 검증
 */

import { describe, it, expect } from 'vitest';
import {
  createLayoutTest,
  createMockComponentRegistryWithBasics,
} from '@core/template-engine/__tests__/utils/layoutTestUtils';

// editor-spec 블록 분할(ce21ab9da) 이후 root editor-spec.json 은 $include 참조만 가지므로
// componentPalette 블록을 분할 파일에서 직접 import 한다(root 스텁엔 .entries 가 없음).
import componentPalette from '../../editor-spec/componentPalette.json';

type DefaultNode = {
  type?: string;
  name?: string;
  props?: Record<string, any>;
  text?: string;
  children?: DefaultNode[];
};

const entries: Record<string, { defaultNode?: DefaultNode }> = (componentPalette as any)
  .entries;

function wrapAsLayout(node: DefaultNode) {
  return {
    name: 'editor-default-node-probe',
    components: [
      {
        ...node,
        id: 'probe',
      },
    ],
  };
}

describe('sirsoft-admin_basic editor-spec defaultNode 공통 디자인', () => {
  describe('JSON 정합성 — defaultNode className 보유', () => {
    const designBearingBasics = [
      'Button',
      'Input',
      'Textarea',
      'Select',
      'Checkbox',
      'Label',
      'H1',
      'H2',
      'H3',
      'H4',
      'P',
      'A',
      'Code',
      'Pre',
      'Hr',
      'Ul',
      'Ol',
      'Nav',
      'Flex',
      'Grid',
      'Section',
    ];

    it.each(designBearingBasics)('%s defaultNode 가 비어있지 않은 className 을 보유한다', (name) => {
      const node = entries[name]?.defaultNode;
      expect(node, `${name} entry 가 존재해야 함`).toBeDefined();
      const className = node?.props?.className;
      expect(typeof className).toBe('string');
      expect((className as string).trim().length).toBeGreaterThan(0);
    });

    it('className 토큰이 admin 디자인 시스템 토큰을 반영한다', () => {
      const button = entries.Button?.defaultNode?.props?.className as string;
      expect(button).toContain('rounded-lg');
      expect(button).toContain('bg-blue-600');
      // Input 은 폼 CRUD 표준 유틸 클래스 "input" 사용
      expect(entries.Input?.defaultNode?.props?.className).toBe('input');
    });
  });

  describe('Button — variant/size 제거, className 만 사용', () => {
    it('Button defaultNode 가 variant/size props 를 사용하지 않는다', () => {
      const props = entries.Button?.defaultNode?.props ?? {};
      expect(props.variant).toBeUndefined();
      expect(props.size).toBeUndefined();
      expect(typeof props.className).toBe('string');
      expect(props.type).toBe('button');
    });
  });

  describe('prop 키 정정 — 회귀 차단', () => {
    it('HtmlContent 가 composite + content/isHtml 키를 사용한다 (html 키 미사용)', () => {
      const node = entries.HtmlContent?.defaultNode;
      expect(node?.type).toBe('composite');
      expect(node?.props?.content).toBeTruthy();
      expect(node?.props?.isHtml).toBe(false);
      expect(node?.props?.html).toBeUndefined();
    });

    it('DataGrid 가 columns[].{field,header} + data 키를 사용한다 (key/label/rows 미사용)', () => {
      const node = entries.DataGrid?.defaultNode;
      expect(node?.type).toBe('composite');
      expect(Array.isArray(node?.props?.data)).toBe(true);
      expect(node?.props?.rows).toBeUndefined();
      const cols = node?.props?.columns ?? [];
      expect(cols.length).toBeGreaterThan(0);
      for (const col of cols) {
        expect(col.field).toBeTruthy();
        expect(col.header).toBeTruthy();
        expect(col.key).toBeUndefined();
        expect(col.label).toBeUndefined();
      }
    });
  });

  describe('A단계 신규 entries — 22개 전체 등록', () => {
    const newEntries = [
      'Form',
      'Table',
      'Accordion',
      'IconButton',
      'Dropdown',
      'ActionMenu',
      'TagInput',
      'SearchableDropdown',
      'RichSelect',
      'TagSelect',
      'IconSelect',
      'ChipCheckbox',
      'MultilingualInput',
      'MultilingualTagInput',
      'LanguageSelector',
      'PermissionTree',
      'CategoryTree',
      'ExtensionBadge',
      'UserProfile',
      'FilterGroup',
      'SortableMenuList',
      'DynamicFieldList',
    ];

    it.each(newEntries)('%s entry 가 defaultNode 와 함께 등록되어 있다', (name) => {
      const node = entries[name]?.defaultNode;
      expect(node, `${name} entry 가 존재해야 함`).toBeDefined();
      expect(node?.name).toBe(name);
    });

    it('Form defaultNode 가 admin 폼 표준(FormField + Button)을 동반하고 FormField 가 Input 을 내포한다', () => {
      const node = entries.Form?.defaultNode;
      expect(node?.props?.className).toBeTruthy();
      const childNames = (node?.children ?? []).map((c) => c.name);
      expect(childNames).toEqual(expect.arrayContaining(['FormField', 'Button']));

      const formField = (node?.children ?? []).find((c) => c.name === 'FormField');
      const innerNames = (formField?.children ?? []).map((c) => c.name);
      expect(innerNames).toContain('Input');
    });

    it('Table defaultNode 가 table className 을 보유한다', () => {
      const node = entries.Table?.defaultNode;
      expect(node?.props?.className).toContain('w-full');
    });
  });

  describe('실제 렌더링 — defaultNode className 이 DOM 에 적용됨', () => {
    const renderCases: Array<[string, string]> = [
      ['Button', 'bg-blue-600'],
      ['Input', 'input'],
      ['Label', 'font-medium'],
      ['H1', 'font-semibold'],
    ];

    it.each(renderCases)('%s defaultNode 렌더 시 className "%s" 가 DOM 에 반영된다', async (name, token) => {
      const node = entries[name]?.defaultNode as DefaultNode;
      const registry = createMockComponentRegistryWithBasics();
      const t = createLayoutTest(wrapAsLayout(node), {
        templateId: 'sirsoft-admin_basic',
        componentRegistry: registry,
        locale: 'ko',
      });
      const { container } = await t.render();
      const el = container.querySelector(`.${CSS.escape(token)}`);
      expect(el, `${name} 의 "${token}" className 요소가 렌더되어야 함`).not.toBeNull();
      t.cleanup();
    });
  });

  // defaultNode prop shape 결함 회귀 가드.
  // 라이브 검증에서 발견한 defaultNode↔컴포넌트 shape 불일치 결함들이 되돌아가지 않게 한다.
  describe('defaultNode prop shape 정합', () => {
    it('BarChart defaultNode 는 labels + datasets 를 시드한다(data shape 아님)', () => {
      const props = entries.BarChart?.defaultNode?.props ?? {};
      // 과거 결함: data:[{label,value}] → datasets.map 크래시. 컴포넌트는 labels+datasets 를 읽음.
      expect(Array.isArray(props.labels)).toBe(true);
      expect(Array.isArray(props.datasets)).toBe(true);
      expect(props.data).toBeUndefined();
    });

    it('DonutChart defaultNode 는 data 항목이 name+color 를 가진다', () => {
      const props = entries.DonutChart?.defaultNode?.props ?? {};
      expect(Array.isArray(props.data)).toBe(true);
      // 과거 결함: {label,value} → 컴포넌트는 item.name/item.color 를 읽어 undefined 라벨/색.
      for (const item of props.data) {
        expect(item).toHaveProperty('name');
        expect(item).toHaveProperty('color');
      }
    });

    it('ExtensionBadge defaultNode type 은 유효 값(module|plugin)이다', () => {
      const props = entries.ExtensionBadge?.defaultNode?.props ?? {};
      // 과거 결함: type:"core" → ExtensionType 미정의 → typeLabels[type]=undefined 렌더.
      expect(['module', 'plugin']).toContain(props.type);
    });

    it('SortableMenuList / CategoryTree defaultNode 는 가시 시드 데이터를 가진다', () => {
      const sml = entries.SortableMenuList?.defaultNode?.props ?? {};
      const cat = entries.CategoryTree?.defaultNode?.props ?? {};
      // 빈 데이터 → 0×0 비가시(편집기 선택 불가) 방지로 샘플 항목 시드.
      expect(Array.isArray(sml.items) && sml.items.length).toBeGreaterThan(0);
      expect(Array.isArray(cat.data) && cat.data.length).toBeGreaterThan(0);
    });
  });
});
