// E2E: tests/Playwright/specs/layout-editor/prop-i18n-text-field.spec.ts (부록7 7-b — 미리보기/키 생성/펼침/저장 영속).
// e2e:allow(InlinePlaceholderField/PlainInsertDataButton)은
// contentEditable/합성 PointerEvent 칩 드래그 의존 → Playwright 부적합(정책). Chrome MCP 매트릭스
// (27케이스) + 단위(prop-i18n-text-field.test 칸자리 칩 4건)로 검증. 미리보기/펼침/저장은 위 spec.
/**
 * I18nTextField.tsx — 텍스트 입력 ↔ 동적 다국어(`$t:custom.*`) 공통 위젯
 *
 * 속성 패널 텍스트 propControl(안내 문구/라벨/도움말/alt/제목 등)·data_sources label_key 가
 * raw `$t:` 키를 그대로 노출하지 않고 인라인 편집과 동일한 `$t:custom.*` 모델로 동작하도록
 * 하는 공통 입력 위젯이다. 와이어프레임(부록7I 설계):
 *
 *  A. 평소(접힌) — 현재 콘텐츠 로케일 해석값 미리보기 input + 🌐 언어 편집 버튼.
 *     직접 타이핑 → 현재 로케일 값만 갱신(평문이면 키 자동 생성, blur 커밋).
 *  B. 펼침(🌐) — ko/en/ja 일괄 편집 폼(TranslationField 와 동일 로직). 미번역=회색 + 마크.
 *  C. 바인딩식(`{{...}}`) — "바인딩됨(코드 편집)" 읽기전용 배지(덮어쓰기 차단, 부록6 정합).
 *
 * 값 모델: 위젯이 다루는 값은 **항상 문자열**(평문 또는 `$t:custom.*` 토큰). 평문 첫 입력 시
 * `useCustomTranslation.commitText` 가 키를 생성하고 그 토큰을 `onChange` 로 흘린다. 따라서
 * 호출자(ControlRenderer→propValue / DataSourcesPanel→label_key)는 토큰 문자열을 그대로
 * 값에 기록하면 된다(별도 키 모델 없음 — 단일 SSoT).
 *
 * 편집기 코어 컴포넌트 — `g7le-*` + 인라인 스타일만(feedback_layout_editor_no_css_lib_dependency).
 *
 * @since engine-v1.50.0
 */

import React, { useCallback, useEffect, useRef, useState } from 'react';
import { localeDisplayLabel } from '../LocaleSwitcher';
import { useLayoutEditor } from '../../LayoutEditorContext';
import { useLayoutDocumentContext } from '../../LayoutDocumentContext';
import { useCustomTranslation } from '../../hooks/useCustomTranslation';
import { getPendingValue } from '../../hooks/pendingCustomTranslations';
import { EDITOR_TRANSLATIONS_REFRESHED_EVENT } from '../../hooks/useInlineEdit';
import { trackEditorI18n } from '../../devtools/editorTrackers';
import { TranslationField, deriveParamLabelsFromText } from './TranslationField';
import { PlaceholderChipInput } from './PlaceholderChipInput';
import { InlineBindingScalarPicker } from './InlineBindingScalarPicker';
import {
  findCustomKeyRow,
  putSingleLocaleKeyValue,
  keyifyWithNewBinding,
  insertBindingIntoParamKey,
  disconnectParamAllLocales,
} from './inlineBindingApi';
import { removeParamBinding, bindingChipLabel, hasSettingsRef, toValueChipTokens } from '../../spec/inlineBindingUtils';
import { parseBindingExpression, buildBindingExpression } from '../../spec/bindingCandidates';
import type { BindingCandidate } from '../../spec/bindingCandidates';
import {
  parseExpressionValue,
  serializeValueNode,
  hasDecomposableExpressionSegment,
  previewSegments,
  seedExpressionFromPlain,
  reduceExpressionToPlain,
  type ValueNode,
  type PreviewToken,
} from '../../spec/expressionValueTree';
import { ConditionalValueEditor } from '../page-settings/ConditionalValueEditor';
import { SegmentedValueEditor } from '../page-settings/SegmentedValueEditor';
import { FloatingDropdown } from '../shared/FloatingDropdown';

export interface I18nTextFieldProps {
  /** 현재 값 — 평문 또는 `$t:custom.*`/`$t:...` 토큰 또는 `{{...}}` 바인딩식 */
  value: string | null | undefined;
  /** 값 변경 — 평문 입력 시 키 생성 후 토큰 문자열을, 키 갱신 시 동일 토큰을 전달 */
  onChange: (value: string | undefined) => void;
  /** 다국어 해석 t(라벨/안내 문구용) */
  t: (key: string, params?: Record<string, string | number>) => string;
  /** 접힌 미리보기 input placeholder */
  placeholder?: string;
  /** data-testid 접두(위젯별 격리) */
  testidPrefix?: string;
  /** 활성 로케일 목록 주입(테스트용). 미전달 시 G7Config 에서 읽음. */
  locales?: string[];
  /**
   * 데이터 연결 검색 후보 풀 — 칸자리 칩 입력의 `+데이터` 삽입(키화)에
   * 쓴다. ControlRenderer/nodeEditor 가 PropertyEditorModal 의 bindingCandidates 를 흘려보낸다.
   * 미전달 시 `+데이터` 피커는 빈 후보(디그레이드) — 칩 표시/평문 키화는 후보 없이도 동작.
   */
  candidates?: BindingCandidate[];
  /**
   * 표현식+다국어(F/G) 값을 분해 트리(ConditionalValueEditor)로 편집할지. 기본 false —
   * 종전대로 `{{...}}` 는 "바인딩됨(코드 편집)" 읽기전용 배지. "제목/설명 먼저
   * 적용 후 넓혀가기" 에 따라 **opt-in**: 현재 MetaForm(제목/설명)만 켠다. 검증 후 전 propControl/
   * SEO 값칸으로 확대. 끄면 회귀 0(기존 읽기전용 경로 그대로).
   */
  enableExpressionTree?: boolean;
  /**
   * 표현식 분해 편집기를 **접힌 미리보기 + [수정]** 으로 띄울지 ("진입 시 정보
   * 과다 방지" 2026-06-13). 기본 false — 펼친 빌더를 바로 렌더. **최상위 진입점**(MetaForm 제목/
   * 설명)만 true 로 접힘을 켠다. 세그먼트 카드·트리 리프 등 **중첩 I18nTextField** 는 false(이미
   * 펼친 빌더 안이라 또 접으면 이중 접힘). enableExpressionTree 와 함께여야 의미.
   */
  expressionTreeCollapsible?: boolean;
  /**
   * 표현식 분해 트리에 [원본 식 보기] 토글을 그릴지 ("조각 편집기당 하나"
   * 2026-06-13). 기본 true. SegmentedValueEditor 의 조각 I18nTextField 는 false — 세그먼트 편집기가
   * 전체 식 하나의 토글을 통합 제공한다(카드마다 중복 방지).
   */
  expressionSourceToggle?: boolean;
}

