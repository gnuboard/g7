import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { VersionList, VersionItem } from '../VersionList';

describe('VersionList', () => {
  const mockVersions: VersionItem[] = [
    {
      id: 1,
      version: '1.0.0',
      created_at: '2024-01-01T10:00:00Z',
      changes_summary: {
        added: 10,
        removed: 5,
      },
    },
    {
      id: 2,
      version: '1.1.0',
      created_at: '2024-01-02T14:30:00Z',
      changes_summary: {
        added: 20,
        removed: 8,
      },
    },
    {
      id: 3,
      version: '2.0.0',
      created_at: '2024-01-03T09:15:00Z',
      changes_summary: {
        added: 50,
        removed: 30,
      },
    },
  ];

  describe('렌더링', () => {
    it('버전 목록을 렌더링해야 함', () => {
      render(<VersionList versions={mockVersions} />);

      expect(screen.getByText('버전 1.0.0')).toBeInTheDocument();
      expect(screen.getByText('버전 1.1.0')).toBeInTheDocument();
      expect(screen.getByText('버전 2.0.0')).toBeInTheDocument();
    });

    it('각 버전의 생성 날짜를 표시해야 함', () => {
      render(<VersionList versions={mockVersions} />);

      // 날짜 포맷팅 확인 (ko-KR 로케일)
      const dateElements = screen.getAllByText(/2024/);
      expect(dateElements.length).toBeGreaterThan(0);
    });

    it('changes_summary의 added 라인 수를 표시해야 함', () => {
      render(<VersionList versions={mockVersions} />);

      expect(screen.getByText('+10')).toBeInTheDocument();
      expect(screen.getByText('+20')).toBeInTheDocument();
      expect(screen.getByText('+50')).toBeInTheDocument();
    });

    it('changes_summary의 removed 라인 수를 표시해야 함', () => {
      render(<VersionList versions={mockVersions} />);

      expect(screen.getByText('-5')).toBeInTheDocument();
      expect(screen.getByText('-8')).toBeInTheDocument();
      expect(screen.getByText('-30')).toBeInTheDocument();
    });

    it('빈 배열을 전달하면 아무것도 렌더링하지 않아야 함', () => {
      const { container } = render(<VersionList versions={[]} />);
      const list = container.querySelector('ul');
      expect(list).toBeNull();
      expect(container.textContent).toContain('버전이 없습니다');
    });
  });

  describe('선택 기능', () => {
    it('selectedId와 일치하는 항목을 하이라이트해야 함', () => {
      render(<VersionList versions={mockVersions} selectedId={2} />);

      const items = screen.getAllByRole('listitem');
      expect(items[1]).toHaveAttribute('aria-selected', 'true');
      expect(items[0]).toHaveAttribute('aria-selected', 'false');
      expect(items[2]).toHaveAttribute('aria-selected', 'false');
    });

    it('selectedId와 일치하는 항목에 선택 스타일이 적용되어야 함', () => {
      render(<VersionList versions={mockVersions} selectedId={1} />);

      const items = screen.getAllByRole('listitem');
      expect(items[0]).toHaveClass('border-blue-500');
      expect(items[0]).toHaveClass('bg-blue-50');
    });

    it('항목 클릭 시 onSelect 콜백을 올바른 id로 호출해야 함', async () => {
      const user = userEvent.setup();
      const onSelect = vi.fn();

      render(
        <VersionList
          versions={mockVersions}
          onSelect={onSelect}
        />
      );

      const items = screen.getAllByRole('listitem');
      await user.click(items[0]);
      expect(onSelect).toHaveBeenCalledWith(1);

      await user.click(items[1]);
      expect(onSelect).toHaveBeenCalledWith(2);

      await user.click(items[2]);
      expect(onSelect).toHaveBeenCalledWith(3);
    });

    it('onSelect가 없으면 클릭해도 에러가 발생하지 않아야 함', async () => {
      const user = userEvent.setup();

      render(<VersionList versions={mockVersions} />);

      const items = screen.getAllByRole('listitem');
      await expect(user.click(items[0])).resolves.not.toThrow();
    });
  });

  describe('접근성', () => {
    it('list 역할을 가진 ul 요소를 렌더링해야 함', () => {
      render(<VersionList versions={mockVersions} />);

      expect(screen.getByRole('list')).toBeInTheDocument();
    });

    it('각 항목이 listitem 역할을 가져야 함', () => {
      render(<VersionList versions={mockVersions} />);

      const items = screen.getAllByRole('listitem');
      expect(items).toHaveLength(3);
    });

    it('각 항목이 aria-selected 속성을 가져야 함', () => {
      render(<VersionList versions={mockVersions} selectedId={1} />);

      const items = screen.getAllByRole('listitem');
      items.forEach((item) => {
        expect(item).toHaveAttribute('aria-selected');
      });
    });
  });

  describe('날짜 포맷팅', () => {
    it('ISO 8601 날짜 문자열을 로케일 형식으로 변환해야 함', () => {
      render(<VersionList versions={mockVersions} />);

      // ko-KR 로케일로 포맷팅된 날짜가 표시되어야 함
      const dateElements = screen.getAllByText(/\d{4}/);
      expect(dateElements.length).toBeGreaterThan(0);
    });
  });

  describe('스타일링', () => {
    it('선택되지 않은 항목은 기본 테두리 색상을 가져야 함', () => {
      render(<VersionList versions={mockVersions} selectedId={1} />);

      const items = screen.getAllByRole('listitem');
      expect(items[1]).toHaveClass('border-gray-300');
      expect(items[2]).toHaveClass('border-gray-300');
    });

    it('added 라인 수는 녹색으로 표시되어야 함', () => {
      const { container } = render(<VersionList versions={mockVersions} />);

      const addedElements = container.querySelectorAll('.text-green-600');
      expect(addedElements.length).toBe(3);
    });

    it('removed 라인 수는 빨간색으로 표시되어야 함', () => {
      const { container } = render(<VersionList versions={mockVersions} />);

      const removedElements = container.querySelectorAll('.text-red-600');
      expect(removedElements.length).toBe(3);
    });
  });
});
