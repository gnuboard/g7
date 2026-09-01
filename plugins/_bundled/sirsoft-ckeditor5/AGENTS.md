# CKEditor 5 WYSIWYG 에디터 — 에이전트 가이드

> 이 문서는 이 플러그인을 수정하는 에이전트·확장개발자를 위한 것입니다. 도입 검토·운영 관점은 [README.md](README.md) 를 보세요.

## TL;DR (5초 요약)

```text
1. 유형: 플러그인 (sirsoft-ckeditor5) — 코어 확장점 `html_editor`/`html_content` 에 CKEditor 5 를 끼워 넣는다. 편집기 자산은 CDN 이 아니라 `dist/vendor/ckeditor5/43.3.1/` 동봉본을 same-origin 으로 서빙
2. 확장 방식: 발행 훅 4개. 업로드 전후 개입은 `image.before_upload`/`after_upload`/`filter_upload_file`, **본문에 이미지를 담는 확장은 `image.filter_reference_sources` 에 자기 테이블을 반드시 등록**
3. 건드리면 안 되는 것: 자산을 CDN 으로 되돌리기, 편집기 실패 시 빈 컨테이너 방치(평문 폴백 + `{name}_mode='text'` 유지), 참조 판정을 토큰 하나로 축소, 로그 사본 테이블을 참조 소스로 등록
4. 작업 위치: `plugins/_bundled/sirsoft-ckeditor5` — 활성 디렉토리 직접 수정 금지
5. 반영: `php artisan plugin:update sirsoft-ckeditor5 --force`
```

## 1. 이 확장은 무엇인가

<!-- @intent START -->
코어가 정의한 두 확장점(`html_editor` · `html_content`)에 CKEditor 5 구현을 끼워 넣는
플러그인입니다. 게시판 본문·상품 설명·페이지 내용 어디든 위지윅이 필요한 자리는 코어가 확장점만
비워 두고, 이 플러그인이 `mode: replace` 로 그 자리를 차지합니다 — 그래서 편집기를 다른 것으로
바꾸는 일은 코어를 고치는 것이 아니라 **이 플러그인을 다른 플러그인으로 교체하는 것**입니다.

**설계 원칙 셋**:

1. **편집기 자산을 자체 제공한다.** CKEditor 5 는 CDN 이 아니라 `dist/vendor/ckeditor5/43.3.1/`
   에 동봉되어 same-origin 으로 서빙됩니다. CDN 도달 실패는 예외도 서버 로그도 남기지 않고
   편집기만 조용히 사라지기 때문입니다(폐쇄망·방화벽·광고차단기에서 재현).
2. **편집기를 못 불러와도 글은 쓸 수 있어야 한다.** 자산 확보에 실패하면 평문 입력창으로
   내려가고 저장 계약(`{name}_mode = 'text'`)을 유지합니다. 재시도로 편집기가 뜨면 그때
   `_mode` 를 `'html'` 로 되돌리며, 그 사이에 쓴 내용은 승계됩니다.
3. **이미지 삭제 판정은 fail-closed 다.** 업로드 이미지가 어디서도 참조되지 않을 때만 지우는데,
   그 "어디"를 각 모듈이 훅으로 등록합니다. 설치돼 있으나 **비활성**인 모듈이 있으면 그
   콘텐츠가 판정에서 빠져 실제로 쓰이는 이미지를 미참조로 오판하므로, 그 상태를 감지해
   정리를 멈춥니다.

**의도적으로 하지 않는 것**: 훅 구독 0 · 리스너 0 · 미들웨어 0 · 브로드캐스트 0 · 알림 0.
이 플러그인은 다른 확장의 흐름에 개입하지 않고, 자기 확장점 안에서만 삽니다. 본문 정화
(sanitize)도 이 플러그인의 일이 아닙니다 — 저장측 검증과 봇 화면 정화는 코어가 담당합니다.
<!-- @intent END -->

## 2. 디렉토리 지도

