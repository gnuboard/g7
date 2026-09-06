# 마케팅 동의 — 아키텍처

> 설계 의도와 계층 구조 · 진입점: [AGENTS.md](../AGENTS.md)

## 설계 의도

<!-- @intent START -->
"동의 항목은 운영자가 늘린다" 는 전제 하나가 이 플러그인의 구조를 결정했습니다.

- **EAV 구조.** 동의 항목마다 컬럼을 만들면 채널을 하나 늘릴 때마다 마이그레이션과 배포가
  필요합니다. `user_marketing_consents` 에 `consent_key` 별 행을 두어, 채널 추가가 **설정
  변경만으로** 끝나게 했습니다. 그 대가로 "회원의 이메일 동의 여부"를 SQL 한 줄로 얻기가
  덜 직관적이지만, 항목이 데이터인 이상 그 편이 맞습니다.
- **자기 화면을 갖지 않는다.** 관리자 설정 화면 하나를 빼면 UI 는 전부 다른 화면에 끼워 넣는
  조각 5개입니다. 코어 회원 화면을 고치지 않고 필드를 더하려면 이 방법뿐입니다.
- **코어에 훅으로만 붙는다.** 구독 11종이 전부 코어 회원·가입 흐름이며, 코어 `User` 모델이나
  회원 컨트롤러는 한 줄도 건드리지 않습니다. 이 플러그인을 비활성화하면 동의 항목이 화면과
  응답에서 함께 사라집니다.
- **상태와 이력을 분리한다.** `user_marketing_consents` 는 "지금 어떤가", `user_marketing_
  consent_histories` 는 "어떻게 여기까지 왔는가" 입니다. 동의 여부만 남기면 나중에 "동의를
  받았다" 는 사실을 증명할 수 없습니다.

**의도적으로 하지 않는 것**: 실제 발송·권한 선언·관리자 메뉴·프론트 액션 핸들러. 동의 관리와
발송이 한 확장에 묶이면 발송 수단을 바꿀 때 동의 이력까지 흔들립니다. 발송 확장은
`user.subscribed`/`user.unsubscribed` 를 구독해 자기 수신 목록을 관리합니다.
<!-- @intent END -->

## 계층 지도

<!-- @intent START -->
```
[주입]  레이아웃 조각 5개 (가입 폼 / 회원 상세 / 회원 수정 / 마이페이지 보기·수정)
            │
[진입]  코어 회원·가입 흐름
            │  core.auth.register(+validation_rules)
            │  core.user.{after_create, after_update, before_delete}
            │  core.user.{create,update,update_profile}_validation_rules
            │  core.user.{filter_update_data, filter_resource_data}
            ▼
        MarketingConsentListener  (구독 11종을 한 클래스가 모두 받는다)
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
```

컨트롤러가 둘뿐입니다(`MarketingSettingsController` · `MarketingAdminController`) — 동의
읽기·쓰기가 자기 엔드포인트가 아니라 **코어 회원 API 를 타고** 이루어지기 때문입니다. 이
플러그인의 라우트는 프론트가 설정을 조회하는 경로와 운영자가 채널을 저장하는 경로뿐입니다.

`MarketingConsentListener` 하나가 11개 훅을 전부 받는 구조는 의도적입니다. 훅마다 리스너를
나누면 "회원 도메인에 필드를 더하려면 어디를 봐야 하는가" 의 답이 흩어집니다.
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
| `resources/layouts/` | 레이아웃 JSON | `php artisan plugin:update sirsoft-marketing --force` (빌드 불필요) |
| `resources/js/` | 프론트 엔트리·핸들러 | `php artisan plugin:build` → `php artisan plugin:update sirsoft-marketing --force` |
| `resources/extensions/` | 다른 확장 레이아웃에 주입하는 조각 | `php artisan plugin:update sirsoft-marketing --force` |
| `editor-spec.json` | 레이아웃 편집기 스펙 | `php artisan plugin:update sirsoft-marketing --force` |
| `config/` | 확장 config | 설정 기본값은 settings 스키마와 어긋나지 않게 |
| `tests/` | 테스트 | 변경 범위만 필터 실행 |
| `CHANGELOG.md` | 변경 이력 | 버전 상향 시 항목 추가 (미기재 시 버전 상향 불가) |
| `docs/` | 개발자 문서 | 표면 변경 시 `php artisan ext:docgen` 재실행 |
| `lang/` | 다국어 | 키 추가 시 ko·en 동시 반영 + 번들 ja 팩 동기화 |
<!-- @generated:directory-map END -->
