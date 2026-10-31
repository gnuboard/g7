# 개발자 화면 만들기 (custom 예약 자리)

그누보드7 화면(템플릿 엔진) 대신, 또는 그 옆에 개발자가 직접 만든 화면을 두는 방법을 설명한다.
같은 서버의 Blade·Twig·HTML+jQuery 화면과 다른 주소의 외부 화면(Next.js 등)을 모두 다룬다. 7.2.0 부터.

## TL;DR (5초 요약)

```text
1. 개발자 파일은 예약 자리(routes/custom.php · app/Custom · resources/views/custom · public/custom · lang/custom · config/custom.php · database/migrations/custom · custom/)에만 둔다 — 코어 업데이트가 덮지도 지우지도 않는다
2. routes/custom.php 에 등록한 주소만 개발자 화면이 그리고, 나머지 주소는 그누보드7 화면이 그대로 그린다 (주소 단위로 일부만 가져갈 수 있다)
3. 로그인 회원은 ScreenAuth::user(), 데이터는 InternalApiClient 로 코어·확장 API 를 그 회원 자격으로 부른다 — 권한·비밀글 판정은 API 가 그대로 한다
4. 외부 화면(Next.js·다른 서버의 HTML)은 로그인 API → Bearer 토큰, 공개 설정 API(/api/frontend-config), 환경설정 「사용자 화면 URL」
5. 먼저 php artisan custom:example board 로 견본을 만들어 보고, php artisan custom:check 로 상태를 확인한다
```

## 목차

