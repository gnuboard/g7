// e2e:allow 레이아웃 편집기 인라인 텍스트 편집 훅 — 캔버스 더블클릭/칩 합성 입력 의존으로 Playwright 자동화 부적합, Chrome MCP 매트릭스 + 단위(useInlineEdit.commit.test.tsx S9-N4 포함)로 검증 (InlineParamChipEditor.tsx 와 동일 정책)
/**
 * useInlineEdit.ts — 인라인 텍스트 편집 + 동적 다국어 키 생성/수정
 *
 * 캔버스에서 `text` 보유 노드를 더블클릭해 확정할 때의 분기 로직을 순수 함수로 분리한다.
 *  1. 이미 `$t:custom.*` 키 → 현재 로케일 값만 `PUT /custom-translations/{id}`.
 *  2. 평문 → 커스텀 키 자동 생성(`POST /custom-translations`) → 응답 키로 `comp.text` 를
 *     `"$t:custom...."` 로 치환 → patchLayout.
 *  3. `{{...}}` 포함 텍스트(바인딩식) → 인라인 편집 비활성.
 *
 * 키 생성 시 노드에 컴포넌트 id 를 부여하지 않는다. 키는
 * 그 노드의 `text` 값(`"$t:custom...."`) 자체로 JSON 에 기록되므로 노드와 영구 연결된다.
 *
 * 본 hook 은 백엔드 4 엔드포인트(`api.admin.templates.custom-translations.*`) 위에
 * 프론트 UI 를 결선한다. 모든 fetch 는 `buildAuthHeaders`(Bearer)로 인증한다(8-1).
 *
 * @since engine-v1.50.0
 */

import { useCallback } from 'react';
import { useLayoutEditor } from '../LayoutEditorContext';
import { useLayoutDocumentContext } from '../LayoutDocumentContext';
import { buildAuthHeaders } from '../utils/authToken';
import {
  findNodeByPath,
  patchNode,
  serializeEditorPath,
  type EditorNode,
  type ComponentPath,
} from '../utils/layoutTreeUtils';
import {
  trackEditorI18n,
  hashInlineText,
  type EditorI18nSourceState,
} from '../devtools/editorTrackers';
import { setPendingValue, setPendingValues } from './pendingCustomTranslations';
import { TranslationEngine } from '../../TranslationEngine';
import {
  stripBindingTokens,
  buildParamizedKeyText,
  buildParamizedKeyValue,
  extractParamBindings,
  resolveLangLiterals,
  deriveChipModel,
  buildKeyTextFromChipModel,
  paramPlaceholderTokens,
  appendPlaceholder,
} from '../spec/inlineBindingUtils';

/** `$t:` 접두 토큰 정규식 — 단일 키 텍스트 판정 (앞뒤 공백 허용). */
const T_KEY_RE = /^\s*\$t:([a-zA-Z0-9._-]+)\s*$/;
/** 커스텀 키 prefix — 동적 다국어 키만 인라인 편집 대상 (코어/언어팩 키는 읽기전용). */
const CUSTOM_KEY_PREFIX = 'custom.';
/** 바인딩식 토큰 — `{{...}}` 가 포함되면 인라인 편집 비활성. */
const BINDING_RE = /\{\{.*?\}\}/;

/** 노드 텍스트 소스 분류 결과. */
export interface InlineEditClassification {
  /** 텍스트 소스 종류 */
  sourceState: EditorI18nSourceState;
  /** 인라인 편집 가능 여부 (바인딩식·데이터 결정 노드는 false) */
  editable: boolean;
  /** 기존 커스텀 키인 경우 그 키 (`custom.{layout}.{seq}`) */
  customKey: string | null;
  /** 화면에 표시할 현재 값 — 평문이면 그대로, 커스텀 키면 현재 로케일 번역값 */
  displayValue: string;
  /**
   * param 부착 키(`$t:custom.X|pN={{}}`)인지 — 인라인 편집을 칩 합성 위젯
   * (PlaceholderChipInput)으로 띄워 평문은 편집, 보간은 드래그 칩으로 표시해야 한다.
   * true 면 InlineTextEditor 가 칩 편집기로 분기한다.
   */
  isParamKey?: boolean;
  /**
   * 데이터가 든 **미키화** 텍스트(`plain_with_binding`)도 첫 진입부터 데이터를 칩으로 보여야
   * 한다. 아직 커스텀 키가 없으므로, 노드 text 를 lang 평문화 +
   * param 정규화한 **파생 칩 값**(`"발행일: {p0}"`)을 여기에 담아 칩 편집기가 키 없이도 칩을
   * 렌더하게 한다. 키 생성은 사용자가 **내용을 실제로 바꿀 때만** 일어난다.
   * `chipValue` 가 있으면(=데이터 든 미키화) overlay 가 chip 편집기로 분기한다.
   */
  chipValue?: string;
  /** 파생 칩 값의 각 `{pN}` → 데이터 친화 라벨(바인딩 경로). 칩 표시명. */
  chipParamLabels?: Record<string, string>;
}

/**
 * 노드의 `text` 가 데이터 결정(iteration/바인딩) 영역인지 — 진입 차단 대상.
 * `iteration` 보유 노드는 데이터 행마다 반복 렌더되므로 단일 평문 편집 대상이 아니다.
 *
 * @param node 검사할 노드
 * @return 데이터 결정 노드면 true
 */
export function isDataBoundNode(node: EditorNode | null | undefined): boolean {
  if (!node) return true;
  if (node.iteration && typeof node.iteration === 'object') return true;
  return false;
}

/**
 * 노드 텍스트를 분류한다(순수 함수). text 가 문자열이 아니면 편집 불가.
 *
 * @param node 대상 노드
 * @param translate 커스텀 키 → 현재 로케일 값 해석기 (없으면 키 그대로 표시)
 * @return 분류 결과
 */
