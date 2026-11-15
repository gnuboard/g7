// e2e:allow 번들 편집기 스펙에 style 이 든 아이콘 미리보기가 없어(잠재 경로) 브라우저에서 재현할 화면이 없다 — 연결 단위만 잠근다
/**
 * IconPickerWidget — 아이콘 미리보기 HTML 서식 지연 적용 (공개 #163, 엄격한 스타일 정책 호환)
 *
 * 회귀 배경: 카탈로그의 `preview.html` 을 dangerouslySetInnerHTML 로 꽂으므로, 그 HTML 에 `style="…"` 속성이 있으면
 * `style-src-attr 'none'` 에서 막힌다. 코어 장치(`G7Core.inlineStyle`)가 있으면 그 장치로 정화해 붙인 뒤 입힌다.
 * 장치가 없는 옛 코어에서는 종전 그대로(원문 그대로)다.
 *
 * @scenario-manifest templates/_bundled/sirsoft-admin_basic/tests/scenarios/content-inline-style-deferral.yaml
 */
import React from 'react';
import { render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { IconPickerWidget } from '../IconPickerWidget';
import { DEFERRED_ATTR, installInlineStyleMock } from '../../../__tests__/helpers/inlineStyleMock';

const t = (key: string) => key;
const catalog = [
  { value: 'star', preview: { html: '<b style="color: red">★</b>' } },
  { value: 'heart' },
];

afterEach(() => {
  delete (window as any).G7Core;
});

describe('IconPickerWidget 미리보기 HTML 서식 지연 적용', () => {
  /**
   * @scenario surface=icon_picker_preview, core=with_inline_style
   * @effects style_attribute_deferred, style_applied_via_cssom
   */
  it('장치가 있으면 style 속성 없이 붙이고 CSSOM 으로 입힌다', () => {
    const mock = installInlineStyleMock();
    render(<IconPickerWidget control={{ icons: catalog }} value="" onChange={vi.fn()} t={t} />);
    const b = screen.getByTestId('g7le-icon-cell-star').querySelector('b') as HTMLElement;

    expect(mock.sanitize.mock.results[0].value).not.toMatch(/\sstyle=/);
    expect(b.style.color).toBe('red');
    expect(b.hasAttribute(DEFERRED_ATTR)).toBe(false);
    // preview.html 이 없는 항목은 종전대로 아이콘 클래스로 그린다
    expect(screen.getByTestId('g7le-icon-cell-heart').querySelector('i.fa-heart')).not.toBeNull();
  });

  /**
   * @scenario surface=icon_picker_preview, core=legacy
   * @effects legacy_core_unchanged
   */
  it('장치가 없으면(옛 코어) 종전 그대로 원문을 꽂는다', () => {
    render(<IconPickerWidget control={{ icons: catalog }} value="" onChange={vi.fn()} t={t} />);
    const preview = screen.getByTestId('g7le-icon-cell-star').querySelector('.g7le-icon-preview') as HTMLElement;

    expect(preview.innerHTML).toMatch(/<b style="color: ?red;?">★<\/b>/);
  });
});
