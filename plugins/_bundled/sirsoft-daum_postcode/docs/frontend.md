# Daum 우편번호 — 프론트엔드

> 레이아웃·액션 핸들러·전역 진입점·에셋 · 진입점: [AGENTS.md](../AGENTS.md)

## 레이아웃

<!-- @generated:layouts START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
레이아웃 1개 (루트: `resources/layouts`).

| 그룹 | 개수 |
|---|---|
| `admin` | 1개 |

| 레이아웃 | 그룹 | 종류 | extends |
|---|---|---|---|
| `plugin_settings` | `admin` | 화면 | `_admin_base` |
<!-- @generated:layouts END -->

<!-- @intent START -->
관리자 설정 화면(`plugin_settings`) 하나뿐입니다. **이 플러그인의 실제 UI 는 레이아웃이 아니라
확장 조각**(`resources/extensions/ecommerce-address-search.json`)이며, 다른 화면 안에
들어갑니다.

`plugin_settings.json` 은 **파일 이름이 계약**입니다. 코어가 플러그인 디렉토리의 이 고정 경로를
찾아 설정 화면을 그리므로, 이름을 바꾸면 설정 화면 자체가 사라집니다.

레이아웃·조각 JSON 만 고쳤다면 빌드는 필요 없고
`php artisan plugin:update sirsoft-daum_postcode --force` 로 반영합니다.
<!-- @intent END -->

## 액션 핸들러

<!-- @generated:handlers START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
핸들러 2개 (정의: `resources/js/handlers/index.ts`).

| 핸들러 | 레이아웃에서 부르는 이름 |
|---|---|
| `setFieldReadOnly` | `sirsoft-daum_postcode.setFieldReadOnly` |
| `openPostcode` | `sirsoft-daum_postcode.openPostcode` |
<!-- @generated:handlers END -->

<!-- @intent START -->
둘뿐이고 역할이 명확히 갈립니다.

| 핸들러 | 하는 일 |
|---|---|
| `setFieldReadOnly` | 지정된 주소 필드를 읽기 전용으로 전환. **SDK 확보를 먼저 확인**하고 확보하지 못했으면 편집 가능한 채로 둡니다. 해제(`readOnly: false`)는 확인 없이 즉시 수행 |
| `openPostcode` | 설정대로 검색 창을 열고, 선택 결과를 `filter_address_data` → 필드 기록 → `address.selected` 순으로 처리. 실패 시 통지 + 재시도 |

두 핸들러 모두 SDK 접근을 `postcodeSdk.ts` 한 모듈로 모읍니다 — 로드·재로드·준비 판정·실패
통지·통지 해제가 거기 있습니다. 새 핸들러를 추가할 때도 SDK 접근은 반드시 이 모듈을 거쳐야
실패 처리 방식이 갈라지지 않습니다.

**읽기 전용 적용 순서가 이 플러그인에서 가장 조심스러운 부분입니다.** 확보 확인 전에 잠그면
SDK 가 막힌 환경에서 필드가 잠긴 채 남아 주소를 아예 입력할 수 없게 됩니다 — 그 조합은 화면
전체(주문·배송지 등록)를 불능으로 만듭니다.

핸들러 TS 를 고치면 빌드가 필요합니다 — `php artisan plugin:build` 후
`plugin:update --force`. 폴백 경로를 건드렸다면
`resources/js/__tests__/postcode-fallback.test.ts` 를 함께 갱신·실행합니다.
<!-- @intent END -->

## 전역 진입점

<!-- @generated:frontend-entry START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 항목 | 값 |
|---|---|
| 엔트리 파일 | `resources/js/index.ts` |
| 전역 객체 | `window.__SirsoftDaumPostcode` |
| 재등록 진입점 | `initPlugin()` |

로케일 전환 시 코어가 이 진입점을 호출해 핸들러를 다시 등록합니다. 진입점은 핸들러 재등록만 수행하고 1회성 부팅 작업을 포함하지 않습니다.
<!-- @generated:frontend-entry END -->

<!-- @intent START -->
`window.__SirsoftDaumPostcode.initPlugin()` 이 재등록 진입점입니다. 로케일을 전환하면 코어가
이 함수를 다시 불러 핸들러를 재등록하는데, 없거나 이름이 다르면 **로케일 전환 직후 검색
버튼이 무반응**이 됩니다 — 오류도 토스트도 남지 않습니다.

진입점은 핸들러 재등록만 수행합니다. SDK 로드 같은 1회성 작업을 여기 넣으면 로케일을 바꿀
때마다 스크립트를 다시 붙이게 됩니다.
<!-- @intent END -->

## 에셋

<!-- @generated:assets START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 경로 | 구분 |
|---|---|
| `dist/js/plugin.iife.js` | 빌드 산출물 (커밋 대상) |

로딩 설정: `{"strategy":"global","priority":100,"dependencies":[]}`
<!-- @generated:assets END -->

<!-- @intent START -->
커밋되는 산출물은 `dist/js/plugin.iife.js` 하나이며, 동봉 제3자 자산은 없습니다 — **Daum SDK
는 동봉하지 않고 외부 호스트에서 로드**하기 때문입니다.

그 예외의 근거는 대상이 라이브러리가 아니라 **서비스**라는 점입니다. 자체 호스팅해도 Daum
서버와 통신하지 않으면 주소 데이터를 얻을 수 없습니다. manifest 에 `trusted_script_hosts` 와
호스트별 사유를 함께 선언하며, **사유 없는 외부 호스트 선언은 금지**입니다.

SDK URL 은 두 곳에 있습니다 — 확장 조각의 `scripts.src` 와 `postcodeSdk.ts` 의
`DAUM_POSTCODE_SDK_URL` 상수. 주소가 바뀌면 **함께** 고쳐야 하며, 한쪽만 고치면 조각이 로드한
스크립트와 핸들러가 찾는 스크립트가 달라져 확보 판정이 어긋납니다.

`dist/` 는 배포 산출물이므로 소스를 고치면 `--production` 으로 다시 굽고 커밋합니다
(`sourceMappingURL` 잔존 금지 — `.map` 은 커밋 대상이 아니라 404 가 됩니다).
<!-- @intent END -->
