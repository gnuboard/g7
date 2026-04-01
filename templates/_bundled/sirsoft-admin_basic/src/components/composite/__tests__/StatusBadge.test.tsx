import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { StatusBadge, StatusType } from '../StatusBadge';
import { IconName } from '../../basic/IconTypes';

describe('StatusBadge', () => {
  describe('기본 렌더링', () => {
    it('status와 기본 라벨을 렌더링해야 함', () => {
      render(<StatusBadge status="success" />);

      expect(screen.getByText('성공')).toBeInTheDocument();
    });

    it('커스텀 라벨을 렌더링해야 함', () => {
      render(<StatusBadge status="success" label="완료됨" />);

      expect(screen.getByText('완료됨')).toBeInTheDocument();
      expect(screen.queryByText('성공')).not.toBeInTheDocument();
    });

    it('기본적으로 아이콘을 표시해야 함', () => {
      const { container } = render(<StatusBadge status="success" />);

      const icon = container.querySelector('i[role="img"]');
      expect(icon).toBeInTheDocument();
    });

    it('showIcon=false일 때 아이콘을 숨겨야 함', () => {
      const { container } = render(<StatusBadge status="success" showIcon={false} />);

      const icon = container.querySelector('i[role="img"]');
      expect(icon).not.toBeInTheDocument();
    });
  });

  describe('상태별 스타일', () => {
    const statusTests: Array<{ status: StatusType; label: string; bgClass: string }> = [
      { status: 'success', label: '성공', bgClass: 'bg-green-50' },
      { status: 'warning', label: '경고', bgClass: 'bg-yellow-50' },
      { status: 'error', label: '오류', bgClass: 'bg-red-50' },
      { status: 'info', label: '정보', bgClass: 'bg-blue-50' },
      { status: 'pending', label: '대기 중', bgClass: 'bg-gray-50' },
      { status: 'default', label: '기본', bgClass: 'bg-gray-50' },
    ];

    statusTests.forEach(({ status, label, bgClass }) => {
      it(`status="${status}"일 때 올바른 스타일과 라벨을 적용해야 함`, () => {
        const { container } = render(<StatusBadge status={status} />);

        expect(screen.getByText(label)).toBeInTheDocument();

        const badge = container.querySelector('span');
        expect(badge).toHaveClass(bgClass);
      });
    });
  });

  describe('아이콘 커스터마이징', () => {
    it('커스텀 아이콘을 사용할 수 있어야 함', () => {
      const { container } = render(
        <StatusBadge status="success" iconName={IconName.Star} />
      );

      const icon = container.querySelector('i[role="img"]');
      expect(icon).toBeInTheDocument();
    });
  });

  describe('스타일 커스터마이징', () => {
    it('className prop을 적용해야 함', () => {
      const { container } = render(
        <StatusBadge status="success" className="custom-class" />
      );

      const badge = container.querySelector('span');
      expect(badge).toHaveClass('custom-class');
    });

    it('style prop을 적용해야 함', () => {
      const { container } = render(
        <StatusBadge status="success" style={{ fontSize: '20px' }} />
      );

      const badge = container.querySelector('span');
      expect(badge).toHaveStyle({ fontSize: '20px' });
    });
  });

  describe('접근성', () => {
    it('올바른 구조로 렌더링되어야 함', () => {
      const { container } = render(<StatusBadge status="success" label="완료" />);

      const badge = container.querySelector('span');
      expect(badge).toBeInTheDocument();

      const labelSpan = screen.getByText('완료');
      expect(labelSpan).toBeInTheDocument();
    });
  });

  describe('복합 시나리오', () => {
    it('모든 props를 함께 사용할 수 있어야 함', () => {
      const { container } = render(
        <StatusBadge
          status="warning"
          label="주의 필요"
          showIcon={true}
          iconName={IconName.ExclamationTriangle}
          className="my-badge"
          style={{ marginTop: '10px' }}
        />
      );

      expect(screen.getByText('주의 필요')).toBeInTheDocument();

      const badge = container.querySelector('span');
      expect(badge).toHaveClass('my-badge');
      expect(badge).toHaveClass('bg-yellow-50');
      expect(badge).toHaveStyle({ marginTop: '10px' });

      const icon = container.querySelector('i[role="img"]');
      expect(icon).toBeInTheDocument();
    });
  });
});
