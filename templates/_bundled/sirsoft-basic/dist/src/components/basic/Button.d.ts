import { default as React } from 'react';
/** 버튼 모양 — 적으면 `btn btn-{variant}` 의미 클래스를 붙인다 (#399, 7.2.1). accent 는 템플릿 강조색(포인트 색)을 따른다 */
export type ButtonVariant = 'primary' | 'secondary' | 'danger' | 'success' | 'ghost' | 'outline' | 'accent';
/** 버튼 크기 — `sm` → `btn-sm`, `lg` → `btn-lg`, `md`·미지정 → 붙이지 않음 */
export type ButtonSize = 'sm' | 'md' | 'lg';
/**
 * variant·size 로 붙일 의미 클래스를 계산합니다.
 *
 * variant 를 **적었을 때만** `btn btn-{variant}` 를 붙인다 — variant 를 적지 않은 기존 버튼(레이아웃 className 으로
 * 모양을 내는 버튼)은 바뀌지 않는다. 종전에는 prop 을 받기만 하고 클래스를 붙이지 않았다(#399 F12).
 *
 * @param variant 버튼 모양
 * @param size 버튼 크기
 * @returns 의미 클래스 문자열 (없으면 빈 문자열)
 */
export declare function buttonVariantClasses(variant?: ButtonVariant, size?: ButtonSize): string;
export interface ButtonProps extends React.ButtonHTMLAttributes<HTMLButtonElement> {
    /** 버튼 모양 — 적으면 `btn btn-{variant}` 를 붙인다. 생략하면 className 만 쓴다 */
    variant?: ButtonVariant;
    /** 버튼 크기 — sm → btn-sm, lg → btn-lg */
    size?: ButtonSize;
}
/**
 * 기본 버튼 컴포넌트
 */
export declare const Button: React.ForwardRefExoticComponent<ButtonProps & React.RefAttributes<HTMLButtonElement>>;