export function I18nTextField({
  value,
  onChange,
  t,
  placeholder,
  testidPrefix = 'g7le-i18n-text-field',
  locales,
  candidates,
  enableExpressionTree = false,
  expressionTreeCollapsible = false,
  expressionSourceToggle = true,
}: I18nTextFieldProps): React.ReactElement {
  const { locale, templateIdentifier, commitText, classify, translate } = useCustomTranslation();

  // 접힌 미리보기 입력 버퍼(blur 전까지 커밋 미발화). null = 미편집(해석값 표시).
  const [draft, setDraft] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [expanded, setExpanded] = useState(false);
  // 표현식 분해 편집기 펼침 여부.
  // 기본 false(접힘): raw 표현식 미리보기(키 해석·데이터 칩, 읽기전용). [수정] 클릭 시 빌더 펼침.
  const [treeExpanded, setTreeExpanded] = useState(false);
  // [일반 이름으로] 되돌리기 확인 대화 표시 여부("첫 결과를 뽑아 확인 후 적용"
  // 2026-06-13). 표현식→일반은 나머지 분기/데이터칩 소실이 따르는 비가역 작업이라 확인 게이트.
  const [revertConfirm, setRevertConfirm] = useState(false);
  // 설정 참조($*_settings:) 칩 시각화 ↔ 평문 편집 토글 (I18nTextField 가
  // 설정참조를 평문으로 떨궈 raw 노출되던 결함 해소). 기본 false(칩 시각화), [✎ 수정] 클릭 시 평문 input.
  const [settingsEditing, setSettingsEditing] = useState(false);
  // 번역 사전 갱신 시 재렌더 — 평문 미리보기의 displayValue 는 classify→translate(key)
  // (=TranslationEngine 값)에서 온다. 칩 X 해제로 키 값의 `{pN}` 이 정리돼 엔진이 갱신돼도, 이 컴포넌트가
  // 재렌더 안 되면 cls 가 stale 이라 미리보기에 raw `{pN}` 이 남는다. REFRESHED 이벤트에 재렌더해 classify
  // 가 엔진의 정리된 값을 다시 읽게 한다(InlinePlaceholderField 칩 분기의 구독과 짝 — 평문 분기 보강).
  const [, forceTick] = useState(0);
  useEffect(() => {
    if (typeof window === 'undefined') return;
    const handler = (e: Event): void => {
      const detail = (e as CustomEvent).detail as { templateIdentifier?: string } | undefined;
      if (detail?.templateIdentifier && detail.templateIdentifier !== templateIdentifier) return;
      forceTick((n) => n + 1);
    };
    window.addEventListener(EDITOR_TRANSLATIONS_REFRESHED_EVENT, handler);
    return () => window.removeEventListener(EDITOR_TRANSLATIONS_REFRESHED_EVENT, handler);
  }, [templateIdentifier]);

  const cls = classify(value);

  // 콘텐츠 로케일/값 변경 시 미편집 버퍼 초기화(새 해석값 노출).
  // 주의: `expanded` 는 여기서 초기화하지 않는다 — 같은 노드 안에서 평문→키 생성 시 value 가
  // 바뀌는데(plain→token) 그때 펼침을 닫으면 방금 만든 키의 ko/en/ja 폼이 사라진다. 노드/컨트롤
  // 전환 시의 stale 펼침/락 누출은 모달이 노드 정체성으로 위젯을 remount 해(아래 ControlRenderer
  // 결선의 nodeKey) 차단한다.
  useEffect(() => {
    setDraft(null);
    // 노드/컨트롤 전환(value 변경) 시 설정참조 평문 편집 모드도 초기화 — 이전 노드의 편집 상태가
    // 다른 노드로 이월돼 칩이 아닌 평문으로 잘못 열리는 stale 차단.
    setSettingsEditing(false);
  }, [locale, value]);

  const previewValue = draft !== null ? draft : cls.displayValue;

  const commitPreview = useCallback(async (): Promise<void> => {
    if (draft === null) return; // 편집 시작 안 함
    if (draft === cls.displayValue) {
      setDraft(null);
      return;
    }
    setBusy(true);
    try {
      const result = await commitText(value, draft);
      if (result.kind === 'created' && result.token) {
        // 평문 → 키 생성: prop/label 값에 토큰 기록.
        onChange(result.token);
        trackEditorI18n({
          op: 'prop_i18n_create_key',
          sourceState: 'plain_text',
          translationKey: result.customKey,
          toLocale: locale,
          valueLength: draft.length,
          timestamp: Date.now(),
        });
      } else if (result.kind === 'updated') {
        // 기존 커스텀 키 — 값(토큰)은 유지, 현재 로케일 값만 갱신됨.
        trackEditorI18n({
          op: 'prop_i18n_update_value',
          sourceState: 'custom_key',
          translationKey: result.customKey,
          toLocale: locale,
          valueLength: draft.length,
          timestamp: Date.now(),
        });
      }
    } finally {
      setBusy(false);
      setDraft(null);
    }
  }, [draft, cls.displayValue, commitText, value, onChange, locale]);

  // 펼침([번역]탭 통합) 칩 X = 데이터 연결 '해제'. node.text `|pN=` 제거(onChange)
  // + 전 로케일 `{pN}` 제거 + 캔버스/칸자리 동기화. 칸자리 InlinePlaceholderField.handleRemoveChip 와
  // 동일 동작(펼침/칸자리 어디서 해제하든 같은 결과). customKey 가 있을 때만 의미(param 키 펼침).
  const handleExpandRemoveParam = useCallback((paramName: string): void => {
    if (typeof value !== 'string') return;
    onChange(removeParamBinding(value, paramName));
    if (cls.customKey) {
      void disconnectParamAllLocales(templateIdentifier, cls.customKey, paramName, locale);
    }
  }, [value, onChange, cls.customKey, templateIdentifier, locale]);

  // C. 바인딩식(`{{...}}`) —표현식+다국어(F/G)면 raw 노출 말고 **분해 트리**로 편집.
  //    파서가 친화적으로 푸는 식(조건분기/폴백/이어붙이기, F/G 94%)이면 ConditionalValueEditor 가
  //    조건 노드 + 분기별 리프(=I18nTextField 재귀)를 그린다. 트리 변경은 serializeValueNode 로 새
  //    node.text 문자열을 만들어 onChange. 못 푸는 식(함수/산술 등)은 현행 읽기전용 배지(손상 0).
  if (cls.binding) {
    // opt-in(enableExpressionTree) 일 때만 분해 시도 — "제목/설명 먼저" 점진 적용.
    if (enableExpressionTree && typeof value === 'string') {
      const isSegmented = hasDecomposableExpressionSegment(value);
      const parsed = parseExpressionValue(value);
      // 단일 순수 바인딩(`{{src.path}}`, 분해할 표현식 구조 없음)은 표현식 트리 대상이 아니다 —
      // 아래 ③ BindingDataField(데이터 칩 + [데이터 바꾸기])로 흘린다(설명 칸과 동일). [일반 이름으로]
      // 되돌려 단일 데이터가 된 값이 최상위 collapsible 진입점에서 트리(SegmentedValueEditor)로 펼쳐지던
      // 결함 차단. 트리는 조건/폴백/이어붙이기 등 분해 가능한 식에만.
      const isPureBinding = !isSegmented && parseBindingExpression(value) !== null;
      const isDecomposable = (isSegmented || parsed.decomposed) && !isPureBinding;
      // "기본 접힌 미리보기 + [수정]"(2026-06-13) — 진입 시 정보 과다 방지. **최상위 진입점**
      // (expressionTreeCollapsible)만 접힘. 세그먼트 카드·트리 리프 등 중첩 위젯은 접지 않음(이미
      // 펼친 빌더 안 — 또 접으면 이중 접힘). 접힘이면 미리보기+[수정], 펼침이면 빌더.
      if (isDecomposable && expressionTreeCollapsible && !treeExpanded) {
        return (
          <ExpressionCollapsedPreview
            value={value}
            t={t}
            translate={translate}
            onEdit={() => setTreeExpanded(true)}
            testidPrefix={`${testidPrefix}-preview`}
          />
        );
      }
      // 펼친 빌더 선택:
      //  · **최상위 진입점**(expressionTreeCollapsible) → 단일식이든 다중이든 **항상 SegmentedValueEditor**.
      //    그래야 일반→표현식 전환 직후에도 [+고정글자]/[+조건분기]/[+데이터] 조각 추가가 가능하다
      //  (종전엔 단일식이 ConditionalValueEditor 로 가서 조각 추가 버튼이 없었다).
      //    SegmentedValueEditor 가 단일식을 expression 조각 1개로 분해하고, 그 조각 내부 I18nTextField
      //    (중첩, collapsible=false)는 ConditionalValueEditor(분기 트리)로 재귀한다.
      //  · **중첩 위젯**(세그먼트 카드 내부, collapsible=false) → 단일식 → ConditionalValueEditor 직접
      //    (또 SegmentedValueEditor 면 무한 중첩). 다중 세그먼트면 SegmentedValueEditor(드묾 — 카드 value
      //    는 단일이 원칙이나 방어적으로 분해).
      // 최상위(collapsible)거나 다중 세그먼트면 조각 편집기. 그 외(중첩 단일식)는 분기 트리.
      // 단, **분해 가능한 식일 때만** 빌더를 그린다 — 단일 순수 바인딩(isDecomposable=false)은
      // 빌더를 건너뛰고 아래 ③ BindingDataField(데이터 칩 + [데이터 바꾸기])로 흘린다.
      const useSegmented = isDecomposable && (expressionTreeCollapsible || isSegmented);
      const builder = useSegmented ? (
        <SegmentedValueEditor
          value={value}
          onChange={(next) => onChange(next)}
          t={t}
          candidates={candidates}
          testidPrefix={`${testidPrefix}-seg`}
        />
      ) : parsed.decomposed ? (
        <ConditionalValueEditor
          node={parsed.node}
          onChange={(next: ValueNode) => onChange(serializeValueNode(next, false))}
          t={t}
          candidates={candidates}
          testidPrefix={`${testidPrefix}-tree`}
          showSourceToggle={expressionSourceToggle}
        />
      ) : null;
      if (builder) {
        if (!expressionTreeCollapsible) return builder;
        // 되돌리기로 남길 값(첫 결과 분기) — 확인 대화 미리보기에 표시.
        const revertTo = reduceExpressionToPlain(value);
        // B1(2026-06-13) — 미리보기는 raw `$t:` 키가 아니라 **다국어 해석값**으로 표시한다
        // (어떤 글자가 남는지 사용자가 알 수 있게). previewSegments 로 `$t:` 키→평문, 데이터칩→라벨.
        const revertTokens: PreviewToken[] = revertTo
          ? previewSegments(
              revertTo,
              (key) => {
                const r = translate(key);
                return r && r !== key && !r.startsWith('$t:') ? r : '';
              },
              (binding) => bindingChipLabel(binding),
            )
          : [];
        return (
          <div data-testid={`${testidPrefix}-builder`} style={{ display: 'flex', flexDirection: 'column', gap: 6, width: '100%', minWidth: 0 }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 6 }}>
              {/* [일반 이름으로] — 복잡 표현식을 일반 다국어 이름으로 되돌림("복잡한
                  표현식을 다 제거하고 일반 이름으로 돌아갈 수 있어야" 2026-06-13). 클릭 → 확인 대화. */}
              <button
                type="button"
                data-testid={`${testidPrefix}-to-plain`}
                onClick={() => setRevertConfirm(true)}
                style={revertBtn}
                title={t('layout_editor.value_tree.to_plain')}
                aria-label={t('layout_editor.value_tree.to_plain')}
              >
                ↩ {t('layout_editor.value_tree.to_plain')}
              </button>
              <button
                type="button"
                data-testid={`${testidPrefix}-builder-collapse`}
                onClick={() => setTreeExpanded(false)}
                style={collapseBtn}
                title={t('layout_editor.value_tree.collapse')}
                aria-label={t('layout_editor.value_tree.collapse')}
              >
                ▴ {t('layout_editor.value_tree.collapse')}
              </button>
            </div>
            {/* 되돌리기 확인 대화 — 첫 결과 미리보기 + 나머지 분기 삭제 경고(비가역). */}
            {revertConfirm && (
              <div data-testid={`${testidPrefix}-to-plain-confirm`} style={revertConfirmBox}>
                <p style={revertConfirmLead}>
                  {t('layout_editor.value_tree.to_plain_lead')}
                </p>
                <div style={revertConfirmPreview}>
                  {revertTokens.length === 0 ? (
                    <span style={{ color: '#94a3b8' }}>{t('layout_editor.prop_i18n.placeholder')}</span>
                  ) : (
                    revertTokens.map((tok, i) =>
                      tok.kind === 'chip' ? (
                        <span key={i} style={previewChip}>🔗 {tok.text}</span>
                      ) : (
                        <span key={i} style={tok.kind === 'ellipsis' ? previewEllipsis : undefined}>{tok.text}</span>
                      ),
                    )
                  )}
                </div>
                <p style={revertConfirmWarn}>⚠ {t('layout_editor.value_tree.to_plain_warn')}</p>
                <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 6 }}>
                  <button
                    type="button"
                    data-testid={`${testidPrefix}-to-plain-confirm-cancel`}
                    onClick={() => setRevertConfirm(false)}
                    style={collapseBtn}
                  >
                    {t('layout_editor.value_tree.to_plain_cancel')}
                  </button>
                  <button
                    type="button"
                    data-testid={`${testidPrefix}-to-plain-confirm-ok`}
                    onClick={() => {
                      setRevertConfirm(false);
                      setTreeExpanded(false);
                      onChange(reduceExpressionToPlain(value) || undefined);
                    }}
                    style={revertConfirmOk}
                  >
                    {t('layout_editor.value_tree.to_plain_ok')}
                  </button>
                </div>
              </div>
            )}
            {builder}
          </div>
        );
      }
    }
    // ③ 단일 경로 바인딩(D, `{{x.y ?? ''}}`) + 후보 풀 → "데이터 바꾸기" 피커( "바인딩됨
    //    부분도 데이터를 고를 수 있어야"). 기존 후보 피커(InlineBindingScalarPicker)+buildBindingExpression
    //    재사용 — 신규 부품 0. 복잡식(함수/산술)·후보 없음·opt-out 은 종전 읽기전용 배지(손상 0).
    if (
      enableExpressionTree &&
      typeof value === 'string' &&
      candidates &&
      candidates.length > 0 &&
      parseBindingExpression(value) !== null
    ) {
      return (
        <BindingDataField
          value={value}
          onChange={onChange}
          t={t}
          candidates={candidates}
          testidPrefix={`${testidPrefix}-data`}
        />
      );
    }
    return (
      <div data-testid={`${testidPrefix}-binding`} style={bindingWrap}>
        <code style={bindingCode}>{typeof value === 'string' ? value : ''}</code>
        <span style={bindingBadge}>🔗 {t('layout_editor.prop_i18n.bound_code_only')}</span>
      </div>
    );
  }

  // C-2. 설정 참조($core_settings:/$module_settings:/$plugin_settings:) — `{{}}` 바인딩이 아니라
  //   설정값 직접 참조라 classify 가 binding 으로 잡지 못해 종전엔 raw `$core_settings:general.site_name`
  //  가 평문 input 에 그대로 노출됐다. 설정 참조는 다국어 키화 대상이 아니므로
  //   칩+평문 시각화(읽기) + [✎ 수정](평문 편집 복귀)으로 보여 준다. DataChipValueInput 의 혼합 분기와
  //   동일 UX(공용 toValueChipTokens SSoT). 편집 중(settingsEditing)이면 아래 평문 경로로 흘러 직접 편집.
  if (!settingsEditing && typeof value === 'string' && hasSettingsRef(value)) {
    const tokens = toValueChipTokens(value);
    return (
      <div data-testid={`${testidPrefix}-settings-ref`} style={settingsRefWrap}>
        <div data-testid={`${testidPrefix}-settings-ref-chips`} style={settingsRefChips}>
          {tokens.map((tok, i) =>
            tok.kind === 'chip' ? (
              <span key={i} style={settingsRefChip}>🔗 {tok.label}</span>
            ) : (
              <span key={i}>{tok.label}</span>
            ),
          )}
        </div>
        <button
          type="button"
          data-testid={`${testidPrefix}-settings-ref-edit`}
          onClick={() => setSettingsEditing(true)}
          style={langBtn}
          title={t('layout_editor.value_tree.edit')}
          aria-label={t('layout_editor.value_tree.edit')}
        >
          ✎ {t('layout_editor.value_tree.edit')}
        </button>
      </div>
    );
  }

  // 🌐 언어별 편집 토글 — 평문/칩 모드 공용. 칩 모드는 InlinePlaceholderField 액션 행 끝에 넘겨
  // [+데이터][ƒx][🌐] 한 행 정렬을 보장하고, 평문 모드는 previewRow 끝에 직접 둔다(동일 버튼/동작).
  const langToggleButton = (
    <button
      type="button"
      data-testid={`${testidPrefix}-toggle`}
      // 버튼 폭 절약: 로케일 라벨 텍스트 제거하고 아이콘만(🌐 + 상태 글리프).
      // 현재 로케일은 title 툴팁으로 접근(localeDisplayLabel 포함), aria-label 로 스크린리더 보강.
      title={`${t('layout_editor.prop_i18n.edit_languages')} (${localeDisplayLabel(locale, t)})`}
      aria-label={`${t('layout_editor.prop_i18n.edit_languages')} (${localeDisplayLabel(locale, t)})`}
      aria-expanded={expanded}
      onClick={() => setExpanded((v) => !v)}
      style={langBtn}
    >
      🌐 {cls.customKey ? (expanded ? '⌃' : '⌄') : '+'}
    </button>
  );

  return (
    <div data-testid={testidPrefix} style={{ display: 'flex', flexDirection: 'column', gap: 4, width: '100%' }}>
      {/* A. 칸자리 — 데이터(칩) 보유 키는 **항상** 칩 입력기를 상시 렌더(편집
          진입 시에만 칩 전환 ❌, 인라인 캔버스 편집과 동일 경험). 순수 평문일 때만 미리보기 input.
 어느 경우든 `+데이터`(키화) 입구를 제공한다. */}
      <div style={previewRow}>
        {cls.paramKey && cls.customKey ? (
          // 데이터 든 param 키 — 칩 합성 위젯(현재 로케일 키 값). `+데이터`=insertBindingIntoParamKey.
          // ƒx 표현식 승격은 평문 모드와 동일 동작/조건으로 전달(데이터 칩 모드도 표현식 승격 가능,
          // 데이터 칩 모드 버튼 구성을 평문 모드와 일관성 맞춤).
          // 데이터 칩 모드 — 칩칸 + [+데이터][ƒx][🌐] 액션 버튼을 한 행에 통일(평문 모드와 동일
          // 위치/정렬 "데이터 칩 추가 후 버튼이 같은 줄에 안 나옴"). 🌐 토글은
          // 평문 모드와 같은 버튼을 액션 행 끝에 넘겨 한 컨테이너에서 정렬을 보장한다.
          <InlinePlaceholderField
            customKey={cls.customKey}
            value={typeof value === 'string' ? value : ''}
            onChange={onChange}
            t={t}
            candidates={candidates}
            testidPrefix={testidPrefix}
            showExprButton={enableExpressionTree && expressionTreeCollapsible}
            onToExpression={() => {
              setTreeExpanded(true);
              onChange(seedExpressionFromPlain(typeof value === 'string' ? value : ''));
            }}
            langToggle={langToggleButton}
          />
        ) : (
          <>
            <input
              type="text"
              data-testid={`${testidPrefix}-preview`}
              value={previewValue}
              placeholder={placeholder ?? t('layout_editor.prop_i18n.placeholder')}
              onChange={(e) => setDraft(e.target.value)}
              onBlur={() => {
                void commitPreview();
                // 설정 참조($*_settings:) 평문 편집 종료(focusout) → 칩 시각화로 복귀.
                if (settingsEditing) setSettingsEditing(false);
              }}
              disabled={busy}
              style={previewInput}
            />
            {/* `+데이터` — 평문/평문 custom 키에 데이터 칩 삽입(키화). param 키로 전이된 뒤엔 위
                InlinePlaceholderField 분기가 담당한다. 후보 풀(candidates) 미전달 시 숨김(디그레이드). */}
            {candidates && candidates.length > 0 && (
              <PlainInsertDataButton
                value={typeof value === 'string' ? value : ''}
                customKey={cls.customKey}
                onChange={onChange}
                t={t}
                candidates={candidates}
                testidPrefix={testidPrefix}
              />
            )}
            {/* `+표현식` — 일반 이름(평문/단일 다국어키)을 **조건 분기 표현식**으로 승격(
 후속, "일반 이름에도 언제든 표현식 부여" 2026-06-13). 현재 값을 then 분기로 seed
                (seedExpressionFromPlain) → onChange(`{{route.id ? '값' : ''}}`) → 다음 렌더부터 cls.binding
                이 true 라 분해 빌더가 열린다. treeExpanded 를 켜 곧바로 펼친 빌더(접힌 미리보기 건너뜀)로
                진입(방금 만든 빈 거짓 분기를 바로 편집). **최상위 진입점**(enableExpressionTree +
                expressionTreeCollapsible)만 노출 — 중첩 리프엔 불필요(이미 표현식 안). */}
            {enableExpressionTree && expressionTreeCollapsible && (
              <button
                type="button"
                data-testid={`${testidPrefix}-to-expr`}
                onClick={() => {
                  setTreeExpanded(true);
                  onChange(seedExpressionFromPlain(typeof value === 'string' ? value : ''));
                }}
                title={t('layout_editor.value_tree.to_expression')}
                aria-label={t('layout_editor.value_tree.to_expression')}
                style={iconBtn}
              >
                ƒx
              </button>
            )}
            {langToggleButton}
          </>
        )}
      </div>

      {/* B. 펼침 — [번역] 탭과 **동일 컴포넌트**(TranslationField) 공유.
 평문/키 생성 전(customKey null)에는 "키 먼저 생성" 힌트를 유지하고, 키가 있으면
          ko/en/ja 일괄 편집 폼(param 키면 칩 합성 위젯)을 그대로 보여 준다(펼침=번역탭 SSoT). */}
      {expanded && (
        cls.customKey ? (
          <TranslationField
            // 키별 재마운트 — customKey 가 바뀌면(노드 전환/키 생성) load(id/lock_version) state 가
            // 절대 이월되지 않게 한다(stale lock → 409/오저장 차단).
            key={cls.customKey}
            customKey={cls.customKey}
            templateIdentifier={templateIdentifier}
            t={t}
            // 칩 친화 라벨 — 현재 값(param 키 텍스트)의 `|pN={{expr}}` 에서 도출(node 비의존).
            paramLabels={deriveParamLabelsFromText(value)}
            locales={locales}
            onRemoveParam={handleExpandRemoveParam}
          />
        ) : (
          <div data-testid={`${testidPrefix}-expand-hint`} style={expandHint}>
            {t('layout_editor.prop_i18n.create_first')}
          </div>
        )
      )}
    </div>
  );
}

