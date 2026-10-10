// e2e:allow 이커머스 전수 실측 후속 B7 — 전용 Chrome 접근성 트리로 배송정책 등록 화면 재실측(이력 「Chrome MCP 정밀 점검 매트릭스」). 커밋 단위 E2E spec 은 후속 판단으로 이월
/**
 * @file LabelAutoLink.test.tsx
 * @description 입력칸·선택 상자·글상자가 바로 앞의 보이는 라벨 이름으로 읽히는지
 *
 * 레이아웃은 `<label class="form-label">우편번호</label><input …>` 처럼 라벨과 칸을 형제로만 놓고
 * `for`/`id` 로 잇지 않는다(관리자 레이아웃 약 460칸). 그래서 화면 낭독기는 칸을 이름 없이 읽었다
 * (이커머스 전수 실측 S5 — 배송정책 등록 회수지·배송비 칸, 2026-10-08). 칸 부품이 바로 앞 라벨
 * (또는 칸 하나만 든 상자의 바로 앞 라벨)을 찾아 `aria-labelledby` 로 잇는다.
 *
 * 이미 이름이 있는 칸(`aria-label`·`aria-labelledby`·라벨 안의 칸·`for` 로 이어진 칸)은 그대로 둔다.
 * 잇는 규칙은 곁 라벨 훅(`useAdjacentLabel`) 하나가 맡는다 — 스위치·태그 입력도 같은 판정을 따른다.
 *
 * @vitest-environment jsdom
 */

import React from 'react';
import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { Div } from '../Div';
import { Span } from '../Span';
import { Label } from '../Label';
import { Input } from '../Input';
import { Select } from '../Select';
import { Textarea } from '../Textarea';
import { Toggle } from '../../composite/Toggle';

const options = [
    { value: 'fixed', label: '고정 배송비' },
    { value: 'free', label: '무료' },
];

/**
 * 문서 안 모든 id 가 한 번씩만 쓰였는지 센다.
 *
 * @param root 검사할 요소
 * @returns 두 번 이상 쓰인 id 목록
 */
function duplicateIds(root: HTMLElement): string[] {
    const counts = new Map<string, number>();
    root.querySelectorAll('[id]').forEach((el) => counts.set(el.id, (counts.get(el.id) ?? 0) + 1));

    return [...counts.entries()].filter(([, n]) => n > 1).map(([id]) => id);
}

