// e2e:allow 편집기 갭 보강(신규 composite capability/editorAttrs/draggable + ecommerce 신규 화면 states) 회귀 가드 — Vitest 정합 가드. 라이브 E2E 는 Chrome MCP base 편집/route states 실측이 별도 커버.
/**
 * editorGapCoverage.test.ts — 레이아웃 편집기 대응 갭 보강 회귀 가드
 *
 * 배경: 편집기 editor-spec 정리(2026-06-15) 이후 추가된
 * 컴포넌트/화면이 편집기에 미반영된 갭을 보강했다. 본 테스트는 그 보강이 회귀하지
 * 않도록 SSoT(editor-spec 분할 소스 + 컴포넌트 tsx + 모듈 editor-spec)를 직접 읽어
 * 가드한다.
 *
 * 가드 항목:
 *  1. 신규 6개 admin composite 가 nesting.draggable 등재 + capability 보유.
 *  2. 그 6개 tsx 가 editorAttrs 를 시각 루트에 spread (선택/클릭 가능 — 검수자가 직접
 *     발견한 AdminSidebar/AdminFooter 선택 불가 결함의 회귀 가드).
 *  3. SectionHeader 가 componentPalette 에 등재 (팔레트 추가 가능).
 *  4. ecommerce editor-spec 에 신규 admin 화면 5개 states 그룹 등록 (상태 토글 노출).
 *  5. ProductCard product itemFields 가 다통화/하이라이트 등 신규 데이터 필드 포함.
 *
 * @since engine-v1.50.0
 */

import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const ROOT = process.cwd();

function loadJson(rel: string): any {
  return JSON.parse(readFileSync(resolve(ROOT, rel), 'utf8'));
}

function readText(rel: string): string {
  return readFileSync(resolve(ROOT, rel), 'utf8');
}

/** 이번 갭 보강으로 admin 편집 대상이 된 신규 composite (base 골격 + 화면 위젯). */
const ADMIN_NEW_COMPONENTS = [
  'AdminSidebar',
  'AdminFooter',
  'ThemeToggle',
  'NotificationCenter',
  'ColumnSelector',
  'SectionHeader',
];

const ADMIN = 'templates/_bundled/sirsoft-admin_basic';

describe('편집기 갭 보강 — 신규 admin composite 편집 가능성', () => {
  const nesting = loadJson(`${ADMIN}/editor-spec/nesting.json`);
  const capabilities = loadJson(`${ADMIN}/editor-spec/componentCapabilities.json`);

  it('신규 6개 컴포넌트가 모두 nesting.draggable 에 등재 (재배치 가능)', () => {
    for (const name of ADMIN_NEW_COMPONENTS) {
      expect(nesting.draggable, `${name} draggable 누락`).toContain(name);
    }
  });

  it('신규 6개 컴포넌트가 모두 componentCapabilities 에 비-빈 항목 보유 (속성 편집 가능)', () => {
    for (const name of ADMIN_NEW_COMPONENTS) {
      const cap = capabilities[name];
      expect(cap, `${name} capability 누락`).toBeTruthy();
      const surfaces =
        (cap.propControls?.length ?? 0) +
        (cap.styleControls?.length ?? 0) +
        (cap.dataProps?.length ?? 0);
      expect(surfaces, `${name} 편집 표면 0`).toBeGreaterThan(0);
    }
  });

  it('신규 6개 컴포넌트가 최소 한 컨테이너의 accepts 에 포함 (드롭 가능)', () => {
    for (const name of ADMIN_NEW_COMPONENTS) {
      const acceptedBy = Object.keys(nesting.containers).filter((c) =>
        (nesting.containers[c].accepts ?? []).includes(name),
      );
      expect(acceptedBy.length, `${name} 어느 컨테이너에도 미수용`).toBeGreaterThan(0);
    }
  });

  it('SectionHeader 가 componentPalette 에 등재 (팔레트에서 새로 추가 가능)', () => {
    const palette = JSON.stringify(loadJson(`${ADMIN}/editor-spec/componentPalette.json`));
    expect(palette).toContain('SectionHeader');
  });
});

