# Daum 우편번호 — 아키텍처

> 설계 의도와 계층 구조 · 진입점: [AGENTS.md](../AGENTS.md)

## 설계 의도

<!-- @intent START -->
백엔드가 없는 것이 이 플러그인의 설계 그 자체입니다 — 라우트 0 · 모델 0 · 테이블 0 ·
마이그레이션 0 · 리스너 0. 주소 검색은 브라우저에서 시작해 브라우저에서 끝나는 일이고,
선택된 주소를 저장하는 것은 그 화면을 소유한 확장의 책임이라 서버에 남길 것이 없습니다.

**외부 호스트 예외.** 코어 규정은 "구동 자산을 자체 제공한다" 입니다. 이 플러그인은 그
예외이며, 근거는 대상이 라이브러리가 아니라 **서비스**라는 점입니다 — Daum 우편번호 SDK 는
자체 호스팅해도 Daum 서버와 통신하지 않으면 주소 데이터를 얻을 수 없습니다. 그래서
`trusted_script_hosts` 와 **호스트별 사유**를 manifest 에 함께 선언합니다. 사유 없는 외부
호스트 선언은 금지이며, 이 플러그인이 그 예외 기재의 선례입니다.

**예외의 대가는 실패 경로다.** 외부 호스트에 의존하는 순간 그 도달 실패가 가능해지고, 그
실패는 예외도 서버 로그도 남기지 않습니다. 그래서 세 가지를 갖춥니다 — 스크립트 로드에
`onerror` 를 걸어 실패를 **감지**하고, `G7Core.assets.notifyFailure` 로 사용자에게 **통지**
하며, 필드를 편집 가능한 채로 남겨 **직접 입력**을 허용합니다. 특히 마지막이 중요합니다:
읽기 전용은 SDK 확보를 확인한 **뒤에만** 적용하고, 해제는 확인 없이 즉시 수행합니다.

**의도적으로 하지 않는 것**: 주소 저장·주소 검증·좌표 변환·해외 주소·자체 화면. 이 플러그인의
UI 는 다른 화면에 끼워 넣는 조각 하나와 관리자 설정 화면 하나뿐입니다.
<!-- @intent END -->

## 계층 지도

<!-- @intent START -->
```
[주입]  resources/extensions/ecommerce-address-search.json
            │  extension_point: address_search_slot
            │  scripts: Daum 우편번호 SDK (외부 호스트, 사유 선언됨)
            ▼
        onMount → setFieldReadOnly 핸들러
            │   SDK 확보 확인 → 확보 시에만 필드 잠금
            ▼
        버튼 클릭 → openPostcode 핸들러
            │   설정(display_mode / popup_* / theme_color) 적용
            │   실패 → notifyPostcodeSdkFailure (통지 + 재시도)
            ▼
        주소 선택 → filter_address_data (가공) → 필드 기록 → address.selected (통지)

[백엔드]  plugin.php 만 존재 — 설정 스키마 · 훅 선언 · 기본값
```

`postcodeSdk.ts` 가 이 플러그인의 **위험 관리 전부**를 담습니다 — 로드·재로드·준비 판정·실패
통지·통지 해제. 두 핸들러가 이 모듈 하나를 공유하므로 실패 처리 방식이 갈라지지 않습니다.
새 핸들러를 추가할 때도 SDK 접근은 반드시 이 모듈을 거칩니다.

`plugin.php` 는 클래스 하나에 메서드 넷(`getMetadata` · `getSettingsSchema` ·
`getConfigValues` · `getHooks`)뿐입니다. 서버가 하는 일이 설정 제공과 훅 선언밖에 없다는
사실이 그대로 드러납니다.
<!-- @intent END -->

## 디렉토리

<!-- @generated:directory-map START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 경로 | 역할 | 수정 시 필요한 절차 |
|---|---|---|
| `plugin.json` | manifest (버전 SSoT) | version 변경 시 package.json·package-lock.json·composer.json 동기화 |
| `plugin.php` | 진입 클래스 (선언형 표면 SSoT) | 표면 변경 시 `ext:docgen` 재실행 + 코어 최소 버전 검토 |
| `resources/layouts/` | 레이아웃 JSON | `php artisan plugin:update sirsoft-daum_postcode --force` (빌드 불필요) |
| `resources/routes.json` | 라우트 → 레이아웃 매핑 | `php artisan plugin:update sirsoft-daum_postcode --force` |
| `resources/js/` | 프론트 엔트리·핸들러 | `php artisan plugin:build` → `php artisan plugin:update sirsoft-daum_postcode --force` |
| `resources/extensions/` | 다른 확장 레이아웃에 주입하는 조각 | `php artisan plugin:update sirsoft-daum_postcode --force` |
| `dist/` | 커밋되는 빌드 산출물 | `--production` 으로 재빌드 (sourceMappingURL 잔존 금지) |
| `config/` | 확장 config | 설정 기본값은 settings 스키마와 어긋나지 않게 |
| `CHANGELOG.md` | 변경 이력 | 버전 상향 시 항목 추가 (미기재 시 버전 상향 불가) |
| `components.json` | 편집기 컴포넌트 선언 (레이아웃 저작자가 읽는 props 계약) | `php artisan plugin:update sirsoft-daum_postcode --force` |
| `docs/` | 개발자 문서 | 표면 변경 시 `php artisan ext:docgen` 재실행 |
<!-- @generated:directory-map END -->
