// e2e:allow Vitest 컴포넌트 계약 — 좁은 화면 카드 배경은 7.1.0 실측 K3 후속(KF3-9)에서 Playwright 로 390px 관리자 글 목록(라이트·다크)을 재측정했다.
/**
 * DataGrid 행 단위 클래스(rowClassName) — 행마다 표현식을 풀어 그 행의 tr 에 붙인다.
 *
 * 관리자 게시글 목록은 `rowClassName="{{row.row_type === 'notice' ? 'bg-blue-50 …' : (row.row_type === 'reply' ? 'bg-gray-50 …' : '')}}"`
 * 로 공지·답글 행 배경을 구분한다. 그런데 엔진이 컴포넌트 props 를 그릴 때 이 식을 row 없이 미리 풀어
 * 빈 문자열로 넘겨, 행 배경이 하나도 붙지 않았다(7.1.0 실측 K3: React props `rowClassName: ""`,
 * 답글 행 tr background rgba(0,0,0,0)). DataGrid 는 `rowClassName` 을 skipBindingKeys 로 받아
 * (components.json) 행마다 엔진의 바인딩 해석기로 푼다 — subRowCondition 과 같은 방식이다.
 */
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { render } from '@testing-library/react';
import { DataGrid } from '../DataGrid';
import { DataBindingEngine } from '@core/template-engine/DataBindingEngine';

const columns = [{ field: 'title', header: '제목' }];
const data = [
  { id: 1, title: '공지', row_type: 'notice' },
  { id: 2, title: '답글', row_type: 'reply' },
  { id: 3, title: '일반', row_type: 'normal' },
];
const ROW_CLASS = "{{row.row_type === 'notice' ? 'bg-blue-50' : (row.row_type === 'reply' ? 'bg-gray-50' : '')}}";

/** 행 제목 → 그 tr 의 클래스 토큰 목록 (hover:bg-gray-50 같은 접두 클래스와 구분하려고 토큰으로 비교한다) */
const rowsByTitle = (container: HTMLElement): Record<string, string[]> =>
  Object.fromEntries(
    Array.from(container.querySelectorAll('tbody tr')).map((tr) => [tr.textContent?.trim() ?? '', tr.className.split(/\s+/).filter(Boolean)]),
  );

describe('[case:components-datagrid-row-class-resolved-without-row] DataGrid rowClassName 행 단위 해석', () => {
  let saved: any;
  beforeEach(() => {
    saved = (window as any).G7Core;
    const engine = new DataBindingEngine();
    (window as any).G7Core = { ...(saved ?? {}), getDataBindingEngine: () => engine };
  });
  afterEach(() => {
    (window as any).G7Core = saved;
  });

  // @scenario row_type=notice
  // @effects datagrid_row_class_resolves_per_row
  it('행 표현식을 행마다 풀어 공지·답글 행에만 배경 클래스를 붙인다', () => {
    const { container } = render(<DataGrid columns={columns} data={data} rowClassName={ROW_CLASS} />);
    const rows = rowsByTitle(container);

    expect(rows['공지']).toContain('bg-blue-50');
    expect(rows['답글']).toContain('bg-gray-50');
    expect(rows['일반']).not.toContain('bg-blue-50');
    expect(rows['일반']).not.toContain('bg-gray-50');
  });

  // @scenario row_type=normal
  // @effects datagrid_row_class_static_string_unchanged
  it('표현식이 아닌 고정 문자열은 모든 행에 그대로 붙인다', () => {
    const { container } = render(<DataGrid columns={columns} data={data} rowClassName="row-fixed" />);
    Object.values(rowsByTitle(container)).forEach((cls) => expect(cls).toContain('row-fixed'));
  });

  // @scenario row_type=reply
  // @effects datagrid_row_class_resolves_per_row
  it('components.json 이 rowClassName 을 엔진 선평가에서 뺀다(skipBindingKeys)', () => {
    const meta = require('../../../../components.json');
    const all = [...(meta.components?.basic ?? []), ...(meta.components?.composite ?? []), ...(meta.components?.layout ?? [])];
    const grid = all.find((c: any) => c.name === 'DataGrid');
    expect(grid?.skipBindingKeys).toContain('rowClassName');
  });

  // @scenario row_type=notice
  // @effects datagrid_row_class_applies_to_mobile_cards
  it('좁은 화면 카드 보기에서도 행 클래스가 카드에 붙고, 배경을 주면 기본 흰 배경을 빼 덮이지 않게 한다', () => {
    // 7.1.0 실측 K3 관측 O-K3-17: 390px 카드 보기의 공지 카드에 배경 구분이 없었다(행 배경은 표 tr 에만 붙음).
    const { container } = render(
      <DataGrid columns={columns} data={data} rowClassName={ROW_CLASS} responsiveBreakpoint={100000} />,
    );
    expect(container.querySelectorAll('tbody tr').length).toBe(0);

    const cardOf = (title: string): string[] => {
      const cell = Array.from(container.querySelectorAll('div')).find((d) => d.textContent?.trim() === title && d.children.length === 0);
      const card = cell?.closest('.rounded-lg.border') as HTMLElement | null;
      return (card?.className ?? '').split(/\s+/).filter(Boolean);
    };

    expect(cardOf('공지')).toContain('bg-blue-50');
    expect(cardOf('공지')).not.toContain('bg-white');
    expect(cardOf('답글')).toContain('bg-gray-50');
    expect(cardOf('일반')).toContain('bg-white');
  });
});

