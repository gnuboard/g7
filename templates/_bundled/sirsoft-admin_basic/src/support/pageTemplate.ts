/**
 * 이 번들이 지금 페이지의 활성 템플릿으로 실렸는지 판정합니다.
 *
 * 레이아웃 편집기는 관리자 템플릿이 떠 있는 페이지에 편집 대상 템플릿 번들을 함께 싣는다(캔버스 컴포넌트와
 * 편집기 위젯용). 그 번들이 핸들러·본인인증 launcher·로케일 전환 재등록 맵 같은 페이지 전역을 등록하면
 * 호스트 템플릿이 등록한 같은 이름을 덮어, 호스트 화면 동작이 바뀌고 엔진이 소유권 경고를 낸다.
 *
 * 페이지 루트 `#app[data-template-id]` 가 다른 템플릿을 가리키면 손님 번들로 본다. 루트나 표식이 없으면
 * (단독 실행·옛 코어) 종전대로 활성 템플릿으로 본다.
 *
 * @param identifier 이 템플릿 식별자
 * @returns 페이지의 활성 템플릿이면 true
 */
export function isPageTemplate(identifier: string): boolean {
  if (typeof document === 'undefined') {
    return true;
  }

  const pageTemplateId = document.getElementById('app')?.getAttribute('data-template-id');

  return !pageTemplateId || pageTemplateId === identifier;
}
