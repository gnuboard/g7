var SirsoftCkeditor5=function(F){"use strict";const E=new Map;let K=!1;function St(){return K}function tt(t){K=t}const T=new Map;function et(t,o){const e=T.get(t)??[];e.push(o),T.set(t,e)}function ot(t){(T.get(t)??[]).forEach(o=>{try{o.stop()}catch{}}),T.delete(t)}const vt=400,Et=1e4;function nt(t,o){const{name:e,locale:r,multilingual:n}=o;let a=location.pathname,c=null,d=!1,k=null,i=0;const l=()=>{const f=window.G7Core?.state?.getLocal?.()?.form;if(!f||!e)return;const g=n?f[e]?.[r]:f[e];return typeof g=="string"?g:void 0},s=window.setInterval(()=>{let u;try{u=t.getData()}catch{window.clearInterval(s);return}location.pathname!==a&&(a=location.pathname,c=null,d=!0,i=Date.now(),k=u);const f=l();if(f!==void 0){if(d){if(f===k){Date.now()-i>Et&&(d=!1,k=null);return}if(f!==u){tt(!0);try{t.setData(f)}finally{tt(!1)}}d=!1,k=null,c=f;return}if(f===c||f===u){c=f;return}}},vt);return{shouldEmit:()=>!d,noteEmitted:u=>{c=u},stop:()=>window.clearInterval(s)}}const xt="43.3.1",H="sirsoft-ckeditor5",q=`dist/vendor/ckeditor5/${xt}`,V="ckeditor5-vendor-css";function W(){const t=window?.G7Core?.asset;if(!t||typeof t.plugin!="function")throw new Error("G7Core.asset is unavailable — core 7.0.10 or later is required to resolve bundled CKEditor5 assets");return t}function Lt(){return W().plugin(H,`${q}/ckeditor5.umd.js`)}function rt(){return W().plugin(H,`${q}/ckeditor5.css`)}function $t(t){return W().plugin(H,`${q}/translations/${t}.umd.js`)}const j="data-ckeditor5-fallback",R=new WeakMap;function N(t,o,e,r){const n=window.G7Core;if(!n?.state?.setLocal||!t)return;e&&r&&n.state.getLocal?.()?.hasChanges!==!0&&n.state.setLocal({hasChanges:!0});const a={[`form.${t}_mode`]:"text"};if(typeof o=="string")a[`form.${t}`]=o;else for(const[c,d]of Object.entries(o))a[`form.${t}.${c}`]=d;n.state.setLocal(a,{render:!1,selfManaged:!0})}function Q(t){const{container:o,name:e,height:r,readOnly:n,placeholder:a,multilingual:c}=t;if(o.querySelector(`[${j}]`))return;o.innerHTML="";const d=t.trackChanges!==!1,k=c?t.locales??[]:[],i=t.activeLocale??k[0]??"",l=c?{...t.contentMap??{}}:{"":t.initialContent??""},s=document.createElement("textarea");s.setAttribute(j,"1"),s.name=e,s.readOnly=n,s.placeholder=a??"",s.style.cssText=["width:100%",`height:${r}px`,"padding:12px","border:1px solid #d1d5db","border-radius:6px","font-family:inherit","font-size:14px","line-height:1.6","resize:vertical","box-sizing:border-box"].join(";");let u=i;if(c){const f=document.createElement("div");f.className="ckeditor5-locale-tabs";const g=()=>{f.querySelectorAll("button").forEach(b=>{const G=b.dataset.locale??"",m=G===u;b.classList.toggle("is-active",m);const S=(l[G]??"").trim()!=="",v=b.querySelector("[data-check]");v&&(v.style.display=S?"":"none")})};k.forEach((b,G)=>{const m=document.createElement("button");m.type="button",m.dataset.locale=b,G===0&&m.classList.add("is-default"),m.textContent=b.toUpperCase();const S=document.createElement("span");S.dataset.check="1",S.textContent="✓",S.style.display="none",m.appendChild(S),m.addEventListener("click",()=>{l[u]=s.value,u=b,s.value=l[b]??"",s.dataset.locale=b,N(e,l,!1,d),g()}),f.appendChild(m)}),o.appendChild(f),s.dataset.locale=u,s.value=l[u]??"",g(),s.addEventListener("input",()=>{l[u]=s.value,N(e,l,!0,d),g()})}else s.value=l[""]??"",s.addEventListener("input",()=>{l[""]=s.value,N(e,s.value,!0,d)});o.appendChild(s),R.set(o,{multilingual:c,values:l,activeLocale:u}),N(e,c?l:l[""]??"",!1,d)}function It(t){const o=R.get(t);if(!o)return null;const e=t.querySelector(`textarea[${j}]`);return e&&(o.values[o.multilingual?e.dataset.locale??o.activeLocale:""]=e.value),o.multilingual?{...o.values}:o.values[""]??""}function Gt(t){return t.querySelector(`[${j}]`)?(t.innerHTML="",R.delete(t),!0):!1}const at="ckeditor5-dark-override",ct="ckeditor5-tab-style",lt={minimal:["bold","italic","underline","|","link","uploadImage","|","undo","redo"],standard:["heading","|","bold","italic","underline","strikethrough","|","alignment","|","link","blockQuote","|","bulletedList","numberedList","indent","outdent","|","insertTable","uploadImage","|","undo","redo"],full:["heading","|","fontSize","fontColor","fontBackgroundColor","|","bold","italic","underline","strikethrough","|","alignment","|","link","blockQuote","insertTable","uploadImage","mediaEmbed","horizontalLine","|","bulletedList","numberedList","indent","outdent","|","codeBlock","sourceEditing","|","undo","redo"]};function Mt(t){if(t==="en")return Promise.resolve();const o=`ckeditor5-translations-${t}`;if(document.getElementById(o))return Promise.resolve();const e=window?.G7Core?.asset?.loadScript;return new Promise(r=>{let n;try{n=$t(t)}catch(c){console.warn("[ckeditor5] 번역 URL 을 만들지 못했습니다 (영어로 동작):",c),r();return}if(typeof e=="function"){e(n,{id:o},{label:`ckeditor5 translations: ${t}`,retries:1}).catch(c=>{console.warn(`[ckeditor5] 번역 로드 실패 (${t}) — 영어로 동작합니다:`,c)}).then(()=>r());return}const a=document.createElement("script");a.id=o,a.src=n,a.onload=()=>r(),a.onerror=()=>{console.warn(`[ckeditor5] 번역 로드 실패 (${t}) — 영어로 동작합니다.`),r()},document.head.appendChild(a)})}async function it(){if(document.getElementById(V))return;const t=window?.G7Core?.asset?.loadStylesheet;let o;try{o=rt()}catch(e){console.warn("[ckeditor5] CSS URL 을 만들지 못했습니다:",e);return}if(typeof t!="function"){const e=document.createElement("link");e.id=V,e.rel="stylesheet",e.href=o,document.head.appendChild(e);return}try{await t(o,{id:V},{label:"ckeditor5 CSS"})}catch(e){console.warn("[ckeditor5] CSS 로드 실패:",e),D("ckeditor5-css",x("editor.asset.style_label","편집기 스타일"),()=>it())}}async function st(){if(window.CKEDITOR)return!0;const t=window?.G7Core?.asset?.loadScript;if(typeof t!="function")return!1;try{await t(Lt(),{id:"ckeditor5-vendor-umd"},{label:"ckeditor5 UMD"})}catch(o){return console.warn("[ckeditor5] 편집기 본체를 불러오지 못했습니다:",o),!1}return!!window.CKEDITOR}async function J(t,o,e,r,n){const a=It(e);if(!await st())throw new Error("[sirsoft-ckeditor5] 편집기 본체를 여전히 불러올 수 없습니다.");Gt(e);const c={...t,params:{...t.params??{},content:r?a??{}:a??""}};if(await mt(c,o),!n)return;window.G7Core?.state?.setLocal?.({[`form.${n}_mode`]:"html"})}function D(t,o,e,r){const n=window?.G7Core?.assets?.notifyFailure;if(typeof n=="function"){n({id:t,label:o,retry:e,message:r});return}console.warn(`[ckeditor5] 자산 로드 실패: ${o}`)}function x(t,o){const e=window?.G7Core?.t;if(typeof e!="function")return o;const r=`sirsoft-ckeditor5.${t}`,n=e(r);return typeof n=="string"&&n!==r?n:o}function zt(){if(document.getElementById(ct))return;const t=document.createElement("style");t.id=ct,t.textContent=`
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
    `,document.head.appendChild(t)}function Ut(){return document.documentElement.classList.contains("dark")}function dt(){const t=document.getElementById(at);if(!Ut()){t&&t.remove();return}if(t)return;const o=document.createElement("style");o.id=at,o.textContent=`
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
    `,document.head.appendChild(o)}let I=null;function At(){I||(I=new MutationObserver(()=>{dt()}),I.observe(document.documentElement,{attributes:!0,attributeFilter:["class"]}))}function Pt(){I&&(I.disconnect(),I=null)}const Bt={ko:"한국어",en:"English",ja:"日本語",zh:"中文",fr:"Français",de:"Deutsch",es:"Español"};function Ot(t){return Bt[t]??t.toUpperCase()}function ut(){const t=window.G7Core;return t?.locale?.supported?t.locale.supported():[localStorage.getItem("g7_locale")||"ko"]}function L(){return window.G7Core?.locale?.current?.()??localStorage.getItem("g7_locale")??"ko"}function Tt(t,o,e){const r=[t.Essentials,t.Bold,t.Italic,t.Underline,t.Strikethrough,t.Link,t.Paragraph,t.Heading,t.BlockQuote,t.List,t.Alignment,t.Table,t.TableToolbar,t.TableProperties,t.TableCellProperties,t.Indent,t.IndentBlock,t.PasteFromOffice,t.GeneralHtmlSupport,t.Image,t.ImageResize,t.ImageStyle,t.ImageCaption,t.ImageToolbar];return o==="full"&&r.push(t.MediaEmbed,t.HorizontalLine,t.CodeBlock,t.SourceEditing,t.FontSize,t.FontColor,t.FontBackgroundColor),e&&r.push(t.ImageUpload,t.SimpleUploadAdapter),r}function jt(){return"/api/plugins/sirsoft-ckeditor5/upload"}function Nt(){const t=localStorage.getItem("auth_token");return t?{Authorization:`Bearer ${t}`}:{}}async function ft(t,o){const e=window.CKEDITOR;if(!e)throw new Error("[sirsoft-ckeditor5] CKEDITOR 전역 객체를 찾을 수 없습니다. CDN 스크립트가 로드되었는지 확인하세요.");const r=lt[o.toolbar]||lt.standard,n=o.imageUpload?r:r.filter(i=>i!=="uploadImage"),a={initialData:o.initialContent,plugins:Tt(e,o.toolbar,o.imageUpload),toolbar:{items:n},table:{contentToolbar:["tableColumn","tableRow","mergeTableCells","|","tableProperties","tableCellProperties"],tableProperties:{defaultProperties:{borderStyle:"solid",borderColor:"#d1d5db",borderWidth:"1px",alignment:"left"}},tableCellProperties:{defaultProperties:{borderStyle:"solid",borderColor:"#d1d5db",borderWidth:"1px",padding:"8px"}}},image:{toolbar:["imageStyle:inline","imageStyle:alignLeft","imageStyle:alignCenter","imageStyle:alignRight","|","toggleImageCaption","|","resizeImage"],resizeOptions:[{name:"resizeImage:original",label:"Original",value:null},{name:"resizeImage:25",label:"25%",value:"25"},{name:"resizeImage:50",label:"50%",value:"50"},{name:"resizeImage:75",label:"75%",value:"75"}],resizeUnit:"%"},indentBlock:{offset:40,unit:"px"},link:{defaultProtocol:"https://",decorators:{openInNewTab:{mode:"automatic",callback:i=>i.startsWith("http://")||i.startsWith("https://"),attributes:{target:"_blank",rel:"noopener noreferrer"}}}},htmlSupport:{allow:[{name:/.*/,attributes:!0,classes:!0,styles:!0}]},placeholder:o.placeholder,language:L()};o.imageUpload&&(a.simpleUpload={uploadUrl:jt(),headers:Nt()});const c=await e.ClassicEditor.create(t,a),d=`ckeditor5-height-style-${o.containerId}`,k=document.getElementById(d);if(k)k.textContent=`#${o.containerId} .ck-editor__editable { min-height: ${o.height}px !important; }`;else{const i=document.createElement("style");i.id=d,i.textContent=`#${o.containerId} .ck-editor__editable { min-height: ${o.height}px !important; }`,document.head.appendChild(i)}return o.readOnly&&c.enableReadOnlyMode("read-only"),c}function Dt(t,o,e,r,n){const a=document.createElement("div");a.className="ckeditor5-locale-tabs";const c=new Map;o.forEach(i=>{const l=document.createElement("div");l.dataset.locale=i,i!==e&&(l.style.cssText="display:none;");const s=document.createElement("div");l.appendChild(s),c.set(i,s)});const d=()=>{n&&a.querySelectorAll("button").forEach(i=>{const l=i.dataset.locale;if(!l||i.classList.contains("is-active")){const g=i.querySelector(".ck5-check");g&&g.remove();return}const u=!!n(l)?.trim(),f=i.querySelector(".ck5-check");if(u&&!f){const g=document.createElement("i");g.className="fas fa-check ck5-check",i.appendChild(g)}else!u&&f&&f.remove()})},k=o[0];return o.forEach(i=>{const l=i===k,s=i===e,u=document.createElement("button");u.type="button",u.dataset.locale=i,u.className=[s?"is-active":"",l?"is-default":""].filter(Boolean).join(" "),u.innerHTML=`<i class="fas fa-globe ck5-lang-icon"></i><span>${Ot(i)}</span>${l?'<span class="ck5-required">*</span>':""}`,u.addEventListener("click",async()=>{a.querySelectorAll("button").forEach(f=>{const b=f.dataset.locale===i;f.classList.toggle("is-active",b)}),c.forEach((f,g)=>{const b=f.parentElement;b&&(b.style.display=g===i?"block":"none")}),r&&await r(i),d()}),a.appendChild(u)}),t.appendChild(a),c.forEach(i=>{i.parentElement&&t.appendChild(i.parentElement)}),{tabsEl:a,contentEls:c,updateCheckIcons:d}}function kt(t,o,e,r,n=!0){if(St())return;const a=window.G7Core;if(!a?.state?.setLocal)return;n&&a.state.getLocal?.()?.hasChanges!==!0&&a.state.setLocal({hasChanges:!0});const c={[`form.${t}_mode`]:"html"};r?c[`form.${t}.${o}`]=e:c[`form.${t}`]=e,a.state.setLocal(c,{debounce:300,debounceKey:`ckeditor-sync-${t}`,render:!1,selfManaged:!0})}function gt(t,o){const e=window.G7Core,r=typeof t.content=="string"?t.content:"";let a=r.startsWith("{{")&&r.endsWith("}}")?"":r;if(!a&&o){const d=(e?.state?.getLocal?.()??{})?.form?.[o];typeof d=="string"&&d&&(a=d)}return a}function bt(t,o){const e=window.G7Core;let r={};try{typeof t.content=="string"&&t.content.startsWith("{")&&!t.content.startsWith("{{")?r=JSON.parse(t.content):typeof t.content=="object"&&t.content!==null&&(r=t.content)}catch{r={}}if(Object.keys(r).length===0&&o){const a=(e?.state?.getLocal?.()??{})?.form?.[o];if(a&&typeof a=="object"&&!Array.isArray(a)?r=a:typeof a=="string"&&a&&(r={[L()]:a}),Object.keys(r).length===0){const c=e?.state?.get?.()??{},k=(c?._global?.selectedItem??c?.selectedItem)?.[o];k&&typeof k=="object"&&!Array.isArray(k)&&(r=k)}}return r}async function mt(t,o){const e=t.params??{},n=`ckeditor5-${e.name??"content"}`;ot(n);const a=E.get(n);if(a&&a.size>0){const p=[];a.forEach(y=>{y&&typeof y.destroy=="function"&&p.push(y.destroy().catch(()=>{}))}),await Promise.allSettled(p),E.delete(n)}const c=document.getElementById(n);if(!c){console.warn(`[sirsoft-ckeditor5] 컨테이너 엘리먼트를 찾을 수 없습니다: #${n}`);return}it(),zt(),dt(),At(),await Mt(L());const k=window.G7Core?.state?.get()||{},i=k._global?.plugins?.["sirsoft-ckeditor5"]??k.plugins?.["sirsoft-ckeditor5"]??{},l=e.name??"content",s=e.multilingual===!0||e.multilingual==="true",u=e.readOnly===!0||e.readOnly==="true",f=e.imageUpload!==void 0?e.imageUpload===!0||e.imageUpload==="true":i.imageUpload===!0||i.imageUpload==="true",g=e.placeholder??"",b=e.height!==void 0?Number(e.height)||400:Number(i.editorHeight)||400,G=(e.toolbar!==void 0?e.toolbar:i.toolbar)??"standard",m=!(e.trackChanges===!1||e.trackChanges==="false");let S=s?bt(e,l):{};if(!await st()){const p={container:c,name:l,height:b,readOnly:u,placeholder:g,trackChanges:m,multilingual:s,locales:s?ut():void 0,activeLocale:L(),contentMap:s?bt(e,l):void 0,initialContent:s?void 0:gt(e,l)};Q(p),D(`ckeditor5-editor:${n}`,x("editor.asset.label","편집기"),()=>J(t,o,c,p.multilingual,l),x("editor.asset.fallback_notice","편집기를 불러오지 못했습니다. 임시 입력창으로 전환했습니다. 작성한 내용은 그대로 저장됩니다."));return}const v=new Map;E.set(n,v);const wt={placeholder:g,readOnly:u,imageUpload:f,height:b,toolbar:G,containerId:n};if(s){const p=ut(),y=L(),C={current:new Map},M={current:()=>{}},z=async h=>{if(v.has(h))return;const B=C.current.get(h);if(!B)return;const Ht=S[h]??"";try{const U=await ft(B,{...wt,initialContent:Ht});v.set(h,U);let _t=!0;const Z=nt(U,{name:l,locale:h,multilingual:!0});if(et(n,Z),U.model.document.on("change:data",()=>{if(_t||!Z.shouldEmit())return;const O=U.getData();Z.noteEmitted(O),kt(l,h,O,!0,m),M.current()}),_t=!1,l){const O=window.G7Core;(O?.state?.getLocal?.()??{}).form?.[`${l}_mode`]!=="html"&&O.state.setLocal({[`form.${l}_mode`]:"html"})}}catch(U){console.error(`[sirsoft-ckeditor5] 에디터 초기화 오류 (locale: ${h}):`,U)}},P=h=>{const B=v.get(h);return B?B.getData?.()??null:null},{contentEls:_,updateCheckIcons:Ct}=Dt(c,p,y,z,P);if(C.current=_,M.current=Ct,await z(y),!v.has(y)){E.delete(n),Q({container:c,name:l,height:b,readOnly:u,placeholder:g,multilingual:!0,locales:p,activeLocale:y,contentMap:S,trackChanges:m}),D(`ckeditor5-editor:${n}`,x("editor.asset.label","편집기"),()=>J(t,o,c,!0,l),x("editor.asset.fallback_notice","편집기를 불러오지 못했습니다. 임시 입력창으로 전환했습니다. 작성한 내용은 그대로 저장됩니다."));return}setTimeout(()=>{p.filter(h=>h!==y).forEach(h=>{z(h)})},2e3)}else{const p=gt(e,l),y=document.createElement("div");c.appendChild(y);try{const C=await ft(y,{...wt,initialContent:p}),M=L();v.set(M,C);let z=!0;const P=nt(C,{name:l,locale:M,multilingual:!1});if(et(n,P),C.model.document.on("change:data",()=>{if(z||!P.shouldEmit())return;const _=C.getData();P.noteEmitted(_),kt(l,M,_,!1,m)}),z=!1,p){const _=C.getData();(!_||_.trim()==="")&&C.setData(p)}if(l){const _=window.G7Core;(_?.state?.getLocal?.()??{}).form?.[`${l}_mode`]!=="html"&&_.state.setLocal({[`form.${l}_mode`]:"html"})}}catch(C){console.error("[sirsoft-ckeditor5] 에디터 초기화 오류:",C),E.delete(n),Q({container:c,name:l,height:b,readOnly:u,placeholder:g,multilingual:!1,activeLocale:L(),initialContent:p,trackChanges:m}),D(`ckeditor5-editor:${n}`,x("editor.asset.label","편집기"),()=>J(t,o,c,!1,l),x("editor.asset.fallback_notice","편집기를 불러오지 못했습니다. 임시 입력창으로 전환했습니다. 작성한 내용은 그대로 저장됩니다."))}}}async function Ft(t,o){const r=`ckeditor5-${t.params?.name??"content"}`;ot(r);const n=E.get(r);if(!n||n.size===0)return;const a=[];n.forEach(d=>{d&&typeof d.destroy=="function"&&a.push(d.destroy().catch(k=>{console.warn("[sirsoft-ckeditor5] destroyEditor error:",k)}))}),await Promise.allSettled(a),E.delete(r);const c=document.getElementById(`ckeditor5-height-style-${r}`);c&&c.remove(),E.size===0&&Pt()}const w="sirsoft-ckeditor5",X="ckeditor5-content-styles",pt="ckeditor5-content-styles-override";function ht(){if(!document.getElementById(X)){const t=window?.G7Core?.asset;try{const o=rt();if(typeof t?.loadStylesheet=="function")t.loadStylesheet(o,{id:X},{label:"ckeditor5 content CSS"}).catch(e=>{console.warn("[ckeditor5] 본문 스타일 로드 실패:",e),window?.G7Core?.assets?.notifyFailure?.({id:"ckeditor5-content-css",label:"본문 스타일",retry:()=>ht()})});else{const e=document.createElement("link");e.id=X,e.rel="stylesheet",e.href=o,document.head.appendChild(e)}}catch(o){console.warn("[ckeditor5] 본문 스타일 URL 을 만들지 못했습니다:",o)}}if(!document.getElementById(pt)){const t=document.createElement("style");t.id=pt,t.textContent=`
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
        `,document.head.appendChild(t)}}const $={initEditor:mt,destroyEditor:Ft,injectContentCss:ht},A=window.G7Core?.createLogger?.(`Plugin:${w}`)??{log:(...t)=>console.log(`[Plugin:${w}]`,...t),warn:(...t)=>console.warn(`[Plugin:${w}]`,...t),error:(...t)=>console.error(`[Plugin:${w}]`,...t)};function yt(t=!1){const o=window.G7Core?.getActionDispatcher?.();if(o)Object.entries($).forEach(([e,r])=>{const n=`${w}.${e}`;o.registerHandler(n,r,{category:"plugin",source:w})}),A.log(`${Object.keys($).length} handler(s) registered:`,Object.keys($).map(e=>`${w}.${e}`));else if(t){let e=0;const r=50,n=()=>{const a=window.G7Core?.getActionDispatcher?.();a?(Object.entries($).forEach(([c,d])=>{const k=`${w}.${c}`;a.registerHandler(k,d,{category:"plugin",source:w})}),A.log(`${Object.keys($).length} handler(s) registered:`,Object.keys($).map(c=>`${w}.${c}`))):(e++,e<=r?(A.warn(`ActionDispatcher not found, retrying... (${e}/${r})`),setTimeout(n,100)):A.error("Failed to register handlers: ActionDispatcher not available after maximum retries"))};n()}else A.warn("ActionDispatcher not found, handlers not registered")}function Y(){if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",()=>yt(!0));else{const t=!!window.G7Core?.getActionDispatcher?.();yt(!t)}}return Y(),typeof window<"u"&&(window.__SirsoftCkeditor5={identifier:w,handlers:Object.keys($),initPlugin:Y}),F.initPlugin=Y,Object.defineProperty(F,Symbol.toStringTag,{value:"Module"}),F}({});
