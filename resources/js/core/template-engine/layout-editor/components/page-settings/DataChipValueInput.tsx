/**
 * DataChipValueInput.tsx — 값 전용 데이터칩 입력
 *
 * SEO 탭에서 **키화(`$t:custom.*`) 없이 표현식/URL/평문만 다루는 값 칸**의 공용 입력기다.
 * og.image·구조화 데이터 속성 값·og/twitter extra content·(검색 피커 경유) vars 값 등이 쓴다.
 * I18nTextField 와 달리 다국어 커스텀 키를 만들지 않는다(텍스트형 다국어가 필요한 검색 제목/
 * og.title 등은 I18nTextField 를 직접 쓴다).
 *
 *  표현식 분해 트리 확대(2026-06-13, "칩 분해로 보이되 번역키는 만들지 않음 + 범용화"):
 * 종전엔 평문 input + 칩 이어붙이기만 해 `{{x}}`/`$core_settings:`/`?? ''` 가 raw 코드로 노출됐다. 값 형태를 분류해 —
 *
 *  - empty/평문(보간 없음): 평문 input + 🔍 데이터 검색 + [표현식]/[기본값] 진입(seed).
 *  - 단일 순수 바인딩(`{{src?.path}}`): 친화 데이터 칩 + [데이터 바꾸기](키화 없음).
 *  - 평문+칩 혼합(`회원 {{x}}` / `$core_settings:foo`): 칩+평문 시각화(읽기) + [수정](평문 input).
 *  - 표현식(조건/폴백/이어붙이기, 분해 성공): SegmentedValueEditor(**값 모드**)로 위임 — 리프
 *    입력기로 자기 자신(DataChipValueInput)을 재귀 주입해 트리 안 평문도 키화하지 않는다.
 *  - 분해 불가식(raw): 평문 input 유지(편집 가능 + 손상 0 — 파서가 안 건드림).
 *
 * 편집기 코어 컴포넌트 — `g7le-*` + 인라인 스타일만(feedback_layout_editor_no_css_lib_dependency).
 *
 * @since engine-v1.50.0 · 값 모드 확대 engine-v1.50.0
 */

import React, { useCallback, useState } from 'react';
import { InlineBindingScalarPicker } from '../property-controls/InlineBindingScalarPicker';
import { FloatingDropdown } from '../shared/FloatingDropdown';
import { BindingChipTextInput } from './BindingChipTextInput';
import { SegmentedValueEditor } from './SegmentedValueEditor';
import { ConditionalValueEditor } from './ConditionalValueEditor';
import { bindingChipLabel, toValueChipTokens, hasValueChipContent } from '../../spec/inlineBindingUtils';
import { parseBindingExpression, buildBindingExpression } from '../../spec/bindingCandidates';
import {
  classifyValueShape,
  hasDecomposableExpressionSegment,
  parseExpressionValue,
  serializeValueNode,
  seedExpressionFromPlain,
  seedFallbackFromValue,
  reduceExpressionToPlain,
  previewSegments,
  type ValueNode,
  type PreviewToken,
} from '../../spec/expressionValueTree';
import type { BindingCandidate } from '../../spec/bindingCandidates';

export interface DataChipValueInputProps {
  /** 현재 값(표현식/URL/평문, 다중 칩 조합 문자열) */
  value: string | null | undefined;
  /** 값 변경 콜백 — 빈 문자열이면 키 미생성(비파괴), 호출자가 빈 값 정리 */
  onChange: (value: string) => void;
  /** 다국어 해석 t */
  t: (key: string, params?: Record<string, string | number>) => string;
  /** 데이터칩 후보 풀(SEO 컨텍스트). 미전달/빈 배열이면 피커 숨김 */
  candidates?: BindingCandidate[];
  /** 입력 placeholder */
  placeholder?: string;
  /** data-testid 접두 */
  testidPrefix?: string;
  /** 읽기전용(필터 잠김 등) */
  readOnly?: boolean;
  /**
   * 표현식 분해 트리 확대 활성화. 기본 true — 값 형태에 따라 칩 시각화/분해 트리를
   * 그린다. false 면 종전대로 평문 input + 칩 이어붙이기만(점진 적용/디그레이드 안전판).
   */
  enableExpressionTree?: boolean;
}