/**
 * 칸자리 칩 입력 — param 키(`$t:custom.X|pN={{}}`)의 현재 로케일 키 값(`{pN}` 자리표시 문장)을
 * 칩+평문으로 상시 렌더한다. 인라인 캔버스
 * 편집(`InlineParamChipEditor`)의 속성탭판 — 오버레이/툴바 없이 칸 안에 직접 렌더한다.
 *
 *  - 키 값 fetch(서버 row + 저장-지연 pending 머지) → `PlaceholderChipInput` 렌더.
 *  - 칩 드래그 이동/평문 편집 → `putSingleLocaleKeyValue`(현재 로케일만, 어순 독립) 버퍼 기록.
 *  - `+데이터` → 후보 피커 → 커서 위치에 `insertBindingIntoParamKey`(키 승계) → 새 node text 를
 *    `onChange` 로 prop 값에 기록(param 추가).
 *  - 칸자리↔펼침/번역탭 동기화 — `EDITOR_TRANSLATIONS_REFRESHED_EVENT` 구독으로 pending 재읽기.
 *
 * @since engine-v1.50.0
 */
function InlinePlaceholderField({
  customKey,
  value,
  onChange,
  t,
  candidates,
  testidPrefix,
  showExprButton = false,
  onToExpression,
  langToggle,
}: {
  customKey: string;
  /** 현재 node/prop 값(param 키 텍스트 `$t:custom.X|pN={{}}`) */
  value: string;
  onChange: (value: string | undefined) => void;
  t: I18nTextFieldProps['t'];
  candidates?: BindingCandidate[];
  testidPrefix: string;
  /** ƒx 표현식 승격 버튼 노출 여부(평문 모드와 동일 조건). 데이터 칩 모드도 표현식으로 승격 가능. */
  showExprButton?: boolean;
  /** ƒx 클릭 — 현재 값(문장+칩)을 조건 분기 표현식으로 승격(부모가 seed + 트리 펼침 배선). */
  onToExpression?: () => void;
  /** 🌐 언어별 편집 토글 버튼 — 액션 행 끝에 두어 [+데이터][ƒx][🌐] 를 한 행에 정렬(평문 모드와 동일). */
  langToggle?: React.ReactNode;
}): React.ReactElement {
  const { state } = useLayoutEditor();
  const templateIdentifier = state.templateIdentifier;
  const locale = state.locale;
  const docCtx = useLayoutDocumentContext();

  const [keyValue, setKeyValue] = useState<string | null>(null); // 현재 로케일 키 값(자리표시 문장)
  const baselineRef = useRef<string>('');
  const chipFieldRef = useRef<HTMLDivElement | null>(null); // '+데이터' 부유 피커 anchor(칩 입력칸 컨테이너)
  const plusRef = useRef<HTMLButtonElement | null>(null); // 외부 '+데이터' 버튼 anchor(액션 행)
  const caretGetterRef = useRef<(() => number) | null>(null); // 칩칸 현재 caret 절대 위치 읽기(커서 삽입 보존)
  const [insertAt, setInsertAt] = useState<number | null>(null);
  const [inserting, setInserting] = useState(false);

  const paramLabels = deriveParamLabelsFromText(value);

  // 현재 로케일 키 값 로드 — 저장-지연 버퍼 우선, 없으면 서버 행.
  const loadValue = useCallback(async (): Promise<void> => {
    const pending = getPendingValue(customKey, locale);
    if (pending !== undefined) {
      baselineRef.current = pending;
      setKeyValue(pending);
      return;
    }
    const row = await findCustomKeyRow(templateIdentifier, customKey);
    const v = row?.values?.[locale] ?? '';
    baselineRef.current = v;
    setKeyValue(v);
  }, [customKey, templateIdentifier, locale]);

  useEffect(() => {
    let cancelled = false;
    void (async () => {
      const pending = getPendingValue(customKey, locale);
      if (pending !== undefined) {
        if (!cancelled) { baselineRef.current = pending; setKeyValue(pending); }
        return;
      }
      const row = await findCustomKeyRow(templateIdentifier, customKey);
      if (cancelled) return;
      const v = row?.values?.[locale] ?? '';
      baselineRef.current = v;
      setKeyValue(v);
    })();
    return () => { cancelled = true; };
  }, [customKey, templateIdentifier, locale]);

  // 칸자리↔펼침/번역탭 동기화 — 다른 위젯이 같은 키 pending 을 바꾸면 재읽기.
  useEffect(() => {
    if (typeof window === 'undefined') return;
    const handler = (e: Event): void => {
      const detail = (e as CustomEvent).detail as { templateIdentifier?: string } | undefined;
      if (detail?.templateIdentifier && detail.templateIdentifier !== templateIdentifier) return;
      const pending = getPendingValue(customKey, locale);
      if (pending !== undefined && pending !== keyValue) {
        baselineRef.current = pending;
        setKeyValue(pending);
      }
    };
    window.addEventListener(EDITOR_TRANSLATIONS_REFRESHED_EVENT, handler);
    return () => window.removeEventListener(EDITOR_TRANSLATIONS_REFRESHED_EVENT, handler);
  }, [customKey, templateIdentifier, locale, keyValue]);

  // 평문 편집/칩 이동 — 현재 로케일 키 값만 버퍼 기록(로케일 독립). node.text(param 구조)는 불변.
  const handleChipChange = useCallback((next: string): void => {
    setKeyValue(next);
    void putSingleLocaleKeyValue(templateIdentifier, customKey, locale, next).then(() => {
      docCtx?.markDirty?.();
    });
  }, [templateIdentifier, customKey, locale, docCtx]);

  // 칩 우측 X = 데이터 연결 '해제'. node.text 의 `|pN=` 제거 →
  // onChange(레이아웃 JSON 반영) + 전 로케일 키 값 `{pN}` 제거 + 캔버스/다국어/펼침 즉시 동기화.
  // ([속성]탭 InlineBindingSection.disconnectParam 과 동일 동작 — 칩 위젯 내 X 로 표면화.)
  const handleRemoveChip = useCallback((paramName: string): void => {
    onChange(removeParamBinding(value, paramName)); // node.text |pN= 제거.
    void disconnectParamAllLocales(templateIdentifier, customKey, paramName, locale).then(() => {
      void loadValue(); // 전 로케일 제거 후 현재 로케일 칩 값 재읽기(즉시 반영).
      docCtx?.markDirty?.();
    });
  }, [value, onChange, templateIdentifier, customKey, locale, loadValue, docCtx]);

  // `+데이터` 커서 위치 → 피커 → insertBindingIntoParamKey(키 승계). 새 node text 를 onChange.
  const handlePick = useCallback(async (c: BindingCandidate): Promise<void> => {
    if (insertAt === null || inserting) return;
    setInserting(true);
    try {
      // 미커밋 평문/이동을 먼저 버퍼에 반영(소실 방지 — S9-N2 와 동일 규율).
      if (keyValue !== null && keyValue !== baselineRef.current) {
        await putSingleLocaleKeyValue(templateIdentifier, customKey, locale, keyValue);
        baselineRef.current = keyValue;
      }
      const res = await insertBindingIntoParamKey(
        templateIdentifier, locale, value, insertAt, c.sourceId, c.path, 'scalar',
      );
      if (res.kind === 'ok') {
        onChange(res.text); // node/prop 값에 새 param 부착된 텍스트 기록.
        await loadValue(); // 버퍼에 갱신된 키 값 재읽기(칩 즉시 반영).
        docCtx?.markDirty?.();
      }
    } finally {
      setInserting(false);
      setInsertAt(null);
    }
  }, [insertAt, inserting, keyValue, value, templateIdentifier, customKey, locale, onChange, loadValue, docCtx]);

  const hasCandidates = !!candidates && candidates.length > 0;
  return (
    // 데이터 칩 모드의 액션 버튼을 평문 모드와 동일하게 입력칸 우측 한 줄로 통일(
    // "데이터를 선택하니 데이터 버튼이 아래로 옮겨가고 표현식 버튼이 사라짐 — 유사 UI 와 일관성").
    // 칩칸(flex:1) + [+데이터][ƒx] 가로 배치. 🌐 토글은 상위 previewRow 공통.
    <div data-testid={`${testidPrefix}-chipfield-wrap`} style={{ flex: 1, minWidth: 0, display: 'flex', alignItems: 'center', gap: 4 }}>
      <div ref={chipFieldRef} data-testid={`${testidPrefix}-chipfield`} style={{ flex: 1, minWidth: 0 }}>
        {keyValue === null ? (
          <div data-testid={`${testidPrefix}-chipfield-loading`} style={{ fontSize: 11, color: '#94a3b8', padding: '4px 0' }}>
            {t('layout_editor.translation.loading')}
          </div>
        ) : (
          <PlaceholderChipInput
            value={keyValue}
            onChange={handleChipChange}
            t={t}
            paramLabels={paramLabels}
            testIdSuffix={`${testidPrefix}-field`}
            onRequestInsert={hasCandidates ? (idx) => setInsertAt(idx) : undefined}
            onRemoveChip={handleRemoveChip}
            // 내부 '+데이터' 버튼은 숨기고(외부 액션 행이 렌더), caretRef 로 현재 커서 위치 노출(삽입 보존).
            hideInsertButton
            caretRef={caretGetterRef}
          />
        )}
      </div>
      {/* 입력칸 우측 액션 행 — 평문 모드(PlainInsertDataButton + ƒx)와 동일 위치/구성. */}
      {hasCandidates && (
        <button
          ref={plusRef}
          type="button"
          data-testid={`${testidPrefix}-plus-data-btn`}
          onClick={() => setInsertAt(caretGetterRef.current ? caretGetterRef.current() : (keyValue?.length ?? 0))}
          title={t('layout_editor.inline_binding.insert_data')}
          aria-label={t('layout_editor.inline_binding.insert_data')}
          style={iconBtn}
        >
          +
        </button>
      )}
      {showExprButton && onToExpression && (
        <button
          type="button"
          data-testid={`${testidPrefix}-to-expr`}
          onClick={onToExpression}
          title={t('layout_editor.value_tree.to_expression')}
          aria-label={t('layout_editor.value_tree.to_expression')}
          style={iconBtn}
        >
          ƒx
        </button>
      )}
      {/* 🌐 언어별 편집 토글 — 액션 행 끝(평문 모드와 동일 위치). 한 컨테이너 정렬로 줄 어긋남 방지. */}
      {langToggle}
      {/* '+데이터'(커서 위치) 삽입 피커 — 버튼 아래 부유(flip/clamp). anchor 는 외부 '+데이터' 버튼. */}
      {hasCandidates && (
        <FloatingDropdown anchorRef={plusRef} open={insertAt !== null} onClose={() => setInsertAt(null)}>
          <div data-testid={`${testidPrefix}-chipfield-picker`}>
            <InlineBindingScalarPicker
              candidates={candidates!}
              t={t}
              onSelect={(c) => void handlePick(c)}
              testIdSuffix={`${testidPrefix}-field-insert`}
              defaultOpen
              floating={false}
            />
          </div>
        </FloatingDropdown>
      )}
    </div>
  );
}