export function classifyInlineText(
  node: EditorNode | null | undefined,
  translate?: (key: string) => string,
): InlineEditClassification {
  const text = node?.text;
  const raw = typeof text === 'string' ? text : '';

  // 데이터 결정 노드(iteration) 또는 text 미보유 → 편집 불가.
  if (isDataBoundNode(node) || typeof text !== 'string') {
    return { sourceState: 'plain_text', editable: false, customKey: null, displayValue: raw };
  }

  // param 부착 custom 키 텍스트 — `$t:custom.X|p0={{a}}|p1={{b}}`.
  // 평문+보간 혼합 노드를 인라인 편집해 키화하면 이 형태가 된다. 평문/문장은 번역 탭(키 K 의
  // 로케일 값, `{p0}` 자리표시 포함)에서, 보간 소스는 [속성] 탭 데이터 연결(param 값 교체)에서
  // 편집한다 — 한 노드 두 표면(쟁점 4 공존). 인라인 편집 진입 시작값은 키의 현재 로케일 값.
  const paramized = extractParamBindings(raw);
  if (paramized && paramized.key.startsWith(CUSTOM_KEY_PREFIX)) {
    const display = translate ? translate(paramized.key) : paramized.key;
    return {
      sourceState: 'custom_key',
      editable: true,
      customKey: paramized.key,
      displayValue: display,
      isParamKey: true,
    };
  }

  // 단일 $t: 키 텍스트
  const keyMatch = T_KEY_RE.exec(raw);
  if (keyMatch) {
    const key = keyMatch[1];
    // 커스텀 키 → 그 키의 현재 로케일 값만 수정(PUT). 점선 밑줄/배지 표시 대상.
    if (key.startsWith(CUSTOM_KEY_PREFIX)) {
      const display = translate ? translate(key) : key;
      return {
        sourceState: 'custom_key',
        editable: true,
        customKey: key,
        displayValue: display,
      };
    }
    // 그 외 모든 $t: 키(템플릿/언어팩 lang 키, 팔레트 placeholder 포함) → 평문과 동격으로
    // 편집 가능. 더블클릭 시 현재 로케일 번역값을 시작값으로 보여 주고,
    // 확정하면 새 커스텀 키를 생성해 그 노드 text 를 `$t:custom.*` 로 치환한다(원본 키는
    // 그 노드에서 분리). 와이어프레임의 "평문 → 키 생성" 흐름을 기존 키 텍스트에도
    // 동일 적용 — "이 화면의 이 문구를 바꾸고 싶다"는 의도 충족. (계획서 의 편집 불가
    // 대상은 `{{...}}` 바인딩식·iteration 데이터결정·base/extension 잠금뿐 — $t: 키는 미포함.)
    // 시작값: 키의 현재 로케일 해석값(placeholder 포함 — 사용자가 보고 있는 그 문구).
    const display = translate ? translate(key) : '';
    return {
      sourceState: 'plain_text',
      editable: true,
      customKey: null,
      // 해석 실패(빈 문자열/키 자체) 시 빈 시작값 — raw 키 노출 회피.
      displayValue: display && display !== key ? display : '',
    };
  }

  // `{{...}}` 바인딩식 포함. 보간을 제거한 평문(`plain`)의 형태로 갈린다:
  //  - plain 이 `$t:custom.*` 단일 키("$t:custom.X {{보간}}") → custom 키 + 보간 공존. 평문(키)은
  //    번역 탭, 보간은 [속성] 탭 데이터 연결로 표면 분리(9-a 쟁점 4). custom_key 로 분류(번역 탭 ON).
  //  - plain 이 `$t:<non-custom>` lang 키("$t:policy.published_at: {{date}}", Shape A) — "공통 문구 +
  //  데이터". : **문구 편집 허용 + 데이터는 칩**. lang 키를 평문(라벨)으로
  //    해석해 plain_with_binding 으로 편집 가능하게 한다(commit 이 이 화면 전용 커스텀 키로 키화 —
  //    공통 lang 문구는 보존, 이 자리만 변경). 종전엔 binding_expression(차단)이었으나, 구분자(`:`)가
  //    붙으면 `stripBindingTokens` 결과(`$t:policy.published_at:`)가 T_KEY_RE 와 불일치해 차단이 새고
  //  raw 키가 키 값에 박혀 재귀 키가 증식하던 결함. lang 키를 먼저 평문화해 두 문제를 함께 해소.
  //  - plain 이 순수 평문("test 000 {{보간}}") → 평문 조각 인라인 편집 허용. commit 이 키화 후 보간
  //    토큰을 키 바깥에 복원한다(Shape A 정규화 — 평문=키, 보간=키 바깥). plain_with_binding.
  //  - plain 이 빈 문자열(보간 전용 "{{x}}") → 편집할 평문 없음 → 데이터 연결 행이 담당. 차단.
  if (BINDING_RE.test(raw)) {
    const plain = stripBindingTokens(raw);
    const plainKeyMatch = T_KEY_RE.exec(plain);
    if (plainKeyMatch && plainKeyMatch[1].startsWith(CUSTOM_KEY_PREFIX)) {
      const key = plainKeyMatch[1];
      const display = translate ? translate(key) : key;
      return { sourceState: 'custom_key', editable: true, customKey: key, displayValue: display };
    }
    // lang 키(custom 아님)를 평문으로 해석한 뒤 보간 제거 — "공통 문구 + 데이터"(Shape A)의 편집용
    // 평문 라벨을 얻는다. 구분자(`:`/공백) 유무와 무관하게 동작한다(T_KEY_RE 의 구분자 취약점 회피).
    const resolvedRaw = resolveLangLiterals(raw, translate);
    const resolvedPlain = stripBindingTokens(resolvedRaw);
    // 해석 후에도 raw `$t:` 토큰이 남아 있으면(번역 미해석 — 사전 미로드) 편집 시작값을 비운다
    // (raw 키 노출 회피). 단 편집 자체는 허용(commit 이 재해석/키화). 평문이 0 이면 데이터 전용 → 차단.
    const hasUnresolvedLangKey = /\$t:[a-zA-Z0-9._-]+/.test(resolvedPlain);
    if (resolvedPlain.length > 0) {
      // 데이터(보간)를 첫 진입부터 **칩**으로 보이게 한다. 아직 키가 없으므로
      // deriveChipModel 이 전 Shape(순수 평문+보간 / lang키+구분자+보간 / lang키+이름param)를 통합
      // 처리해 파생 칩 값(`"발행일: {p0}"`, `"남은 시도: {p0}회"`)과 칩 라벨을 만든다. overlay 가
      // chipValue 존재 시 칩 편집기로 분기하고, 키 생성은 내용 변경 시에만 일어난다.
      // (종전 buildParamizedKeyValue 단순 변환은 이름param lang키에서 lang값 {{count}} 와 노드
      //  |count={{}} 를 이중 {p0}/{p1} 화해 칩이 깨졌다 — identity/challenge 결함. deriveChipModel 로 근본 해소.)
      const chip = deriveChipModel(raw, translate);
      return {
        sourceState: 'plain_with_binding',
        editable: true,
        customKey: null,
        displayValue: hasUnresolvedLangKey ? '' : resolvedPlain,
        // 칩 모델이 키화 가능할 때만 칩 편집기 분기. 미해석/이중변환 위험 시 평문 편집기 폴백.
        chipValue: chip.keyifiable ? chip.chipValue : undefined,
        chipParamLabels: chip.keyifiable ? chip.paramLabels : undefined,
      };
    }
    return { sourceState: 'binding_expression', editable: false, customKey: null, displayValue: raw };
  }

  // 순수 평문 → 편집 가능 (확정 시 키 자동 생성)
  return { sourceState: 'plain_text', editable: true, customKey: null, displayValue: raw };
}

