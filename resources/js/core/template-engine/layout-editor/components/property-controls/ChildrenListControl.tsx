// e2e:allow children 항목 노드 에디터 — `+데이터` 칩 삽입(키화) 후보 풀 결선. 칩 입력기·합성 클릭 의존으로 Playwright 부적합, 단위(ChildrenListControl.test)+Chrome MCP 라이브 매트릭스로 검증 (계획 정책)
/**
 * ChildrenListControl.tsx — `children` 노드 에디터
 *
 * 컴포넌트의 **자식 노드 트리**(`node.children`)를 항목 단위로 구조 편집한다 —
 * `props` 데이터 배열(OptionsListControl/ArrayItemsEditor)과 달리 실제 노드 자식을
 * 추가/삭제/정렬한다. capability `nodeEditor: { kind: "children", params }` 로 선언된
 * 컴포넌트(Ul/Ol/Nav/Form/Li)가 대상이며, 코어 빌트인은 `registerCoreEditors` 가
 * `registerNodeEditor('children', ChildrenListControl)` 로 일반 레지스트리에 올린다
 * (특권 분기 0 — 템플릿 등록분과 동일 경로).
 *
 * params 스키마(이 kind 소유의 불투명 객체 — 코어는 메커니즘만, 정책은 스펙 선언):
 *  - `childComponent`: 추가할 자식 컴포넌트명(예 Li). 팔레트 defaultNode/manifest 폴백.
 *  - `childTemplate`(선택): "추가" 가 만들 골격 노드 JSON. 선언 시 childComponent 의
 *    defaultNode 보다 우선 — 폼처럼 "라벨+입력칸 묶음" 행 단위 추가를 스펙이 선언한다
 *
 *  - `childLabel`(선택): 추가 버튼 친화 명칭(`$t:` 토큰 가능). 미선언 시 childComponent.
 *  - `itemFields`(선택): 각 항목 행의 편집 필드 선언 배열. 미선언 시 `[{kind:"text"}]`
 *    (종전 동작 — 텍스트 자손 편집만). 항목별 필드:
 *      - `{ kind: "text", label? }` — 항목의 의미 텍스트 자손(findTextNodePath).
 *      - `{ kind: "prop", prop, label? }` — 스펙이 지정한 prop(예 placeholder)을 가진
 *        첫 자손(findPropNodePath). 코어는 prop 이름을 모른다 — 스펙이 정책의 SSoT.
 *
 * - 항목 추가: childTemplate(우선) 또는 childComponent defaultNode 골격을 children 끝에
 *   append. 골격의 텍스트 노드가 빈 문자열(`""`)을 **선언**한 경우에만 기본 안내 텍스트
 *   ("새 항목")를 시드 — text 미선언 자식(Input 등 void element)에 시드하면 렌더러가
 *  `<input>새 항목</input>` 을 만들어 React error #137 로 크래시한다.
 * - 항목 삭제/정렬: children 배열에서 제거/스왑 → `onPatchNode({ ...node, children })`.
 *   `onPatchNode` 는 EditorCanvasOverlay 가 path 의 노드를 patched 로 통째 교체하므로
 *   children 패치가 PATCH_LAYOUT 으로 살아남는다(캔버스 즉시 반영 + history).
 * - 항목 필드 다국어: 각 필드 값이 평문이면 blur 시 커스텀 키(`$t:custom.*`)를 생성해
 *   그 위치(text 또는 prop)를 키로 치환(인라인 편집과 동일 모델 — I18nTextField SSoT).
 *   이미 커스텀 키면 현재 로케일 값만 갱신. 편집 가능 필드가 전무한 구조 자식은
 *   컴포넌트명 라벨만 표시하고 정렬/삭제만 제공.
 *
 * 캔버스 드래그앤드롭(자식 노드 직접 드래그)은 본 컨트롤과 **동일 children 배열**을
 * 패치하므로(EditorCanvasOverlay 의 moveNode 경로) 양립한다 — SSoT 1벌.
 *
 * 편집기 코어 컴포넌트 — `g7le-*` BEM + 인라인 스타일만, CSS 라이브러리 토큰 비종속.
 *
 * @since engine-v1.50.0
 */

import React, { useCallback } from 'react';
import type { NodeEditorProps } from '../../spec/nodeEditorRegistry';
import type { EditorNode } from '../../utils/layoutTreeUtils';
import { buildDefaultNode, type ComponentManifestEntry } from '../ComponentPalette';
import {
  findTextNodePath,
  nodeAtTextPath,
  patchTextAtPath,
  findPropNodePath,
  patchPropAtPath,
} from '../../utils/nodeTextPath';
import { I18nTextField } from './I18nTextField';