<!-- @generated:directory-map START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 경로 | 역할 | 수정 시 필요한 절차 |
|---|---|---|
| `plugin.json` | manifest (버전 SSoT) | version 변경 시 package.json·package-lock.json·composer.json 동기화 |
| `plugin.php` | 진입 클래스 (선언형 표면 SSoT) | 표면 변경 시 `ext:docgen` 재실행 + 코어 최소 버전 검토 |
| `src/Http/Controllers/` | 컨트롤러 | API 표면 변경 시 `api:docgen` 재실행 |
| `src/Http/Requests/` | FormRequest (검증 SSoT) | 검증 규칙은 Service 가 아니라 여기에 둔다 |
| `src/Http/Resources/` | API 리소스 | 목록 응답은 화면이 실제로 그리는 것만 싣는다 |
| `src/Services/` | 비즈니스 로직 | Repository 인터페이스 주입 (구체 클래스 금지) |
| `src/Repositories/` | 데이터 접근 | 목록 쿼리는 컬럼 프루닝·정렬 화이트리스트 확인 |
| `src/Models/` | Eloquent 모델 | 스키마 변경 시 마이그레이션 + 업그레이드 스텝 동반 |
| `src/routes/` | 라우트 | 모든 라우트에 `name()` 필수 |
| `database/migrations/` | 마이그레이션 | 한국어 comment + `down()` 필수, 기설치본은 업그레이드 스텝으로 백필 |
| `upgrades/` | 업그레이드 스텝 | DB·설정 구조 변경 시 작성 (모듈/플러그인 전용) |
| `resources/layouts/` | 레이아웃 JSON | `php artisan plugin:update sirsoft-ckeditor5 --force` (빌드 불필요) |
| `resources/routes.json` | 라우트 → 레이아웃 매핑 | `php artisan plugin:update sirsoft-ckeditor5 --force` |
| `resources/js/` | 프론트 엔트리·핸들러 | `php artisan plugin:build` → `php artisan plugin:update sirsoft-ckeditor5 --force` |
| `resources/extensions/` | 다른 확장 레이아웃에 주입하는 조각 | `php artisan plugin:update sirsoft-ckeditor5 --force` |
| `dist/` | 커밋되는 빌드 산출물 | `--production` 으로 재빌드 (sourceMappingURL 잔존 금지) |
| `config/` | 확장 config | 설정 기본값은 settings 스키마와 어긋나지 않게 |
| `tests/` | 테스트 | 변경 범위만 필터 실행 |
| `CHANGELOG.md` | 변경 이력 | 버전 상향 시 항목 추가 (미기재 시 버전 상향 불가) |
| `components.json` | 편집기 컴포넌트 선언 (레이아웃 저작자가 읽는 props 계약) | `php artisan plugin:update sirsoft-ckeditor5 --force` |
| `docs/` | 개발자 문서 | 표면 변경 시 `php artisan ext:docgen` 재실행 |
| `lang/` | 다국어 | 키 추가 시 ko·en 동시 반영 + 번들 ja 팩 동기화 |
<!-- @generated:directory-map END -->

## 3. 핵심 흐름

<!-- @intent START -->
**편집기 장착**: 어떤 화면이 `html_editor` 확장점을 열면 → 코어가
`resources/extensions/html-editor.json` 을 그 자리에 치환 → 조각의 `scripts` 가 동봉된
`ckeditor5.umd.js` 를 same-origin 으로 로드 → 컨테이너 `onMount` 에서
`sirsoft-ckeditor5.initEditor` 핸들러가 실행되어 편집기를 붙이고
`form.{name}_mode = 'html'` 을 세웁니다. 화면을 떠날 때 `destroyEditor` 가 인스턴스를
해제합니다. 자산 로드가 실패하면 `renderTextareaFallback` 이 평문 입력창을 그리고 사용자에게
사실을 알린 뒤 재시도 통로를 남깁니다.

**이미지 업로드 → 서빙**: 편집기가 `POST /api/plugins/sirsoft-ckeditor5/upload` 호출 →
`ImageUploadService`(`before_upload` → `filter_upload_file` → 저장 → `after_upload`) →
`ckeditor5_image_uploads` 에 기록. 서빙은 **해시 경로**(`GET images/{hash}`)이며, 설정
디스크가 공개 URL 을 주는 환경에서는 본문에 디스크 직접 URL 이 박힙니다 — 그래서 본문에
남는 URL 형태가 두 가지입니다.

**미참조 이미지 정리**: `sirsoft-ckeditor5:prune-unused-images --scheduled`(일 1회, 설정
`unusedImageCleanup` 이 켜져 있을 때만) → `ImageReferenceScanService` 가 코어 소스 6개
테이블 + 모듈이 `image.filter_reference_sources` 로 등록한 소스를 훑어, **해시와 저장
파일명 두 토큰을 OR 로** 검사합니다(한쪽만 보면 다른 형태로 저장된 이미지를 미참조로
오판합니다). 보존기간(`unusedImageRetentionDays`, 기본 30일)이 지난 것만 대상이며,
비활성 설치 모듈이 있으면 판정 자체를 중단합니다.
<!-- @intent END -->

## 4. 확장점