/**
 * 평문/평문 custom 키에 데이터 칩 삽입(키화) — `+데이터` 버튼. 평문은 새 param
 * custom 키 생성(`keyifyWithNewBinding`), 평문 custom 키는 키 승계(insertBindingIntoParamKey).
 * 결과 node text 를 `onChange` 로 기록 → param 키로 전이 → 다음 렌더부터 InlinePlaceholderField.
 */
function PlainInsertDataButton({
  value,
  customKey,
  onChange,
  t,
  candidates,
  testidPrefix,
}: {
  value: string;
  customKey: string | null;
  onChange: (value: string | undefined) => void;
  t: I18nTextFieldProps['t'];
  candidates: BindingCandidate[];
  testidPrefix: string;
}): React.ReactElement {
  const { state } = useLayoutEditor();
  const templateIdentifier = state.templateIdentifier;
  const layoutName = state.selectedRoute?.layoutName ?? null;
  const locale = state.locale;
  const docCtx = useLayoutDocumentContext();
  const [open, setOpen] = useState(false);
  const [busy, setBusy] = useState(false);
  const plusRef = useRef<HTMLButtonElement | null>(null);

  // `$t:` lang 키를 현재 로케일 평문으로 치환 (종전 항등 함수라 lang 키
  // 라벨(`$t:shop.tabs.detail_info`)을 키화하면 raw `$t:` 가 키 값에 박혔다. InlineBindingSection.
  // resolveLang 과 동일 SSoT). 콜론은 키에서 제외, 미해석은 원문 보존.
  const resolveLangKey = useCallback((key: string): string => {
    const resolved = t(key);
    return resolved && resolved !== key && !resolved.startsWith('$t:') ? resolved : '';
  }, [t]);
  const resolveLang = useCallback((s: string): string =>
    s.replace(/\$t:[a-zA-Z0-9._-]+/g, (tok) => resolveLangKey(tok.slice(3)))
      .replace(/\s+/g, ' ')
      .trim(), [resolveLangKey]);

  const handlePick = useCallback(async (c: BindingCandidate): Promise<void> => {
    if (busy) return;
    setBusy(true);
    try {
      // 평문 custom 키(param 없음)는 키 승계, 그 외(평문/임의 키)는 신규 custom 키화. 끝에 추가
      // (앞/중간 위치 삽입은 데이터가 이미 붙어 param 키로 전이된 뒤 InlinePlaceholderField 가 담당).
      if (customKey) {
        const res = await insertBindingIntoParamKey(
          templateIdentifier, locale, value, Number.MAX_SAFE_INTEGER, c.sourceId, c.path, 'scalar',
        );
        if (res.kind === 'ok') { onChange(res.text); docCtx?.markDirty?.(); }
      } else {
        const res = await keyifyWithNewBinding(
          templateIdentifier, layoutName, locale, value, value.length, c.sourceId, c.path, 'scalar',
          // 평문/lang 키 평문화(라벨 라벨이 `$t:` 키면 평문으로) + lang named-param 분해기(인라인 편집 SSoT).
          resolveLang, (key) => t(key),
        );
        if (res.kind === 'ok') { onChange(res.text); docCtx?.markDirty?.(); }
      }
    } finally {
      setBusy(false);
      setOpen(false);
    }
  }, [busy, customKey, templateIdentifier, layoutName, locale, value, onChange, docCtx, t, resolveLang]);

  return (
    <div data-testid={`${testidPrefix}-plus-data`} style={{ display: 'inline-block' }}>
      {/* `+` 버튼은 항상 자리 유지(피커가 열려도 플로우 폭 불변). 피커는 공용 FloatingDropdown 으로
 버튼 아래 띄우되 위치 자동 보정(flip/clamp)으로 좁은 입력 행 어디서든 잘리지 않는다(PO
          2026-06-13 — 하드코딩 right:0 으로 좌측 쏠려 가려지던 결함 → 범용 위치 보정으로 통일). */}
      <button
        ref={plusRef}
        type="button"
        data-testid={`${testidPrefix}-plus-data-btn`}
        disabled={busy}
        onClick={() => setOpen((v) => !v)}
        // 버튼 폭 절약: "데이터" 텍스트 제거하고 `+` 아이콘만. 라벨은
        // title/aria-label 툴팁으로 접근(좁은 항목 행에서 라벨/ID/아이콘 칸 폭 확보).
        title={t('layout_editor.inline_binding.insert_data')}
        aria-label={t('layout_editor.inline_binding.insert_data')}
        style={iconBtn}
      >
        +
      </button>
      <FloatingDropdown anchorRef={plusRef} open={open} onClose={() => setOpen(false)}>
        {/* 외부 FloatingDropdown 으로 직접 부유 — 이중 부유 방지 위해 picker 는 인라인 렌더. */}
        <InlineBindingScalarPicker
          candidates={candidates}
          t={t}
          onSelect={(c) => void handlePick(c)}
          testIdSuffix={`${testidPrefix}-plus-insert`}
          defaultOpen
          floating={false}
        />
      </FloatingDropdown>
    </div>
  );
}

