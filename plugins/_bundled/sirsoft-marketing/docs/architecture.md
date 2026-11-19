# 마케팅 동의 — 아키텍처

> 설계 의도와 계층 구조 · 진입점: [AGENTS.md](../AGENTS.md)

## 설계 의도

<!-- @intent START -->
"동의 항목은 운영자가 늘린다" 는 전제 하나가 이 플러그인의 구조를 결정했습니다.

- **EAV 구조.** 동의 항목마다 컬럼을 만들면 채널을 하나 늘릴 때마다 마이그레이션과 배포가
  필요합니다. `user_marketing_consents` 에 `consent_key` 별 행을 두어, 채널 추가가 **설정
  변경만으로** 끝나게 했습니다. 그 대가로 "회원의 이메일 동의 여부"를 SQL 한 줄로 얻기가
  덜 직관적이지만, 항목이 데이터인 이상 그 편이 맞습니다.
- **동의 칸을 직접 그리지 않는다.** 동의 값은 코어 회원 항목 집합 `core.user` 의 예/아니오 항목으로
  선언하고, 가입 화면, 내 정보 수정과 보기, 관리자 회원 폼의 동의 칸은 코어 템플릿이 항목 계약대로
  그립니다. 이 플러그인의 화면은 관리자 설정 화면 하나와 관리자 회원 상세의 동의 이력 조각 하나입니다.
- **값은 자기 테이블에, 검증과 저장은 회원 항목 엔진에.** 네 경로(가입, 관리자 회원 추가와 수정,
  내 정보 수정)의 검증과 저장은 코어 회원 항목 엔진이 하고, 저장소 어댑터가 값을 동의 테이블로
  넘깁니다. 코어 `User` 모델이나 회원 컨트롤러는 한 줄도 건드리지 않습니다. 이 플러그인을 비활성화하면
  동의 항목이 화면과 응답에서 함께 사라집니다.
- **상태와 이력을 분리한다.** `user_marketing_consents` 는 "지금 어떤가", `user_marketing_
  consent_histories` 는 "어떻게 여기까지 왔는가" 입니다. 동의 여부만 남기면 나중에 "동의를
  받았다" 는 사실을 증명할 수 없습니다. 회원 응답에 싣는 이력 시각은 보는 사람(관리자) 시간대
  문자열이라 같은 화면의 가입일과 같은 기준으로 읽힙니다.

**의도적으로 하지 않는 것**: 실제 발송·권한 선언·관리자 메뉴·프론트 액션 핸들러. 동의 관리와
발송이 한 확장에 묶이면 발송 수단을 바꿀 때 동의 이력까지 흔들립니다. 발송 확장은
`user.subscribed`/`user.unsubscribed` 를 구독해 자기 수신 목록을 관리합니다.
<!-- @intent END -->

## 계층 지도

<!-- @intent START -->
```
[화면]  코어 템플릿이 항목 계약대로 그리는 동의 칸 (가입 / 내 정보 수정, 보기 / 관리자 회원 폼)
        + 이 플러그인의 조각 하나 (관리자 회원 상세의 동의 이력)
            │
[진입]  코어 회원 항목 엔진 (집합 core.user)
            │  선언:  Plugin::getFields() → Fields\MarketingConsentFields
            │  검증:  코어가 선언으로 규칙을 만든다 (예/아니오)
            │  저장:  회원 저장과 같은 트랜잭션에서 저장소 어댑터를 부른다
            ▼
        Fields\MarketingConsentFieldHandler  (read / write / purge)
            │
            ▼
        MarketingConsentService
            │  채널 해석: PluginSettingsService 의 `channels` JSON
            │  상태 갱신 + 이력 적재 + user.consent_changed 발행
            ▼
        MarketingConsentRepository (Interface 경유)
            │
            ▼
        MarketingConsent / MarketingConsentHistory

[주변]  MarketingConsentListener
            core.field.filter_definitions        항목 목록을 지금 설정에 맞춘다 (끈 항목 제외, 행 없는 채널 추가, 약관 링크)
            core.auth.register_validation_rules  옛 가입 키(agree_ 접두)의 규칙
            core.user.filter_resource_data       회원 응답에 동의 값, 동의 시각, 채널 목록, 이력
            core.user.before_delete              회원 삭제 전 정리
            core.plugin_settings.filter_save_data / after_save   채널 저장 형태 정규화, 항목 선언 재동기화
```