/**
 * 값 칩 토큰화/판정은 공용 SSoT(inlineBindingUtils)로 위임 — DataChipValueInput(값 칸)과
 * I18nTextField(설정참조 칩)가 동일 로직 공유.
 */
const toChipTokens = toValueChipTokens;

/** 값에 데이터 칩/설정 참조가 섞여 있는지(보간 또는 설정 참조 토큰 존재). */
function hasChipContent(value: string): boolean {
  return hasValueChipContent(value);
}

/**
 * 값 전용 데이터칩 입력기.
 *
 * @param props DataChipValueInputProps
 * @return 입력 + 검색 피커 엘리먼트
 */
export function DataChipValueInput({
  value,
  onChange,
  t,
  candidates,
  placeholder,
  testidPrefix = 'g7le-data-chip-value',
  readOnly = false,
  enableExpressionTree = true,
}: DataChipValueInputProps): React.ReactElement {
  const current = typeof value === 'string' ? value : '';
  const hasCandidates = !!candidates && candidates.length > 0;

  // 평문 input 편집(타이핑) 진행 중 여부. 켜져 있으면 칩 시각화/분해 트리로 **전환하지 않고**
  // 평문 input 을 유지한다(타이핑 도중 모드 전환으로 input 이 언마운트되어 나머지 입력이 유실되는
  // 결함 차단 — feedback_editor_chip_input_uncontrolled_and_all_interaction_cases). 빈 칸에서
  // `{{` 를 타이핑하면 값이 곧바로 바인딩/혼합으로 분류돼 분기가 바뀌는데, **포커스가 있는 동안에는
  // editing=true 로 평문 input 분기에 머물고** blur(focusout) 시에만 값 형태를 재평가해 칩/분해로 전환한다.
  const [editing, setEditing] = useState(false);

  // 인라인 칩 편집 모드 — **[✎ 수정] 버튼으로만** 켠다(평문+칩 혼합 값의 칩 유지 편집). 평문 input 의
  // 포커스(editing)와 분리해야, 빈 칸에서 `{{` 를 타이핑하다 hasChipContent 가 참이 되는 순간 칩 편집기로
  // 전환돼 input 이 언마운트되며 입력이 유실되는 결함을 막는다(타이핑은 editing 평문 input, 명시적 칩
  // 편집은 chipEditing 칩 편집기 —-06-14). 값 형태가 칩화 불가로 바뀌면 자동 해제.
  const [chipEditing, setChipEditing] = useState(false);

  // [↩ 일반 이름으로] 되돌리기 확인 대화 표시 여부 (-06-14 — 값 모드 표현식에서
  // 문자열+데이터칩(일반 이름)으로 복귀할 길이 없던 결함, 페이지이름 I18nTextField 패리티). 표현식→일반은
  // 나머지 분기/데이터칩 소실이 따르는 비가역 작업이라 확인 게이트.
  const [revertConfirm, setRevertConfirm] = useState(false);

  // 값 모드 리프 입력기 — 표현식 분해 트리(SegmentedValueEditor)의 모든 리프에 자기 자신을 재귀
  // 주입한다. I18nTextField(키화) 대신 이 입력기를 쓰므로 트리 안 평문도 다국어 키로 새지 않는다.
  // 재귀 주입이라 깊이 무관하게 키화 0 이 유지된다(설계 산출물 5-2 누수 방지).
  const renderLeafInput = useCallback(
    (leaf: { value: string; onChange: (v: string | undefined) => void; t: DataChipValueInputProps['t']; candidates?: BindingCandidate[]; testidPrefix: string }) => (
      <DataChipValueInput
        value={leaf.value}
        onChange={(v) => leaf.onChange(v)}
        t={leaf.t}
        candidates={leaf.candidates}
        testidPrefix={leaf.testidPrefix}
        enableExpressionTree
      />
    ),
    [],
  );

  // ── 평문 input(편집/디그레이드 공통) ──
  const renderPlainInput = (): React.ReactElement => (
    <div style={{ display: 'flex', gap: 4, alignItems: 'center', width: '100%', minWidth: 0 }}>
      <input
        type="text"
        data-testid={`${testidPrefix}-input`}
        value={current}
        placeholder={placeholder ?? t('layout_editor.page_settings.seo.value_placeholder')}
        readOnly={readOnly}
        onChange={(e) => onChange(e.target.value)}
        // 포커스 동안 editing 유지 → 타이핑 중 칩/분해 분기로 전환 안 함(input 언마운트로 입력
        // 유실 차단). blur(focusout) 시 editing 해제 → 값 형태 재평가해 칩/분해로 전환.
        onFocus={() => setEditing(true)}
        onBlur={() => setEditing(false)}
        style={{
          flex: 1,
          padding: '5px 8px',
          fontSize: 12,
          border: '1px solid #cbd5e1',
          borderRadius: 6,
          minWidth: 0,
          width: '100%',
          background: readOnly ? '#f1f5f9' : '#fff',
          color: readOnly ? '#64748b' : '#0f172a',
        }}
      />
    </div>
  );

  // 데이터 검색 피커(이어붙이기) — 기존 다중 칩 조합 동작. 빈 값이면 그 표현식으로 시작, 값이 있으면
  // 끝에 공백 구분 이어붙임.
  const appendExpression = useCallback(
    (c: BindingCandidate): void => {
      const next = current ? `${current} ${c.expression}` : c.expression;
      onChange(next);
    },
    [current, onChange],
  );

  // ── 표현식 분해 트리 비활성(디그레이드) / 읽기전용 — 종전 동작(평문 input + 이어붙이기) ──
  if (!enableExpressionTree || readOnly) {
    return (
      <div data-testid={testidPrefix} style={col}>
        {renderPlainInput()}
        {!readOnly && hasCandidates && (
          <InlineBindingScalarPicker candidates={candidates!} t={t} onSelect={appendExpression} testIdSuffix={`${testidPrefix}-pick`} />
        )}
      </div>
    );
  }

  const shape = classifyValueShape(current);
  const isSegmented = hasDecomposableExpressionSegment(current);
  const singleBinding = parseBindingExpression(current); // 단일 순수 바인딩(`{{src?.path}}`)이면 객체.

  // [↩ 일반 이름으로] 헤더 + 확인 대화 — 값 모드 표현식 분해 트리 상단에 그려, 문자열+데이터칩(일반
  // 이름)으로 복귀할 입구를 제공한다(페이지이름 I18nTextField 와 동일 UX). prefix 는 분기별 testid
  // (seg/tree)에 맞춘다. 복귀값은 reduceExpressionToPlain(첫 결과 분기, 데이터면 칩 보존).
  const renderRevertHeader = (prefix: string): React.ReactElement => {
    const revertTo = reduceExpressionToPlain(current);
    const revertTokens: PreviewToken[] = revertTo
      ? previewSegments(revertTo, () => '', (binding) => bindingChipLabel(binding))
      : [];
    return (
      <>
        <div style={{ display: 'flex', justifyContent: 'flex-start' }}>
          <button
            type="button"
            data-testid={`${prefix}-to-plain`}
            onClick={() => setRevertConfirm(true)}
            style={revertBtn}
            title={t('layout_editor.value_tree.to_plain')}
            aria-label={t('layout_editor.value_tree.to_plain')}
          >
            ↩ {t('layout_editor.value_tree.to_plain')}
          </button>
        </div>
        {revertConfirm && (
          <div data-testid={`${prefix}-to-plain-confirm`} style={revertConfirmBox}>
            <p style={revertConfirmLead}>{t('layout_editor.value_tree.to_plain_lead')}</p>
            <div style={revertConfirmPreview}>
              {revertTokens.length === 0 ? (
                <span style={{ color: '#94a3b8' }}>{t('layout_editor.page_settings.seo.value_placeholder')}</span>
              ) : (
                revertTokens.map((tok, i) =>
                  tok.kind === 'chip' ? (
                    <span key={i} style={chip}>🔗 {tok.text}</span>
                  ) : (
                    <span key={i} style={tok.kind === 'ellipsis' ? { color: '#94a3b8' } : undefined}>{tok.text}</span>
                  ),
                )
              )}
            </div>
            <p style={revertConfirmWarn}>⚠ {t('layout_editor.value_tree.to_plain_warn')}</p>
            <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 6 }}>
              <button
                type="button"
                data-testid={`${prefix}-to-plain-confirm-cancel`}
                onClick={() => setRevertConfirm(false)}
                style={smallBtn}
              >
                {t('layout_editor.value_tree.to_plain_cancel')}
              </button>
              <button
                type="button"
                data-testid={`${prefix}-to-plain-confirm-ok`}
                onClick={() => { setRevertConfirm(false); onChange(reduceExpressionToPlain(current) || ''); }}
                style={revertConfirmOk}
              >
                {t('layout_editor.value_tree.to_plain_ok')}
              </button>
            </div>
          </div>
        )}
      </>
    );
  };

  // ① 표현식(F/G, 분해 성공) 또는 다중 세그먼트 — 분해 트리 위임(값 모드 리프 주입).
  //   분기 기준은 I18nTextField(키 모드)와 동일하다(무한 재귀 회피 핵심):
  //    · **다중 세그먼트**(`{{식}} 평문 {{x}}`) → SegmentedValueEditor(조각 분해). 조각이 더 작은
  //      값이라 리프 재귀가 유한하다.
  //    · **단일 분해식**(`{{a ? b : c}}`) → ConditionalValueEditor(분기 트리). 단일식을 다시
  //      SegmentedValueEditor 로 보내면 그 조각 1개의 value 가 원본과 동일 → 리프(DataChipValueInput
  //      재귀)가 또 단일식으로 판정 → **무한 재귀**. 분기 트리로 보내야 leaf→conditional 로 분해돼
  //      분기(then/else)가 더 작은 값이 되어 유한 종료(키 모드 I18nTextField 와 동일 구조).
  //  상단에 [↩ 일반 이름으로](renderRevertHeader)로 문자열+데이터칩 복귀 입구 제공.
  if (!editing && (shape === 'expression' || isSegmented)) {
    if (isSegmented) {
      return (
        <div data-testid={testidPrefix} style={col}>
          {!readOnly && renderRevertHeader(`${testidPrefix}-seg`)}
          <SegmentedValueEditor
            value={current}
            onChange={(v) => onChange(v)}
            t={t}
            candidates={candidates}
            testidPrefix={`${testidPrefix}-seg`}
            renderLeafInput={renderLeafInput}
          />
        </div>
      );
    }
    const parsed = parseExpressionValue(current);
    return (
      <div data-testid={testidPrefix} style={col}>
        {!readOnly && renderRevertHeader(`${testidPrefix}-tree`)}
        <ConditionalValueEditor
          node={parsed.node}
          onChange={(next: ValueNode) => onChange(serializeValueNode(next, false))}
          t={t}
          candidates={candidates}
          testidPrefix={`${testidPrefix}-tree`}
          renderLeafInput={renderLeafInput}
        />
      </div>
    );
  }

  // ② 단일 순수 바인딩(`{{src?.path}}` — D) — 친화 칩 + [데이터 바꾸기](키화 없음).
  //    chipEditing([✎ 수정]) 진입 시엔 건너뛰어 아래 칩 편집기(BindingChipTextInput)로 흐른다.
  if (singleBinding && !editing && !chipEditing) {
    return (
      <DataChipBindingField
        value={current}
        onChange={onChange}
        t={t}
        candidates={candidates}
        testidPrefix={testidPrefix}
        onEditRaw={() => setChipEditing(true)}
      />
    );
  }

  // ③ 평문 + 칩 혼합(`회원 {{x}}` / `$core_settings:foo`) — 칩+평문 시각화(읽기) + [수정].
  //    [✎ 수정]은 chipEditing(인라인 칩 편집기, 아래 분기)을 켠다 — 평문 input 포커스(editing)와 분리.
  if (!editing && !chipEditing && hasChipContent(current)) {
    const tokens = toChipTokens(current);
    return (
      <div data-testid={testidPrefix} style={col}>
        <div style={chipRow}>
          <div data-testid={`${testidPrefix}-chips`} style={chipBox}>
            {tokens.map((tok, i) =>
              tok.kind === 'chip' ? (
                <span key={i} style={chip}>🔗 {tok.label}</span>
              ) : (
                <span key={i}>{tok.label}</span>
              ),
            )}
          </div>
          <button
            type="button"
            data-testid={`${testidPrefix}-edit-raw`}
            onClick={() => setChipEditing(true)}
            style={smallBtn}
            title={t('layout_editor.value_tree.edit')}
            aria-label={t('layout_editor.value_tree.edit')}
          >
            ✎ {t('layout_editor.value_tree.edit')}
          </button>
        </div>
      </div>
    );
  }

  // ③-b 인라인 칩 편집기(chipEditing) — [✎ 수정]으로 진입. 데이터 칩 유지 + 평문 타이핑 + 칩 추가/삭제/이동.
  //    값이 칩화 불가로 바뀌면(모든 칩 제거) 자동으로 칩 편집 종료(아래 평문 input 분기로). [✓ 완료]로도 종료.
  if (chipEditing && hasChipContent(current)) {
    return (
      <div data-testid={testidPrefix} style={col}>
        <BindingChipTextInput
          value={current}
          onChange={onChange}
          t={t}
          candidates={candidates}
          disabled={readOnly}
          onDone={() => setChipEditing(false)}
          testidPrefix={`${testidPrefix}-chipedit`}
        />
        {/* [표현식으로]/[값이 없을 때 대신] — 칩 포함 값을 표현식 양식으로 승격(칩은 표현식 항으로 보존). */}
        {!readOnly && shape !== 'raw' && (
          <div style={{ display: 'flex', gap: 4, flexWrap: 'wrap' }}>
            <button
              type="button"
              data-testid={`${testidPrefix}-to-expr`}
              onClick={() => onChange(seedExpressionFromPlain(current))}
              style={smallBtn}
              title={t('layout_editor.value_tree.to_expression')}
              aria-label={t('layout_editor.value_tree.to_expression')}
            >
              ƒx {t('layout_editor.value_tree.to_expression')}
            </button>
            <button
              type="button"
              data-testid={`${testidPrefix}-to-fallback`}
              onClick={() => onChange(seedFallbackFromValue(current))}
              style={smallBtn}
              title={t('layout_editor.value_tree.segment.fallback')}
              aria-label={t('layout_editor.value_tree.segment.fallback')}
            >
              ?? {t('layout_editor.value_tree.segment.fallback')}
            </button>
          </div>
        )}
        {/* 이미 복잡한 식(shape=raw, 친화 트리 분해 불가) — "표현식으로 바꾸기"는 의미 없다(이미
            식이며 적용 시 중괄호 중첩으로 손상). 막힌 게 아니라 이미 식임을 안내 + 코드 편집기 입구로
. */}
        {shape === 'raw' && (
          <p data-testid={`${testidPrefix}-raw-hint`} style={rawHint}>
            ⓘ {t('layout_editor.value_tree.raw_expression_hint')}
          </p>
        )}
      </div>
    );
  }

  // ④ 평문(보간 없음) 또는 평문 편집 모드(타이핑) — 평문 input + 검색/표현식/기본값 진입.
  //    단, **칩 콘텐츠(`{{...}}`/`$*_settings:`)가 들어 있으면 raw 평문 input 대신 칩 편집기**로 편집한다
  //  ([✎ 수정] 시 `{{_seo.x ?? ''}}`·`$core_settings:` 가 raw 코드로 노출되던
  //    결함). 폴백 달린 단일 바인딩(`{{x ?? ''}}`)처럼 ①②③ 분기에 안 잡혀 ④로 빠진 값도 여기서 칩으로
  //    편집된다. [✓ 완료](onDone)로 평문 input 모드 종료(editing 해제)해 칩 시각화로 복귀.
  //    단 **editing(포커스 평문 타이핑) 중에는 제외** — 빈 칸에서 `{{` 타이핑하다 hasChipContent 가
  //    참이 되는 순간 칩 편집기로 전환돼 input 이 언마운트되며 입력이 유실되는 결함 차단(메모리
  //    feedback_editor_chip_input_uncontrolled_and_all_interaction_cases). blur 후 재평가돼 칩으로 전환.
  if (!editing && hasChipContent(current)) {
    return (
      <div data-testid={testidPrefix} style={col}>
        <BindingChipTextInput
          value={current}
          onChange={onChange}
          t={t}
          candidates={candidates}
          disabled={readOnly}
          onDone={() => { setChipEditing(false); setEditing(false); }}
          testidPrefix={`${testidPrefix}-chipedit`}
        />
        {!readOnly && shape !== 'raw' && (
          <div style={{ display: 'flex', gap: 4, flexWrap: 'wrap' }}>
            <button type="button" data-testid={`${testidPrefix}-to-expr`} onClick={() => onChange(seedExpressionFromPlain(current))} style={smallBtn} title={t('layout_editor.value_tree.to_expression')} aria-label={t('layout_editor.value_tree.to_expression')}>
              ƒx {t('layout_editor.value_tree.to_expression')}
            </button>
            <button type="button" data-testid={`${testidPrefix}-to-fallback`} onClick={() => onChange(seedFallbackFromValue(current))} style={smallBtn} title={t('layout_editor.value_tree.segment.fallback')} aria-label={t('layout_editor.value_tree.segment.fallback')}>
              ?? {t('layout_editor.value_tree.segment.fallback')}
            </button>
          </div>
        )}
        {shape === 'raw' && (
          <p data-testid={`${testidPrefix}-raw-hint`} style={rawHint}>ⓘ {t('layout_editor.value_tree.raw_expression_hint')}</p>
        )}
      </div>
    );
  }
  return (
    <div data-testid={testidPrefix} style={col}>
      {renderPlainInput()}
      <div style={actionRow}>
        {hasCandidates && (
          <InlineBindingScalarPicker candidates={candidates!} t={t} onSelect={appendExpression} testIdSuffix={`${testidPrefix}-pick`} />
        )}
        {/* [표현식으로]/[값이 없을 때 대신] — 평문/단일값을 표현식 양식으로 승격(키화 0, seed 재사용). */}
        {shape !== 'raw' && (
          <div style={{ display: 'flex', gap: 4 }}>
            <button
              type="button"
              data-testid={`${testidPrefix}-to-expr`}
              onClick={() => onChange(seedExpressionFromPlain(current))}
              style={smallBtn}
              title={t('layout_editor.value_tree.to_expression')}
              aria-label={t('layout_editor.value_tree.to_expression')}
            >
              ƒx {t('layout_editor.value_tree.to_expression')}
            </button>
            <button
              type="button"
              data-testid={`${testidPrefix}-to-fallback`}
              onClick={() => onChange(seedFallbackFromValue(current))}
              style={smallBtn}
              title={t('layout_editor.value_tree.segment.fallback')}
              aria-label={t('layout_editor.value_tree.segment.fallback')}
            >
              ?? {t('layout_editor.value_tree.segment.fallback')}
            </button>
          </div>
        )}
      </div>
      {/* 이미 복잡한 식(분해 불가) — 표현식 승격 불가 사유 안내 + 코드 편집 위임. */}
      {shape === 'raw' && !editing && (
        <p data-testid={`${testidPrefix}-raw-hint`} style={rawHint}>
          ⓘ {t('layout_editor.value_tree.raw_expression_hint')}
        </p>
      )}
    </div>
  );
}