<!-- @generated:extension-points-summary START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 확장점 | 수 | 상세 |
|---|---|---|
| 발행 훅 | 4개 | [발행 훅](docs/extension-points.md#발행-훅) |
| 구독 훅 | 0개 | [구독 훅](docs/extension-points.md#구독-훅) |
| 훅 리스너 | 0개 | [훅 리스너](docs/extension-points.md#훅-리스너) |
| 레이아웃 확장 | 2개 | [레이아웃 확장](docs/extension-points.md#레이아웃-확장) |
| 미들웨어 | 0개 | [미들웨어](docs/extension-points.md#미들웨어) |
| 브로드캐스트 채널 | 0개 | [브로드캐스트 채널](docs/extension-points.md#브로드캐스트-채널) |
| 스케줄 | 1개 | [스케줄](docs/extension-points.md#스케줄) |
| 알림 정의 | 0개 | [알림 정의](docs/extension-points.md#알림-정의) |
<!-- @generated:extension-points-summary END -->

<!-- @intent START -->
발행 훅은 4종뿐이지만 성격이 둘로 갈립니다.

| 훅 | 무엇을 열어 주는가 |
|---|---|
| `image.before_upload` · `image.after_upload` | 업로드 전후. 본인인증 강제·쿼터 제한·외부 저장소 미러링을 붙이는 자리입니다 |
| `image.filter_upload_file` | 저장 직전 파일 변형 (압축·리사이즈·형식 변환) |
| `image.filter_reference_sources` | **정리 대상 판정에 자기 콘텐츠를 등록하는 자리** |

`image.filter_reference_sources` 가 이 플러그인에서 가장 중요한 훅입니다. 본문에 이미지를
담는 확장(게시판 글·상품 설명·페이지 내용)은 **반드시 자기 테이블·컬럼을 여기에 등록**해야
합니다. 등록하지 않으면 그 확장의 콘텐츠는 참조 판정에서 통째로 빠지고, 실제로 화면에 보이는
이미지가 "미참조" 로 분류되어 정리 대상이 됩니다 — 오류 없이 이미지가 깨지는 형태로만 드러납니다.

등록할 때 **로그 사본 테이블을 소스로 삼지 않습니다.** 알림 발송 로그·메일 로그·신고 스냅샷·
레이아웃 미리보기는 자체 보존기간으로 지워지는 사본이라, 소스로 넣으면 "로그가 지워지는 순간
이미지가 고아가 되는" 역전이 생깁니다. 코어가 그 넷을 명시적으로 제외한 이유입니다.

레이아웃 확장 2개(`html-editor.json` · `html-content.json`)는 코어 확장점을 `replace` 로
차지합니다. 다른 편집기 플러그인이 같은 확장점을 노리면 어느 쪽이 이기는지가 설치 순서에
좌우되므로, 편집기 플러그인은 하나만 활성화하는 것이 전제입니다.

구독 훅·리스너·미들웨어·브로드캐스트 채널·알림은 전부 0개입니다.
<!-- @intent END -->

## 5. 수정 시 동반 의무

- [ ] `_bundled` 에서만 수정하고 `php artisan plugin:update sirsoft-ckeditor5 --force` 로 반영
- [ ] manifest version 상향 시 `package.json` · `package-lock.json` · `composer.json` 동기화 + CHANGELOG 기재
- [ ] 스키마 변경 시 마이그레이션(한국어 comment + `down()`) + 기설치본 백필용 업그레이드 스텝
- [ ] 발행 훅 추가·이름 변경 시 `php artisan ext:docgen` 재실행 (구독하는 확장의 계약이 바뀝니다)
- [ ] API 표면 변경 시 `php artisan api:docgen --scope=plugin:sirsoft-ckeditor5` 재실행 + `docs/api/**` 갱신
- [ ] 레이아웃 JSON 변경 시 빌드 없이 update 만 — 신규 Tailwind 클래스는 빌드된 CSS 에 존재하는지 확인
- [ ] TSX/TS 변경 시 `--production` 재빌드 후 `dist/` 커밋 (sourceMappingURL 잔존 금지)
- [ ] 다국어 키 추가 시 ko·en 동시 반영 + 번들 ja 언어팩 증분 동기화
- [ ] 동봉 CKEditor 5 버전을 올렸다면 디렉토리명 · `resources/extensions/html-editor.json` 의 `scripts.src` · 소스 상수 · 테스트 단언을 **한 버전으로** 맞춘다 (하나만 어긋나면 그 자산이 404 인데 빌드·테스트는 통과한다)
- [ ] `dist/` 는 커밋되는 배포 산출물 — TS 를 고쳤으면 `--production` 재빌드 후 커밋 (`sourceMappingURL` 잔존 금지)
- [ ] 참조 소스 목록(코어 6종)을 바꿨다면 로그 사본 테이블이 섞이지 않았는지 확인
- [ ] 정리 커맨드의 판정 로직을 고쳤다면 fail-open 가드(`hasPotentiallyMissingSources()`)가 여전히 앞에 있는지 확인 — 이 가드가 빠지면 이미지가 조용히 지워진다
- [ ] 편집기 폴백 경로를 고쳤다면 저장 계약(`{name}_mode`)과 재시도 시 내용 승계가 유지되는지 확인
- [ ] 프론트엔드를 고쳤다면 Playwright 위지윅 spec 을 함께 갱신·실행한다 (단위 테스트만으로는 편집기 장착 회귀가 드러나지 않는다)
- [ ] 레이아웃·컴포넌트·`data_source` 를 건드렸다면 [`docs/editor-spec.md`](docs/editor-spec.md) 를 확인 — 이 확장은 편집기 스펙이 없어 `ckeditor5Uploads` 가 편집기 캔버스에서 빈 화면으로 보인다. `data_source` 를 더 늘리면 그 자리도 같은 상태가 된다

## 6. 금지 패턴

<!-- @intent START -->
| 금지 | 올바른 사용 | 이유 |
|---|---|---|
| 편집기 자산을 CDN 에서 로드 | `dist/vendor/ckeditor5/{version}/` 동봉 + same-origin 서빙 | CDN 도달 실패는 예외도 로그도 남기지 않고 편집기만 사라진다 — 폐쇄망·광고차단기에서 재현되고 서버에 흔적이 없다 |
| 자산 URL 을 문자열로 조립 (`'/api/plugins/assets/'+id+'/…'`) | `G7Core.asset.plugin` | 확장자를 정적 location 이 가로채는 서버에서 조립한 URL 만 404 가 된다 |
| 편집기 확보 실패 시 빈 컨테이너를 남기기 | 평문 입력창 폴백 + 저장 계약(`{name}_mode='text'`) 유지 + 재시도 시 내용 승계 | 빈 컨테이너는 "글을 쓸 수 없다" 인데 화면에는 아무 설명이 없다 |
| 본문에 이미지를 담는 확장이 `image.filter_reference_sources` 에 등록하지 않음 | 자기 테이블·컬럼을 등록 | 그 콘텐츠가 참조 판정에서 빠져, 화면에 보이는 이미지가 미참조로 분류되어 삭제된다 |
| 로그 사본 테이블(알림 로그·메일 로그·신고 스냅샷·레이아웃 미리보기)을 참조 소스로 등록 | 원본 콘텐츠 테이블만 등록 | 사본은 자체 보존기간으로 지워진다 — 로그가 지워지는 순간 이미지가 고아가 되는 역전이 생긴다 |
| 참조 판정을 해시 토큰 하나로만 수행 | 해시와 저장 파일명 두 토큰을 OR 로 검사 | 본문에 박히는 URL 형태가 둘(API 폴백형·디스크 직접형)이라, 한쪽만 보면 다른 형태를 미참조로 오판한다 |
| 비활성 설치 모듈이 있는 상태에서 정리를 강행 | `hasPotentiallyMissingSources()` 로 감지해 중단 | 비활성 모듈의 콘텐츠는 훅을 등록하지 않으므로 판정에서 빠진다 (fail-open 방지) |
| 동봉 자산 버전을 올리면서 일부 기재만 갱신 | 디렉토리명·레이아웃 조각의 `scripts.src`·의존성 핀·소스 상수·테스트 단언을 한 버전으로 | 하나만 어긋나도 그 자산이 404 가 되는데 빌드와 테스트는 통과한다 |
<!-- @intent END -->

## 7. 테스트 실행

<!-- @generated:test-commands START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 종류 | 개수 | 위치 |
|---|---|---|
| PHPUnit | 15개 | `plugins/_bundled/sirsoft-ckeditor5/tests` |
| Vitest | 9개 | `vitest.config.ts` |
| Playwright | 3개 | `tests/Playwright` |
| 시나리오 매니페스트 | 1개 | `tests/scenarios` |

기저 TestCase: `tests/PluginTestCase.php` — 확장 테스트는 이 클래스를 상속합니다 (`Tests\TestCase` 직접 상속 금지).

```bash
# PHPUnit (변경 범위만) (Bash)
php vendor/bin/phpunit plugins/_bundled/sirsoft-ckeditor5/tests --filter='<대상클래스>'

# Vitest (확장 디렉토리에서) (PowerShell)
cd plugins/_bundled/sirsoft-ckeditor5 && powershell -Command "npm run test:run -- <대상>"

# Playwright E2E (Bash)
npx playwright test plugins/_bundled/sirsoft-ckeditor5/tests/Playwright/specs/<대상>.spec.ts

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