- [1. 예약 자리와 업데이트](#1-예약-자리와-업데이트)
- [2. 라우트·컨트롤러·뷰](#2-라우트컨트롤러뷰)
- [3. 로그인 판정과 내부 API 도구](#3-로그인-판정과-내부-api-도구)
- [4. 개발자 패키지(custom composer)](#4-개발자-패키지custom-composer)
- [5. 화면 주소 키와 주소 필터](#5-화면-주소-키와-주소-필터)
- [6. 외부 화면(Next.js 등)](#6-외부-화면nextjs-등)
- [7. HTML + jQuery](#7-html--jquery)
- [8. 개발자 화면이 잃는 것](#8-개발자-화면이-잃는-것)
- [9. 결제·본인인증·쿠키 동의](#9-결제본인인증쿠키-동의)
- [10. 공개 설정 API](#10-공개-설정-api)
- [11. 점검할 것과 없는 것](#11-점검할-것과-없는-것)
- [12. 운영 주의](#12-운영-주의)

---

## 1. 예약 자리와 업데이트

| 자리 | 쓰임 | 읽히는 방식 |
| --- | --- | --- |
| `routes/custom.php` | 개발자 화면 주소 | `routes/web.php` 맨 앞에서 읽는다(그누보드7 화면보다 먼저) |
| `app/Custom/` | 컨트롤러·서비스·서비스 프로바이더 (`App\Custom\…`) | 코어 Composer 자동 로드(`App\`). `App\Custom\CustomServiceProvider` 가 있으면 마지막에 등록 |
| `resources/views/custom/` | Blade(`custom.board.list`)·HTML(`.html`) 뷰 | 일반 뷰 경로. `.html` 은 이 아래에서만 파일 그대로 내보낸다 |
| `public/custom/` | CSS·JS·이미지 | 웹 서버가 `/custom/...` 로 바로 서빙 |
| `lang/custom/{ko,en,…}/` | 번역 | `__('custom::파일.키')` |
| `config/custom.php` | 개발자 설정 | `config('custom.키')` |
| `database/migrations/custom/` | 개발자 테이블 | `php artisan migrate` 가 함께 돈다 |
| `custom/` | 개발자 전용 Composer(`composer.json`·`vendor`·`vendor-bundle.zip`) | `custom/vendor/autoload.php` 를 코어 다음에 읽는다 |

**업데이트가 지키는 것**

- 위 자리의 파일은 코어 업데이트(`core:update`, 관리자 화면 업데이트)가 덮지도 지우지도 않는다. 코어는 앞으로도 이 자리에 파일을 내놓지 않는다.
- 업데이트 패키지에 이 자리의 파일이 들어 있으면(잘못 만든 패키지) 유지보수 모드로 들어가기 전에 멈춘다.
- 목록은 `config/app.php` 의 `update.reserved_paths`, 환경변수 `G7_UPDATE_RESERVED_PATHS`(쉼표 구분)로 바꿀 수 있다.

**업데이트가 지키지 않는 것**

- 예약 자리 밖에서 고친 코어 파일(`app/Http/...`, `routes/web.php`, 템플릿 `_bundled` 등)은 업데이트로 덮인다. 코어 동작을 바꿀 때는 훅·필터를 `app/Custom` 의 서비스 프로바이더에서 건다.
- 코어 백업(`storage/app/core_backups`)에는 최상위 `custom/` 이 들어가지 않는다. `custom/` 은 개발자 저장소(git 등)로 따로 관리한다.

---

## 2. 라우트·컨트롤러·뷰

```php
// routes/custom.php
use App\Custom\Http\Controllers\BoardController;
use Illuminate\Support\Facades\Route;

Route::get('/board/{slug}', [BoardController::class, 'index'])->where('slug', '[A-Za-z0-9_-]+');
Route::get('/board/{slug}/{id}', [BoardController::class, 'show'])->whereNumber('id');
Route::get('/mypage/summary', [MyController::class, 'summary'])->middleware('custom.auth');
Route::get('/admin/report', [ReportController::class, 'index'])->middleware('custom.admin');
```

- 자동으로 붙는 미들웨어: `web`(세션·CSRF·로케일)과 `custom.screen`(정식 주소 이동·방문 통계·봇 기록). 봇 판정은 그누보드7 화면과 같은 `BotDetector` 이고, 관리자 환경설정의 봇 감지를 끄면 두 화면 모두 봇 표식을 남기지 않는다.
- 등록하지 않는 주소: 첫 구간이 `api`·`plugins`·`modules`·`_boost`·`build`·`storage`·`install`·`dev`(개발 대시보드) 인 주소, 코어 사이트맵(`/sitemap.xml`·`/sitemap-{n}.xml`), 정적 파일 확장자로 끝나는 주소. 경고 로그를 남기고 등록하지 않는다(코어 응답이 그대로 나간다).
- `/board/{slug}` 만 등록하면 `/board/{slug}/write` 같은 나머지 주소는 그누보드7 화면이 그린다. 필요한 주소만 가져간다.
- 등록 상태 확인: `php artisan custom:check`. 라우트 캐시를 쓰는 사이트는 `routes/custom.php` 를 바꾼 뒤 `php artisan route:cache` 를 다시 실행한다.
- 견본: `php artisan custom:example board` 가 게시판 목록·글보기(`/board/{slug}`·`/board/{slug}/{id}`), HTML+jQuery(`/custom-jquery-example`), 관리자(`/admin/custom-example`) 견본 13개 파일을 위 예약 자리에 만들고 목록을 출력한다. 같은 이름의 파일이 하나라도 있으면 아무것도 쓰지 않고 멈춘다. 다 봤으면 출력된 파일을 지우고 `php artisan route:cache` 를 다시 실행한다.
- 컨트롤러가 예외를 던지면 라라벨 기본 동작대로 오류 화면(500)과 로그가 남는다. 의도한 HTTP 예외(`abort(404)`)는 그 코드 그대로 나간다.

**Blade** — 일반 라라벨과 같다. `view('custom.board.list', [...])`.

**HTML 파일** — `resources/views/custom/jquery/index.html` 은 `view('custom.jquery.index')` 로 파일 그대로 내보낸다(Blade 해석 없음). `.html` 은 `custom.` 으로 시작하는 뷰에서만 찾는다.

**Twig** — 코어에 넣지 않는다. custom 전용 Composer 로 설치하고 서비스 프로바이더에서 뷰 엔진으로 등록한다.

```bash
composer require twig/twig -d custom
```

```php
// app/Custom/View/TwigEngine.php
namespace App\Custom\View;

use Illuminate\Contracts\View\Engine;
use Twig\Environment;

class TwigEngine implements Engine
{
    public function __construct(private Environment $twig, private string $root) {}

    public function get($path, array $data = [])
    {
        $relative = ltrim(str_replace('\\', '/', substr($path, strlen($this->root))), '/');

        return $this->twig->render($relative, $data);
    }
}
```

```php
// app/Custom/CustomServiceProvider.php
namespace App\Custom;

use App\Custom\View\TwigEngine;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

class CustomServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $root = resource_path('views/custom');
        $twig = new Environment(new FilesystemLoader($root), [
            'cache' => storage_path('framework/twig'),
            'autoescape' => 'html',
        ]);

        View::addExtension('twig', 'twig', fn () => new TwigEngine($twig, $root));
    }
}
```

이제 `resources/views/custom/shop/list.twig` 를 `view('custom.shop.list', ['csrf' => csrf_token()])` 로 그린다. Twig 에는 `@csrf` 가 없으므로 폼에는 넘겨 준 토큰을 직접 넣는다.

---

## 3. 로그인 판정과 내부 API 도구

같은 서버 화면은 서버 세션으로 로그인 회원을 안다. 로그인·회원가입·토큰 갱신 API 가 세션에 그 로그인의 토큰을 짝지어 두므로, 그누보드7 화면에서 로그인한 회원이 개발자 화면에서도 로그인으로 보인다.

| 도구 | 쓰임 |
| --- | --- |
| `ScreenAuth::user()` | 로그인 회원(`App\Models\User`) 또는 `null`. 짝 토큰이 회수·만료됐으면 `null` |
| `ScreenAuth::check()` | 로그인 여부 |
| `ScreenAuth::logout()` | 서버 세션과 짝 토큰을 함께 끝낸다(그누보드7 화면도 로그아웃) |
| 미들웨어 `custom.auth` | 비회원이면 로그인 화면(`/login?redirect=…`)으로, JSON 요청이면 401 |
| 미들웨어 `custom.admin` | 비회원이면 관리자 로그인으로, 관리자가 아니면 403 |

**내부 API 도구** — `App\Services\Screen\InternalApiClient` 는 같은 프로세스 안에서 `/api/...` 를 로그인 회원 자격으로 부른다(비회원이면 비회원 자격). 권한·비밀글·소유 판정은 API 가 그대로 한다.

```php
public function index(InternalApiClient $api, string $slug)
{
    $res = $api->get("/api/modules/sirsoft-board/boards/{$slug}/posts", ['page' => request('page', 1)]);

    if ($res->status() === 401) {
        return redirect('/login?redirect='.rawurlencode(request()->getRequestUri()));
    }
    if (! $res->ok()) {
        abort($res->status());
    }

    return view('custom.board.list', ['posts' => $res->json('data.data', [])]);
}
```

- 메서드: `get`·`post`·`put`·`delete`·`send`. 응답: `status()`·`ok()`·`json('점.경로')`·`header()`·`toResponse()`.
- 부를 수 없는 것: `/api/` 밖의 주소, 하위 요청 안에서 다시 부르기, 세션을 여는 인증 API(로그인·로그아웃·갱신·가입). 로그아웃은 `ScreenAuth::logout()` 을 쓴다.
- 호출이 끝나면 가드·로케일·`request()`·현재 라우트가 원래대로 돌아온다.
- 비밀글처럼 API 가 200 에 본문을 `null` 로 주는 경우가 있다. 화면이 「권한 없음」으로 바꿔 보여 준다(견본 `BoardController::show()`).
- 폼(POST)은 웹 라우트로 받고 `@csrf` 를 넣는다. API 를 브라우저에서 바로 부를 때는 Bearer 토큰을 쓴다(7절).

---

## 4. 개발자 패키지(custom composer)

코어 `composer.json` 에 패키지를 넣으면 업데이트로 사라진다. 개발자 패키지는 `custom/composer.json` 에 둔다.

```bash
composer init -d custom --name=site/custom --no-interaction
composer require twig/twig -d custom
```

- `custom/vendor/autoload.php` 는 코어 오토로더 다음에 읽힌다. **같은 패키지가 양쪽에 있으면 코어 버전이 쓰인다.**
- 코어 업데이트는 시작 전에 새 코어 `composer.lock` 과 `custom/composer.lock` 을 비교한다. 개발자 패키지의 요구 버전을 새 코어 패키지가 만족하지 못하거나(`constraint`), 같은 패키지가 다른 버전으로 양쪽에 있으면(`duplicate`) 업데이트를 시작하지 않고 표로 알린다. 관리자 업데이트 확인 창에도 같은 표와 해결 순서가 보인다. Composer 실행 없이 정적으로 비교한다.
- 해결: `custom/composer.json` 의 요구 버전을 새 코어 패키지 버전에 맞게 고치고 `composer update -d custom` 뒤 다시 업데이트한다. 코어가 이미 가진 패키지는 `custom` 에서 빼도 된다.
- Composer 가 없는 서버: 개발 PC 에서 `php artisan custom:vendor-bundle` 로 `custom/vendor-bundle.zip` 을 만들어 함께 올린다. 설치기와 코어 업데이트가 `custom/vendor` 가 없거나 묶음과 다르면 풀어 준다. 무결성 확인은 `php artisan custom:vendor-verify`.
- 현황: `php artisan custom:check` (충돌·거부 라우트가 있으면 종료 코드 1).

---

## 5. 화면 주소 키와 주소 필터

서버가 만드는 화면 링크(알림 메일·비밀번호 재설정·결제 복귀·사이트맵 등)는 모두 화면 키를 거친다. 기본 경로는 그누보드7 주소 구조이고, 필터 훅 `core.screen_url.filter_path` 로 바꾼다. 사용자 범위 링크는 「사용자 화면 URL」(비면 사이트 주소)을, 관리자 범위 링크는 사이트 주소를 앞에 붙인다.

| 키 | 기본 경로 | 범위 |
| --- | --- | --- |
| `home` | `/` | user |
| `auth.login` | `/login` | user |
| `auth.reset_password` | `/reset-password` | user |
| `mypage.change_password` | `/mypage/change-password` | user |
| `identity.verify` | `/identity/verify?challenge={challenge}&token={token}` | user (관리자 화면에서 시작한 인증은 admin — 사이트 주소의 확인 화면) |
| `board.index` | `/boards` | user |
| `board.list` | `/board/{slug}` | user |
| `board.post` | `/board/{slug}/{id}` | user |
| `board.write` · `board.edit` | `/board/{slug}/write` · `/board/{slug}/{id}/edit` | user |
| `page.show` | `/page/{slug}` | user |
| `ecommerce.products` · `ecommerce.product` | `{shop}/products` · `{shop}/products/{product_code}` | user |
| `ecommerce.category` | `{shop}/category/{slug}` | user |
| `ecommerce.checkout` | `{shop}/checkout` | user |
| `ecommerce.order.complete` | `{shop}/orders/{order_number}/complete` | user |
| `ecommerce.guest.orders` · `ecommerce.guest.claim.detail` | `{shop}/guest/orders` · `{shop}/guest/claims/{claim_number}` | user |
| `mypage.orders` · `mypage.order.detail` | `/mypage/orders` · `/mypage/orders/{order_number}` | user |
| `mypage.claims` · `mypage.claim.detail` | `/mypage/claims` · `/mypage/claims/{claim_number}` | user |
| `mypage.inquiries` · `mypage.mileage` | `/mypage/inquiries` · `/mypage/mileage` | user |
| `admin.login` · `admin.reset_password` | `/admin/login` · `/admin/reset-password` | admin |
| `admin.user.edit` | `/admin/users/{uuid}/edit` | admin |
| `admin.settings.seo` · `admin.analytics.settings` | `/admin/settings?tab=seo` · `/admin/analytics/settings?tab=retention` | admin |
| `admin.analytics` | 통계 알림이 가리키는 통계 화면 경로(`/admin/analytics/...`, 알림마다 다름) | admin |
| `admin.schedules` | `/admin/schedules?search_field=identifier&search_keyword={name}` (예약 작업 실패 알림) | admin |
| `board.admin.post` · `board.admin.reports` | `/admin/board/{slug}/post/{id}` · `/admin/boards/reports` | admin |
| `ecommerce.admin.order` · `ecommerce.admin.claims` · `ecommerce.admin.claim.detail` · `ecommerce.admin.inquiries` | 이커머스 관리자 화면 | admin |

`{shop}` 은 이커머스 상점 주소 설정(`basic_info.route_path`, 기본 `/shop`)이다.

```php
// app/Custom/CustomServiceProvider.php — 외부 화면이 상품 주소를 /goods/{code} 로 쓰는 경우
use App\Extension\HookManager;
use App\Support\ScreenUrl;

public function boot(): void
{
    HookManager::addFilter(ScreenUrl::FILTER, function (string $path, string $key, array $params, string $scope) {
        return match ($key) {
            'ecommerce.product' => '/goods/'.rawurlencode((string) $params['product_code']),
            'board.post' => "/community/{$params['slug']}/{$params['id']}",
            default => $path,
        };
    });
}
```

필터는 경로(쿼리 포함)나 절대 URL 을 돌려준다. 빈 값이나 문자열이 아닌 값을 돌려주면 기본 경로가 쓰인다.

---

## 6. 외부 화면(Next.js 등)

다른 주소의 프레임워크가 화면을 그리고 그누보드7 은 API 만 쓰는 구성이다.

1. **로그인** — `POST /api/auth/login` (`email`·`password`) → `data.token` 을 저장하고 이후 요청에 `Authorization: Bearer {token}`. 2단계 인증·비밀번호 변경 요구가 켜진 회원은 로그인 응답이 다음 단계(`/api/auth/login/two-factor`·`/api/auth/login/password-change`)를 요구한다. 갱신 `POST /api/user/auth/refresh`, 로그아웃 `POST /api/auth/logout`, 회원 정보 `GET /api/auth/user`.
2. **CORS** — `config/cors.php` 는 기본으로 모든 출처(`*`)의 `api/*` 요청을 받는다. 운영에서는 `allowed_origins` 를 외부 화면 주소로 좁힌다. 서버(SSR)에서만 부르면 CORS 는 상관없다.
3. **「사용자 화면 URL」** — 관리자 → 환경설정 → 일반에 외부 화면 주소(`https://front.example.com`)를 적는다. 알림 메일·비밀번호 재설정·결제 복귀·본인인증 복귀·사이트맵 링크가 그 주소로 만들어진다. 외부 화면 주소 구조가 그누보드7 과 다르면 5절의 필터를 건다.
4. **설정 값** — `GET /api/frontend-config` (10절).
5. **사이트맵** — 그누보드7 의 `/sitemap.xml` 은 「사용자 화면 URL」 기준 주소로 만들어진다. 외부 화면의 `/sitemap.xml` 이 그누보드7 의 것을 그대로 중계(프록시)하면 된다.
6. **SSR 과 방문자 IP** — 외부 화면 서버가 API 를 대신 부르면 그누보드7 은 그 서버 IP 를 방문자로 본다. 외부 화면 서버를 신뢰 프록시로 등록하고(`TRUSTED_PROXIES`, [리버스 프록시 문서](backend/reverse-proxy.md)) `X-Forwarded-For` 에 방문자 IP 를 실어 보낸다. 로그인 시도 제한·방문 통계가 방문자 단위로 돈다.
7. **그누보드7 주소의 사용자 화면** — 「사용자 화면 URL」을 바꿔도 그누보드7 주소의 사용자 화면은 그대로 열린다(자동 이동 없음). 막으려면 웹 서버에서 막는다. `/api`·`/admin`·`/plugins`·`/modules`·`/build`·`/storage` 는 남겨야 한다.

```nginx
# 그누보드7 서버 — 사용자 화면 주소만 외부 화면으로 돌린다 (API·관리자·자산은 그대로)
location ~ ^/(api|admin|plugins|modules|build|storage|install|sitemap\.xml)(/|$) {
    try_files $uri $uri/ /index.php?$query_string;
}
location / {
    return 302 https://front.example.com$request_uri;
}
```

---

## 7. HTML + jQuery

**같은 서버** — HTML 은 `resources/views/custom/…/*.html`, 스크립트·스타일은 `public/custom/`. 라우트는 `Route::get('/my-page', fn () => view('custom.my.index'));`. 데이터는 브라우저의 jQuery 가 API 로 가져온다. `php artisan custom:example board` 가 만드는 `/custom-jquery-example` 이 그대로 견본이다.

```js
$.ajax({ method: 'POST', url: '/api/auth/login', contentType: 'application/json',
         data: JSON.stringify({ email, password }), dataType: 'json' })
  .done(res => localStorage.setItem('auth_token', res.data.token))
  .fail(xhr => $('#error').text(xhr.responseJSON?.message || '로그인에 실패했습니다.'));

$.ajax({ url: '/api/modules/sirsoft-board/boards/free/posts',
         headers: { Authorization: 'Bearer ' + localStorage.getItem('auth_token') }, dataType: 'json' })
  .done(res => render(res.data.data));
```

`localStorage` 의 `auth_token` 은 그누보드7 화면과 같은 저장 키라서 같은 브라우저에서 로그인 상태를 나눈다.

**다른 서버** — 6절과 같다(CORS·「사용자 화면 URL」).

---

## 8. 개발자 화면이 잃는 것

- **동적 레이아웃 주입** — 모듈·플러그인이 그누보드7 화면에 끼워 넣는 영역(확장 영역·레이아웃 확장)은 개발자 화면에 나타나지 않는다. 필요한 데이터는 API 로 가져와 직접 그린다.
- **봇용 SEO 렌더** — 그누보드7 화면은 검색 봇에게 서버 렌더 HTML 을 주지만, 개발자 화면은 개발자 HTML 이 곧 봇이 보는 HTML 이다. 제목·설명·`<link rel="canonical">`·구조화 데이터를 직접 넣는다. 봇 방문 기록은 그대로 남는다.
- **템플릿 번역** — 템플릿 번역 문구를 꺼내 주는 API 는 없다. 각 화면이 자기 번역(`lang/custom`)을 둔다.

---

## 9. 결제·본인인증·쿠키 동의

결제창·본인인증창·쿠키 동의 배너는 그누보드7 화면이 JS 로 처리하던 절차다. 개발자 화면은 아래 API 순서대로 직접 부른다(엔진 없이 쓰는 JS 는 따로 제공하지 않는다). 각 API 의 필드는 해당 확장의 `docs/api/*.md` 에 있다.

### 9-1. 주문과 결제 (PG 4종 공통)

1. **주문 생성** — `POST /api/modules/sirsoft-ecommerce/user/orders` (회원·비회원 같은 주소, 비회원 장바구니는 `X-Cart-Key`). 본인인증이 필요한 상점이면 428(9-2).
2. **응답에서 PG 고르기** — `data.requires_pg_payment` 가 참이면 `data.pg_provider`(`sirsoft-{공급자}` — 예: `sirsoft-tosspayments`·`sirsoft-kginicis`·`sirsoft-nhnkcp`·`sirsoft-nicepayments`)와 `data.pg_payment_data`(주문번호·주문명·금액(최소 화폐 단위 정수)·통화·구매자 정보)를 쓴다. `pg_payment_handler` 는 그누보드7 엔진 핸들러 이름이라 개발자 화면에는 필요 없다. 비회원은 `data.guest_order_token`(30분)을 이후 요청의 `X-Guest-Order-Token` 헤더로 보낸다.
3. **결제창 설정** — `GET /api/modules/sirsoft-ecommerce/payments/client-config/{공급자}` (`pg_provider` 에서 `sirsoft-` 를 뗀 값). 클라이언트 키·`sdk_url`·`callback_urls` 를 준다.
4. **결제창을 띄운다** — 공급자 SDK 로(아래 표). PG 가 브라우저를 플러그인 콜백으로 되돌리면 서버가 승인하고 성공·실패 주소로 302 이동한다.
5. **결제창을 닫거나 실패하면** — `POST /api/plugins/{플러그인}/payment/close-report` 로 알린다(회원 Bearer 또는 `X-Guest-Order-Token`). 주문을 결제 실패로 바꾸는 유일한 경로다.
6. **도착 화면** — 플러그인 설정 `redirect_success_url`(기본 `{shop}/orders/{orderId}/complete`)·`redirect_fail_url`(기본 `{shop}/checkout`, `?error=…&orderId=…`). 상대 경로는 「사용자 화면 URL」(비면 사이트 주소) 기준 절대 URL 이 된다. 외부 화면은 이 두 주소를 자기 화면으로 받는다.

| 공급자 | SDK | 결제 전 서버 호출 | 콜백(PG → 서버) |
| --- | --- | --- | --- |
| 토스페이먼츠 | `https://js.tosspayments.com/v2/standard` | 없음 | `GET /plugins/sirsoft-tosspayments/payment/success`·`/fail` |
| KG 이니시스 | `https://stdpay.inicis.com/stdjs/INIStdPay.js` (테스트 `stgstdpay`) | `POST /api/plugins/sirsoft-pay_kginicis/payment/signature` (모바일 `…/payment/mobile/signature`) | `POST /plugins/sirsoft-pay_kginicis/payment/callback` |
| NHN KCP | `https://pay.kcp.co.kr/plugin/payplus_web.jsp` (테스트 `testpay`) | 모바일만 `POST /api/plugins/sirsoft-pay_nhnkcp/mobile/approval-key`(회원) | `POST /plugins/sirsoft-pay_nhnkcp/payment/callback` |
| 나이스페이먼츠 | `https://web.nicepay.co.kr/v3/webstd/js/nicepay-3.0.js` | `POST /plugins/sirsoft-pay_nicepayments/payment/sign-data` (웹 라우트) | `POST /plugins/sirsoft-pay_nicepayments/payment/callback` |

- NHN KCP 의 `client-config` 가 주는 `callback_urls.close_report` 는 `/plugins/…/payment/close-report` 다. 실제 API 는 `/api/plugins/sirsoft-pay_nhnkcp/payment/close-report` 이므로 경로 앞에 `/api` 를 붙여 부른다(그누보드7 화면의 API 도구는 `/api` 를 스스로 붙인다).
- 나이스페이먼츠 `sign-data` 와 PG 콜백은 `/api` 밖의 웹 라우트라 CORS 대상이 아니다. 외부 화면은 서버(SSR)에서 부르거나 그누보드7 주소로 폼을 보낸다.

### 9-2. 본인인증

1. 보호된 API 가 **428** `identity_verification_required` 로 답하면 응답의 `verification`(`policy_key`·`purpose`·`provider_id`·`render_hint`)을 읽는다.
2. `POST /api/identity/challenges` (`purpose`·`policy_key`·`provider_id`) → `id`·`render_hint`·`redirect_url`·`public_payload`.
3. 공급자별로 진행한다.
   - 메일 코드: `POST /api/identity/challenges/{id}/verify` (`code`). 메일 링크: `POST /api/identity/challenges/{id}/claim`. 상태: `GET /api/identity/challenges/{id}`.
   - 외부 인증창(KG 이니시스·NHN KCP 플러그인): `public_payload` 로 인증창을 띄운다. 인증창 결과는 플러그인 브리지(`/plugins/…/popup-bridge`·`/bridge`)가 받는다. **브리지는 결과를 그누보드7 사이트 출처로 `postMessage` 하므로 다른 출처의 외부 화면은 받지 못한다.** 외부 화면은 범용 콜백을 쓴다 — `POST /api/identity/callback/{providerId}?return={외부 화면 주소}` 가 `{return}?verification_token=…&challenge_id=…` 로 302 이동한다. `return` 은 사이트 주소 또는 「사용자 화면 URL」과 같은 출처만 받는다.
4. 받은 `verification_token` 을 원래 요청의 쿼리나 본문에 실어 다시 보낸다.

### 9-3. 쿠키 동의

- 읽기: `GET /api/consent` (`status` 가 `unknown` 이면 동의 전으로 본다). 첫 화면은 `GET /api/frontend-config` 의 `consent_initial` 로 시작해도 된다.
- 저장: GDPR 플러그인 `POST /api/plugins/sirsoft-gdpr/consent/cookie` (`consents: {cookie_<분류>: bool}`·`source`), 배너 상태 `GET /api/plugins/sirsoft-gdpr/consent/cookie/status`. 코어에는 저장 API 가 없다(GDPR 플러그인이 꺼져 있으면 배너를 띄우지 않는다).

### 9-4. SDK 호출 예시

그누보드7 화면의 결제·본인인증 처리기(결제는 `plugins/_bundled/sirsoft-tosspayments/resources/js/handlers/requestPayment.ts` 처럼 각 결제 플러그인의 처리기, 본인인증은 `plugins/_bundled/sirsoft-verification_kginicis/resources/js/index.ts` 처럼 각 플러그인의 진입 파일)가 하는 일을 엔진 없이 옮긴 최소 예시다. 필드 이름은 처리기와 같다. 결제창 닫힘 보고(9-1 5단계)·간편결제·에스크로·가상계좌 같은 수단별 추가 필드는 생략했다 — 그 값은 처리기 파일에 있다. `pg` 는 주문 생성 응답의 `data.pg_payment_data`, `config` 는 `client-config` 응답의 `data` 다.

```js
// 공통 도구
const getJson = (url, init) => fetch(url, init).then((r) => (r.ok ? r.json() : Promise.reject(r)));
const postJson = (url, body, headers = {}) => getJson(url, { method: 'POST',
  headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...headers }, body: JSON.stringify(body) });
const loadScript = (src) => new Promise((ok, ng) => { const s = document.createElement('script');
  s.src = src; s.onload = ok; s.onerror = ng; document.head.appendChild(s); });
function hiddenForm(fields, attrs) {           // attrs: id·name·method·action·acceptCharset·target
  const f = Object.assign(document.createElement('form'), attrs);
  for (const [name, value] of Object.entries(fields)) {
    f.appendChild(Object.assign(document.createElement('input'), { type: 'hidden', name, value: String(value ?? '') }));
  }
  document.body.appendChild(f);
  return f;
}
const clientConfig = async (provider) => (await getJson(`/api/modules/sirsoft-ecommerce/payments/client-config/${provider}`)).data;
```

**토스페이먼츠** — SDK 하나로 끝난다. 결제 뒤 브라우저가 `successUrl`·`failUrl`(플러그인 콜백)로 이동한다.

```js
async function payToss(pg) {
  const config = await clientConfig('tosspayments');
  if (!window.TossPayments) await loadScript(config.sdk_url);
  const payment = window.TossPayments(config.client_key).payment({ customerKey: pg.customer_key ?? window.TossPayments.ANONYMOUS });
  await payment.requestPayment({
    method: 'CARD', amount: { currency: pg.currency ?? 'KRW', value: pg.amount },
    orderId: pg.order_number, orderName: pg.order_name,
    successUrl: config.callback_urls.success, failUrl: config.callback_urls.fail,
    customerEmail: pg.customer_email, customerName: pg.customer_name, customerMobilePhone: pg.customer_phone,
    card: { flowMode: 'DEFAULT', useCardPoint: false, useAppCardOnly: false },
  });
}
```

**KG 이니시스 (PC)** — 서버 서명을 받아 폼을 만들고 `INIStdPay.pay(폼 id)`. 모바일은 `callback_urls.mobile_signature` 로 `chkfake`·`mobile_payment_url` 을 받아 `P_MID`·`P_OID`·`P_AMT`·`P_GOODS`·`P_NEXT_URL`(=`mobile_callback?orderId=…`)·`P_CHKFAKE` 등을 `mobile_payment_url` 로 제출한다(SDK 없음).

```js
async function payInicis(pg) {
  const config = await clientConfig('kginicis');
  const timestamp = String(Date.now());
  const { data: sig } = await postJson(config.callback_urls.signature, {   // 주문자 이메일·전화가 주문과 다르면 403
    oid: pg.order_number, price: pg.amount, timestamp, buyer_email: pg.customer_email ?? '', buyer_phone: pg.customer_phone ?? '' });
  if (!window.INIStdPay) await loadScript(config.sdk_url);
  const id = 'kginicis_' + Date.now();
  hiddenForm({
    version: '1.0', mid: config.mid, oid: pg.order_number, goodname: pg.order_name, price: pg.amount, currency: 'WON',
    buyername: pg.customer_name, buyeremail: pg.customer_email, buyertel: pg.customer_phone,
    timestamp, signature: sig.signature, verification: sig.verification, mKey: sig.mKey,
    returnUrl: config.callback_urls.callback, closeUrl: config.callback_urls.close,
    gopaymethod: 'Card', acceptmethod: 'centerCd(Y)', payViewType: 'overlay', use_chkfake: 'Y', charset: 'UTF-8',
  }, { id, method: 'POST', acceptCharset: 'euc-kr' });
  window.INIStdPay.pay(id);
}
```

**NHN KCP (모바일)** — 서버가 승인 키와 제출할 필드를 모두 만들어 준다. 회원 Bearer 가 필요하다(비회원 주문의 모바일 결제는 지원하지 않는다). PC 는 투명 iframe 에 `config.sdk_url` 과 `<form name="order_info">`(`site_cd`·`ordr_idxx`·`good_name`·`good_mny`·`buyr_*`·`pay_method`(카드 `100000000000`)·`Ret_URL`)을 쓰고 `KCP_Pay_Execute(form)` 을 부른 뒤, 결과 함수 `m_Completepayment(form)` 에서 `res_cd === '0000'` 이면 그 폼을 `Ret_URL` 로 제출한다.

```js
async function payKcpMobile(pg, bearer) {
  const { data } = await postJson('/api/plugins/sirsoft-pay_nhnkcp/mobile/approval-key', {
    order_number: pg.order_number, amount: pg.amount, good_name: pg.order_name, pay_method: pg.payment_method,
    buyr_name: pg.customer_name, buyr_mail: pg.customer_email, buyr_tel1: pg.customer_phone, currency: pg.currency ?? 'KRW',
  }, { Authorization: `Bearer ${bearer}` });
  hiddenForm(data.fields, { method: 'POST', action: data.pay_url, acceptCharset: 'euc-kr' }).submit();
}
```

**나이스페이먼츠 (PC)** — 서명 응답은 봉투 없이 `{ediDate, signData, mid}` 다. 모바일은 같은 필드를 `CharSet: 'euc-kr'` 로 바꿔 `https://web.nicepay.co.kr/v3/v3Payment.jsp` 로 제출한다(SDK 없음).

```js
async function payNicepay(pg, guestToken) {
  const config = await clientConfig('nicepayments');
  if (typeof window.goPay !== 'function') await loadScript(config.sdk_url);
  const sign = await postJson(config.sign_data_url, {
    amt: pg.amount, moid: pg.order_number, buyer_email: pg.customer_email ?? '', buyer_phone: pg.customer_phone ?? '',
  }, { 'X-Requested-With': 'XMLHttpRequest', ...(guestToken ? { 'X-Guest-Order-Token': guestToken } : {}) });
  const form = hiddenForm({
    PayMethod: 'CARD', GoodsName: pg.order_name, Amt: pg.amount, MID: sign.mid, Moid: pg.order_number,
    BuyerName: pg.customer_name, BuyerEmail: pg.customer_email, BuyerTel: pg.customer_phone,
    ReturnURL: config.callback_url, EdiDate: sign.ediDate, SignData: sign.signData, CharSet: 'utf-8', GoodsCl: '1',
    TransType: config.useEscrow ? '1' : '0',
  }, { id: 'nicepayForm', method: 'post', action: config.callback_url, acceptCharset: 'utf-8' });
  window.nicepaySubmit = () => form.submit();           // SDK 가 인증 끝에 부른다
  window.nicepayClose = () => form.remove();            // 결제창 닫힘
  window.goPay(form);
}
```

**본인인증 (KG 이니시스 팝업)** — 클릭 핸들러 안에서 창을 먼저 연다(팝업 차단 회피). 결과는 브리지가 사이트 출처로 `postMessage` 한다 — 다른 출처의 외부 화면은 9-2 의 범용 콜백을 쓴다.

```js
function openInicisCert(ch, onResult) {                 // ch = POST /api/identity/challenges 응답의 data
  const p = ch.public_payload;
  const popup = window.open('', 'sa_popup', 'width=400,height=640,scrollbars=yes,resizable=yes');
  hiddenForm({ mid: p.mid, reqSvcCd: p.reqSvcCd, mTxId: p.mtxid, authHash: p.authHash, flgFixedUser: p.flgFixedUser,
    successUrl: p.callbackUrl, failUrl: p.callbackUrl, reservedMsg: p.reservedMsg },
  { name: 'saForm', method: 'POST', action: 'https://sa.inicis.com/auth', target: 'sa_popup', acceptCharset: 'UTF-8' }).submit();
  window.addEventListener('message', function onMsg(ev) {
    if (ev.source !== popup || ev.origin !== p.siteOrigin || ev.data?.type !== 'identity_result') return;
    window.removeEventListener('message', onMsg);
    popup.close();
    onResult(ev.data);                                  // verification_token 을 원래 요청에 실어 다시 보낸다
  });
}
```

**본인인증 (NHN KCP)** — `public_payload` 의 `call_url`(https `*.kcp.co.kr` 만) 로 `reg_cert_key`·`kcp_page_submit_yn: 'Y'` 를 제출한다. PC 는 `window.open('', 'kcp_cert_popup', …)` 창을 `target` 으로, 모바일은 `target: '_self'` 로 보내고 돌아온 주소의 `?verification_token=`(실패면 `?identity_error=`)을 읽는다. 팝업을 그냥 닫으면 `POST /api/identity/challenges/{id}/cancel`.

**쿠키 동의 (GDPR)** — SDK 없이 API 만 쓴다.

```js
const consent = (await getJson('/api/consent', { headers: { Accept: 'application/json' } })).data;
if (consent.status === 'unknown') {
  await postJson('/api/plugins/sirsoft-gdpr/consent/cookie', { consents: { cookie_analytics: true, cookie_marketing: false }, source: 'banner' });
}
```

---

## 10. 공개 설정 API

- `GET /api/frontend-config` — 공개. 그누보드7 사용자 화면이 받는 `window.G7Config` 와 같은 값(사이트 이름·로케일·공개 환경설정·모듈·플러그인 공개 설정·앱 상수·실시간 연결·쿠키 동의 초기값·`custom_routes`). Bearer 토큰이 있으면 그 회원 맥락으로 답한다.
- `GET /api/admin/frontend-config` — 관리자. 관리자 화면 `window.G7Config` 와 같은 값.
- 민감 설정은 들어 있지 않다. 응답 필드는 [API 문서](backend/api/frontend-config.md).

---

## 11. 점검할 것과 없는 것

**점검할 것**

- **알림 템플릿의 클릭 주소** — 관리자 → 환경설정 → 알림 정의(모듈은 각 모듈 설정)에서 템플릿마다 `click_url` 을 고칠 수 있다. 템플릿 변수(`{site_url}`·`{action_url}`, 이커머스 `{order_url}`·`{mileage_url}`·`{inquiry_url}`·`{claim_url}`, 게시판 `{post_url}`·`{report_url}`)는 화면 주소 키(5절)로 만들어지므로 「사용자 화면 URL」과 필터를 따른다. `{site_url}` 은 회원(비회원 주문자 포함)에게 가는 알림·메일에서 「사용자 화면 URL」(비우면 사이트 주소), 운영자에게 가는 알림에서 사이트 주소다. 관리자가 템플릿에 주소를 직접 적어 두었다면 그 주소는 바뀌지 않는다 — 외부 화면으로 옮길 때 한 번 훑는다.
- **비회원 통화·배송 국가** — 비회원은 요청 헤더로 고른다. 통화 `X-Currency`(우선순위: 회원 저장값 → 헤더 → 기본 통화), 배송 국가 `X-Shipping-Country`(활성 국가일 때만, 아니면 회원 저장값 → GeoIP → 기본). 회원은 `GET`/`PUT /api/modules/sirsoft-ecommerce/user/currency`·`…/user/shipping-country` 로 저장한다. 고를 수 있는 목록은 공개 설정의 `modules.sirsoft-ecommerce.language_currency`·`shipping`.

**없는 것**

- **페이지 목록 API** — 공개 API 는 한 페이지(`GET /api/modules/sirsoft-page/pages/{slug}`)뿐이다. 목록은 관리자 API 에만 있다.
- **사용자 메뉴 API** — 코어 메뉴 API 는 관리자 사이드바용(`/api/admin/menus`)이다. 사용자 화면 메뉴는 개발자 화면이 직접 둔다.
- **번역 문구 API** — 템플릿 번역을 꺼내 주는 API 는 만들지 않는다. 각 화면이 `lang/custom` 등 자기 번역을 둔다.

---

## 12. 운영 주의

- **엔진 화면에서의 이동** — 그누보드7 화면 안에서 개발자 주소(`routes/custom.php` 의 GET 주소)로 가는 링크·메뉴는 전체 페이지로 이동한다. 뒤로 가기로 개발자 주소에 오면 새로 불러온다. 경로 매개변수 자리는 한 구간으로 맞추고, 라우트에 제약(`where`·`whereNumber` 등)을 걸면 엔진도 그 제약을 따른다 — 예를 들어 `/board/{slug}/{id}` 에 `whereNumber('id')` 를 걸어야 `/board/free/write`(그누보드7 글쓰기)가 엔진 안 이동으로 남는다. 슬래시를 받는 제약은 구간 비교로 표현할 수 없어 제약 없는 한 구간으로 본다.
- **조회수·쓰기 API** — `HEAD` 요청이나 봇·브라우저 미리 가져오기(prefetch)에서 조회수를 올리는 API(글 상세 등)를 부르지 않는다. 화면 컨트롤러는 `GET` 에서만 상세 API 를 부른다.
- **폼과 API** — 같은 서버 화면의 폼은 웹 라우트(CSRF)로 받는다. 브라우저에서 API 를 바로 부를 때는 Bearer 토큰을 쓴다.
- **7.2.0 이전 로그인** — 7.2.0 이전에 로그인한 회원은 개발자 화면에서 비회원으로 보인다. 다시 로그인하면 된다(그누보드7 화면은 영향 없음).
- **세션과 토큰 수명** — 개발자 화면의 로그인은 서버 세션 수명과 토큰 만료 중 짧은 쪽을 따른다. 개발자 화면 요청도 토큰 만료를 연장한다. 로그아웃, 모든 기기 로그아웃, 관리자가 바꾼 비밀번호처럼 토큰이 회수되면 개발자 화면에서도 바로 비회원이 된다.
- **라우트 캐시** — `routes/custom.php` 를 바꾸면 `php artisan route:cache` 를 다시 실행한다.

## 관련 문서

- [인증](backend/authentication.md) — 세션과 토큰의 짝
- [미들웨어](backend/middleware.md) — `custom.auth`·`custom.admin`·`custom.screen`
- [라우팅 「절대 URL」](backend/routing.md#절대-url-은-사이트-주소로-만든다) — `ScreenUrl`(화면 링크)과 `SiteUrl`(콜백)의 구분
- [코어 업데이트](backend/core-update-system.md) — 예약 경로와 사전 검사
- [vendor 묶음](extension/vendor-bundle.md) — Composer 없는 서버의 `custom/vendor-bundle.zip`
- [공개 설정 API](backend/api/frontend-config.md) — `GET /api/frontend-config`·`/api/admin/frontend-config` 응답 필드
- [SEO 시스템](backend/seo-system.md) — 개발자 화면이 받지 않는 봇 렌더, 사이트맵 주소
- [알림 시스템](backend/notification-system.md) — 알림 링크의 화면 주소
- [방문자 통계](backend/analytics.md) — 개발자 화면의 서버 폴백 집계
- [템플릿 기초](extension/template-basics.md) — 템플릿 엔진 화면과의 관계
- [리버스 프록시](backend/reverse-proxy.md) — SSR 서버의 방문자 IP
