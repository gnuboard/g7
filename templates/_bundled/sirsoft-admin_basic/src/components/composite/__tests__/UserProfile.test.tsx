import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { UserProfile, User } from '../UserProfile';

describe('UserProfile', () => {
  const mockUser: User = {
    name: 'John Doe',
    email: 'john@example.com',
    avatar: '/avatar.png',
    role: 'Administrator',
  };

  describe('렌더링 테스트', () => {
    it('컴포넌트가 렌더링됨', () => {
      render(<UserProfile user={mockUser} />);
      expect(screen.getByText('John Doe')).toBeInTheDocument();
    });

    it('사용자 이름이 표시됨', () => {
      render(<UserProfile user={mockUser} />);
      expect(screen.getByText('John Doe')).toBeInTheDocument();
    });

    it('사용자 역할이 표시됨', () => {
      render(<UserProfile user={mockUser} />);
      expect(screen.getByText('Administrator')).toBeInTheDocument();
    });

    it('아바타 이미지가 표시됨', () => {
      render(<UserProfile user={mockUser} />);
      const avatar = screen.getByAltText('John Doe');
      expect(avatar).toBeInTheDocument();
      expect(avatar).toHaveAttribute('src', '/avatar.png');
    });

    it('아바타가 없을 때 기본 아이콘이 표시됨', () => {
      const userWithoutAvatar = { ...mockUser, avatar: undefined };
      render(<UserProfile user={userWithoutAvatar} />);

      // 기본 아이콘 컨테이너가 존재하는지 확인
      const defaultIconContainer = screen.getByText('John Doe').closest('button');
      expect(defaultIconContainer).toBeInTheDocument();
    });

    it('역할이 없을 때도 정상 렌더링됨', () => {
      const userWithoutRole = { name: 'Jane Doe', email: 'jane@example.com' };
      render(<UserProfile user={userWithoutRole} />);
      expect(screen.getByText('Jane Doe')).toBeInTheDocument();
    });

    it('기본 사용자 객체로 렌더링됨', () => {
      render(<UserProfile user={{ name: '', email: '' }} />);
      // 빈 이름으로도 렌더링되는지 확인
      const button = screen.getByRole('button');
      expect(button).toBeInTheDocument();
    });
  });

  describe('프로필 드롭다운 테스트', () => {
    it('프로필 버튼 클릭 시 드롭다운이 표시됨', async () => {
      const user = userEvent.setup();
      render(<UserProfile user={mockUser} />);

      const profileButton = screen.getByText('John Doe').closest('button');
      await user.click(profileButton!);

      expect(screen.getByText('Profile Settings')).toBeInTheDocument();
      expect(screen.getByText('Logout')).toBeInTheDocument();
    });

    it('드롭다운에 사용자 이메일이 표시됨', async () => {
      const user = userEvent.setup();
      render(<UserProfile user={mockUser} />);

      const profileButton = screen.getByText('John Doe').closest('button');
      await user.click(profileButton!);

      // 드롭다운 내의 이메일 확인
      const emails = screen.getAllByText('john@example.com');
      expect(emails.length).toBeGreaterThan(0);
    });

    it('드롭다운이 아래에서 위로 열림 (bottom-full 클래스)', async () => {
      const user = userEvent.setup();
      const { container } = render(<UserProfile user={mockUser} />);

      const profileButton = screen.getByText('John Doe').closest('button');
      await user.click(profileButton!);

      const dropdown = container.querySelector('.absolute.bottom-full');
      expect(dropdown).toBeInTheDocument();
    });
  });

  describe('다국어 Props 테스트', () => {
    it('커스텀 profileText가 표시됨', async () => {
      const user = userEvent.setup();
      render(<UserProfile user={mockUser} profileText="프로필 설정" />);

      const profileButton = screen.getByText('John Doe').closest('button');
      await user.click(profileButton!);

      expect(screen.getByText('프로필 설정')).toBeInTheDocument();
    });

    it('커스텀 logoutText가 표시됨', async () => {
      const user = userEvent.setup();
      render(<UserProfile user={mockUser} logoutText="로그아웃" />);

      const profileButton = screen.getByText('John Doe').closest('button');
      await user.click(profileButton!);

      expect(screen.getByText('로그아웃')).toBeInTheDocument();
    });

    it('기본 영어 텍스트가 표시됨', async () => {
      const user = userEvent.setup();
      render(<UserProfile user={mockUser} />);

      const profileButton = screen.getByText('John Doe').closest('button');
      await user.click(profileButton!);

      expect(screen.getByText('Profile Settings')).toBeInTheDocument();
      expect(screen.getByText('Logout')).toBeInTheDocument();
    });
  });

  describe('이벤트 핸들러 테스트', () => {
    it('프로필 설정 클릭 시 onProfileClick이 호출됨', async () => {
      const user = userEvent.setup();
      const onProfileClick = vi.fn();
      render(<UserProfile user={mockUser} onProfileClick={onProfileClick} />);

      const profileButton = screen.getByText('John Doe').closest('button');
      await user.click(profileButton!);

      const settingsButton = screen.getByText('Profile Settings');
      await user.click(settingsButton);

      expect(onProfileClick).toHaveBeenCalledTimes(1);
    });

    it('로그아웃 클릭 시 onLogoutClick이 호출됨', async () => {
      const user = userEvent.setup();
      const onLogoutClick = vi.fn();
      render(<UserProfile user={mockUser} onLogoutClick={onLogoutClick} />);

      const profileButton = screen.getByText('John Doe').closest('button');
      await user.click(profileButton!);

      const logoutButton = screen.getByText('Logout');
      await user.click(logoutButton);

      expect(onLogoutClick).toHaveBeenCalledTimes(1);
    });

    it('프로필 설정 클릭 시 드롭다운이 닫힘', async () => {
      const user = userEvent.setup();
      const onProfileClick = vi.fn();
      render(<UserProfile user={mockUser} onProfileClick={onProfileClick} />);

      const profileButton = screen.getByText('John Doe').closest('button');
      await user.click(profileButton!);

      const settingsButton = screen.getByText('Profile Settings');
      await user.click(settingsButton);

      // 드롭다운이 닫혔는지 확인
      expect(screen.queryByText('john@example.com')).not.toBeInTheDocument();
    });

    it('로그아웃 클릭 시 드롭다운이 닫힘', async () => {
      const user = userEvent.setup();
      const onLogoutClick = vi.fn();
      render(<UserProfile user={mockUser} onLogoutClick={onLogoutClick} />);

      const profileButton = screen.getByText('John Doe').closest('button');
      await user.click(profileButton!);

      const logoutButton = screen.getByText('Logout');
      await user.click(logoutButton);

      // 드롭다운이 닫혔는지 확인 (이메일이 드롭다운에만 있음)
      const emails = screen.queryAllByText('john@example.com');
      expect(emails.length).toBeLessThan(2); // 버튼 내부 것만 남음
    });
  });

  describe('외부 클릭 감지 테스트', () => {
    it('드롭다운 외부 클릭 시 드롭다운이 닫힘', async () => {
      const user = userEvent.setup();
      render(
        <div>
          <UserProfile user={mockUser} />
          <div data-testid="outside">Outside Element</div>
        </div>
      );

      const profileButton = screen.getByText('John Doe').closest('button');
      await user.click(profileButton!);

      // 드롭다운이 열렸는지 확인
      expect(screen.getByText('Profile Settings')).toBeInTheDocument();

      // 외부 클릭
      const outsideElement = screen.getByTestId('outside');
      await user.click(outsideElement);

      // 드롭다운이 닫혔는지 확인
      expect(screen.queryByText('Profile Settings')).not.toBeInTheDocument();
    });
  });

  describe('스타일 및 className 테스트', () => {
    it('커스텀 className이 적용됨', () => {
      const { container } = render(
        <UserProfile user={mockUser} className="custom-profile" />
      );
      const wrapper = container.firstChild;
      expect(wrapper).toHaveClass('custom-profile');
    });

    it('기본 클래스가 유지됨', () => {
      const { container } = render(<UserProfile user={mockUser} />);
      const wrapper = container.firstChild;
      expect(wrapper).toHaveClass('relative');
    });

    it('로그아웃 버튼에 빨간색 텍스트가 적용됨', async () => {
      const user = userEvent.setup();
      render(<UserProfile user={mockUser} />);

      const profileButton = screen.getByText('John Doe').closest('button');
      await user.click(profileButton!);

      const logoutButton = screen.getByText('Logout').closest('button');
      expect(logoutButton).toHaveClass('text-red-600');
    });
  });

  describe('접근성 테스트', () => {
    it('프로필 버튼이 버튼으로 접근 가능함', () => {
      render(<UserProfile user={mockUser} />);
      const button = screen.getByRole('button');
      expect(button).toBeInTheDocument();
    });

    it('드롭다운 내 버튼들이 버튼으로 접근 가능함', async () => {
      const user = userEvent.setup();
      render(<UserProfile user={mockUser} />);

      const profileButton = screen.getByText('John Doe').closest('button');
      await user.click(profileButton!);

      const buttons = screen.getAllByRole('button');
      expect(buttons.length).toBeGreaterThanOrEqual(3); // 메인 버튼 + 프로필 + 로그아웃
    });
  });

  describe('복합 시나리오 테스트', () => {
    it('드롭다운 열고 닫기를 반복해도 정상 동작함', async () => {
      const user = userEvent.setup();
      render(<UserProfile user={mockUser} />);

      const profileButton = screen.getByText('John Doe').closest('button');

      // 첫 번째 열기
      await user.click(profileButton!);
      expect(screen.getByText('Profile Settings')).toBeInTheDocument();

      // 닫기 (같은 버튼 클릭)
      await user.click(profileButton!);
      expect(screen.queryByText('Profile Settings')).not.toBeInTheDocument();

      // 두 번째 열기
      await user.click(profileButton!);
      expect(screen.getByText('Profile Settings')).toBeInTheDocument();
    });

    it('여러 핸들러가 모두 정상 작동함', async () => {
      const user = userEvent.setup();
      const onProfileClick = vi.fn();
      const onLogoutClick = vi.fn();
      render(
        <UserProfile
          user={mockUser}
          onProfileClick={onProfileClick}
          onLogoutClick={onLogoutClick}
        />
      );

      const profileButton = screen.getByText('John Doe').closest('button');
      await user.click(profileButton!);

      // 프로필 클릭
      const settingsButton = screen.getByText('Profile Settings');
      await user.click(settingsButton);
      expect(onProfileClick).toHaveBeenCalledTimes(1);
      expect(onLogoutClick).not.toHaveBeenCalled();

      // 다시 열기
      await user.click(profileButton!);

      // 로그아웃 클릭
      const logoutButton = screen.getByText('Logout');
      await user.click(logoutButton);
      expect(onProfileClick).toHaveBeenCalledTimes(1);
      expect(onLogoutClick).toHaveBeenCalledTimes(1);
    });
  });
});
