# 그누보드7 마케팅 동의 플러그인 — 에이전트 가이드

> 이 문서는 이 플러그인을 수정하는 에이전트·확장개발자를 위한 것입니다. 도입 검토·운영 관점은 [README.md](README.md) 를 보세요.

## TL;DR (5초 요약)

```text
1. 유형: 플러그인 (sirsoft-marketing) — 회원의 마케팅 수신 동의를 항목별로 받고 이력을 남긴다. 동의 칸은 코어 회원 항목(`core.user`)으로 선언하고 코어 템플릿이 그린다. 자기 조각은 관리자 회원 상세의 동의 이력 하나
2. 확장 방식: 동의 상태 변화를 알리는 발행 훅 4개(`user.consent_changed` / `subscribed` / `unsubscribed` / `filter_consent_data`). 동의 항목 추가는 코드가 아니라 `channels` 설정
3. 건드리면 안 되는 것: 항목마다 컬럼 추가(EAV 구조가 전제), 이력 없는 상태 갱신, 검증 규칙 필터나 저장 액션 훅으로 동의 값을 다시 처리하기(이중 검증, 이중 저장), 회원 삭제 정리를 CASCADE 에 위임
4. 작업 위치: `plugins/_bundled/sirsoft-marketing` — 활성 디렉토리 직접 수정 금지
5. 반영: `php artisan plugin:update sirsoft-marketing --force`
```

## 1. 이 확장은 무엇인가

<!-- @intent START -->
회원의 **마케팅 정보 수신 동의**를 항목별로 받고, 그 동의·철회 이력을 남기는 플러그인입니다.
이메일·SMS 같은 수신 채널을 운영자가 자유롭게 추가할 수 있고, 제3자 제공 동의·정보 공개
동의처럼 채널이 아닌 항목도 같은 구조로 다룹니다.

**동의 칸을 직접 그리지 않습니다.** 동의 값(전체 동의, 수신 채널, 법적 동의 둘)은 코어 회원 항목 집합
`core.user` 의 예/아니오 항목으로 선언하고(`Plugin::getFields()` → `src/Fields/MarketingConsentFields.php`),
가입 화면의 약관 동의 자리, 내 정보 수정과 보기, 관리자 회원 폼의 동의 칸은 코어 템플릿이 항목 계약
(`ui.group = agreements`, `ui.link`, `ui.role`, `ui.parent`)대로 그립니다. 이 플러그인이 가진 화면은 관리자
설정 화면 하나와 관리자 회원 상세에 끼우는 동의 이력 조각(`user-marketing-detail`) 하나입니다.

**설계 원칙 셋**:

1. **EAV 구조로 항목을 데이터화한다.** 동의 항목마다 컬럼을 만들면 항목을 늘릴 때 스키마
   변경이 필요합니다. 대신 `user_marketing_consents` 한 테이블에 `consent_key` 별 행을 두어,
   채널 추가가 **설정 변경만으로** 끝나게 했습니다.
2. **값은 자기 테이블에 두고 회원 항목 엔진에 잇는다.** 가입, 관리자 회원 추가와 수정, 내 정보 수정
   네 경로의 검증과 저장은 코어 회원 항목 엔진이 하고, 저장소 어댑터
   (`src/Fields/MarketingConsentFieldHandler.php`, `storage = handler`)가 그 값을 동의 테이블로 넘깁니다.
   코어 `User` 모델이나 회원 컨트롤러를 고치지 않고, 검증 규칙 필터나 저장 액션 훅도 구독하지 않습니다.
3. **철회는 동의만큼 쉬워야 한다.** 동의 항목은 본인 수정 범위(`edit_scope = self`)라 내 정보 수정에
   항상 나타나고, 동의와 철회가 모두 `user_marketing_consent_histories` 에 기록됩니다(행위, 출처, IP).
   동의를 받은 경로와 철회 경로가 대칭이 아니면 그 동의는 법적 근거로 쓸 수 없습니다.

