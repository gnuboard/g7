import { default as React } from 'react';
/** 버튼 모양 — 적으면 `btn btn-{variant}` 의미 클래스를 붙인다 (7.2.1) */
export type ButtonVariant = 'primary' | 'secondary' | 'danger' | 'success' | 'ghost' | 'outline';
/** 버튼 크기 — `sm` → `btn-sm`, `lg` → `btn-lg`, `md`·미지정 → 붙이지 않음 */
export type ButtonSize = 'sm' | 'md' | 'lg';
/**
 * variant·size 로 붙일 의미 클래스를 계산합니다.
 *
 * variant 를 **적었을 때만** `btn btn-{variant}` 를 붙인다 — variant 를 적지 않은 기존 버튼(레이아웃 className 으로
 * 모양을 내는 버튼)은 바뀌지 않는다. 종전에는 prop 을 받기만 하고 클래스를 붙이지 않아 계약대로 variant 를 써도
 * 민자 버튼이 나왔다.
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
    /**
     * 연타 잠금 — 첫 클릭 즉시 스스로 잠가, 처리 중 표시(`disabled`)가 화면에 그려지기 전의 두 번째 클릭을 무시한다.
     *
     * `disabled="{{…submitting}}"` 는 첫 클릭의 상태 변경이 그려진 뒤에야 걸려 더블클릭이 요청을 한 번 더 보낸다.
     * 화면이 `disabled` 를 그리면 잠금을 그 판정에 넘기고, 끝내 오지 않으면 잠시 뒤 풀린다.
     * @default false
     */
    lockRepeatClick?: boolean;
}
/**
 * 기본 버튼 컴포넌트
 */
export declare const Button: React.ForwardRefExoticComponent<ButtonProps & React.RefAttributes<HTMLButtonElement>>;