/** store 응답 — 생성된 커스텀 키 리소스(필요한 필드만). */
interface CreatedKeyResource {
  id: number;
  translation_key: string;
  values: Record<string, string>;
  lock_version: number;
}

export interface InlineEditCommitResult {
  kind: 'created' | 'updated' | 'noop' | 'blocked' | 'error';
  /** 생성/수정된 커스텀 키 */
  translationKey?: string;
  /** 생성된 행 id (created 시) */
  id?: number;
  /** error 시 메시지 */
  message?: string;
}

export interface UseInlineEditResult {
  /** 노드 텍스트 분류 (편집 진입 가부 판정) */
  classify: (node: EditorNode | null | undefined) => InlineEditClassification;
  /**
   * 인라인 편집 확정 — path 의 노드 text 를 newValue 로 반영한다.
   *  - 기존 커스텀 키: 현재 로케일 값만 PUT.
   *  - 평문: POST 로 키 생성 → 노드 text 를 `$t:custom...` 으로 치환(patchLayout).
   *  - 바인딩식/데이터 결정: noop(blocked).
   *
   * @param path 대상 노드의 컴포넌트 path (number[])
   * @param newValue 사용자가 입력한 새 텍스트(평문)
   * @return 커밋 결과
   */
  commit: (path: ComponentPath, newValue: string) => Promise<InlineEditCommitResult>;
  /** 편집 취소를 트래커에 기록(상태 변경 없음). */
  trackCancel: (path: ComponentPath | null) => void;
  /**
   * 데이터 든 미키화 노드(`plain_with_binding`)를 칩 편집기에서 **내용 변경 시** 키화한다
   * 편집된 `{pN}` 자리표시 키 값으로 커스텀 키를 생성하고
   * 노드 text 를 `$t:custom.X|pN={{원본보간}}` param 형태로 치환한다. 반환 customKey 로 칩
   * 편집기가 이후 일반 param 키 경로로 전환한다. 키 생성은 내용 변경 시에만.
   *
   * @param path 대상 노드 path
   * @param editedKeyValue 칩 편집기에서 편집된 자리표시 키 값(`"발행일: {p0}"`)
   * @return 생성된 키 또는 error
   */
  keyifyChipValue: (
    path: ComponentPath,
    editedKeyValue: string,
  ) => Promise<{ kind: 'created' | 'error'; translationKey?: string; message?: string }>;
}

/**
 * 인라인 편집 hook. EditorCanvasOverlay 가 더블클릭 시 호출한다.
 */
