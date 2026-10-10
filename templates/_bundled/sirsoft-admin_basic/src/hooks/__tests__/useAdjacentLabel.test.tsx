/**
 * @file useAdjacentLabel.test.tsx
 * @description 곁 라벨을 입력 부품 이름으로 잇기 (7.1.0 영역 J D-J7, 관측 O-G2·O-H2)
 *
 * 관리자 레이아웃은 라벨과 입력을 형제로 둔다(`for` 없음). 그래서 숫자 칸은 placeholder 값(「30」),
 * 스위치는 필드 키(「security.force_https」), 선택 상자는 고른 값(「Artisan」)으로 읽혔다.
 */

import React from 'react';
import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { Input } from '../../components/basic/Input';
import { Select } from '../../components/basic/Select';
import { Textarea } from '../../components/basic/Textarea';
import { Toggle } from '../../components/composite/Toggle';
import { TagInput } from '../../components/composite/TagInput';

describe('곁 라벨을 입력 이름으로 잇는다', () => {
  /**
   * @scenario control=input_number
   * @effects form_controls_named_by_adjacent_label
   */
  it('형제 label(form-label) 다음 숫자 칸은 라벨 문구로 읽힌다(placeholder 값 아님)', () => {
    render(
      <div>
        <label className="form-label">인증 토큰 유지시간</label>
        <Input type="number" name="security.auth_token_lifetime" placeholder="30" />
      </div>
    );
    expect(screen.getByRole('spinbutton', { name: '인증 토큰 유지시간' })).toBeTruthy();
  });

  /**
   * @scenario control=input_number
   * @effects form_controls_named_by_adjacent_label
   */
  it('라벨이 한 겹 안쪽 상자에 있고 설명 문단이 끼어 있어도 잇는다(게시판 보유기간 칸 구조)', () => {
    render(
      <div>
        <div className="flex-center"><label className="form-label">IP 보유 기간(일)</label></div>
        <p>설명</p>
        <Input type="number" name="privacy_retention.ip_retention_days" />
      </div>
    );
    expect(screen.getByRole('spinbutton', { name: 'IP 보유 기간(일)' })).toBeTruthy();
  });

  /**
   * @scenario control=switch
   * @effects form_controls_named_by_adjacent_label
   */
  it('스위치 행의 제목(text-heading)이 스위치 이름이 된다(필드 키 아님)', () => {
    render(
      <div className="flex-between">
        <div>
          <span className="text-heading">HTTPS 강제 적용</span>
          <p>설명</p>
        </div>
        <Toggle name="security.force_https" />
      </div>
    );
    expect(screen.getByRole('switch', { name: 'HTTPS 강제 적용' })).toBeTruthy();
    expect(screen.queryByRole('switch', { name: 'security.force_https' })).toBeNull();
  });

  /**
   * @scenario control=select
   * @effects form_controls_named_by_adjacent_label
   */
  it('선택 상자는 「라벨 + 현재 값」 으로 읽힌다', () => {
    render(
      <div>
        <label className="form-label">작업 유형</label>
        <Select options={[{ value: 'artisan', label: 'Artisan' }]} value="artisan" onChange={() => {}} />
      </div>
    );
    expect(screen.getByRole('button', { name: '작업 유형 Artisan' })).toBeTruthy();
  });

  /**
   * @scenario control=textarea
   * @effects form_controls_named_by_adjacent_label
   */
  it('여러 줄 칸도 곁 라벨로 읽힌다', () => {
    render(
      <div>
        <label className="form-label">사이트 설명</label>
        <Textarea name="general.site_description" placeholder="설명을 입력" />
      </div>
    );
    expect(screen.getByRole('textbox', { name: '사이트 설명' })).toBeTruthy();
  });

  /**
   * @scenario control=explicit_name
   * @effects explicit_name_wins_over_adjacent_label
   */
  it('스스로 이름이 있으면(aria-label·ariaLabel·label) 곁 라벨로 덮지 않는다', () => {
    render(
      <div>
        <label className="form-label">곁 라벨</label>
        <Input name="a" aria-label="직접 이름" />
        <Toggle name="b" ariaLabel="규칙 사용" />
      </div>
    );
    expect(screen.getByRole('textbox', { name: '직접 이름' })).toBeTruthy();
    expect(screen.getByRole('switch', { name: '규칙 사용' })).toBeTruthy();
  });

  /**
   * @scenario control=switch_without_label
   * @effects explicit_name_wins_over_adjacent_label
   */
  it('곁에 라벨이 없으면 종전대로 name 을 마지막 수단으로 쓴다', () => {
    render(<Toggle name="use_file_upload" />);
    expect(screen.getByRole('switch', { name: 'use_file_upload' })).toBeTruthy();
  });

  /**
   * @scenario control=input_number
   * @effects form_controls_named_by_adjacent_label
   */
  it('입력을 감싼 래퍼 label·다른 곳을 가리키는 for 라벨은 이름표로 집지 않는다', () => {
    render(
      <div>
        <label htmlFor="other">다른 칸</label>
        <label className="form-label"><input type="checkbox" /> 감싼 라벨</label>
        <Input name="c" placeholder="자리표시" />
      </div>
    );
    expect(screen.queryByRole('textbox', { name: '다른 칸' })).toBeNull();
    expect(screen.queryByRole('textbox', { name: /감싼 라벨/ })).toBeNull();
  });
});

