# 그누보드7 마케팅 동의 플러그인 — 에이전트 가이드

> 이 문서는 이 플러그인을 수정하는 에이전트·확장개발자를 위한 것입니다. 도입 검토·운영 관점은 [README.md](README.md) 를 보세요.

## TL;DR (5초 요약)

```text
1. 유형: 플러그인 (sirsoft-marketing) — 회원의 마케팅 수신 동의를 항목별로 받고 이력을 남긴다. 자기 화면 없이 코어 회원 화면에 조각 5개를 주입
2. 확장 방식: 동의 상태 변화를 알리는 발행 훅 4개(`user.consent_changed` / `subscribed` / `unsubscribed` / `filter_consent_data`). 동의 항목 추가는 코드가 아니라 `channels` 설정
3. 건드리면 안 되는 것: 항목마다 컬럼 추가(EAV 구조가 전제), 이력 없는 상태 갱신, 코어 User 모델·컨트롤러 직접 수정, 회원 삭제 정리를 CASCADE 에 위임
4. 작업 위치: `plugins/_bundled/sirsoft-marketing` — 활성 디렉토리 직접 수정 금지
5. 반영: `php artisan plugin:update sirsoft-marketing --force`
```

## 1. 이 확장은 무엇인가

<!-- @intent START -->
회원의 **마케팅 정보 수신 동의**를 항목별로 받고, 그 동의·철회 이력을 남기는 플러그인입니다.
이메일·SMS 같은 수신 채널을 운영자가 자유롭게 추가할 수 있고, 제3자 제공 동의·정보 공개
동의처럼 채널이 아닌 항목도 같은 구조로 다룹니다.

**자기 화면이 없습니다.** 관리자 설정 화면 하나를 빼면 이 플러그인의 UI 는 전부 **다른 화면에
끼워 넣는 조각**입니다 — 회원가입 폼·회원 상세·회원 수정 폼·마이페이지 프로필에 동의 항목이
나타나는 것이 그것입니다. 코어 회원 화면을 고치지 않고 동의 항목을 늘리기 위한 구조입니다.

**설계 원칙 셋**:

1. **EAV 구조로 항목을 데이터화한다.** 동의 항목마다 컬럼을 만들면 항목을 늘릴 때 스키마
   변경이 필요합니다. 대신 `user_marketing_consents` 한 테이블에 `consent_key` 별 행을 두어,
   채널 추가가 **설정 변경만으로** 끝나게 했습니다.
2. **코어 회원 흐름에 훅으로 붙는다.** 구독 훅 11개가 전부 코어 것입니다 — 가입·생성·수정·
   삭제·조회 각 지점의 검증 규칙과 데이터에 동의 항목을 얹습니다. 코어 `User` 모델이나 회원
   컨트롤러를 고치지 않습니다.
3. **철회는 동의만큼 쉬워야 한다.** 마이페이지 조각이 항상 노출되고, 동의·철회가 모두
   `user_marketing_consent_histories` 에 기록됩니다(행위·출처·IP). 동의를 받은 경로와 철회
   경로가 대칭이 아니면 그 동의는 법적 근거로 쓸 수 없습니다.

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

## 3. 핵심 흐름

<!-- @intent START -->
**회원가입 시 동의 수집**: 템플릿의 가입 폼이 `user-marketing-register.json` 조각을 그 자리에
받아 동의 체크박스를 그림 → 제출 → 코어 가입 흐름에서 `core.auth.register_validation_rules`
필터가 동의 항목의 검증 규칙을 더함 → 가입 완료 후 `core.auth.register` 액션에서
`MarketingConsentListener::afterRegister()` 가 동의 값을 `user_marketing_consents` 에 기록하고
이력을 남깁니다. **코어 가입 코드는 이 플러그인을 알지 못합니다.**

