// e2e:allow 노드 에디터 레지스트리 타입 — 칩·키화 결선용 후보 풀 prop 추가. 칩 입력기·합성 클릭 의존으로 Playwright 부적합, 단위+Chrome MCP 매트릭스로 검증 (계획 정책)
/**
 * nodeEditorRegistry.ts — 노드 에디터(속성탭 본체) 레지스트리
 *
 * capability 의 일반 슬롯 `nodeEditor: { kind, params }` 가 가리키는 `kind` 를
 * 실제 React 에디터 컴포넌트로 매핑한다. PropertyEditorModal 은 컴포넌트명이 아니라
 * 이 레지스트리의 kind 로만 디스패치한다(kind-agnostic — 코어는 종류를 모른다).
 *
 * 코어 빌트인(children/table)도 `registerCoreEditors` 가 본 API 로 등록한다
 * (특권/특수분기 0 — 템플릿 등록분과 동일 경로). 템플릿이 같은 kind 를 재등록하면
 * 그 편집기에서 빌트인을 대체한다(widgetRegistry 의 덮어쓰기 정책과 동일).
 *
 * 미등록 kind 는 `getNodeEditor` 가 null 을 돌려주고, PropertyEditorModal 은 안전
 * 디그레이드(아무 에디터도 렌더하지 않음 — 이름 가정 0).
 *
 * @since engine-v1.50.0
 */

import type React from 'react';
import type { EditorNode } from '../utils/layoutTreeUtils';
import type { EditorSpec } from './specTypes';
import type { ComponentManifest } from '../components/ComponentPalette';
import type { BindingCandidate } from './bindingCandidates';

/**
 * 노드 에디터 컴포넌트가 받는 공통 props.
 *
 * FlexEditor 와 같은 "탭 본체" 패턴 — value 파이프라인(WidgetProps)을 거치지 않고
 * 노드 전체를 직접 읽고 `onPatchNode` 로 통째 패치한다. `params` 는 capability 의
 * `nodeEditor.params`(그 kind 소유의 불투명 객체)를 그대로 전달받는다.
 */
export interface NodeEditorProps {
  /** 편집 대상 노드(현재 패치 반영본) */
  node: EditorNode;
  /** capability `nodeEditor.params` — 그 kind 에디터가 해석하는 불투명 객체 */
  params?: Record<string, unknown>;
  /** 병합 editor-spec */
  spec: EditorSpec | null;
  /** components.json 매니페스트 */
  manifest: ComponentManifest | null;
  /** 다국어 해석 함수 */
  t: (key: string, params?: Record<string, string | number>) => string;
  /** 노드 패치 — 호출자가 PATCH_LAYOUT 으로 캔버스 반영 */
  onPatchNode: (patched: EditorNode) => void;
  /** 편집 대상 템플릿 식별자 — 항목 다국어(custom-translations API) 등에 사용 */
  templateIdentifier?: string;
  /**
   * 데이터 연결 검색 후보 풀 — 배열폼/목록/표 셀 항목 텍스트의 `+데이터`
   * 칩 삽입(키화)에 쓴다. 단계 2~4 에서 nodeEditor(ArrayItems/Table/ChildrenList 등)가 항목별
   * I18nTextField/칩 입력기에 흘려보낸다. PropertyEditorModal 이 빌드해 주입(미전달 시 빈 검색).
   */
  candidates?: BindingCandidate[];
}

/** 노드 에디터 컴포넌트 타입 */
export type NodeEditorComponent = React.ComponentType<NodeEditorProps>;

/** 내부 레지스트리 — kind → 컴포넌트 */
const registry = new Map<string, NodeEditorComponent>();

/**
 * 노드 에디터 등록. 같은 kind 재등록은 덮어쓰기(템플릿이 코어 빌트인을 대체할 수
 * 있도록 — 단, 코어 빌트인은 부팅 시 1회 등록).
 *
 * @param kind 핸들러 kind (`children`/`table`/신규 종류)
 * @param component 에디터 컴포넌트
 */
export function registerNodeEditor(kind: string, component: NodeEditorComponent): void {
  registry.set(kind, component);
}

/**
 * 등록된 노드 에디터 조회. 미등록이면 null(PropertyEditorModal 디그레이드).
 *
 * @param kind 핸들러 kind
 * @return 에디터 컴포넌트 또는 null
 */
export function getNodeEditor(kind: string | undefined): NodeEditorComponent | null {
  if (!kind) return null;
  return registry.get(kind) ?? null;
}

/** 등록된 kind 목록 (진단/테스트용) */
export function getRegisteredNodeEditorKinds(): string[] {
  return Array.from(registry.keys());
}

/** 레지스트리 초기화 (테스트 격리용) */
export function clearNodeEditorRegistry(): void {
  registry.clear();
}
