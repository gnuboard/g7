import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { AdminFooter, QuickLink } from '../AdminFooter';
import { IconName } from '../../basic/IconTypes';

describe('AdminFooter', () => {
  const mockQuickLinks: QuickLink[] = [
    { id: 1, label: '문서', url: '/docs', iconName: IconName.FileText },
    { id: 2, label: '지원', url: '/support', iconName: IconName.HelpCircle },
  ];

  it('컴포넌트가 렌더링됨', () => {
    render(<AdminFooter />);
    expect(screen.getByText('© 2026 G7')).toBeInTheDocument();
  });

  it('커스텀 저작권 텍스트가 표시됨', () => {
    render(<AdminFooter copyright="© 2025 Custom CMS" />);
    expect(screen.getByText('© 2025 Custom CMS')).toBeInTheDocument();
  });

  it('버전 정보가 표시됨', () => {
    render(<AdminFooter version="1.0.0" />);
    expect(screen.getByText('v1.0.0')).toBeInTheDocument();
  });

  it('빠른 링크가 표시됨', () => {
    render(<AdminFooter quickLinks={mockQuickLinks} />);

    expect(screen.getByText('문서')).toBeInTheDocument();
    expect(screen.getByText('지원')).toBeInTheDocument();

    const docLink = screen.getByText('문서').closest('a');
    expect(docLink).toHaveAttribute('href', '/docs');
  });

  it('빈 빠른 링크 배열일 때 링크가 표시되지 않음', () => {
    render(<AdminFooter quickLinks={[]} />);

    expect(screen.queryByText('문서')).not.toBeInTheDocument();
    expect(screen.queryByText('지원')).not.toBeInTheDocument();
  });

  it('버전 정보와 빠른 링크가 함께 표시됨', () => {
    render(
      <AdminFooter
        version="2.0.0"
        quickLinks={mockQuickLinks}
      />
    );

    expect(screen.getByText('v2.0.0')).toBeInTheDocument();
    expect(screen.getByText('문서')).toBeInTheDocument();
    expect(screen.getByText('지원')).toBeInTheDocument();
  });

  it('className prop이 적용됨', () => {
    const { container } = render(<AdminFooter className="custom-class" />);
    expect(container.firstChild).toHaveClass('custom-class');
  });
});
