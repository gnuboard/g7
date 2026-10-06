var SirsoftCkeditor5=function(S){"use strict";var de=Object.defineProperty;var ue=(S,p,I)=>p in S?de(S,p,{enumerable:!0,configurable:!0,writable:!0,value:I}):S[p]=I;var At=(S,p,I)=>ue(S,typeof p!="symbol"?p+"":p,I);const p=new Map;let I=!1;function Ut(){return I}function lt(t){I=t}const q=new Map;function ct(t,o){const e=q.get(t)??[];e.push(o),q.set(t,e)}function it(t){(q.get(t)??[]).forEach(o=>{try{o.stop()}catch{}}),q.delete(t)}const Mt=400,zt=1e4;function st(t,o){const{name:e,locale:n,multilingual:a}=o;let r=location.pathname,l=null,i=!1,f=null,s=0;const c=()=>{if(o.readExternal)return o.readExternal();const k=window.G7Core?.state?.getLocal?.()?.form;if(!k||!e)return;const g=a?k[e]?.[n]:k[e];return typeof g=="string"?g:void 0},d=window.setInterval(()=>{let u;try{u=t.getData()}catch{window.clearInterval(d);return}location.pathname!==r&&(r=location.pathname,l=null,i=!0,s=Date.now(),f=u);const k=c();if(k!==void 0){if(i){if(k===f){Date.now()-s>zt&&(i=!1,f=null);return}if(k!==u){lt(!0);try{t.setData(k)}finally{lt(!1)}}i=!1,f=null,l=k;return}if(k===l||k===u){l=k;return}}},Mt);return{shouldEmit:()=>!i,noteEmitted:u=>{l=u},stop:()=>window.clearInterval(d)}}const Tt="43.3.1",J="sirsoft-ckeditor5",Y=`dist/vendor/ckeditor5/${Tt}`,X="ckeditor5-vendor-css";function Z(){const t=window?.G7Core?.asset;if(!t||typeof t.plugin!="function")throw new Error("G7Core.asset is unavailable — core 7.0.10 or later is required to resolve bundled CKEditor5 assets");return t}function Dt(){return Z().plugin(J,`${Y}/ckeditor5.umd.js`)}function dt(){return Z().plugin(J,`${Y}/ckeditor5.css`)}function Ot(t){return Z().plugin(J,`${Y}/translations/${t}.umd.js`)}const Nt="ckeditor5-vendor-umd",Ft=15e3,Bt=2500,jt=50;let O=null;function V(){return typeof window.CKEDITOR?.ClassicEditor=="function"}function ut(){return typeof window.CKEDITOR_VERSION=="string"}function K(){return Array.from(document.querySelectorAll("script[src]")).filter(t=>(t.getAttribute("src")??"").toLowerCase().includes("ckeditor5.umd.js"))}function qt(t){return new Promise(o=>{let e=!1;const n=new Set,a=new Set;let r=null;const l=Date.now(),i=c=>{e||(e=!0,clearInterval(s),o(c))},f=c=>{n.has(c)||(n.add(c),c.addEventListener("load",()=>{V()&&i("ready")}),c.addEventListener("error",()=>{a.add(c),r=Date.now()}))},s=setInterval(()=>{if(V()){i("ready");return}K().forEach(f);const c=K().some(u=>!a.has(u)),d=Date.now();if(!c&&r!==null&&d-r>=Bt){i("failed");return}d-l>=Ft&&i(c?"timeout":"failed")},jt);t.forEach(f)})}async function Vt(){const t=window?.G7Core?.asset?.loadScript;if(typeof t!="function")return!1;try{await t(Dt(),{id:Nt},{label:"ckeditor5 UMD"})}catch(o){return console.warn("[ckeditor5] 편집기 본체를 불러오지 못했습니다:",o),!1}return V()}function ft(){return V()?Promise.resolve(!0):O||(O=(async()=>{if(ut())return console.warn("[ckeditor5] 편집기 본체가 두 번 실행되어 전역이 비었습니다 — 다시 싣지 않습니다."),!1;const t=K();if(t.length>0){const o=await qt(t);if(o==="ready")return!0;if(o==="timeout"||ut())return!1}return Vt()})().finally(()=>{O=null}),O)}const Ht="upload";function kt(t,o){const e=t?.response?.data,n=e?.error?.message??e?.message;return typeof n=="string"&&n!==""?n:o}function Rt(t){const o="sirsoft-ckeditor5.editor.upload_failed",e=window?.G7Core?.t,n=typeof e=="function"?e(o):null;return(typeof n=="string"&&n!==o?n:"Couldn't upload file: {{name}}.").replace("{{name}}",t)}class Wt{constructor(o,e){At(this,"controller",new AbortController);this.loader=o,this.uploadUrl=e}async upload(){const o=await this.loader.file,e=Rt(o?.name??""),n=window.G7Core?.api;if(!o||typeof n?.post!="function")throw e;const a=new FormData;a.append(Ht,o);let r;try{r=await n.post(this.uploadUrl,a,{headers:{"Content-Type":"multipart/form-data"},signal:this.controller.signal,onUploadProgress:i=>{typeof i.total=="number"&&(this.loader.uploadTotal=i.total),this.loader.uploaded=i.loaded}})}catch(i){throw kt(i,e)}const l=r?.url??r?.urls?.default;if(typeof l!="string"||l==="")throw kt({response:{data:r}},e);return{default:l}}abort(){this.controller.abort()}}function Qt(t){return function(e){e.plugins.get("FileRepository").createUploadAdapter=n=>new Wt(n,t)}}const H="data-ckeditor5-fallback",tt=new WeakMap;function R(t,o,e,n){const a=window.G7Core;if(!a?.state?.setLocal||!t)return;e&&n&&a.state.getLocal?.()?.hasChanges!==!0&&a.state.setLocal({hasChanges:!0});const r={[`form.${t}_mode`]:"text"};if(typeof o=="string")r[`form.${t}`]=o;else for(const[l,i]of Object.entries(o))r[`form.${t}.${l}`]=i;a.state.setLocal(r,{render:!1,selfManaged:!0})}function et(t){const{container:o,name:e,height:n,readOnly:a,placeholder:r,multilingual:l}=t;if(o.querySelector(`[${H}]`))return;o.innerHTML="";const i=t.trackChanges!==!1,f=l?t.locales??[]:[],s=t.activeLocale??f[0]??"",c=l?{...t.contentMap??{}}:{"":t.initialContent??""},d=document.createElement("textarea");d.setAttribute(H,"1"),d.name=e,d.readOnly=a,d.placeholder=r??"",d.style.cssText=["width:100%",`height:${n}px`,"padding:12px","border:1px solid #d1d5db","border-radius:6px","font-family:inherit","font-size:14px","line-height:1.6","resize:vertical","box-sizing:border-box"].join(";");let u=s;if(l){const k=document.createElement("div");k.className="ckeditor5-locale-tabs";const g=()=>{k.querySelectorAll("button").forEach(m=>{const z=m.dataset.locale??"",h=z===u;m.classList.toggle("is-active",h);const L=(c[z]??"").trim()!=="",F=m.querySelector("[data-check]");F&&(F.style.display=L?"":"none")})};f.forEach((m,z)=>{const h=document.createElement("button");h.type="button",h.dataset.locale=m,z===0&&h.classList.add("is-default"),h.textContent=m.toUpperCase();const L=document.createElement("span");L.dataset.check="1",L.textContent="✓",L.style.display="none",h.appendChild(L),h.addEventListener("click",()=>{c[u]=d.value,u=m,d.value=c[m]??"",d.dataset.locale=m,R(e,c,!1,i),g()}),k.appendChild(h)}),o.appendChild(k),d.dataset.locale=u,d.value=c[u]??"",g(),d.addEventListener("input",()=>{c[u]=d.value,R(e,c,!0,i),g()})}else d.value=c[""]??"",d.addEventListener("input",()=>{c[""]=d.value,R(e,d.value,!0,i)});o.appendChild(d),tt.set(o,{multilingual:l,values:c,activeLocale:u}),R(e,l?c:c[""]??"",!1,i)}function Jt(t){const o=tt.get(t);if(!o)return null;const e=t.querySelector(`textarea[${H}]`);return e&&(o.values[o.multilingual?e.dataset.locale??o.activeLocale:""]=e.value),o.multilingual?{...o.values}:o.values[""]??""}function Yt(t){return t.querySelector(`[${H}]`)?(t.innerHTML="",tt.delete(t),!0):!1}const gt="ckeditor5-dark-override",mt="ckeditor5-tab-style",pt={minimal:["bold","italic","underline","|","link","uploadImage","|","undo","redo"],standard:["heading","|","bold","italic","underline","strikethrough","|","alignment","|","link","blockQuote","|","bulletedList","numberedList","indent","outdent","|","insertTable","uploadImage","|","undo","redo"],full:["heading","|","fontSize","fontColor","fontBackgroundColor","|","bold","italic","underline","strikethrough","|","alignment","|","link","blockQuote","insertTable","uploadImage","mediaEmbed","horizontalLine","|","bulletedList","numberedList","indent","outdent","|","codeBlock","sourceEditing","|","undo","redo"]};function Xt(t){if(t==="en")return Promise.resolve();const o=`ckeditor5-translations-${t}`;if(document.getElementById(o))return Promise.resolve();const e=window?.G7Core?.asset?.loadScript;return new Promise(n=>{let a;try{a=Ot(t)}catch(l){console.warn("[ckeditor5] 번역 URL 을 만들지 못했습니다 (영어로 동작):",l),n();return}if(typeof e=="function"){e(a,{id:o},{label:`ckeditor5 translations: ${t}`,retries:1}).catch(l=>{console.warn(`[ckeditor5] 번역 로드 실패 (${t}) — 영어로 동작합니다:`,l)}).then(()=>n());return}const r=document.createElement("script");r.id=o,r.src=a,r.onload=()=>n(),r.onerror=()=>{console.warn(`[ckeditor5] 번역 로드 실패 (${t}) — 영어로 동작합니다.`),n()},document.head.appendChild(r)})}async function bt(){if(document.getElementById(X))return;const t=window?.G7Core?.asset?.loadStylesheet;let o;try{o=dt()}catch(e){console.warn("[ckeditor5] CSS URL 을 만들지 못했습니다:",e);return}if(typeof t!="function"){const e=document.createElement("link");e.id=X,e.rel="stylesheet",e.href=o,document.head.appendChild(e);return}try{await t(o,{id:X},{label:"ckeditor5 CSS"})}catch(e){console.warn("[ckeditor5] CSS 로드 실패:",e),W("ckeditor5-css",G("editor.asset.style_label","편집기 스타일"),()=>bt())}}async function ot(t,o,e,n,a){const r=Jt(e);if(!await ft())throw new Error("[sirsoft-ckeditor5] 편집기 본체를 여전히 불러올 수 없습니다.");Yt(e);const l={...t,params:{...t.params??{},content:n?r??{}:r??""}};if(await St(l,o),!a)return;window.G7Core?.state?.setLocal?.({[`form.${a}_mode`]:"html"})}function W(t,o,e,n){const a=window?.G7Core?.assets?.notifyFailure;if(typeof a=="function"){a({id:t,label:o,retry:e,message:n});return}console.warn(`[ckeditor5] 자산 로드 실패: ${o}`)}function G(t,o){const e=window?.G7Core?.t;if(typeof e!="function")return o;const n=`sirsoft-ckeditor5.${t}`,a=e(n);return typeof a=="string"&&a!==n?a:o}function Zt(){if(document.getElementById(mt))return;const t=document.createElement("style");t.id=mt,t.textContent=`
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
    `,document.head.appendChild(t)}function Kt(){return document.documentElement.classList.contains("dark")}function ht(){const t=document.getElementById(gt);if(!Kt()){t&&t.remove();return}if(t)return;const o=document.createElement("style");o.id=gt,o.textContent=`
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
    `,document.head.appendChild(o)}let M=null;function te(){M||(M=new MutationObserver(()=>{ht()}),M.observe(document.documentElement,{attributes:!0,attributeFilter:["class"]}))}function ee(){M&&(M.disconnect(),M=null)}const oe={ko:"한국어",en:"English",ja:"日本語",zh:"中文",fr:"Français",de:"Deutsch",es:"Español"};function ne(t){return oe[t]??t.toUpperCase()}function yt(){const t=window.G7Core;return t?.locale?.supported?t.locale.supported():[localStorage.getItem("g7_locale")||"ko"]}function P(){return window.G7Core?.locale?.current?.()??localStorage.getItem("g7_locale")??"ko"}function re(t,o,e){const n=[t.Essentials,t.Bold,t.Italic,t.Underline,t.Strikethrough,t.Link,t.Paragraph,t.Heading,t.BlockQuote,t.List,t.Alignment,t.Table,t.TableToolbar,t.TableProperties,t.TableCellProperties,t.Indent,t.IndentBlock,t.PasteFromOffice,t.GeneralHtmlSupport,t.Image,t.ImageResize,t.ImageStyle,t.ImageCaption,t.ImageToolbar];return o==="full"&&n.push(t.MediaEmbed,t.HorizontalLine,t.CodeBlock,t.SourceEditing,t.FontSize,t.FontColor,t.FontBackgroundColor),e&&n.push(t.ImageUpload),n}function ae(){return"/api/plugins/sirsoft-ckeditor5/upload"}async function wt(t,o){const e=window.CKEDITOR;if(!e)throw new Error("[sirsoft-ckeditor5] CKEDITOR 전역 객체를 찾을 수 없습니다. CDN 스크립트가 로드되었는지 확인하세요.");const n=pt[o.toolbar]||pt.standard,a=o.imageUpload?n:n.filter(s=>s!=="uploadImage"),r={initialData:o.initialContent,plugins:re(e,o.toolbar,o.imageUpload),toolbar:{items:a},table:{contentToolbar:["tableColumn","tableRow","mergeTableCells","|","tableProperties","tableCellProperties"],tableProperties:{defaultProperties:{borderStyle:"solid",borderColor:"#d1d5db",borderWidth:"1px",alignment:"left"}},tableCellProperties:{defaultProperties:{borderStyle:"solid",borderColor:"#d1d5db",borderWidth:"1px",padding:"8px"}}},image:{toolbar:["imageStyle:inline","imageStyle:alignLeft","imageStyle:alignCenter","imageStyle:alignRight","|","toggleImageCaption","|","resizeImage"],resizeOptions:[{name:"resizeImage:original",label:"Original",value:null},{name:"resizeImage:25",label:"25%",value:"25"},{name:"resizeImage:50",label:"50%",value:"50"},{name:"resizeImage:75",label:"75%",value:"75"}],resizeUnit:"%"},indentBlock:{offset:40,unit:"px"},link:{defaultProtocol:"https://",decorators:{openInNewTab:{mode:"automatic",callback:s=>s.startsWith("http://")||s.startsWith("https://"),attributes:{target:"_blank",rel:"noopener noreferrer"}}}},htmlSupport:{allow:[{name:/.*/,attributes:!0,classes:!0,styles:!0}]},placeholder:o.placeholder,language:P()};o.imageUpload&&(r.extraPlugins=[Qt(ae())]);const l=await e.ClassicEditor.create(t,r),i=`ckeditor5-height-style-${o.containerId}`,f=document.getElementById(i);if(f)f.textContent=`#${o.containerId} .ck-editor__editable { min-height: ${o.height}px !important; }`;else{const s=document.createElement("style");s.id=i,s.textContent=`#${o.containerId} .ck-editor__editable { min-height: ${o.height}px !important; }`,document.head.appendChild(s)}return o.readOnly&&l.enableReadOnlyMode("read-only"),l}function le(t,o,e,n,a){const r=document.createElement("div");r.className="ckeditor5-locale-tabs";const l=new Map;o.forEach(s=>{const c=document.createElement("div");c.dataset.locale=s,s!==e&&(c.style.cssText="display:none;");const d=document.createElement("div");c.appendChild(d),l.set(s,d)});const i=()=>{a&&r.querySelectorAll("button").forEach(s=>{const c=s.dataset.locale;if(!c||s.classList.contains("is-active")){const g=s.querySelector(".ck5-check");g&&g.remove();return}const u=!!a(c)?.trim(),k=s.querySelector(".ck5-check");if(u&&!k){const g=document.createElement("i");g.className="fas fa-check ck5-check",s.appendChild(g)}else!u&&k&&k.remove()})},f=o[0];return o.forEach(s=>{const c=s===f,d=s===e,u=document.createElement("button");u.type="button",u.dataset.locale=s,u.className=[d?"is-active":"",c?"is-default":""].filter(Boolean).join(" "),u.innerHTML=`<i class="fas fa-globe ck5-lang-icon"></i><span>${ne(s)}</span>${c?'<span class="ck5-required">*</span>':""}`,u.addEventListener("click",async()=>{r.querySelectorAll("button").forEach(k=>{const m=k.dataset.locale===s;k.classList.toggle("is-active",m)}),l.forEach((k,g)=>{const m=k.parentElement;m&&(m.style.display=g===s?"block":"none")}),n&&await n(s),i()}),r.appendChild(u)}),t.appendChild(r),l.forEach(s=>{s.parentElement&&t.appendChild(s.parentElement)}),{tabsEl:r,contentEls:l,updateCheckIcons:i}}function Ct(t,o,e,n,a=!0,r=null){if(Ut())return;const l=window.G7Core;if(!l?.state?.setLocal)return;if(a&&l.state.getLocal?.()?.hasChanges!==!0&&l.state.setLocal({hasChanges:!0}),r&&r.scope==="global"){const s=n?`${r.path}.${o}`:r.path;window.G7Core?.state?.set?.({[s]:e});return}const i=r?.path??`form.${t}`,f={[`form.${t}_mode`]:"html"};n?f[`${i}.${o}`]=e:f[i]=e,l.state.setLocal(f,{debounce:300,debounceKey:`ckeditor-sync-${t}`,render:!1,selfManaged:!0})}function _t(t,o){const e=window.G7Core,n=typeof t.content=="string"?t.content:"";let r=n.startsWith("{{")&&n.endsWith("}}")?"":n;if(!r&&o){const i=(e?.state?.getLocal?.()??{})?.form?.[o];typeof i=="string"&&i&&(r=i)}return r}function Et(t,o){const e=window.G7Core;let n={};try{typeof t.content=="string"&&t.content.startsWith("{")&&!t.content.startsWith("{{")?n=JSON.parse(t.content):typeof t.content=="object"&&t.content!==null&&(n=t.content)}catch{n={}}if(Object.keys(n).length===0&&o){const r=(e?.state?.getLocal?.()??{})?.form?.[o];if(r&&typeof r=="object"&&!Array.isArray(r)?n=r:typeof r=="string"&&r&&(n={[P()]:r}),Object.keys(n).length===0){const l=e?.state?.get?.()??{},f=(l?._global?.selectedItem??l?.selectedItem)?.[o];f&&typeof f=="object"&&!Array.isArray(f)&&(n=f)}}return n}async function St(t,o){const e=t.params??{},a=`ckeditor5-${e.name??"content"}`;it(a);const r=p.get(a);if(r&&r.size>0){const b=[];r.forEach(y=>{y&&typeof y.destroy=="function"&&b.push(y.destroy().catch(()=>{}))}),await Promise.allSettled(b),p.delete(a)}const l=document.getElementById(a);if(!l){console.warn(`[sirsoft-ckeditor5] 컨테이너 엘리먼트를 찾을 수 없습니다: #${a}`);return}bt(),Zt(),ht(),te(),await Xt(P());const f=window.G7Core?.state?.get()||{},s=f._global?.plugins?.["sirsoft-ckeditor5"]??f.plugins?.["sirsoft-ckeditor5"]??{},c=e.name??"content",d=e.multilingual===!0||e.multilingual==="true",u=e.readOnly===!0||e.readOnly==="true",k=e.imageUpload!==void 0?e.imageUpload===!0||e.imageUpload==="true":s.imageUpload===!0||s.imageUpload==="true",g=e.placeholder??"",m=e.height!==void 0?Number(e.height)||400:Number(s.editorHeight)||400,z=(e.toolbar!==void 0?e.toolbar:s.toolbar)??"standard",h=!(e.trackChanges===!1||e.trackChanges==="false"),L=e.writeScope==="global"?"global":"local",F=typeof e.writePath=="string"&&e.writePath.trim()!==""&&!e.writePath.includes("{{")?e.writePath.trim():null,x=F===null?null:{scope:L,path:F},$t=(b,y)=>{if(!x)return;const w=window.G7Core,v=x.scope==="global"?w?.state?.get?.():w?.state?.getLocal?.(),$=(y?`${x.path}.${b}`:x.path).split(".").reduce((_,Q)=>_?.[Q],v);return typeof $=="string"?$:void 0};let It=d?Et(e,c):{};const ie=await ft();if(!l.isConnected)return;if(!ie){const b={container:l,name:c,height:m,readOnly:u,placeholder:g,trackChanges:h,multilingual:d,locales:d?yt():void 0,activeLocale:P(),contentMap:d?Et(e,c):void 0,initialContent:d?void 0:_t(e,c)};et(b),W(`ckeditor5-editor:${a}`,G("editor.asset.label","편집기"),()=>ot(t,o,l,b.multilingual,c),G("editor.asset.fallback_notice","편집기를 불러오지 못했습니다. 임시 입력창으로 전환했습니다. 작성한 내용은 그대로 저장됩니다."));return}const T=new Map;p.set(a,T);const Gt={placeholder:g,readOnly:u,imageUpload:k,height:m,toolbar:z,containerId:a};if(d){const b=yt(),y=P(),w={current:new Map},v={current:()=>{}},U=async C=>{if(T.has(C))return;const B=w.current.get(C);if(!B)return;const se=It[C]??"";try{const D=await wt(B,{...Gt,initialContent:se});T.set(C,D);let Pt=!0;const at=st(D,{name:c,locale:C,multilingual:!0,readExternal:x?()=>$t(C,!0):void 0});if(ct(a,at),D.model.document.on("change:data",()=>{if(Pt||!at.shouldEmit())return;const j=D.getData();at.noteEmitted(j),Ct(c,C,j,!0,h,x),v.current()}),Pt=!1,c){const j=window.G7Core;(j?.state?.getLocal?.()??{}).form?.[`${c}_mode`]!=="html"&&j.state.setLocal({[`form.${c}_mode`]:"html"})}}catch(D){console.error(`[sirsoft-ckeditor5] 에디터 초기화 오류 (locale: ${C}):`,D)}},$=C=>{const B=T.get(C);return B?B.getData?.()??null:null},{contentEls:_,updateCheckIcons:Q}=le(l,b,y,U,$);if(w.current=_,v.current=Q,await U(y),!T.has(y)){p.delete(a),et({container:l,name:c,height:m,readOnly:u,placeholder:g,multilingual:!0,locales:b,activeLocale:y,contentMap:It,trackChanges:h}),W(`ckeditor5-editor:${a}`,G("editor.asset.label","편집기"),()=>ot(t,o,l,!0,c),G("editor.asset.fallback_notice","편집기를 불러오지 못했습니다. 임시 입력창으로 전환했습니다. 작성한 내용은 그대로 저장됩니다."));return}setTimeout(()=>{b.filter(C=>C!==y).forEach(C=>{U(C)})},2e3)}else{const b=_t(e,c),y=document.createElement("div");l.appendChild(y);try{const w=await wt(y,{...Gt,initialContent:b}),v=P();T.set(v,w);let U=!0;const $=st(w,{name:c,locale:v,multilingual:!1,readExternal:x?()=>$t(v,!1):void 0});if(ct(a,$),w.model.document.on("change:data",()=>{if(U||!$.shouldEmit())return;const _=w.getData();$.noteEmitted(_),Ct(c,v,_,!1,h,x)}),U=!1,b){const _=w.getData();(!_||_.trim()==="")&&w.setData(b)}if(c){const _=window.G7Core;(_?.state?.getLocal?.()??{}).form?.[`${c}_mode`]!=="html"&&_.state.setLocal({[`form.${c}_mode`]:"html"})}}catch(w){console.error("[sirsoft-ckeditor5] 에디터 초기화 오류:",w),p.delete(a),et({container:l,name:c,height:m,readOnly:u,placeholder:g,multilingual:!1,activeLocale:P(),initialContent:b,trackChanges:h}),W(`ckeditor5-editor:${a}`,G("editor.asset.label","편집기"),()=>ot(t,o,l,!1,c),G("editor.asset.fallback_notice","편집기를 불러오지 못했습니다. 임시 입력창으로 전환했습니다. 작성한 내용은 그대로 저장됩니다."))}}}async function ce(t,o){const n=`ckeditor5-${t.params?.name??"content"}`;it(n);const a=p.get(n);if(!a||a.size===0)return;const r=[];a.forEach(i=>{i&&typeof i.destroy=="function"&&r.push(i.destroy().catch(f=>{console.warn("[sirsoft-ckeditor5] destroyEditor error:",f)}))}),await Promise.allSettled(r),p.delete(n);const l=document.getElementById(`ckeditor5-height-style-${n}`);l&&l.remove(),p.size===0&&ee()}const E="sirsoft-ckeditor5",nt="ckeditor5-content-styles",vt="ckeditor5-content-styles-override";function Lt(){if(!document.getElementById(nt)){const t=window?.G7Core?.asset;try{const o=dt();if(typeof t?.loadStylesheet=="function")t.loadStylesheet(o,{id:nt},{label:"ckeditor5 content CSS"}).catch(e=>{console.warn("[ckeditor5] 본문 스타일 로드 실패:",e),window?.G7Core?.assets?.notifyFailure?.({id:"ckeditor5-content-css",label:"본문 스타일",retry:()=>Lt()})});else{const e=document.createElement("link");e.id=nt,e.rel="stylesheet",e.href=o,document.head.appendChild(e)}}catch(o){console.warn("[ckeditor5] 본문 스타일 URL 을 만들지 못했습니다:",o)}}if(!document.getElementById(vt)){const t=document.createElement("style");t.id=vt,t.textContent=`
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
        `,document.head.appendChild(t)}}const A={initEditor:St,destroyEditor:ce,injectContentCss:Lt},N=window.G7Core?.createLogger?.(`Plugin:${E}`)??{log:(...t)=>console.log(`[Plugin:${E}]`,...t),warn:(...t)=>console.warn(`[Plugin:${E}]`,...t),error:(...t)=>console.error(`[Plugin:${E}]`,...t)};function xt(t=!1){const o=window.G7Core?.getActionDispatcher?.();if(o)Object.entries(A).forEach(([e,n])=>{const a=`${E}.${e}`;o.registerHandler(a,n,{category:"plugin",source:E})}),N.log(`${Object.keys(A).length} handler(s) registered:`,Object.keys(A).map(e=>`${E}.${e}`));else if(t){let e=0;const n=50,a=()=>{const r=window.G7Core?.getActionDispatcher?.();r?(Object.entries(A).forEach(([l,i])=>{const f=`${E}.${l}`;r.registerHandler(f,i,{category:"plugin",source:E})}),N.log(`${Object.keys(A).length} handler(s) registered:`,Object.keys(A).map(l=>`${E}.${l}`))):(e++,e<=n?(N.warn(`ActionDispatcher not found, retrying... (${e}/${n})`),setTimeout(a,100)):N.error("Failed to register handlers: ActionDispatcher not available after maximum retries"))};a()}else N.warn("ActionDispatcher not found, handlers not registered")}function rt(){if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",()=>xt(!0));else{const t=!!window.G7Core?.getActionDispatcher?.();xt(!t)}}return rt(),typeof window<"u"&&(window.__SirsoftCkeditor5={identifier:E,handlers:Object.keys(A),initPlugin:rt}),S.initPlugin=rt,Object.defineProperty(S,Symbol.toStringTag,{value:"Module"}),S}({});
