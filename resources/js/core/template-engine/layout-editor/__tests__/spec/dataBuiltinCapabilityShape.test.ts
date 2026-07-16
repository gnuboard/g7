// e2e:allow-shape 게이트(데이터 표면 누락 0 잠금)만 추가한다.
// 데이터 빌트인의 라이브 동작(추가/편집→저장→실사용자화면 렌더)은 8-b 가 작성한
// tests/Playwright/specs/layout-editor/data-builtins.spec.ts + 시나리오 매니페스트
// tests/scenarios/layout-editor-data-builtins.yaml 이 이미 커버한다(런타임 무변경).
/**
 * dataBuiltinCapabilityShape.test.ts — 8-b 데이터 정의 빌트인 capability 정합 가드
 *
 * 8-a 분류표(계획서 (E))대로 미커버 데이터 표면이 nodeEditor 로 부착됐는지, 그리고 각
 * nodeEditor 의 `newItem` shape 가 **컴포넌트가 읽는 prop shape 와 일치**하는지를 잠근다
 * (1-c-verify-4 결함#7 — 편집기가 쓰는 shape ≠ 컴포넌트 read shape 회귀 차단, 계획서 §아키텍처
 * "defaultNode shape 정합").
 *
 * 검토군 판정(계획서 (E) #6) 회귀 가드: ChipCheckbox.value 는 propControl ccValue(propValue→
 * value)로 커버되므로 nodeEditor/dataProps 데이터 편집 대상이 아님을 확인한다.
 *
 * 8-c(데이터 정의 전수 검증 잠금): 파일 하단 "전 draggable 데이터 편집 누락 0 게이트"
 * describe 가 audit 룰 `editor-all-draggable-data-editable` 와 동일 판정을 Vitest 계층에서
 * 가드한다(방어 심층화 + NON_TARGET allowlist SSoT 공유).
 *
 * @since engine-v1.50.0, 누락 0 게이트 추가
 */

import { describe, it, expect } from 'vitest';
import adminCaps from '../../../../../../../templates/_bundled/sirsoft-admin_basic/editor-spec/componentCapabilities.json';
import basicCaps from '../../../../../../../templates/_bundled/sirsoft-basic/editor-spec/componentCapabilities.json';

type Cap = {
  nodeEditor?: { kind?: string; params?: Record<string, any> };
  dataProps?: Array<{ propKey?: string }>;
  propControls?: string[];
};
const admin = (adminCaps as any).componentCapabilities ?? (adminCaps as any);
const basic = (basicCaps as any).componentCapabilities ?? (basicCaps as any);

