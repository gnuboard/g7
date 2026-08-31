# Hello 플러그인 — 에이전트 가이드

> 이 문서는 이 플러그인을 수정하는 에이전트·확장개발자를 위한 것입니다. 도입 검토·운영 관점은 [README.md](README.md) 를 보세요.

## TL;DR (5초 요약)

```text
1. 유형: 플러그인 (gnuboard7-hello_plugin) — 학습용 최소 샘플. 학습용 모듈의 훅을 Action·Filter 두 방식으로 구독하는 것만 시연한다. 모델·테이블 없음, `hidden: true`
2. 확장 방식: 발행 훅 1개(`log.written`) — 구독한 플러그인이 다시 발행해 연쇄를 잇는 형태를 보인다
3. 건드리면 안 되는 것: Filter 구독의 `'type' => 'filter'` 누락(반환값이 버려진다), 대상 모듈 직접 수정, 설정 토글 없는 무조건 동작, 완전한 페이지 레이아웃 등록
4. 작업 위치: `plugins/_bundled/gnuboard7-hello_plugin` — 활성 디렉토리 직접 수정 금지
5. 반영: `php artisan plugin:update gnuboard7-hello_plugin --force`
```

## 1. 이 확장은 무엇인가

<!-- @intent START -->
**학습용 최소 샘플 플러그인**입니다. 플러그인의 핵심 역할인 **훅 구독**을 두 종류로 시연하는
것이 유일한 목적입니다 — 부가 작업을 수행하는 Action 리스너 하나와, 흐름 중간에서 값을 가공하는
Filter 리스너 하나.

대상은 학습용 모듈(`gnuboard7-hello_module`)의 메모입니다. 메모가 생성되면 로그를 남기고(Action),
메모 제목이 화면에 나가기 전에 접두사를 붙입니다(Filter). **모듈 코드는 한 줄도 고치지
않습니다** — 그것이 훅 시스템이 존재하는 이유입니다.

**모듈과 플러그인의 경계**도 함께 보여줍니다. 플러그인은 완전한 페이지 레이아웃을 등록할 수
없고, 설정 화면(`plugin_settings.json`)과 `layout_extensions`(다른 화면에 끼워 넣는 조각)만
허용됩니다. 이 샘플에는 설정 화면 하나가 있습니다.

`manifest.hidden = true` 라 관리자 UI 의 플러그인 목록에 나타나지 않습니다. artisan CLI 로는
정상 설치·활성화됩니다.

**의도적으로 하지 않는 것**: 모델·테이블·마이그레이션·API 라우트. 플러그인이 자기 데이터를 가질
수는 있지만(다른 플러그인들이 그렇습니다), 이 샘플은 **훅만** 보이면 되므로 두지 않았습니다.
<!-- @intent END -->

## 2. 디렉토리 지도

<!-- @generated:directory-map START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 경로 | 역할 | 수정 시 필요한 절차 |
|---|---|---|
| `plugin.json` | manifest (버전 SSoT) | version 변경 시 package.json·package-lock.json·composer.json 동기화 |
| `plugin.php` | 진입 클래스 (선언형 표면 SSoT) | 표면 변경 시 `ext:docgen` 재실행 + 코어 최소 버전 검토 |
| `src/Services/` | 비즈니스 로직 | Repository 인터페이스 주입 (구체 클래스 금지) |
| `src/Listeners/` | 훅 리스너 | Repository 경유 (Model·DB 파사드 직접 접근 금지) |
| `src/routes/` | 라우트 | 모든 라우트에 `name()` 필수 |
| `resources/layouts/` | 레이아웃 JSON | `php artisan plugin:update gnuboard7-hello_plugin --force` (빌드 불필요) |
| `config/` | 확장 config | 설정 기본값은 settings 스키마와 어긋나지 않게 |
| `tests/` | 테스트 | 변경 범위만 필터 실행 |
| `CHANGELOG.md` | 변경 이력 | 버전 상향 시 항목 추가 (미기재 시 버전 상향 불가) |
| `docs/` | 개발자 문서 | 표면 변경 시 `php artisan ext:docgen` 재실행 |
<!-- @generated:directory-map END -->

## 3. 핵심 흐름

