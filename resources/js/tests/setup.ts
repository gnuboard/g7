/**
 * Vitest 테스트 환경 설정
 */

import '@testing-library/jest-dom';
import { vi } from 'vitest';

// 전역 mocks
global.fetch = vi.fn();

/**
 * window.matchMedia 모킹
 * 테스트 환경에서 CSS 미디어 쿼리를 지원하기 위함
 */
Object.defineProperty(window, 'matchMedia', {
  writable: true,
  value: vi.fn().mockImplementation((query: string) => ({
    matches: false,
    media: query,
    onchange: null,
    addListener: vi.fn(),
    removeListener: vi.fn(),
    addEventListener: vi.fn(),
    removeEventListener: vi.fn(),
    dispatchEvent: vi.fn(),
  })),
});

/**
 * 테스트용 번역 맵
 * 컴포넌트에서 사용하는 번역 키에 대한 한글 번역
 */
const testTranslations: Record<string, string> = {
  // 공통
  'common.close': '닫기',
  'common.close_modal': '모달 닫기',
  'common.close_dialog': '대화상자 닫기',
  'common.close_notification': '알림 닫기',
  'common.confirm': '확인',
  'common.cancel': '취소',
  'common.save': '저장',
  'common.delete': '삭제',
  'common.edit': '편집',
  'common.edit_layout': '레이아웃 편집',
  'common.search': '검색',
  'common.search_placeholder': '검색...',
  'common.loading': '로딩 중...',
  'common.first_page': '첫 페이지',
  'common.last_page': '마지막 페이지',
  'common.previous_page': '이전 페이지',
  'common.prev_page': '이전 페이지',
  'common.next_page': '다음 페이지',
  'common.page': '페이지',
  'common.page_n': '페이지 {{n}}',
  'common.pagination': '페이지네이션',
  'common.activate': '활성화',
  'common.deactivate': '비활성화',
  'common.enabled': '활성화됨',
  'common.disabled': '비활성화됨',
  'common.update_available': '업데이트 가능',
  'common.preview': '미리보기',
  'common.dependencies': '의존성',
  'common.no_data': '데이터가 없습니다',
  'common.notifications': '알림',
  'common.no_notifications': '알림이 없습니다',
  'common.profile_settings': '프로필 설정',
  'common.logout': '로그아웃',
  'common.reset': '초기화',
  'common.filter': '필터',
  // 상태
  'common.status_active': '활성화',
  'common.status_inactive': '비활성화',
  'common.status_pending': '대기중',
  'common.status_error': '오류',
  // 토스트
  'toast.close': '알림 닫기',
  // 템플릿
  'template.activate': '활성화',
  'template.deactivate': '비활성화',
  'template.update_available': '업데이트 가능',
  'template.edit_layout': '레이아웃 편집',
  'template.preview': '미리보기',
  'common.template_preview': '{{vendor}}/{{name}} 미리보기',
  // 사이드바
  'sidebar.collapse': '사이드바 접기',
  'sidebar.expand': '사이드바 펼치기',
  'common.collapse_sidebar': '사이드바 접기',
  'common.expand_sidebar': '사이드바 펼치기',
};

/**
 * G7Core 전역 모킹
 * 컴포넌트에서 window.G7Core를 사용하므로 테스트 환경에서 모킹 필요
 */
(window as any).G7Core = {
  createChangeEvent: vi.fn((data: { checked?: boolean; name?: string; value?: any }) => ({
    target: {
      checked: data.checked ?? false,
      name: data.name ?? '',
      value: data.value ?? (data.checked ? 'on' : 'off'),
      type: 'checkbox',
    },
    currentTarget: {
      checked: data.checked ?? false,
      name: data.name ?? '',
      value: data.value ?? (data.checked ? 'on' : 'off'),
      type: 'checkbox',
    },
    preventDefault: vi.fn(),
    stopPropagation: vi.fn(),
  })),
  state: {
    get: vi.fn(),
    set: vi.fn(),
    update: vi.fn(),
    subscribe: vi.fn(() => vi.fn()),
  },
  toast: {
    success: vi.fn(),
    error: vi.fn(),
    warning: vi.fn(),
    info: vi.fn(),
  },
  modal: {
    open: vi.fn(),
    close: vi.fn(),
  },
  events: {
    emit: vi.fn(),
    on: vi.fn(() => vi.fn()),
    off: vi.fn(),
  },
  t: (key: string, params?: Record<string, string | number>) => {
    // 번역 맵에서 찾거나, 키 자체를 반환
    let translation = testTranslations[key] ?? key;
    // params가 있으면 치환
    if (params) {
      Object.entries(params).forEach(([paramKey, paramValue]) => {
        translation = translation.replace(`{{${paramKey}}}`, String(paramValue));
      });
    }
    return translation;
  },
};
