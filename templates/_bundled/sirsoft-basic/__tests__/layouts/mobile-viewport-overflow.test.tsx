/**
 * @file mobile-viewport-overflow.test.tsx
 * @description 390px(iPhone) 모바일 뷰포트 가로 오버플로/압착 회귀 차단
 *
 * 배경 (Chrome DevTools 390x844x3, mobile UA 실측):
 * - 헤더 우측 그룹에 언어(71px)+통화(91px)가 있어 26개 라우트 중 24개에서
 *   document.scrollWidth = 401px (뷰포트 390px 대비 +11px). 320px(iPhone SE)에서는
 *   햄버거 버튼이 화면 밖으로 밀려 내비게이션 자체가 불가능했다.
 *   → 언어/통화를 드로어로 이동 (currencySelector.test.tsx 가 배치를 단언)
 * - 비회원 댓글폼: 아바타 76px 들여쓰기 + flex-1 입력 2개 → 문서 +81px 초과
 * - 비회원 글쓰기폼: grid-cols-2 → 각 입력 147px 로 압착, placeholder 잘림
 * - 상품상세 쿠폰 칩: 라벨 Span 에 className 이 없어 39px 폭에서 2줄로 줄바꿈
 * - 부분환불 모달: 91px 3열에서 한글 쿠폰명이 단어 중간에서 잘림
 * - 해외주소 도시/주: 무접두 grid-cols-2 → 390px 에서 각 입력 155px
 * - 게시글 상단 네비: 게시판명이 길수록 좌우 버튼을 압착 (390px 실측 — 5자 '이전글' 87px,
 *   11자 73px, 21자 62px 로 chevron 과 텍스트가 겹침). docOverflow 는 0 이라 오버플로
 *   검사로는 잡히지 않는다.
 *
 * 이 파일은 위 좌표들이 되돌아가지 않도록 레이아웃 JSON 구조를 직접 단언한다.
 * 2열 행의 좁은 화면 대비는 두 방식 모두 허용한다: Tailwind 접두(`grid-cols-1 sm:grid-cols-2`)
 * 또는 엔진 노드 레벨 `responsive.portable`(0~1023px) 오버라이드.
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

const layoutsDir = path.resolve(__dirname, '../../layouts');

/**
 * 레이아웃 JSON 을 로드합니다.
 *
 * @param relativePath layouts/ 기준 상대 경로
 * @return 파싱된 레이아웃 객체
 */
function loadLayout(relativePath: string): any {
  return JSON.parse(fs.readFileSync(path.join(layoutsDir, relativePath), 'utf-8'));
}

/**
 * 술어를 만족하는 첫 노드를 찾습니다.
 *
 * @param node 탐색 시작 노드/배열
 * @param predicate 판정 함수
 * @return 찾은 노드 (없으면 null)
 */
function findNode(node: any, predicate: (n: any) => boolean): any {
  if (!node || typeof node !== 'object') return null;
  if (Array.isArray(node)) {
    for (const n of node) {
      const r = findNode(n, predicate);
      if (r) return r;
    }
    return null;
  }
  if (predicate(node)) return node;
  for (const k of ['children', 'components']) {
    if (node[k]) {
      const r = findNode(node[k], predicate);
      if (r) return r;
    }
  }
  return null;
}

/**
 * 술어를 만족하는 모든 노드를 찾습니다.
 *
 * @param node 탐색 시작 노드/배열
 * @param predicate 판정 함수
 * @param acc 누적 배열 (재귀 내부용)
 * @return 찾은 노드 배열
 */
function findAllNodes(node: any, predicate: (n: any) => boolean, acc: any[] = []): any[] {
  if (!node || typeof node !== 'object') return acc;
  if (Array.isArray(node)) {
    for (const n of node) findAllNodes(n, predicate, acc);
    return acc;
  }
  if (predicate(node)) acc.push(node);
  for (const k of ['children', 'components']) {
    if (node[k]) findAllNodes(node[k], predicate, acc);
  }
  return acc;
}

describe('게시판 비회원 입력 행 — 좁은 화면에서 세로 스택', () => {
  it('댓글 입력폼의 이름/비밀번호 행이 flex-col sm:flex-row 다 (+81px 오버플로 회귀)', () => {
    const layout = loadLayout('partials/board/show/_comment_input.json');
    const guestRow = findNode(
      layout,
      (n) => typeof n.if === 'string' && n.if.includes('!_global.currentUser?.uuid')
    );
    expect(guestRow).toBeTruthy();

    const cls: string = guestRow.props?.className ?? '';
    expect(cls).toContain('flex-col');
    expect(cls).toContain('sm:flex-row');
  });

  it('글쓰기폼의 이름/비밀번호 행이 grid-cols-1 sm:grid-cols-2 다 (147px 압착 회귀)', () => {
    const layout = loadLayout('partials/board/form/_post_form.json');
    const guestRow = findNode(
      layout,
      (n) =>
        typeof n.if === 'string' &&
        n.if.includes('!_global.currentUser?.uuid') &&
        n.if.includes('!route.id')
    );
    expect(guestRow).toBeTruthy();

    const cls: string = guestRow.props?.className ?? '';
    expect(cls).toContain('grid-cols-1');
    expect(cls).toContain('sm:grid-cols-2');
    // 무접두 grid-cols-2 는 모바일에서 그대로 2열이 된다
    expect(cls).not.toMatch(/(^|\s)grid-cols-2(\s|$)/);
  });
});