<!-- @intent START -->
**Action 구독** — 부가 작업: 학습용 모듈의 `MemoService::create()` 가
`gnuboard7-hello_module.memo.created` 를 발행 → `LogMemoCreatedListener::onMemoCreated()`
가 그것을 받아 로그를 기록 → 기록 직후 자기 훅
`gnuboard7-hello_plugin.log.written` 을 발행합니다. **구독한 플러그인이 다시 발행하는** 이
연쇄가 훅 시스템의 확장 방식입니다 — 또 다른 확장이 이 플러그인의 동작에 반응할 수 있습니다.

로그 기록 여부는 설정(`log_enabled`)이 정합니다. **설정으로 끌 수 있게 만드는 것**이 부가
동작의 규약입니다 — 리스너가 무조건 동작하면 그 확장을 설치한 사이트는 끌 방법이 없습니다.

**Filter 구독** — 값 가공: `gnuboard7-hello_module.memo.title.filter` 가 발행되면
`FilterMemoTitleListener::prependHelloPrefix()` 가 그 값을 받아 접두사를 붙여 **반환**합니다.
Action 과 달리 Filter 는 **반환값이 흐름에 다시 들어갑니다.**

이 훅은 **학습용 모듈이 실제로 발행하지 않습니다.** 리스너 docblock 이 "발행한다고 가정하고"
라고 밝히고 있으며, 그 자체가 학습 포인트입니다 — **훅이 발행되지 않아도 리스너 등록은
유효하고**, 나중에 발행 지점이 생기면 그때부터 자동으로 호출됩니다. 구독은 발행자에게 아무런
부담을 주지 않으므로 확장이 서로를 몰라도 됩니다.

Filter 구독에는 `'type' => 'filter'` 선언이 반드시 필요합니다. 빠뜨리면 코어가 그것을 Action
으로 취급해 **반환값을 버립니다** — 리스너는 정상 실행되고 오류도 없는데 가공만 반영되지
않습니다.
<!-- @intent END -->

## 4. 확장점

