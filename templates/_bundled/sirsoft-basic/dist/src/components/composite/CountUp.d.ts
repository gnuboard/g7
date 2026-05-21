import { default as React } from 'react';
export interface CountUpProps {
    target: number;
    durationMs?: number;
    format?: 'ko-KR' | 'en-US' | 'plain';
    className?: string;
    suffix?: string;
    prefix?: string;
    rootMargin?: string;
    threshold?: number;
}
declare const CountUp: React.FC<CountUpProps>;
export default CountUp;