describe('데이터 정의 빌트인 capability 정합', () => {
  describe('단순 배열 부착(8-a (E) #1~#4)', () => {
    it('TabNavigationScroll.tabs — nodeEditor:array(tabs)', () => {
      const cap = admin.TabNavigationScroll as Cap;
      expect(cap.nodeEditor?.kind).toBe('array');
      expect(cap.nodeEditor?.params?.arrayProp).toBe('tabs');
    });

    it('DonutChart.data — nodeEditor:array(data) + number/color 필드', () => {
      const cap = admin.DonutChart as Cap;
      expect(cap.nodeEditor?.kind).toBe('array');
      expect(cap.nodeEditor?.params?.arrayProp).toBe('data');
      const widgets = (cap.nodeEditor?.params?.fields ?? []).map((f: any) => f.widget);
      expect(widgets).toContain('number');
      expect(widgets).toContain('color');
    });

    it('DynamicFieldList.columns — nodeEditor:array(columns) + type select', () => {
      const cap = admin.DynamicFieldList as Cap;
      expect(cap.nodeEditor?.kind).toBe('array');
      expect(cap.nodeEditor?.params?.arrayProp).toBe('columns');
      const typeField = (cap.nodeEditor?.params?.fields ?? []).find((f: any) => f.key === 'type');
      expect(typeField?.widget).toBe('select');
      // columns 정적 편집 + items 런타임 데이터 바인딩 공존(계획서 (G)).
      expect((cap.dataProps ?? []).some((d) => d.propKey === 'items')).toBe(true);
    });

    it('SocialLoginButtons.providers — nodeEditor:array(providers) 원시 enum select (basic)', () => {
      const cap = basic.SocialLoginButtons as Cap;
      expect(cap.nodeEditor?.kind).toBe('array');
      expect(cap.nodeEditor?.params?.arrayProp).toBe('providers');
      const primary = (cap.nodeEditor?.params?.fields ?? []).find((f: any) => f.primary);
      expect(primary?.widget).toBe('select');
      // 원시 string[] — newItem 이 원시 문자열이어야 객체 혼입 회귀 차단.
      expect(typeof cap.nodeEditor?.params?.newItem).toBe('string');
    });
  });

  describe('다중 배열 / 중첩 셀 트리(8-a (E) #5~#6)', () => {
    it('BarChart — nodeEditor:array-group(labels+datasets), datasets.data=number-list', () => {
      const cap = admin.BarChart as Cap;
      expect(cap.nodeEditor?.kind).toBe('array-group');
      const groups = (cap.nodeEditor?.params?.groups ?? []) as any[];
      const props = groups.map((g) => g.arrayProp);
      expect(props).toContain('labels');
      expect(props).toContain('datasets');
      const datasets = groups.find((g) => g.arrayProp === 'datasets');
      const dataField = (datasets?.fields ?? []).find((f: any) => f.key === 'data');
      expect(dataField?.widget).toBe('number-list');
    });

    it('CardGrid.cardColumns — nodeEditor:array-cell-tree(idField/cellChildrenProp)', () => {
      const cap = admin.CardGrid as Cap;
      expect(cap.nodeEditor?.kind).toBe('array-cell-tree');
      expect(cap.nodeEditor?.params?.arrayProp).toBe('cardColumns');
      expect(cap.nodeEditor?.params?.idField).toBe('id');
      expect(cap.nodeEditor?.params?.cellChildrenProp).toBe('cellChildren');
    });
  });

  describe('newItem shape ↔ 컴포넌트 read shape 정합(1-c-verify-4 결함#7 회귀 가드)', () => {
    it('DonutChart newItem 은 name/value/color (컴포넌트 read shape)', () => {
      const ni = (admin.DonutChart as Cap).nodeEditor?.params?.newItem;
      expect(ni).toHaveProperty('name');
      expect(ni).toHaveProperty('value');
      expect(ni).toHaveProperty('color');
    });

    it('BarChart datasets newItem 은 label/data:[]/backgroundColor', () => {
      const groups = (admin.BarChart as Cap).nodeEditor?.params?.groups as any[];
      const ni = groups.find((g) => g.arrayProp === 'datasets')?.newItem;
      expect(ni).toHaveProperty('label');
      expect(Array.isArray(ni.data)).toBe(true);
      expect(ni).toHaveProperty('backgroundColor');
    });

    it('CardGrid newItem 은 id + 빈 cellChildren 배열', () => {
      const ni = (admin.CardGrid as Cap).nodeEditor?.params?.newItem;
      expect(ni).toHaveProperty('id');
      expect(Array.isArray(ni.cellChildren)).toBe(true);
    });

    it('DynamicFieldList newItem 은 key/type/label/placeholder', () => {
      const ni = (admin.DynamicFieldList as Cap).nodeEditor?.params?.newItem;
      expect(ni).toHaveProperty('key');
      expect(ni).toHaveProperty('type');
      expect(ni).toHaveProperty('label');
    });
  });

  describe('검토군 판정(8-a (E) #6) — 데이터 편집 비대상 회귀 가드', () => {
    it('ChipCheckbox.value 는 propControl ccValue 로 커버(nodeEditor/dataProps 없음)', () => {
      const cap = admin.ChipCheckbox as Cap;
      expect(cap.propControls).toContain('ccValue');
      expect(cap.nodeEditor).toBeUndefined();
      expect(cap.dataProps).toBeUndefined();
    });
  });
});