/**
 * 표현식 접힌 미리보기 — 읽기전용 한 줄(키 해석·데이터 칩) + [수정] 버튼 (PO
 * "기본 접힌 미리보기 + [수정]" 2026-06-13). 진입 시 정보 과다 방지 — [수정] 클릭 시 분해 빌더 펼침.
 *
 * 미리보기 = `previewSegments`("한 값만 해석" — 조건은 참 분기 + ⋯, 키는 해석, 바인딩은 칩).
 */
function ExpressionCollapsedPreview({
  value,
  t,
  translate,
  onEdit,
  testidPrefix,
}: {
  value: string;
  t: I18nTextFieldProps['t'];
  translate: (key: string) => string;
  onEdit: () => void;
  testidPrefix: string;
}): React.ReactElement {
  const tokens: PreviewToken[] = previewSegments(
    value,
    (key) => {
      const r = translate(key);
      return r && r !== key && !r.startsWith('$t:') ? r : '';
    },
    (binding) => bindingChipLabel(binding),
  );
  return (
    <div data-testid={testidPrefix} style={collapsedRow}>
      <div data-testid={`${testidPrefix}-text`} style={collapsedPreview}>
        {tokens.length === 0 ? (
          <span style={{ color: '#94a3b8' }}>{t('layout_editor.prop_i18n.placeholder')}</span>
        ) : (
          tokens.map((tok, i) =>
            tok.kind === 'chip' ? (
              <span key={i} style={previewChip}>🔗 {tok.text}</span>
            ) : (
              <span key={i} style={tok.kind === 'ellipsis' ? previewEllipsis : undefined}>{tok.text}</span>
            ),
          )
        )}
      </div>
      <button
        type="button"
        data-testid={`${testidPrefix}-edit`}
        onClick={onEdit}
        style={editBtn}
        title={t('layout_editor.value_tree.edit')}
        aria-label={t('layout_editor.value_tree.edit')}
      >
        ✎ {t('layout_editor.value_tree.edit')}
      </button>
    </div>
  );
}

