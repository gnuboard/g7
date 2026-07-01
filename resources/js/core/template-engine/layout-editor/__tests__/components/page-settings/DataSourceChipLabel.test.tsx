/**
 * DataSourceChipLabel.test.tsx — 데이터소스 멀티선택 칩 라벨
 *
 * 친화명 유무·확장 출처 유무에 따른 표기 규칙을 고정한다.
 */
import { describe, it, expect, afterEach } from 'vitest';
import { render, screen, cleanup } from '@testing-library/react';
import { DataSourceChipLabel } from '../../../components/page-settings/DataSourceChipLabel';

afterEach(cleanup);

describe('DataSourceChipLabel', () => {
  it('친화명 있으면 제목=친화명 + 보조 id 동반', () => {
    render(<DataSourceChipLabel option={{ id: 'products', friendly: '상품 목록', source: null }} testIdPrefix="chip" />);
    expect(screen.getByTestId('chip-title').textContent).toBe('상품 목록');
    expect(screen.getByTestId('chip-id').textContent).toBe('products');
  });

  it('친화명 없으면 제목=id, 보조 id 미노출', () => {
    render(<DataSourceChipLabel option={{ id: 'plain', friendly: null, source: null }} testIdPrefix="chip" />);
    expect(screen.getByTestId('chip-title').textContent).toBe('plain');
    expect(screen.queryByTestId('chip-id')).not.toBeInTheDocument();
  });

  it('확장 출처 있으면 배지 노출, 없으면 미노출', () => {
    const { rerender } = render(
      <DataSourceChipLabel option={{ id: 'g', friendly: null, source: '플러그인: GDPR (sirsoft-gdpr)' }} testIdPrefix="chip" />,
    );
    expect(screen.getByTestId('chip-source').textContent).toBe('플러그인: GDPR (sirsoft-gdpr)');
    rerender(<DataSourceChipLabel option={{ id: 'g', friendly: null, source: null }} testIdPrefix="chip" />);
    expect(screen.queryByTestId('chip-source')).not.toBeInTheDocument();
  });
});