export function useInlineEdit(): UseInlineEditResult {
  const { state } = useLayoutEditor();
  const docCtx = useLayoutDocumentContext();
  const templateIdentifier = state.templateIdentifier;
  const locale = state.locale;
  const layoutName = state.selectedRoute?.layoutName ?? null;

  // 커스텀 키 현재 로케일 값 해석 — 캔버스와 같은 격리 사전 대신 코어 싱글톤 사전을
  // 사용한다(PreviewCanvas 가 `loadTranslations` 로 채운 사전). 미해석 시 키 반환.
  const translateKey = useCallback(
    (key: string): string => {
      try {
        const engine = TranslationEngine.getInstance();
        const resolved = engine.translate(key, { templateId: templateIdentifier, locale });
        return resolved && resolved !== key ? resolved : '';
      } catch {
        return '';
      }
    },
    [templateIdentifier, locale],
  );

  const classify = useCallback(
    (node: EditorNode | null | undefined): InlineEditClassification =>
      classifyInlineText(node, translateKey),
    [translateKey],
  );

  const trackCancel = useCallback(
    (path: ComponentPath | null): void => {
      trackEditorI18n({
        op: 'inline_edit_cancel',
        componentPath: path ? serializeEditorPath(path) : null,
        timestamp: Date.now(),
      });
    },
    [],
  );

  const commit = useCallback(
    async (path: ComponentPath, newValue: string): Promise<InlineEditCommitResult> => {
      if (!docCtx) return { kind: 'error', message: 'no document context' };
      const components = (docCtx.document?.raw?.components as EditorNode[] | undefined) ?? [];
      const root: EditorNode = { children: components };
      const node = findNodeByPath(root, path);
      const cls = classifyInlineText(node, translateKey);

      if (!cls.editable) {
        trackEditorI18n({
          op: 'inline_edit_blocked_binding',
          sourceState: cls.sourceState,
          componentPath: serializeEditorPath(path),
          timestamp: Date.now(),
        });
        return { kind: 'blocked' };
      }

      // (1) 기존 커스텀 키 → 현재 로케일 값만 PUT.
      //
      // param 부착 키(`$t:custom.X|p0={{a}}`)도 같은 경로다. 단 사용자가 인라인
      // 편집으로 입력한 값(`newValue`)은 보간 평가값이 치환된 **표시 문장**이지 자리표시 문장이
      // 아니므로, 그대로 PUT 하면 `{p0}` 자리표시가 사라진다. 인라인 편집 진입 시작값
      // (`cls.displayValue`)도 동일하게 평가값이 치환된 문장이라, 평문 부분 편집이 자리표시를
      // 깨지 않는 한 토큰 보존이 어렵다 → param 키의 문장/자리표시 편집은 [번역] 탭으로 위임하고,
      // 인라인 편집에서는 평문 변경분만 단순 PUT 하되 자리표시는 번역 탭 가드가 지킨다.
      // (인라인 편집은 "그 자리 현재 로케일 문장을 빠르게 고치기" 용도 — 자리표시 포함 문장을
      //  편집하면 그대로 반영. 자리표시 멀티셋 보존은 번역 탭 저장 가드와 동일 책임.)
      if (cls.customKey) {
        // 변경 없음 → noop(서버 호출 회피).
        if (newValue === cls.displayValue) {
          return { kind: 'noop', translationKey: cls.customKey };
        }
        // 선반영 — PUT 왕복 전에 입력값을 사전에 즉시 주입 + 캔버스 재렌더.
        // 편집 종료 직후 옛 값이 잠깐 노출되는 깜빡임을 없앤다(서버 권위 값은 아래 재fetch 가 보정).
        seedTranslationOptimistically(templateIdentifier, locale, cls.customKey, newValue);
        const result = await updateCustomKeyValue(
          templateIdentifier,
          cls.customKey,
          locale,
          newValue,
        );
        if (result.kind === 'error') {
          return { kind: 'error', message: result.message };
        }
        await bustTranslationCache(templateIdentifier, locale);
        trackEditorI18n({
          op: 'inline_edit_update_value',
          sourceState: 'custom_key',
          componentPath: serializeEditorPath(path),
          translationKey: cls.customKey,
          toLocale: locale,
          valueLength: newValue.length,
          valueHash: hashInlineText(newValue),
          timestamp: Date.now(),
        });
        return { kind: 'updated', translationKey: cls.customKey };
      }

      // (2) 평문 → 커스텀 키 생성 후 노드 text 치환.
      //
      // 평문+보간 혼합: **param 정규화**한다.
      // 원본 보간을 `|p0={{a}}|p1={{b}}` param 으로 키 뒤에 부착하고, 키 값은 평문에 보간 자리를
      // `{p0}`/`{p1}` 자리표시로 치환한 문장으로 둔다(위치/개수 완전 보존). 키화된 text 는
      // `"$t:custom.X|p0={{a}}|p1={{b}}"` → 평문/문장=번역 탭(키 값), 보간=데이터 연결(param 값)로
      // 표면 분리(쟁점 4·5-3). TranslationEngine 의 검증된 param 치환 경로라 엔진 무변경.
      // 순수 평문이면 보간 0 → `$t:key` 단독(종전 동작 동일).
      const originalText = typeof node?.text === 'string' ? node.text : '';
      const isMixed = cls.sourceState === 'plain_with_binding';
      // 키 값 생성 전 lang 키 평문화 — "공통 문구 + 데이터"(Shape A,
      // `"$t:policy.published_at: {{date}}"`)를 키화할 때, 원본 평문에 박힌 `$t:<lang 키>` 를
      // 그 로케일 번역값("발행일")으로 먼저 치환한다. 평문화하지 않으면 키 값이
      // `"$t:policy.published_at: {p0}"` (=lang 키 참조)가 되고, 그 노드를 다시 편집하면 그 값을
      // 또 키화해 `$t:custom.X|p0={p0}` 가 무한 증식한다. 평문화하면 키 값이
      // `"발행일: {p0}"` 가 되어 lang 키 참조가 사라져 재귀가 끊긴다. 순수 평문/일반 보간 노드는
      // lang 키가 없어 무영향(resolveLangLiterals 항등).
      const resolvedOriginal = resolveLangLiterals(originalText, translateKey);
      // createCustomKey 에 보낼 초기 로케일 값 — 혼합이면 자리표시 문장(`"{p0} 작성 {p1}"`),
      // 순수 평문이면 입력값 그대로. 번역가는 이 문장을 통째로 번역하며 자리만 유지한다.
      const keyValue = isMixed ? buildParamizedKeyValue(resolvedOriginal) : newValue;
      // 키화된 text 를 만든다 — 혼합이면 `$t:key|p0={{a}}..`(param 정규화), 순수 평문이면 `$t:key`.
      const withParams = (keyToken: string): string =>
        isMixed ? buildParamizedKeyText(keyToken, originalText) : keyToken;
      // 선반영 — 키 생성 POST 왕복 동안 캔버스가 옛 텍스트를 보이지 않도록,
      // 먼저 노드 text 를 입력한 평문(보간 제거)으로 즉시 교체한다. 이어 키 생성이 끝나면
      // param 정규화 형태로 다시 교체하되, 키 값을 자리표시 문장으로 seed + 보간을 param 으로
      // 평가하므로 캔버스 텍스트는 원본과 동일하게 유지된다(키화 전후 캔버스 불변 — 명세 4).
      docCtx.patchLayout((current) => {
        const r: EditorNode = { children: current };
        // 선반영 단계는 보간 없는 단순 평문(newValue) — 키 생성 직후 param 형태로 재치환된다.
        const next = patchNode(r, path, (n) => ({ ...n, text: newValue }));
        return (next.children as EditorNode[]) ?? [];
      });

      // 키 값으로 자리표시 문장(혼합) 또는 입력 평문(순수)을 POST → 백엔드가 그대로 ko 값 저장.
      const created = await createCustomKey(templateIdentifier, layoutName, locale, keyValue);
      if (created.kind === 'error' || !created.resource) {
        // 키 생성 실패 — 선반영한 평문은 그대로 둔다(사용자 입력 보존). 다음 편집/저장에서 재시도.
        return { kind: 'error', message: created.message };
      }
      const key = created.resource.translation_key;
      // 낙관적 즉시 반영 — 노드 text 를 param 형태로 치환하기 전에 그 키의 현재 로케일
      // 값(자리표시 문장)을 사전에 주입한다. 그래야 patchLayout 직후 캔버스가 raw 키가 아니라
      // `{p0}` 가 보간 평가값으로 치환된 원본 텍스트로 즉시 렌더된다(깜빡임 0). 서버 권위 값은
      // 아래 재fetch 가 원자 교체한다(다른 로케일 값도 함께 채워짐).
      seedTranslationOptimistically(templateIdentifier, locale, key, keyValue);
      // 노드 text 를 `$t:custom....` 로 치환 → patchLayout.
      //
      // 좀비(orphaned) 정리 위임: 노드 text 를 이전 custom 키에서
      // 다른 값(평문/새 키)으로 바꾸거나 노드를 삭제해도, 여기서 이전 키를 즉시
      // orphaned/destroy 호출하지 않는다. 끊긴 키 표시는 **레이아웃 저장 시점**에
      // 백엔드 `core.layout.after_update` 리스너(MarkOrphanedCustomTranslations)가
      // 저장된 content 를 스캔해 일괄 전이한다. 인라인 편집 단계에서 개별 정리하면
      // (a) 같은 키를 다시 입력해 되살리는 흐름, (b) 미저장 취소(Escape/이탈) 시
      // 롤백을 별도 추적해야 하므로, 저장 시 일괄 처리에 위임해 단일 SSoT 를 둔다.
      docCtx.patchLayout((current) => {
        const r: EditorNode = { children: current };
        const next = patchNode(r, path, (n) => ({ ...n, text: withParams(`$t:${key}`) }));
        return (next.children as EditorNode[]) ?? [];
      });
      await bustTranslationCache(templateIdentifier, locale);
      trackEditorI18n({
        op: 'inline_edit_create_key',
        sourceState: cls.sourceState,
        componentPath: serializeEditorPath(path),
        translationKey: key,
        toLocale: locale,
        valueLength: newValue.length,
        valueHash: hashInlineText(newValue),
        timestamp: Date.now(),
      });
      return { kind: 'created', translationKey: key, id: created.resource.id };
    },
    [docCtx, templateIdentifier, locale, layoutName, translateKey],
  );

  // 데이터 든 미키화 노드(`plain_with_binding`)를 칩 편집기에서 **내용 변경 시** 키화한다
  // 속성 패널의
  // `keyifyWithNewBinding`(데이터 삽입=즉시 키화)과 동일 패턴으로 일관 — 키는 **즉시 생성(POST)**
  // 하되 **placeholder 키 값은 저장-지연 버퍼**에 두고 레이아웃 [저장] 시 flush 한다(desync 0,
  // [[feedback_editor_chip_input_uncontrolled_and_all_interaction_cases]] eager-PUT 금지).
  //  1. 초기 값 = lang 평문화한 **평문 base**(보간/자리표시 0)로 POST → 미저장 새로고침해도 raw
  //     `{pN}` 미노출(키 값=평문, node.text=원본 복귀 → desync 0).
  //  2. 편집된 placeholder 키 값(`"발행일: {p0}"`)은 setPendingValue 버퍼 → [저장] 시 영속.
  //  3. 노드 text 를 `$t:custom.X|pN={{원본보간}}` param 형태로 치환(원본 노드의 보간 순서 보존).
  //  4. 키 값 seed → 캔버스가 raw 키가 아니라 `{p0}` 가 보간 평가값으로 치환된 텍스트로 즉시 렌더.
  // 반환된 customKey 로 칩 편집기가 이후 일반 param 키 경로(putSingleLocaleKeyValue)로 전환한다.
  const keyifyChipValue = useCallback(
    async (
      path: ComponentPath,
      editedKeyValue: string,
    ): Promise<{ kind: 'created' | 'updated' | 'error'; translationKey?: string; message?: string }> => {
      if (!docCtx) return { kind: 'error', message: 'no document context' };
      const components = (docCtx.document?.raw?.components as EditorNode[] | undefined) ?? [];
      const root: EditorNode = { children: components };
      const node = findNodeByPath(root, path);
      const originalText = typeof node?.text === 'string' ? node.text : '';
      // G-2 충돌 차단 — 노드가
      // **이미 param 키**(`$t:custom.X|pN={{}}`)이면 재키화하지 않는다. 미키화 노드에 인라인
      // '+데이터'(keyifyWithNewBinding)로 1차 키화하면 node.text 가 param 키가 되는데, 그 직후
      // 저장 버튼이 칩 편집기 activeKeyRef===null 이라 본 함수를 또 호출하면 종전엔 무조건
      // createCustomKey 로 **둘째 키를 만들어** 두 모델이 충돌했다(기존 칩 소실 + raw 보간 박힘).
      // 이미 키화된 노드는 그 키의 현재 로케일 값만 버퍼 기록(putSingleLocaleKeyValue 와 동형)하고
      // 끝낸다 — node.text 변경 없음(키 토큰·param 보존). 칩 편집기 activeKeyRef 전파(아래 오버레이
      // 수정)와 이중 안전망: 전파가 동작하면 본 함수는 애초에 호출되지 않고, 어떤 경로로든 호출돼도
      // 본 가드가 충돌을 막는다.
      //
      // S9-N4: 본 가드는
      // **custom 키일 때만** 적용한다. `extractParamBindings`(PARAMIZED_KEY_RE)는 custom/lang 키를
      // 구분하지 않아, **미키화 lang named-param** 노드(`$t:user.identity.challenge.remaining_attempts
      // |count={{Math.max(...)}}`)도 매칭한다. 가드가 그런 노드를 "이미 키화됨"으로 오판하면 키화를
      // 건너뛰고 lang 키(`user.identity...`)에 pending 만 심은 채 `updated` 를 반환한다 → 오버레이
      // handleInlineChipKeyify 가 `kind !== 'created'` 이라 null 반환 → 칩 편집기 안 닫힘 + 캔버스 raw
      // `{p0}` 노출 + DB 키 미생성(저장 무반응) + lang 키 오염(`남은 {p0}시도: 회|count={p0}` 잔재).
      // custom 가드를 붙이면 lang named-param 은 아래 정상 키화(createCustomKey) 경로로 내려간다.
      // (classify 의 paramized 분기[line 119]도 동일하게 `startsWith(CUSTOM_KEY_PREFIX)` 가드를 둔다 —
      //  두 함수의 "키화됨" 판정 기준 일치.) G-2 가 막던 노드는 custom param 키라 가드는 그대로 작동.
      const existing = extractParamBindings(originalText);
      if (existing && existing.key.startsWith(CUSTOM_KEY_PREFIX)) {
        setPendingValue(templateIdentifier, existing.key, locale, editedKeyValue);
        seedTranslationOptimistically(templateIdentifier, locale, existing.key, editedKeyValue);
        docCtx.markDirty?.();
        return { kind: 'updated', translationKey: existing.key };
      }
      // 칩 모델 — node.text 의 보간을 칩 `{pN}` 순서(이름param lang키는 lang값 자리표시 순서)대로
      // 확정한다. node.text 의 보간 등장 순서와 칩 순서가 다를 수 있어(다중 named param), 키화 시
      // 반드시 칩 모델 bindings 를 SSoT 로 써야 `{pN}` ↔ `|pN={{}}` 가 일치한다.
      const chip = deriveChipModel(originalText, translateKey);
      // 초기 POST 값 = 편집된 키 값에서 `{pN}` 자리표시를 제거한 평문 base(desync 방지 — 속성 패널
      // keyifyWithNewBinding 과 동일). 미저장 새로고침 시 키 값=평문, node.text 원복 → raw `{pN}` 0.
      // `{pN}` 자리표시뿐 아니라 raw `{{...}}` 보간도 제거한다 — chipValue 에
      // 혹시 정규화 안 된 raw 보간이 섞여 들어와도 키 값(전 로케일)에 raw 표현식이 영속되지 않도록.
      const plainBase = stripBindingTokens(editedKeyValue.replace(/\{\{?p\d+\}?\}/g, ' '))
        .replace(/\s+/g, ' ')
        .trim();
      const created = await createCustomKey(templateIdentifier, layoutName, locale, plainBase);
      if (created.kind === 'error' || !created.resource) {
        return { kind: 'error', message: created.message };
      }
      const key = created.resource.translation_key;
      // placeholder 키 값을 **전 로케일** 버퍼에 — 저장 시 flush(다중 칩 다로케일
      // 누락 결함과 동일 원인). 편집 로케일에만 placeholder 를 심으면, 다른 로케일은 서버 plainBase
      // 로 남아 둘째 칩 추가 시 insertBindingIntoParamKey 가 첫 칩 `{p0}` 를 못 붙인다. 편집 로케일은
      // 커서 위치 문장(editedKeyValue), 그 외는 그 로케일 서버값 끝에 같은 자리표시들 추가.
      const paramNames = paramPlaceholderTokens(editedKeyValue);
      const serverValues = (created.resource.values ?? {}) as Record<string, string>;
      const seeded: Record<string, string> = { [locale]: editedKeyValue };
      for (const loc of Object.keys(serverValues)) {
        if (loc === locale) continue;
        let v = serverValues[loc] ?? '';
        for (const pn of paramNames) v = appendPlaceholder(v, pn);
        seeded[loc] = v;
      }
      setPendingValues(templateIdentifier, key, seeded);
      // 캔버스 즉시 반영 — 키 값 seed(placeholder 문장) 후 노드 text 를 param 형태로 치환(칩 순서 보존).
      seedTranslationOptimistically(templateIdentifier, locale, key, editedKeyValue);
      docCtx.patchLayout((current) => {
        const r: EditorNode = { children: current };
        const next = patchNode(r, path, (n) => ({ ...n, text: buildKeyTextFromChipModel(`$t:${key}`, chip) }));
        return (next.children as EditorNode[]) ?? [];
      });
      docCtx.markDirty?.();
      await bustTranslationCache(templateIdentifier, locale);
      // 재-seed. 위 bustTranslationCache 는
      // 서버 lang 을 재fetch 해 엔진 사전을 교체하는데, 새 키의 **서버 값은 plainBase**(`"발 행일:"`,
      // 자리표시 없음 — placeholder 문장은 아직 저장-지연 버퍼에만 있고 레이아웃 [저장] 전까지
      // 서버 미반영)다. 그래서 refetch 가 위 seed(placeholder)를 plainBase 로 덮어써 `{p0}` 가
      // 사라지고 캔버스가 데이터 없이 렌더된다(칩 소실). bust 직후 placeholder 를 다시 주입해
      // 레이아웃 저장 전에도 캔버스가 `{p0}` 치환(=데이터 칩) 문장을 유지하게 한다.
      seedTranslationOptimistically(templateIdentifier, locale, key, editedKeyValue);
      trackEditorI18n({
        op: 'inline_edit_create_key',
        sourceState: 'plain_with_binding',
        componentPath: serializeEditorPath(path),
        translationKey: key,
        toLocale: locale,
        valueLength: editedKeyValue.length,
        valueHash: hashInlineText(editedKeyValue),
        timestamp: Date.now(),
      });
      return { kind: 'created', translationKey: key };
    },
    [docCtx, templateIdentifier, locale, layoutName],
  );

  return { classify, commit, trackCancel, keyifyChipValue };
}

