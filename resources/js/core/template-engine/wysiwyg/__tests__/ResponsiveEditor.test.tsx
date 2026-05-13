/**
 * ResponsiveEditor.test.tsx
 *
 * ResponsiveEditor 컴포넌트 테스트
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { ResponsiveEditor } from '../components/PropertyPanel/ResponsiveEditor';
import type { ComponentDefinition } from '../types/editor';

// ============================================================================
// Mock 설정
// ============================================================================

// useComponentMetadata mock
vi.mock('../hooks/useComponentMetadata', () => ({
  useComponentMetadata: () => ({
    getMetadata: (name: string) => {
      if (name === 'Button') {
        return {
          name: 'Button',
          type: 'basic',
          description: 'Button component',
          props: [
            { name: 'className', type: 'text', label: 'CSS 클래스' },
            { name: 'variant', type: 'select', label: '스타일', options: [
              { label: 'Primary', value: 'primary' },
              { label: 'Secondary', value: 'secondary' },
            ]},
            { name: 'disabled', type: 'boolean', label: '비활성화' },
            { name: 'size', type: 'select', label: '크기', options: [
              { label: 'Small', value: 'sm' },
              { label: 'Medium', value: 'md' },
              { label: 'Large', value: 'lg' },
            ]},
          ],
        };
      }
      return null;
    },
  }),
}));

// ============================================================================
// 테스트 헬퍼
// ============================================================================

function createTestComponent(overrides: Partial<ComponentDefinition> = {}): ComponentDefinition {
  return {
    id: 'test-button',
    type: 'basic',
    name: 'Button',
    props: {
      className: 'px-4 py-2',
      variant: 'primary',
    },
    text: '버튼 텍스트',
    ...overrides,
  };
}

// ============================================================================
// 테스트
// ============================================================================

describe('ResponsiveEditor', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  describe('기본 렌더링', () => {
    it('반응형 오버라이드 헤더가 표시되어야 함', () => {
      const component = createTestComponent();
      const onChange = vi.fn();

      render(<ResponsiveEditor component={component} onChange={onChange} />);

      expect(screen.getByText('반응형 오버라이드')).toBeInTheDocument();
    });

    it('프리셋 브레이크포인트 4개가 표시되어야 함', () => {
      const component = createTestComponent();
      const onChange = vi.fn();

      render(<ResponsiveEditor component={component} onChange={onChange} />);

      expect(screen.getByText('모바일')).toBeInTheDocument();
      expect(screen.getByText('태블릿')).toBeInTheDocument();
      expect(screen.getByText('데스크톱')).toBeInTheDocument();
      expect(screen.getByText('포터블')).toBeInTheDocument();
    });

    it('브레이크포인트 범위 정보가 표시되어야 함', () => {
      const component = createTestComponent();
      const onChange = vi.fn();

      render(<ResponsiveEditor component={component} onChange={onChange} />);

      expect(screen.getByText('0 ~ 767px')).toBeInTheDocument();
      expect(screen.getByText('768 ~ 1023px')).toBeInTheDocument();
      expect(screen.getByText('1024px 이상')).toBeInTheDocument();
      expect(screen.getByText('0 ~ 1023px')).toBeInTheDocument();
    });

    it('커스텀 범위 설정 버튼이 표시되어야 함', () => {
      const component = createTestComponent();
      const onChange = vi.fn();

      render(<ResponsiveEditor component={component} onChange={onChange} />);

      expect(screen.getByText('커스텀 범위 설정')).toBeInTheDocument();
    });
  });

  describe('기존 반응형 설정 표시', () => {
    it('이미 설정된 반응형 설정이 있으면 활성 배지가 표시되어야 함', () => {
      const component = createTestComponent({
        responsive: {
          mobile: {
            props: { className: 'hidden' },
          },
          tablet: {
            props: { className: 'flex' },
          },
        },
      });
      const onChange = vi.fn();

      render(<ResponsiveEditor component={component} onChange={onChange} />);

      expect(screen.getByText(/2개 브레이크포인트 활성화/)).toBeInTheDocument();
    });

    it('반응형 설정이 없으면 활성 배지가 표시되지 않아야 함', () => {
      const component = createTestComponent();
      const onChange = vi.fn();

      render(<ResponsiveEditor component={component} onChange={onChange} />);

      expect(screen.queryByText(/브레이크포인트 활성화/)).not.toBeInTheDocument();
    });

    it('기존 반응형 설정의 JSON 미리보기가 표시되어야 함', () => {
      const component = createTestComponent({
        responsive: {
          mobile: {
            props: { className: 'hidden' },
          },
        },
      });
      const onChange = vi.fn();

      render(<ResponsiveEditor component={component} onChange={onChange} />);

      expect(screen.getByText('설정 미리보기 (JSON)')).toBeInTheDocument();
    });
  });

  describe('브레이크포인트 활성화/비활성화', () => {
    it('비활성 상태에서 활성 버튼 클릭 시 onChange가 호출되어야 함', () => {
      const component = createTestComponent();
      const onChange = vi.fn();

      render(<ResponsiveEditor component={component} onChange={onChange} />);

      // 모바일 브레이크포인트의 활성 버튼 찾기
      const mobileSection = screen.getByText('모바일').closest('div[class*="cursor-pointer"]');
      const activateButton = mobileSection?.querySelector('button');

      if (activateButton) {
        fireEvent.click(activateButton);
      }

      expect(onChange).toHaveBeenCalledWith({
        responsive: {
          mobile: { props: {} },
        },
      });
    });

    it('활성 상태에서 비활성 버튼 클릭 시 해당 브레이크포인트가 제거되어야 함', () => {
      const component = createTestComponent({
        responsive: {
          mobile: { props: { className: 'hidden' } },
          tablet: { props: { className: 'flex' } },
        },
      });
      const onChange = vi.fn();

      render(<ResponsiveEditor component={component} onChange={onChange} />);

      // 모바일 브레이크포인트의 활성 버튼 찾기
      const mobileSection = screen.getByText('모바일').closest('div[class*="cursor-pointer"]');
      const activateButton = mobileSection?.querySelector('button');

      if (activateButton) {
        fireEvent.click(activateButton);
      }

      expect(onChange).toHaveBeenCalledWith({
        responsive: {
          tablet: { props: { className: 'flex' } },
        },
      });
    });
  });

  describe('브레이크포인트 펼치기/접기', () => {
    it('브레이크포인트 섹션 클릭 시 펼쳐져야 함', () => {
      const component = createTestComponent({
        responsive: {
          mobile: { props: { className: 'hidden' } },
        },
      });
      const onChange = vi.fn();

      render(<ResponsiveEditor component={component} onChange={onChange} />);

      // 모바일 섹션 클릭
      const mobileSection = screen.getByText('모바일').closest('div[class*="cursor-pointer"]');
      if (mobileSection) {
        fireEvent.click(mobileSection);
      }

      // className 입력 필드가 표시되어야 함 (placeholder는 기본 props 값)
      const classNameLabel = screen.getByText('className');
      expect(classNameLabel).toBeInTheDocument();
    });
  });

  describe('Props 오버라이드 편집', () => {
    it('className 입력 시 onChange가 호출되어야 함', () => {
      const component = createTestComponent({
        responsive: {
          mobile: { props: {} },
        },
      });
      const onChange = vi.fn();

      render(<ResponsiveEditor component={component} onChange={onChange} />);

      // 모바일 섹션 펼치기
      const mobileSection = screen.getByText('모바일').closest('div[class*="cursor-pointer"]');
      if (mobileSection) {
        fireEvent.click(mobileSection);
      }

      // className 입력 (placeholder는 기존 props 값)
      const classNameInput = screen.getByPlaceholderText('px-4 py-2');
      fireEvent.change(classNameInput, { target: { value: 'hidden' } });

      expect(onChange).toHaveBeenCalledWith({
        responsive: {
          mobile: { props: { className: 'hidden' } },
        },
      });
    });

    it('빠른 템플릿 버튼 클릭 시 className에 추가되어야 함', () => {
      const component = createTestComponent({
        responsive: {
          mobile: { props: {} },
        },
      });
      const onChange = vi.fn();

      render(<ResponsiveEditor component={component} onChange={onChange} />);

      // 모바일 섹션 펼치기
      const mobileSection = screen.getByText('모바일').closest('div[class*="cursor-pointer"]');
      if (mobileSection) {
        fireEvent.click(mobileSection);
      }

      // 숨김 템플릿 클릭
      const hiddenButton = screen.getByRole('button', { name: '숨김' });
      fireEvent.click(hiddenButton);

      expect(onChange).toHaveBeenCalledWith({
        responsive: {
          mobile: { props: { className: 'hidden' } },
        },
      });
    });
  });

  describe('모두 삭제 버튼', () => {
    it('반응형 설정이 있을 때 모두 삭제 버튼이 표시되어야 함', () => {
      const component = createTestComponent({
        responsive: {
          mobile: { props: { className: 'hidden' } },
        },
      });
      const onChange = vi.fn();

      render(<ResponsiveEditor component={component} onChange={onChange} />);

      expect(screen.getByText('모두 삭제')).toBeInTheDocument();
    });

    it('모두 삭제 클릭 시 responsive가 undefined로 설정되어야 함', () => {
      const component = createTestComponent({
        responsive: {
          mobile: { props: { className: 'hidden' } },
          tablet: { props: { className: 'flex' } },
        },
      });
      const onChange = vi.fn();

      render(<ResponsiveEditor component={component} onChange={onChange} />);

      fireEvent.click(screen.getByText('모두 삭제'));

      expect(onChange).toHaveBeenCalledWith({ responsive: undefined });
    });
  });

  describe('커스텀 범위', () => {
    it('커스텀 범위 설정 버튼 클릭 시 편집기가 표시되어야 함', () => {
      const component = createTestComponent();
      const onChange = vi.fn();

      render(<ResponsiveEditor component={component} onChange={onChange} />);

      fireEvent.click(screen.getByText('커스텀 범위 설정'));

      expect(screen.getByPlaceholderText('최소')).toBeInTheDocument();
      expect(screen.getByPlaceholderText('최대')).toBeInTheDocument();
    });

    it('커스텀 범위 추가 시 onChange가 호출되어야 함', () => {
      const component = createTestComponent();
      const onChange = vi.fn();

      render(<ResponsiveEditor component={component} onChange={onChange} />);

      // 커스텀 범위 편집기 열기
      fireEvent.click(screen.getByText('커스텀 범위 설정'));

      // 범위 입력
      fireEvent.change(screen.getByPlaceholderText('최소'), { target: { value: '0' } });
      fireEvent.change(screen.getByPlaceholderText('최대'), { target: { value: '599' } });

      // 추가 버튼 클릭
      fireEvent.click(screen.getByRole('button', { name: '추가' }));

      expect(onChange).toHaveBeenCalledWith({
        responsive: {
          '0-599': { props: {} },
        },
      });
    });

    it('최소값만 입력 시 min- 형식으로 추가되어야 함', () => {
      const component = createTestComponent();
      const onChange = vi.fn();

      render(<ResponsiveEditor component={component} onChange={onChange} />);

      fireEvent.click(screen.getByText('커스텀 범위 설정'));
      fireEvent.change(screen.getByPlaceholderText('최소'), { target: { value: '1200' } });
      fireEvent.click(screen.getByRole('button', { name: '추가' }));

      expect(onChange).toHaveBeenCalledWith({
        responsive: {
          '1200-': { props: {} },
        },
      });
    });

    it('기존 커스텀 범위가 목록에 표시되어야 함', () => {
      const component = createTestComponent({
        responsive: {
          '0-599': { props: { className: 'grid-cols-1' } },
          '600-899': { props: { className: 'grid-cols-2' } },
        },
      });
      const onChange = vi.fn();

      render(<ResponsiveEditor component={component} onChange={onChange} />);

      fireEvent.click(screen.getByText('커스텀 범위 설정'));

      expect(screen.getByText('0-599px')).toBeInTheDocument();
      expect(screen.getByText('600-899px')).toBeInTheDocument();
    });

    it('커스텀 범위 삭제 시 해당 범위가 제거되어야 함', () => {
      const component = createTestComponent({
        responsive: {
          '0-599': { props: { className: 'grid-cols-1' } },
          mobile: { props: { className: 'hidden' } },
        },
      });
      const onChange = vi.fn();

      render(<ResponsiveEditor component={component} onChange={onChange} />);

      fireEvent.click(screen.getByText('커스텀 범위 설정'));

      // 삭제 버튼 클릭
      const deleteButtons = screen.getAllByRole('button', { name: '삭제' });
      fireEvent.click(deleteButtons[0]); // 첫 번째 삭제 버튼 (커스텀 범위의 것)

      expect(onChange).toHaveBeenCalledWith({
        responsive: {
          mobile: { props: { className: 'hidden' } },
        },
      });
    });
  });

  describe('text 오버라이드', () => {
    it('컴포넌트에 text가 있으면 text 오버라이드 필드가 표시되어야 함', () => {
      const component = createTestComponent({
        text: '원본 텍스트',
        responsive: {
          mobile: { props: {} },
        },
      });
      const onChange = vi.fn();

      render(<ResponsiveEditor component={component} onChange={onChange} />);

      // 모바일 섹션 펼치기
      const mobileSection = screen.getByText('모바일').closest('div[class*="cursor-pointer"]');
      if (mobileSection) {
        fireEvent.click(mobileSection);
      }

      expect(screen.getByPlaceholderText('원본 텍스트')).toBeInTheDocument();
    });
  });

  describe('if 오버라이드', () => {
    it('if 오버라이드 필드가 표시되어야 함', () => {
      const component = createTestComponent({
        responsive: {
          mobile: { props: {} },
        },
      });
      const onChange = vi.fn();

      render(<ResponsiveEditor component={component} onChange={onChange} />);

      // 모바일 섹션 펼치기
      const mobileSection = screen.getByText('모바일').closest('div[class*="cursor-pointer"]');
      if (mobileSection) {
        fireEvent.click(mobileSection);
      }

      expect(screen.getByText('if (조건부 렌더링)')).toBeInTheDocument();
    });

    it('if 오버라이드 입력 시 onChange가 호출되어야 함', () => {
      const component = createTestComponent({
        responsive: {
          mobile: { props: {} },
        },
      });
      const onChange = vi.fn();

      render(<ResponsiveEditor component={component} onChange={onChange} />);

      // 모바일 섹션 펼치기
      const mobileSection = screen.getByText('모바일').closest('div[class*="cursor-pointer"]');
      if (mobileSection) {
        fireEvent.click(mobileSection);
      }

      // if 입력 필드 찾기
      const ifInput = screen.getByPlaceholderText('{{true}}');
      fireEvent.change(ifInput, { target: { value: '{{_global.sidebarOpen}}' } });

      expect(onChange).toHaveBeenCalledWith({
        responsive: {
          mobile: { props: {}, if: '{{_global.sidebarOpen}}' },
        },
      });
    });
  });

  describe('도움말', () => {
    it('반응형 규칙 도움말이 표시되어야 함', () => {
      const component = createTestComponent();
      const onChange = vi.fn();

      render(<ResponsiveEditor component={component} onChange={onChange} />);

      expect(screen.getByText('반응형 규칙:')).toBeInTheDocument();
      // portable 사용 시 mobile/tablet 혼용 금지 규칙이 표시되어야 함
      expect(screen.getByText(/mobile\/tablet과 혼용 금지/)).toBeInTheDocument();
    });
  });

  describe('빈 설정 시 responsive undefined 처리', () => {
    it('모든 props가 빈 값이면 브레이크포인트가 제거되어야 함', () => {
      const component = createTestComponent({
        responsive: {
          mobile: { props: { className: 'hidden' } },
        },
      });
      const onChange = vi.fn();

      render(<ResponsiveEditor component={component} onChange={onChange} />);

      // 모바일 섹션 펼치기
      const mobileSection = screen.getByText('모바일').closest('div[class*="cursor-pointer"]');
      if (mobileSection) {
        fireEvent.click(mobileSection);
      }

      // className 비우기
      const classNameInput = screen.getByDisplayValue('hidden');
      fireEvent.change(classNameInput, { target: { value: '' } });

      expect(onChange).toHaveBeenCalledWith({ responsive: undefined });
    });
  });
});
