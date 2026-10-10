var SirsoftCkeditor5=function(v){"use strict";var ve=Object.defineProperty;var Le=(v,g,A)=>g in v?ve(v,g,{enumerable:!0,configurable:!0,writable:!0,value:A}):v[g]=A;var zt=(v,g,A)=>Le(v,typeof g!="symbol"?g+"":g,A);const g=new Map;let A=!1;function Nt(){return A}function it(t){A=t}const j=new Map;function J(t,o){const e=j.get(t)??[];e.push(o),j.set(t,e)}function st(t){(j.get(t)??[]).forEach(o=>{try{o.stop()}catch{}}),j.delete(t)}const jt=400,Bt=1e4;function dt(t,o){const{name:e,locale:n,multilingual:r}=o;let a=location.pathname,l=null,i=!1,u=null,s=0;const c=()=>{if(o.readExternal)return o.readExternal();const f=window.G7Core?.state?.getLocal?.()?.form;if(!f||!e)return;const m=r?f[e]?.[n]:f[e];return typeof m=="string"?m:void 0},d=window.setInterval(()=>{let k;try{k=t.getData()}catch{window.clearInterval(d);return}location.pathname!==a&&(a=location.pathname,l=null,i=!0,s=Date.now(),u=k);const f=c();if(f!==void 0){if(i){if(f===u){Date.now()-s>Bt&&(i=!1,u=null);return}if(f!==k){it(!0);try{t.setData(f)}finally{it(!1)}}i=!1,u=null,l=f;return}if(f===l||f===k){l=f;return}}},jt);return{shouldEmit:()=>!i,noteEmitted:k=>{l=k},stop:()=>window.clearInterval(d)}}const qt="43.3.1",X="sirsoft-ckeditor5",Z=`dist/vendor/ckeditor5/${qt}`,K="ckeditor5-vendor-css";function tt(){const t=window?.G7Core?.asset;if(!t||typeof t.plugin!="function")throw new Error("G7Core.asset is unavailable — core 7.0.10 or later is required to resolve bundled CKEditor5 assets");return t}function Vt(){return tt().plugin(X,`${Z}/ckeditor5.umd.js`)}function ut(){return tt().plugin(X,`${Z}/ckeditor5.css`)}function Ht(t){return tt().plugin(X,`${Z}/translations/${t}.umd.js`)}const Wt="ckeditor5-vendor-umd",Yt=15e3,Qt=2500,Jt=50;let F=null;function B(){return typeof window.CKEDITOR?.ClassicEditor=="function"}function ft(){return typeof window.CKEDITOR_VERSION=="string"}function et(){return Array.from(document.querySelectorAll("script[src]")).filter(t=>(t.getAttribute("src")??"").toLowerCase().includes("ckeditor5.umd.js"))}function Xt(t){return new Promise(o=>{let e=!1;const n=new Set,r=new Set;let a=null;const l=Date.now(),i=c=>{e||(e=!0,clearInterval(s),o(c))},u=c=>{n.has(c)||(n.add(c),c.addEventListener("load",()=>{B()&&i("ready")}),c.addEventListener("error",()=>{r.add(c),a=Date.now()}))},s=setInterval(()=>{if(B()){i("ready");return}et().forEach(u);const c=et().some(k=>!r.has(k)),d=Date.now();if(!c&&a!==null&&d-a>=Qt){i("failed");return}d-l>=Yt&&i(c?"timeout":"failed")},Jt);t.forEach(u)})}async function Zt(){const t=window?.G7Core?.asset?.loadScript;if(typeof t!="function")return!1;try{await t(Vt(),{id:Wt},{label:"ckeditor5 UMD"})}catch(o){return console.warn("[ckeditor5] 편집기 본체를 불러오지 못했습니다:",o),!1}return B()}function kt(){return B()?Promise.resolve(!0):F||(F=(async()=>{if(ft())return console.warn("[ckeditor5] 편집기 본체가 두 번 실행되어 전역이 비었습니다 — 다시 싣지 않습니다."),!1;const t=et();if(t.length>0){const o=await Xt(t);if(o==="ready")return!0;if(o==="timeout"||ft())return!1}return Zt()})().finally(()=>{F=null}),F)}const pt="ckeditor5-editor:",gt="layout-script:ckeditor5_script",Kt=500,te=3e4;let q=null;function mt(){q!==null&&(clearInterval(q),q=null)}function ee(){const t=window?.G7Core?.assets;if(typeof t?.clearFailure!="function"||typeof t?.getFailures!="function")return;const o=()=>t.getFailures()??[],e=()=>{o().some(r=>r?.id===gt)&&t.clearFailure(gt)};e(),mt();const n=Date.now();q=setInterval(()=>{if(!o().some(a=>String(a?.id??"").startsWith(pt))||Date.now()-n>=te){mt();return}e()},Kt)}function V(t,o,e,n){const r=window?.G7Core?.assets?.notifyFailure;if(typeof r=="function"){r({id:t,label:o,retry:e,message:n}),t.startsWith(pt)&&ee();return}console.warn(`[ckeditor5] 자산 로드 실패: ${o}`)}const bt="data-readonly",ht="read-only";function yt(t,o){const e=t.getAttribute(bt);return e==="true"?!0:e==="false"?!1:o}function oe(t,o){const e=t;!e||typeof e.enableReadOnlyMode!="function"||typeof e.disableReadOnlyMode!="function"||(o?e.enableReadOnlyMode(ht):e.disableReadOnlyMode(ht))}function ne(t,o){t.querySelectorAll("textarea").forEach(e=>{e.readOnly=o})}function re(t,o,e){let n=null;const r=()=>{const l=yt(t,e);if(l!==n){n=l;for(const i of o())oe(i,l);ne(t,l)}};if(r(),typeof MutationObserver>"u")return{stop:()=>{}};const a=new MutationObserver(r);return a.observe(t,{attributes:!0,attributeFilter:[bt]}),{stop:()=>a.disconnect()}}const ae="upload",le=["jpeg","png","gif","webp"];function wt(t,o){const e=t?.response?.data,n=e?.error?.message??e?.message;return typeof n=="string"&&n!==""?n:o}function ce(t){const o="sirsoft-ckeditor5.editor.upload_failed",e=window?.G7Core?.t,n=typeof e=="function"?e(o):null;return(typeof n=="string"&&n!==o?n:"Couldn't upload file: {{name}}.").replace("{{name}}",t)}class ie{constructor(o,e){zt(this,"controller",new AbortController);this.loader=o,this.uploadUrl=e}async upload(){const o=await this.loader.file,e=ce(o?.name??""),n=window.G7Core?.api;if(!o||typeof n?.post!="function")throw e;const r=new FormData;r.append(ae,o);let a;try{a=await n.post(this.uploadUrl,r,{headers:{"Content-Type":"multipart/form-data"},signal:this.controller.signal,onUploadProgress:i=>{typeof i.total=="number"&&(this.loader.uploadTotal=i.total),this.loader.uploaded=i.loaded}})}catch(i){throw wt(i,e)}const l=a?.url??a?.urls?.default;if(typeof l!="string"||l==="")throw wt({response:{data:a}},e);return{default:l}}abort(){this.controller.abort()}}function se(t){return function(e){e.plugins.get("FileRepository").createUploadAdapter=n=>new ie(n,t)}}const H="data-ckeditor5-fallback",ot=new WeakMap;function W(t,o,e,n){const r=window.G7Core;if(!r?.state?.setLocal||!t)return;e&&n&&r.state.getLocal?.()?.hasChanges!==!0&&r.state.setLocal({hasChanges:!0});const a={[`form.${t}_mode`]:"text"};if(typeof o=="string")a[`form.${t}`]=o;else for(const[l,i]of Object.entries(o))a[`form.${t}.${l}`]=i;r.state.setLocal(a,{render:!1,selfManaged:!0})}function nt(t){const{container:o,name:e,height:n,readOnly:r,placeholder:a,multilingual:l}=t;if(o.querySelector(`[${H}]`))return;o.innerHTML="";const i=t.trackChanges!==!1,u=l?t.locales??[]:[],s=t.activeLocale??u[0]??"",c=l?{...t.contentMap??{}}:{"":t.initialContent??""},d=document.createElement("textarea");d.setAttribute(H,"1"),d.name=e,d.readOnly=r,d.placeholder=a??"",d.style.cssText=["width:100%",`height:${n}px`,"padding:12px","border:1px solid #d1d5db","border-radius:6px","font-family:inherit","font-size:14px","line-height:1.6","resize:vertical","box-sizing:border-box"].join(";");let k=s;if(l){const f=document.createElement("div");f.className="ckeditor5-locale-tabs";const m=()=>{f.querySelectorAll("button").forEach(p=>{const L=p.dataset.locale??"",S=L===k;p.classList.toggle("is-active",S);const C=(c[L]??"").trim()!=="",Y=p.querySelector("[data-check]");Y&&(Y.style.display=C?"":"none")})};u.forEach((p,L)=>{const S=document.createElement("button");S.type="button",S.dataset.locale=p,L===0&&S.classList.add("is-default"),S.textContent=p.toUpperCase();const C=document.createElement("span");C.dataset.check="1",C.textContent="✓",C.style.display="none",S.appendChild(C),S.addEventListener("click",()=>{c[k]=d.value,k=p,d.value=c[p]??"",d.dataset.locale=p,W(e,c,!1,i),m()}),f.appendChild(S)}),o.appendChild(f),d.dataset.locale=k,d.value=c[k]??"",m(),d.addEventListener("input",()=>{c[k]=d.value,W(e,c,!0,i),m()})}else d.value=c[""]??"",d.addEventListener("input",()=>{c[""]=d.value,W(e,d.value,!0,i)});o.appendChild(d),ot.set(o,{multilingual:l,values:c,activeLocale:k}),W(e,l?c:c[""]??"",!1,i)}function de(t){const o=ot.get(t);if(!o)return null;const e=t.querySelector(`textarea[${H}]`);return e&&(o.values[o.multilingual?e.dataset.locale??o.activeLocale:""]=e.value),o.multilingual?{...o.values}:o.values[""]??""}function ue(t){return t.querySelector(`[${H}]`)?(t.innerHTML="",ot.delete(t),!0):!1}const Ct="ckeditor5-dark-override",_t="ckeditor5-tab-style",Et={minimal:["bold","italic","underline","|","link","uploadImage","|","undo","redo"],standard:["heading","|","bold","italic","underline","strikethrough","|","alignment","|","link","blockQuote","|","bulletedList","numberedList","indent","outdent","|","insertTable","uploadImage","|","undo","redo"],full:["heading","|","fontSize","fontColor","fontBackgroundColor","|","bold","italic","underline","strikethrough","|","alignment","|","link","blockQuote","insertTable","uploadImage","mediaEmbed","horizontalLine","|","bulletedList","numberedList","indent","outdent","|","codeBlock","sourceEditing","|","undo","redo"]};function fe(t){if(t==="en")return Promise.resolve();const o=`ckeditor5-translations-${t}`;if(document.getElementById(o))return Promise.resolve();const e=window?.G7Core?.asset?.loadScript;return new Promise(n=>{let r;try{r=Ht(t)}catch(l){console.warn("[ckeditor5] 번역 URL 을 만들지 못했습니다 (영어로 동작):",l),n();return}if(typeof e=="function"){e(r,{id:o},{label:`ckeditor5 translations: ${t}`,retries:1}).catch(l=>{console.warn(`[ckeditor5] 번역 로드 실패 (${t}) — 영어로 동작합니다:`,l)}).then(()=>n());return}const a=document.createElement("script");a.id=o,a.src=r,a.onload=()=>n(),a.onerror=()=>{console.warn(`[ckeditor5] 번역 로드 실패 (${t}) — 영어로 동작합니다.`),n()},document.head.appendChild(a)})}async function St(){if(document.getElementById(K))return;const t=window?.G7Core?.asset?.loadStylesheet;let o;try{o=ut()}catch(e){console.warn("[ckeditor5] CSS URL 을 만들지 못했습니다:",e);return}if(typeof t!="function"){const e=document.createElement("link");e.id=K,e.rel="stylesheet",e.href=o,document.head.appendChild(e);return}try{await t(o,{id:K},{label:"ckeditor5 CSS"})}catch(e){console.warn("[ckeditor5] CSS 로드 실패:",e),V("ckeditor5-css",G("editor.asset.style_label","편집기 스타일"),()=>St())}}async function rt(t,o,e,n,r){const a=de(e);if(!await kt())throw new Error("[sirsoft-ckeditor5] 편집기 본체를 여전히 불러올 수 없습니다.");ue(e);const l={...t,params:{...t.params??{},content:n?a??{}:a??""}};if(await Gt(l,o),!r)return;window.G7Core?.state?.setLocal?.({[`form.${r}_mode`]:"html"})}function G(t,o){const e=window?.G7Core?.t;if(typeof e!="function")return o;const n=`sirsoft-ckeditor5.${t}`,r=e(n);return typeof r=="string"&&r!==n?r:o}function ke(){if(document.getElementById(_t))return;const t=document.createElement("style");t.id=_t,t.textContent=`
        /* 다국어 탭 컨테이너 */
        .ckeditor5-locale-tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 12px;
            align-items: center;
        }
        /* 다국어 탭 버튼 - HtmlEditor 언어탭 스타일 */
        .ckeditor5-locale-tabs button {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border: none;
            border-radius: 9999px;
            cursor: pointer;
            font-size: 0.75rem;
            font-weight: 600;
            line-height: 1;
            transition: background 0.15s, color 0.15s, transform 0.15s, box-shadow 0.15s;
        }
        /* 비활성 - 기본언어 (blue) */
        .ckeditor5-locale-tabs button.is-default {
            background: #eff6ff;
            color: #2563eb;
        }
        .ckeditor5-locale-tabs button.is-default:hover {
            background: #dbeafe;
        }
        /* 비활성 - 비기본언어 (gray) */
        .ckeditor5-locale-tabs button:not(.is-default) {
            background: #f3f4f6;
            color: #4b5563;
        }
        .ckeditor5-locale-tabs button:not(.is-default):hover {
            background: #e5e7eb;
        }
        /* 활성 - 기본언어 */
        .ckeditor5-locale-tabs button.is-active.is-default {
            background: #3b82f6;
            color: #ffffff;
            box-shadow: 0 2px 6px rgba(0,0,0,0.15);
            transform: scale(1.05);
        }
        /* 활성 - 비기본언어 */
        .ckeditor5-locale-tabs button.is-active:not(.is-default) {
            background: #6b7280;
            color: #ffffff;
            box-shadow: 0 2px 6px rgba(0,0,0,0.15);
            transform: scale(1.05);
        }
        .ckeditor5-locale-tabs button .ck5-lang-icon {
            font-size: 0.75rem;
            line-height: 1;
        }
        .ckeditor5-locale-tabs button .ck5-required {
            color: #ef4444;
        }
        .ckeditor5-locale-tabs button.is-active .ck5-required {
            color: #fca5a5;
        }
        .ckeditor5-locale-tabs button .ck5-check {
            color: #22c55e;
        }
        /* 다크 모드 - 비활성 기본언어 */
        html.dark .ckeditor5-locale-tabs button.is-default {
            background: rgba(59, 130, 246, 0.15);
            color: #93c5fd;
        }
        html.dark .ckeditor5-locale-tabs button.is-default:hover {
            background: rgba(59, 130, 246, 0.25);
        }
        /* 다크 모드 - 비활성 비기본언어 */
        html.dark .ckeditor5-locale-tabs button:not(.is-default) {
            background: #374151;
            color: #d1d5db;
        }
        html.dark .ckeditor5-locale-tabs button:not(.is-default):hover {
            background: #4b5563;
        }
        /* 다크 모드 - 활성 기본언어 */
        html.dark .ckeditor5-locale-tabs button.is-active.is-default {
            background: #2563eb;
            color: #ffffff;
        }
        /* 다크 모드 - 활성 비기본언어 */
        html.dark .ckeditor5-locale-tabs button.is-active:not(.is-default) {
            background: #4b5563;
            color: #ffffff;
        }
        html.dark .ckeditor5-locale-tabs button .ck5-check {
            color: #4ade80;
        }
        /* 표 너비 인라인 스타일 무력화 - 컨테이너에 맞게 100% */
        .ck-content figure.table { width: 100%; }
        .ck-content figure.table table { width: 100%; }
        /* 표 tr 배경색 초기화 */
        .ck-content table tr { background-color: unset; }
        /* 표 col 절대 px 너비 무력화 - 컨테이너에 맞게 비율로 */
        .ck-content table col { width: auto !important; }
        /* 표 정렬 보정: CKEditor5 기본 CSS의 margin-left:auto를 float으로 덮어씀 */
        .ck-content figure.table[style*="float:left"],
        .ck-content figure.table[style*="float: left"] {
            margin-left: 0 !important;
            margin-right: 1em !important;
        }
        .ck-content figure.table[style*="float:right"],
        .ck-content figure.table[style*="float: right"] {
            margin-left: 1em !important;
            margin-right: 0 !important;
        }
        /* 이미지 캡션 - 라이트 모드 */
        .ck-content .image > figcaption {
            background-color: #f3f4f6;
            color: #374151;
            font-size: 0.875em;
            padding: 4px 8px;
            text-align: center;
        }
        /* 에디터 편집 영역 - 목록 마커 복원 (Tailwind preflight reset 대응) */
        .ck.ck-editor__editable ul,
        .ck-content ul {
            list-style-type: disc;
            padding-left: 2em;
        }
        .ck.ck-editor__editable ol,
        .ck-content ol {
            list-style-type: decimal;
            padding-left: 2em;
        }
        .ck.ck-editor__editable ul ul,
        .ck-content ul ul {
            list-style-type: circle;
            padding-left: 2em;
        }
        .ck.ck-editor__editable ul ul ul,
        .ck-content ul ul ul {
            list-style-type: square;
            padding-left: 2em;
        }
        .ck.ck-editor__editable ol ol,
        .ck-content ol ol {
            list-style-type: lower-alpha;
            padding-left: 2em;
        }
        .ck.ck-editor__editable ol ol ol,
        .ck-content ol ol ol {
            list-style-type: lower-roman;
            padding-left: 2em;
        }
    `,document.head.appendChild(t)}function pe(){return document.documentElement.classList.contains("dark")}function vt(){const t=document.getElementById(Ct);if(!pe()){t&&t.remove();return}if(t)return;const o=document.createElement("style");o.id=Ct,o.textContent=`
        /* CKEditor5 다크 모드 오버라이드 - CSS 변수 전역 적용 */
        :root {
            --ck-color-base-foreground: #1f2937;
            --ck-color-base-background: #1f2937;
            --ck-color-base-border: #374151;
            --ck-color-text: #f3f4f6;
            --ck-color-toolbar-background: #111827;
            --ck-color-toolbar-border: #374151;
            --ck-color-button-default-background: transparent;
            --ck-color-button-default-hover-background: #374151;
            --ck-color-button-default-active-background: #4b5563;
            --ck-color-button-on-background: #374151;
            --ck-color-button-on-hover-background: #4b5563;
            --ck-color-focus-border: #3b82f6;
            --ck-color-input-background: #1f2937;
            --ck-color-input-text: #f3f4f6;
            --ck-color-input-border: #374151;
            --ck-color-panel-background: #1f2937;
            --ck-color-panel-border: #374151;
            --ck-color-list-button-hover-background: #374151;
            --ck-color-list-button-on-background: #3b82f6;
            --ck-color-list-button-on-color: #f3f4f6;
            --ck-color-list-button-on-hover-background: #4b5563;
            --ck-color-labeled-field-label-background: #1f2937;
            --ck-color-link-default: #60a5fa;
            --ck-color-link-selected-background: rgba(96, 165, 250, 0.1);
            --ck-color-table-focused-cell-background: rgba(59, 130, 246, 0.1);
        }
        /* 에디터 본문 */
        .ck.ck-editor__editable_inline {
            background-color: #1f2937 !important;
            color: #f3f4f6 !important;
            border-color: #374151 !important;
        }
        /* 메인 툴바 */
        .ck.ck-toolbar {
            background-color: #111827 !important;
            border-color: #374151 !important;
        }
        /* 모든 버튼 텍스트 */
        .ck.ck-button,
        .ck.ck-button .ck-button__label {
            color: #f3f4f6 !important;
        }
        /* 모든 SVG 아이콘 (툴바, 드롭다운 화살표 포함) */
        .ck.ck-icon,
        .ck .ck-icon,
        .ck.ck-button .ck-icon,
        .ck.ck-dropdown__arrow {
            color: #f3f4f6 !important;
        }
        .ck.ck-button:hover,
        .ck.ck-button.ck-on {
            background-color: #374151 !important;
        }
        /* 툴팁 (호버 시 버튼 설명) */
        .ck.ck-tooltip .ck-tooltip__text {
            background-color: #1f2937 !important;
            color: #f3f4f6 !important;
        }
        /* 드롭다운 패널 / 리스트 / balloon 팝업 공통 */
        .ck.ck-dropdown__panel,
        .ck.ck-list,
        .ck.ck-balloon-panel {
            background-color: #1f2937 !important;
            border-color: #374151 !important;
        }
        /* 리스트 아이템 */
        .ck.ck-list__item .ck-button,
        .ck.ck-list__item .ck-button .ck-button__label {
            color: #f3f4f6 !important;
        }
        .ck.ck-list__item .ck-button:hover {
            background-color: #374151 !important;
        }
        .ck.ck-list__item .ck-button.ck-disabled .ck-button__label {
            color: #6b7280 !important;
        }
        /* 링크 팝업 / form */
        .ck.ck-link-form,
        .ck.ck-link-actions {
            background-color: #1f2937 !important;
            border-color: #374151 !important;
        }
        .ck.ck-input-text {
            background-color: #111827 !important;
            color: #f3f4f6 !important;
            border-color: #374151 !important;
        }
        .ck.ck-labeled-field-view__label {
            background-color: #1f2937 !important;
            color: #9ca3af !important;
        }
        /* 테이블 속성/셀 속성 폼 */
        .ck.ck-table-form,
        .ck.ck-table-cell-properties-form,
        .ck.ck-table-properties-form {
            background-color: #1f2937 !important;
            border-color: #374151 !important;
        }
        .ck.ck-table-form .ck-label,
        .ck.ck-table-cell-properties-form .ck-label,
        .ck.ck-table-properties-form .ck-label {
            color: #d1d5db !important;
        }
        /* 색상 선택 패널 */
        .ck.ck-color-table,
        .ck.ck-color-grid {
            background-color: #1f2937 !important;
        }
        .ck.ck-color-table .ck-color-table__remove-color,
        .ck.ck-color-table .ck-color-table__remove-color .ck-button__label {
            color: #f3f4f6 !important;
        }
        /* 헤딩/폰트 드롭다운 라벨 */
        .ck.ck-heading_paragraph,
        .ck.ck-heading_heading1,
        .ck.ck-heading_heading2,
        .ck.ck-heading_heading3 {
            color: #f3f4f6 !important;
        }
        /* 이미지 캡션 */
        .ck-content .image > figcaption {
            background-color: #374151 !important;
            color: #d1d5db !important;
        }
        /* 이미지 리사이즈 핸들 */
        .ck-content .image.ck-widget_selected .ck-widget__resizer__handle {
            background-color: #3b82f6 !important;
            border-color: #1d4ed8 !important;
        }
        /* 이미지 정렬 툴바 아이콘 */
        .ck.ck-toolbar .ck-button .ck-icon {
            color: #f3f4f6 !important;
        }
        /* 이미지 선택 시 balloon toolbar (floating) */
        .ck.ck-toolbar_floating {
            background-color: #111827 !important;
            border-color: #374151 !important;
        }
        /* 이미지 리사이즈 드롭다운 버튼 라벨 (25%, 50% 등) */
        .ck.ck-dropdown .ck-button__label {
            color: #f3f4f6 !important;
        }
        /* 이미지 리사이즈 드롭다운 내 선택 리스트 */
        .ck.ck-resize-image-form,
        .ck.ck-image-resize-form {
            background-color: #1f2937 !important;
            border-color: #374151 !important;
            color: #f3f4f6 !important;
        }
        .ck.ck-resize-image-form .ck-label,
        .ck.ck-image-resize-form .ck-label {
            color: #d1d5db !important;
        }
        .ck.ck-resize-image-form .ck-input-text,
        .ck.ck-image-resize-form .ck-input-text {
            background-color: #111827 !important;
            color: #f3f4f6 !important;
            border-color: #374151 !important;
        }
        /* 표 안 에디터 내용 (다크 배경) */
        .ck-content table tr {
            background-color: unset;
        }
        .ck-content table td,
        .ck-content table th {
            border-color: #4b5563;
            color: #f3f4f6 !important;
        }
        /* 표 col 절대 px 너비 무력화 */
        .ck-content table col {
            width: auto !important;
        }
        /* 목록 마커 색상 - 다크모드 */
        .ck.ck-editor__editable ul,
        .ck.ck-editor__editable ol {
            color: #f3f4f6;
        }
        .ck.ck-editor__editable li::marker {
            color: #d1d5db;
        }
    `,document.head.appendChild(o)}let U=null;function ge(){U||(U=new MutationObserver(()=>{vt()}),U.observe(document.documentElement,{attributes:!0,attributeFilter:["class"]}))}function me(){U&&(U.disconnect(),U=null)}const be={ko:"한국어",en:"English",ja:"日本語",zh:"中文",fr:"Français",de:"Deutsch",es:"Español"};function he(t){return be[t]??t.toUpperCase()}function Lt(){const t=window.G7Core;return t?.locale?.supported?t.locale.supported():[localStorage.getItem("g7_locale")||"ko"]}function P(){return window.G7Core?.locale?.current?.()??localStorage.getItem("g7_locale")??"ko"}function ye(t,o,e){const n=[t.Essentials,t.Bold,t.Italic,t.Underline,t.Strikethrough,t.Link,t.Paragraph,t.Heading,t.BlockQuote,t.List,t.Alignment,t.Table,t.TableToolbar,t.TableProperties,t.TableCellProperties,t.Indent,t.IndentBlock,t.PasteFromOffice,t.GeneralHtmlSupport,t.Image,t.ImageResize,t.ImageStyle,t.ImageCaption,t.ImageToolbar];return o==="full"&&n.push(t.MediaEmbed,t.HorizontalLine,t.CodeBlock,t.SourceEditing,t.FontSize,t.FontColor,t.FontBackgroundColor),e&&n.push(t.ImageUpload),n}function we(){return"/api/plugins/sirsoft-ckeditor5/upload"}async function xt(t,o){const e=window.CKEDITOR;if(!e)throw new Error("[sirsoft-ckeditor5] CKEDITOR 전역 객체를 찾을 수 없습니다. CDN 스크립트가 로드되었는지 확인하세요.");const n=Et[o.toolbar]||Et.standard,r=o.imageUpload?n:n.filter(s=>s!=="uploadImage"),a={initialData:o.initialContent,plugins:ye(e,o.toolbar,o.imageUpload),toolbar:{items:r},table:{contentToolbar:["tableColumn","tableRow","mergeTableCells","|","tableProperties","tableCellProperties"],tableProperties:{defaultProperties:{borderStyle:"solid",borderColor:"#d1d5db",borderWidth:"1px",alignment:"left"}},tableCellProperties:{defaultProperties:{borderStyle:"solid",borderColor:"#d1d5db",borderWidth:"1px",padding:"8px"}}},image:{toolbar:["imageStyle:inline","imageStyle:alignLeft","imageStyle:alignCenter","imageStyle:alignRight","|","toggleImageCaption","|","resizeImage"],resizeOptions:[{name:"resizeImage:original",label:"Original",value:null},{name:"resizeImage:25",label:"25%",value:"25"},{name:"resizeImage:50",label:"50%",value:"50"},{name:"resizeImage:75",label:"75%",value:"75"}],resizeUnit:"%",upload:{types:[...le]}},indentBlock:{offset:40,unit:"px"},link:{defaultProtocol:"https://",decorators:{openInNewTab:{mode:"automatic",callback:s=>s.startsWith("http://")||s.startsWith("https://"),attributes:{target:"_blank",rel:"noopener noreferrer"}}}},htmlSupport:{allow:[{name:/.*/,attributes:!0,classes:!0,styles:!0}]},placeholder:o.placeholder,language:P()};o.imageUpload&&(a.extraPlugins=[se(we())]);const l=await e.ClassicEditor.create(t,a),i=`ckeditor5-height-style-${o.containerId}`,u=document.getElementById(i);if(u)u.textContent=`#${o.containerId} .ck-editor__editable { min-height: ${o.height}px !important; }`;else{const s=document.createElement("style");s.id=i,s.textContent=`#${o.containerId} .ck-editor__editable { min-height: ${o.height}px !important; }`,document.head.appendChild(s)}return o.readOnly&&l.enableReadOnlyMode("read-only"),l}function Ce(t,o,e,n,r){const a=document.createElement("div");a.className="ckeditor5-locale-tabs";const l=new Map;o.forEach(s=>{const c=document.createElement("div");c.dataset.locale=s,s!==e&&(c.style.cssText="display:none;");const d=document.createElement("div");c.appendChild(d),l.set(s,d)});const i=()=>{r&&a.querySelectorAll("button").forEach(s=>{const c=s.dataset.locale;if(!c||s.classList.contains("is-active")){const m=s.querySelector(".ck5-check");m&&m.remove();return}const k=!!r(c)?.trim(),f=s.querySelector(".ck5-check");if(k&&!f){const m=document.createElement("i");m.className="fas fa-check ck5-check",s.appendChild(m)}else!k&&f&&f.remove()})},u=o[0];return o.forEach(s=>{const c=s===u,d=s===e,k=document.createElement("button");k.type="button",k.dataset.locale=s,k.className=[d?"is-active":"",c?"is-default":""].filter(Boolean).join(" "),k.innerHTML=`<i class="fas fa-globe ck5-lang-icon"></i><span>${he(s)}</span>${c?'<span class="ck5-required">*</span>':""}`,k.addEventListener("click",async()=>{a.querySelectorAll("button").forEach(f=>{const p=f.dataset.locale===s;f.classList.toggle("is-active",p)}),l.forEach((f,m)=>{const p=f.parentElement;p&&(p.style.display=m===s?"block":"none")}),n&&await n(s),i()}),a.appendChild(k)}),t.appendChild(a),l.forEach(s=>{s.parentElement&&t.appendChild(s.parentElement)}),{tabsEl:a,contentEls:l,updateCheckIcons:i}}function It(t,o,e,n,r=!0,a=null){if(Nt())return;const l=window.G7Core;if(!l?.state?.setLocal)return;if(r&&l.state.getLocal?.()?.hasChanges!==!0&&l.state.setLocal({hasChanges:!0}),a&&a.scope==="global"){const s=n?`${a.path}.${o}`:a.path;window.G7Core?.state?.set?.({[s]:e});return}const i=a?.path??`form.${t}`,u={[`form.${t}_mode`]:"html"};n?u[`${i}.${o}`]=e:u[i]=e,l.state.setLocal(u,{debounce:300,debounceKey:`ckeditor-sync-${t}`,render:!1,selfManaged:!0})}function $t(t,o){const e=window.G7Core,n=typeof t.content=="string"?t.content:"";let a=n.startsWith("{{")&&n.endsWith("}}")?"":n;if(!a&&o){const i=(e?.state?.getLocal?.()??{})?.form?.[o];typeof i=="string"&&i&&(a=i)}return a}function At(t,o){const e=window.G7Core;let n={};try{typeof t.content=="string"&&t.content.startsWith("{")&&!t.content.startsWith("{{")?n=JSON.parse(t.content):typeof t.content=="object"&&t.content!==null&&(n=t.content)}catch{n={}}if(Object.keys(n).length===0&&o){const a=(e?.state?.getLocal?.()??{})?.form?.[o];if(a&&typeof a=="object"&&!Array.isArray(a)?n=a:typeof a=="string"&&a&&(n={[P()]:a}),Object.keys(n).length===0){const l=e?.state?.get?.()??{},u=(l?._global?.selectedItem??l?.selectedItem)?.[o];u&&typeof u=="object"&&!Array.isArray(u)&&(n=u)}}return n}async function Gt(t,o){const e=t.params??{},r=`ckeditor5-${e.name??"content"}`;st(r);const a=g.get(r);if(a&&a.size>0){const b=[];a.forEach(h=>{h&&typeof h.destroy=="function"&&b.push(h.destroy().catch(()=>{}))}),await Promise.allSettled(b),g.delete(r)}const l=document.getElementById(r);if(!l){console.warn(`[sirsoft-ckeditor5] 컨테이너 엘리먼트를 찾을 수 없습니다: #${r}`);return}St(),ke(),vt(),ge(),await fe(P());const u=window.G7Core?.state?.get()||{},s=u._global?.plugins?.["sirsoft-ckeditor5"]??u.plugins?.["sirsoft-ckeditor5"]??{},c=e.name??"content",d=e.multilingual===!0||e.multilingual==="true",k=e.readOnly===!0||e.readOnly==="true",f=()=>yt(l,k),m=e.imageUpload!==void 0?e.imageUpload===!0||e.imageUpload==="true":s.imageUpload===!0||s.imageUpload==="true",p=e.placeholder??"",L=e.height!==void 0?Number(e.height)||400:Number(s.editorHeight)||400,S=(e.toolbar!==void 0?e.toolbar:s.toolbar)??"standard",C=!(e.trackChanges===!1||e.trackChanges==="false"),Y=e.writeScope==="global"?"global":"local",Ut=typeof e.writePath=="string"&&e.writePath.trim()!==""&&!e.writePath.includes("{{")?e.writePath.trim():null,I=Ut===null?null:{scope:Y,path:Ut},Dt=(b,h)=>{if(!I)return;const y=window.G7Core,x=I.scope==="global"?y?.state?.get?.():y?.state?.getLocal?.(),$=(h?`${I.path}.${b}`:I.path).split(".").reduce((_,Q)=>_?.[Q],x);return typeof $=="string"?$:void 0};let Tt=d?At(e,c):{};const Ee=await kt();if(!l.isConnected)return;if(J(r,re(l,()=>g.get(r)?.values()??[],k)),!Ee){const b={container:l,name:c,height:L,readOnly:f(),placeholder:p,trackChanges:C,multilingual:d,locales:d?Lt():void 0,activeLocale:P(),contentMap:d?At(e,c):void 0,initialContent:d?void 0:$t(e,c)};nt(b),V(`ckeditor5-editor:${r}`,G("editor.asset.label","편집기"),()=>rt(t,o,l,b.multilingual,c),G("editor.asset.fallback_notice","편집기를 불러오지 못했습니다. 임시 입력창으로 전환했습니다. 작성한 내용은 그대로 저장됩니다."));return}const D=new Map;g.set(r,D);const Ft={placeholder:p,get readOnly(){return f()},imageUpload:m,height:L,toolbar:S,containerId:r};if(d){const b=Lt(),h=P(),y={current:new Map},x={current:()=>{}},O=async w=>{if(D.has(w))return;const z=y.current.get(w);if(!z)return;const Se=Tt[w]??"";try{const T=await xt(z,{...Ft,initialContent:Se});D.set(w,T);let Rt=!0;const ct=dt(T,{name:c,locale:w,multilingual:!0,readExternal:I?()=>Dt(w,!0):void 0});if(J(r,ct),T.model.document.on("change:data",()=>{if(Rt||!ct.shouldEmit())return;const N=T.getData();ct.noteEmitted(N),It(c,w,N,!0,C,I),x.current()}),Rt=!1,c){const N=window.G7Core;(N?.state?.getLocal?.()??{}).form?.[`${c}_mode`]!=="html"&&N.state.setLocal({[`form.${c}_mode`]:"html"})}}catch(T){console.error(`[sirsoft-ckeditor5] 에디터 초기화 오류 (locale: ${w}):`,T)}},$=w=>{const z=D.get(w);return z?z.getData?.()??null:null},{contentEls:_,updateCheckIcons:Q}=Ce(l,b,h,O,$);if(y.current=_,x.current=Q,await O(h),!D.has(h)){g.delete(r),nt({container:l,name:c,height:L,readOnly:f(),placeholder:p,multilingual:!0,locales:b,activeLocale:h,contentMap:Tt,trackChanges:C}),V(`ckeditor5-editor:${r}`,G("editor.asset.label","편집기"),()=>rt(t,o,l,!0,c),G("editor.asset.fallback_notice","편집기를 불러오지 못했습니다. 임시 입력창으로 전환했습니다. 작성한 내용은 그대로 저장됩니다."));return}setTimeout(()=>{b.filter(w=>w!==h).forEach(w=>{O(w)})},2e3)}else{const b=$t(e,c),h=document.createElement("div");l.appendChild(h);try{const y=await xt(h,{...Ft,initialContent:b}),x=P();D.set(x,y);let O=!0;const $=dt(y,{name:c,locale:x,multilingual:!1,readExternal:I?()=>Dt(x,!1):void 0});if(J(r,$),y.model.document.on("change:data",()=>{if(O||!$.shouldEmit())return;const _=y.getData();$.noteEmitted(_),It(c,x,_,!1,C,I)}),O=!1,b){const _=y.getData();(!_||_.trim()==="")&&y.setData(b)}if(c){const _=window.G7Core;(_?.state?.getLocal?.()??{}).form?.[`${c}_mode`]!=="html"&&_.state.setLocal({[`form.${c}_mode`]:"html"})}}catch(y){console.error("[sirsoft-ckeditor5] 에디터 초기화 오류:",y),g.delete(r),nt({container:l,name:c,height:L,readOnly:f(),placeholder:p,multilingual:!1,activeLocale:P(),initialContent:b,trackChanges:C}),V(`ckeditor5-editor:${r}`,G("editor.asset.label","편집기"),()=>rt(t,o,l,!1,c),G("editor.asset.fallback_notice","편집기를 불러오지 못했습니다. 임시 입력창으로 전환했습니다. 작성한 내용은 그대로 저장됩니다."))}}}async function _e(t,o){const n=`ckeditor5-${t.params?.name??"content"}`;st(n);const r=g.get(n);if(!r||r.size===0)return;const a=[];r.forEach(i=>{i&&typeof i.destroy=="function"&&a.push(i.destroy().catch(u=>{console.warn("[sirsoft-ckeditor5] destroyEditor error:",u)}))}),await Promise.allSettled(a),g.delete(n);const l=document.getElementById(`ckeditor5-height-style-${n}`);l&&l.remove(),g.size===0&&me()}const E="sirsoft-ckeditor5",at="ckeditor5-content-styles",Pt="ckeditor5-content-styles-override";function Mt(){if(!document.getElementById(at)){const t=window?.G7Core?.asset;try{const o=ut();if(typeof t?.loadStylesheet=="function")t.loadStylesheet(o,{id:at},{label:"ckeditor5 content CSS"}).catch(e=>{console.warn("[ckeditor5] 본문 스타일 로드 실패:",e),window?.G7Core?.assets?.notifyFailure?.({id:"ckeditor5-content-css",label:"본문 스타일",retry:()=>Mt()})});else{const e=document.createElement("link");e.id=at,e.rel="stylesheet",e.href=o,document.head.appendChild(e)}}catch(o){console.warn("[ckeditor5] 본문 스타일 URL 을 만들지 못했습니다:",o)}}if(!document.getElementById(Pt)){const t=document.createElement("style");t.id=Pt,t.textContent=`
            /* 이미지 캡션 - 다크모드 */
            html.dark .ck-content .image > figcaption { color: hsl(0, 0%, 80%); background-color: hsl(0, 0%, 15%); }

            /* CKEditor5 표 스타일 - 다크모드 */
            html.dark .ck-content figure.table table { border-color: hsl(0, 0%, 35%); }
            html.dark .ck-content figure.table table tr { background-color: unset; }
            html.dark .ck-content figure.table table td, html.dark .ck-content figure.table table th { border-color: hsl(0, 0%, 30%); color: hsl(0, 0%, 90%); background-color: unset; }
            html.dark .ck-content figure.table table th { background-color: hsl(215, 20%, 25%); }

            /* figure.table 전체 너비 - prose와의 충돌 방지 */
            .ck-content figure.table { display: table; width: 100%; margin: 1em 0; }
            .ck-content figure.table table { width: 100%; }
            .ck-content table tr { background-color: unset; }
            .ck-content table col { width: auto !important; }

            /* 표 정렬 보정 */
            .ck-content figure.table[style*="float:left"],
            .ck-content figure.table[style*="float: left"] { margin-left: 0 !important; margin-right: 1em !important; }
            .ck-content figure.table[style*="float:right"],
            .ck-content figure.table[style*="float: right"] { margin-left: 1em !important; margin-right: 0 !important; }

            /* 이미지 인라인/정렬 - Tailwind preflight img:block 대응 */
            .ck-content img { display: inline; margin: 0; max-width: 100%; }
            .ck-content p:has(img) { line-height: 0; }
            .ck-content p:has(img[style*="float"]) { overflow: hidden; }

            /* 이미지 캡션 - 라이트 모드 */
            .ck-content .image > figcaption { background-color: #f3f4f6; color: #374151; font-size: 0.875em; padding: 4px 8px; text-align: center; }

            /* 목록 마커 및 들여쓰기 - Tailwind preflight reset 대응 */
            .ck-content ul { list-style-type: disc; padding-left: 2em; }
            .ck-content ol { list-style-type: decimal; padding-left: 2em; }
            .ck-content ul ul { list-style-type: circle; padding-left: 2em; }
            .ck-content ul ul ul { list-style-type: square; padding-left: 2em; }
            .ck-content ol ol { list-style-type: lower-alpha; padding-left: 2em; }
            .ck-content ol ol ol { list-style-type: lower-roman; padding-left: 2em; }
        `,document.head.appendChild(t)}}const M={initEditor:Gt,destroyEditor:_e,injectContentCss:Mt},R=window.G7Core?.createLogger?.(`Plugin:${E}`)??{log:(...t)=>console.log(`[Plugin:${E}]`,...t),warn:(...t)=>console.warn(`[Plugin:${E}]`,...t),error:(...t)=>console.error(`[Plugin:${E}]`,...t)};function Ot(t=!1){const o=window.G7Core?.getActionDispatcher?.();if(o)Object.entries(M).forEach(([e,n])=>{const r=`${E}.${e}`;o.registerHandler(r,n,{category:"plugin",source:E})}),R.log(`${Object.keys(M).length} handler(s) registered:`,Object.keys(M).map(e=>`${E}.${e}`));else if(t){let e=0;const n=50,r=()=>{const a=window.G7Core?.getActionDispatcher?.();a?(Object.entries(M).forEach(([l,i])=>{const u=`${E}.${l}`;a.registerHandler(u,i,{category:"plugin",source:E})}),R.log(`${Object.keys(M).length} handler(s) registered:`,Object.keys(M).map(l=>`${E}.${l}`))):(e++,e<=n?(R.warn(`ActionDispatcher not found, retrying... (${e}/${n})`),setTimeout(r,100)):R.error("Failed to register handlers: ActionDispatcher not available after maximum retries"))};r()}else R.warn("ActionDispatcher not found, handlers not registered")}function lt(){if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",()=>Ot(!0));else{const t=!!window.G7Core?.getActionDispatcher?.();Ot(!t)}}return lt(),typeof window<"u"&&(window.__SirsoftCkeditor5={identifier:E,handlers:Object.keys(M),initPlugin:lt}),v.initPlugin=lt,Object.defineProperty(v,Symbol.toStringTag,{value:"Module"}),v}({});