describe('스위치 안쪽 체크박스', () => {
  /**
   * @scenario control=switch
   * @effects form_controls_named_by_adjacent_label
   */
  it('스위치(role=switch) 안의 실제 체크박스는 접근성 트리에서 숨겨 이름 없는 체크박스가 따로 읽히지 않는다', () => {
    render(
      <div>
        <span className="text-heading">로그인 시도 제한</span>
        <Toggle name="security.login_attempt_enabled" />
      </div>
    );
    expect(screen.queryAllByRole('checkbox')).toHaveLength(0);
    expect(screen.getByRole('switch', { name: '로그인 시도 제한' })).toBeTruthy();
  });
});

describe('곁 라벨 규칙 보강', () => {
  /**
   * @scenario control=switch_label_after
   * @effects form_controls_named_by_adjacent_label
   */
  it('스위치 뒤에 제목을 두는 배치(고급 탭 캐시 항목)도 그 제목을 이름으로 쓴다', () => {
    render(
      <div className="flex-center gap-3">
        <Toggle name="advanced.layout_cache_enabled" />
        <div><span className="text-heading">레이아웃 캐시</span><p>설명</p></div>
      </div>
    );
    expect(screen.getByRole('switch', { name: '레이아웃 캐시' })).toBeTruthy();
  });

  /**
   * @scenario control=select_filter_label
   * @effects form_controls_named_by_adjacent_label
   */
  it('목록 필터 줄 라벨(filter-label)도 이름표로 쓴다', () => {
    render(
      <div className="filter-row">
        <span className="filter-label">작업유형</span>
        <Select options={[{ value: '', label: '전체' }]} value="" onChange={() => {}} />
      </div>
    );
    expect(screen.getByRole('button', { name: '작업유형 전체' })).toBeTruthy();
  });

  /**
   * @scenario control=select_placeholder
   * @effects form_controls_named_by_adjacent_label
   */
  it('고른 값이 없는 선택 상자는 placeholder 를 보이고 그것으로 읽힌다(빈 상자 아님)', () => {
    render(<Select options={[{ value: 'a', label: '활성화' }]} value="" placeholder="상태 변경" onChange={() => {}} />);
    const button = screen.getByRole('button', { name: '상태 변경' });
    expect(button.textContent).toContain('상태 변경');
  });
});

describe('곁 라벨 오연결 방지', () => {
  /**
   * @scenario control=switch_after_other_field
   * @effects adjacent_label_never_borrows_previous_field
   */
  it('앞 칸 라벨을 빌리지 않는다 — 라벨과 스위치 사이에 다른 입력이 있으면 그 라벨은 앞 칸 것이다(스케줄 추가 창)', () => {
    render(
      <form>
        <div>
          <label className="form-label">타임아웃 (초)</label>
          <Input type="number" name="timeout" />
        </div>
        <div className="row-stack">
          <div className="flex-between">
            <div>
              <span className="text-sm font-medium">중복 실행 방지</span>
              <p>이전 작업이 실행 중일 때 건너뜁니다</p>
            </div>
            <Toggle name="without_overlapping" />
          </div>
          <div className="flex-between">
            <div>
              <span className="text-sm font-medium">점검 모드에서 실행</span>
            </div>
            <Toggle name="run_in_maintenance" />
          </div>
        </div>
      </form>
    );
    expect(screen.getByRole('spinbutton', { name: '타임아웃 (초)' })).toBeTruthy();
    expect(screen.getByRole('switch', { name: '중복 실행 방지' })).toBeTruthy();
    expect(screen.getByRole('switch', { name: '점검 모드에서 실행' })).toBeTruthy();
    expect(screen.queryAllByRole('switch', { name: '타임아웃 (초)' })).toHaveLength(0);
  });
});

describe('태그 입력 곁 라벨', () => {
  /**
   * @scenario control=tag_input
   * @effects form_controls_named_by_adjacent_label
   */
  it('형제 라벨 문구가 태그 입력칸(combobox) 이름이 된다(환경설정 SEO 「추가 봇 패턴」)', () => {
    render(
      <div>
        <label className="form-label">추가 봇 패턴</label>
        <TagInput value={[]} options={[]} onChange={() => {}} creatable />
      </div>
    );
    expect(screen.getByRole('combobox', { name: '추가 봇 패턴' })).toBeTruthy();
  });
});

describe('곁 글자 오연결 방지 — 단추 글자', () => {
  /**
   * 스위치 바로 앞 형제가 단추면 그 글자는 행 제목이 아니다(확장 카드 「업데이트」 단추, O-Q2-1).
   *
   * @scenario control=switch_after_button
   * @effects adjacent_label_never_borrows_button_text
   */
  it('스위치 앞에 단추만 있으면 단추 글자를 스위치 이름으로 집지 않는다', () => {
    render(
      <div className="flex-center gap-2">
        <button type="button">업데이트</button>
        <Toggle name="extension_active" />
      </div>
    );
    expect(screen.queryAllByRole('switch', { name: '업데이트' })).toHaveLength(0);
  });

  /**
   * 단추 건너뛰기는 앞 형제 안의 단추·링크에만 적용한다 — 행 전체를 감싼 클릭 카드(`role=button`,
   * 확장 카드 `ext_card` 와 같은 배치)까지 단추로 보면 그 안의 모든 글자를 건너뛰어 스위치가 이름을 잃는다.
   *
   * @scenario control=switch_in_clickable_card
   * @effects adjacent_label_never_borrows_button_text
   */
  it('클릭할 수 있는 카드(role=button) 안의 스위치 행은 여전히 행 제목을 이름으로 쓴다', () => {
    render(
      <div role="button" tabIndex={0} aria-label="상세 열기">
        <div className="flex-between">
          <div>
            <span className="text-sm font-medium">자동 갱신</span>
          </div>
          <Toggle name="auto_refresh" />
        </div>
      </div>
    );
    expect(screen.getByRole('switch', { name: '자동 갱신' })).toBeTruthy();
  });
});