/**
 * 커스텀 키 생성 (`POST /api/admin/templates/{id}/custom-translations`).
 *
 * @param templateIdentifier 템플릿 식별자
 * @param layoutName 편집 중 레이아웃명 (키 네이밍 출처)
 * @param locale 편집 로케일
 * @param value 입력 평문
 * @return 생성 결과 (성공 시 리소스 포함)
 */
export async function createCustomKey(
  templateIdentifier: string,
  layoutName: string | null,
  locale: string,
  value: string,
): Promise<{ kind: 'ok' | 'error'; resource?: CreatedKeyResource; message?: string }> {
  const url = `/api/admin/templates/${encodeURIComponent(templateIdentifier)}/custom-translations`;
  try {
    const response = await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: buildAuthHeaders({ 'Content-Type': 'application/json' }),
      body: JSON.stringify({ layout_name: layoutName, locale, value }),
    });
    const body = await response.json().catch(() => null);
    if (!response.ok) {
      return { kind: 'error', message: (body as { message?: string })?.message ?? `HTTP ${response.status}` };
    }
    const data = (body as { data?: CreatedKeyResource })?.data;
    if (!data || typeof data.translation_key !== 'string') {
      return { kind: 'error', message: 'invalid response' };
    }
    return { kind: 'ok', resource: data };
  } catch (err: unknown) {
    return { kind: 'error', message: err instanceof Error ? err.message : 'network error' };
  }
}

