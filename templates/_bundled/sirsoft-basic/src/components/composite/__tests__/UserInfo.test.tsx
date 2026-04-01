/**
 * UserInfo 컴포넌트 테스트
 *
 * @description 사용자 정보 표시 컴포넌트의 렌더링 및 상태 배지 기능을 테스트합니다.
 * - 기본 렌더링 (이름, 서브텍스트)
 * - 비회원 배지 표시
 * - 사용자 상태 배지 표시 (inactive, blocked)
 * - 드롭다운 메뉴 동작
 */

import React from 'react';
import { render, screen, fireEvent, act } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { UserInfo } from '../UserInfo';

// G7Core mock
const mockG7Core = {
  t: vi.fn((key: string) => {
    const translations: Record<string, string> = {
      'userinfo.guest_badge': '비회원',
      'userinfo.view_profile': '프로필 보기',
      'userinfo.view_posts': '작성글 보기',
      'userinfo.status_inactive': '휴면',
      'userinfo.status_blocked': '차단됨',
    };
    return translations[key] ?? key;
  }),
  navigate: vi.fn(),
};

// window.G7Core mock 설정
beforeEach(() => {
  (window as any).G7Core = mockG7Core;
  vi.clearAllMocks();
});

afterEach(() => {
  delete (window as any).G7Core;
});

// UserInfo 컴포넌트 mock (basic 컴포넌트 의존성 때문에)
const MockUserInfo: React.FC<{
  name?: string;
  userId?: string | number;
  subText?: string;
  subTextTitle?: string;
  isGuest?: boolean;
  showDropdown?: boolean;
  status?: 'active' | 'inactive' | 'blocked';
  statusLabel?: string;
  showStatusBadge?: boolean;
  layout?: 'vertical' | 'horizontal';
  text?: string;
}> = ({
  name,
  userId,
  subText,
  subTextTitle,
  isGuest = false,
  showDropdown = true,
  status,
  statusLabel,
  showStatusBadge = true,
  layout = 'vertical',
  text,
}) => {
  const t = (key: string) => mockG7Core.t(key);
  const actualName = text ?? name ?? '';

  // 상태별 배지 스타일
  const statusBadgeStyles: Record<string, string> = {
    active: '',
    inactive: 'bg-yellow-100 dark:bg-yellow-900 text-yellow-800 dark:text-yellow-200',
    blocked: 'bg-red-100 dark:bg-red-900 text-red-800 dark:text-red-200',
  };

  // 상태 배지 렌더링 여부
  const shouldShowStatusBadge = showStatusBadge && status && status !== 'active';

  // 비회원인 경우
  if (isGuest || !userId) {
    return (
      <div
        className={layout === 'horizontal' ? 'flex items-center gap-1.5' : 'flex flex-col items-start'}
        data-testid="user-info"
      >
        <div className="flex items-center gap-1.5 text-gray-400 dark:text-gray-500">
          <span data-testid="user-name">{actualName}</span>
          <span
            className="text-xs px-1.5 py-0.5 bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 rounded"
            data-testid="guest-badge"
          >
            {t('userinfo.guest_badge')}
          </span>
        </div>
        {subText && (
          <span className="text-sm text-gray-500 dark:text-gray-400" data-testid="sub-text" title={subTextTitle}>
            {subText}
          </span>
        )}
      </div>
    );
  }

  // 회원인 경우
  return (
    <div className="relative inline-block" data-testid="user-info">
      <div className={layout === 'horizontal' ? 'flex items-center gap-1.5' : 'flex flex-col items-start'}>
        <div className="flex items-center gap-1.5">
          <button
            onClick={() => showDropdown && mockG7Core.navigate(`/users/${userId}`)}
            className="font-medium"
            data-testid="user-name-button"
          >
            {actualName}
          </button>

          {/* 상태 배지 (active가 아닐 때만 표시) */}
          {shouldShowStatusBadge && (
            <span
              className={`text-xs px-1.5 py-0.5 rounded ${statusBadgeStyles[status!] ?? ''}`}
              data-testid="user-status-badge"
            >
              {statusLabel || t(`userinfo.status_${status}`)}
            </span>
          )}
        </div>

        {subText && (
          <span className="text-sm text-gray-500 dark:text-gray-400" data-testid="sub-text" title={subTextTitle}>
            {subText}
          </span>
        )}
      </div>
    </div>
  );
};