**동의 변경 → 이력 적재**: 회원이 마이페이지에서, 또는 운영자가 회원 수정 화면에서 동의를
바꾸면 → `core.user.filter_update_data` / `update_validation_rules` 로 동의 필드가 흐름에
편입 → `core.user.after_update` 에서 리스너가 `MarketingConsentService` 로 위임 → 항목별
현재 상태(`is_consented` · `consented_at` · `revoked_at` · `consent_count` · `last_source`)를
갱신하고 이력 한 줄(`action` · `source` · `ip_address`)을 적재한 뒤
`sirsoft-marketing.user.consent_changed` 를 발행합니다.

**채널 추가**: 운영자가 설정 화면에서 채널을 더함 → `PUT admin/channels` →
`core.plugin_settings.filter_save_data` 필터가 저장 형태를 정규화 → `channels` 설정(JSON)에
반영. 다음 요청부터 가입 폼·마이페이지 조각에 그 항목이 나타납니다 — **스키마 변경도 배포도
필요 없습니다.**

**회원 삭제**: `core.user.before_delete` 에서 그 회원의 동의 기록을 정리합니다. 코어 회원
삭제가 이 플러그인의 테이블을 알지 못하므로, 이 훅이 없으면 고아 행이 남습니다.
<!-- @intent END -->

## 4. 확장점