/**
 * 단일 경로 바인딩(D) 데이터 선택 칸 — 현재 가리키는 데이터 라벨 + [데이터 바꾸기] 피커
 * 종전 읽기전용
 * "바인딩됨(코드 편집)" 배지를 대체한다(opt-in + 단일 경로 + 후보 보유 시). 복잡식은 여전히 배지.
 *
 *  - 현재 값(`{{x.y ?? ''}}`)의 친화 라벨(bindingChipLabel)을 칩으로 표시.
 *  - [데이터 바꾸기] → InlineBindingScalarPicker(기존 후보 피커) → buildBindingExpression 으로
 *    안전 형태(`{{src?.path ?? ''}}`) 재생성 후 onChange. 신규 변환 인프라 0(부록6 SSoT 재사용).
 */
function BindingDataField({
  value,
  onChange,
  t,
  candidates,
  testidPrefix,
}: {
  value: string;
  onChange: (value: string | undefined) => void;
  t: I18nTextFieldProps['t'];
  candidates: BindingCandidate[];
  testidPrefix: string;
}): React.ReactElement {
  const [picking, setPicking] = useState(false);
  const changeRef = useRef<HTMLButtonElement | null>(null);
  const label = bindingChipLabel(value);
  return (
    <div data-testid={testidPrefix} style={{ display: 'flex', flexDirection: 'column', gap: 4, width: '100%', minWidth: 0 }}>
      <div style={dataChipRow}>
        <span data-testid={`${testidPrefix}-chip`} style={dataChip}>🔗 {label}</span>
        <button
          ref={changeRef}
          type="button"
          data-testid={`${testidPrefix}-change`}
          onClick={() => setPicking((v) => !v)}
          style={iconBtn}
          title={t('layout_editor.value_tree.change_data')}
          aria-label={t('layout_editor.value_tree.change_data')}
        >
          {t('layout_editor.value_tree.change_data')}
        </button>
      </div>
      {/* [데이터 바꾸기] 버튼 아래 부유 — 좁은 값 행에서 인라인 펼침 시 행을 밀어내던 결함을
 공용 FloatingDropdown(flip/clamp) 으로 통일. */}
      <FloatingDropdown anchorRef={changeRef} open={picking} onClose={() => setPicking(false)}>
        <InlineBindingScalarPicker
          candidates={candidates}
          t={t}
          onSelect={(c) => {
            // **폴백 없는 순수 바인딩**(`{{src?.path}}`) — `buildBindingExpression` 의 `?? ''` 폴백은
            // 리프 컨텍스트에서 재귀 분해(fallback 노드)를 유발해 "데이터 바꿀 때마다 기본값 중첩"
            // 결함을 냈다. 리프는 단말(조건/폴백 분기 안)이라 안전 폴백
            // 불필요 — 폴백 없는 바인딩이면 parseBindingExpression 이 단일 경로 리프로만 인지(중첩 0).
            const segs = c.path ? c.path.split('.').filter(Boolean) : [];
            const chain = [c.sourceId, ...segs].join('?.');
            onChange(`{{${chain}}}`);
            setPicking(false);
          }}
          testIdSuffix={`${testidPrefix}-pick`}
          defaultOpen
          floating={false}
        />
      </FloatingDropdown>
    </div>
  );
}

