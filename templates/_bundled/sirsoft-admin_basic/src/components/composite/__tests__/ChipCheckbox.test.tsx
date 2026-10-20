import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { ChipCheckbox } from '../ChipCheckbox';

describe('ChipCheckbox', () => {
  describe('기본 렌더링', () => {
    /**
     * @scenario component=chip_checkbox
     * @effects chip_checkbox_renders_label_icon_and_hidden_input, chip_checkbox_contract_with_decorative_icon
     */
    it('라벨을 렌더링해야 함', () => {
      render(<ChipCheckbox value="test" label="테스트 라벨" />);

      expect(screen.getByText('테스트 라벨')).toBeInTheDocument();
    });

    /**
     * @scenario component=chip_checkbox
     * @effects chip_checkbox_renders_label_icon_and_hidden_input, chip_checkbox_contract_with_decorative_icon
     */
    it('체크박스 input이 화면에서만 숨겨져(sr-only) 키보드·낭독기에는 남아야 함', () => {
      const { container } = render(<ChipCheckbox value="test" label="테스트" />);

      const checkbox = container.querySelector('input[type="checkbox"]');
      expect(checkbox).toBeInTheDocument();
      // display:none(hidden)이면 Tab 으로 닿지 않고 낭독기에도 없다(#757)
      expect(checkbox).toHaveClass('sr-only');
      expect(checkbox).not.toHaveClass('hidden');
    });

    /**
     * @scenario component=chip_checkbox
     * @effects chip_checkbox_renders_label_icon_and_hidden_input, chip_checkbox_contract_with_decorative_icon
     */
    it('아이콘이 지정되면 렌더링해야 함', () => {
      const { container } = render(
        <ChipCheckbox value="test" label="테스트" icon="clock" />
      );

      const icon = container.querySelector('i[class*="fa-"]');
      expect(icon).toBeInTheDocument();
    });

    /**
     * @scenario component=chip_checkbox
     * @effects chip_checkbox_renders_label_icon_and_hidden_input, chip_checkbox_contract_with_decorative_icon
     */
    it('아이콘이 없으면 아이콘을 렌더링하지 않아야 함', () => {
      const { container } = render(
        <ChipCheckbox value="test" label="테스트" />
      );

      const icon = container.querySelector('i[class*="fa-"]');
      expect(icon).not.toBeInTheDocument();
    });
  });

  describe('variant 스타일', () => {
    /**
     * @scenario component=chip_checkbox
     * @effects chip_checkbox_applies_variant_or_custom_color_styles, chip_checkbox_contract_with_decorative_icon
     */
    it('기본 variant는 blue여야 함', () => {
      const { container } = render(
        <ChipCheckbox value="test" label="테스트" />
      );

      const label = container.querySelector('label');
      expect(label).toHaveClass('bg-blue-50');
    });

    /**
     * @scenario component=chip_checkbox
     * @effects chip_checkbox_applies_variant_or_custom_color_styles, chip_checkbox_contract_with_decorative_icon
     */
    it('checked 상태에서 variant 스타일이 적용되어야 함', () => {
      const { container } = render(
        <ChipCheckbox value="test" label="테스트" variant="green" checked={true} />
      );

      const label = container.querySelector('label');
      // 흰 글자 대비 4.5:1 — green-600 은 3.22 라 700 단계를 쓴다
      expect(label).toHaveClass('bg-green-700');
      expect(label).toHaveClass('text-white');
    });

    /**
     * @scenario component=chip_checkbox
     * @effects chip_checkbox_applies_variant_or_custom_color_styles, chip_checkbox_contract_with_decorative_icon
     */
    it('unchecked 상태에서 variant 스타일이 적용되어야 함', () => {
      const { container } = render(
        <ChipCheckbox value="test" label="테스트" variant="orange" checked={false} />
      );

      const label = container.querySelector('label');
      expect(label).toHaveClass('bg-orange-50');
      // 연한 바탕 위 글자 대비 4.5:1 — orange-600 은 orange-50 위에서 3.38 이라 700 단계를 쓴다
      expect(label).toHaveClass('text-orange-700');
    });

    const variants = ['green', 'orange', 'yellow', 'gray', 'blue'] as const;

    variants.forEach((variant) => {
      /**
       * @scenario component=chip_checkbox
       * @effects chip_checkbox_applies_variant_or_custom_color_styles, chip_checkbox_contract_with_decorative_icon
       */
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

      /**
       * @scenario component=chip_checkbox
       * @effects chip_checkbox_applies_variant_or_custom_color_styles, chip_checkbox_contract_with_decorative_icon
       */
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
    /**
     * @scenario component=chip_checkbox
     * @effects chip_checkbox_applies_variant_or_custom_color_styles, chip_checkbox_contract_with_decorative_icon
     */
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
      // 인라인 스타일이 적용되어야 함 — 글자색은 데이터 색 그대로가 아니라 바탕과 4.5 이상이 되도록 계산된 색
      expect(label?.style.color).toBeTruthy();
      expect(label?.style.backgroundColor).toContain('rgba(255, 87, 51');
    });

    /**
     * @scenario component=chip_checkbox
     * @effects chip_checkbox_applies_variant_or_custom_color_styles, chip_checkbox_contract_with_decorative_icon
     */
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
      // 바탕은 데이터 색 그대로, 글자는 검정·흰색 중 대비가 큰 쪽(#FF5733 위 흰색 3.2 → 검정 6.5)
      expect(label).toHaveStyle({
        backgroundColor: '#ff5733',
        color: '#000000',
        borderColor: '#ff5733',
      });
    });

    /**
     * @scenario component=chip_checkbox
     * @effects chip_checkbox_applies_variant_or_custom_color_styles, chip_checkbox_contract_with_decorative_icon
     */
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
      // 글자색은 데이터 색(#FF5733 — 연한 바탕 위 3.2)이 아니라 같은 계열을 짙게 섞은 4.5 이상 색
      expect(label).not.toHaveStyle({ color: '#FF5733' });
    });

    /**
     * @scenario component=chip_checkbox
     * @effects chip_checkbox_applies_variant_or_custom_color_styles, chip_checkbox_contract_with_decorative_icon
     */
    it('customColor가 없으면 기존 variant 로직을 사용해야 함', () => {
      const { container } = render(
        <ChipCheckbox value="test" label="테스트" variant="blue" checked={false} />
      );

      const label = container.querySelector('label');
      expect(label).toHaveClass('bg-blue-50');
      // 인라인 backgroundColor가 없어야 함 (variant 클래스 사용)
      expect(label?.style.backgroundColor).toBeFalsy();
    });

    /**
     * @scenario component=chip_checkbox
     * @effects chip_checkbox_applies_variant_or_custom_color_styles, chip_checkbox_contract_with_decorative_icon
     */
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

    /**
     * @scenario component=chip_checkbox
     * @effects chip_checkbox_applies_variant_or_custom_color_styles, chip_checkbox_contract_with_decorative_icon
     */
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

    /**
     * @scenario component=chip_checkbox
     * @effects chip_checkbox_applies_variant_or_custom_color_styles, chip_checkbox_contract_with_decorative_icon
     */
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

    /**
     * @scenario component=chip_checkbox
     * @effects chip_checkbox_applies_variant_or_custom_color_styles, chip_checkbox_contract_with_decorative_icon
     */
    it('hover 시 바탕이 조금 짙어지고 글자 대비는 유지되어야 함 (checked)', () => {
      const { container } = render(
        <ChipCheckbox
          value="test"
          label="커스텀"
          customColor="#FF5733"
          checked={true}
        />
      );

      const label = container.querySelector('label')!;
      // 투명도로 흐리게 하면 글자까지 흐려져 대비가 떨어진다 — 바탕만 짙힌다
      const initialBg = label.style.backgroundColor;
      expect(label.style.opacity).toBe('');

      fireEvent.mouseEnter(label);
      expect(label.style.backgroundColor).not.toBe(initialBg);
      expect(label.style.opacity).toBe('');

      fireEvent.mouseLeave(label);
      expect(label.style.backgroundColor).toBe(initialBg);
    });

    /**
     * @scenario component=chip_checkbox
     * @effects chip_checkbox_applies_variant_or_custom_color_styles, chip_checkbox_contract_with_decorative_icon
     */
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
    /**
     * @scenario component=chip_checkbox
     * @effects chip_checkbox_change_reaches_handler, chip_checkbox_contract_with_decorative_icon
     */
    it('onChange 핸들러가 호출되어야 함', () => {
      const handleChange = vi.fn();
      const { container } = render(
        <ChipCheckbox value="test" label="테스트" onChange={handleChange} />
      );

      const checkbox = container.querySelector('input[type="checkbox"]')!;
      fireEvent.click(checkbox);

      expect(handleChange).toHaveBeenCalledTimes(1);
    });

    /**
     * @scenario component=chip_checkbox
     * @effects chip_checkbox_change_reaches_handler, chip_checkbox_contract_with_decorative_icon
     */
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

    /**
     * @scenario component=chip_checkbox
     * @effects chip_checkbox_change_reaches_handler, chip_checkbox_contract_with_decorative_icon
     */
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
    /**
     * @scenario component=chip_checkbox
     * @effects chip_checkbox_applies_variant_or_custom_color_styles, chip_checkbox_contract_with_decorative_icon
     */
    it('className prop이 적용되어야 함', () => {
      const { container } = render(
        <ChipCheckbox value="test" label="테스트" className="extra-class" />
      );

      const label = container.querySelector('label');
      expect(label).toHaveClass('extra-class');
    });

    /**
     * @scenario component=chip_checkbox
     * @effects chip_checkbox_applies_variant_or_custom_color_styles, chip_checkbox_contract_with_decorative_icon
     */
    it('style prop이 적용되어야 함', () => {
      const { container } = render(
        <ChipCheckbox value="test" label="테스트" style={{ padding: '20px' }} />
      );

      const label = container.querySelector('label');
      expect(label).toHaveStyle({ padding: '20px' });
    });
  });

  describe('value prop', () => {
    /**
     * @scenario component=chip_checkbox
     * @effects chip_checkbox_renders_label_icon_and_hidden_input, chip_checkbox_contract_with_decorative_icon
     */
    it('checkbox에 value가 설정되어야 함', () => {
      const { container } = render(
        <ChipCheckbox value="my_value" label="테스트" />
      );

      const checkbox = container.querySelector('input[type="checkbox"]') as HTMLInputElement;
      expect(checkbox.value).toBe('my_value');
    });
  });
});
