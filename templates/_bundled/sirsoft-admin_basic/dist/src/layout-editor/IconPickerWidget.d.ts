import { default as React } from 'react';
interface IconPickerWidgetProps {
    control: {
        icons?: unknown;
        iconColumns?: number;
        iconSearchPlaceholder?: string;
    } & Record<string, unknown>;
    value: unknown;
    onChange: (value: unknown) => void;
    t: (key: string, params?: Record<string, string | number>) => string;
}
export declare function IconPickerWidget({ control, value, onChange, t, }: IconPickerWidgetProps): React.ReactElement;
export {};
