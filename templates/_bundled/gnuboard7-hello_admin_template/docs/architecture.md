# Hello Admin Template — 아키텍처

> 설계 의도와 계층 구조 · 진입점: [AGENTS.md](../AGENTS.md)

## 설계 의도

<!-- @intent START -->
"관리자 템플릿이 성립하기 위한 **최소 구성**은 무엇인가" 에 답하는 것이 목적입니다. 그래서
담긴 것이 넷뿐입니다 — Basic 컴포넌트 8개, 베이스 레이아웃, 화면 하나, **오류 레이아웃 6종**.

**오류 6종이 왜 최소 구성에 들어가는가**가 이 샘플의 핵심입니다. 코어는 오류 상황에서 활성
템플릿의 해당 레이아웃을 부르는데, 없으면 그 오류를 화면에 표시할 수단이 없습니다 — 사용자에게는
백지가 됩니다. 화면이 하나뿐인 템플릿에도 6종은 있어야 하며, 그래서 "선택" 이 아니라 "구성" 입니다.

**컴포넌트를 8개로 제한한 것도 의도**입니다. 이 템플릿으로 바꿨을 때 깨지는 모듈 화면이 있다면,
그 화면이 템플릿 고유 컴포넌트에 의존하고 있다는 뜻입니다 — 모듈 호환성을 확인하는 실험 도구로
쓸 수 있습니다.

`manifest.hidden = true` 는 학습용이 운영 사이트의 템플릿 목록에 섞이지 않게 하면서도 CLI 로는
실제로 설치·동작하게 하는 장치입니다.

**의도적으로 하지 않는 것**: 실제 관리 화면 · composite/layout 컴포넌트 · 액션 핸들러 ·
SEO 설정 · 확장 오버라이드. `sirsoft-admin_basic` 과 나란히 보면 무엇이 필수이고 무엇이 그
템플릿의 선택인지가 드러납니다.
<!-- @intent END -->

## 계층 지도

<!-- @intent START -->
```
template.json          manifest — type: admin · 컴포넌트 레지스트리 · error_config
routes.json            `*/admin` → admin_dashboard
     │
layouts/_admin_base.g7.html     관리자 공통 뼈대 (헤더 · 사이드바 · 콘텐츠 슬롯)
     │  extends
     ├─ layouts/admin_dashboard.g7.html
     └─ layouts/errors/{401,403,404,500,503,maintenance}.json   ← 6종 필수
     │
src/components/basic/  Div · Button · H1 · H2 · H3 · A · Span · Img
     │
src/index.ts           Basic 8개 + templateMetadata export 만 (등록은 코어가 components.json 기준으로)
     │
dist/                  커밋되는 빌드 산출물
__tests__/layouts/     createLayoutTest() 기반 렌더링 테스트
```

**서버 코드가 없습니다.** 템플릿은 화면만 담당하며 데이터는 코어·모듈의 공개 API 에서
옵니다 — 그래서 템플릿을 갈아 끼워도 데이터는 그대로입니다.

`src/index.ts` 가 **export 만 한다**는 점이 눈여겨볼 부분입니다. 템플릿은 컴포넌트를 스스로
등록하지 않습니다 — 코어 `ComponentRegistry` 가 `components.json` 을 읽고, 번들이 내보낸 전역
`window.Gnuboard7HelloAdminTemplate` 에서 선언된 이름의 구현체를 찾아 등록합니다. 코어는 엔진과
템플릿 번들이 모두 적재된 뒤에 초기화하므로 템플릿 쪽에서 적재 순서를 기다릴 일이 없습니다.
대신 export 이름과 `components.json` 의 `name` 이 어긋나면 그 컴포넌트는 등록되지 않고, 레이아웃이
그 이름을 참조하는 자리가 조용히 빕니다.

레이아웃 렌더링 테스트는 **이 템플릿 디렉토리에 둡니다**(`__tests__/layouts/`). 코어
디렉토리에 두면 그 템플릿을 지울 때 테스트만 남습니다.
<!-- @intent END -->

## 디렉토리

<!-- @generated:directory-map START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 경로 | 역할 | 수정 시 필요한 절차 |
|---|---|---|
| `template.json` | manifest (버전 SSoT) | version 변경 시 package.json·package-lock.json 동기화 |
| `routes.json` | 라우트 → 레이아웃 매핑 | `php artisan template:update gnuboard7-hello_admin_template --force` |
| `layouts/` | 레이아웃 소스 `.g7.html` (짝 `.json` 은 굽기 산출. 컴포넌트 종류는 `data-type`, 컴파일은 템플릿과 무관) | `php artisan template:update gnuboard7-hello_admin_template --force` (빌드 불필요) |
| `src/components/` | React 컴포넌트 | `php artisan template:build` → `php artisan template:update gnuboard7-hello_admin_template --force` |
| `dist/` | 커밋되는 빌드 산출물 | `--production` 으로 재빌드 (sourceMappingURL 잔존 금지) |
| `CHANGELOG.md` | 변경 이력 | 버전 상향 시 항목 추가 (미기재 시 버전 상향 불가) |
| `components.json` | 편집기 컴포넌트 선언 (레이아웃 작성자가 읽는 props 계약) | `php artisan template:update gnuboard7-hello_admin_template --force` |
| `assets/icon.svg` | 확장 아이콘 (관리자 목록·설치 마법사 카드) | 정사각·100KB 이하·스크립트 없는 SVG — 교체 후 `php artisan template:update gnuboard7-hello_admin_template --force` |
| `docs/` | 개발자 문서 | 표면 변경 시 `php artisan ext:docgen` 재실행 |
| `lang/` | 다국어 | 키 추가 시 ko·en 동시 반영 + 번들 ja 팩 동기화 |
<!-- @generated:directory-map END -->
