// e2e:allow 레이아웃 편집기 텍스트 데이터 연결 scalar 검색 피커 — 합성 이벤트 의존으로 Playwright 자동화 부적합, Chrome MCP 매트릭스(§공통 검증) + 단위 테스트로 검증 (InlineBindingSection.tsx L1 과 동일 정책)
/**
 * InlineBindingScalarPicker.tsx — scalar 데이터 후보 검색 피커
 *
 * [속성] 탭(`InlineBindingSection`)의 조각 교체/신규 추가와, 인라인 칩 편집(`InlineParamChipEditor`)의
 * '+데이터' 커서 위치 삽입이 **동일 피커**를 공유한다(SSoT — 저장값 형태/테스트ID/UX 일관).
 *
 * 텍스트에 꽂는 값은 항상 단일 스칼라이므로 scalar 후보만 노출한다(`filterCandidatesByShape`).
 * 선택 시 `onSelect(candidate)` — 호출자가 위치(끝/커서)·동기 방식을 결정한다.
 *
 * 편집기 코어 컴포넌트 — `g7le-*` + 인라인 스타일만(라이브러리 중립).
 *
 * @since engine-v1.50.0
 */

import React, { useMemo, useRef, useState } from 'react';
import {
  type BindingCandidate,
  filterCandidatesByShape,
  searchCandidates,
} from '../../spec/bindingCandidates';
import { FloatingDropdown } from '../shared/FloatingDropdown';

export type InlineBindingT = (key: string, params?: Record<string, string | number>) => string;

/** `$t:` 키 또는 평문 라벨 해석. 미해석 시 fallback. (InlineBindingSection.resolveLabel 과 동형) */
export function resolveBindingLabel(t: InlineBindingT, key: string | undefined, fallback: string): string {
  if (!key) return fallback;
  const resolved = t(key);
  if (!resolved || resolved === key || resolved.startsWith('$t:')) return fallback;
  return resolved;
}

export interface InlineBindingScalarPickerProps {
  /** 연결 가능 데이터 후보 풀(평탄) */
  candidates: BindingCandidate[];
  /** 다국어 해석 t */
  t: InlineBindingT;
  /** 후보 선택 — 호출자가 삽입 위치/동기를 결정 */
  onSelect: (c: BindingCandidate) => void;
  /** 테스트/식별용 접미사 */
  testIdSuffix: string;
  /**
   * 초기 펼침 여부(기본 false). 인라인 '+데이터' 커서 삽입처럼 사용자가 이미 삽입 의도를 표명한
   * 진입점은 true 로 즉시 결과 목록을 열어 토글 1회를 생략한다([속성]탭 append/replace 는 false).
   */
  defaultOpen?: boolean;
  /**
   * 펼침 시 검색창+결과를 **부유 드롭다운**(공용 `FloatingDropdown`)으로 띄울지 (검수
   * "돋보기 누르면 화면 50% 차지 — 부유 상태로 떠야" 2026-06-13 / "데이터 선택기가 부착된 모든 UI 를
   * 동일하게 부유 처리" 2026-06-14). **기본 true** — 어느 진입점이든 펼치면 토글 기준으로 떠서 행/패널을
   * 밀어내지 않는다(좁은 조건 행·데이터 조각·[속성]탭 폼 공통). 종전엔 기본 false(인라인)였으나, 새
   * 호출처가 floating 미지정으로 또 인라인으로 새어 같은 결함이 반복돼 기본값을 부유로 뒤집었다.
   * 외부에서 직접 `FloatingDropdown` 으로 감싸는 호출처(자체 토글 + `defaultOpen`)는 이중 부유를 피하려
   * 명시적으로 `floating={false}` 를 주어 외부 패널 안에서 인라인으로 렌더한다.
   */
  floating?: boolean;
}

