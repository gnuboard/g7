# 페이지 — 아키텍처

> 설계 의도와 계층 구조 · 진입점: [AGENTS.md](../AGENTS.md)

## 설계 의도

<!-- @intent START -->
"문서 하나 = 주소 하나" 라는 전제 하나가 이 모듈의 모든 선택을 설명합니다.

- **목록이 없다.** 공개 API 는 `GET /pages/{slug}` 뿐이고 목록 엔드포인트가 없습니다. 여러 글을
  목록으로 다루는 것은 게시판 모듈의 역할이며, 두 모듈이 그 역할을 나눠 갖지 않으면 둘 다
  애매해집니다. 방문자가 페이지를 찾는 통로는 사이트 메뉴와 통합 검색입니다.
- **삭제가 실삭제다.** 초기 스키마의 SoftDeletes 를 마이그레이션 두 개로 걷어냈습니다. slug 가
  주소이므로 지운 페이지가 보이지 않게 남아 있으면 같은 주소를 다시 쓸 수 없습니다. 되돌리기의
  책임은 삭제 플래그가 아니라 **버전 이력**이 집니다.
- **모든 수정이 버전을 남긴다.** 그래서 되돌리기도 덮어쓰기가 아니라 새 버전 생성입니다 —
  되돌린 사실 자체가 이력에 남아야 하기 때문입니다.
- **검색·SEO 를 스스로 만들지 않는다.** 코어 검색 훅에 결과를 얹고 코어 SEO 캐시에 무효화를
  통지할 뿐, 자기 검색 화면이나 자기 캐시를 두지 않습니다.
- **관리자 설정 화면이 없다.** 조정 가능한 값은 첨부 제한 셋뿐이고 개점 후 거의 바뀌지 않아
  설정 파일에 두었습니다. 조정이 잦아지면 그때 화면을 더하는 것이 맞습니다.

**의도적으로 하지 않는 것**: 알림·브로드캐스트·미들웨어·레이아웃 확장·프론트 액션 핸들러.
이 모듈은 다른 확장의 화면이나 요청 흐름에 개입하지 않습니다.
<!-- @intent END -->

## 계층 지도

<!-- @intent START -->
```
Http/Controllers (Admin/ 관리자 CRUD, User/ 공개 조회·첨부 서빙)
        │
        ▼
FormRequest (slug 유일성 · 다국어 제목 · 첨부 용량/형식)
        │
        ▼
Services 3종
        │  ├─ PageService        : CRUD + 발행 + 버전 스냅샷·복원
        │  ├─ PageAttachmentService : 업로드·순서·삭제 (개수 상한 판정)
        │  └─ PageSettingsService  : 설정 파일 해석
        │       before_* → filter_*_data → 실행 → after_*
        ▼
Repositories 3종 (Interface 경유)
        │
        ▼
Models 3종 (Page ─1:N─ PageVersion / PageAttachment)
```

`PageService` 안에 **정렬 이름 → 컬럼 선언**(`SEARCH_SORT_MAP`)이 상수로 있습니다. 코어
`SearchPagePolicy` 가 이 선언을 읽어 커서 페이지네이션 적용 여부를 판정하므로, 정렬 이름을
추가할 때는 이 상수부터 손댑니다 — 여기 없는 정렬(관련도순 등)은 계산값이라 커서 경계로 쓸 수
없어 offset 을 유지합니다.

Listeners 5종은 별도 레인입니다 — 활동 로그·검색 결과 편입·SEO 캐시 무효화·편집기 이미지
출처 제공. Service 는 이 부가효과를 알지 못하며, 그래서 새 부가효과는 리스너 추가만으로
끝납니다.
<!-- @intent END -->

## 디렉토리

<!-- @generated:directory-map START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 경로 | 역할 | 수정 시 필요한 절차 |
|---|---|---|
| `module.json` | manifest (버전 SSoT) | version 변경 시 package.json·package-lock.json·composer.json 동기화 |
| `module.php` | 진입 클래스 (선언형 표면 SSoT) | 표면 변경 시 `ext:docgen` 재실행 + 코어 최소 버전 검토 |
| `src/Http/Controllers/` | 컨트롤러 | API 표면 변경 시 `api:docgen` 재실행 |
| `src/Http/Requests/` | FormRequest (검증 SSoT) | 검증 규칙은 Service 가 아니라 여기에 둔다 |
| `src/Http/Resources/` | API 리소스 | 목록 응답은 화면이 실제로 그리는 것만 싣는다 |
| `src/Services/` | 비즈니스 로직 | Repository 인터페이스 주입 (구체 클래스 금지) |
| `src/Repositories/` | 데이터 접근 | 목록 쿼리는 컬럼 프루닝·정렬 화이트리스트 확인 |
| `src/Models/` | Eloquent 모델 | 스키마 변경 시 마이그레이션 + 업그레이드 스텝 동반 |
| `src/Listeners/` | 훅 리스너 | Repository 경유 (Model·DB 파사드 직접 접근 금지) |
| `src/routes/` | 라우트 | 모든 라우트에 `name()` 필수 |
| `src/lang/` | 백엔드 다국어 | ko·en 동시 반영 + 번들 ja 팩 동기화 |
| `database/migrations/` | 마이그레이션 | 한국어 comment + `down()` 필수, 기설치본은 업그레이드 스텝으로 백필 |
| `database/seeders/` | 시더 | composer autoload 등록 + `extension:update-autoload` |
| `upgrades/` | 업그레이드 스텝 | DB·설정 구조 변경 시 작성 (모듈/플러그인 전용) |
| `resources/layouts/` | 레이아웃 JSON | `php artisan module:update sirsoft-page --force` (빌드 불필요) |
| `resources/routes.json` | 라우트 → 레이아웃 매핑 | `php artisan module:update sirsoft-page --force` |
| `resources/js/` | 프론트 엔트리·핸들러 | `php artisan module:build` → `php artisan module:update sirsoft-page --force` |
| `editor-spec.json` | 레이아웃 편집기 스펙 | `php artisan module:update sirsoft-page --force` |
| `config/` | 확장 config | 설정 기본값은 settings 스키마와 어긋나지 않게 |
| `tests/` | 테스트 | 변경 범위만 필터 실행 |
| `CHANGELOG.md` | 변경 이력 | 버전 상향 시 항목 추가 (미기재 시 버전 상향 불가) |
| `docs/` | 개발자 문서 | 표면 변경 시 `php artisan ext:docgen` 재실행 |
<!-- @generated:directory-map END -->
