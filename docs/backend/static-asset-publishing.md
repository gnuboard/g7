# 부트스트랩 리소스 정적 게시 (Static Asset Publishing)

초기 부트스트랩 리소스(다국어 병합·컴포넌트 정의·라우트 정보·확장 병합 번들·템플릿 dist 에셋)를 캐시 버전 디렉토리 기반의 실파일로 `public/` 하위에 게시(bake)하고, 웹서버가 rewrite 전에 직접 서빙하는 fast path 를 다룬다. (공개 #122)

## TL;DR (5초 요약)

```text
1. 게시물: public/build/ext/{cache_version}/ — 수명주기 이벤트마다 terminating 훅이 재생성
2. 서빙 게이트 3조건: 프로덕션 + G7_STATIC_CACHE(기본 on) + 게시 완료(manifest 존재)
3. 폴백 2층: 태그 계층은 파일 단위 file_exists + 파샬 역변환, fetch 계층은 fetchStaticFirst
4. 무효화는 버전 디렉토리 — 포인터(cache_version)가 바뀔 뿐 파일 덮어쓰기가 없다
5. .json/.js/.css 로 끝나는 신규 동적(Laravel) 라우트를 만들지 않는다 — 실파일만 정적 확장자
```

## 1. 왜 게시(bake)인가

부트스트랩 리소스는 병합 결과물이다 — lang 은 코어→템플릿→모듈→플러그인→언어팩 훅, routes 는 템플릿+활성 모듈, 번들은 활성 확장 IIFE concat. 병합 구조는 유지하되 그 **결과물**을 실파일로 게시하면, PHP lifecycle 없이 웹서버가 직접 서빙한다 (실측: API 경유 웜 ~131ms vs 정적 파일 17ms).

두 위험은 다음으로 해소된다.

1. **재게시 트리거 누락 → 조용한 stale**: 모든 수명주기 이벤트(확장 설치/활성/비활성/삭제/업데이트, 언어팩 변경, 레이아웃 편집기 저장, 커스텀 번역, CLI 빌드/캐시클리어)는 `ClearsTemplateCaches::incrementExtensionCacheVersion()` 을 경유한다. 게시 예약을 그 **단일 지점 내부**에 심어 누락이 구조적으로 불가능하다. 추가로 blade 렌더가 현재 버전의 미게시를 감지하면 자가 치유(terminating 게시 예약)한다.
2. **stale 파일 참조**: 덮어쓰기가 아닌 **버전 디렉토리** 게시 + 포인터(`cache_version`)는 blade 가 HTML 에 주입한다. 구버전 파일은 잔존해도 참조되지 않는다. content-hash 가 필요 없다.

## 2. 경로 규약

```text
public/build/ext/{cache_version}/
├── manifest.json                              ← 마지막에 기록 (게시 완료 마커)
├── .htaccess                                  ← Apache: public, max-age=31536000, immutable
├── templates/{template_id}/
│   ├── lang/{locale}.json                     ← 병합 결과 raw (lang API 와 동일 페이로드)
│   ├── components.json                        ← components.json 사본 (raw)
│   ├── routes.json                            ← 병합 결과 + {"success":true,...} 봉투
│   └── assets/{dist 이하 경로}                 ← dist/** 사본 (*.map 제외, 허용 확장자만)
└── bundles/
    ├── modules.js / modules.css               ← 확장 병합 번들 사본
    └── plugins.js / plugins.css
```

- 대상: **활성** 템플릿 전수, 로케일은 활성 로케일 열거(언어팩이 추가한 로케일 포함).
- 원자성: `{v}.tmp/` 에 전부 쓴 뒤 디렉토리 rename → `{v}/`, `manifest.json` 은 rename 후 마지막 기록. **manifest 존재 = 게시 완료** — 부분 게시 상태가 참조되지 않는다.
- 쓰기 안전: 식별자(vendor-name)·로케일 패턴 화이트리스트, dist 복사는 허용 확장자 화이트리스트(자산 서빙 검증 규칙과 동일 목록) + `*.map` 제외 + realpath 컨테인먼트.
- `config.json` 과 레이아웃 JSON 은 게시 대상이 **아니다** — 전자는 버전 핸드셰이크의 SSoT(항상 신선해야 함), 후자는 인증 문맥(optional.sanctum) 의존.

## 3. 서빙·폴백 모델

- 실파일이므로 Apache(`RewriteCond !-f`)/nginx(`try_files $uri`) 어느 쪽이든 서버 설정 추가 없이 rewrite 전에 직접 서빙된다. 정적 확장자 정규식 location(`location ~* \.(js|css|json)$`)이 있는 서버에서는 그 location 이 곧 서빙 메커니즘이 된다.
- **`.json/.js/.css` 로 끝나는 신규 동적(Laravel) 라우트를 만들지 않는다.** 정적 확장자 location 이 있는 서버에서 PHP 폴백 없이 404 가 되는 함정을 원천 회피한다 (정적 검사가 차단). 404 는 프론트 폴백의 설계된 신호다.
- **서버 렌더(blade) 층**: `AssetUrl::staticExtBase()` 가 게이트 3조건(프로덕션·`core.static_cache.enabled`·게시 완료)을 판정한다. 태그로 방출되는 자산(템플릿 CSS·JS, 번들 4종)은 **그 자산의 개별 `file_exists`** 까지 확인 후에만 정적 URL 을 방출한다 — 태그는 404 를 받아도 스스로 재시도하지 못하기 때문.
- **프론트 fetch 층**: routes/lang/components 는 정적 URL 우선 + 응답 `!ok`/네트워크 실패 시 **즉시** 종전 API URL 폴백. 폴백 발생은 console.warn 1줄로 관측 가능하다 (조용한 폴백 금지 — 자가 치유 실패를 발견할 유일한 통로).
- **태그 계층 런타임 복구**: 브라우저에 캐시된 구 HTML 이 GC 된 구버전 정적 자산을 참조하는 등 서빙 시점 404 는, 자산 URL 자가 복구 파샬이 `/build/ext/{v}/…` → 종전 `/api/…` URL 로 1회 역변환한다. `/build/core/**` 는 실물 정적 파일이라 변환 대상이 아니다. 확장 병합 번들(`ModuleAssetLoader`)도 동일 규칙 — 정적 번들 URL 은 1회만 시도하고 미스 시 종전 API URL 에서 기존 재시도 예산을 이어간다 (같은 정적 URL 재시도는 게시본 소실을 복구하지 못한다).
- **SEO(봇) 렌더는 정적 URL 을 쓰지 않는다**: 봇 HTML 은 `seo.page.*` 캐시(키에 cache_version 미포함, TTL 수시간)에 박제되는데 게시 디렉토리는 GC 대상이고 SEO HTML 에는 자가 복구 파샬이 없다. `AssetUrl::templateAsset(..., allowStatic: false)` 로 무버전 API URL 을 고정한다 — 생성한 URL 이 정적 게시 GC 보다 오래 사는 저장소에 남는 호출부는 모두 이 원칙을 따른다.
- **비프로덕션(dev)에서는 정적 URL 을 방출하지 않는다** — dev 는 파일 수정 즉시 반영이 우선이며, 게시 자체도 트리거되지 않는다.

## 4. 트리거와 수명주기

| 트리거 | 지점 | 방식 |
|---|---|---|
| 수명주기 전체 | `incrementExtensionCacheVersion()` 내부 | terminating 게시 예약 — 프로세스당 1회, **실행 시점의 최종 버전**으로 게시 (연속 bump 자연 병합) |
| 자가 치유 | blade 렌더의 `staticExtBase()` 게이트 | 현재 버전 미게시 감지 시 terminating 게시 예약. 이번 응답은 API URL (첫 방문자 1회만 종전 속도) |
| 수동/워밍 | `php artisan ext-static:publish [--force]` | 설치기 완료 단계에서도 호출 |
| GC | `php artisan ext-static:cleanup` + 게시 성공 직후 인라인 GC | 현재 + 직전 1개 보존. 스케줄 일 1회 등록 |

- 동시성: 게시는 캐시 락으로 단일 실행. manifest 존재 시 skip(멱등).
- 실패 정책: 쓰기 실패는 로그만 남기고 tmp 정리 — 사이트는 API 폴백으로 정상 ("정적 fast path 미적용" 상태이지 장애가 아니다). 다음 렌더의 자가 치유가 재시도한다.
- routes 병합이 열화 상태(확장 업데이트 진행 중 등)면 그 산출물은 게시하지 않는다 — 정적 파일은 스스로 회복되지 않으므로 열화가 다음 bump 까지 박제된다. 같은 규율이 폴백 API 의 HTTP 캐시 헤더에도 적용된다 — 열화 응답에는 `public, max-age` 를 부여하지 않는다 (브라우저/CDN 박제 방지).

## 5. 운영자 kill-switch

`.env` 에 `G7_STATIC_CACHE=false` 를 두면 게시가 중단되고 blade 가 정적 URL 을 방출하지 않아 전면 API 폴백(종전 동작)으로 돌아간다. 기본값은 활성이며 관리자 UI 는 두지 않는다 (내부 인프라 — 파일시스템 상태를 화면 토글이 즉시 반영한다고 오해할 소지가 있고, 문제 상황의 조치는 서버 접근을 전제한다).

## 6. 권한과 소유권 (설치/코어 업데이트)

게시물은 **런타임이 `public/build/ext` 에 쓰는** 최초의 산출물이다 — 종전의 `public/build/core` 는 빌드 도구가 배포 시점에 만들고 런타임은 읽기만 했다. 따라서 다음이 성립해야 정적 fast path 가 동작한다 (미성립 시에도 사이트는 전면 API 폴백으로 정상 — 경고 로그만 남는다).

- **웹 프로세스 계정의 `public/build` 쓰기 권한**: 게시의 정상 주체는 terminating 훅/자가 치유 = php-fpm(웹 계정)이다. 시스템 요구사항의 그룹 공유(방식 A) 구성이라면 `public/build` 도 같은 원칙(g+w + 공용 그룹)을 적용한다. 게시 코드가 디렉토리를 0775 로 생성하므로 umask 동조 환경에서 그룹 쓰기가 유지된다.
- **설치 시**: 설치기 완료 단계가 `ext-static:publish --force` 를 best-effort 로 실행한다 — 설치기는 웹 요청 컨텍스트에서 돌므로 산출물은 웹 계정 소유가 되어 이후 재게시와 자연 정합한다. 실패해도 설치는 완료되고 첫 방문의 자가 치유가 재시도한다.
- **sudo 코어 업데이트 시**: 업데이트가 root 로 실행되면 종료 시점의 terminating 게시가 root 소유 산출물을 만들 수 있다. 이는 코어 업데이트의 소유권 복원(`app.update.restore_ownership`) **이후**에 일어나므로, 게시 서비스가 직접 방어한다 — root 로 실행된 게시는 완료 직후 부모 디렉토리(`public/build`) 소유권을 산출물에 상속시킨다. `public/build/ext` 는 소유권 복원·그룹 쓰기 정상화 목록에도 포함되어 있다.
- **코어 업데이트의 orphan 정리**: 게시본은 릴리즈 소스에 없는 로컬 파생물이므로 `app.update.excludes` 에 `build/ext` 로 등록되어 있다 — `--prune` 업데이트가 orphan 으로 삭제하지 않고, 백업 대상에서도 제외된다.

권한 문제로 게시가 계속 실패하는 환경(공유 호스팅 등)에서는 `G7_STATIC_CACHE=false` 로 기능을 끄면 경고 로그도 남지 않는다.

## 7. 다중 웹서버 제약

게시물은 **로컬 디스크** 파생물이다 (확장 병합 번들 캐시와 동일한 제약). 다중 웹서버 스케일아웃 구성에서는 서버마다 게시가 필요하다 — terminating 트리거는 요청을 받은 서버에서만 실행되므로, 나머지 서버는 각자의 blade 자가 치유가 첫 렌더에서 보충한다. 공유 스토리지에 `public/build/ext` 를 올리는 구성은 rename 원자성이 보장되는 파일시스템에서만 사용한다.

## 8. nginx 권장 설정 (선택)

버전 디렉토리라 내용이 불변이므로, 서버 기본 재검증(ETag/Last-Modified)만으로도 충분하다. 다만 **압축은 서버 몫이다** — 정적 서빙은 Laravel 의 응답 압축(GzipEncodeResponse)을 우회하므로, 서버에 gzip 설정이 없으면 종전 API 대비 전송량이 회귀한다 (실측: 병합 lang JSON 약 525KB 비압축). 권장:

```nginx
location ^~ /build/ext/ {
    expires max;
    add_header Cache-Control "public, immutable";
    access_log off;
    gzip on;
    gzip_types application/json application/javascript text/css image/svg+xml;
    gzip_min_length 1024;
}
```

Apache 는 게시 트리에 포함된 `.htaccess` 가 같은 캐시 헤더와 `mod_deflate` 압축을 함께 선언한다 (모듈 미탑재 시 자동 무시).

## 9. 관련 규율

- 정적 우선 URL 규칙은 서버측 `AssetUrl` 과 프론트측 `assetUrl.ts`(+ 자가 복구 파샬 역변환)가 **항상 쌍으로** 수정되어야 한다 — 한쪽만 바꾸면 그 자산만 404 가 된다.
- 게시 산출물(`public/build/ext/`)은 git 미추적·release 페이로드 제외다. `public/build/core/` 는 계속 추적한다 (배포 산출물) — 혼동 금지.
- 루트 `npm run build`(기본 vite 앱 빌드)는 `public/build` 를 비운다(`emptyOutDir`) — `core` 와 `ext` 가 함께 지워진다. `core` 는 `core:build --production` 으로 재생성해야 하고(공개 #70 의 실제 원인), `ext` 는 다음 프로덕션 렌더의 자가 치유가 재게시한다.
- 확장 병합 번들의 생성 규율은 [module-assets.md "서버측 번들 병합"](../extension/module-assets.md) 이 소유한다. 본 게시는 그 산출물의 **사본**만 만든다.