**사용 여부는 이 플러그인 설정이 정합니다.** 전체 동의(`marketing_consent_enabled`), 채널의 사용 여부,
법적 동의의 `{키}_enabled` 가 기준이고, 「환경설정 > 회원」의 회원 항목 표에서는 이 항목들의 「사용」이
잠겨 있습니다(`locks.enabled`). 그 표에서 운영자가 정하는 것은 필수 여부, 가입 화면 표시, 순서입니다.

**의도적으로 하지 않는 것**: 실제 발송(메일·SMS)·권한 선언·관리자 메뉴·프론트 액션 핸들러.
이 플러그인은 "누가 무엇에 동의했는가"만 답하고, 그 동의를 근거로 무엇을 보낼지는 발송을
담당하는 확장의 일입니다.
<!-- @intent END -->

## 2. 디렉토리 지도

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

## 3. 핵심 흐름

<!-- @intent START -->
**회원가입 시 동의 수집**: 가입 화면이 항목 계약(`GET /api/auth/register/fields`)을 받아 약관 동의
자리에 동의 체크박스를 그림 → 제출 → 코어 가입 요청이 회원 항목 선언으로 검증 규칙을 만듦 →
회원 생성과 같은 트랜잭션에서 코어가 `MarketingConsentFieldHandler::write()` 를 부름 →
`MarketingConsentService` 가 `user_marketing_consents` 에 기록하고 이력을 남깁니다.
요청 키는 접두 없는 항목 키(`marketing_consent`, `email_subscription` 등)입니다. 가입 경로가 쓰던 옛 키
`agree_{키}` 도 계속 받습니다 — 코어가 정식 키가 없을 때만 정식 키로 복사하고, 리스너가
`core.auth.register_validation_rules` 로 옛 키의 규칙을 주어 가입 데이터(`registration_data`)에는
둘 다 실립니다.

**동의 변경 → 이력 적재**: 회원이 내 정보 수정에서, 또는 운영자가 회원 추가와 수정 화면에서 동의를
바꾸면 → 코어 회원 저장이 바뀐 항목만 골라 저장소 어댑터에 넘김 → `MarketingConsentService` 가 항목별
현재 상태(`is_consented`, `consented_at`, `revoked_at`, `consent_count`, `last_source`)를
갱신하고 이력 한 줄(`action`, `source`, `ip_address`)을 적재한 뒤
`sirsoft-marketing.user.consent_changed` 를 발행합니다. 이력의 출처는 요청 경로가 아니라 회원 항목의
표면에서 정합니다(`signup` → `register`, `admin_create`와 `admin_update` → `admin`,
`self_update`와 `required_info` → `profile`).

**채널 추가**: 운영자가 설정 화면에서 채널을 더함 → `PUT admin/channels` →
`core.plugin_settings.filter_save_data` 필터가 저장 형태를 정규화 → `channels` 설정(JSON)에
반영 → `core.plugin_settings.after_save` 에서 리스너가 항목 선언을 다시 동기화 → 회원 항목 표에 그
채널의 행이 생기고 가입 화면과 내 정보 수정에 나타납니다. **스키마 변경도 배포도 필요 없습니다.**
운영자가 회원 항목 표에서 바꿔 둔 필수, 가입 화면 표시, 순서는 재동기화에서 보존됩니다.
항목 키 형식(영문 소문자로 시작, 2~50자)에 맞지 않는 채널 키나 다른 항목과 겹치는 키는 행이 되지
못합니다. 형식만 맞지 않는 경우는 리스너가 요청 시점에 항목으로 더해 값은 받습니다.

**요청마다 하는 일**: `core.field.filter_definitions` 에서 리스너가 회원 항목 목록을 지금 설정에
맞춥니다 — 설정에서 끈 항목을 빼고, 지금 받는 항목인데 행이 없는 채널을 더하고, 약관 페이지를 정한
항목에 약관 「보기」 링크(`ui.link`)를 붙입니다.

**회원 삭제**: `core.user.before_delete` 에서 그 회원의 동의 기록을 정리합니다. 코어 회원
삭제가 이 플러그인의 테이블을 알지 못하므로, 이 훅이 없으면 고아 행이 남습니다.
<!-- @intent END -->

## 4. 확장점