/** 자식 노드 배열을 안전 추출. */
function childArray(node: EditorNode): EditorNode[] {
  return Array.isArray(node.children) ? (node.children as EditorNode[]) : [];
}

// 항목 텍스트/prop 노드 탐색·패치는 공용 `utils/nodeTextPath` 와 SSoT 공유 — TableEditor
// 부록7 7-a 공통 모듈과 동일 경로 추상.
//
// 항목 필드 편집은 `I18nTextField` 위젯이 담당한다. 평문/`$t:custom.*`
// 키/`{{...}}` 바인딩 분류·키 생성·ko/en/ja 펼침 폼은 모두 7-a 공통 모듈(useCustomTranslation)
// SSoT 로 일원화 — 본 컨트롤은 필드 위치(text/prop 경로)에 토큰 문자열을 기록만 한다.

/** 항목 편집 필드 선언 — capability `nodeEditor.params.itemFields` 항목. */
interface ItemFieldSpec {
  kind: 'text' | 'prop';
  /** kind:"prop" 일 때 대상 prop 키(스펙 선언 — 코어는 이름을 모름) */
  prop?: string;
  /** 필드 캡션(`$t:` 토큰 가능). 미선언 시 text=항목 텍스트 기본 캡션 / prop=prop 키 */
  label?: string;
}

/** params.itemFields 안전 파싱 — 미선언/비배열이면 종전 동작(text 1필드). */
function parseItemFields(params: NodeEditorProps['params']): ItemFieldSpec[] {
  const raw = (params as Record<string, unknown> | null | undefined)?.itemFields;
  if (!Array.isArray(raw)) return [{ kind: 'text' }];
  const fields: ItemFieldSpec[] = [];
  for (const entry of raw) {
    if (!entry || typeof entry !== 'object') continue;
    const e = entry as Record<string, unknown>;
    if (e.kind === 'text') {
      fields.push({ kind: 'text', label: typeof e.label === 'string' ? e.label : undefined });
    } else if (e.kind === 'prop' && typeof e.prop === 'string' && e.prop.length > 0) {
      fields.push({
        kind: 'prop',
        prop: e.prop,
        label: typeof e.label === 'string' ? e.label : undefined,
      });
    }
  }
  return fields.length > 0 ? fields : [{ kind: 'text' }];
}

/** `$t:` 토큰이면 t() 해석, 아니면 평문 그대로. */
function resolveSpecLabel(
  label: string | undefined,
  t: NodeEditorProps['t'],
): string | undefined {
  if (typeof label !== 'string' || label.length === 0) return undefined;
  return label.startsWith('$t:') ? t(label.slice(3)) : label;
}