describe('라벨 자동 연결', () => {
    /**
     * @scenario case=c01
     * @effects form_control_named_by_preceding_label
     */
    it('바로 앞 라벨의 글자가 입력칸 이름이 된다', () => {
        render(
            <Div>
                <Label className="form-label">우편번호</Label>
                <Input type="text" value="" onChange={() => {}} />
            </Div>,
        );

        expect(screen.getByRole('textbox', { name: '우편번호' })).toBeInTheDocument();
    });

    /**
     * @scenario case=c02
     * @effects form_control_named_by_preceding_label
     */
    it('필수 표시가 든 라벨도 이름으로 읽힌다', () => {
        render(
            <Div>
                <Label className="form-label">
                    <Span>기본주소</Span>
                    <Span className="text-required">*</Span>
                </Label>
                <Input type="text" value="" onChange={() => {}} />
            </Div>,
        );

        expect(screen.getByRole('textbox', { name: /기본주소/ })).toBeInTheDocument();
    });

    /**
     * @scenario case=c03
     * @effects form_control_named_by_preceding_label
     */
    it('통화 표시와 함께 상자에 든 숫자칸도 상자 앞 라벨로 읽힌다', () => {
        render(
            <Div>
                <Label className="form-label">배송비</Label>
                <Div className="relative">
                    <Input type="number" value="3000" onChange={() => {}} />
                    <Span>KRW</Span>
                </Div>
            </Div>,
        );

        expect(screen.getByRole('spinbutton', { name: '배송비' })).toBeInTheDocument();
    });

    /**
     * @scenario case=c04
     * @effects form_control_named_by_preceding_label
     */
    it('라벨 칸·입력 칸으로 나뉜 행(라벨을 상자로 감싼 모양)도 잇는다', () => {
        render(
            <Div>
                <Div>
                    <Label>사이트 이름</Label>
                </Div>
                <Div>
                    <Input type="text" value="" onChange={() => {}} />
                    <Div>도움말</Div>
                </Div>
            </Div>,
        );

        expect(screen.getByRole('textbox', { name: '사이트 이름' })).toBeInTheDocument();
    });

    /**
     * @scenario case=c05
     * @effects form_control_named_by_preceding_label
     */
    it('글상자도 바로 앞 라벨로 읽힌다', () => {
        render(
            <Div>
                <Label>메모</Label>
                <Textarea value="" onChange={() => {}} />
            </Div>,
        );

        expect(screen.getByRole('textbox', { name: '메모' })).toBeInTheDocument();
    });

    /**
     * @scenario case=c06
     * @effects select_named_by_label_and_value
     */
    it('선택 상자 버튼은 라벨과 고른 값을 함께 읽는다', () => {
        render(
            <Div>
                <Label>배송비 부과 정책</Label>
                <Select options={options} value="fixed" onChange={() => {}} />
            </Div>,
        );

        expect(screen.getByRole('button', { name: '배송비 부과 정책 고정 배송비' })).toBeInTheDocument();
    });

    /**
     * @scenario case=c07
     * @effects select_named_by_label_and_value
     */
    it('기본 select(자식 option) 도 바로 앞 라벨로 읽힌다', () => {
        render(
            <Div>
                <Label>정렬</Label>
                <Select value="a" onChange={() => {}}>
                    <option value="a">최신순</option>
                </Select>
            </Div>,
        );

        expect(screen.getByRole('combobox', { name: '정렬' })).toBeInTheDocument();
    });

    /**
     * @scenario case=c08
     * @effects existing_names_preserved
     */
    it('이미 이름이 있는 칸은 바꾸지 않는다 — aria-label · for 연결 · 라벨 안의 칸', () => {
        render(
            <Div>
                <Div>
                    <Label>무시할 라벨</Label>
                    <Input type="text" aria-label="검색어" value="" onChange={() => {}} />
                </Div>
                <Div>
                    <Label htmlFor="email">이메일</Label>
                    <Input id="email" type="text" value="" onChange={() => {}} />
                </Div>
                <Div>
                    <Label>무시할 라벨 2</Label>
                    <Label>
                        <Input type="checkbox" checked={false} onChange={() => {}} />
                        <Span>사용</Span>
                    </Label>
                </Div>
                <Div>
                    <Label>무시할 라벨 3</Label>
                    <Select options={options} value="free" aria-label="정책" onChange={() => {}} />
                </Div>
            </Div>,
        );

        expect(screen.getByRole('textbox', { name: '검색어' })).toBeInTheDocument();
        expect(screen.getByRole('textbox', { name: '이메일' })).toBeInTheDocument();
        expect(screen.getByRole('checkbox', { name: '사용' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: '정책' })).toBeInTheDocument();
        expect(screen.getByRole('textbox', { name: '검색어' }).getAttribute('aria-labelledby')).toBeNull();
    });

    /**
     * @scenario case=c09
     * @effects ambiguous_label_not_linked
     */
    it('라벨 뒤 상자에 칸이 둘이면 어느 칸에도 잇지 않는다', () => {
        render(
            <Div>
                <Label>기간</Label>
                <Div className="flex">
                    <Input type="date" value="" onChange={() => {}} data-testid="from" />
                    <Span>~</Span>
                    <Input type="date" value="" onChange={() => {}} data-testid="to" />
                </Div>
            </Div>,
        );

        expect(screen.getByTestId('from').getAttribute('aria-labelledby')).toBeNull();
        expect(screen.getByTestId('to').getAttribute('aria-labelledby')).toBeNull();
    });

    /**
     * @scenario case=c10
     * @effects no_duplicate_ids
     */
    it('반복 행에서 라벨 id 가 겹치지 않고, 각 칸은 자기 행 라벨을 가리킨다', () => {
        const rows = ['교환', '반품', '교환', '반품'];
        const { container } = render(
            <Div>
                {rows.map((name, i) => (
                    <Div key={i}>
                        <Label>{`${name} 우편번호`}</Label>
                        <Input type="text" value="" onChange={() => {}} data-testid={`zip-${i}`} />
                    </Div>
                ))}
            </Div>,
        );

        expect(duplicateIds(container)).toEqual([]);
        rows.forEach((name, i) => {
            const input = screen.getByTestId(`zip-${i}`);
            const labelId = input.getAttribute('aria-labelledby');
            expect(labelId).toBeTruthy();
            expect(document.getElementById(labelId as string)?.textContent).toBe(`${name} 우편번호`);
        });
    });

    /**
     * @scenario case=c12
     * @effects ambiguous_label_not_linked
     */
    it('여러 줄을 묶는 그룹 제목 라벨(회원 등록 「주소」)은 첫 칸에 잇지 않는다 — 안내 글자 이름을 지킨다', () => {
        render(
            <Div>
                <Label>주소</Label>
                <Div className="flex">
                    <Input type="text" placeholder="우편번호" value="" onChange={() => {}} />
                    <Div>
                        <button type="button">주소 검색</button>
                    </Div>
                </Div>
                <Input type="text" placeholder="주소" value="" onChange={() => {}} />
                <Input type="text" placeholder="상세 주소" value="" onChange={() => {}} />
            </Div>,
        );

        // jsdom 의 이름 계산은 안내 글자를 이름으로 쓰지 않는다 — 브라우저에서는 안내 글자가 이름이 되므로
        // aria-labelledby 가 붙지 않았는지(안내 글자 이름이 덮이지 않는지)로 본다
        for (const placeholder of ['우편번호', '주소', '상세 주소']) {
            expect(screen.getByPlaceholderText(placeholder).getAttribute('aria-labelledby')).toBeNull();
        }
    });

    /**
     * @scenario case=c13
     * @effects form_control_named_by_preceding_label
     */
    it('FormField 처럼 라벨 칸 뒤 입력 칸 안에서 칸이 상자에 한 번 더 들어가도(두 단계) 잇는다', () => {
        render(
            <Div>
                <Div>
                    <Label>
                        <Span>최소주문금액</Span>
                    </Label>
                </Div>
                <Div>
                    <Div className="flex">
                        <Input type="number" value="0" onChange={() => {}} />
                        <Span>원</Span>
                    </Div>
                    <Div>도움말</Div>
                </Div>
            </Div>,
        );

        expect(screen.getByRole('spinbutton', { name: '최소주문금액' })).toBeInTheDocument();
    });

    /**
     * @scenario case=c14
     * @effects form_control_named_by_preceding_label
     */
    it('라벨·칸이 번갈아 놓인 한 줄에서 각 칸은 자기 라벨만 받는다', () => {
        render(
            <Div className="grid">
                <Label>가로</Label>
                <Input type="number" value="1" onChange={() => {}} />
                <Label>세로</Label>
                <Input type="number" value="2" onChange={() => {}} />
            </Div>,
        );

        expect(screen.getByRole('spinbutton', { name: '가로' })).toBeInTheDocument();
        expect(screen.getByRole('spinbutton', { name: '세로' })).toBeInTheDocument();
    });

    /**
     * @scenario case=c11
     * @effects no_duplicate_ids
     */
    it('레이아웃이 같은 id 를 여러 라벨에 준 경우(반복 안의 고정 id)는 엉뚱한 라벨을 잇지 않는다', () => {
        render(
            <Div>
                <Div>
                    <Label id="dup_label">첫째</Label>
                    <Input type="text" value="" onChange={() => {}} data-testid="first" />
                </Div>
                <Div>
                    <Label id="dup_label">둘째</Label>
                    <Input type="text" value="" onChange={() => {}} data-testid="second" />
                </Div>
            </Div>,
        );

        expect(screen.getByTestId('second').getAttribute('aria-labelledby')).toBeNull();
    });
    /**
     * @scenario case=c15
     * @effects no_duplicate_ids
     */
    it('자동으로 다는 라벨 id 는 문서에 이미 있는 id 를 피한다', () => {
        const w = window as unknown as { __g7AutoLabelSeq?: number };
        const seq = w.__g7AutoLabelSeq ?? 0;
        // 다음 두 일련번호 자리를 다른 요소가 이미 쓰고 있다(다른 번들·다른 부품이 단 id)
        const { container } = render(
            <Div>
                <Span id={`g7-auto-label-${seq + 1}`}>다른 요소</Span>
                <Span id={`g7-auto-label-${seq + 2}`}>또 다른 요소</Span>
                <Div>
                    <Label>상호</Label>
                    <Input type="text" value="" onChange={() => {}} data-testid="name" />
                </Div>
            </Div>,
        );

        expect(duplicateIds(container)).toEqual([]);
        const labelId = screen.getByTestId('name').getAttribute('aria-labelledby');
        // 부품 공통의 일련번호(window)를 이어 쓰고, 이미 있는 두 자리를 건너뛴다
        expect(labelId).toBe(`g7-auto-label-${seq + 3}`);
        expect(document.getElementById(labelId as string)?.textContent).toBe('상호');
        expect(screen.getByRole('textbox', { name: '상호' })).toBeInTheDocument();
    });

    /**
     * @scenario case=c16
     * @effects ambiguous_label_not_linked
     */
    it('스위치도 같은 판정을 따른다 — 스위치 둘을 묶는 그룹 제목은 어느 스위치에도 잇지 않는다', () => {
        render(
            <Div>
                <Label>알림 채널</Label>
                <Div className="flex">
                    <Toggle name="notify_mail" />
                    <Toggle name="notify_sms" />
                </Div>
            </Div>,
        );

        expect(screen.queryAllByRole('switch', { name: '알림 채널' })).toHaveLength(0);
        expect(screen.getByRole('switch', { name: 'notify_mail' })).toBeInTheDocument();
        expect(screen.getByRole('switch', { name: 'notify_sms' })).toBeInTheDocument();
    });
});