<!-- @generated:extension-points-summary START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 확장점 | 수 | 상세 |
|---|---|---|
| 발행 훅 | 4개 | [발행 훅](docs/extension-points.md#발행-훅) |
| 구독 훅 | 11개 | [구독 훅](docs/extension-points.md#구독-훅) |
| 훅 리스너 | 1개 | [훅 리스너](docs/extension-points.md#훅-리스너) |
| 레이아웃 확장 | 5개 | [레이아웃 확장](docs/extension-points.md#레이아웃-확장) |
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

**구독 방향이 이 플러그인의 성격을 더 잘 보여줍니다.** 11개 구독이 전부 코어 회원·가입
흐름이며, 이것이 곧 "코어를 고치지 않고 회원 도메인에 필드를 더하는 방법" 의 선례입니다:
검증 규칙은 `*_validation_rules` 필터로, 저장 데이터는 `filter_update_data` 로, 응답 표현은
`filter_resource_data` 로, 생명주기 정리는 `before_delete` 로 붙습니다. 회원에 자기 필드를
더하려는 확장은 이 리스너 하나를 읽으면 됩니다.

레이아웃 조각 5개가 UI 전부입니다 — 가입 폼 · 회원 상세 · 회원 수정 폼 · 마이페이지 프로필
(보기/수정). 대상 화면이 그 자리(슬롯)를 없애면 조각은 **오류 없이 사라지므로**, 템플릿이나
코어 회원 화면을 업그레이드한 뒤에는 동의 항목이 여전히 보이는지 눈으로 확인합니다.

미들웨어·브로드캐스트 채널·스케줄·알림은 0개입니다.
<!-- @intent END -->

## 5. 수정 시 동반 의무

- [ ] `_bundled` 에서만 수정하고 `php artisan plugin:update sirsoft-marketing --force` 로 반영
- [ ] manifest version 상향 시 `package.json` · `package-lock.json` · `composer.json` 동기화 + CHANGELOG 기재
- [ ] 스키마 변경 시 마이그레이션(한국어 comment + `down()`) + 기설치본 백필용 업그레이드 스텝
- [ ] 발행 훅 추가·이름 변경 시 `php artisan ext:docgen` 재실행 (구독하는 확장의 계약이 바뀝니다)
- [ ] API 표면 변경 시 `php artisan api:docgen --scope=plugin:sirsoft-marketing` 재실행 + `docs/api/**` 갱신
- [ ] 레이아웃 JSON 변경 시 빌드 없이 update 만 — 신규 Tailwind 클래스는 빌드된 CSS 에 존재하는지 확인
- [ ] 다국어 키 추가 시 ko·en 동시 반영 + 번들 ja 언어팩 증분 동기화
- [ ] 동의 상태를 바꾸는 경로를 추가했다면 이력 적재(`user_marketing_consent_histories`)가 같은 트랜잭션에 있는지 확인
- [ ] 코어 회원·가입 훅 11종 중 하나라도 이름·페이로드가 바뀌면 이 플러그인이 조용히 끊기므로, 코어 회원 흐름 변경 시 함께 확인
- [ ] 레이아웃 조각 5개는 대상 화면의 슬롯이 사라지면 오류 없이 빠진다 — 템플릿·코어 회원 화면 업그레이드 후 노출 확인
- [ ] 약관 페이지 slug 설정은 `sirsoft-page` 모듈의 페이지를 가리킨다 (manifest 의존 `>=1.0.0`)
- [ ] 동의 항목을 늘릴 때는 설정만 바꾼다 — 마이그레이션이 필요해졌다면 EAV 구조를 벗어난 설계라는 신호
- [ ] 레이아웃·컴포넌트·`data_source` 를 건드렸다면 [`docs/editor-spec.md`](docs/editor-spec.md) 의 동반 의무 표를 따라 `editor-spec.json` 을 함께 갱신 — 샘플이 없는 `data_source` 는 편집기 캔버스에서만 빈 화면이 되고 실제 화면은 정상이라 오류도 경고도 남지 않는다. 반영은 `php artisan plugin:update sirsoft-marketing --force`

## 6. 금지 패턴

<!-- @intent START -->
| 금지 | 올바른 사용 | 이유 |
|---|---|---|
| 동의 항목을 추가하려고 `user_marketing_consents` 에 컬럼을 더하기 | `channels` 설정에 항목을 추가 (EAV 구조) | 항목마다 컬럼을 만들면 운영자가 채널을 늘릴 때마다 배포가 필요해진다 — 이 플러그인이 존재하는 이유가 사라진다 |
| 동의 상태만 갱신하고 이력을 남기지 않기 | 상태 갱신과 이력 적재를 같은 트랜잭션에 | 이력이 없는 동의는 법적 근거로 쓸 수 없다. "언제 어느 경로로 동의했는가"가 동의 그 자체다 |
| 마이페이지 철회 조각을 설정 토글로 감추기 | 동의 이력이 있는 회원에게는 항상 노출 | 동의를 받은 경로와 철회 경로가 대칭이 아니면 그 동의는 무효가 된다 |
| 코어 `User` 모델·회원 컨트롤러를 고쳐 동의 필드를 넣기 | `core.user.*` 훅 11종 | 코어 수정은 업그레이드마다 충돌하고, 이 플러그인을 비활성화해도 필드가 남는다 |
| 회원 삭제 시 동의 기록 정리를 DB CASCADE 에 맡기기 | `core.user.before_delete` 구독 | CASCADE 는 훅 발행·이력 처리를 건너뛰고, 아무 오류도 남기지 않는다 |
| 동의 여부를 근거로 이 플러그인이 직접 메일·SMS 를 보내기 | 발송은 발송 담당 확장이, 이 플러그인은 `user.subscribed`/`unsubscribed` 발행까지 | 동의 관리와 발송이 한 확장에 묶이면 발송 수단을 바꿀 때 동의 이력까지 흔들린다 |
| 약관 slug 를 코드에 리터럴로 박기 | 설정(`*_terms_slug`)을 읽어 페이지 모듈에서 조회 | 약관 문서는 운영자가 만들고 고치는 콘텐츠다 |
<!-- @intent END -->

## 7. 테스트 실행

<!-- @generated:test-commands START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 종류 | 개수 | 위치 |
|---|---|---|
| PHPUnit | 5개 | `plugins/_bundled/sirsoft-marketing/tests` |
| Vitest | 2개 | `vitest.config.ts` |
| Playwright | 0개 | — |
| 시나리오 매니페스트 | 0개 | — |

기저 TestCase: `tests/PluginTestCase.php` — 확장 테스트는 이 클래스를 상속합니다 (`Tests\TestCase` 직접 상속 금지).

```bash
# PHPUnit (변경 범위만) (Bash)
php vendor/bin/phpunit plugins/_bundled/sirsoft-marketing/tests --filter='<대상클래스>'

# Vitest (확장 디렉토리에서) (PowerShell)
cd plugins/_bundled/sirsoft-marketing && powershell -Command "npm run test:run -- <대상>"

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