/** 검색형 scalar 피커 — 조각 교체/신규 추가/커서 삽입 공용. */
export function InlineBindingScalarPicker({
  candidates,
  t,
  onSelect,
  testIdSuffix,
  defaultOpen = false,
  floating = true,
}: InlineBindingScalarPickerProps): React.ReactElement {
  const [open, setOpen] = useState(defaultOpen);
  const [keyword, setKeyword] = useState('');

  const toggleRef = useRef<HTMLButtonElement | null>(null);

  const shaped = useMemo(() => {
    const byShape = filterCandidatesByShape(candidates, 'scalar');
    return byShape.map((c) => ({ ...c, resolvedLabel: resolveBindingLabel(t, c.labelKey, c.path || c.sourceId) }));
  }, [candidates, t]);
  const results = useMemo(() => searchCandidates(shaped, keyword), [shaped, keyword]);

  // 검색 입력 + 결과 목록 — floating(FloatingDropdown 내부) / 인라인(pickerBox) 양쪽이 공유.
  const panelBody = (
    <>
      <input
        type="text"
        autoFocus
        data-testid={`g7le-inline-binding-search-input-${testIdSuffix}`}
        value={keyword}
        placeholder={t('layout_editor.inline_binding.search_placeholder')}
        onChange={(e) => setKeyword(e.target.value)}
        style={searchInput}
      />
      <div style={resultList}>
        {results.length === 0 ? (
          <div style={noResult}>{t('layout_editor.inline_binding.no_results')}</div>
        ) : (
          results.map((c) => (
            <button
              key={c.expression}
              type="button"
              data-testid={`g7le-inline-binding-candidate-${c.expression}`}
              onClick={() => {
                onSelect(c);
                setOpen(false);
                setKeyword('');
              }}
              style={candidateBtn}
            >
              <span style={{ display: 'flex', flexDirection: 'column', alignItems: 'flex-start' }}>
                <span style={{ fontSize: 12 }}>{c.resolvedLabel}</span>
                <span style={candidateMeta}>
                  {resolveBindingLabel(t, c.groupLabelKey, c.sourceId)} · {c.path || c.sourceId}
                </span>
              </span>
              <span style={candidatePreview}>{c.preview}</span>
            </button>
          ))
        )}
      </div>
    </>
  );

  return (
    <div style={floating ? floatingWrap : { marginTop: 4 }}>
      <button
        ref={toggleRef}
        type="button"
        data-testid={`g7le-inline-binding-search-toggle-${testIdSuffix}`}
        onClick={() => setOpen((v) => !v)}
        // 버튼 폭 절약: "데이터 검색" 텍스트 제거하고 🔍 아이콘만. 라벨은
        // title/aria-label 로 접근(좁은 항목 행 폭 확보). 클릭 시 전체 검색 입력칸이 펼쳐진다.
        title={t('layout_editor.inline_binding.search')}
        aria-label={t('layout_editor.inline_binding.search')}
        aria-expanded={open}
        style={searchToggle}
      >
        🔍
      </button>
      {floating ? (
        // floating — 공용 FloatingDropdown 이 토글 기준 위치 자동 보정(flip/clamp). 진입점이 정렬을
        // 지정하지 않아도 좁은 조각 카드(좌측)·조건 행(우측) 어디서든 잘리지 않는다( —
        // 하드코딩 right:0 으로 데이터 조각이 좌측 쏠려 가려지던 결함 → 범용 위치 보정으로 대체).
        <FloatingDropdown
          anchorRef={toggleRef}
          open={open}
          onClose={() => setOpen(false)}
          testid={`g7le-inline-binding-picker-${testIdSuffix}`}
        >
          {panelBody}
        </FloatingDropdown>
      ) : (
        open && (
          // 인라인 — 문서 흐름 펼침([속성]탭처럼 충분한 폭 영역).
          <div data-testid={`g7le-inline-binding-picker-${testIdSuffix}`} style={pickerBox}>
            {panelBody}
          </div>
        )
      )}
    </div>
  );
}

// 아이콘만(🔍) 버튼. 전폭(width:100%) 제거하고 내용 폭으로 좁힌다(좁은 항목 행 폭 절약).
const searchToggle: React.CSSProperties = { fontSize: 13, lineHeight: 1, border: '1px solid #cbd5e1', borderRadius: 6, background: '#fff', color: '#334155', padding: '4px 8px', cursor: 'pointer', textAlign: 'center', whiteSpace: 'nowrap' };
const pickerBox: React.CSSProperties = { marginTop: 4, border: '1px solid #cbd5e1', borderRadius: 6, background: '#fff', padding: 6 };
// 부유 모드 토글 컨테이너 — 인라인 블록(토글 폭만). 패널 위치 보정은 공용 FloatingDropdown 이 담당.
const floatingWrap: React.CSSProperties = { display: 'inline-block' };
const searchInput: React.CSSProperties = { width: '100%', padding: '5px 8px', fontSize: 12, border: '1px solid #cbd5e1', borderRadius: 6, boxSizing: 'border-box' };
const resultList: React.CSSProperties = { marginTop: 4, maxHeight: 200, overflowY: 'auto', display: 'flex', flexDirection: 'column', gap: 2 };
const noResult: React.CSSProperties = { fontSize: 11, color: '#94a3b8', padding: '8px 0', textAlign: 'center' };
const candidateBtn: React.CSSProperties = { display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 8, border: 'none', background: 'transparent', cursor: 'pointer', padding: '6px 8px', borderRadius: 4, textAlign: 'left' };
const candidateMeta: React.CSSProperties = { fontSize: 10, color: '#94a3b8' };
const candidatePreview: React.CSSProperties = { fontSize: 10, color: '#64748b', fontFamily: 'monospace', whiteSpace: 'nowrap' };