<!-- @generated:extension-points-summary START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 확장점 | 수 | 상세 |
|---|---|---|
| 발행 훅 | 5개 | [발행 훅](docs/extension-points.md#발행-훅) |
| 구독 훅 | 7개 | [구독 훅](docs/extension-points.md#구독-훅) |
| 훅 리스너 | 2개 | [훅 리스너](docs/extension-points.md#훅-리스너) |
| 레이아웃 확장 | 1개 | [레이아웃 확장](docs/extension-points.md#레이아웃-확장) |
| 미들웨어 | 0개 | [미들웨어](docs/extension-points.md#미들웨어) |
| 브로드캐스트 채널 | 0개 | [브로드캐스트 채널](docs/extension-points.md#브로드캐스트-채널) |
| 스케줄 | 0개 | [스케줄](docs/extension-points.md#스케줄) |
| 알림 정의 | 0개 | [알림 정의](docs/extension-points.md#알림-정의) |
<!-- @generated:extension-points-summary END -->

<!-- @intent START -->
발행 훅 4종은 전부 **동의 상태 변화를 다른 확장에 알리는** 것입니다.

| 훅 | 언제 쓰는가 |
|---|---|
| `user.consent_changed` | 동의 상태가 바뀔 때마다. 외부 마케팅 도구 동기화 지점 |
| `user.subscribed` · `user.unsubscribed` | 동의/철회로 갈라진 지점. 수신 목록 추가·제거를 각각 배선할 때 |
| `filter_consent_data` | 동의 데이터를 다른 확장이 가공해야 할 때 |

**구독은 값 처리가 아니라 그 주변을 잇습니다.** 동의 값의 검증과 저장은 회원 항목 선언과 저장소
어댑터가 하므로, 리스너(`MarketingConsentListener`)가 구독하는 것은 다음뿐입니다.

| 훅 | 하는 일 |
|---|---|
| `core.field.filter_definitions` | 설정에서 끈 항목 제외, 행이 없는 채널을 요청 시점에 추가, 약관 링크 `ui.link` 부착 |
| `core.auth.register_validation_rules` | 옛 가입 키(`agree_` 접두)의 규칙 — 정식 키의 규칙이 있을 때만 |
| `core.user.before_delete` | 회원 삭제 전 동의 기록 정리 |
| `core.user.filter_resource_data` | 회원 응답에 `{키}` 값, `{키}_at`, `channels`, `consent_histories` |
| `core.plugin_settings.filter_save_data` | 채널 설정의 저장 형태 정규화 |
| `core.plugin_settings.after_save` | 설정 저장 뒤 항목 선언 재동기화 |

회원에 자기 값을 더하려는 확장은 `Plugin::getFields()` 와 `src/Fields/` 두 클래스를 읽으면 됩니다.

레이아웃 조각은 관리자 회원 상세의 동의 이력 하나입니다. 대상 화면이 그 자리(슬롯)를 없애면 조각은
**오류 없이 사라지므로**, 관리자 템플릿을 업그레이드한 뒤에는 동의 이력 탭이 여전히 보이는지 눈으로
확인합니다. 동의 칸 자체는 템플릿이 항목 계약대로 그리므로, 약관 묶음(`ui.group = agreements`)을
그리지 않는 템플릿에서는 동의 칸이 보이지 않습니다.

미들웨어·브로드캐스트 채널·스케줄·알림은 0개입니다.
<!-- @intent END -->

## 5. 수정 시 동반 의무

- [ ] `_bundled` 에서만 수정하고 `php artisan plugin:update sirsoft-marketing --force` 로 반영
- [ ] manifest version 상향 시 `package.json` · `package-lock.json` · `composer.json` 동기화 + CHANGELOG 기재
- [ ] 스키마 변경 시 마이그레이션(한국어 comment + `down()`) + 기설치본 백필용 업그레이드 스텝
- [ ] 발행 훅 추가·이름 변경 시 `php artisan ext:docgen` 재실행 (구독하는 확장의 계약이 바뀝니다)
- [ ] API 표면 변경 시 `php artisan api:docgen --scope=plugin:sirsoft-marketing` 재실행 + `docs/api/**` 갱신
- [ ] 레이아웃은 `.g7.html` 을 고치고 `php artisan layout:decompile <경로> --sync` 로 짝 `.json` 을 다시 굽는다 (짝을 굽지 않으면 화면에 반영되지 않음) — 클래스는 자기 앞글자(`marketing:` 접두 유틸리티 · `marketing-*`)로만 쓰고, 새 클래스를 쓰면 `php artisan plugin:build sirsoft-marketing --production` 으로 `dist/css/plugin.css` 를 다시 만든다(템플릿 CSS 에 기대지 않는다)
- [ ] 다국어 키 추가 시 ko·en 동시 반영 + 번들 ja 언어팩 증분 동기화
- [ ] 다국어 값(JSON 다국어 컬럼·설정)을 표시용으로 고를 때는 `App\Support\LocalizedValue::pick($value)` — 요청 언어 → 사이트 기본 언어 → 영어 순(7.1.1, #148). `['ko']`·`config('app.fallback_locale')` 고정 대체는 강제 검사(`tests/Unit/I18n/MultilingualValueFallbackGuardTest.php`)가 막는다
- [ ] 동의 상태를 바꾸는 경로를 추가했다면 이력 적재(`user_marketing_consent_histories`)가 같은 트랜잭션에 있는지 확인
- [ ] 리스너가 구독하는 코어 훅(`core.field.filter_definitions`, `core.auth.register_validation_rules`, `core.user.before_delete`, `core.user.filter_resource_data`, `core.plugin_settings.filter_save_data`, `core.plugin_settings.after_save`)이나 회원 항목 계약(`App\Fields\Contracts\FieldValueHandlerInterface`)의 이름, 인자가 바뀌면 이 플러그인이 조용히 끊기므로, 코어 회원 흐름 변경 시 함께 확인
- [ ] 동의 값의 검증과 저장은 회원 항목 선언(`Plugin::getFields()` → `src/Fields/MarketingConsentFields.php`)과 저장소 어댑터(`src/Fields/MarketingConsentFieldHandler.php`)가 한다 — 검증 규칙 필터나 저장 액션 훅을 다시 구독하지 않는다(잠금: `tests/Unit/Listeners/MarketingConsentListenerTest.php`, `tests/Feature/MarketingConsentFieldsTest.php`)
- [ ] 채널 설정을 저장하는 경로를 새로 두면 `core.plugin_settings.after_save` 를 거치게 한다 — 거치지 않으면 회원 항목 표의 행이 따라가지 않는다
- [ ] 항목 선언의 이름과 안내 문구는 `lang/{ko,en}/fields.php` 에 둔다 (채널 항목의 이름은 채널 설정의 항목명)
- [ ] 레이아웃 조각은 관리자 회원 상세의 동의 이력 하나다. 대상 화면의 슬롯이 사라지면 오류 없이 빠진다 — 관리자 템플릿 업그레이드 후 노출 확인. 동의 칸은 조각이 아니라 템플릿이 항목 계약대로 그리므로 템플릿 업그레이드 후 가입 화면, 내 정보 수정, 관리자 회원 폼에서도 확인
- [ ] 약관 페이지 slug 설정은 `sirsoft-page` 모듈의 페이지를 가리킨다 (선택 연동 `integrations` `>=1.0.0`). 약관 「보기」 링크는 조각이 아니라 항목 계약 `ui.link` 로 싣는다(`requires_module` 로 기대는 모듈을 함께 알린다). 페이지 모듈 API(`/api/modules/sirsoft-page/…`)를 부르는 단추를 이 플러그인 레이아웃에 두지 않는다 — 잠금: `resources/js/__tests__/layouts/marketing-terms-page-guard.test.ts`
- [ ] 동의 항목을 늘릴 때는 설정만 바꾼다 — 마이그레이션이 필요해졌다면 EAV 구조를 벗어난 설계라는 신호
- [ ] 레이아웃·컴포넌트·`data_source` 를 건드렸다면 [`docs/editor-spec.md`](docs/editor-spec.md) 의 동반 의무 표를 따라 `editor-spec.json` 을 함께 갱신 — 샘플이 없는 `data_source` 는 편집기 캔버스에서만 빈 화면이 되고 실제 화면은 정상이라 오류도 경고도 남지 않는다. 반영은 `php artisan plugin:update sirsoft-marketing --force`

## 6. 금지 패턴

<!-- @intent START -->
| 금지 | 올바른 사용 | 이유 |
|---|---|---|
| 동의 항목을 추가하려고 `user_marketing_consents` 에 컬럼을 더하기 | `channels` 설정에 항목을 추가 (EAV 구조) | 항목마다 컬럼을 만들면 운영자가 채널을 늘릴 때마다 배포가 필요해진다 — 이 플러그인이 존재하는 이유가 사라진다 |
| 동의 상태만 갱신하고 이력을 남기지 않기 | 상태 갱신과 이력 적재를 같은 트랜잭션에 | 이력이 없는 동의는 법적 근거로 쓸 수 없다. "언제 어느 경로로 동의했는가"가 동의 그 자체다 |
| 동의 항목의 수정 범위를 관리자만 수정으로 바꾸거나 내 정보 수정 표면에서 빼기 | 본인 수정 범위(`edit_scope = self`)를 유지 — 내 정보 수정의 동의 칸은 코어 템플릿이 항목 계약대로 그린다 | 동의를 받은 경로와 철회 경로가 대칭이 아니면 그 동의는 무효가 된다 |
| 코어 `User` 모델이나 회원 컨트롤러를 고쳐 동의 필드를 넣기 | 회원 항목 선언(`Plugin::getFields()`)과 저장소 어댑터(`MarketingConsentFieldHandler`) | 코어 수정은 업그레이드마다 충돌하고, 이 플러그인을 비활성화해도 필드가 남는다 |
| 검증 규칙 필터(`core.user.*_validation_rules`)나 저장 액션 훅(`core.user.after_create`, `after_update`, `core.auth.after_register`)을 다시 구독해 동의 값을 처리하기 | 선언과 저장소 어댑터에만 맡긴다 | 같은 값이 두 번 검증되고 두 번 저장된다 — 이력과 동의 변경 훅이 두 번 남는다 |
| 회원 항목 표의 「사용」으로 동의 항목을 켜고 끄게 풀기 | 플러그인 설정(`marketing_consent_enabled`, 채널 사용 여부, `{키}_enabled`)이 기준, 표에서는 잠금(`locks.enabled`) | 켜고 끄는 자리가 둘이면 한쪽만 끈 상태에서 화면과 저장이 어긋난다 |
| 회원 삭제 시 동의 기록 정리를 DB CASCADE 에 맡기기 | `core.user.before_delete` 구독 | CASCADE 는 훅 발행·이력 처리를 건너뛰고, 아무 오류도 남기지 않는다 |
| 동의 여부를 근거로 이 플러그인이 직접 메일·SMS 를 보내기 | 발송은 발송 담당 확장이, 이 플러그인은 `user.subscribed`/`unsubscribed` 발행까지 | 동의 관리와 발송이 한 확장에 묶이면 발송 수단을 바꿀 때 동의 이력까지 흔들린다 |
| 약관 slug 를 코드에 리터럴로 박기 | 설정(`*_terms_slug`)을 읽어 페이지 모듈에서 조회 | 약관 문서는 운영자가 만들고 고치는 콘텐츠다 |
<!-- @intent END -->

## 7. 테스트 실행

<!-- @generated:test-commands START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 종류 | 개수 | 위치 |
|---|---|---|
| PHPUnit | 13개 | `plugins/_bundled/sirsoft-marketing/tests` |
| Vitest | 16개 | `vitest.config.ts` |
| Playwright | 1개 | `tests/Playwright` |
| 시나리오 매니페스트 | 24개 | `tests/scenarios` |

기저 TestCase: `tests/PluginTestCase.php` — 확장 테스트는 이 클래스를 상속합니다 (`Tests\TestCase` 직접 상속 금지).

```bash
# PHPUnit (변경 범위만) (Bash)
php vendor/bin/phpunit plugins/_bundled/sirsoft-marketing/tests --filter='<대상클래스>'

# Vitest (확장 디렉토리에서) (PowerShell)
cd plugins/_bundled/sirsoft-marketing && powershell -Command "npm run test:run -- <대상>"

# Playwright E2E (확장 디렉토리에서) (Bash)
cd plugins/_bundled/sirsoft-marketing && npm run test:e2e -- specs/<대상>.spec.ts

```

무필터 전체 실행은 금지되어 있습니다 — 변경 범위에 걸리는 대상만 지정해 실행합니다.
<!-- @generated:test-commands END -->

## 8. 문서 목차

<!-- @generated:docs-index START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 문서 | 내용 | 상태 |
|---|---|---|
| [docs/README.md](docs/README.md) | 문서 통합 목차와 실측 집계 | ✅ |
| [docs/architecture.md](docs/architecture.md) | 설계 의도·계층 지도·디렉토리 맵 | ✅ |
| [docs/extension-points.md](docs/extension-points.md) | 발행/구독 훅·미들웨어·채널·스케줄 | ✅ |
| [docs/data-model.md](docs/data-model.md) | 모델·소유 테이블·마이그레이션·Enum | ✅ |
| [docs/settings.md](docs/settings.md) | 설정 스키마·권한·메뉴·라우트·의존 관계 | ✅ |
| [docs/frontend.md](docs/frontend.md) | 레이아웃·액션 핸들러·전역 진입점·에셋 | ✅ |
| [docs/editor-spec.md](docs/editor-spec.md) | 레이아웃 편집기에 선언한 팔레트·컨트롤·샘플 데이터 | ✅ |
| [docs/api/](docs/api/README.md) | API 레퍼런스 (엔드포인트별 파라미터·응답 필드) | ✅ |
| [CHANGELOG.md](CHANGELOG.md) | 변경 이력 | ✅ |
<!-- @generated:docs-index END -->

## 화면 클래스 규약 — 플러그인 자기 CSS

레이아웃·확장 조각·핸들러가 쓰는 클래스는 모두 이 플러그인 CSS(`resources/css/main.css` → `dist/css/plugin.css`)에
정의된 **자기 앞글자** 이름이다(`plugin.json` 의 `assets.css.namespace` = `marketing`). 조각이 관리자·사용자 어느 템플릿
화면에 들어가든, 템플릿이 Tailwind 를 쓰든 안 쓰든 같은 모양으로 보인다.

1. **유틸리티는 접두 유틸리티로 쓴다** — `marketing:flex`, `marketing:dark:bg-gray-800` 처럼. 이 플러그인이 Tailwind 를 자기
   작성 도구로 빌드한다(접두 빌드, 페이지 초기화 규칙 없음). 템플릿 CSS 에 그 규칙이 있는지와 무관하다.
2. **템플릿 의미 클래스에 해당하는 이름은 `marketing-` 판본을 쓴다** — `marketing-section-heading-md`·`marketing-form-error` 등.
   정의는 생성 파일 `resources/css/g7-extension.generated.css` 의 가장 낮은 칸(`@layer g7-extension`)에 있어 템플릿이
   덮을 수 있다. 템플릿에 없던 의미 클래스를 새로 쓰려면 변환 도구(`node scripts/extension-css/convert-layout-classes.mjs
   plugins/_bundled/sirsoft-marketing`)를 다시 돌린다.
3. **다크 모드는 `marketing:dark:` 변형 또는 중립 표식 규격**(`data-g7-color-scheme`)으로 쓴다. `.dark` 선택자는 쓰지 않는다.
4. **아이콘은 `<icon name="…">` 에 라이브러리 중립 이름**(`circle-info`)을 준다. `fas fa-*` 같은 아이콘 글꼴 클래스를
   직접 쓰지 않는다.
5. **새 클래스를 쓰면 다시 빌드한다** — `php artisan plugin:build sirsoft-marketing --production` 후 `dist/` 를 함께 올린다.

앞글자 없는 클래스는 정적 검사(`extension-class-self-contained`)가, 이 플러그인 CSS 의 앞글자 위반·`.dark` 는
`extension-css-namespace` 가 막는다.
