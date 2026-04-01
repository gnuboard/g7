/**
 * 진단 탭 컴포넌트
 *
 * 증상을 선택하여 자동 진단을 실행합니다.
 */

import React, { useState } from 'react';

/**
 * 진단 탭
 */
export const DiagnoseTab: React.FC = () => {
    const [symptoms, setSymptoms] = useState<string[]>([]);
    const [results, setResults] = useState<any[]>([]);
    const [customSymptom, setCustomSymptom] = useState('');

    const commonSymptoms = [
        '이전 값이 전송됨',
        '모든 행이 같은 값',
        '상태가 업데이트 안됨',
        'API 호출이 안됨',
        '초기값이 없음',
        '무한 로딩',
        'Form 값이 저장 안됨',
        'iteration 데이터 중복',
    ];

    const toggleSymptom = (symptom: string) => {
        setSymptoms(prev =>
            prev.includes(symptom)
                ? prev.filter(s => s !== symptom)
                : [...prev, symptom]
        );
    };

    const addCustomSymptom = () => {
        if (customSymptom && !symptoms.includes(customSymptom)) {
            setSymptoms(prev => [...prev, customSymptom]);
            setCustomSymptom('');
        }
    };

    const runDiagnosis = async () => {
        const { DiagnosticEngine } = await import('../../DiagnosticEngine');
        const engine = new DiagnosticEngine();
        const diagnoses = engine.analyze(symptoms);
        setResults(diagnoses);
    };

    return (
        <div className="g7dt-space-y-4">
            {/* 증상 선택 */}
            <div>
                <h3 className="g7dt-text-sm g7dt-font-bold g7dt-mb-2">증상 선택</h3>
                <div className="g7dt-flex g7dt-flex-wrap g7dt-gap-2">
                    {commonSymptoms.map(symptom => (
                        <button
                            key={symptom}
                            onClick={() => toggleSymptom(symptom)}
                            className={symptoms.includes(symptom) ? 'g7dt-symptom-btn g7dt-symptom-active' : 'g7dt-symptom-btn'}
                        >
                            {symptom}
                        </button>
                    ))}
                </div>
            </div>

            {/* 커스텀 증상 입력 */}
            <div className="g7dt-flex g7dt-gap-2">
                <input
                    type="text"
                    placeholder="직접 증상 입력..."
                    value={customSymptom}
                    onChange={e => setCustomSymptom(e.target.value)}
                    onKeyDown={e => e.key === 'Enter' && addCustomSymptom()}
                    className="g7dt-input g7dt-flex-1"
                />
                <button
                    onClick={addCustomSymptom}
                    className="g7dt-btn-secondary"
                >
                    추가
                </button>
            </div>

            {/* 진단 버튼 */}
            <button
                onClick={runDiagnosis}
                disabled={symptoms.length === 0}
                className={symptoms.length === 0 ? 'g7dt-btn-primary g7dt-btn-disabled g7dt-w-full' : 'g7dt-btn-primary g7dt-w-full'}
            >
                진단 실행 ({symptoms.length}개 증상)
            </button>

            {/* 진단 결과 */}
            {results.length > 0 && (
                <div className="g7dt-space-y-3">
                    <h3 className="g7dt-text-sm g7dt-font-bold">진단 결과</h3>
                    {results.slice(0, 5).map((result, i) => (
                        <div key={i} className="g7dt-card">
                            <div className="g7dt-flex g7dt-justify-between g7dt-items-center g7dt-mb-2">
                                <span className="g7dt-font-bold g7dt-text-yellow">{result.rule.name}</span>
                                <span className="g7dt-confidence-badge">
                                    {(result.confidence * 100).toFixed(0)}%
                                </span>
                            </div>
                            <div className="g7dt-text-sm g7dt-text-lightgray">{result.rule.solution}</div>
                            {result.rule.codeExample && (
                                <pre className="g7dt-pre g7dt-mt-2">
                                    {result.rule.codeExample}
                                </pre>
                            )}
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
};

export default DiagnoseTab;
