// e2e:allow 레이아웃 편집기 SEO 구조화 데이터 통편집 — 합성 데이터칩/토글 의존, Chrome MCP 매트릭스(세션 D) + 단위로 검증
/**
 * SeoStructuredDataEditor.test.tsx — 통 덮어쓰기 토글 4상태 RTL
 *
 * 검증:
 *  ① OFF(자동): 모듈 자동 블록 읽기전용 미리보기 + 사전 경고
 *  ② ON 전환: 통교체 상시 경고 + 단일 @type + 점 경로 키–값 평탄↔중첩(`offers.price`→중첩)
 *  ③ "모듈 자동값 불러와 시작" → 모듈 블록 복사 시드
 *  ④ 진입 시 structured_data 선언 있으면 토글 ON(상태4)
 *  ⑤ filter 등록 → 토글 비활성 + 블록 읽기전용 + filteredBlock 표시(상태3)
 *  ⑥ 빈 값 항목 "노출 안 됨" 안내
 *  ⑦ page_type 표시(자동 블록 재계산 컨텍스트)
 *  ⑧ 단일 @type만(평탄↔중첩 직렬화 단위 — flatten/nest)
 *
 * @since engine-v1.50.0
 */

import React from 'react';
import { describe, it, expect, vi, afterEach } from 'vitest';
import { render, screen, fireEvent, cleanup } from '@testing-library/react';
import {
  SeoStructuredDataEditor,
  flattenStructured,
  nestStructured,
} from '../../../components/page-settings/SeoStructuredDataEditor';

const t = (k: string) => k;
afterEach(() => cleanup());

const autoBlock = {
  '@type': 'Product',
  name: '{{product.data.name}}',
  offers: { price: '{{product.data.price}}' },
};

describe('flatten/nest 직렬화', () => {
  it('중첩 → 점 경로 평탄(@type/@context 제외)', () => {
    const flat = flattenStructured(autoBlock);
    const keys = flat.map((r) => r.key);
    expect(keys).toContain('name');
    expect(keys).toContain('offers.price');
    expect(keys).not.toContain('@type');
  });

  it('점 경로 평탄 + @type → 중첩 복원(offers.price → {offers:{price}})', () => {
    const nested = nestStructured('Product', [
      { key: 'name', value: 'X' },
      { key: 'offers.price', value: '100' },
    ]);
    expect(nested['@type']).toBe('Product');
    expect((nested.offers as Record<string, unknown>).price).toBe('100');
  });
});