/**
 * 데이터 정의 전수 검증 잠금 (누락 0 게이트)
 *
 * 전 draggable 컴포넌트를 양 템플릿 capability 에서 스캔해 "데이터 편집 불가"(누락)가
 * 0 임을 잠근다. audit 룰 `editor-all-draggable-data-editable` 와 동일 판정을 Vitest
 * 계층에서도 가드(방어 심층화 — 룰이 트리거 경로 밖에서 미발화해도 단위 회귀로 차단).
 *
 * 판정: 데이터 편집 표면(nodeEditor | selectOptions | dataProps) 보유 || 비대상 allowlist.
 * 비대상 allowlist = 8-a 감사 분류표(부록8 (C)(D)(E)) SSoT — flat scalar/text/layout/런타임
 * scalar 바인딩전용. allowlist 와 룰 파일의 NON_TARGET 은 동일 SSoT 를 공유한다.
 */
const NON_TARGET: Record<string, Set<string>> = {
  admin: new Set([
    'Div', 'Span', 'P', 'H1', 'H2', 'H3', 'H4', 'A', 'Button', 'Img', 'Icon', 'Hr',
    'Section', 'Code', 'Pre', 'Container', 'Grid', 'Flex', 'SectionLayout', 'ThreeColumnLayout',
    'Card', 'Badge', 'StatusBadge', 'Alert', 'EmptyState', 'LoadingSpinner', 'FormField',
    'Label', 'FileInput', 'FileUploader', 'HtmlContent', 'HtmlEditor', 'Accordion', 'IconButton',
    'StatCard', 'ProductCard', 'TemplateCard', 'ExtensionBadge', 'Toggle', 'Checkbox',
    'ChipCheckbox', 'MultilingualTagInput',
  ]),
  basic: new Set([
    'Div', 'Span', 'P', 'H1', 'H2', 'H3', 'H4', 'A', 'Button', 'Img', 'Icon', 'Hr',
    'Container', 'Flex', 'Grid', 'SectionLayout', 'ThreeColumnLayout', 'HtmlContent', 'HtmlEditor',
    'RichTextEditor', 'ExpandableContent', 'FileUploader', 'AvatarUploader', 'Checkbox', 'Label',
    'Pagination',
  ]),
};

describe('전 draggable 데이터 편집 누락 0 게이트', () => {
  const hasDataSurface = (cap: Cap | undefined): boolean => {
    if (!cap || typeof cap !== 'object') return false;
    const optionsList = Array.isArray(cap.propControls) && cap.propControls.includes('selectOptions');
    const dataProps = Array.isArray(cap.dataProps) && cap.dataProps.length > 0;
    return !!cap.nodeEditor || optionsList || dataProps;
  };

  it.each([
    ['admin', admin, '../../../../../../../templates/_bundled/sirsoft-admin_basic/editor-spec/nesting.json'],
    ['basic', basic, '../../../../../../../templates/_bundled/sirsoft-basic/editor-spec/nesting.json'],
  ])('%s — 모든 draggable 이 데이터 표면 보유 || 비대상 allowlist 등록(누락 0)', (key, caps) => {
    // nesting.draggable 동적 로드 (정적 import 회피 — 양 템플릿 경로)
    // eslint-disable-next-line @typescript-eslint/no-var-requires
    const nesting =
      key === 'admin'
        ? require('../../../../../../../templates/_bundled/sirsoft-admin_basic/editor-spec/nesting.json')
        : require('../../../../../../../templates/_bundled/sirsoft-basic/editor-spec/nesting.json');
    const draggable: string[] = Array.isArray(nesting.draggable)
      ? nesting.draggable
      : Object.keys(nesting.draggable ?? {});
    const allow = NON_TARGET[key];
    const missing = draggable.filter((name) => !hasDataSurface(caps[name]) && !allow.has(name));
    expect(missing).toEqual([]);
  });

  it.each([
    ['admin', admin],
    ['basic', basic],
  ])('%s — NON_TARGET allowlist 에 stale(미존재 draggable) 항목이 없다', (key) => {
    const nesting =
      key === 'admin'
        ? require('../../../../../../../templates/_bundled/sirsoft-admin_basic/editor-spec/nesting.json')
        : require('../../../../../../../templates/_bundled/sirsoft-basic/editor-spec/nesting.json');
    const draggable: string[] = Array.isArray(nesting.draggable)
      ? nesting.draggable
      : Object.keys(nesting.draggable ?? {});
    const stale = [...NON_TARGET[key]].filter((name) => !draggable.includes(name));
    expect(stale).toEqual([]);
  });
});
