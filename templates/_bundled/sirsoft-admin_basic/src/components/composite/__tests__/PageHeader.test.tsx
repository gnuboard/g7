import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { PageHeader, BreadcrumbItem, TabItem, ActionButton } from '../PageHeader';
import { IconName } from '../../basic/IconTypes';

describe('PageHeader', () => {
  const mockBreadcrumbs: BreadcrumbItem[] = [
    { label: '홈', href: '/' },
    { label: '템플릿', href: '/templates' },
    { label: '관리' },
  ];

  const mockTabs: TabItem[] = [
    { id: 1, label: '사용자', value: 'user', active: true },
    { id: 2, label: '관리자', value: 'admin' },
  ];

  const mockActions: ActionButton[] = [
    { id: 1, label: '새로 만들기', variant: 'primary', iconName: IconName.Plus },
  ];

  it('컴포넌트가 렌더링됨', () => {
    render(<PageHeader title="테스트 페이지" />);

    expect(screen.getByText('테스트 페이지')).toBeInTheDocument();
  });

  it('description이 표시됨', () => {
    render(
      <PageHeader
        title="테스트 페이지"
        description="페이지 설명"
      />
    );

    expect(screen.getByText('페이지 설명')).toBeInTheDocument();
  });

  it('breadcrumb이 표시됨', () => {
    render(
      <PageHeader
        title="테스트 페이지"
        breadcrumbItems={mockBreadcrumbs}
      />
    );

    expect(screen.getByText('홈')).toBeInTheDocument();
    expect(screen.getByText('템플릿')).toBeInTheDocument();
    expect(screen.getByText('관리')).toBeInTheDocument();
  });

  it('탭이 표시됨', () => {
    render(
      <PageHeader
        title="테스트 페이지"
        tabs={mockTabs}
      />
    );

    expect(screen.getByText('사용자')).toBeInTheDocument();
    expect(screen.getByText('관리자')).toBeInTheDocument();
  });

  it('탭 클릭 시 onTabChange가 호출됨', async () => {
    const user = userEvent.setup();
    const onTabChange = vi.fn();

    render(
      <PageHeader
        title="테스트 페이지"
        tabs={mockTabs}
        onTabChange={onTabChange}
      />
    );

    const adminTab = screen.getByText('관리자');
    await user.click(adminTab);

    expect(onTabChange).toHaveBeenCalledWith('admin');
  });

  it('활성 탭에 active 스타일이 적용됨', () => {
    render(
      <PageHeader
        title="테스트 페이지"
        tabs={mockTabs}
      />
    );

    const activeTab = screen.getByText('사용자').closest('button');
    expect(activeTab).toHaveClass('text-blue-600', 'border-blue-600');
  });

  it('탭 뱃지가 표시됨', () => {
    const tabsWithBadge: TabItem[] = [
      { id: 1, label: '알림', value: 'notifications', badge: 3 },
    ];

    render(
      <PageHeader
        title="테스트 페이지"
        tabs={tabsWithBadge}
      />
    );

    expect(screen.getByText('3')).toBeInTheDocument();
  });

  it('액션 버튼이 표시됨', () => {
    render(
      <PageHeader
        title="테스트 페이지"
        actions={mockActions}
      />
    );

    expect(screen.getByText('새로 만들기')).toBeInTheDocument();
  });

  it('액션 버튼 클릭 시 onClick이 호출됨', async () => {
    const user = userEvent.setup();
    const onClick = vi.fn();
    const actions: ActionButton[] = [
      { id: 1, label: '추가', onClick },
    ];

    render(
      <PageHeader
        title="테스트 페이지"
        actions={actions}
      />
    );

    const button = screen.getByText('추가');
    await user.click(button);

    expect(onClick).toHaveBeenCalledTimes(1);
  });

  it('비활성화된 액션 버튼은 클릭되지 않음', async () => {
    const user = userEvent.setup();
    const onClick = vi.fn();
    const actions: ActionButton[] = [
      { id: 1, label: '추가', onClick, disabled: true },
    ];

    render(
      <PageHeader
        title="테스트 페이지"
        actions={actions}
      />
    );

    const button = screen.getByText('추가').closest('button');
    expect(button).toBeDisabled();

    await user.click(button!);
    expect(onClick).not.toHaveBeenCalled();
  });

  it('모든 요소가 함께 표시됨', () => {
    render(
      <PageHeader
        title="테스트 페이지"
        description="페이지 설명"
        breadcrumbItems={mockBreadcrumbs}
        tabs={mockTabs}
        actions={mockActions}
      />
    );

    expect(screen.getByText('테스트 페이지')).toBeInTheDocument();
    expect(screen.getByText('페이지 설명')).toBeInTheDocument();
    expect(screen.getByText('홈')).toBeInTheDocument();
    expect(screen.getByText('사용자')).toBeInTheDocument();
    expect(screen.getByText('새로 만들기')).toBeInTheDocument();
  });

  it('className prop이 적용됨', () => {
    const { container } = render(
      <PageHeader
        title="테스트 페이지"
        className="custom-header"
      />
    );
    expect(container.firstChild).toHaveClass('custom-header');
  });
});