/**
 * 기존 커스텀 키의 현재 로케일 값만 갱신 (`PUT /custom-translations/{id}`).
 *
 * 키 → id 해석을 위해 먼저 index 로 행을 조회한 뒤(translation_key 매칭) PUT 한다.
 * 낙관적 잠금: 조회 응답의 `lock_version` 을 `expected_lock_version` 으로 전달한다.
 *
 * @param templateIdentifier 템플릿 식별자
 * @param customKey 커스텀 키 (`custom.{layout}.{seq}`)
 * @param locale 편집 로케일
 * @param value 새 값
 * @return 갱신 결과
 */
export async function updateCustomKeyValue(
  templateIdentifier: string,
  customKey: string,
  locale: string,
  value: string,
): Promise<{ kind: 'ok' | 'error'; message?: string }> {
  // 키 → 행(id + values + lock_version) 해석.
  const row = await findCustomKeyRow(templateIdentifier, customKey);
  if (!row) {
    return { kind: 'error', message: 'custom key not found' };
  }
  const url = `/api/admin/templates/${encodeURIComponent(
    templateIdentifier,
  )}/custom-translations/${row.id}`;
  // 다른 로케일 값 보존 — 기존 values 위에 현재 로케일만 덮어쓴다.
  const nextValues = { ...(row.values ?? {}), [locale]: value };
  try {
    const response = await fetch(url, {
      method: 'PUT',
      credentials: 'same-origin',
      headers: buildAuthHeaders({ 'Content-Type': 'application/json' }),
      body: JSON.stringify({ values: nextValues, expected_lock_version: row.lock_version }),
    });
    const body = await response.json().catch(() => null);
    if (response.status === 409) {
      return { kind: 'error', message: 'concurrent_modification' };
    }
    if (!response.ok) {
      return { kind: 'error', message: (body as { message?: string })?.message ?? `HTTP ${response.status}` };
    }
    return { kind: 'ok' };
  } catch (err: unknown) {
    return { kind: 'error', message: err instanceof Error ? err.message : 'network error' };
  }
}