export function ChildrenListControl({
  node,
  params,
  spec,
  manifest,
  t,
  onPatchNode,
  templateIdentifier,
  candidates,
}: NodeEditorProps): React.ReactElement {
  // templateIdentifier 는 NodeEditorProps 시그니처 호환을 위해 유지(미사용 — 항목 필드
  // 편집은 I18nTextField 가 useCustomTranslation 으로 로케일/식별자/레이아웃명을 자체 해석).
  void templateIdentifier;

  const childComponent =
    typeof params?.childComponent === 'string' ? (params.childComponent as string) : null;
  // childTemplate — 스펙이 선언한 추가 골격(라벨+입력칸 묶음 등). childComponent 보다 우선.
  const childTemplate =
    params?.childTemplate && typeof params.childTemplate === 'object'
      ? (params.childTemplate as EditorNode)
      : null;
  const itemFields = parseItemFields(params);
  const addLabel =
    resolveSpecLabel(
      typeof params?.childLabel === 'string' ? (params.childLabel as string) : undefined,
      t,
    ) ?? childComponent ?? '';

  const items = childArray(node);

  /** children 배열을 통째 교체해 노드 패치(캔버스 PATCH_LAYOUT 반영). */
  const commitChildren = useCallback(
    (next: EditorNode[]): void => {
      onPatchNode({ ...node, children: next });
    },
    [node, onPatchNode],
  );

  /** 새 자식 골격 — params.childTemplate(스펙 선언) > 팔레트 defaultNode > manifest 폴백. */
  const buildChildNode = useCallback((): EditorNode | null => {
    if (childTemplate) {
      return JSON.parse(JSON.stringify(childTemplate)) as EditorNode;
    }
    if (!childComponent) return null;
    const entrySpec = spec?.componentPalette?.entries?.[childComponent];
    if (entrySpec?.defaultNode && typeof entrySpec.defaultNode === 'object') {
      return JSON.parse(JSON.stringify(entrySpec.defaultNode)) as EditorNode;
    }
    // manifest props.default 기반 코어 폴백.
    const groups = manifest?.components ?? {};
    let entry: ComponentManifestEntry | null = null;
    for (const list of Object.values(groups)) {
      if (Array.isArray(list)) {
        const found = (list as ComponentManifestEntry[]).find((e) => e.name === childComponent);
        if (found) {
          entry = found;
          break;
        }
      }
    }
    if (!entry) {
      // 매니페스트에 없으면 최소 골격(type/name) — 추가는 가능하게.
      return { type: 'basic', name: childComponent };
    }
    return buildDefaultNode(entry);
  }, [childTemplate, childComponent, spec, manifest]);

  const addItem = useCallback((): void => {
    const child = buildChildNode();
    if (!child) return;
    // 기본 안내 텍스트 시드는 골격이 `text` 를 **빈 문자열로 선언한** 텍스트 노드에만 —
    // 직접 text 또는 의미 텍스트 자손(childTemplate 의 라벨 Span 등) 모두 지원.
    // text 미선언 자식(Input 등 void element)에 시드하면 React error #137 크래시.
    if (child.text === '') {
      child.text = t('layout_editor.list_editor.new_item');
    } else {
      const seedPath = findTextNodePath(child);
      if (seedPath !== null && seedPath.length > 0) {
        const target = nodeAtTextPath(child, seedPath);
        if (target && target.text === '') {
          const seeded = patchTextAtPath(child, seedPath, t('layout_editor.list_editor.new_item'));
          commitChildren([...items, seeded]);
          return;
        }
      }
    }
    commitChildren([...items, child]);
  }, [buildChildNode, commitChildren, items, t]);

  const removeAt = useCallback(
    (idx: number): void => {
      commitChildren(items.filter((_, i) => i !== idx));
    },
    [commitChildren, items],
  );

  const move = useCallback(
    (idx: number, dir: -1 | 1): void => {
      const target = idx + dir;
      if (target < 0 || target >= items.length) return;
      const next = [...items];
      [next[idx], next[target]] = [next[target]!, next[idx]!];
      commitChildren(next);
    },
    [commitChildren, items],
  );

  /**
   * 항목 텍스트 토큰 기록 — `I18nTextField` 가 평문 첫 입력 시 커스텀 키를 생성하면 그
   * `$t:custom.*` 토큰을 항목의 텍스트 노드(직접 text 또는 의미 있는 텍스트 자손)에 기록한다.
   *
   * 기존 커스텀 키의 **현재 로케일 값 갱신**·**ko/en/ja 일괄 편집**은 위젯이 키 모델
   * (useCustomTranslation SSoT) 안에서 처리하므로 노드 text(키 토큰)는 불변 → 그 경우
   * 위젯이 동일 토큰으로 onChange 를 호출해도 patch 결과가 동일하다(idempotent).
   *
   * @param idx 항목 인덱스
   * @param token 위젯이 흘린 새 값(생성된 `$t:custom.*` 토큰)
   */
  const commitItemTextToken = useCallback(
    (idx: number, token: string | undefined): void => {
      if (typeof token !== 'string' || token.length === 0) return;
      const item = items[idx];
      if (!item) return;
      // 항목 안의 실제 텍스트 노드(직접 text 또는 의미 있는 텍스트 자손) 경로.
      const textPath = findTextNodePath(item);
      if (textPath === null) return; // 텍스트 노드 없음(구조 자식)
      const next = items.map((it, i) => (i === idx ? patchTextAtPath(it, textPath, token) : it));
      commitChildren(next);
    },
    [items, commitChildren],
  );

  /**
   * 항목 prop 토큰 기록 — itemFields `{kind:"prop", prop}` 필드의 값(평문→키 생성 토큰)을
   * 그 prop 을 가진 자손 노드의 `props[prop]` 에 기록한다(텍스트와 동일 모델).
   *
   * @param idx 항목 인덱스
   * @param propKey 스펙이 선언한 prop 키
   * @param token 위젯이 흘린 새 값
   */
  const commitItemPropToken = useCallback(
    (idx: number, propKey: string, token: string | undefined): void => {
      if (typeof token !== 'string' || token.length === 0) return;
      const item = items[idx];
      if (!item) return;
      const propPath = findPropNodePath(item, propKey);
      if (propPath === null) return; // 그 prop 보유 자손 없음
      const next = items.map((it, i) =>
        i === idx ? patchPropAtPath(it, propPath, propKey, token) : it,
      );
      commitChildren(next);
    },
    [items, commitChildren],
  );

  // childComponent/childTemplate 둘 다 미선언(잘못된 capability) — 안전 안내.
  if (!childComponent && !childTemplate) {
    return (
      <div
        className="g7le-node-editor g7le-node-editor--children"
        data-testid="g7le-children-editor-misconfigured"
        style={emptyHint}
      >
        {t('layout_editor.list_editor.no_child_component')}
      </div>
    );
  }

  // 필드 캡션 노출 여부 — 다필드 선언 시에만(단일 text 필드는 종전처럼 캡션 없음).
  const showCaptions = itemFields.length > 1 || itemFields.some((f) => f.label);

  return (
    <div
      className="g7le-node-editor g7le-node-editor--children"
      data-testid="g7le-children-editor"
      style={wrap}
    >
      <div style={sectionTitle}>{t('layout_editor.list_editor.items_title')}</div>

      {items.length === 0 && (
        <div data-testid="g7le-children-empty" style={emptyHint}>
          {t('layout_editor.list_editor.empty')}
        </div>
      )}

      {items.map((child, idx) => {
        // 항목의 편집 가능 필드들 — 스펙 선언(itemFields) 순서대로, 항목에 그 위치가
        // 실재하는 필드만 렌더한다(placeholder 없는 체크박스 행은 라벨만 등).
        const fieldViews: Array<{
          key: string;
          caption: string | undefined;
          value: string;
          onChange: (token: string | undefined) => void;
          testid: string;
        }> = [];
        for (const field of itemFields) {
          if (field.kind === 'text') {
            const textPath = findTextNodePath(child);
            const textNode = textPath !== null ? nodeAtTextPath(child, textPath) : null;
            if (textNode && typeof textNode.text === 'string') {
              fieldViews.push({
                key: 'text',
                caption:
                  resolveSpecLabel(field.label, t) ??
                  (showCaptions ? t('layout_editor.list_editor.item_label') : undefined),
                value: textNode.text,
                onChange: (token) => commitItemTextToken(idx, token),
                testid: `g7le-children-i18n-${idx}`,
              });
            }
          } else if (field.kind === 'prop' && field.prop) {
            const propPath = findPropNodePath(child, field.prop);
            if (propPath !== null) {
              const target = nodeAtTextPath(child, propPath);
              const raw = (target?.props as Record<string, unknown> | undefined)?.[field.prop];
              fieldViews.push({
                key: `prop-${field.prop}`,
                caption: resolveSpecLabel(field.label, t) ?? field.prop,
                value: typeof raw === 'string' ? raw : '',
                onChange: (token) => commitItemPropToken(idx, field.prop!, token),
                testid: `g7le-children-prop-${field.prop}-${idx}`,
              });
            }
          }
        }

        const childName =
          typeof child?.name === 'string' ? child.name : t('layout_editor.list_editor.item');
        return (
          <div key={idx} data-testid={`g7le-children-row-${idx}`} style={row}>
            {/* 2줄 구조 — 입력(텍스트/구조라벨) 윗줄, 액션 버튼 아랫줄.
                좁은 속성 모달에서 한 줄에 입력+🌐+↑↓✕ 가 겹치고 가로 스크롤 나던 결함 근절. */}
            {fieldViews.length > 0 ? (
              fieldViews.map((fv) => (
                <div key={fv.key} style={inputLine}>
                  <div
                    data-testid={
                      fv.key === 'text'
                        ? `g7le-children-text-${idx}`
                        : `g7le-children-field-${fv.key}-${idx}`
                    }
                    style={textArea}
                  >
                    {fv.caption !== undefined && (
                      <div
                        data-testid={`g7le-children-caption-${fv.key}-${idx}`}
                        style={fieldCaption}
                      >
                        {fv.caption}
                      </div>
                    )}
                    <I18nTextField
                      value={fv.value}
                      onChange={fv.onChange}
                      t={t}
                      placeholder={t('layout_editor.list_editor.item_text')}
                      testidPrefix={fv.testid}
                      // children 항목 텍스트도 `+데이터` 칩 삽입(키화)에 후보 풀이
                      // 닿도록 전달(ArrayCellTreeEditor 가 본 컨트롤에 {...props} 통째 위임하므로
                      // 셀 트리 항목까지 자동 전파).
                      candidates={candidates}
                      // children 항목 텍스트도 표현식
                      // 분해 트리(접힌 미리보기 + [수정]) + 데이터 칩. 평문/단일키/칩 종전 경로(회귀 0).
                      enableExpressionTree
                      expressionTreeCollapsible
                    />
                  </div>
                </div>
              ))
            ) : (
              // 편집 가능 필드 없는 구조 자식 — 컴포넌트명 라벨(정렬/삭제만).
              <div style={inputLine}>
                <span data-testid={`g7le-children-label-${idx}`} style={structLabel}>
                  {childName}
                </span>
              </div>
            )}
            {/* 액션 버튼 줄 — 입력 아래 별도 줄, 우측 정렬. 윗줄과 분리돼 겹치지 않는다. */}
            <div style={actionLine}>
              <button
                type="button"
                data-testid={`g7le-children-up-${idx}`}
                title={t('layout_editor.list_editor.move_up')}
                disabled={idx === 0}
                onClick={() => move(idx, -1)}
                style={iconBtn}
              >
                ↑
              </button>
              <button
                type="button"
                data-testid={`g7le-children-down-${idx}`}
                title={t('layout_editor.list_editor.move_down')}
                disabled={idx === items.length - 1}
                onClick={() => move(idx, 1)}
                style={iconBtn}
              >
                ↓
              </button>
              <button
                type="button"
                data-testid={`g7le-children-remove-${idx}`}
                title={t('layout_editor.list_editor.remove')}
                onClick={() => removeAt(idx)}
                style={removeBtn}
              >
                ✕
              </button>
            </div>
          </div>
        );
      })}

      <button
        type="button"
        data-testid="g7le-children-add"
        onClick={addItem}
        style={addBtn}
      >
        {t('layout_editor.list_editor.add_child', { component: addLabel })}
      </button>
    </div>
  );
}