컨트롤러가 둘뿐입니다(`MarketingSettingsController`, `MarketingAdminController`) — 동의
읽기·쓰기가 자기 엔드포인트가 아니라 **코어 회원 API 를 타고** 이루어지기 때문입니다. 이
플러그인의 라우트는 공개 설정을 조회하는 경로와 운영자가 채널을 저장하는 경로뿐입니다.

리스너는 값의 검증과 저장을 하지 않습니다. 검증 규칙 필터나 저장 액션 훅을 다시 구독하면 같은 값이
두 번 검증되고 두 번 저장되어 이력이 두 번 남습니다. 리스너가 하는 일은 항목 목록을 요청 시점의 설정에
맞추는 것, 응답에 값을 싣는 것, 삭제 때 정리하는 것, 설정 저장 뒤 선언을 다시 동기화하는 것입니다.
<!-- @intent END -->

## 디렉토리

<!-- @generated:directory-map START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 경로 | 역할 | 수정 시 필요한 절차 |
|---|---|---|
| `plugin.json` | manifest (버전 SSoT) | version 변경 시 package.json·package-lock.json·composer.json 동기화 |
| `plugin.php` | 진입 클래스 (선언형 표면 SSoT) | 표면 변경 시 `ext:docgen` 재실행 + 코어 최소 버전 검토 |
| `src/Http/Controllers/` | 컨트롤러 | API 표면 변경 시 `api:docgen` 재실행 |
| `src/Http/Requests/` | FormRequest (검증 SSoT) | 검증 규칙은 Service 가 아니라 여기에 둔다 |
| `src/Services/` | 비즈니스 로직 | Repository 인터페이스 주입 (구체 클래스 금지) |
| `src/Repositories/` | 데이터 접근 | 목록 쿼리는 컬럼 프루닝·정렬 화이트리스트 확인 |
| `src/Models/` | Eloquent 모델 | 스키마 변경 시 마이그레이션 + 업그레이드 스텝 동반 |
| `src/Listeners/` | 훅 리스너 | Repository 경유 (Model·DB 파사드 직접 접근 금지) |
| `src/routes/` | 라우트 | 모든 라우트에 `name()` 필수 |
| `database/migrations/` | 마이그레이션 | 한국어 comment + `down()` 필수, 기설치본은 업그레이드 스텝으로 백필 |
| `upgrades/` | 업그레이드 스텝 | DB·설정 구조 변경 시 작성 (모듈/플러그인 전용) |
| `resources/layouts/` | 레이아웃 소스 `.g7.html` (짝 `.json` 은 굽기 산출. 컴포넌트 종류는 `data-type`, 컴파일은 템플릿과 무관) | `php artisan plugin:update sirsoft-marketing --force` (빌드 불필요) |
| `resources/js/` | 프론트 엔트리·핸들러 | `php artisan plugin:build` → `php artisan plugin:update sirsoft-marketing --force` |
| `resources/extensions/` | 다른 확장 레이아웃에 주입하는 조각 | `php artisan plugin:update sirsoft-marketing --force` |
| `editor-spec.json` | 레이아웃 편집기 스펙 | `php artisan plugin:update sirsoft-marketing --force` |
| `dist/` | 커밋되는 빌드 산출물 | `--production` 으로 재빌드 (sourceMappingURL 잔존 금지) |
| `config/` | 확장 config | 설정 기본값은 settings 스키마와 어긋나지 않게 |
| `tests/` | 테스트 | 변경 범위만 필터 실행 |
| `CHANGELOG.md` | 변경 이력 | 버전 상향 시 항목 추가 (미기재 시 버전 상향 불가) |
| `components.json` | 편집기 컴포넌트 선언 (레이아웃 작성자가 읽는 props 계약) | `php artisan plugin:update sirsoft-marketing --force` |
| `assets/icon.svg` | 확장 아이콘 (관리자 목록·설치 마법사 카드) | 정사각·100KB 이하·스크립트 없는 SVG — 교체 후 `php artisan plugin:update sirsoft-marketing --force` |
| `docs/` | 개발자 문서 | 표면 변경 시 `php artisan ext:docgen` 재실행 |
| `lang/` | 다국어 | 키 추가 시 ko·en 동시 반영 + 번들 ja 팩 동기화 |
<!-- @generated:directory-map END -->
