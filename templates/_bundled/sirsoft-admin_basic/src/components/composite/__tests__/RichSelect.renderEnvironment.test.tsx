/**
 * RichSelect — component_layout 렌더 환경 전달 (이슈 #141)
 *
 * component_layout 항목은 `G7Core.renderComponentLayout` 이 그린다. 종전에는 호스트 번역·dispatcher·
 * 레지스트리로 그려 편집기 미리보기의 콘텐츠 언어·격리 dispatcher 를 무시했다. RichSelect 는 자신이 받은
 * `__componentContext.renderEnvRef.current` 를 4번째 인자로 넘긴다.
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render } from '@testing-library/react';
import { RichSelect } from '../RichSelect';

describe('RichSelect — 렌더 환경 전달 (#141)', () => {
  let originalG7Core: any;
  const options = [{ value: 'file1', label: 'home.json' }];

  beforeEach(() => {
    originalG7Core = (window as any).G7Core;
  });

  afterEach(() => {
    (window as any).G7Core = originalG7Core;
  });

  /**
   * @scenario component=RichSelect, render_path=component_layout, render_env=present
   * @effects preview_cell_wrapper_uses_render_env_over_host
   */
  it('renderComponentLayout 4번째 인자로 { renderEnv } 를 넘긴다', () => {
    const env = { responsiveWidth: 390, translationContext: { templateId: 't', locale: 'en' } };
    const renderComponentLayout = vi.fn(() => 'rendered');
    (window as any).G7Core = { ...originalG7Core, renderComponentLayout };

    render(
      <RichSelect
        options={options}
        value="file1"
        __componentLayoutDefs={{ selected: [{ type: 'basic', name: 'Span', text: '{{item.label}}' }] }}
        __componentContext={{ renderEnvRef: { current: env } }}
      />
    );

    const call = renderComponentLayout.mock.calls[0] as any[];
    expect(call[2]).toBe('selected');
    expect(call[3]).toEqual({ renderEnv: env });
  });

  /**
   * @scenario component=RichSelect, render_path=component_layout, render_env=absent
   * @effects preview_render_env_falls_back_to_host_outside_preview
   */
  it('렌더 환경이 없으면 renderEnv 를 null 로 넘긴다 (코어가 호스트 값으로 폴백)', () => {
    const renderComponentLayout = vi.fn(() => 'rendered');
    (window as any).G7Core = { ...originalG7Core, renderComponentLayout };

    render(
      <RichSelect
        options={options}
        value="file1"
        __componentLayoutDefs={{ selected: [{ type: 'basic', name: 'Span', text: '{{item.label}}' }] }}
      />
    );

    expect((renderComponentLayout.mock.calls[0] as any[])[3]).toEqual({ renderEnv: null });
  });
});
