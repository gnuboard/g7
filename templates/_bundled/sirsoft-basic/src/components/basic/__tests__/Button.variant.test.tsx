/**
 * Button(sirsoft-basic) — variant·size 의미 클래스 (7.2.1)
 *
 * @scenario-manifest templates/_bundled/sirsoft-basic/tests/scenarios/button-variant-size.yaml
 */
import { describe, it, expect, afterEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import { Button, buttonVariantClasses, type ButtonVariant } from '../Button';

const classesOf = (name: string) => screen.getByRole('button', { name }).className.split(/\s+/);

describe('Button(sirsoft-basic) variant·size', () => {
  afterEach(() => {
    delete (window as any).G7Core;
  });

  /**
   * @scenario variant=omitted,size=omitted
   * @effects button_variant_adds_semantic_classes_only_when_given
   */
  it('variant 를 적지 않으면 의미 클래스를 붙이지 않는다(종전 버튼 모양 불변)', () => {
    render(<Button className="px-4 py-2 bg-gray-900 text-white dark:bg-white dark:text-gray-900">확인</Button>);
    const classes = classesOf('확인');
    expect(classes).not.toContain('btn');
    expect(classes).toContain('cursor-pointer');
  });

  /**
   * @scenario variant=named,size=omitted
   * @effects button_variant_adds_semantic_classes_only_when_given
   */
  it.each<ButtonVariant>(['primary', 'secondary', 'danger', 'success', 'ghost', 'outline', 'accent'])('variant="%s" 는 btn btn-%s 를 붙인다', (variant) => {
    render(<Button variant={variant}>버튼</Button>);
    const classes = classesOf('버튼');
    expect(classes).toContain('btn');
    expect(classes).toContain(`btn-${variant}`);
  });

  /**
   * @scenario variant=named,size=sm
   * @effects button_size_maps_to_btn_size_classes
   */
  it('size 는 sm → btn-sm, lg → btn-lg, md 와 생략은 붙이지 않는다', () => {
    expect(buttonVariantClasses('accent', 'sm')).toBe('btn btn-accent btn-sm');
    expect(buttonVariantClasses('primary', 'lg')).toBe('btn btn-primary btn-lg');
    expect(buttonVariantClasses('outline', 'md')).toBe('btn btn-outline');
    expect(buttonVariantClasses()).toBe('');
  });

  /**
   * @scenario variant=named,size=lg
   * @effects caller_class_name_wins_over_internal_classes
   */
  it('호출처 className 이 내부 클래스 뒤에 붙는다(mergeClasses 경로)', () => {
    (window as any).G7Core = {
      style: {
        mergeClasses: (base: string, override: string) => `${base} ${override}`.trim(),
      },
    };
    render(<Button variant="outline" size="lg" className="w-full">취소</Button>);
    const classes = classesOf('취소');
    expect(classes.indexOf('btn-outline')).toBeLessThan(classes.indexOf('w-full'));
    expect(classes).toContain('btn-lg');
  });

  /**
   * @scenario variant=omitted,size=sm
   * @effects button_size_maps_to_btn_size_classes, button_variant_adds_semantic_classes_only_when_given
   */
  it('variant 없이 size="sm" 만 적으면 btn-sm 만 붙고 모양 클래스는 붙지 않는다', () => {
    render(<Button size="sm">작게</Button>);
    const classes = classesOf('작게');
    expect(classes).toContain('btn-sm');
    expect(classes).not.toContain('btn');
  });

  /**
   * @scenario variant=omitted,size=lg
   * @effects button_size_maps_to_btn_size_classes, button_variant_adds_semantic_classes_only_when_given
   */
  it('variant 없이 size="lg" 만 적으면 btn-lg 만 붙는다', () => {
    render(<Button size="lg">크게</Button>);
    const classes = classesOf('크게');
    expect(classes).toContain('btn-lg');
    expect(classes).not.toContain('btn');
  });
});