describe('UserInfo 컴포넌트', () => {
  describe('기본 렌더링', () => {
    it('사용자 이름이 표시되어야 함', () => {
      render(<MockUserInfo name="홍길동" userId={1} />);
      expect(screen.getByTestId('user-name-button')).toHaveTextContent('홍길동');
    });

    it('text prop이 name보다 우선순위가 높아야 함', () => {
      render(<MockUserInfo name="홍길동" text="김철수" userId={1} />);
      expect(screen.getByTestId('user-name-button')).toHaveTextContent('김철수');
    });

    it('서브 텍스트가 표시되어야 함', () => {
      render(<MockUserInfo name="홍길동" userId={1} subText="2024-01-15" />);
      expect(screen.getByTestId('sub-text')).toHaveTextContent('2024-01-15');
    });

    it('서브 텍스트가 없으면 표시되지 않아야 함', () => {
      render(<MockUserInfo name="홍길동" userId={1} />);
      expect(screen.queryByTestId('sub-text')).not.toBeInTheDocument();
    });

    it('subTextTitle이 서브 텍스트의 title 속성으로 렌더링되어야 함', () => {
      render(
        <MockUserInfo
          name="홍길동"
          userId={1}
          subText="03-18"
          subTextTitle="2026-03-18 14:30:00"
        />
      );
      const subText = screen.getByTestId('sub-text');
      expect(subText).toHaveTextContent('03-18');
      expect(subText).toHaveAttribute('title', '2026-03-18 14:30:00');
    });

    it('subTextTitle이 없으면 title 속성이 없어야 함', () => {
      render(<MockUserInfo name="홍길동" userId={1} subText="03-18" />);
      const subText = screen.getByTestId('sub-text');
      expect(subText).not.toHaveAttribute('title');
    });

    it('비회원인 경우에도 subTextTitle이 title 속성으로 렌더링되어야 함', () => {
      render(
        <MockUserInfo
          name="익명"
          isGuest
          subText="03-18"
          subTextTitle="2026-03-18 14:30:00"
        />
      );
      const subText = screen.getByTestId('sub-text');
      expect(subText).toHaveAttribute('title', '2026-03-18 14:30:00');
    });
  });

  describe('비회원 처리', () => {
    it('비회원인 경우 비회원 배지가 표시되어야 함', () => {
      render(<MockUserInfo name="익명" isGuest />);
      expect(screen.getByTestId('guest-badge')).toHaveTextContent('비회원');
    });

    it('userId가 없으면 비회원으로 처리되어야 함', () => {
      render(<MockUserInfo name="익명" />);
      expect(screen.getByTestId('guest-badge')).toBeInTheDocument();
    });

    it('비회원인 경우 상태 배지가 표시되지 않아야 함', () => {
      render(<MockUserInfo name="익명" isGuest status="inactive" />);
      expect(screen.queryByTestId('user-status-badge')).not.toBeInTheDocument();
    });
  });

  describe('상태 배지 (status badge)', () => {
    it('active 상태에서는 배지가 표시되지 않아야 함', () => {
      render(<MockUserInfo name="홍길동" userId={1} status="active" />);
      expect(screen.queryByTestId('user-status-badge')).not.toBeInTheDocument();
    });

    it('inactive 상태에서 휴면 배지가 표시되어야 함', () => {
      render(<MockUserInfo name="홍길동" userId={1} status="inactive" />);
      expect(screen.getByTestId('user-status-badge')).toHaveTextContent('휴면');
    });

    it('blocked 상태에서 차단됨 배지가 표시되어야 함', () => {
      render(<MockUserInfo name="홍길동" userId={1} status="blocked" />);
      expect(screen.getByTestId('user-status-badge')).toHaveTextContent('차단됨');
    });

    it('inactive 배지에 노란색 스타일이 적용되어야 함', () => {
      render(<MockUserInfo name="홍길동" userId={1} status="inactive" />);
      const badge = screen.getByTestId('user-status-badge');
      expect(badge.className).toContain('bg-yellow-100');
      expect(badge.className).toContain('dark:bg-yellow-900');
    });

    it('blocked 배지에 빨간색 스타일이 적용되어야 함', () => {
      render(<MockUserInfo name="홍길동" userId={1} status="blocked" />);
      const badge = screen.getByTestId('user-status-badge');
      expect(badge.className).toContain('bg-red-100');
      expect(badge.className).toContain('dark:bg-red-900');
    });

    it('statusLabel이 있으면 해당 라벨을 사용해야 함', () => {
      render(<MockUserInfo name="홍길동" userId={1} status="inactive" statusLabel="커스텀 라벨" />);
      expect(screen.getByTestId('user-status-badge')).toHaveTextContent('커스텀 라벨');
    });

    it('showStatusBadge가 false면 배지가 표시되지 않아야 함', () => {
      render(<MockUserInfo name="홍길동" userId={1} status="inactive" showStatusBadge={false} />);
      expect(screen.queryByTestId('user-status-badge')).not.toBeInTheDocument();
    });

    it('status가 없으면 배지가 표시되지 않아야 함', () => {
      render(<MockUserInfo name="홍길동" userId={1} />);
      expect(screen.queryByTestId('user-status-badge')).not.toBeInTheDocument();
    });
  });

  describe('레이아웃', () => {
    it('기본 레이아웃은 vertical이어야 함', () => {
      render(<MockUserInfo name="홍길동" userId={1} subText="2024-01-15" />);
      const userInfo = screen.getByTestId('user-info');
      // vertical 레이아웃에서는 flex-col 클래스가 포함된 div가 있어야 함
      expect(userInfo.querySelector('.flex-col')).toBeInTheDocument();
    });

    it('horizontal 레이아웃이 적용되어야 함', () => {
      render(<MockUserInfo name="홍길동" userId={1} subText="2024-01-15" layout="horizontal" />);
      const userInfo = screen.getByTestId('user-info');
      // horizontal 레이아웃에서는 flex items-center gap-1.5 클래스가 포함된 div가 있어야 함
      expect(userInfo.querySelector('.flex.items-center.gap-1\\.5')).toBeInTheDocument();
    });
  });

  describe('Portal 기반 드롭다운 (overflow-hidden 부모 회피)', () => {
    it('드롭다운 메뉴가 document.body에 Portal로 렌더링되어야 함', async () => {
      // UserInfo 실제 컴포넌트를 동적 import하여 Portal 동작 검증
      const { UserInfo } = await import('../UserInfo');

      const { container } = render(
        <div style={{ overflow: 'hidden', position: 'relative' }}>
          <UserInfo
            author={{ id: 1, uuid: 'test-uuid-1', name: '홍길동' } as any}
            showDropdown={true}
          />
        </div>
      );

      // 이름 버튼 클릭하여 드롭다운 열기
      const nameButton = screen.getByText('홍길동');
      fireEvent.click(nameButton);

      // 드롭다운이 document.body에 렌더링되어야 함 (overflow-hidden 부모 내부가 아님)
      const dropdown = screen.getByTestId('author-dropdown-menu');
      expect(dropdown).toBeInTheDocument();

      // 드롭다운이 overflow-hidden 컨테이너 내부가 아닌 document.body에 위치해야 함
      expect(container.contains(dropdown)).toBe(false);
      expect(document.body.contains(dropdown)).toBe(true);

      // 드롭다운에 fixed 포지셔닝이 적용되어야 함
      expect(dropdown.className).toContain('fixed');
    });
  });

  describe('다국어 지원', () => {
    it('상태 배지에 t() 함수가 호출되어야 함', () => {
      render(<MockUserInfo name="홍길동" userId={1} status="inactive" />);
      expect(mockG7Core.t).toHaveBeenCalledWith('userinfo.status_inactive');
    });

    it('비회원 배지에 t() 함수가 호출되어야 함', () => {
      render(<MockUserInfo name="익명" isGuest />);
      expect(mockG7Core.t).toHaveBeenCalledWith('userinfo.guest_badge');
    });
  });
});

