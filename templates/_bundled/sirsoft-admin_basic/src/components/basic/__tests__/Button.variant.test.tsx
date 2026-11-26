/**
 * Button(sirsoft-admin_basic) — variant·size 의미 클래스 (7.2.1)
 *
 * @scenario-manifest templates/_bundled/sirsoft-admin_basic/tests/scenarios/button-variant-size.yaml
 */
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import { Button, buttonVariantClasses, type ButtonVariant } from '../Button';

const classesOf = (name: string) => screen.getByRole('button', { name }).className.split(/\s+/);

describe('Button(sirsoft-admin_basic) variant·size', () => {
  beforeEach(() => {
    // 실제 런타임과 같이 G7Core.style.mergeClasses 를 쓰는 경로도 본다 — 없으면 단순 이어붙이기
    delete (window as any).G7Core;
  });
  afterEach(() => {
    delete (window as any).G7Core;
  });

  /**
   * @scenario variant=omitted,size=omitted
   * @effects button_variant_adds_semantic_classes_only_when_given
   */
  it('variant 를 적지 않으면 의미 클래스를 붙이지 않는다(종전 버튼 모양 불변)', () => {
    render(<Button className="px-4 py-2 bg-blue-600 text-white dark:bg-blue-500 dark:text-white">저장</Button>);
    const classes = classesOf('저장');
    expect(classes).not.toContain('btn');
    expect(classes).not.toContain('btn-primary');
    expect(classes).toContain('button');
  });

  /**
   * @scenario variant=named,size=omitted
   * @effects button_variant_adds_semantic_classes_only_when_given
   */
  it.each<ButtonVariant>(['primary', 'secondary', 'danger', 'success', 'ghost', 'outline'])('variant="%s" 는 btn btn-%s 를 붙인다', (variant) => {
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
    expect(buttonVariantClasses('primary', 'sm')).toBe('btn btn-primary btn-sm');
    expect(buttonVariantClasses('primary', 'lg')).toBe('btn btn-primary btn-lg');
    expect(buttonVariantClasses('primary', 'md')).toBe('btn btn-primary');
    expect(buttonVariantClasses(undefined, 'sm')).toBe('btn-sm');
    expect(buttonVariantClasses()).toBe('');
  });

  /**
   * @scenario variant=named,size=lg
   * @effects caller_class_name_wins_over_internal_classes
   */
  it('호출처 className 이 내부 클래스 뒤에 붙어 이긴다(mergeClasses 경로)', () => {
    (window as any).G7Core = {
      style: {
        mergeClasses: (base: string, override: string) => `${base} ${override}`.trim(),
      },
    };
    render(<Button variant="secondary" size="lg" className="w-full">취소</Button>);
    const classes = classesOf('취소');
    expect(classes.indexOf('btn-secondary')).toBeLessThan(classes.indexOf('w-full'));
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
