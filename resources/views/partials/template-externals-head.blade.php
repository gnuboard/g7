@php
    $templateExternals = $templateExternals ?? [];
    $resourceHints = \App\Support\TemplateExternals::resourceHints($templateExternals);
    $headLinks = \App\Support\TemplateExternals::headLinks($templateExternals);
    $headScripts = \App\Support\TemplateExternals::scriptsForPosition($templateExternals, 'head');
@endphp
{{-- 자산 실패 신호 부트스트랩.

     아래 externals 는 서버가 HTML 에 직접 심으므로 브라우저가 자산에 도달하지 못해도
     자바스크립트에는 아무 신호가 오지 않는다. 실패는 "아이콘이 안 보인다" 로만 나타나고
     자체 서버 로그에도 흔적이 남지 않는다. 각 태그의 onerror 가 여기로 들어온다.

     엔진 번들보다 먼저 실행되므로 대기열에 쌓아 두고, 엔진이 뜨면 배너로 흘려보낸다. --}}
        <script>
            (function () {
                var queue = window.__g7ExternalAssetFailures = window.__g7ExternalAssetFailures || [];

                window.__g7RetryExternalAsset = function (el, url) {
                    return new Promise(function (resolve, reject) {
                        var fresh = document.createElement(el.tagName);
                        var bust = url + (url.indexOf('?') === -1 ? '?' : '&') + '_g7retry=' + Date.now();

                        if (el.tagName === 'LINK') {
                            fresh.rel = el.rel || 'stylesheet';
                            if (el.media) { fresh.media = el.media; }
                            if (el.crossOrigin) { fresh.crossOrigin = el.crossOrigin; }
                            fresh.href = bust;
                        } else {
                            if (el.crossOrigin) { fresh.crossOrigin = el.crossOrigin; }
                            fresh.src = bust;
                        }

                        fresh.onload = function () {
                            if (el.parentNode) { el.parentNode.removeChild(el); }
                            resolve();
                        };
                        fresh.onerror = function () {
                            if (fresh.parentNode) { fresh.parentNode.removeChild(fresh); }
                            reject(new Error('external asset retry failed'));
                        };

                        (el.parentNode || document.head).appendChild(fresh);
                    });
                };

                window.__g7ExternalAssetFailed = function (el, label) {
                    if (!el) { return; }

                    var url = el.tagName === 'LINK' ? el.href : el.src;
                    var id = 'template-external:' + url;

                    for (var i = 0; i < queue.length; i++) {
                        if (queue[i].id === id) { return; }
                    }

                    var entry = {
                        id: id,
                        label: label || url,
                        url: url,
                        retry: function () { return window.__g7RetryExternalAsset(el, url); }
                    };

                    queue.push(entry);

                    if (typeof window.__g7ExternalAssetSink === 'function') {
                        window.__g7ExternalAssetSink(entry);
                    }
                };
            })();
        </script>
@foreach($resourceHints as $external)
        <link{!! \App\Support\TemplateExternals::renderAttributes(\App\Support\TemplateExternals::linkAttributes($external)) !!}>
@endforeach
@foreach($headLinks as $external)
        <link{!! \App\Support\TemplateExternals::renderAttributes(\App\Support\TemplateExternals::linkAttributes($external)) !!}>
@endforeach
@foreach($headScripts as $external)
        <script{!! \App\Support\TemplateExternals::renderAttributes(\App\Support\TemplateExternals::scriptAttributes($external)) !!}></script>
@endforeach
