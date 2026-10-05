var SirsoftCkeditor5=function(W){"use strict";const E=new Map;let at=!1;function Pt(){return at}function ct(t){at=t}const j=new Map;function lt(t,o){const e=j.get(t)??[];e.push(o),j.set(t,e)}function it(t){(j.get(t)??[]).forEach(o=>{try{o.stop()}catch{}}),j.delete(t)}const At=400,Mt=1e4;function st(t,o){const{name:e,locale:r,multilingual:a}=o;let n=location.pathname,c=null,s=!1,f=null,i=0;const l=()=>{if(o.readExternal)return o.readExternal();const k=window.G7Core?.state?.getLocal?.()?.form;if(!k||!e)return;const g=a?k[e]?.[r]:k[e];return typeof g=="string"?g:void 0},d=window.setInterval(()=>{let u;try{u=t.getData()}catch{window.clearInterval(d);return}location.pathname!==n&&(n=location.pathname,c=null,s=!0,i=Date.now(),f=u);const k=l();if(k!==void 0){if(s){if(k===f){Date.now()-i>Mt&&(s=!1,f=null);return}if(k!==u){ct(!0);try{t.setData(k)}finally{ct(!1)}}s=!1,f=null,c=k;return}if(k===c||k===u){c=k;return}}},At);return{shouldEmit:()=>!s,noteEmitted:u=>{c=u},stop:()=>window.clearInterval(d)}}const Ut="43.3.1",Q="sirsoft-ckeditor5",J=`dist/vendor/ckeditor5/${Ut}`,Y="ckeditor5-vendor-css";function X(){const t=window?.G7Core?.asset;if(!t||typeof t.plugin!="function")throw new Error("G7Core.asset is unavailable — core 7.0.10 or later is required to resolve bundled CKEditor5 assets");return t}function zt(){return X().plugin(Q,`${J}/ckeditor5.umd.js`)}function dt(){return X().plugin(Q,`${J}/ckeditor5.css`)}function Tt(t){return X().plugin(Q,`${J}/translations/${t}.umd.js`)}const Nt="ckeditor5-vendor-umd",Dt=15e3,Ot=2500,Bt=50;let T=null;function F(){return typeof window.CKEDITOR?.ClassicEditor=="function"}function ut(){return typeof window.CKEDITOR_VERSION=="string"}function Z(){return Array.from(document.querySelectorAll("script[src]")).filter(t=>(t.getAttribute("src")??"").toLowerCase().includes("ckeditor5.umd.js"))}function jt(t){return new Promise(o=>{let e=!1;const r=new Set,a=new Set;let n=null;const c=Date.now(),s=l=>{e||(e=!0,clearInterval(i),o(l))},f=l=>{r.has(l)||(r.add(l),l.addEventListener("load",()=>{F()&&s("ready")}),l.addEventListener("error",()=>{a.add(l),n=Date.now()}))},i=setInterval(()=>{if(F()){s("ready");return}Z().forEach(f);const l=Z().some(u=>!a.has(u)),d=Date.now();if(!l&&n!==null&&d-n>=Ot){s("failed");return}d-c>=Dt&&s(l?"timeout":"failed")},Bt);t.forEach(f)})}async function Ft(){const t=window?.G7Core?.asset?.loadScript;if(typeof t!="function")return!1;try{await t(zt(),{id:Nt},{label:"ckeditor5 UMD"})}catch(o){return console.warn("[ckeditor5] 편집기 본체를 불러오지 못했습니다:",o),!1}return F()}function ft(){return F()?Promise.resolve(!0):T||(T=(async()=>{if(ut())return console.warn("[ckeditor5] 편집기 본체가 두 번 실행되어 전역이 비었습니다 — 다시 싣지 않습니다."),!1;const t=Z();if(t.length>0){const o=await jt(t);if(o==="ready")return!0;if(o==="timeout"||ut())return!1}return Ft()})().finally(()=>{T=null}),T)}const q="data-ckeditor5-fallback",K=new WeakMap;function H(t,o,e,r){const a=window.G7Core;if(!a?.state?.setLocal||!t)return;e&&r&&a.state.getLocal?.()?.hasChanges!==!0&&a.state.setLocal({hasChanges:!0});const n={[`form.${t}_mode`]:"text"};if(typeof o=="string")n[`form.${t}`]=o;else for(const[c,s]of Object.entries(o))n[`form.${t}.${c}`]=s;a.state.setLocal(n,{render:!1,selfManaged:!0})}function tt(t){const{container:o,name:e,height:r,readOnly:a,placeholder:n,multilingual:c}=t;if(o.querySelector(`[${q}]`))return;o.innerHTML="";const s=t.trackChanges!==!1,f=c?t.locales??[]:[],i=t.activeLocale??f[0]??"",l=c?{...t.contentMap??{}}:{"":t.initialContent??""},d=document.createElement("textarea");d.setAttribute(q,"1"),d.name=e,d.readOnly=a,d.placeholder=n??"",d.style.cssText=["width:100%",`height:${r}px`,"padding:12px","border:1px solid #d1d5db","border-radius:6px","font-family:inherit","font-size:14px","line-height:1.6","resize:vertical","box-sizing:border-box"].join(";");let u=i;if(c){const k=document.createElement("div");k.className="ckeditor5-locale-tabs";const g=()=>{k.querySelectorAll("button").forEach(m=>{const M=m.dataset.locale??"",p=M===u;m.classList.toggle("is-active",p);const v=(l[M]??"").trim()!=="",D=m.querySelector("[data-check]");D&&(D.style.display=v?"":"none")})};f.forEach((m,M)=>{const p=document.createElement("button");p.type="button",p.dataset.locale=m,M===0&&p.classList.add("is-default"),p.textContent=m.toUpperCase();const v=document.createElement("span");v.dataset.check="1",v.textContent="✓",v.style.display="none",p.appendChild(v),p.addEventListener("click",()=>{l[u]=d.value,u=m,d.value=l[m]??"",d.dataset.locale=m,H(e,l,!1,s),g()}),k.appendChild(p)}),o.appendChild(k),d.dataset.locale=u,d.value=l[u]??"",g(),d.addEventListener("input",()=>{l[u]=d.value,H(e,l,!0,s),g()})}else d.value=l[""]??"",d.addEventListener("input",()=>{l[""]=d.value,H(e,d.value,!0,s)});o.appendChild(d),K.set(o,{multilingual:c,values:l,activeLocale:u}),H(e,c?l:l[""]??"",!1,s)}function qt(t){const o=K.get(t);if(!o)return null;const e=t.querySelector(`textarea[${q}]`);return e&&(o.values[o.multilingual?e.dataset.locale??o.activeLocale:""]=e.value),o.multilingual?{...o.values}:o.values[""]??""}function Ht(t){return t.querySelector(`[${q}]`)?(t.innerHTML="",K.delete(t),!0):!1}const kt="ckeditor5-dark-override",gt="ckeditor5-tab-style",mt={minimal:["bold","italic","underline","|","link","uploadImage","|","undo","redo"],standard:["heading","|","bold","italic","underline","strikethrough","|","alignment","|","link","blockQuote","|","bulletedList","numberedList","indent","outdent","|","insertTable","uploadImage","|","undo","redo"],full:["heading","|","fontSize","fontColor","fontBackgroundColor","|","bold","italic","underline","strikethrough","|","alignment","|","link","blockQuote","insertTable","uploadImage","mediaEmbed","horizontalLine","|","bulletedList","numberedList","indent","outdent","|","codeBlock","sourceEditing","|","undo","redo"]};function Vt(t){if(t==="en")return Promise.resolve();const o=`ckeditor5-translations-${t}`;if(document.getElementById(o))return Promise.resolve();const e=window?.G7Core?.asset?.loadScript;return new Promise(r=>{let a;try{a=Tt(t)}catch(c){console.warn("[ckeditor5] 번역 URL 을 만들지 못했습니다 (영어로 동작):",c),r();return}if(typeof e=="function"){e(a,{id:o},{label:`ckeditor5 translations: ${t}`,retries:1}).catch(c=>{console.warn(`[ckeditor5] 번역 로드 실패 (${t}) — 영어로 동작합니다:`,c)}).then(()=>r());return}const n=document.createElement("script");n.id=o,n.src=a,n.onload=()=>r(),n.onerror=()=>{console.warn(`[ckeditor5] 번역 로드 실패 (${t}) — 영어로 동작합니다.`),r()},document.head.appendChild(n)})}async function bt(){if(document.getElementById(Y))return;const t=window?.G7Core?.asset?.loadStylesheet;let o;try{o=dt()}catch(e){console.warn("[ckeditor5] CSS URL 을 만들지 못했습니다:",e);return}if(typeof t!="function"){const e=document.createElement("link");e.id=Y,e.rel="stylesheet",e.href=o,document.head.appendChild(e);return}try{await t(o,{id:Y},{label:"ckeditor5 CSS"})}catch(e){console.warn("[ckeditor5] CSS 로드 실패:",e),V("ckeditor5-css",$("editor.asset.style_label","편집기 스타일"),()=>bt())}}async function et(t,o,e,r,a){const n=qt(e);if(!await ft())throw new Error("[sirsoft-ckeditor5] 편집기 본체를 여전히 불러올 수 없습니다.");Ht(e);const c={...t,params:{...t.params??{},content:r?n??{}:n??""}};if(await St(c,o),!a)return;window.G7Core?.state?.setLocal?.({[`form.${a}_mode`]:"html"})}function V(t,o,e,r){const a=window?.G7Core?.assets?.notifyFailure;if(typeof a=="function"){a({id:t,label:o,retry:e,message:r});return}console.warn(`[ckeditor5] 자산 로드 실패: ${o}`)}function $(t,o){const e=window?.G7Core?.t;if(typeof e!="function")return o;const r=`sirsoft-ckeditor5.${t}`,a=e(r);return typeof a=="string"&&a!==r?a:o}function Rt(){if(document.getElementById(gt))return;const t=document.createElement("style");t.id=gt,t.textContent=`
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
    `,document.head.appendChild(t)}function Wt(){return document.documentElement.classList.contains("dark")}function pt(){const t=document.getElementById(kt);if(!Wt()){t&&t.remove();return}if(t)return;const o=document.createElement("style");o.id=kt,o.textContent=`
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
    `,document.head.appendChild(o)}let A=null;function Qt(){A||(A=new MutationObserver(()=>{pt()}),A.observe(document.documentElement,{attributes:!0,attributeFilter:["class"]}))}function Jt(){A&&(A.disconnect(),A=null)}const Yt={ko:"한국어",en:"English",ja:"日本語",zh:"中文",fr:"Français",de:"Deutsch",es:"Español"};function Xt(t){return Yt[t]??t.toUpperCase()}function ht(){const t=window.G7Core;return t?.locale?.supported?t.locale.supported():[localStorage.getItem("g7_locale")||"ko"]}function I(){return window.G7Core?.locale?.current?.()??localStorage.getItem("g7_locale")??"ko"}function Zt(t,o,e){const r=[t.Essentials,t.Bold,t.Italic,t.Underline,t.Strikethrough,t.Link,t.Paragraph,t.Heading,t.BlockQuote,t.List,t.Alignment,t.Table,t.TableToolbar,t.TableProperties,t.TableCellProperties,t.Indent,t.IndentBlock,t.PasteFromOffice,t.GeneralHtmlSupport,t.Image,t.ImageResize,t.ImageStyle,t.ImageCaption,t.ImageToolbar];return o==="full"&&r.push(t.MediaEmbed,t.HorizontalLine,t.CodeBlock,t.SourceEditing,t.FontSize,t.FontColor,t.FontBackgroundColor),e&&r.push(t.ImageUpload,t.SimpleUploadAdapter),r}function Kt(){return"/api/plugins/sirsoft-ckeditor5/upload"}function te(){const t=localStorage.getItem("auth_token");return t?{Authorization:`Bearer ${t}`}:{}}async function yt(t,o){const e=window.CKEDITOR;if(!e)throw new Error("[sirsoft-ckeditor5] CKEDITOR 전역 객체를 찾을 수 없습니다. CDN 스크립트가 로드되었는지 확인하세요.");const r=mt[o.toolbar]||mt.standard,a=o.imageUpload?r:r.filter(i=>i!=="uploadImage"),n={initialData:o.initialContent,plugins:Zt(e,o.toolbar,o.imageUpload),toolbar:{items:a},table:{contentToolbar:["tableColumn","tableRow","mergeTableCells","|","tableProperties","tableCellProperties"],tableProperties:{defaultProperties:{borderStyle:"solid",borderColor:"#d1d5db",borderWidth:"1px",alignment:"left"}},tableCellProperties:{defaultProperties:{borderStyle:"solid",borderColor:"#d1d5db",borderWidth:"1px",padding:"8px"}}},image:{toolbar:["imageStyle:inline","imageStyle:alignLeft","imageStyle:alignCenter","imageStyle:alignRight","|","toggleImageCaption","|","resizeImage"],resizeOptions:[{name:"resizeImage:original",label:"Original",value:null},{name:"resizeImage:25",label:"25%",value:"25"},{name:"resizeImage:50",label:"50%",value:"50"},{name:"resizeImage:75",label:"75%",value:"75"}],resizeUnit:"%"},indentBlock:{offset:40,unit:"px"},link:{defaultProtocol:"https://",decorators:{openInNewTab:{mode:"automatic",callback:i=>i.startsWith("http://")||i.startsWith("https://"),attributes:{target:"_blank",rel:"noopener noreferrer"}}}},htmlSupport:{allow:[{name:/.*/,attributes:!0,classes:!0,styles:!0}]},placeholder:o.placeholder,language:I()};o.imageUpload&&(n.simpleUpload={uploadUrl:Kt(),headers:te()});const c=await e.ClassicEditor.create(t,n),s=`ckeditor5-height-style-${o.containerId}`,f=document.getElementById(s);if(f)f.textContent=`#${o.containerId} .ck-editor__editable { min-height: ${o.height}px !important; }`;else{const i=document.createElement("style");i.id=s,i.textContent=`#${o.containerId} .ck-editor__editable { min-height: ${o.height}px !important; }`,document.head.appendChild(i)}return o.readOnly&&c.enableReadOnlyMode("read-only"),c}function ee(t,o,e,r,a){const n=document.createElement("div");n.className="ckeditor5-locale-tabs";const c=new Map;o.forEach(i=>{const l=document.createElement("div");l.dataset.locale=i,i!==e&&(l.style.cssText="display:none;");const d=document.createElement("div");l.appendChild(d),c.set(i,d)});const s=()=>{a&&n.querySelectorAll("button").forEach(i=>{const l=i.dataset.locale;if(!l||i.classList.contains("is-active")){const g=i.querySelector(".ck5-check");g&&g.remove();return}const u=!!a(l)?.trim(),k=i.querySelector(".ck5-check");if(u&&!k){const g=document.createElement("i");g.className="fas fa-check ck5-check",i.appendChild(g)}else!u&&k&&k.remove()})},f=o[0];return o.forEach(i=>{const l=i===f,d=i===e,u=document.createElement("button");u.type="button",u.dataset.locale=i,u.className=[d?"is-active":"",l?"is-default":""].filter(Boolean).join(" "),u.innerHTML=`<i class="fas fa-globe ck5-lang-icon"></i><span>${Xt(i)}</span>${l?'<span class="ck5-required">*</span>':""}`,u.addEventListener("click",async()=>{n.querySelectorAll("button").forEach(k=>{const m=k.dataset.locale===i;k.classList.toggle("is-active",m)}),c.forEach((k,g)=>{const m=k.parentElement;m&&(m.style.display=g===i?"block":"none")}),r&&await r(i),s()}),n.appendChild(u)}),t.appendChild(n),c.forEach(i=>{i.parentElement&&t.appendChild(i.parentElement)}),{tabsEl:n,contentEls:c,updateCheckIcons:s}}function wt(t,o,e,r,a=!0,n=null){if(Pt())return;const c=window.G7Core;if(!c?.state?.setLocal)return;if(a&&c.state.getLocal?.()?.hasChanges!==!0&&c.state.setLocal({hasChanges:!0}),n&&n.scope==="global"){const i=r?`${n.path}.${o}`:n.path;window.G7Core?.state?.set?.({[i]:e});return}const s=n?.path??`form.${t}`,f={[`form.${t}_mode`]:"html"};r?f[`${s}.${o}`]=e:f[s]=e,c.state.setLocal(f,{debounce:300,debounceKey:`ckeditor-sync-${t}`,render:!1,selfManaged:!0})}function Ct(t,o){const e=window.G7Core,r=typeof t.content=="string"?t.content:"";let n=r.startsWith("{{")&&r.endsWith("}}")?"":r;if(!n&&o){const s=(e?.state?.getLocal?.()??{})?.form?.[o];typeof s=="string"&&s&&(n=s)}return n}function _t(t,o){const e=window.G7Core;let r={};try{typeof t.content=="string"&&t.content.startsWith("{")&&!t.content.startsWith("{{")?r=JSON.parse(t.content):typeof t.content=="object"&&t.content!==null&&(r=t.content)}catch{r={}}if(Object.keys(r).length===0&&o){const n=(e?.state?.getLocal?.()??{})?.form?.[o];if(n&&typeof n=="object"&&!Array.isArray(n)?r=n:typeof n=="string"&&n&&(r={[I()]:n}),Object.keys(r).length===0){const c=e?.state?.get?.()??{},f=(c?._global?.selectedItem??c?.selectedItem)?.[o];f&&typeof f=="object"&&!Array.isArray(f)&&(r=f)}}return r}async function St(t,o){const e=t.params??{},a=`ckeditor5-${e.name??"content"}`;it(a);const n=E.get(a);if(n&&n.size>0){const b=[];n.forEach(h=>{h&&typeof h.destroy=="function"&&b.push(h.destroy().catch(()=>{}))}),await Promise.allSettled(b),E.delete(a)}const c=document.getElementById(a);if(!c){console.warn(`[sirsoft-ckeditor5] 컨테이너 엘리먼트를 찾을 수 없습니다: #${a}`);return}bt(),Rt(),pt(),Qt(),await Vt(I());const f=window.G7Core?.state?.get()||{},i=f._global?.plugins?.["sirsoft-ckeditor5"]??f.plugins?.["sirsoft-ckeditor5"]??{},l=e.name??"content",d=e.multilingual===!0||e.multilingual==="true",u=e.readOnly===!0||e.readOnly==="true",k=e.imageUpload!==void 0?e.imageUpload===!0||e.imageUpload==="true":i.imageUpload===!0||i.imageUpload==="true",g=e.placeholder??"",m=e.height!==void 0?Number(e.height)||400:Number(i.editorHeight)||400,M=(e.toolbar!==void 0?e.toolbar:i.toolbar)??"standard",p=!(e.trackChanges===!1||e.trackChanges==="false"),v=e.writeScope==="global"?"global":"local",D=typeof e.writePath=="string"&&e.writePath.trim()!==""&&!e.writePath.includes("{{")?e.writePath.trim():null,L=D===null?null:{scope:v,path:D},xt=(b,h)=>{if(!L)return;const y=window.G7Core,S=L.scope==="global"?y?.state?.get?.():y?.state?.getLocal?.(),x=(h?`${L.path}.${b}`:L.path).split(".").reduce((C,R)=>C?.[R],S);return typeof x=="string"?x:void 0};let $t=d?_t(e,l):{};const ne=await ft();if(!c.isConnected)return;if(!ne){const b={container:c,name:l,height:m,readOnly:u,placeholder:g,trackChanges:p,multilingual:d,locales:d?ht():void 0,activeLocale:I(),contentMap:d?_t(e,l):void 0,initialContent:d?void 0:Ct(e,l)};tt(b),V(`ckeditor5-editor:${a}`,$("editor.asset.label","편집기"),()=>et(t,o,c,b.multilingual,l),$("editor.asset.fallback_notice","편집기를 불러오지 못했습니다. 임시 입력창으로 전환했습니다. 작성한 내용은 그대로 저장됩니다."));return}const U=new Map;E.set(a,U);const It={placeholder:g,readOnly:u,imageUpload:k,height:m,toolbar:M,containerId:a};if(d){const b=ht(),h=I(),y={current:new Map},S={current:()=>{}},P=async w=>{if(U.has(w))return;const O=y.current.get(w);if(!O)return;const re=$t[w]??"";try{const z=await yt(O,{...It,initialContent:re});U.set(w,z);let Gt=!0;const rt=st(z,{name:l,locale:w,multilingual:!0,readExternal:L?()=>xt(w,!0):void 0});if(lt(a,rt),z.model.document.on("change:data",()=>{if(Gt||!rt.shouldEmit())return;const B=z.getData();rt.noteEmitted(B),wt(l,w,B,!0,p,L),S.current()}),Gt=!1,l){const B=window.G7Core;(B?.state?.getLocal?.()??{}).form?.[`${l}_mode`]!=="html"&&B.state.setLocal({[`form.${l}_mode`]:"html"})}}catch(z){console.error(`[sirsoft-ckeditor5] 에디터 초기화 오류 (locale: ${w}):`,z)}},x=w=>{const O=U.get(w);return O?O.getData?.()??null:null},{contentEls:C,updateCheckIcons:R}=ee(c,b,h,P,x);if(y.current=C,S.current=R,await P(h),!U.has(h)){E.delete(a),tt({container:c,name:l,height:m,readOnly:u,placeholder:g,multilingual:!0,locales:b,activeLocale:h,contentMap:$t,trackChanges:p}),V(`ckeditor5-editor:${a}`,$("editor.asset.label","편집기"),()=>et(t,o,c,!0,l),$("editor.asset.fallback_notice","편집기를 불러오지 못했습니다. 임시 입력창으로 전환했습니다. 작성한 내용은 그대로 저장됩니다."));return}setTimeout(()=>{b.filter(w=>w!==h).forEach(w=>{P(w)})},2e3)}else{const b=Ct(e,l),h=document.createElement("div");c.appendChild(h);try{const y=await yt(h,{...It,initialContent:b}),S=I();U.set(S,y);let P=!0;const x=st(y,{name:l,locale:S,multilingual:!1,readExternal:L?()=>xt(S,!1):void 0});if(lt(a,x),y.model.document.on("change:data",()=>{if(P||!x.shouldEmit())return;const C=y.getData();x.noteEmitted(C),wt(l,S,C,!1,p,L)}),P=!1,b){const C=y.getData();(!C||C.trim()==="")&&y.setData(b)}if(l){const C=window.G7Core;(C?.state?.getLocal?.()??{}).form?.[`${l}_mode`]!=="html"&&C.state.setLocal({[`form.${l}_mode`]:"html"})}}catch(y){console.error("[sirsoft-ckeditor5] 에디터 초기화 오류:",y),E.delete(a),tt({container:c,name:l,height:m,readOnly:u,placeholder:g,multilingual:!1,activeLocale:I(),initialContent:b,trackChanges:p}),V(`ckeditor5-editor:${a}`,$("editor.asset.label","편집기"),()=>et(t,o,c,!1,l),$("editor.asset.fallback_notice","편집기를 불러오지 못했습니다. 임시 입력창으로 전환했습니다. 작성한 내용은 그대로 저장됩니다."))}}}async function oe(t,o){const r=`ckeditor5-${t.params?.name??"content"}`;it(r);const a=E.get(r);if(!a||a.size===0)return;const n=[];a.forEach(s=>{s&&typeof s.destroy=="function"&&n.push(s.destroy().catch(f=>{console.warn("[sirsoft-ckeditor5] destroyEditor error:",f)}))}),await Promise.allSettled(n),E.delete(r);const c=document.getElementById(`ckeditor5-height-style-${r}`);c&&c.remove(),E.size===0&&Jt()}const _="sirsoft-ckeditor5",ot="ckeditor5-content-styles",Et="ckeditor5-content-styles-override";function vt(){if(!document.getElementById(ot)){const t=window?.G7Core?.asset;try{const o=dt();if(typeof t?.loadStylesheet=="function")t.loadStylesheet(o,{id:ot},{label:"ckeditor5 content CSS"}).catch(e=>{console.warn("[ckeditor5] 본문 스타일 로드 실패:",e),window?.G7Core?.assets?.notifyFailure?.({id:"ckeditor5-content-css",label:"본문 스타일",retry:()=>vt()})});else{const e=document.createElement("link");e.id=ot,e.rel="stylesheet",e.href=o,document.head.appendChild(e)}}catch(o){console.warn("[ckeditor5] 본문 스타일 URL 을 만들지 못했습니다:",o)}}if(!document.getElementById(Et)){const t=document.createElement("style");t.id=Et,t.textContent=`
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
        `,document.head.appendChild(t)}}const G={initEditor:St,destroyEditor:oe,injectContentCss:vt},N=window.G7Core?.createLogger?.(`Plugin:${_}`)??{log:(...t)=>console.log(`[Plugin:${_}]`,...t),warn:(...t)=>console.warn(`[Plugin:${_}]`,...t),error:(...t)=>console.error(`[Plugin:${_}]`,...t)};function Lt(t=!1){const o=window.G7Core?.getActionDispatcher?.();if(o)Object.entries(G).forEach(([e,r])=>{const a=`${_}.${e}`;o.registerHandler(a,r,{category:"plugin",source:_})}),N.log(`${Object.keys(G).length} handler(s) registered:`,Object.keys(G).map(e=>`${_}.${e}`));else if(t){let e=0;const r=50,a=()=>{const n=window.G7Core?.getActionDispatcher?.();n?(Object.entries(G).forEach(([c,s])=>{const f=`${_}.${c}`;n.registerHandler(f,s,{category:"plugin",source:_})}),N.log(`${Object.keys(G).length} handler(s) registered:`,Object.keys(G).map(c=>`${_}.${c}`))):(e++,e<=r?(N.warn(`ActionDispatcher not found, retrying... (${e}/${r})`),setTimeout(a,100)):N.error("Failed to register handlers: ActionDispatcher not available after maximum retries"))};a()}else N.warn("ActionDispatcher not found, handlers not registered")}function nt(){if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",()=>Lt(!0));else{const t=!!window.G7Core?.getActionDispatcher?.();Lt(!t)}}return nt(),typeof window<"u"&&(window.__SirsoftCkeditor5={identifier:_,handlers:Object.keys(G),initPlugin:nt}),W.initPlugin=nt,Object.defineProperty(W,Symbol.toStringTag,{value:"Module"}),W}({});
