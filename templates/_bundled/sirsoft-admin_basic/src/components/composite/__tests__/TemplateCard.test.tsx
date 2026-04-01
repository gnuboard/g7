import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { TemplateCard, TemplateStatus } from '../TemplateCard';
import { IconName } from '../../basic/IconTypes';

describe('TemplateCard', () => {
  const defaultProps = {
    vendor: 'sirsoft',
    name: 'admin_basic',
    version: '1.0.0',
    status: 'active' as TemplateStatus,
  };

  describe('기본 렌더링', () => {
    it('필수 정보를 렌더링해야 함', () => {
      render(<TemplateCard {...defaultProps} />);

      expect(screen.getByText('sirsoft/admin_basic')).toBeInTheDocument();
      expect(screen.getByText('v1.0.0')).toBeInTheDocument();
      expect(screen.getByText('활성화')).toBeInTheDocument();
    });

    it('border와 rounded 스타일을 가져야 함', () => {
      const { container } = render(<TemplateCard {...defaultProps} />);

      const card = container.querySelector('.border');
      expect(card).toHaveClass('rounded-lg', 'border-gray-200');
    });
  });

  describe('미리보기 이미지', () => {
    it('이미지가 제공되면 렌더링해야 함', () => {
      render(
        <TemplateCard
          {...defaultProps}
          image="/preview.png"
          imageAlt="템플릿 미리보기"
        />
      );

      const img = screen.getByAltText('템플릿 미리보기');
      expect(img).toBeInTheDocument();
      expect(img).toHaveAttribute('src', '/preview.png');
    });

    it('이미지가 없으면 렌더링하지 않아야 함', () => {
      const { container } = render(<TemplateCard {...defaultProps} />);

      const img = container.querySelector('img');
      expect(img).not.toBeInTheDocument();
    });

    it('imageAlt가 없으면 기본 alt를 사용해야 함', () => {
      render(<TemplateCard {...defaultProps} image="/preview.png" />);

      // common.template_preview 번역: '{{vendor}}/{{name}} 미리보기'
      const img = screen.getByAltText('sirsoft/admin_basic 미리보기');
      expect(img).toBeInTheDocument();
    });
  });

  describe('상태 뱃지', () => {
    const statusTests: Array<{ status: TemplateStatus; label: string }> = [
      { status: 'active', label: '활성화' },
      { status: 'inactive', label: '비활성화' },
      { status: 'pending', label: '대기중' },  // 번역 값과 일치
      { status: 'error', label: '오류' },
    ];

    statusTests.forEach(({ status, label }) => {
      it(`status="${status}"일 때 올바른 라벨을 표시해야 함`, () => {
        render(<TemplateCard {...defaultProps} status={status} />);

        expect(screen.getByText(label)).toBeInTheDocument();
      });
    });
  });

  describe('업데이트 정보', () => {
    it('업데이트가 가능하면 뱃지를 표시해야 함', () => {
      render(
        <TemplateCard
          {...defaultProps}
          image="/preview.png"
          updateAvailable={true}
          latestVersion="1.1.0"
        />
      );

      expect(screen.getByText('업데이트 가능')).toBeInTheDocument();
      expect(screen.getByText('v1.1.0')).toBeInTheDocument();
    });

    it('업데이트가 없으면 뱃지를 표시하지 않아야 함', () => {
      render(<TemplateCard {...defaultProps} updateAvailable={false} />);

      expect(screen.queryByText('업데이트 가능')).not.toBeInTheDocument();
    });

    it('이미지가 없으면 업데이트 뱃지를 표시하지 않아야 함', () => {
      render(
        <TemplateCard
          {...defaultProps}
          updateAvailable={true}
          latestVersion="1.1.0"
        />
      );

      expect(screen.queryByText('업데이트 가능')).not.toBeInTheDocument();
    });
  });

  describe('의존성', () => {
    it('의존성 목록을 렌더링해야 함', () => {
      render(
        <TemplateCard
          {...defaultProps}
          dependencies={['laravel/framework:^10.0', 'vue:^3.0']}
        />
      );

      expect(screen.getByText('의존성:')).toBeInTheDocument();
      expect(screen.getByText('laravel/framework:^10.0')).toBeInTheDocument();
      expect(screen.getByText('vue:^3.0')).toBeInTheDocument();
    });

    it('의존성이 없으면 섹션을 표시하지 않아야 함', () => {
      render(<TemplateCard {...defaultProps} dependencies={[]} />);

      expect(screen.queryByText('의존성:')).not.toBeInTheDocument();
    });
  });

  describe('레이아웃 편집 버튼', () => {
    it('showLayoutEditButton=true이고 onLayoutEditClick이 제공되면 버튼을 렌더링해야 함', () => {
      const handleClick = vi.fn();
      render(
        <TemplateCard
          {...defaultProps}
          showLayoutEditButton={true}
          onLayoutEditClick={handleClick}
        />
      );

      expect(screen.getByText('레이아웃 편집')).toBeInTheDocument();
    });

    it('레이아웃 편집 버튼 클릭 시 onLayoutEditClick이 호출되어야 함', () => {
      const handleClick = vi.fn();
      render(
        <TemplateCard
          {...defaultProps}
          showLayoutEditButton={true}
          onLayoutEditClick={handleClick}
        />
      );

      const button = screen.getByText('레이아웃 편집').closest('button');
      fireEvent.click(button!);

      expect(handleClick).toHaveBeenCalledTimes(1);
    });

    it('showLayoutEditButton=false이면 버튼을 렌더링하지 않아야 함', () => {
      render(
        <TemplateCard
          {...defaultProps}
          showLayoutEditButton={false}
          onLayoutEditClick={() => {}}
        />
      );

      expect(screen.queryByText('레이아웃 편집')).not.toBeInTheDocument();
    });

    it('onLayoutEditClick이 없으면 버튼을 렌더링하지 않아야 함', () => {
      render(<TemplateCard {...defaultProps} showLayoutEditButton={true} />);

      expect(screen.queryByText('레이아웃 편집')).not.toBeInTheDocument();
    });
  });

  describe('액션 메뉴', () => {
    it('actions가 제공되면 ActionMenu를 렌더링해야 함', () => {
      const actions = [
        {
          id: 'info',
          label: '정보 보기',
          iconName: IconName.InfoCircle,
          onClick: vi.fn(),
        },
        {
          id: 'update',
          label: '업데이트',
          iconName: IconName.Download,
          onClick: vi.fn(),
        },
      ];

      const { container } = render(
        <TemplateCard {...defaultProps} actions={actions} />
      );

      // ActionMenu의 트리거 버튼 확인 (ellipsis-vertical 아이콘)
      const menuTrigger = container.querySelector('button');
      expect(menuTrigger).toBeInTheDocument();
    });

    it('actions가 비어있으면 ActionMenu를 렌더링하지 않아야 함', () => {
      const { container } = render(<TemplateCard {...defaultProps} actions={[]} />);

      // 레이아웃 편집 버튼이 없다면 버튼이 없어야 함
      const buttons = container.querySelectorAll('button');
      expect(buttons.length).toBe(0);
    });
  });

  describe('스타일 커스터마이징', () => {
    it('className prop을 적용해야 함', () => {
      const { container } = render(
        <TemplateCard {...defaultProps} className="custom-card" />
      );

      const card = container.querySelector('.custom-card');
      expect(card).toBeInTheDocument();
    });
  });

  describe('복합 시나리오', () => {
    it('모든 props를 함께 사용할 수 있어야 함', () => {
      const handleLayoutEdit = vi.fn();
      const handleAction = vi.fn();

      const actions = [
        {
          id: 'info',
          label: '정보 보기',
          iconName: IconName.InfoCircle,
          onClick: handleAction,
        },
      ];

      render(
        <TemplateCard
          vendor="sirsoft"
          name="admin_basic"
          version="1.0.0"
          status="active"
          image="/preview.png"
          imageAlt="미리보기"
          updateAvailable={true}
          latestVersion="1.1.0"
          dependencies={['laravel/framework:^10.0']}
          showLayoutEditButton={true}
          onLayoutEditClick={handleLayoutEdit}
          actions={actions}
          className="my-template-card"
        />
      );

      expect(screen.getByText('sirsoft/admin_basic')).toBeInTheDocument();
      expect(screen.getByText('v1.0.0')).toBeInTheDocument();
      expect(screen.getByText('활성화')).toBeInTheDocument();
      expect(screen.getByAltText('미리보기')).toBeInTheDocument();
      expect(screen.getByText('업데이트 가능')).toBeInTheDocument();
      expect(screen.getByText('v1.1.0')).toBeInTheDocument();
      expect(screen.getByText('laravel/framework:^10.0')).toBeInTheDocument();
      expect(screen.getByText('레이아웃 편집')).toBeInTheDocument();

      const layoutEditButton = screen.getByText('레이아웃 편집').closest('button');
      fireEvent.click(layoutEditButton!);
      expect(handleLayoutEdit).toHaveBeenCalledTimes(1);
    });
  });
});
