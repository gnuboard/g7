import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { LayoutEditorHeader } from '../LayoutEditorHeader';

describe('LayoutEditorHeader 컴포넌트', () => {
  const defaultProps = {
    layoutName: '메인 레이아웃',
    onBack: vi.fn(),
    onPreview: vi.fn(),
    onSave: vi.fn(),
  };

  describe('기본 렌더링', () => {
    it('layoutName이 렌더링된다', () => {
      render(<LayoutEditorHeader {...defaultProps} />);
      expect(screen.getByText('메인 레이아웃')).toBeTruthy();
    });

    it('layoutName이 h2 요소에 렌더링된다', () => {
      const { container } = render(<LayoutEditorHeader {...defaultProps} />);
      const h2Element = container.querySelector('h2');
      expect(h2Element?.textContent).toBe('메인 레이아웃');
    });

    it('뒤로가기 버튼이 렌더링된다', () => {
      const { container } = render(<LayoutEditorHeader {...defaultProps} />);
      const backButton = container.querySelector('[aria-label="뒤로가기"]');
      expect(backButton).toBeTruthy();
    });

    it('미리보기 버튼이 렌더링된다', () => {
      render(<LayoutEditorHeader {...defaultProps} />);
      expect(screen.getByText('미리보기')).toBeTruthy();
    });

    it('저장 버튼이 렌더링된다', () => {
      render(<LayoutEditorHeader {...defaultProps} />);
      expect(screen.getByText('저장')).toBeTruthy();
    });
  });

  describe('버튼 클릭 이벤트', () => {
    it('뒤로가기 버튼 클릭 시 onBack 콜백이 호출된다', () => {
      const onBack = vi.fn();
      const { container } = render(
        <LayoutEditorHeader {...defaultProps} onBack={onBack} />
      );

      const backButton = container.querySelector('[aria-label="뒤로가기"]') as HTMLElement;
      expect(backButton).toBeTruthy();

      fireEvent.click(backButton);
      expect(onBack).toHaveBeenCalledTimes(1);
    });

    it('미리보기 버튼 클릭 시 onPreview 콜백이 호출된다', () => {
      const onPreview = vi.fn();
      const { container } = render(
        <LayoutEditorHeader {...defaultProps} onPreview={onPreview} />
      );

      const previewButton = container.querySelector('[aria-label="미리보기"]') as HTMLElement;
      expect(previewButton).toBeTruthy();

      fireEvent.click(previewButton);
      expect(onPreview).toHaveBeenCalledTimes(1);
    });

    it('저장 버튼 클릭 시 onSave 콜백이 호출된다', () => {
      const onSave = vi.fn();
      render(<LayoutEditorHeader {...defaultProps} onSave={onSave} />);

      const saveButton = screen.getByText('저장');
      fireEvent.click(saveButton);
      expect(onSave).toHaveBeenCalledTimes(1);
    });

    it('각 버튼을 여러 번 클릭하면 콜백이 여러 번 호출된다', () => {
      const onBack = vi.fn();
      const onPreview = vi.fn();
      const onSave = vi.fn();
      const { container } = render(
        <LayoutEditorHeader
          {...defaultProps}
          onBack={onBack}
          onPreview={onPreview}
          onSave={onSave}
        />
      );

      const backButton = container.querySelector('[aria-label="뒤로가기"]') as HTMLElement;
      const previewButton = container.querySelector('[aria-label="미리보기"]') as HTMLElement;
      const saveButton = screen.getByText('저장');

      fireEvent.click(backButton);
      fireEvent.click(backButton);
      expect(onBack).toHaveBeenCalledTimes(2);

      fireEvent.click(previewButton);
      fireEvent.click(previewButton);
      expect(onPreview).toHaveBeenCalledTimes(2);

      fireEvent.click(saveButton);
      fireEvent.click(saveButton);
      expect(onSave).toHaveBeenCalledTimes(2);
    });
  });

  describe('isSaving 상태', () => {
    it('isSaving이 false일 때 저장 버튼이 활성화된다', () => {
      render(<LayoutEditorHeader {...defaultProps} isSaving={false} />);

      const saveButton = screen.getByText('저장').closest('button');
      expect(saveButton?.disabled).toBe(false);
    });

    it('isSaving이 true일 때 저장 버튼이 비활성화된다', () => {
      render(<LayoutEditorHeader {...defaultProps} isSaving={true} />);

      const saveButton = screen.getByText('저장 중...').closest('button');
      expect(saveButton?.disabled).toBe(true);
    });

    it('isSaving이 true일 때 "저장 중..." 텍스트가 표시된다', () => {
      render(<LayoutEditorHeader {...defaultProps} isSaving={true} />);
      expect(screen.getByText('저장 중...')).toBeTruthy();
    });

    it('isSaving이 false일 때 "저장" 텍스트가 표시된다', () => {
      render(<LayoutEditorHeader {...defaultProps} isSaving={false} />);
      expect(screen.getByText('저장')).toBeTruthy();
    });

    it('isSaving 기본값은 false이다', () => {
      render(<LayoutEditorHeader {...defaultProps} />);

      const saveButton = screen.getByText('저장').closest('button');
      expect(saveButton?.disabled).toBe(false);
    });

    it('isSaving이 true일 때 저장 버튼 클릭해도 onSave가 호출되지 않는다', () => {
      const onSave = vi.fn();
      render(<LayoutEditorHeader {...defaultProps} onSave={onSave} isSaving={true} />);

      const saveButton = screen.getByText('저장 중...').closest('button') as HTMLElement;
      fireEvent.click(saveButton);

      // disabled 버튼은 클릭 이벤트가 발생하지 않음
      expect(onSave).not.toHaveBeenCalled();
    });

    it('isSaving이 true일 때 spinner 아이콘이 표시된다', () => {
      const { container } = render(<LayoutEditorHeader {...defaultProps} isSaving={true} />);
      const spinnerIcon = container.querySelector('.fa-spinner');
      expect(spinnerIcon).toBeTruthy();
    });

    it('isSaving이 false일 때 save 아이콘이 표시된다', () => {
      const { container } = render(<LayoutEditorHeader {...defaultProps} isSaving={false} />);
      const saveIcon = container.querySelector('.fa-save');
      expect(saveIcon).toBeTruthy();
    });

    it('isSaving이 true일 때 spinner 아이콘에 animate-spin 클래스가 적용된다', () => {
      const { container } = render(<LayoutEditorHeader {...defaultProps} isSaving={true} />);
      const spinnerIcon = container.querySelector('.fa-spinner');
      expect(spinnerIcon?.className).toContain('animate-spin');
    });
  });

  describe('aria-label 속성', () => {
    it('뒤로가기 버튼에 aria-label이 있다', () => {
      const { container } = render(<LayoutEditorHeader {...defaultProps} />);
      const backButton = container.querySelector('[aria-label="뒤로가기"]');
      expect(backButton).toBeTruthy();
    });

    it('미리보기 버튼에 aria-label이 있다', () => {
      const { container } = render(<LayoutEditorHeader {...defaultProps} />);
      const previewButton = container.querySelector('[aria-label="미리보기"]');
      expect(previewButton).toBeTruthy();
    });

    it('저장 버튼에 aria-label이 있다 (isSaving=false)', () => {
      const { container } = render(<LayoutEditorHeader {...defaultProps} isSaving={false} />);
      const saveButton = container.querySelector('[aria-label="저장"]');
      expect(saveButton).toBeTruthy();
    });

    it('저장 버튼에 aria-label이 있다 (isSaving=true)', () => {
      const { container } = render(<LayoutEditorHeader {...defaultProps} isSaving={true} />);
      const saveButton = container.querySelector('[aria-label="저장 중..."]');
      expect(saveButton).toBeTruthy();
    });

    it('뒤로가기 아이콘에 aria-label이 있다', () => {
      const { container } = render(<LayoutEditorHeader {...defaultProps} />);
      const backIcon = container.querySelector('[aria-label="뒤로가기"]');
      expect(backIcon).toBeTruthy();
    });

    it('미리보기 아이콘에 aria-label이 있다', () => {
      const { container } = render(<LayoutEditorHeader {...defaultProps} />);
      const previewIcon = container.querySelector('[aria-label="미리보기 아이콘"]');
      expect(previewIcon).toBeTruthy();
    });

    it('저장 아이콘에 aria-label이 있다 (isSaving=false)', () => {
      const { container } = render(<LayoutEditorHeader {...defaultProps} isSaving={false} />);
      const saveIcon = container.querySelector('[aria-label="저장 아이콘"]');
      expect(saveIcon).toBeTruthy();
    });

    it('저장 중 아이콘에 aria-label이 있다 (isSaving=true)', () => {
      const { container } = render(<LayoutEditorHeader {...defaultProps} isSaving={true} />);
      const savingIcon = container.querySelector('[aria-label="저장 중 아이콘"]');
      expect(savingIcon).toBeTruthy();
    });
  });

  describe('아이콘 렌더링', () => {
    it('뒤로가기 버튼에 arrow-left 아이콘이 표시된다', () => {
      const { container } = render(<LayoutEditorHeader {...defaultProps} />);
      const arrowIcon = container.querySelector('.fa-arrow-left');
      expect(arrowIcon).toBeTruthy();
    });

    it('미리보기 버튼에 eye 아이콘이 표시된다', () => {
      const { container } = render(<LayoutEditorHeader {...defaultProps} />);
      const eyeIcon = container.querySelector('.fa-eye');
      expect(eyeIcon).toBeTruthy();
    });

    it('저장 버튼에 save 아이콘이 표시된다 (isSaving=false)', () => {
      const { container } = render(<LayoutEditorHeader {...defaultProps} isSaving={false} />);
      const saveIcon = container.querySelector('.fa-save');
      expect(saveIcon).toBeTruthy();
    });

    it('저장 버튼에 spinner 아이콘이 표시된다 (isSaving=true)', () => {
      const { container } = render(<LayoutEditorHeader {...defaultProps} isSaving={true} />);
      const spinnerIcon = container.querySelector('.fa-spinner');
      expect(spinnerIcon).toBeTruthy();
    });
  });

  describe('사용자 정의 Props', () => {
    it('사용자 정의 클래스가 적용된다', () => {
      const { container } = render(
        <LayoutEditorHeader {...defaultProps} className="custom-header" />
      );
      const headerElement = container.querySelector('.custom-header');
      expect(headerElement).toBeTruthy();
    });

    it('사용자 정의 클래스가 기본 클래스와 함께 적용된다', () => {
      const { container } = render(
        <LayoutEditorHeader {...defaultProps} className="custom-class" />
      );
      const headerElement = container.querySelector('.custom-class');
      expect(headerElement?.className).toContain('custom-class');
      expect(headerElement?.className).toContain('flex');
    });
  });

  describe('복합 시나리오', () => {
    it('다양한 layoutName이 정상적으로 표시된다', () => {
      const names = ['짧은이름', '매우 긴 레이아웃 이름입니다 테스트', '특수문자!@#$%'];

      names.forEach((name) => {
        const { rerender } = render(<LayoutEditorHeader {...defaultProps} layoutName={name} />);
        expect(screen.getByText(name)).toBeTruthy();
        rerender(<LayoutEditorHeader {...defaultProps} layoutName={names[0]} />);
      });
    });

    it('isSaving 상태 전환이 정상적으로 동작한다', () => {
      const { rerender } = render(<LayoutEditorHeader {...defaultProps} isSaving={false} />);

      // isSaving=false 상태 확인
      expect(screen.getByText('저장')).toBeTruthy();
      let saveButton = screen.getByText('저장').closest('button');
      expect(saveButton?.disabled).toBe(false);

      // isSaving=true로 전환
      rerender(<LayoutEditorHeader {...defaultProps} isSaving={true} />);
      expect(screen.getByText('저장 중...')).toBeTruthy();
      saveButton = screen.getByText('저장 중...').closest('button');
      expect(saveButton?.disabled).toBe(true);

      // isSaving=false로 다시 전환
      rerender(<LayoutEditorHeader {...defaultProps} isSaving={false} />);
      expect(screen.getByText('저장')).toBeTruthy();
      saveButton = screen.getByText('저장').closest('button');
      expect(saveButton?.disabled).toBe(false);
    });
  });

  describe('레이아웃 구조', () => {
    it('flexbox로 좌우 정렬이 구현된다', () => {
      const { container } = render(<LayoutEditorHeader {...defaultProps} />);
      const headerElement = container.firstChild as HTMLElement;
      expect(headerElement?.className).toContain('flex');
      expect(headerElement?.className).toContain('justify-between');
    });

    it('좌측 영역에 뒤로가기와 제목이 있다', () => {
      const { container } = render(<LayoutEditorHeader {...defaultProps} />);
      const leftSection = container.querySelector('.flex.items-center.gap-3');
      expect(leftSection).toBeTruthy();

      const backButton = leftSection?.querySelector('[aria-label="뒤로가기"]');
      const title = leftSection?.querySelector('h2');
      expect(backButton).toBeTruthy();
      expect(title).toBeTruthy();
    });

    it('우측 영역에 미리보기와 저장 버튼이 있다', () => {
      const { container } = render(<LayoutEditorHeader {...defaultProps} />);
      const rightSection = container.querySelector('.flex.items-center.gap-2');
      expect(rightSection).toBeTruthy();

      const previewButton = screen.getByText('미리보기');
      const saveButton = screen.getByText('저장');
      expect(previewButton).toBeTruthy();
      expect(saveButton).toBeTruthy();
    });
  });

  describe('다크 모드 클래스', () => {
    it('다크 모드 클래스가 포함된다', () => {
      const { container } = render(<LayoutEditorHeader {...defaultProps} />);
      const headerElement = container.firstChild as HTMLElement;
      expect(headerElement?.className).toContain('dark:bg-gray-800');
      expect(headerElement?.className).toContain('dark:border-gray-700');
    });
  });
});
