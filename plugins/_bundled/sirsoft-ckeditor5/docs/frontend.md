# CKEditor 5 WYSIWYG 에디터 — 프론트엔드

> 레이아웃·액션 핸들러·전역 진입점·에셋 · 진입점: [AGENTS.md](../AGENTS.md)

## 레이아웃

<!-- @generated:layouts START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
레이아웃 2개 (루트: `resources/layouts`).

| 그룹 | 개수 |
|---|---|
| `admin` | 2개 |

| 레이아웃 | 그룹 | 종류 | extends |
|---|---|---|---|
| `ckeditor5_uploads` | `admin` | 화면 | `_admin_base` |
| `plugin_settings` | `admin` | 화면 | `_admin_base` |
<!-- @generated:layouts END -->

<!-- @intent START -->
관리자 화면 2개뿐입니다 — 업로드 이미지 목록(`ckeditor5_uploads`)과 플러그인 설정
(`plugin_settings`).

**이 플러그인의 실제 UI 는 여기 없습니다.** 편집기는 레이아웃이 아니라 확장점 조각
(`resources/extensions/html-editor.json` · `html-content.json`)으로 다른 화면 안에 들어가므로,
편집기 모양을 바꾸는 작업은 이 두 레이아웃이 아니라 그 조각과 핸들러 쪽입니다.

`plugin_settings.json` 은 **파일 이름이 계약**입니다. 코어가 플러그인 디렉토리의 이 고정
경로를 찾아 설정 화면을 그리므로, 이름을 바꾸면 설정 화면이 사라집니다.

레이아웃 JSON 만 고쳤다면 빌드는 필요 없고 `php artisan plugin:update sirsoft-ckeditor5 --force`
로 반영합니다.
<!-- @intent END -->

## 액션 핸들러

<!-- @generated:handlers START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
핸들러 3개 (정의: `resources/js/index.ts`).

| 핸들러 | 레이아웃에서 부르는 이름 |
|---|---|
| `initEditor` | `sirsoft-ckeditor5.initEditor` |
| `destroyEditor` | `sirsoft-ckeditor5.destroyEditor` |
| `injectContentCss` | `sirsoft-ckeditor5.injectContentCss` |
<!-- @generated:handlers END -->

<!-- @intent START -->
셋뿐이지만 이 플러그인의 동작 대부분이 여기 있습니다.

| 핸들러 | 하는 일 |
|---|---|
| `initEditor` | 컨테이너에 편집기를 붙이고 `form.{name}_mode = 'html'` 설정. **자산 확보 실패 시 평문 입력창 폴백 + 사용자 통지 + 재시도 통로** |
| `destroyEditor` | 화면을 떠날 때 인스턴스 해제 (누수 방지) |
| `injectContentCss` | 읽기 화면에 본문 스타일 주입 |

`initEditor` 의 폴백 경로가 이 플러그인에서 가장 조심스러운 코드입니다. 편집기를 못 불러왔을
때 **빈 컨테이너를 남기면 안 되고**(글을 쓸 수 없는데 화면에 설명이 없습니다), 폴백으로 내려간
뒤에도 저장 계약을 지켜야 하며(`{name}_mode = 'text'`), 재시도로 편집기가 뜨면 그때 `_mode` 를
`'html'` 로 되돌리면서 **그 사이에 쓴 내용을 승계**해야 합니다. 이 셋 중 하나라도 빠지면 사용자
입력이 사라집니다.

핸들러 TS 를 고치면 빌드가 필요합니다 — `php artisan plugin:build` 후
`plugin:update --force`. 그리고 프론트엔드 변경은 Playwright 위지윅 spec 을 함께 갱신·실행
합니다. 편집기 장착 회귀는 단위 테스트가 초록인 상태에서도 브라우저에서만 드러납니다.
<!-- @intent END -->

## 전역 진입점

<!-- @generated:frontend-entry START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 항목 | 값 |
|---|---|
| 엔트리 파일 | `resources/js/index.ts` |
| 전역 객체 | `window.__SirsoftCkeditor5` |
| 재등록 진입점 | `initPlugin()` |

로케일 전환 시 코어가 이 진입점을 호출해 핸들러를 다시 등록합니다. 진입점은 핸들러 재등록만 수행하고 1회성 부팅 작업을 포함하지 않습니다.
<!-- @generated:frontend-entry END -->

<!-- @intent START -->
`window.__SirsoftCkeditor5.initPlugin()` 이 재등록 진입점입니다. 로케일을 전환하면 코어가 이
함수를 다시 불러 핸들러를 재등록하는데, 없거나 이름이 다르면 **로케일 전환 직후 편집기가
장착되지 않습니다** — 오류도 토스트도 없이 본문 자리만 비게 됩니다.

진입점은 핸들러 재등록만 수행합니다. 편집기 인스턴스 생성·자산 로드 같은 1회성 작업을 여기
넣으면 로케일을 바꿀 때마다 다시 실행되어 인스턴스가 중복되거나 작성 중인 내용이 날아갑니다.
<!-- @intent END -->

## 에셋

<!-- @generated:assets START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 경로 | 구분 |
|---|---|
| `dist/js/plugin.iife.js` | 빌드 산출물 (커밋 대상) |
| `dist/vendor/ckeditor5/43.3.1` | 동봉 제3자 자산 (자체 제공) |

로딩 설정: `{"strategy":"global","priority":100,"dependencies":[]}`
<!-- @generated:assets END -->

<!-- @intent START -->
두 항목의 성격이 다릅니다.

| 경로 | 성격 |
|---|---|
| `dist/js/plugin.iife.js` | 이 플러그인의 빌드 산출물 — 소스(`resources/js/**`)를 고치면 `--production` 으로 다시 굽고 커밋 |
| `dist/vendor/ckeditor5/43.3.1/` | **동봉한 제3자 자산** — CKEditor 5 본체. CDN 이 아니라 여기서 same-origin 으로 서빙 |

동봉 자산이 이 플러그인 설계의 핵심입니다. CDN 도달 실패는 예외도 서버 로그도 남기지 않고
편집기만 사라지므로(폐쇄망·방화벽·광고차단기), 운영자가 원인을 특정할 수 없습니다. 동봉본은
어떤 잠금파일에도 없어 의존성 감사 도구가 원리상 볼 수 없으므로, **버전 상향은 사람이
확인**합니다.

버전을 올릴 때 기재가 여러 곳에 흩어져 있습니다 — 디렉토리명 · `resources/extensions/
html-editor.json` 의 `scripts.src` · 소스 상수 · 테스트 단언. 하나만 어긋나도 그 자산이
404 가 되는데 빌드와 테스트는 통과하므로, 한 벌로 함께 고칩니다.

배포 산출물이므로 `sourceMappingURL` 참조를 남기지 않습니다(`.map` 은 커밋 대상이 아니라
404 가 됩니다). 자산 URL 은 문자열로 조립하지 않고 `G7Core.asset.plugin` 을 씁니다.
<!-- @intent END -->