describe('상품상세 쿠폰 칩 — 라벨 한 줄, 목록은 여러 줄', () => {
  const layout = loadLayout('partials/shop/detail/_info_summary.json');

  it('칩 컨테이너가 가로 스크롤 대신 줄바꿈한다', () => {
    const container = findNode(
      layout,
      (n) => typeof n.comment === 'string' && n.comment.includes('쿠폰 배지 목록')
    );
    expect(container).toBeTruthy();

    const cls: string = container.props?.className ?? '';
    expect(cls).toContain('flex-wrap');
    // 실측상 스크롤되지 않았고(scrollWidth == clientWidth), 칩이 숨겨질 뿐이었다
    expect(cls).not.toContain('overflow-x-auto');
  });

  it('칩 라벨(할인 금액)이 whitespace-nowrap 으로 한 줄을 유지한다', () => {
    const label = findNode(
      layout,
      (n) => n.name === 'Span' && typeof n.text === 'string' && n.text.includes('benefit_formatted')
    );
    expect(label).toBeTruthy();
    expect(label.props?.className ?? '').toContain('whitespace-nowrap');
  });
});

describe('Modal 좌우 여백 — max-w-full 이 clamp 를 이기지 못하게', () => {
  /**
   * Modal.tsx 는 `max-w-[calc(100vw-2rem)]` 로 화면 좌우 16px 여백을 보장한다.
   * 그런데 그 뒤에 `${className}` 이 붙으므로, 레이아웃이 `max-w-full`(=100%)을 주면
   * CSS 우선순위상 100% 가 이겨 모달이 화면에 딱 붙는다(390px 실측: left=0, right=390).
   *
   * Modal 이 스스로 clamp 하므로 `max-w-full` 은 불필요하며 해롭다.
   */
  const modalFiles = [
    'partials/mypage/orders/_modal_cancel.json',
    'partials/mypage/orders/_modal_confirm_purchase.json',
    'partials/mypage/orders/_modal_write_review.json',
  ];

  for (const file of modalFiles) {
    it(`${file} 의 Modal props.className 에 max-w-full 이 없다`, () => {
      const layout = loadLayout(file);
      expect(layout.name).toBe('Modal');
      const cls: string = layout.props?.className ?? '';
      expect(cls.split(/\s+/)).not.toContain('max-w-full');
    });
  }

  it('Modal.tsx 패널이 max-w-[calc(100vw-2rem)] 로 화면 폭을 clamp 한다', () => {
    const tsx = fs.readFileSync(
      path.resolve(__dirname, '../../src/components/composite/Modal.tsx'),
      'utf-8'
    );
    expect(tsx).toContain('max-w-[calc(100vw-2rem)]');
  });
});

describe('부분환불 계산 모달 — 좁은 열에서 단어 중간 줄바꿈 금지', () => {
  const layout = loadLayout('partials/mypage/orders/_modal_cancel.json');

  it('행 라벨(상품쿠폰 할인 등)이 모두 break-keep 이다', () => {
    // 390px 에서 3열 grid 의 첫 열은 71px — keep-all 이 없으면 "상품쿠폰 할" / "인" 으로 잘린다
    const labels = findAllNodes(
      layout,
      (n) =>
        n.name === 'Span' &&
        typeof n.text === 'string' &&
        n.text.startsWith('$t:mypage.order_detail.cancel_modal.')
    ).filter((n) => (n.props?.className ?? '').includes('text-gray-600'));

    expect(labels.length).toBeGreaterThan(0);
    for (const node of labels) {
      expect(node.props.className).toContain('break-keep');
    }
  });

  it('쿠폰 상세 행(쿠폰명 + 할인액)이 모두 break-keep 이다', () => {
    // `{{pc.name}} -{{amount}}원` 형태의 P 노드 — 91px 3열에서 한글 단어가 중간에서 잘렸다
    const couponLines = findAllNodes(
      layout,
      (n) =>
        n.name === 'P' &&
        typeof n.text === 'string' &&
        n.text.includes('.name}}') &&
        n.text.includes('discount_amount')
    );
    expect(couponLines.length).toBeGreaterThan(0);

    for (const node of couponLines) {
      expect(node.props?.className ?? '').toContain('break-keep');
    }
  });

  it('복원 쿠폰 행은 쿠폰명 break-keep + 금액 whitespace-nowrap 이다', () => {
    const name = findNode(
      layout,
      (n) => n.name === 'Span' && typeof n.text === 'string' && n.text.includes('coupon.coupon_name')
    );
    expect(name).toBeTruthy();
    expect(name.props?.className ?? '').toContain('break-keep');

    const amount = findNode(
      layout,
      (n) =>
        n.name === 'Span' &&
        typeof n.text === 'string' &&
        n.text.includes('coupon.discount_amount')
    );
    expect(amount).toBeTruthy();
    expect(amount.props?.className ?? '').toContain('whitespace-nowrap');
  });
});

