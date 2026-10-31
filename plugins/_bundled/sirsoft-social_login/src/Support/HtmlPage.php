<?php

namespace Plugins\Sirsoft\SocialLogin\Support;

use Illuminate\Http\Response;

/**
 * 플러그인 웹 라우트가 내보내는 단독 HTML 페이지 (테스트 결과 화면, 모의 제공자 동의 화면).
 *
 * 템플릿 엔진을 거치지 않는 일회성 화면이라 뷰를 등록하지 않고 여기서 조립한다. 본문은 호출자가 `e()` 로 이스케이프한
 * 조각만 넘긴다. 응답은 캐시하지 않는다(`no-store`) — 일회성 결과가 브라우저·프록시에 남지 않게.
 *
 * @since 1.0.0
 */
final class HtmlPage
{
    /**
     * HTML 응답을 만듭니다.
     *
     * @param  string  $title  제목 (이스케이프 전 문자열)
     * @param  string  $bodyHtml  본문 HTML (이스케이프된 조각)
     * @param  int  $status  HTTP 상태
     * @return Response 응답
     */
    public static function render(string $title, string $bodyHtml, int $status = 200): Response
    {
        $lang = e(str_replace('_', '-', app()->getLocale()));
        $title = e($title);

        $html = <<<HTML
<!doctype html>
<html lang="{$lang}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>{$title}</title>
<style>
:root { color-scheme: light dark; --bg: #f9fafb; --fg: #111827; --card: #ffffff; --muted: #6b7280; --line: #e5e7eb; --ok: #047857; --bad: #b91c1c; --btn: #2563eb; }
@media (prefers-color-scheme: dark) { :root { --bg: #111827; --fg: #f9fafb; --card: #1f2937; --muted: #9ca3af; --line: #374151; --ok: #34d399; --bad: #f87171; --btn: #3b82f6; } }
body { margin: 0; padding: 24px 16px; background: var(--bg); color: var(--fg); font: 15px/1.6 system-ui, -apple-system, sans-serif; }
main { max-width: 560px; margin: 0 auto; background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 24px; }
h1 { font-size: 20px; margin: 0 0 16px; }
dl { display: grid; grid-template-columns: max-content 1fr; gap: 6px 16px; margin: 0; }
dt { color: var(--muted); } dd { margin: 0; overflow-wrap: anywhere; }
.ok { color: var(--ok); font-weight: 600; } .bad { color: var(--bad); font-weight: 600; }
label { display: block; margin: 12px 0 4px; color: var(--muted); }
select, input { width: 100%; box-sizing: border-box; padding: 8px; border: 1px solid var(--line); border-radius: 8px; background: var(--card); color: var(--fg); }
.actions { display: flex; gap: 8px; margin-top: 20px; flex-wrap: wrap; }
button { padding: 10px 16px; border-radius: 8px; border: 1px solid var(--line); background: var(--card); color: var(--fg); cursor: pointer; }
button.primary { background: var(--btn); border-color: var(--btn); color: #fff; }
p.note { color: var(--muted); font-size: 13px; }
</style>
</head>
<body><main><h1>{$title}</h1>{$bodyHtml}</main></body>
</html>
HTML;

        return response($html, $status)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Cache-Control', 'no-store, private')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    /**
     * 정의 목록(dl)을 만듭니다.
     *
     * @param  array<string, string|null>  $rows  라벨 => 값 (이스케이프 전)
     * @return string HTML
     */
    public static function definitionList(array $rows): string
    {
        $html = '<dl>';
        foreach ($rows as $label => $value) {
            $html .= '<dt>'.e($label).'</dt><dd>'.e($value ?? '—').'</dd>';
        }

        return $html.'</dl>';
    }
}