const wrap: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 6, width: '100%', marginBottom: 12 };
const sectionTitle: React.CSSProperties = { fontSize: 12, fontWeight: 700, color: '#0f172a', marginBottom: 2 };
// 2줄 구조 — 행은 세로 컨테이너. 윗줄 입력 + 아랫줄 액션 버튼.
const row: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 4, width: '100%', minWidth: 0, paddingBottom: 6, borderBottom: '1px solid #f1f5f9' };
// 입력 줄 — 전폭. minWidth:0 으로 내부 입력이 줄어들 수 있게 해 넘침 차단.
const inputLine: React.CSSProperties = { display: 'flex', gap: 4, alignItems: 'flex-start', width: '100%', minWidth: 0 };
// 텍스트 입력 영역 — 전폭 채움.
const textArea: React.CSSProperties = { flex: 1, minWidth: 0 };
// 필드 캡션(라벨/안내 문구) — 다필드 선언 시 입력 위 소제목.
const fieldCaption: React.CSSProperties = { fontSize: 11, color: '#64748b', marginBottom: 2 };
// 액션 버튼 줄 — 입력 아래 별도 줄, 우측 정렬. 윗줄과 분리돼 겹치지 않는다.
const actionLine: React.CSSProperties = { display: 'flex', gap: 4, alignItems: 'center', justifyContent: 'flex-end' };
const structLabel: React.CSSProperties = { flex: 1, minWidth: 0, padding: '4px 6px', fontSize: 12, color: '#475569', fontStyle: 'italic', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' };
const iconBtn: React.CSSProperties = { padding: '2px 6px', fontSize: 12, border: '1px solid #cbd5e1', borderRadius: 6, background: '#fff', color: '#475569', cursor: 'pointer' };
const removeBtn: React.CSSProperties = { padding: '2px 6px', fontSize: 12, border: '1px solid #fecaca', borderRadius: 6, background: '#fff', color: '#dc2626', cursor: 'pointer' };
const addBtn: React.CSSProperties = { padding: '4px 8px', fontSize: 12, border: '1px dashed #94a3b8', borderRadius: 6, background: '#fff', color: '#475569', cursor: 'pointer', alignSelf: 'flex-start' };
const emptyHint: React.CSSProperties = { fontSize: 11, color: '#94a3b8', fontStyle: 'italic' };
