# Basic — 아키텍처

> 설계 의도와 계층 구조 · 진입점: [AGENTS.md](../AGENTS.md)

## 설계 의도

<!-- @intent START -->
"모듈은 데이터, 템플릿은 화면" 이라는 경계가 이 템플릿의 존재 이유입니다.

게시판 모듈도 이커머스 모듈도 레이아웃이 전부 `admin` 그룹이고 방문자 화면을 갖지 않습니다.
두 모듈이 방문자 화면을 소유하면 템플릿마다 다른 디자인을 그 모듈이 전부 알아야 하는데, 공개
API 로만 노출하면 템플릿이 자유롭게 구성할 수 있습니다. 그 대가로 **방문자가 보는 커머스·
게시판 UI 는 사실상 이 템플릿이 전부**이며, 상점 화면을 고치는 작업은 이커머스 모듈이 아니라
여기입니다.

그 위에 화면 조직 원칙 셋이 있습니다.

- **단일 베이스.** 166개 전부 `_user_base` 를 상속합니다. 헤더·푸터·모바일 네비와 토스트·모달
  호스트가 거기 있어, 상속하지 않은 화면에서는 전역 UI 가 통째로 없습니다.
- **조각 중심.** 124개가 partial 입니다. 화면이 커서가 아니라, 조각을 여러 화면이 공유하기
  때문입니다 — 상품 카드·주소 폼·탭 구조가 그 예입니다.
- **설정이 라우트에 스며든다.** 상점 경로는 정적 문자열이 아니라 표현식입니다. 운영자가 상점을
  `/store` 로 옮기거나 루트에 두면 `routes.json` 의 그 표현식이 따라갑니다.

**서버 코드가 없습니다** — PHP 0줄 · 모델 0 · 라우트 파일 없음 · 훅 발행/구독 0. 데이터는 전부
모듈·코어의 공개 API 에서 오며, 그래서 이 템플릿만으로는 아무 기능도 동작하지 않습니다.
manifest 가 게시판·이커머스·페이지 모듈과 주소 검색 플러그인을 **의존으로 선언**하는 이유이고,
반대로 템플릿을 갈아 끼워도 데이터는 그대로인 이유이기도 합니다.

클레임 화면(취소·반품·교환·환불)도 같은 경계 위에 있습니다 — 금액 계산·상태 전이는 전부 이커머스
모듈의 API 가 하고, 이 템플릿은 회원·비회원이 공유하는 조각으로 입력과 표시만 담당합니다.
<!-- @intent END -->

## 계층 지도

<!-- @intent START -->
```
routes.json  (경로 → 레이아웃. 상점 9개는 표현식 — 운영자 설정이 평가되어 들어온다)
     │
     ▼
layouts/_user_base.g7.html  (헤더 · 푸터 · 모바일 네비 · 토스트/모달 호스트 · 콘텐츠 슬롯)
     │  extends
     ▼
layouts/{auth,board,shop,mypage,page,search,users,errors}/*.json   화면 42
     │  partial 참조
     ▼
layouts/partials/**  조각 124
     │  data_sources → 모듈·코어 공개 API
     │  actions      → 코어 빌트인 핸들러 + 이 템플릿의 전용 핸들러 32
     ▼
src/components/{basic,composite,layout}/  컴포넌트 79   →  dist/ (커밋되는 빌드 산출물)

extensions/{확장}/*.json   다른 확장이 제공한 조각을 대체하는 오버라이드
seo-config.json            봇 화면 렌더 규칙 (어떤 prop 을 HTML 로 내보낼지)
src/index.ts               initTemplate() — 핸들러 등록 · IDV launcher · iOS 판정 보정
```

**두 방향의 주입이 이 템플릿에서 만납니다.** 다른 확장이 `layout_extensions` 로 이 템플릿의
화면에 조각을 끼워 넣고(이커머스 통화 선택기·마케팅 동의 항목), 이 템플릿은
`extensions/{확장}/` 으로 그 확장이 제공한 조각을 자기 것으로 대체합니다. 앞은 확장이 화면을
넓히는 통로이고, 뒤는 템플릿이 디자인 주도권을 되찾는 통로입니다.