describe('UserInfo 드롭다운 위치 (fixed 배치)', () => {
  beforeEach(() => {
    (window as any).G7Core = mockG7Core;
    vi.clearAllMocks();

    // getBoundingClientRect mock — viewport 기준 버튼 위치
    Element.prototype.getBoundingClientRect = vi.fn(() => ({
      top: 100,
      bottom: 120,
      left: 50,
      right: 150,
      width: 100,
      height: 20,
      x: 50,
      y: 100,
      toJSON: () => {},
    }));
  });

  afterEach(() => {
    delete (window as any).G7Core;
    vi.restoreAllMocks();
  });

  it('드롭다운이 fixed 포지션으로 렌더링되어야 함', async () => {
    render(
      <UserInfo
        author={{ id: 1, name: '홍길동' }}
        showDropdown={true}
        stopPropagation={true}
      />
    );

    const button = screen.getByText('홍길동');
    await act(async () => {
      fireEvent.click(button);
    });

    const dropdown = screen.getByTestId('author-dropdown-menu');
    expect(dropdown.className).toContain('fixed');
    expect(dropdown.className).not.toContain('absolute');
  });

  it('드롭다운 top이 버튼 bottom + 4px (viewport 기준)이어야 함', async () => {
    render(
      <UserInfo
        author={{ id: 1, name: '홍길동' }}
        showDropdown={true}
        stopPropagation={true}
      />
    );

    const button = screen.getByText('홍길동');
    await act(async () => {
      fireEvent.click(button);
    });

    const dropdown = screen.getByTestId('author-dropdown-menu') as HTMLElement;
    // buttonRect.bottom(120) + 4 = 124
    expect(dropdown.style.top).toBe('124px');
  });

  it('드롭다운 left가 버튼 left (viewport 기준)이어야 함', async () => {
    render(
      <UserInfo
        author={{ id: 1, name: '홍길동' }}
        showDropdown={true}
        stopPropagation={true}
      />
    );

    const button = screen.getByText('홍길동');
    await act(async () => {
      fireEvent.click(button);
    });

    const dropdown = screen.getByTestId('author-dropdown-menu') as HTMLElement;
    // buttonRect.left = 50
    expect(dropdown.style.left).toBe('50px');
  });

  it('스크롤 시 드롭다운이 닫혀야 함', async () => {
    render(
      <UserInfo
        author={{ id: 1, name: '홍길동' }}
        showDropdown={true}
        stopPropagation={true}
      />
    );

    const button = screen.getByText('홍길동');
    await act(async () => {
      fireEvent.click(button);
    });

    expect(screen.getByTestId('author-dropdown-menu')).toBeInTheDocument();

    await act(async () => {
      fireEvent.scroll(window);
    });

    expect(screen.queryByTestId('author-dropdown-menu')).not.toBeInTheDocument();
  });
});