/**
 * 커스텀 키 행 1건 조회 (`GET /custom-translations` 목록에서 translation_key 매칭).
 *
 * @param templateIdentifier 템플릿 식별자
 * @param customKey 커스텀 키
 * @return 행 (id/values/lock_version) 또는 null
 */
export async function findCustomKeyRow(
  templateIdentifier: string,
  customKey: string,
): Promise<CreatedKeyResource | null> {
  const url = `/api/admin/templates/${encodeURIComponent(templateIdentifier)}/custom-translations`;
  try {
    const response = await fetch(url, {
      method: 'GET',
      credentials: 'same-origin',
      headers: buildAuthHeaders(),
    });
    if (!response.ok) return null;
    const body = await response.json().catch(() => null);
    const list = (body as { data?: CreatedKeyResource[] })?.data;
    if (!Array.isArray(list)) return null;
    return list.find((r) => r.translation_key === customKey) ?? null;
  } catch {
    return null;
  }
}

/**
 * 입력값을 TranslationEngine 싱글톤 사전에 낙관적으로 주입하고 캔버스를 즉시 재렌더한다.
 *
 * 인라인 편집 확정 직후 서버 lang 재fetch(`bustTranslationCache`)는 네트워크 왕복이 걸리므로, 그
 * 사이 캔버스가 옛 값(또는 raw 키)을 잠깐 노출한다. 본 함수가 입력값을 사전에 선반영하고 재렌더
 * 이벤트를 발화해, 사용자가 입력한 텍스트가 **확정 즉시** 보이게 한다. 이어지는 재fetch 가
 * 서버 권위 값(다른 로케일 포함)으로 사전을 원자 교체하면 최종 최신화가 끝난다(선반영 → 최신화 2단계).
 *
 * @param templateIdentifier 템플릿 식별자
 * @param locale 대상 로케일
 * @param translationKey 커스텀 키(`custom.*`, `$t:` 접두 없이)
 * @param value 입력값
 * @return 없음
 */