const dataChipRow: React.CSSProperties = { display: 'flex', alignItems: 'center', gap: 6, width: '100%', minWidth: 0, flexWrap: 'wrap' };
const dataChip: React.CSSProperties = { fontSize: 11, background: '#eef2ff', color: '#4338ca', padding: '3px 8px', borderRadius: 12, wordBreak: 'break-all' };
// 접힌 미리보기 행 — 한 줄 읽기전용(키 해석·칩) + [수정] 버튼. 진입 시 정보 과다 방지.
const collapsedRow: React.CSSProperties = { display: 'flex', alignItems: 'center', gap: 8, width: '100%', minWidth: 0 };
const collapsedPreview: React.CSSProperties = { flex: 1, minWidth: 0, display: 'flex', flexWrap: 'wrap', alignItems: 'center', gap: 4, padding: '5px 8px', fontSize: 12, color: '#334155', border: '1px solid #e2e8f0', borderRadius: 6, background: '#f8fafc', overflow: 'hidden' };
const previewChip: React.CSSProperties = { fontSize: 11, background: '#eef2ff', color: '#4338ca', padding: '1px 6px', borderRadius: 10, whiteSpace: 'nowrap' };
const previewEllipsis: React.CSSProperties = { color: '#94a3b8' };
const editBtn: React.CSSProperties = { padding: '4px 10px', fontSize: 11, border: '1px solid #cbd5e1', borderRadius: 6, background: '#fff', color: '#2563eb', cursor: 'pointer', whiteSpace: 'nowrap', flexShrink: 0 };
// 펼친 빌더 [접기] 버튼. 우측 정렬 헤더.
const collapseBtn: React.CSSProperties = { padding: '3px 10px', fontSize: 11, border: '1px solid #cbd5e1', borderRadius: 6, background: '#f8fafc', color: '#475569', cursor: 'pointer', whiteSpace: 'nowrap' };
// [일반 이름으로] 되돌리기 버튼(빌더 헤더 좌측). 표현식 제거 = 주의 동작이라 경고색.
const revertBtn: React.CSSProperties = { padding: '3px 10px', fontSize: 11, border: '1px solid #fca5a5', borderRadius: 6, background: '#fef2f2', color: '#b91c1c', cursor: 'pointer', whiteSpace: 'nowrap' };
// 되돌리기 확인 대화 박스 — 첫 결과 미리보기 + 비가역 경고. 빌더 위에 띄움.
const revertConfirmBox: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 6, padding: 10, border: '1px solid #fca5a5', borderRadius: 8, background: '#fffbeb' };
const revertConfirmLead: React.CSSProperties = { margin: 0, fontSize: 12, color: '#334155' };
const revertConfirmPreview: React.CSSProperties = { padding: '5px 8px', fontSize: 12, color: '#0f172a', background: '#fff', border: '1px solid #e2e8f0', borderRadius: 6, wordBreak: 'break-all' };
const revertConfirmWarn: React.CSSProperties = { margin: 0, fontSize: 11, color: '#b45309' };
const revertConfirmOk: React.CSSProperties = { padding: '3px 12px', fontSize: 11, border: '1px solid #dc2626', borderRadius: 6, background: '#dc2626', color: '#fff', cursor: 'pointer', whiteSpace: 'nowrap' };
// minWidth:0 — flex 자식의 기본 min-content 폭 제약을 풀어 좁은 속성 모달에서 행이 부모보다
// 넓어지지 않게 한다(가로 스크롤 근절). 미설정 시 previewInput(min-content)+🌐(nowrap)
// 합이 부모보다 커져 넘쳤다(라이브 실측: label-i18n 150px 안에 scrollWidth 160px).
const previewRow: React.CSSProperties = { display: 'flex', gap: 4, alignItems: 'center', width: '100%', minWidth: 0 };
// minWidth:0 — 입력칸이 가용 폭까지 자유롭게 줄어들도록(고정 minWidth:80 제거). 🌐 버튼이 nowrap
// 으로 자기 폭을 유지하므로, 입력칸이 줄어들어 행 전체가 부모 폭에 들어간다.
const previewInput: React.CSSProperties = { flex: 1, padding: '5px 8px', fontSize: 12, border: '1px solid #cbd5e1', borderRadius: 6, minWidth: 0, width: '100%' };
const langBtn: React.CSSProperties = { padding: '4px 8px', fontSize: 11, border: '1px solid #cbd5e1', borderRadius: 6, background: '#f8fafc', color: '#475569', cursor: 'pointer', whiteSpace: 'nowrap' };
// 아이콘 전용 버튼. `+데이터` 등 좁은 행 버튼.
const iconBtn: React.CSSProperties = { padding: '4px 8px', fontSize: 13, lineHeight: 1, border: '1px solid #cbd5e1', borderRadius: 6, background: '#f8fafc', color: '#475569', cursor: 'pointer', whiteSpace: 'nowrap' };
// 설정 참조($*_settings:) 칩 시각화 행 — 칩+평문 + [✎ 수정]. 데이터 칩(dataChipRow)과 동형.
const settingsRefWrap: React.CSSProperties = { display: 'flex', alignItems: 'center', gap: 6, width: '100%', minWidth: 0, flexWrap: 'wrap' };
const settingsRefChips: React.CSSProperties = { flex: 1, minWidth: 0, display: 'flex', flexWrap: 'wrap', alignItems: 'center', gap: 4, fontSize: 12, color: '#334155' };
const settingsRefChip: React.CSSProperties = { fontSize: 11, background: '#eef2ff', color: '#4338ca', padding: '2px 8px', borderRadius: 12, whiteSpace: 'nowrap' };
const bindingWrap: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 2, width: '100%' };
const bindingCode: React.CSSProperties = { fontSize: 11, background: '#f1f5f9', padding: '3px 6px', borderRadius: 4, color: '#0f172a', wordBreak: 'break-all' };
const bindingBadge: React.CSSProperties = { fontSize: 10, color: '#64748b' };
// minWidth:0 — 펼침 폼이 부모(컨트롤 위젯) 폭 안에서 줄어들도록. 미설정 시 내부 행/긴 키 코드가
// min-content 로 폼을 본문보다 넓게 밀어 가로 스크롤을 만들었다.
// 펼침 안내(키 미생성 평문 상태) — 키 생성 후 통합 TranslationField 가 렌더.
const expandHint: React.CSSProperties = { fontSize: 11, color: '#94a3b8', padding: '6px 8px', border: '1px dashed #cbd5e1', borderRadius: 6, background: '#f8fafc' };