describe('편집기 갭 보강 — editorAttrs 시각 루트 spread (선택/클릭 가능)', () => {
  // base 골격/위젯이 편집기 주입 editorAttrs 를 시각 루트에 흘려야 클릭이 그 노드에 닿는다.
  // (검수자가 base 편집 모드에서 AdminSidebar/AdminFooter 클릭 시 부모 Div 만 선택되던 결함)
  const FILES: Record<string, string> = {
    AdminSidebar: `${ADMIN}/src/components/composite/AdminSidebar.tsx`,
    AdminFooter: `${ADMIN}/src/components/composite/AdminFooter.tsx`,
    ThemeToggle: `${ADMIN}/src/components/composite/ThemeToggle.tsx`,
    NotificationCenter: `${ADMIN}/src/components/composite/NotificationCenter.tsx`,
    ColumnSelector: `${ADMIN}/src/components/composite/ColumnSelector.tsx`,
    SectionHeader: `${ADMIN}/src/components/composite/SectionHeader.tsx`,
  };

  for (const [name, path] of Object.entries(FILES)) {
    it(`${name} 가 editorAttrs 를 수신하고 루트에 spread`, () => {
      const src = readText(path);
      expect(src, `${name} editorAttrs prop 미수신`).toMatch(/editorAttrs/);
      // 시각 루트에 {...editorAttrs} spread (메모리: 시각 루트 도달 필수)
      expect(src, `${name} editorAttrs 루트 spread 누락`).toMatch(/\{\.\.\.editorAttrs\}/);
    });
  }
});

describe('편집기 갭 보강 — ecommerce 신규 admin 화면 states', () => {
  const spec = loadJson('modules/_bundled/sirsoft-ecommerce/editor-spec.json');
  const matches = (spec.states?.groups ?? []).map((g: any) => g.scope?.match);

  const EXPECTED_MATCHES = [
    '*/admin/ecommerce/mileage-transactions',
    '*/admin/ecommerce/promotion-coupons',
    '*/admin/ecommerce/promotion-coupon-create',
    '*/admin/ecommerce/orders',
    '*/admin/ecommerce/orders/:orderNumber',
  ];

  it('신규 5개 화면 states 그룹이 모두 등록 (상태 토글 노출)', () => {
    for (const m of EXPECTED_MATCHES) {
      expect(matches, `states 그룹 누락: ${m}`).toContain(m);
    }
  });

  it('신규 states 그룹의 모든 라벨 키가 admin 템플릿 lang(ko/en)에서 해석', () => {
    const ko = loadJson(`${ADMIN}/lang/partial/ko/editor.json`);
    const en = loadJson(`${ADMIN}/lang/partial/en/editor.json`);
    const get = (o: any, path: string) => path.split('.').reduce((a, k) => a?.[k], o);

    const newGroups = (spec.states.groups as any[]).filter((g) =>
      EXPECTED_MATCHES.includes(g.scope?.match),
    );
    expect(newGroups.length).toBe(5);

    for (const g of newGroups) {
      for (const item of g.items ?? []) {
        if (typeof item.label === 'string' && item.label.startsWith('$t:editor.')) {
          const key = item.label.replace('$t:editor.', '');
          expect(get(ko, key), `ko 라벨 누락: ${key}`).toBeTruthy();
          expect(get(en, key), `en 라벨 누락: ${key}`).toBeTruthy();
        }
      }
    }
  });

  it('마일리지 모달 _global 키가 sampleGlobal 에 시드 (편집기 캔버스 초기값)', () => {
    const sg = spec.sampleGlobal ?? {};
    expect(sg).toHaveProperty('mileageManual');
    expect(sg).toHaveProperty('mileageEdit');
    expect(sg).toHaveProperty('mileageExtend');
  });
});

describe('편집기 갭 보강 — ProductCard 신규 데이터 필드 (다통화/하이라이트)', () => {
  it('basic ProductCard product itemFields 가 신규 필드 포함', () => {
    const cap = loadJson('templates/_bundled/sirsoft-basic/editor-spec/componentCapabilities.json');
    const product = (cap.ProductCard?.dataProps ?? []).find((d: any) => d.propKey === 'product');
    expect(product, 'ProductCard product dataProp 누락').toBeTruthy();
    const fields: string[] = product.itemFields ?? [];
    for (const f of [
      'multi_currency_selling_price',
      'multi_currency_list_price',
      'name_highlighted',
      'sales_status',
      'brand_name',
      'rating_avg',
    ]) {
      expect(fields, `itemFields 누락: ${f}`).toContain(f);
    }
  });
});