function seedTranslationOptimistically(
  templateIdentifier: string,
  locale: string,
  translationKey: string,
  value: string,
): void {
  try {
    const engine = TranslationEngine.getInstance();
    engine.setTranslationValue(templateIdentifier, locale, translationKey, value);
  } catch {
    // 선반영 실패는 무시 — 아래 bustTranslationCache 재fetch 가 최종 보정한다.
  }
  if (typeof window !== 'undefined') {
    try {
      window.dispatchEvent(
        new CustomEvent(EDITOR_TRANSLATIONS_REFRESHED_EVENT, {
          detail: { templateIdentifier, locale },
        }),
      );
    } catch {
      // 이벤트 발화 실패는 본 흐름에 영향 없음.
    }
  }
}

/** 캔버스 재렌더 신호 이벤트명 — PreviewCanvas 가 구독해 강제 재렌더한다. */
export const EDITOR_TRANSLATIONS_REFRESHED_EVENT = 'g7le:translations-refreshed';

/**
 * 키 CRUD 후 캔버스 즉시 반영 — 서버 lang 을 cache-bust 재fetch 한 뒤 캔버스를 재렌더해
 * 새 `$t:custom.*` 키가 raw 키가 아닌 값으로 표시되게 한다("서버 lang
 * 재fetch 후 반영"). 캔버스 DynamicRenderer 는 `TranslationEngine.getInstance()` 싱글톤
 * 사전으로 `$t:` 를 해석하므로(PreviewCanvas) 그 싱글톤을 재로드한다. 재로드 완료 후
 * `EDITOR_TRANSLATIONS_REFRESHED_EVENT` 를 발화해 PreviewCanvas 가 재렌더하도록 한다 —
 * 종전엔 비동기 재로드를 await/재렌더 없이 흘려 보내 직후 캔버스가 raw 키를 표시했다
 *
 *
 * @param templateIdentifier 템플릿 식별자
 * @param locale 재로드할 콘텐츠 로케일 (호출자의 활성 로케일 — window 전역 stale 회피)
 */
export async function bustTranslationCache(
  templateIdentifier: string,
  locale?: string,
): Promise<void> {
  if (typeof window === 'undefined') return;
  const loc =
    locale ??
    (window as unknown as { __g7EditorContentLocale?: string }).__g7EditorContentLocale;
  if (!loc) return;
  try {
    const engine = TranslationEngine.getInstance();
    // serveLanguage 는 `template.language.{id}.{locale}.v{cacheVersion}` 로 HTTP 캐시된다.
    // 커스텀 키 CRUD 시 서버가 `ext.cache_version` 을 새 timestamp 로 bump 하므로, 클라이언트도
    // 그 **새 버전**으로 요청해야 캐시 미스 → 병합(커스텀 키 포함) 재실행된 신선 응답을 받는다.
    // page-load 시 주입된 `G7Config.cache_version`(또는 0)으로 요청하면 옛 캐시 키에 갇혀
    // 방금 만든 키가 영영 안 보인다. config.json(비캐시)이
    // 최신 cache_version 을 노출하므로 그것을 읽어 엔진 버전을 갱신한 뒤 재로드한다.
    try {
      const cfg = await fetch(
        `/api/templates/${encodeURIComponent(templateIdentifier)}/config.json`,
        { headers: { Accept: 'application/json' }, credentials: 'same-origin' },
      ).then((r) => (r.ok ? r.json() : null));
      const v = cfg?.data?.cache_version ?? cfg?.cache_version;
      if (typeof v === 'number' && v > 0) {
        engine.setCacheVersion(v);
        const w = window as unknown as { G7Config?: { cache_version?: number } };
        if (w.G7Config) w.G7Config.cache_version = v; // 후속 fetch(레이아웃 등)도 신선 버전 사용
      }
    } catch {
      // config 조회 실패 — 아래 bustCache(_=ts) 만으로 폴백 시도.
    }
    await engine.loadTranslations(templateIdentifier, loc, '/api', true);
  } catch {
    // degrade — 재로드 실패해도 재렌더는 시도(다음 진입 시 최신 사전 로드).
  }
  try {
    window.dispatchEvent(
      new CustomEvent(EDITOR_TRANSLATIONS_REFRESHED_EVENT, { detail: { templateIdentifier, locale: loc } }),
    );
  } catch {
    // 이벤트 발화 실패는 본 흐름에 영향 없음.
  }
}
