/**
 * 회귀: 편집기 자산 실패 시 폴백이 저장된 본문을 잃지 않는다 (공개 #123)
 *
 * 결함: 수정 화면은 폼 데이터(`.../posts/form-data`)가 도착하기 전에 첫 렌더가 일어나므로
 * 컴포넌트가 캡처한 `params.content` 가 빈 문자열이 된다. 편집기 확보는 `await` 이라 그 사이에
 * 데이터가 도착하는데, 폴백 경로가 캡처값을 그대로 쓰면 **도착한 본문을 보지 못한 채** 빈
 * textarea 를 세우고 `form.content` 를 빈 문자열로 덮어썼다. 그 상태로 저장하면 본문이 통째로
 * 사라진다 — 예외도 경고도 없이, 화면상 "내용이 원래 없던 글" 로 보인다.
 *
 * 빈 문자열은 `'{{'` 로 시작하지 않아 "정상 해석된 값" 처럼 보이는 것이 함정이었다.
 * 그래서 빈 값도 미해석과 같이 취급해 **호출 시점의** 라이브 폼 상태로 내려가야 한다.
 */
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { resolveSingleContent, resolveContentMap } from '../../handlers/initEditor';

/**
 * G7Core 전역 스텁을 설치합니다.
 *
 * @param form 로컬 폼 상태
 * @param selectedItem 전역 selectedItem
 * @return void
 */
function stubG7Core(form: Record<string, any>, selectedItem?: Record<string, any>): void {
    (window as any).G7Core = {
        state: {
            getLocal: () => ({ form }),
            get: () => ({ _global: { selectedItem } }),
        },
    };
}

describe('편집기 초기 본문 해석 (폴백 데이터 손실 회귀)', () => {
    beforeEach(() => {
        stubG7Core({});
    });

    afterEach(() => {
        delete (window as any).G7Core;
    });

    describe('단일 모드', () => {
        it('캡처값이 빈 문자열이면 라이브 폼 상태의 본문을 쓴다 (수정 화면 회귀)', () => {
            stubG7Core({ content: '저장돼 있던 본문' });

            expect(resolveSingleContent({ content: '' }, 'content')).toBe('저장돼 있던 본문');
        });

        it('캡처값이 미해석 표현식이어도 라이브 폼 상태로 내려간다', () => {
            stubG7Core({ content: '저장돼 있던 본문' });

            expect(resolveSingleContent({ content: '{{_local.form.content}}' }, 'content')).toBe('저장돼 있던 본문');
        });

        it('캡처값이 실제 본문이면 그 값을 그대로 쓴다 (라이브 상태가 덮지 않는다)', () => {
            stubG7Core({ content: '나중에 도착한 다른 값' });

            expect(resolveSingleContent({ content: '캡처된 본문' }, 'content')).toBe('캡처된 본문');
        });

        it('양쪽 다 비어 있으면 빈 문자열 (작성 화면 정상 경로)', () => {
            expect(resolveSingleContent({ content: '' }, 'content')).toBe('');
        });
    });

    describe('다국어 모드', () => {
        it('캡처값이 비면 라이브 폼 상태의 로케일 맵을 쓴다', () => {
            stubG7Core({ content: { ko: '한국어 본문', en: 'English body' } });

            expect(resolveContentMap({ content: '' }, 'content')).toEqual({ ko: '한국어 본문', en: 'English body' });
        });

        it('폼 상태가 비면 _global.selectedItem 으로 한 단계 더 내려간다', () => {
            stubG7Core({}, { content: { ko: '선택 항목 본문' } });

            expect(resolveContentMap({ content: '' }, 'content')).toEqual({ ko: '선택 항목 본문' });
        });

        it('API 기본값인 빈 배열은 본문으로 취급하지 않는다', () => {
            stubG7Core({ content: [] });

            expect(resolveContentMap({ content: '' }, 'content')).toEqual({});
        });
    });
});