/**
 * 단일 순수 바인딩(`{{src?.path}}`) 칩 + [데이터 바꾸기] — 키화 없는 값 칸용. I18nTextField 의
 * BindingDataField 와 동일 UX 이나 다국어 키를 만들지 않는다(SSoT 분리). [수정]은 raw 평문 편집.
 *
 *  - 현재 바인딩의 친화 라벨(bindingChipLabel)을 칩으로.
 *  - [데이터 바꾸기] → 후보 피커 → 폴백 없는 순수 바인딩(`{{src?.path}}`)으로 교체(리프 중첩 0).
 */
function DataChipBindingField({
  value,
  onChange,
  t,
  candidates,
  testidPrefix,
  onEditRaw,
}: {
  value: string;
  onChange: (value: string) => void;
  t: DataChipValueInputProps['t'];
  candidates?: BindingCandidate[];
  testidPrefix: string;
  onEditRaw: () => void;
}): React.ReactElement {
  const [picking, setPicking] = useState(false);
  const pickRef = React.useRef<HTMLButtonElement | null>(null);
  const label = bindingChipLabel(value);
  const hasCandidates = !!candidates && candidates.length > 0;
  // 현재 값이 SEO 다국어 추출 함수(`$localized(<경로>)`) 래핑이면 데이터 교체 시에도 래핑을
  // 보존한다 — 래핑이 빠지면 다국어 객체가 현재 로케일 문자열로 추출되지 않아 SEO 메타가 깨진다.
  const currentLocaleFn = parseBindingExpression(value)?.localeFn;
  return (
    <div data-testid={testidPrefix} style={col}>
      <div style={chipRow}>
        <span data-testid={`${testidPrefix}-chip`} style={chip}>🔗 {label}</span>
        {hasCandidates && (
          <button
            ref={pickRef}
            type="button"
            data-testid={`${testidPrefix}-change`}
            onClick={() => setPicking((v) => !v)}
            style={smallBtn}
            title={t('layout_editor.value_tree.change_data')}
            aria-label={t('layout_editor.value_tree.change_data')}
          >
            {t('layout_editor.value_tree.change_data')}
          </button>
        )}
        <button
          type="button"
          data-testid={`${testidPrefix}-edit-raw`}
          onClick={onEditRaw}
          style={smallBtn}
          title={t('layout_editor.value_tree.edit')}
          aria-label={t('layout_editor.value_tree.edit')}
        >
          ✎
        </button>
      </div>
      {hasCandidates && (
        <FloatingDropdown anchorRef={pickRef} open={picking} onClose={() => setPicking(false)}>
          {/* 외부 FloatingDropdown 으로 직접 부유 — picker 는 그 패널 안에서 인라인 렌더(floating={false})
              해 이중 부유를 막는다(컴포넌트 기본 floating=true 이지만 자체 토글 + defaultOpen 패턴). */}
          <InlineBindingScalarPicker
            candidates={candidates!}
            t={t}
            onSelect={(c) => {
              if (currentLocaleFn) {
                // SEO 추출 함수 래핑 보존 — `$localized(<src>.<path>)`(옵셔널 체이닝/폴백 없음).
                const arg = [c.sourceId, ...(c.path ? c.path.split('.').filter(Boolean) : [])].join('.');
                onChange(`{{${currentLocaleFn}(${arg})}}`);
                setPicking(false);
                return;
              }
              // 폴백 없는 순수 바인딩(`{{src?.path}}`) — 리프 컨텍스트라 안전 폴백 불필요(중첩 0).
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
      )}
    </div>
  );
}

const col: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 4, width: '100%', minWidth: 0 };
const chipRow: React.CSSProperties = { display: 'flex', alignItems: 'center', gap: 6, width: '100%', minWidth: 0, flexWrap: 'wrap' };
const chipBox: React.CSSProperties = { flex: 1, minWidth: 0, display: 'flex', flexWrap: 'wrap', alignItems: 'center', gap: 4, padding: '5px 8px', fontSize: 12, color: '#334155', border: '1px solid #e2e8f0', borderRadius: 6, background: '#f8fafc' };
const chip: React.CSSProperties = { fontSize: 11, background: '#eef2ff', color: '#4338ca', padding: '2px 8px', borderRadius: 12, whiteSpace: 'nowrap' };
const smallBtn: React.CSSProperties = { padding: '4px 8px', fontSize: 11, border: '1px solid #cbd5e1', borderRadius: 6, background: '#f8fafc', color: '#475569', cursor: 'pointer', whiteSpace: 'nowrap' };
const actionRow: React.CSSProperties = { display: 'flex', flexWrap: 'wrap', alignItems: 'center', gap: 6 };
// raw(분해 불가 복잡식) 안내 — 이미 식이라 친화 분해 불가, 코드 편집기 위임 안내.
const rawHint: React.CSSProperties = { margin: '2px 0 0', fontSize: 11, color: '#94a3b8', lineHeight: 1.4 };
// [↩ 일반 이름으로] 되돌리기 — I18nTextField 와 동일 시각(경고색 버튼 + 확인 대화). 표현식 제거=주의 동작.
const revertBtn: React.CSSProperties = { padding: '3px 10px', fontSize: 11, border: '1px solid #fca5a5', borderRadius: 6, background: '#fef2f2', color: '#b91c1c', cursor: 'pointer', whiteSpace: 'nowrap' };
const revertConfirmBox: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 6, padding: 10, border: '1px solid #fca5a5', borderRadius: 8, background: '#fffbeb' };
const revertConfirmLead: React.CSSProperties = { margin: 0, fontSize: 12, color: '#334155' };
const revertConfirmPreview: React.CSSProperties = { padding: '5px 8px', fontSize: 12, color: '#0f172a', background: '#fff', border: '1px solid #e2e8f0', borderRadius: 6, wordBreak: 'break-all', display: 'flex', flexWrap: 'wrap', gap: 4, alignItems: 'center' };
const revertConfirmWarn: React.CSSProperties = { margin: 0, fontSize: 11, color: '#b45309' };
const revertConfirmOk: React.CSSProperties = { padding: '3px 12px', fontSize: 11, border: '1px solid #dc2626', borderRadius: 6, background: '#dc2626', color: '#fff', cursor: 'pointer', whiteSpace: 'nowrap' };