`seo-config.json` 은 계층 밖에 있지만 화면과 짝을 이룹니다 — 봇 요청에는 React 가 아니라 서버
렌더러가 화면을 그리므로, 컴포넌트가 새 prop 에 텍스트를 담기 시작하면 이 파일의 `text_props`
에 그 prop 을 더해야 봇 화면에도 글자가 실립니다.

**다른 확장이 읽는 전역 상태는 manifest `state_contracts` 로 공개합니다.** 아래 키만 다른 확장이 읽어도
되는 값이고, 이 템플릿의 나머지 전역 키는 화면 내부 값이라 예고 없이 바뀝니다. 쓰는 주체는 모두 이
템플릿뿐입니다(`writers` 빈 배열). 키를 바꾸거나 없앨 때는 「읽는 쪽」 확장과 함께 확인합니다.

| 키 | 값 | 읽는 쪽 |
|---|---|---|
| `shopBase` | 쇼핑몰 경로 앞머리(문자열, 예: `/shop`, 루트면 빈 문자열) — 쇼핑몰 경로 설정에서 온다 | 결제 플러그인(KG이니시스 결제창 닫힘 판정) |
| `selectedAddressId` | 배송지 변경 창에서 고른 배송지 ID(정수 또는 `null`) | 이커머스 배송지 변경 핸들러 |
| `changeAddressMode` | 배송지 변경 창의 모드(목록·새 배송지 등) | 이커머스 배송지 변경 핸들러 |
| `editingShippingAddress` | 배송지 변경 창에서 고치는 중인 주소(객체 또는 `null`) | 이커머스 배송지 변경 핸들러 |

이커머스가 공개하는 키(`preferredCurrency`·`guestOrderToken` 등)는 이 템플릿이 쓰는 쪽이며, 목록은 이커머스 모듈 문서
「다른 확장이 읽는 전역 상태」에 있습니다.
<!-- @intent END -->

## 디렉토리

<!-- @generated:directory-map START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 경로 | 역할 | 수정 시 필요한 절차 |
|---|---|---|
| `template.json` | manifest (버전 SSoT) | version 변경 시 package.json·package-lock.json 동기화 |
| `routes.json` | 라우트 → 레이아웃 매핑 | `php artisan template:update sirsoft-basic --force` |
| `layouts/` | 레이아웃 소스 `.g7.html` (짝 `.json` 은 굽기 산출. 컴포넌트 종류는 `data-type`, 컴파일은 템플릿과 무관) | `php artisan template:update sirsoft-basic --force` (빌드 불필요) |
| `extensions/` | 다른 확장 화면에 주입하는 레이아웃 조각 | `php artisan template:update sirsoft-basic --force` (빌드 불필요) |
| `seo-config.json` | SEO 렌더 설정 | `php artisan template:update sirsoft-basic --force` |
| `src/components/` | React 컴포넌트 | `php artisan template:build` → `php artisan template:update sirsoft-basic --force` |
| `src/handlers/` | 템플릿 전용 액션 핸들러 | `php artisan template:build` → `php artisan template:update sirsoft-basic --force` |
| `dist/` | 커밋되는 빌드 산출물 | `--production` 으로 재빌드 (sourceMappingURL 잔존 금지) |
| `editor-spec.json` | 레이아웃 편집기 스펙 | `php artisan template:update sirsoft-basic --force` |
| `editor-spec/` | 분할 편집기 스펙 | `php artisan template:update sirsoft-basic --force` |
| `tests/` | 테스트 | 변경 범위만 필터 실행 |
| `CHANGELOG.md` | 변경 이력 | 버전 상향 시 항목 추가 (미기재 시 버전 상향 불가) |
| `components.json` | 편집기 컴포넌트 선언 (레이아웃 작성자가 읽는 props 계약) | `php artisan template:update sirsoft-basic --force` |
| `assets/icon.svg` | 확장 아이콘 (관리자 목록·설치 마법사 카드) | 정사각·100KB 이하·스크립트 없는 SVG — 교체 후 `php artisan template:update sirsoft-basic --force` |
| `docs/` | 개발자 문서 | 표면 변경 시 `php artisan ext:docgen` 재실행 |
| `lang/` | 다국어 | 키 추가 시 ko·en 동시 반영 + 번들 ja 팩 동기화 |
<!-- @generated:directory-map END -->