describe('SeoStructuredDataEditor — 4상태', () => {
  it('① OFF(자동): 모듈 자동 블록 읽기전용 + 사전 경고', () => {
    render(<SeoStructuredDataEditor value={null} onChange={vi.fn()} autoBlock={autoBlock} pageType="product" t={t} />);
    expect(screen.getByTestId('g7le-seo-sd-auto-preview')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-seo-sd-auto-row-offers.price')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-seo-sd-replace-warn')).toBeInTheDocument();
    // 토글 OFF — 수동 편집 영역 없음. (ToggleSwitch = role=switch 버튼 → aria-checked)
    expect(screen.queryByTestId('g7le-seo-sd-manual')).not.toBeInTheDocument();
    expect(screen.getByTestId('g7le-seo-sd-mode')).toHaveAttribute('aria-checked', 'false');
  });

  it('② ON 전환(자동 블록 有, 레이아웃 선언 無): 모듈 자동값을 자동 시드', () => {
    const onChange = vi.fn();
    render(<SeoStructuredDataEditor value={null} onChange={onChange} autoBlock={autoBlock} t={t} />);
    fireEvent.click(screen.getByTestId('g7le-seo-sd-mode'));
    expect(screen.getByTestId('g7le-seo-sd-manual')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-seo-sd-replace-warn')).toBeInTheDocument();
    // 종전엔 빈 WebPage 였으나, 이제 "모듈값 불러오기"를 누르지 않아도 모듈 자동 블록이 출발점으로 시드된다.
    const seeded = onChange.mock.calls[onChange.mock.calls.length - 1][0];
    expect(seeded['@type']).toBe('Product');
    expect(seeded.name).toBe('{{product.data.name}}');
    expect((seeded.offers as Record<string, unknown>).price).toBe('{{product.data.price}}');
    // @type 변경 → 중첩 객체 갱신(시드 행 보존).
    fireEvent.change(screen.getByTestId('g7le-seo-sd-type'), { target: { value: 'Recipe' } });
    expect(onChange.mock.calls[onChange.mock.calls.length - 1][0]['@type']).toBe('Recipe');
  });

  it('② ON 전환(자동 블록 無): 빈 @type 블록(시드할 모듈값 없음)', () => {
    const onChange = vi.fn();
    render(<SeoStructuredDataEditor value={null} onChange={onChange} t={t} />);
    fireEvent.click(screen.getByTestId('g7le-seo-sd-mode'));
    expect(onChange).toHaveBeenLastCalledWith({ '@type': 'WebPage' });
  });

  it('② 점 경로 속성 추가 → 중첩 복원 onChange', () => {
    const onChange = vi.fn();
    render(<SeoStructuredDataEditor value={{ '@type': 'WebPage' }} onChange={onChange} t={t} />);
    fireEvent.click(screen.getByTestId('g7le-seo-sd-add'));
    const keyInput = screen.getByTestId(/g7le-seo-sd-prop-key-/);
    fireEvent.change(keyInput, { target: { value: 'breadcrumb.0' } });
    const last = onChange.mock.calls[onChange.mock.calls.length - 1][0];
    expect(last['@type']).toBe('WebPage');
    expect((last.breadcrumb as Record<string, unknown>)['0']).toBe('');
  });

  it('③ "모듈 자동값 불러와 시작" → 모듈 블록 복사 시드', () => {
    const onChange = vi.fn();
    render(<SeoStructuredDataEditor value={{ '@type': 'WebPage' }} onChange={onChange} autoBlock={autoBlock} t={t} />);
    fireEvent.click(screen.getByTestId('g7le-seo-sd-seed-from-module'));
    const last = onChange.mock.calls[onChange.mock.calls.length - 1][0];
    expect(last['@type']).toBe('Product');
    expect(last.name).toBe('{{product.data.name}}');
    expect((last.offers as Record<string, unknown>).price).toBe('{{product.data.price}}');
  });

  it('④ 진입 시 structured_data 선언 있으면 토글 ON', () => {
    render(<SeoStructuredDataEditor value={{ '@type': 'Article', headline: 'X' }} onChange={vi.fn()} t={t} />);
    expect(screen.getByTestId('g7le-seo-sd-mode')).toHaveAttribute('aria-checked', 'true');
    expect(screen.getByTestId('g7le-seo-sd-manual')).toBeInTheDocument();
    expect((screen.getByTestId('g7le-seo-sd-type') as HTMLInputElement).value).toBe('Article');
  });

  it('⑤ filter 잠김 → 토글 부재 + filteredBlock 읽기전용', () => {
    const filteredBlock = { '@type': 'Product', name: '{{product.data.name}}' };
    render(
      <SeoStructuredDataEditor value={null} onChange={vi.fn()} lockedByFilter filteredBlock={filteredBlock} t={t} />,
    );
    expect(screen.getByTestId('g7le-seo-sd-locked')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-seo-sd-filtered-block')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-seo-sd-filtered-row-name')).toBeInTheDocument();
    // 토글 비활성 — mode 체크박스 자체 미렌더(제어 불가).
    expect(screen.queryByTestId('g7le-seo-sd-mode')).not.toBeInTheDocument();
  });

  it('⑥ 빈 값 항목 → "노출 안 됨" 안내(빈 값 경고)', () => {
    render(<SeoStructuredDataEditor value={{ '@type': 'WebPage', name: '' }} onChange={vi.fn()} t={t} />);
    expect(screen.getByTestId('g7le-seo-sd-empty-note')).toBeInTheDocument();
  });

  // ── 묶음③ 자동 블록 연결 칩(autoMeta) ──
  describe('연결 칩', () => {
    const autoMeta = {
      name: { expr: '{{product.data.name}}', label: '상품 이름' },
      'offers.price': { expr: '{{product.data.selling_price}}', label: '판매가' },
    };

    it('OFF 자동 미리보기 — autoMeta 있는 속성은 평문 대신 "상품 이름" 연결 칩', () => {
      render(<SeoStructuredDataEditor value={null} onChange={vi.fn()} autoBlock={autoBlock} autoMeta={autoMeta} pageType="product" t={t} />);
      const nameChip = screen.getByTestId('g7le-seo-sd-auto-chip-name');
      expect(nameChip).toHaveTextContent('상품 이름');
      expect(nameChip).toHaveAttribute('title', '{{product.data.name}}');
      expect(screen.getByTestId('g7le-seo-sd-auto-chip-offers.price')).toHaveTextContent('판매가');
    });

    it('회귀: autoMeta 없으면 종전 평문 행 유지(칩 미노출)', () => {
      render(<SeoStructuredDataEditor value={null} onChange={vi.fn()} autoBlock={autoBlock} pageType="product" t={t} />);
      // autoMeta 미전달 → 칩 미노출, 자동 행은 평문으로 그대로.
      expect(screen.queryByTestId('g7le-seo-sd-auto-chip-name')).not.toBeInTheDocument();
      expect(screen.getByTestId('g7le-seo-sd-auto-row-name')).toBeInTheDocument();
    });

    // "직접 지정"으로 켜니 raw 평문이 나옴 → autoMeta 있는 키는 데이터 경로(expr)로 시드돼야.
    // autoBlock 은 resolve 된 평문(production 에서 모든 상품에 그 값이 박힘) — 시드값은 데이터 연결이어야 한다.
    it('ON 시드 — autoMeta 있는 키는 평문(autoBlock) 아닌 데이터 경로(expr)로 시드', () => {
      // autoBlock 은 resolve 된 평문, autoMeta 는 데이터 경로.
      const resolvedAutoBlock = { '@type': 'Product', name: '베이직 오버핏 티셔츠', offers: { price: '23200' } };
      const onChange = vi.fn();
      render(
        <SeoStructuredDataEditor
          value={null}
          onChange={onChange}
          autoBlock={resolvedAutoBlock}
          autoMeta={autoMeta}
          pageType="product"
          t={t}
        />,
      );
      // 토글 ON.
      fireEvent.click(screen.getByTestId('g7le-seo-sd-mode'));
      const seeded = onChange.mock.calls[onChange.mock.calls.length - 1][0];
      // name/offers.price 는 autoMeta 데이터 경로로(평문 아님).
      expect(seeded.name).toBe('{{product.data.name}}');
      expect((seeded.offers as Record<string, unknown>).price).toBe('{{product.data.selling_price}}');
      // 평문이 시드되면 안 됨.
      expect(seeded.name).not.toBe('베이직 오버핏 티셔츠');
    });

    it('ON 시드 — autoMeta 없는 키는 종전대로 autoBlock 평문 시드(하위호환)', () => {
      // sku 는 autoMeta 에 없음 → autoBlock 평문 유지.
      const resolvedAutoBlock = { '@type': 'Product', name: '티셔츠', sku: 'TS-1001' };
      const partialMeta = { name: { expr: '{{product.data.name}}', label: '상품 이름' } };
      const onChange = vi.fn();
      render(
        <SeoStructuredDataEditor value={null} onChange={onChange} autoBlock={resolvedAutoBlock} autoMeta={partialMeta} t={t} />,
      );
      fireEvent.click(screen.getByTestId('g7le-seo-sd-mode'));
      const seeded = onChange.mock.calls[onChange.mock.calls.length - 1][0];
      expect(seeded.name).toBe('{{product.data.name}}'); // 메타 있음 → 경로.
      expect(seeded.sku).toBe('TS-1001'); // 메타 없음 → 평문 유지.
    });

    it('ON 시드 후 그 행 값은 DataChipValueInput 으로 칩 렌더(데이터 경로라 칩)', () => {
      const resolvedAutoBlock = { '@type': 'Product', name: '베이직 티셔츠' };
      render(
        <SeoStructuredDataEditor value={null} onChange={vi.fn()} autoBlock={resolvedAutoBlock} autoMeta={autoMeta} t={t} />,
      );
      fireEvent.click(screen.getByTestId('g7le-seo-sd-mode'));
      // 편집 행의 값 입력기(DataChipValueInput)가 데이터 경로를 칩으로 — name 행 값에 raw 평문 미노출.
      expect(screen.getByTestId('g7le-seo-sd-manual')).toBeInTheDocument();
      // 행 값에 resolve 된 평문이 그대로 박혀 있지 않아야 한다(칩/경로로 전환).
      expect(screen.queryByDisplayValue('베이직 티셔츠')).not.toBeInTheDocument();
    });
  });
});
