# CKEditor 5 WYSIWYG 에디터 — 아키텍처

> 설계 의도와 계층 구조 · 진입점: [AGENTS.md](../AGENTS.md)

## 설계 의도

<!-- @intent START -->
편집기는 **교체 가능해야 한다**는 전제에서 출발합니다. 그래서 본문 입력 화면들은 편집기를
직접 알지 않고 코어 확장점(`html_editor` · `html_content`)만 열어 두고, 이 플러그인이
`mode: replace` 로 그 자리를 차지합니다. 편집기를 바꾸는 일은 화면들을 고치는 것이 아니라
플러그인을 교체하는 것입니다.

그 구조의 대가로 **같은 확장점을 노리는 편집기 플러그인이 둘이면 승자가 설치 순서에
좌우됩니다.** 편집기 플러그인은 하나만 활성화하는 것이 전제이며, 이는 규칙이 아니라 구조적
성질입니다.

나머지 설계는 전부 "**조용한 실패를 만들지 않는다**" 로 수렴합니다:

- **자산을 자체 제공한다** — CKEditor 5 를 `dist/vendor/ckeditor5/43.3.1/` 에 동봉해
  same-origin 으로 서빙합니다. CDN 도달 실패는 서버 로그에 흔적이 없고 브라우저에서 편집기만
  사라지므로, 운영자가 원인을 특정할 수 없습니다.
- **실패해도 글은 쓸 수 있다** — 자산 확보에 실패하면 평문 입력창으로 내려가되 저장 계약
  (`{name}_mode`)을 유지하고, 사용자에게 사실과 재시도 통로를 제시합니다. 빈 컨테이너를 남기는
  것은 "글을 쓸 수 없다" 인데 화면에는 아무 설명이 없는 상태입니다.
- **이미지 정리는 fail-closed** — 참조 판정에 필요한 소스를 다 모으지 못한 정황(비활성 설치
  모듈)이 있으면 정리를 아예 하지 않습니다. 잘못 지운 이미지는 되돌릴 수 없기 때문입니다.

**의도적으로 하지 않는 것**: 본문 정화(sanitize)·훅 구독·리스너·미들웨어·브로드캐스트·알림.
저장측 검증과 봇 화면 정화는 코어의 일이며, 이 플러그인이 정화까지 맡으면 편집기를 교체하는
순간 그 방어가 함께 사라집니다.
<!-- @intent END -->

## 계층 지도

<!-- @intent START -->
```
[프론트]  레이아웃 확장 조각 (html-editor.json / html-content.json)
              │  scripts: 동봉 CKEditor 5 UMD (same-origin)
              ▼
          핸들러 3종 (initEditor / destroyEditor / injectContentCss)
              │  실패 시 → renderTextareaFallback + 재시도 + `_mode='text'`
              ▼
[백엔드]  Http/Controllers
              ├─ ImageUploadController      (업로드)
              ├─ ImageServeController       (해시 서빙)
              └─ Admin/ImageUploadAdminController (목록·삭제)
              │
              ▼
          Services
              ├─ ImageUploadService        : before_upload → filter_upload_file → after_upload
              └─ ImageReferenceScanService : 참조 판정 (코어 소스 6 + 훅 등록 소스)
              │
              ▼
          Repositories (Interface 경유)
              │
              ▼
          Ckeditor5ImageUpload / ckeditor5_image_uploads
```

`ImageReferenceScanService` 만 다른 계층과 성격이 다릅니다 — 이 플러그인의 데이터가 아니라
**다른 확장의 콘텐츠 테이블**을 읽습니다. 그래서 두 가지 방어가 붙어 있습니다: 소스 목록을
요청 수명 동안 memoize 하고, 비활성 설치 모듈을 감지하면 판정을 중단합니다
(`hasPotentiallyMissingSources()`).

`getDynamicTables()` 로 `ckeditor5_image_uploads` 를 선언하는 것은 이 테이블이 플러그인
제거와 함께 정리되는 대상임을 코어에 알리기 위해서입니다.
<!-- @intent END -->

## 디렉토리

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
