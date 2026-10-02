/**
 * CardGrid — 셀 렌더 환경 전달 (이슈 #141)
 *
 * 셀 렌더 함수는 React Context 를 못 읽어 브라우저 창 폭으로 `responsive` 분기를 골랐다.
 * CardGrid 는 자신이 받은 렌더 환경(`__componentContext.renderEnvRef.current`)에 자기
 * `useResponsive()` 폭을 얹어 `renderEnv` 로 넘긴다. `componentContext` 는 넘기지 않는다 —
 * 넘기면 셀의 `_local` 의미와 setState 대상이 바뀐다.
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render } from '@testing-library/react';
import { CardGrid } from '../CardGrid';

describe('CardGrid — 렌더 환경 전달 (#141)', () => {
  let originalG7Core: any;
  const renderItemChildren = vi.fn(() => null);

  beforeEach(() => {
    originalG7Core = (window as any).G7Core;
    renderItemChildren.mockClear();
  });

  afterEach(() => {
    (window as any).G7Core = originalG7Core;
  });

  const install = (width: number | undefined) => {
    (window as any).G7Core = {
      ...originalG7Core,
      renderItemChildren,
      getComponentMap: () => ({}),
      evaluateCondition: () => true,
      useResponsive: width === undefined ? undefined : vi.fn(() => ({ width })),
    };
  };

  const cardColumns = [{ id: 'c', cellChildren: [{ type: 'basic', name: 'Span', text: '{{row.name}}' }] }] as any;

  /**
   * @scenario component=CardGrid, preview_device=mobile, window_width=1440
   * @effects preview_cell_render_follows_preview_width
   */
  it('renderEnv(Provider 폭 390 + 받은 환경 값)를 5번째 인자로 넘기고 componentContext 는 넘기지 않는다', () => {
    install(390);
    const envDispatcher = { id: 'env' };

    render(
      <CardGrid
        data={[{ id: 1, name: 'A' }]}
        cardColumns={cardColumns}
        __componentContext={{ renderEnvRef: { current: { responsiveWidth: 1440, actionDispatcher: envDispatcher } } }}
      />
    );

    const options = (renderItemChildren.mock.calls[0] as any[])[4];
    expect(options).toEqual(
      expect.objectContaining({
        renderEnv: expect.objectContaining({ responsiveWidth: 390, actionDispatcher: envDispatcher }),
      })
    );
    expect(options).not.toHaveProperty('componentContext');
  });

  /**
   * @scenario component=CardGrid, render_env=absent
   * @effects preview_render_env_falls_back_to_host_outside_preview
   */
  it('렌더 환경·훅이 없으면 빈 renderEnv 를 넘긴다 (코어가 호스트 값으로 폴백)', () => {
    install(undefined);

    render(<CardGrid data={[{ id: 1, name: 'A' }]} cardColumns={cardColumns} />);

    const options = (renderItemChildren.mock.calls[0] as any[])[4];
    expect(options).toEqual({ renderEnv: {} });
  });
});