<!-- @generated:extension-points-summary START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 확장점 | 수 | 상세 |
|---|---|---|
| 발행 훅 | 1개 | [발행 훅](docs/extension-points.md#발행-훅) |
| 구독 훅 | 2개 | [구독 훅](docs/extension-points.md#구독-훅) |
| 훅 리스너 | 2개 | [훅 리스너](docs/extension-points.md#훅-리스너) |
| 레이아웃 확장 | 0개 | [레이아웃 확장](docs/extension-points.md#레이아웃-확장) |
| 미들웨어 | 0개 | [미들웨어](docs/extension-points.md#미들웨어) |
| 브로드캐스트 채널 | 0개 | [브로드캐스트 채널](docs/extension-points.md#브로드캐스트-채널) |
| 스케줄 | 0개 | [스케줄](docs/extension-points.md#스케줄) |
| 알림 정의 | 0개 | [알림 정의](docs/extension-points.md#알림-정의) |
<!-- @generated:extension-points-summary END -->

<!-- @intent START -->
이 샘플이 보여주는 것은 **구독 쪽**이지만, 발행도 하나 있습니다.

| 방향 | 훅 | 무엇을 보여주는가 |
|---|---|---|
| 구독 (Action) | `gnuboard7-hello_module.memo.created` | 다른 확장의 흐름에 부가 작업을 붙이는 법 |
| 구독 (Filter) | `gnuboard7-hello_module.memo.title.filter` | 흐름 중간의 값을 가공하는 법 (`'type' => 'filter'` 필수). **모듈이 실제로 발행하지는 않는 가상의 훅** — 미발행 훅 구독도 유효함을 함께 보인다 |
| 발행 (Action) | `gnuboard7-hello_plugin.log.written` | 구독한 확장이 **다시 발행**해 연쇄를 잇는 법 |

발행 훅에는 `getHooks()` 선언이 있어 표에 유형과 설명이 함께 실립니다 — 발행 훅을 선언하면
구독하려는 쪽에 계약이 드러납니다.

**의존 방향에 주의합니다.** 이 플러그인은 `gnuboard7-hello_module` 에 manifest 의존을
선언합니다. 구독 대상이 없으면 훅이 발화하지 않을 뿐이지만, 이 샘플은 **그 모듈의 훅을 보는
것 자체가 목적**이라 모듈 없이는 존재 이유가 없습니다. 실제 플러그인에서는 "없으면 그 기능만
비는" 관계인지 "없으면 성립하지 않는" 관계인지를 보고 의존 선언 여부를 정합니다.

레이아웃 확장·미들웨어·브로드캐스트·스케줄·알림·권한·메뉴는 없습니다.
<!-- @intent END -->

## 5. 수정 시 동반 의무

- [ ] `_bundled` 에서만 수정하고 `php artisan plugin:update gnuboard7-hello_plugin --force` 로 반영
- [ ] manifest version 상향 시 `package.json` · `package-lock.json` · `composer.json` 동기화 + CHANGELOG 기재
- [ ] 발행 훅 추가·이름 변경 시 `php artisan ext:docgen` 재실행 (구독하는 확장의 계약이 바뀝니다)
- [ ] API 표면 변경 시 `php artisan api:docgen --scope=plugin:gnuboard7-hello_plugin` 재실행 + `docs/api/**` 갱신
- [ ] 레이아웃 JSON 변경 시 빌드 없이 update 만 — 신규 Tailwind 클래스는 빌드된 CSS 에 존재하는지 확인
- [ ] Filter 훅을 구독한다면 `'type' => 'filter'` 를 선언했는지 확인 — 누락 시 반환값이 조용히 버려진다
- [ ] 부가 동작은 설정 토글 뒤에 둔다 (`log_enabled` 가 그 본보기)
- [ ] `manifest.hidden = true` 를 유지 (복제본에서만 제거)
- [ ] 구독 대상 모듈의 훅 이름이 바뀌면 이 플러그인이 조용히 아무 일도 하지 않게 된다
- [ ] `docs/extension/sample-extensions.md` 의 계층 표와 어긋나지 않는지 확인 (파일을 추가·삭제했다면 그 표도 갱신)
- [ ] 플러그인은 완전한 페이지 레이아웃을 등록할 수 없다 — 설정 화면과 `layout_extensions` 만

## 6. 금지 패턴

<!-- @intent START -->
| 금지 | 올바른 사용 | 이유 |
|---|---|---|
| Filter 훅을 구독하면서 `'type' => 'filter'` 를 빠뜨리기 | 선언 필수 | 코어가 Action 으로 취급해 **반환값을 버린다** — 리스너는 실행되고 오류도 없는데 가공만 반영되지 않는다 |
| 대상 모듈의 코드를 직접 고쳐 부가 동작을 넣기 | 훅 구독 | 모듈이 업그레이드될 때마다 충돌하고, 플러그인을 꺼도 그 동작이 남는다 |
| 부가 동작을 설정 없이 무조건 수행 | 설정 토글(`log_enabled`) 뒤에 둔다 | 설치한 사이트가 끌 방법이 없다 |
| 리스너에서 `Model::query()` · `DB::table()` · `$row->save()` 직접 호출 | Repository 인터페이스 주입 | 리스너가 데이터 접근 규약의 예외가 되면 그 예외가 번진다 |
| 플러그인에 완전한 페이지 레이아웃을 등록 | 설정 화면(`plugin_settings.json`)과 `layout_extensions` 만 | 페이지 소유권은 모듈·템플릿에 있다 — 경로를 다투면 설치 순서에 따라 화면이 바뀐다 |
| 이 샘플에 기능을 더해 "쓸모 있게" 만들기 | 짧게 유지하고, 필요한 기능은 별도 확장으로 | 샘플의 가치는 한눈에 읽히는 것이다 |
| `manifest.hidden` 을 제거 | 그대로 둔다 (복제본에서만 제거) | 학습용 플러그인이 운영 사이트의 목록에 섞인다 |
| 금전이 오가는 훅을 기본 설정(큐)으로 구독 | `'sync' => true` | 커밋 뒤 실행이라 예외를 던져도 롤백되지 않는다 (이 샘플에는 해당 없으나 실제 플러그인에서 자주 걸린다) |
<!-- @intent END -->

## 7. 테스트 실행

<!-- @generated:test-commands START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 종류 | 개수 | 위치 |
|---|---|---|
| PHPUnit | 2개 | `plugins/_bundled/gnuboard7-hello_plugin/tests` |
| Vitest | 0개 | — |
| Playwright | 0개 | — |
| 시나리오 매니페스트 | 0개 | — |

기저 TestCase: `tests/PluginTestCase.php` — 확장 테스트는 이 클래스를 상속합니다 (`Tests\TestCase` 직접 상속 금지).

```bash
# PHPUnit (변경 범위만) (Bash)
php vendor/bin/phpunit plugins/_bundled/gnuboard7-hello_plugin/tests --filter='<대상클래스>'

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
| [CHANGELOG.md](CHANGELOG.md) | 변경 이력 | ✅ |
<!-- @generated:docs-index END -->
