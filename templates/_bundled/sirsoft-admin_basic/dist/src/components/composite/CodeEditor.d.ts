import { default as React } from 'react';
export interface CodeEditorProps {
    value: string;
    onChange?: (event: {
        target: {
            value: string;
        };
    }) => void;
    language?: string;
    height?: string;
    readOnly?: boolean;
    theme?: 'vs-dark' | 'vs-light';
}
export declare const CodeEditor: React.FC<CodeEditorProps>;
