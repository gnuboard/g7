import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { ChipCheckbox } from '../ChipCheckbox';

describe('ChipCheckbox', () => {
  describe('기본 렌더링', () => {
    it('라벨을 렌더링해야 함', () => {
      render(<ChipCheckbox value="test" label="테스트 라벨" />);

      expect(screen.getByText('테스트 라벨')).toBeInTheDocument();
    });

    it('체크박스 input이 hidden으로 렌더링되어야 함', () => {
      const { container } = render(<ChipCheckbox value="test" label="테스트" />);

      const checkbox = container.querySelector('input[type="checkbox"]');
      expect(checkbox).toBeInTheDocument();
      expect(checkbox).toHaveClass('hidden');
    });

    it('아이콘이 지정되면 렌더링해야 함', () => {
      const { container } = render(
        <ChipCheckbox value="test" label="테스트" icon="clock" />
      );

      const icon = container.querySelector('i[role="img"]');
      expect(icon).toBeInTheDocument();
    });

    it('아이콘이 없으면 아이콘을 렌더링하지 않아야 함', () => {
      const { container } = render(
        <ChipCheckbox value="test" label="테스트" />
      );

      const icon = container.querySelector('i[role="img"]');
      expect(icon).not.toBeInTheDocument();
    });
  });

  describe('variant 스타일', () => {
    it('기본 variant는 blue여야 함', () => {
      const { container } = render(
        <ChipCheckbox value="test" label="테스트" />
      );

      const label = container.querySelector('label');
      expect(label).toHaveClass('bg-blue-50');
    });

    it('checked 상태에서 variant 스타일이 적용되어야 함', () => {
      const { container } = render(
        <ChipCheckbox value="test" label="테스트" variant="green" checked={true} />
      );

      const label = container.querySelector('label');
      expect(label).toHaveClass('bg-green-600');
      expect(label).toHaveClass('text-white');
    });

    it('unchecked 상태에서 variant 스타일이 적용되어야 함', () => {
      const { container } = render(
        <ChipCheckbox value="test" label="테스트" variant="orange" checked={false} />
      );

      const label = container.querySelector('label');
      expect(label).toHaveClass('bg-orange-50');
      expect(label).toHaveClass('text-orange-600');
    });

    const variants = ['green', 'orange', 'yellow', 'gray', 'blue'] as const;

    variants.forEach((variant) => {
      it(`variant="${variant}" unchecked 스타일이 적용되어야 함`, () => {
        const { container } = render(
          <ChipCheckbox value="test" label="테스트" variant={variant} checked={false} />
        );

        const label = container.querySelector('label');
        expect(label).toBeTruthy();
        // 모든 variant에 기본 스타일이 적용되어야 함
        expect(label).toHaveClass('inline-flex');
        expect(label).toHaveClass('rounded-lg');
      });

      it(`variant="${variant}" checked 스타일이 적용되어야 함`, () => {
        const { container } = render(
          <ChipCheckbox value="test" label="테스트" variant={variant} checked={true} />
        );

        const label = container.querySelector('label');
        expect(label).toHaveClass('text-white');
      });
    });
  });

  describe('customColor prop', () => {
    it('customColor가 있으면 variant를 무시하고 인라인 스타일을 적용해야 함', () => {
      const { container } = render(
        <ChipCheckbox
          value="test"
          label="커스텀"
          variant="green"
          customColor="#FF5733"
          checked={false}
        />
      );

      const label = container.querySelector('label');
      // variant 클래스가 적용되지 않아야 함
      expect(label).not.toHaveClass('bg-green-50');
      // 인라인 스타일이 적용되어야 함
      expect(label).toHaveStyle({ color: '#FF5733' });
    });

    it('customColor checked 상태에서 배경색이 커스텀 색상이어야 함', () => {
      const { container } = render(
        <ChipCheckbox
          value="test"
          label="커스텀"
          customColor="#FF5733"
          checked={true}
        />
      );

      const label = container.querySelector('label');
      expect(label).toHaveStyle({
        backgroundColor: '#FF5733',
        color: '#ffffff',
        borderColor: '#FF5733',
      });
    });

    it('customColor unchecked 상태에서 rgba 배경이 적용되어야 함', () => {
      const { container } = render(
        <ChipCheckbox
          value="test"
          label="커스텀"
          customColor="#FF5733"
          checked={false}
        />
      );

      const label = container.querySelector('label');
      // #FF5733 = rgb(255, 87, 51)
      // 라이트 모드 unchecked: rgba(255, 87, 51, 0.06)
      expect(label?.style.backgroundColor).toContain('rgba(255, 87, 51');
      expect(label).toHaveStyle({ color: '#FF5733' });
    });

    it('customColor가 없으면 기존 variant 로직을 사용해야 함', () => {
      const { container } = render(
        <ChipCheckbox value="test" label="테스트" variant="blue" checked={false} />
      );

      const label = container.querySelector('label');
      expect(label).toHaveClass('bg-blue-50');
      // 인라인 backgroundColor가 없어야 함 (variant 클래스 사용)
      expect(label?.style.backgroundColor).toBeFalsy();
    });

    it('customColor + style prop이 함께 작동해야 함', () => {
      const { container } = render(
        <ChipCheckbox
          value="test"
          label="커스텀"
          customColor="#3B82F6"
          style={{ marginLeft: '8px' }}
        />
      );

      const label = container.querySelector('label');
      // style prop이 병합되어야 함
      expect(label).toHaveStyle({ marginLeft: '8px' });
    });

    it('customColor + className이 함께 작동해야 함', () => {
      const { container } = render(
        <ChipCheckbox
          value="test"
          label="커스텀"
          customColor="#3B82F6"
          className="my-custom-class"
        />
      );

      const label = container.querySelector('label');
      expect(label).toHaveClass('my-custom-class');
      expect(label).toHaveClass('inline-flex');
    });

    it('잘못된 hex 색상이면 빈 스타일을 적용해야 함', () => {
      const { container } = render(
        <ChipCheckbox
          value="test"
          label="잘못된 색상"
          customColor="invalid"
          checked={false}
        />
      );

      const label = container.querySelector('label');
      // hexToRgb가 null을 반환하므로 빈 스타일
      expect(label).toBeTruthy();
    });

    it('hover 시 스타일이 변경되어야 함 (checked)', () => {
      const { container } = render(
        <ChipCheckbox
          value="test"
          label="커스텀"
          customColor="#FF5733"
          checked={true}
        />
      );

      const label = container.querySelector('label')!;
      // 초기 상태: opacity 1
      expect(label).toHaveStyle({ opacity: '1' });

      // hover
      fireEvent.mouseEnter(label);
      expect(label).toHaveStyle({ opacity: '0.85' });

      // unhover
      fireEvent.mouseLeave(label);
      expect(label).toHaveStyle({ opacity: '1' });
    });

    it('hover 시 unchecked 배경 투명도가 증가해야 함', () => {
      const { container } = render(
        <ChipCheckbox
          value="test"
          label="커스텀"
          customColor="#FF5733"
          checked={false}
        />
      );

      const label = container.querySelector('label')!;
      const initialBg = label.style.backgroundColor;

      // hover
      fireEvent.mouseEnter(label);
      const hoveredBg = label.style.backgroundColor;

      // hover 시 배경 투명도가 달라져야 함
      expect(hoveredBg).not.toBe(initialBg);
    });
  });

  describe('onChange 이벤트', () => {
    it('onChange 핸들러가 호출되어야 함', () => {
      const handleChange = vi.fn();
      const { container } = render(
        <ChipCheckbox value="test" label="테스트" onChange={handleChange} />
      );

      const checkbox = container.querySelector('input[type="checkbox"]')!;
      fireEvent.click(checkbox);

      expect(handleChange).toHaveBeenCalledTimes(1);
    });

    it('customColor 모드에서도 onChange가 호출되어야 함', () => {
      const handleChange = vi.fn();
      const { container } = render(
        <ChipCheckbox
          value="test"
          label="커스텀"
          customColor="#FF5733"
          onChange={handleChange}
        />
      );

      const checkbox = container.querySelector('input[type="checkbox"]')!;
      fireEvent.click(checkbox);

      expect(handleChange).toHaveBeenCalledTimes(1);
    });

    it('onChange 없이도 오류 없이 렌더링되어야 함', () => {
      const { container } = render(
        <ChipCheckbox value="test" label="테스트" />
      );

      const checkbox = container.querySelector('input[type="checkbox"]')!;
      // onChange 없이 클릭해도 에러 없어야 함
      expect(() => fireEvent.click(checkbox)).not.toThrow();
    });
  });

  describe('스타일 커스터마이징', () => {
    it('className prop이 적용되어야 함', () => {
      const { container } = render(
        <ChipCheckbox value="test" label="테스트" className="extra-class" />
      );

      const label = container.querySelector('label');
      expect(label).toHaveClass('extra-class');
    });

    it('style prop이 적용되어야 함', () => {
      const { container } = render(
        <ChipCheckbox value="test" label="테스트" style={{ padding: '20px' }} />
      );

      const label = container.querySelector('label');
      expect(label).toHaveStyle({ padding: '20px' });
    });
  });

  describe('value prop', () => {
    it('checkbox에 value가 설정되어야 함', () => {
      const { container } = render(
        <ChipCheckbox value="my_value" label="테스트" />
      );

      const checkbox = container.querySelector('input[type="checkbox"]') as HTMLInputElement;
      expect(checkbox.value).toBe('my_value');
    });
  });
});
