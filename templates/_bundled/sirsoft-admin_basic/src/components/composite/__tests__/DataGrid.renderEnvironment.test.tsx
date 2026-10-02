/**
 * DataGrid — 렌더 환경 전달 잠금 (이슈 #141)
 *
 * DataGrid 는 셀 렌더 6경로(셀·서브행·푸터 셀·푸터 텍스트·모바일 카드·확장 행) 모두 `__componentContext`
 * 를 넘긴다. 코어 래퍼가 그 안의 `renderEnvRef.current`(미리보기 폭·언어·dispatcher·레지스트리·편집 모드)를
 * 읽으므로 DataGrid 코드 변경 없이 미리보기 값이 셀까지 간다. 한 경로라도 빠지면 그 경로만 창 폭·호스트
 * 언어로 그려지는 비대칭이 되돌아온다.
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render } from '@testing-library/react';
import { DataGrid, DataGridColumn } from '../DataGrid';

describe('DataGrid — 렌더 환경 전달 6경로 잠금 (#141)', () => {
  let originalG7Core: any;
  const ctx = { state: {}, setState: vi.fn(), renderEnvRef: { current: { responsiveWidth: 390 } } };

  const columns = [
    { field: 'name', header: '이름', cellChildren: [{ type: 'basic', name: 'Span', text: '{{row.name}}' }] },
    { field: 'email', header: '이메일' },
  ] as DataGridColumn[];

  beforeEach(() => {
    originalG7Core = (window as any).G7Core;
  });

  afterEach(() => {
    (window as any).G7Core = originalG7Core;
  });

  /**
   * 렌더 호출을 기록하는 G7Core 를 세운다.
   *
   * @param width useResponsive 폭(모바일 카드 경로 판정)
   * @returns 호출 기록
   */
  const capture = (width: number) => {
    const calls: { path: string; options: any }[] = [];
    (window as any).G7Core = {
      ...originalG7Core,
      useResponsive: vi.fn(() => ({ width, isMobile: width < 768, isTablet: false, isDesktop: width >= 1024 })),
      evaluateCondition: () => true,
      getComponentMap: () => ({ Span: 'span' }),
      renderItemChildren: (_c: any, _ctx: any, _map: any, keyPrefix: string, options: any) => {
        calls.push({ path: keyPrefix, options });
        return null;
      },
      renderExpandContent: (config: any) => {
        calls.push({ path: config.keyPrefix, options: { componentContext: config.componentContext } });
        return null;
      },
    };
    return calls;
  };

  /**
   * @scenario component=DataGrid, view=desktop, paths=cell|subrow|footer_cell|footer_text|expand
   * @effects preview_cell_render_follows_preview_width
   */
  it('데스크톱: 셀·서브행·푸터 셀·푸터 텍스트·확장 행이 모두 componentContext 를 넘긴다', () => {
    const calls = capture(1280);
    render(
      <DataGrid
        columns={columns}
        data={[{ id: 1, name: 'A' }]}
        subRowChildren={[{ type: 'basic', name: 'Span', text: '{{row.name}}' }] as any}
        footerCells={[
          { field: 'name', children: [{ type: 'basic', name: 'Span', text: 'x' }] } as any,
          { field: 'email', text: '{{row.name}}' } as any,
        ]}
        expandable
        expandedRowIds={[1]}
        expandChildren={[{ type: 'basic', name: 'Span', text: 'e' }] as any}
        __componentContext={ctx as any}
      />
    );

    const has = (prefix: string) => calls.some((c) => c.path.startsWith(prefix) && c.options?.componentContext === ctx);
    expect(has('1-name')).toBe(true);
    expect(has('subrow-')).toBe(true);
    expect(has('footer-name')).toBe(true);
    expect(has('footer-text-')).toBe(true);
    expect(has('expand')).toBe(true);
    expect(calls.every((c) => c.options?.componentContext === ctx)).toBe(true);
  });

  /**
   * @scenario component=DataGrid, view=mobile_card, paths=card_cell|footer_card
   * @effects preview_cell_render_follows_preview_width
   */
  it('모바일 카드: 카드 셀·푸터 카드가 componentContext 를 넘긴다', () => {
    const calls = capture(375);
    render(
      <DataGrid
        columns={columns}
        data={[{ id: 1, name: 'A' }]}
        mobileBreakpoint={768}
        footerCells={[{ field: 'name', text: 'x' } as any]}
        footerCardChildren={[{ type: 'basic', name: 'Span', text: '{{row.name}}' }] as any}
        __componentContext={ctx as any}
      />
    );

    expect(calls.some((c) => c.path.startsWith('card-') && c.options?.componentContext === ctx)).toBe(true);
    expect(calls.some((c) => c.path === 'footer-card' && c.options?.componentContext === ctx)).toBe(true);
    expect(calls.every((c) => c.options?.componentContext === ctx)).toBe(true);
  });
});
