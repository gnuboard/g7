// e2e:allow 레이아웃 편집기 속성패널 다중 배열 항목 에디터 UI — Chrome MCP 매트릭스(T1~T7) 실측 + 단위/레이아웃 렌더링 테스트로 검증
/**
 * ArrayGroupEditor.tsx — `array-group` 노드 에디터
 *
 * 한 컴포넌트가 **여러 배열 prop** 을 동시에 정적 편집해야 할 때(예 BarChart 의
 * `labels` + `datasets`)를 위한 얇은 합성 래퍼다. capability 의 단일 `nodeEditor`
 * 슬롯 제약 아래에서 ArrayItemsEditor 를 그룹마다 1벌씩 재사용해 렌더한다 —
 * **새 편집 의미를 도입하지 않는다**(부록4-ter "신규 에디터 종류 남발 금지").
 *
 * params 스키마(이 kind 소유의 불투명 객체):
 *  - `groups`: 각 항목이 ArrayItemsEditor 의 params(`arrayProp`/`itemLabel`/`fields`/
 *    `newItem`)에 더해 선택적 `title`(그룹 소제목 `$t:` 키 또는 평문)을 가진다.
 *
 * 각 그룹은 동일한 `node`/`onPatchNode` 를 받으므로 한 그룹의 패치가 다른 그룹의
 * 입력값을 덮어쓰지 않는다(ArrayItemsEditor 는 `node.props[arrayProp]` 만 교체하고
 * 나머지 props 는 spread 로 보존 — commit 참조). 정적-바인딩 가드/항목 텍스트 다국어/
 * defaultNode 합성은 모두 ArrayItemsEditor 가 그룹별로 처리한다(SSoT 1벌).
 *
 * 편집기 코어 컴포넌트 — `g7le-*` BEM + 인라인 스타일만, CSS 라이브러리 토큰 비종속.
 *
 * @since engine-v1.50.0
 */

import React from 'react';
import type { NodeEditorProps } from '../../spec/nodeEditorRegistry';
import { ArrayItemsEditor } from './ArrayItemsEditor';

/** 그룹 1건 — ArrayItemsEditor params + 선택적 소제목. */
interface ArrayGroupSpec {
  /** 편집 대상 prop 키(ArrayItemsEditor params.arrayProp) */
  arrayProp?: string;
  /** 그룹 소제목 — `$t:` 키 또는 평문(미지정 시 소제목 없이 에디터만) */
  title?: string;
  /** 나머지 ArrayItemsEditor params(itemLabel/fields/newItem) — 그대로 전달 */
  [k: string]: unknown;
}

/** 친화 라벨 해석 — `$t:` 키면 t(), 아니면 평문. */
function resolveLabel(
  label: string | undefined,
  t: (k: string, p?: Record<string, string | number>) => string,
): string | null {
  if (!label) return null;
  return label.startsWith('$t:') ? t(label.slice(3)) : label;
}

export function ArrayGroupEditor(props: NodeEditorProps): React.ReactElement {
  const { params, t } = props;
  const groups: ArrayGroupSpec[] = Array.isArray(params?.groups)
    ? (params!.groups as ArrayGroupSpec[])
    : [];

  // groups 미선언(잘못된 capability) — 안전 안내.
  if (groups.length === 0) {
    return (
      <div
        className="g7le-node-editor g7le-node-editor--array-group"
        data-testid="g7le-array-group-misconfigured"
        style={emptyHint}
      >
        {t('layout_editor.array_editor.no_array_prop')}
      </div>
    );
  }

  return (
    <div
      className="g7le-node-editor g7le-node-editor--array-group"
      data-testid="g7le-array-group-editor"
      style={wrap}
    >
      {groups.map((group, gi) => {
        const title = resolveLabel(group.title, t);
        // 그룹 params 에서 title 을 떼고 나머지를 ArrayItemsEditor 로 전달(title 은 본 래퍼 소유).
        const { title: _omit, ...itemParams } = group;
        void _omit;
        return (
          <div key={group.arrayProp ?? gi} data-testid={`g7le-array-group-${group.arrayProp ?? gi}`} style={groupBox}>
            {title && <div style={groupTitle}>{title}</div>}
            <ArrayItemsEditor {...props} params={itemParams as Record<string, unknown>} />
          </div>
        );
      })}
    </div>
  );
}

const wrap: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 16, width: '100%', marginBottom: 12 };
const groupBox: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 4 };
const groupTitle: React.CSSProperties = { fontSize: 12, fontWeight: 700, color: '#1e293b', borderBottom: '1px solid #e2e8f0', paddingBottom: 4 };
const emptyHint: React.CSSProperties = { fontSize: 11, color: '#94a3b8', fontStyle: 'italic' };
