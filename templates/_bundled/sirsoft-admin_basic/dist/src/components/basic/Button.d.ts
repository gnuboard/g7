import { default as React } from 'react';
export interface ButtonProps extends React.ButtonHTMLAttributes<HTMLButtonElement> {
    variant?: 'primary' | 'secondary' | 'danger' | 'success';
    size?: 'sm' | 'md' | 'lg';
    /**
     * 연타 잠금 — 첫 클릭 즉시 스스로 잠가, 처리 중 표시(`disabled`)가 화면에 그려지기 전의 두 번째 클릭을 무시한다.
     *
     * `disabled="{{…submitting}}"` 는 첫 클릭의 상태 변경이 그려진 뒤에야 걸려 더블클릭이 요청을 한 번 더 보낸다(#736 S2).
     * 화면이 `disabled` 를 그리면 잠금을 그 판정에 넘기고, 끝내 오지 않으면 잠시 뒤 풀린다.
     * @default false
     */
    lockRepeatClick?: boolean;
}
/**
 * 기본 버튼 컴포넌트
 */
export declare const Button: React.ForwardRefExoticComponent<ButtonProps & React.RefAttributes<HTMLButtonElement>>;
