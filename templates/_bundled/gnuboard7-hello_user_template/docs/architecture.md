# Hello 사용자 템플릿 — 아키텍처

> 설계 의도와 계층 구조 · 진입점: [AGENTS.md](../AGENTS.md)

## 설계 의도

<!-- @intent START -->
목표가 둘입니다 — "User 템플릿의 **최소 구성**은 무엇인가" 와 "모듈의 데이터를 화면에 **어떻게
연결하는가**".

앞의 답은 Admin 샘플과 같습니다: Basic 컴포넌트 8개 · 베이스 레이아웃 · 화면 하나 ·
**오류 레이아웃 6종**. 오류 6종이 선택이 아닌 이유도 같습니다 — 코어가 그 상황에서 활성
템플릿의 레이아웃을 부르는데 없으면 방문자에게 백지가 됩니다.

뒤의 답이 이 샘플만의 것입니다. 홈 화면이 `data_sources` 로 학습용 모듈의 메모 API 를 호출해
목록을 그립니다 — **모듈이 데이터를, 템플릿이 화면을** 담당하는 경계를 가장 짧게 보여주는
예시이며, 실제 게시판·이커머스 모듈도 방문자 화면을 갖지 않고 같은 방식으로 템플릿에
맡깁니다.

**의도적으로 하지 않는 것**: 실제 사이트 화면 · composite/layout 컴포넌트 · 액션 핸들러 ·
SEO 설정 · 확장 오버라이드. `sirsoft-basic` 과 나란히 보면 무엇이 필수이고 무엇이 그 템플릿의
선택인지가 드러납니다.

`manifest.hidden = true` 는 학습용이 운영 사이트의 템플릿 목록에 섞이지 않게 하면서도 CLI 로는
실제로 설치·동작하게 하는 장치입니다.
<!-- @intent END -->

## 계층 지도

<!-- @intent START -->
```
template.json          manifest — type: user · features 플래그 · 컴포넌트 레지스트리
routes.json            `/` → home (auth_required: false)
     │
layouts/_user_base.json      방문자 공통 뼈대 (헤더 + 콘텐츠 + 푸터)
     │  extends
     ├─ layouts/home.json          data_sources: 학습용 모듈 메모 API
     └─ layouts/errors/{401,403,404,500,503,maintenance}.json   ← 6종 필수
     │
src/components/basic/  Div · Button · H1 · H2 · H3 · A · Span · Img
     │
src/index.ts           ComponentRegistry 에 8개 등록 (모듈 로드 시점, 1회)
     │
dist/                  커밋되는 빌드 산출물
__tests__/layouts/     createLayoutTest() + API 모킹 기반 렌더링 테스트
```

**서버 코드가 없습니다.** 데이터는 전부 모듈·코어의 공개 API 에서 오며, 그래서 템플릿을 갈아
끼워도 데이터는 그대로입니다. 반대로 이 템플릿만으로는 홈 화면이 비어 있습니다 — manifest 가
학습용 모듈을 의존으로 선언하는 이유입니다.

> **등록 방식이 Admin 샘플과 다릅니다.** `gnuboard7-hello_admin_template` 은
> `initTemplate()` 안에서 100ms 간격 최대 50회 **재시도**로 컴포넌트를 등록하지만, 이 템플릿은
> 모듈 로드 시점에 한 번만 시도하고 레지스트리가 없으면 경고를 남기고 건너뜁니다. 로드 순서에
> 따라 등록이 누락되면 레이아웃이 컴포넌트 이름을 찾지 못해 화면이 조용히 빕니다 — 새 템플릿을
> 만들 때는 **Admin 샘플의 재시도 형태를 따르는 것**이 안전합니다.

레이아웃 렌더링 테스트는 **이 템플릿 디렉토리에 둡니다**(`__tests__/layouts/`). API 응답을
모킹해 데이터소스 연동까지 검증하는 것이 이 샘플 테스트의 학습 포인트입니다.
<!-- @intent END -->

## 디렉토리

<!-- @generated:directory-map START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 경로 | 역할 | 수정 시 필요한 절차 |
|---|---|---|
| `template.json` | manifest (버전 SSoT) | version 변경 시 package.json·package-lock.json 동기화 |
| `routes.json` | 라우트 → 레이아웃 매핑 | `php artisan template:update gnuboard7-hello_user_template --force` |
| `layouts/` | 레이아웃 JSON | `php artisan template:update gnuboard7-hello_user_template --force` (빌드 불필요) |
| `src/components/` | React 컴포넌트 | `php artisan template:build` → `php artisan template:update gnuboard7-hello_user_template --force` |
| `dist/` | 커밋되는 빌드 산출물 | `--production` 으로 재빌드 (sourceMappingURL 잔존 금지) |
| `CHANGELOG.md` | 변경 이력 | 버전 상향 시 항목 추가 (미기재 시 버전 상향 불가) |
| `components.json` | 편집기 컴포넌트 선언 (레이아웃 저작자가 읽는 props 계약) | `php artisan template:update gnuboard7-hello_user_template --force` |
| `docs/` | 개발자 문서 | 표면 변경 시 `php artisan ext:docgen` 재실행 |
| `lang/` | 다국어 | 키 추가 시 ko·en 동시 반영 + 번들 ja 팩 동기화 |
<!-- @generated:directory-map END -->
