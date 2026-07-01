/**
 * DataSourceChipLabel.tsx — 데이터소스 멀티선택 칩 라벨
 *
 * [로딩 화면] wait_for·[검색엔진] SEO 연동 데이터의 체크박스 칩이 [데이터] 탭
 * (DataSourcesPanel)과 같은 표기 — 친화 명칭(label_key 해석) + 보조 id + 확장 출처 배지
 * (모듈/플러그인) — 를 쓰도록 한 곳에서 라벨을 그린다. 종전엔 두 칩이 raw id 만 노출해
 * 사용자가 어느 데이터인지·어디서 왔는지 알 수 없었다.
 *
 * 친화 명칭이 없으면 id 가 제목, 있으면 친화 명칭이 제목 + id 는 회색 보조. 출처 배지는
 * DataSourcesPanel 확장 배지와 동일 색(보라). 편집기 코어 컴포넌트 — `g7le-*` + 인라인 스타일만.
 *
 * @since engine-v1.50.0
 */

import React from 'react';
import type { DataSourceOption } from '../../spec/candidatePools';

export interface DataSourceChipLabelProps {
  /** 멀티선택 옵션({id, friendly, source}) */
  option: DataSourceOption;
  /** data-testid 접두(상위가 부여) */
  testIdPrefix?: string;
}

/**
 * 데이터소스 칩 라벨(친화명 + id + 출처 배지).
 *
 * @param props DataSourceChipLabelProps
 * @return 칩 라벨 엘리먼트
 */
export function DataSourceChipLabel({ option, testIdPrefix }: DataSourceChipLabelProps): React.ReactElement {
  const { id, friendly, source } = option;
  return (
    <span style={wrap}>
      <span data-testid={testIdPrefix ? `${testIdPrefix}-title` : undefined} style={title}>
        {friendly ?? id}
      </span>
      {friendly ? (
        <span data-testid={testIdPrefix ? `${testIdPrefix}-id` : undefined} style={idHint}>
          {id}
        </span>
      ) : null}
      {source ? (
        <span data-testid={testIdPrefix ? `${testIdPrefix}-source` : undefined} style={sourceBadge}>
          {source}
        </span>
      ) : null}
    </span>
  );
}

const wrap: React.CSSProperties = { display: 'inline-flex', alignItems: 'baseline', gap: 4, flexWrap: 'wrap', minWidth: 0 };
const title: React.CSSProperties = { fontSize: 12, color: '#0f172a' };
const idHint: React.CSSProperties = { fontSize: 10, color: '#94a3b8', fontFamily: 'ui-monospace, monospace' };
const sourceBadge: React.CSSProperties = {
  fontSize: 9,
  fontWeight: 600,
  color: '#7c3aed',
  background: '#f5f3ff',
  border: '1px solid #ddd6fe',
  borderRadius: 4,
  padding: '0 5px',
  whiteSpace: 'nowrap',
};