describe('게시글 상단 네비 — 긴 게시판명이 좌우 버튼을 압착하지 않는다', () => {
  const layout = loadLayout('partials/board/show/_navigation.json');

  it('컨테이너가 모바일에서 줄바꿈한다 (flex-wrap)', () => {
    // 셋을 한 줄에 두면 게시판명이 남은 폭을 먹고 형제를 눌러버린다.
    // 390px 실측: '이전글' 버튼 폭이 5자 87px → 11자 73px → 21자 62px 로 줄었다.
    expect(layout.props.className).toContain('justify-between');

    // portable(0~1023) 이 아니라 mobile(0~767). 버튼 3개 고정폭 261px + 21자 제목 자연폭
    // 323px = 584px 이므로 태블릿(콘텐츠 735px)은 한 줄로 충분하다.
    expect(layout.responsive?.portable).toBeUndefined();
    const mobile: string = layout.responsive?.mobile?.props?.className ?? '';
    expect(mobile).toContain('flex-wrap');
    expect(mobile).toContain('justify-between');
    // 줄바꿈 시 두 행이 붙지 않도록 세로 gap 이 필요하다
    expect(mobile).toMatch(/\bgap-y-\d/);
  });

  it('게시판명이 모바일에서 자기 줄을 차지한다 (order-first w-full)', () => {
    const title = findNode(
      layout,
      (n) => n.name === 'Span' && typeof n.text === 'string' && n.text.includes('board?.name')
    );
    expect(title).toBeTruthy();

    const mobile: string = title.responsive?.mobile?.props?.className ?? '';
    const tokens = mobile.split(/\s+/);
    expect(tokens).toContain('order-first');
    expect(tokens).toContain('w-full');
  });

  it('목록 버튼과 이전/다음 그룹은 shrink-0 이라 눌리지 않는다', () => {
    const backBtn = findNode(
      layout,
      (n) => n.name === 'Button' && findNode(n, (c) => c.text === '$t:board.back_to_list') !== null
    );
    expect(backBtn).toBeTruthy();
    expect(backBtn.props.className.split(/\s+/)).toContain('shrink-0');

    // 루트 컨테이너도 이전글/다음글 을 자손으로 갖는다. 직계 자식이 두 Button 인 노드만 고른다.
    const prevNextGroup = findNode(
      layout,
      (n) =>
        n.name === 'Div' &&
        Array.isArray(n.children) &&
        n.children.length === 2 &&
        n.children.every((c: any) => c.name === 'Button') &&
        findNode(n, (c: any) => c.text === '$t:board.prev') !== null &&
        findNode(n, (c: any) => c.text === '$t:board.next') !== null
    );
    expect(prevNextGroup).toBeTruthy();
    expect(prevNextGroup.props.className.split(/\s+/)).toContain('shrink-0');
  });
});

describe('해외주소 도시/주 입력 — 무접두 grid-cols-2 금지', () => {
  const files = [
    'partials/shop/_modal_address_manage.json',
    'partials/mypage/addresses/_modal_address.json',
  ];

  for (const file of files) {
    it(`${file} 의 2열 행은 모두 좁은 화면 대비가 있다 (sm: 접두 또는 responsive.portable)`, () => {
      const layout = loadLayout(file);
      // 390px 에서 2열을 그대로 유지하는 노드를 찾는다.
      // 허용되는 두 가지 대비책:
      //  (a) Tailwind breakpoint 접두 — "grid-cols-1 sm:grid-cols-2"
      //  (b) 엔진 노드 레벨 responsive.portable(0~1023px) 오버라이드 — 받는분/연락처 행 패턴
      const offenders = findAllNodes(layout, (n) => {
        const cls = n.props?.className;
        if (typeof cls !== 'string' || !cls.split(/\s+/).includes('grid-cols-2')) return false;
        const portableCls = n.responsive?.portable?.props?.className;
        const portableIsSingleColumn =
          typeof portableCls === 'string' && portableCls.split(/\s+/).includes('grid-cols-1');
        return !portableIsSingleColumn;
      });
      expect(offenders.map((n) => n.props.className)).toEqual([]);
    });

    it(`${file} 의 도시/주 행은 grid-cols-1 sm:grid-cols-2 다`, () => {
      const layout = loadLayout(file);
      const row = findNode(layout, (n) => {
        const cls = n.props?.className;
        return typeof cls === 'string' && cls.includes('sm:grid-cols-2') && cls.includes('gap-2');
      });
      expect(row).toBeTruthy();
      expect(row.props.className.split(/\s+/)).toContain('grid-cols-1');
    });
  }
});
