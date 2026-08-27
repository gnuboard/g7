var SirsoftCkeditor5=function(D){"use strict";const E=new Map;let Z=!1;function _t(){return Z}function K(t){Z=t}const O=new Map;function tt(t,o){const e=O.get(t)??[];e.push(o),O.set(t,e)}function et(t){(O.get(t)??[]).forEach(o=>{try{o.stop()}catch{}}),O.delete(t)}const St=400,vt=1e4;function ot(t,o){const{name:e,locale:n,multilingual:r}=o;let l=location.pathname,i=null,s=!1,k=null,c=0;const a=()=>{const u=window.G7Core?.state?.getLocal?.()?.form;if(!u||!e)return;const f=r?u[e]?.[n]:u[e];return typeof f=="string"?f:void 0},b=window.setInterval(()=>{let d;try{d=t.getData()}catch{window.clearInterval(b);return}location.pathname!==l&&(l=location.pathname,i=null,s=!0,c=Date.now(),k=d);const u=a();if(u!==void 0){if(s){if(u===k){Date.now()-c>vt&&(s=!1,k=null);return}if(u!==d){K(!0);try{t.setData(u)}finally{K(!1)}}s=!1,k=null,i=u;return}if(u===i||u===d){i=u;return}}},St);return{shouldEmit:()=>!s,noteEmitted:d=>{i=d},stop:()=>window.clearInterval(b)}}const Et="43.3.1",F="sirsoft-ckeditor5",H=`dist/vendor/ckeditor5/${Et}`,q="ckeditor5-vendor-css";function V(){const t=window?.G7Core?.asset;if(!t||typeof t.plugin!="function")throw new Error("G7Core.asset is unavailable — core 7.0.10 or later is required to resolve bundled CKEditor5 assets");return t}function xt(){return V().plugin(F,`${H}/ckeditor5.umd.js`)}function nt(){return V().plugin(F,`${H}/ckeditor5.css`)}function Lt(t){return V().plugin(F,`${H}/translations/${t}.umd.js`)}const T="data-ckeditor5-fallback",W=new WeakMap;function j(t,o){const e=window.G7Core;if(!e?.state?.setLocal||!t)return;const n={[`form.${t}_mode`]:"text",hasChanges:!0};if(typeof o=="string")n[`form.${t}`]=o;else for(const[r,l]of Object.entries(o))n[`form.${t}.${r}`]=l;e.state.setLocal(n,{render:!1,selfManaged:!0})}function R(t){const{container:o,name:e,height:n,readOnly:r,placeholder:l,multilingual:i}=t;if(o.querySelector(`[${T}]`))return;o.innerHTML="";const s=i?t.locales??[]:[],k=t.activeLocale??s[0]??"",c=i?{...t.contentMap??{}}:{"":t.initialContent??""},a=document.createElement("textarea");a.setAttribute(T,"1"),a.name=e,a.readOnly=r,a.placeholder=l??"",a.style.cssText=["width:100%",`height:${n}px`,"padding:12px","border:1px solid #d1d5db","border-radius:6px","font-family:inherit","font-size:14px","line-height:1.6","resize:vertical","box-sizing:border-box"].join(";");let b=k;if(i){const d=document.createElement("div");d.className="ckeditor5-locale-tabs";const u=()=>{d.querySelectorAll("button").forEach(f=>{const m=f.dataset.locale??"",w=m===b;f.classList.toggle("is-active",w);const S=(c[m]??"").trim()!=="",v=f.querySelector("[data-check]");v&&(v.style.display=S?"":"none")})};s.forEach((f,m)=>{const w=document.createElement("button");w.type="button",w.dataset.locale=f,m===0&&w.classList.add("is-default"),w.textContent=f.toUpperCase();const S=document.createElement("span");S.dataset.check="1",S.textContent="✓",S.style.display="none",w.appendChild(S),w.addEventListener("click",()=>{c[b]=a.value,b=f,a.value=c[f]??"",a.dataset.locale=f,j(e,c),u()}),d.appendChild(w)}),o.appendChild(d),a.dataset.locale=b,a.value=c[b]??"",u(),a.addEventListener("input",()=>{c[b]=a.value,j(e,c),u()})}else a.value=c[""]??"",a.addEventListener("input",()=>{c[""]=a.value,j(e,a.value)});o.appendChild(a),W.set(o,{multilingual:i,values:c,activeLocale:b}),j(e,i?c:c[""]??"")}function $t(t){const o=W.get(t);if(!o)return null;const e=t.querySelector(`textarea[${T}]`);return e&&(o.values[o.multilingual?e.dataset.locale??o.activeLocale:""]=e.value),o.multilingual?{...o.values}:o.values[""]??""}function It(t){return t.querySelector(`[${T}]`)?(t.innerHTML="",W.delete(t),!0):!1}const rt="ckeditor5-dark-override",lt="ckeditor5-tab-style",ct={minimal:["bold","italic","underline","|","link","uploadImage","|","undo","redo"],standard:["heading","|","bold","italic","underline","strikethrough","|","alignment","|","link","blockQuote","|","bulletedList","numberedList","indent","outdent","|","insertTable","uploadImage","|","undo","redo"],full:["heading","|","fontSize","fontColor","fontBackgroundColor","|","bold","italic","underline","strikethrough","|","alignment","|","link","blockQuote","insertTable","uploadImage","mediaEmbed","horizontalLine","|","bulletedList","numberedList","indent","outdent","|","codeBlock","sourceEditing","|","undo","redo"]};function Gt(t){if(t==="en")return Promise.resolve();const o=`ckeditor5-translations-${t}`;if(document.getElementById(o))return Promise.resolve();const e=window?.G7Core?.asset?.loadScript;return new Promise(n=>{let r;try{r=Lt(t)}catch(i){console.warn("[ckeditor5] 번역 URL 을 만들지 못했습니다 (영어로 동작):",i),n();return}if(typeof e=="function"){e(r,{id:o},{label:`ckeditor5 translations: ${t}`,retries:1}).catch(i=>{console.warn(`[ckeditor5] 번역 로드 실패 (${t}) — 영어로 동작합니다:`,i)}).then(()=>n());return}const l=document.createElement("script");l.id=o,l.src=r,l.onload=()=>n(),l.onerror=()=>{console.warn(`[ckeditor5] 번역 로드 실패 (${t}) — 영어로 동작합니다.`),n()},document.head.appendChild(l)})}async function at(){if(document.getElementById(q))return;const t=window?.G7Core?.asset?.loadStylesheet;let o;try{o=nt()}catch(e){console.warn("[ckeditor5] CSS URL 을 만들지 못했습니다:",e);return}if(typeof t!="function"){const e=document.createElement("link");e.id=q,e.rel="stylesheet",e.href=o,document.head.appendChild(e);return}try{await t(o,{id:q},{label:"ckeditor5 CSS"})}catch(e){console.warn("[ckeditor5] CSS 로드 실패:",e),N("ckeditor5-css",x("editor.asset.style_label","편집기 스타일"),()=>at())}}async function it(){if(window.CKEDITOR)return!0;const t=window?.G7Core?.asset?.loadScript;if(typeof t!="function")return!1;try{await t(xt(),{id:"ckeditor5-vendor-umd"},{label:"ckeditor5 UMD"})}catch(o){return console.warn("[ckeditor5] 편집기 본체를 불러오지 못했습니다:",o),!1}return!!window.CKEDITOR}async function Q(t,o,e,n,r){const l=$t(e);if(!await it())throw new Error("[sirsoft-ckeditor5] 편집기 본체를 여전히 불러올 수 없습니다.");It(e);const i={...t,params:{...t.params??{},content:n?l??{}:l??""}};if(await mt(i,o),!r)return;window.G7Core?.state?.setLocal?.({[`form.${r}_mode`]:"html"})}function N(t,o,e,n){const r=window?.G7Core?.assets?.notifyFailure;if(typeof r=="function"){r({id:t,label:o,retry:e,message:n});return}console.warn(`[ckeditor5] 자산 로드 실패: ${o}`)}function x(t,o){const e=window?.G7Core?.t;if(typeof e!="function")return o;const n=`sirsoft-ckeditor5.${t}`,r=e(n);return typeof r=="string"&&r!==n?r:o}function Mt(){if(document.getElementById(lt))return;const t=document.createElement("style");t.id=lt,t.textContent=`
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
    `,document.head.appendChild(t)}function zt(){return document.documentElement.classList.contains("dark")}function st(){const t=document.getElementById(rt);if(!zt()){t&&t.remove();return}if(t)return;const o=document.createElement("style");o.id=rt,o.textContent=`
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
    `,document.head.appendChild(o)}let I=null;function Ut(){I||(I=new MutationObserver(()=>{st()}),I.observe(document.documentElement,{attributes:!0,attributeFilter:["class"]}))}function At(){I&&(I.disconnect(),I=null)}const Pt={ko:"한국어",en:"English",ja:"日本語",zh:"中文",fr:"Français",de:"Deutsch",es:"Español"};function Bt(t){return Pt[t]??t.toUpperCase()}function dt(){const t=window.G7Core;return t?.locale?.supported?t.locale.supported():[localStorage.getItem("g7_locale")||"ko"]}function L(){return window.G7Core?.locale?.current?.()??localStorage.getItem("g7_locale")??"ko"}function Ot(t,o,e){const n=[t.Essentials,t.Bold,t.Italic,t.Underline,t.Strikethrough,t.Link,t.Paragraph,t.Heading,t.BlockQuote,t.List,t.Alignment,t.Table,t.TableToolbar,t.TableProperties,t.TableCellProperties,t.Indent,t.IndentBlock,t.PasteFromOffice,t.GeneralHtmlSupport,t.Image,t.ImageResize,t.ImageStyle,t.ImageCaption,t.ImageToolbar];return o==="full"&&n.push(t.MediaEmbed,t.HorizontalLine,t.CodeBlock,t.SourceEditing,t.FontSize,t.FontColor,t.FontBackgroundColor),e&&n.push(t.ImageUpload,t.SimpleUploadAdapter),n}function Tt(){return"/api/plugins/sirsoft-ckeditor5/upload"}function jt(){const t=localStorage.getItem("auth_token");return t?{Authorization:`Bearer ${t}`}:{}}async function ut(t,o){const e=window.CKEDITOR;if(!e)throw new Error("[sirsoft-ckeditor5] CKEDITOR 전역 객체를 찾을 수 없습니다. CDN 스크립트가 로드되었는지 확인하세요.");const n=ct[o.toolbar]||ct.standard,r=o.imageUpload?n:n.filter(c=>c!=="uploadImage"),l={initialData:o.initialContent,plugins:Ot(e,o.toolbar,o.imageUpload),toolbar:{items:r},table:{contentToolbar:["tableColumn","tableRow","mergeTableCells","|","tableProperties","tableCellProperties"],tableProperties:{defaultProperties:{borderStyle:"solid",borderColor:"#d1d5db",borderWidth:"1px",alignment:"left"}},tableCellProperties:{defaultProperties:{borderStyle:"solid",borderColor:"#d1d5db",borderWidth:"1px",padding:"8px"}}},image:{toolbar:["imageStyle:inline","imageStyle:alignLeft","imageStyle:alignCenter","imageStyle:alignRight","|","toggleImageCaption","|","resizeImage"],resizeOptions:[{name:"resizeImage:original",label:"Original",value:null},{name:"resizeImage:25",label:"25%",value:"25"},{name:"resizeImage:50",label:"50%",value:"50"},{name:"resizeImage:75",label:"75%",value:"75"}],resizeUnit:"%"},indentBlock:{offset:40,unit:"px"},link:{defaultProtocol:"https://",decorators:{openInNewTab:{mode:"automatic",callback:c=>c.startsWith("http://")||c.startsWith("https://"),attributes:{target:"_blank",rel:"noopener noreferrer"}}}},htmlSupport:{allow:[{name:/.*/,attributes:!0,classes:!0,styles:!0}]},placeholder:o.placeholder,language:L()};o.imageUpload&&(l.simpleUpload={uploadUrl:Tt(),headers:jt()});const i=await e.ClassicEditor.create(t,l),s=`ckeditor5-height-style-${o.containerId}`,k=document.getElementById(s);if(k)k.textContent=`#${o.containerId} .ck-editor__editable { min-height: ${o.height}px !important; }`;else{const c=document.createElement("style");c.id=s,c.textContent=`#${o.containerId} .ck-editor__editable { min-height: ${o.height}px !important; }`,document.head.appendChild(c)}return o.readOnly&&i.enableReadOnlyMode("read-only"),i}function Nt(t,o,e,n,r){const l=document.createElement("div");l.className="ckeditor5-locale-tabs";const i=new Map;o.forEach(c=>{const a=document.createElement("div");a.dataset.locale=c,c!==e&&(a.style.cssText="display:none;");const b=document.createElement("div");a.appendChild(b),i.set(c,b)});const s=()=>{r&&l.querySelectorAll("button").forEach(c=>{const a=c.dataset.locale;if(!a||c.classList.contains("is-active")){const f=c.querySelector(".ck5-check");f&&f.remove();return}const d=!!r(a)?.trim(),u=c.querySelector(".ck5-check");if(d&&!u){const f=document.createElement("i");f.className="fas fa-check ck5-check",c.appendChild(f)}else!d&&u&&u.remove()})},k=o[0];return o.forEach(c=>{const a=c===k,b=c===e,d=document.createElement("button");d.type="button",d.dataset.locale=c,d.className=[b?"is-active":"",a?"is-default":""].filter(Boolean).join(" "),d.innerHTML=`<i class="fas fa-globe ck5-lang-icon"></i><span>${Bt(c)}</span>${a?'<span class="ck5-required">*</span>':""}`,d.addEventListener("click",async()=>{l.querySelectorAll("button").forEach(u=>{const m=u.dataset.locale===c;u.classList.toggle("is-active",m)}),i.forEach((u,f)=>{const m=u.parentElement;m&&(m.style.display=f===c?"block":"none")}),n&&await n(c),s()}),l.appendChild(d)}),t.appendChild(l),i.forEach(c=>{c.parentElement&&t.appendChild(c.parentElement)}),{tabsEl:l,contentEls:i,updateCheckIcons:s}}function ft(t,o,e,n){if(_t())return;const r=window.G7Core;if(!r?.state?.setLocal)return;const l={[`form.${t}_mode`]:"html",hasChanges:!0};n?l[`form.${t}.${o}`]=e:l[`form.${t}`]=e,r.state.setLocal(l,{debounce:300,debounceKey:`ckeditor-sync-${t}`,render:!1,selfManaged:!0})}function kt(t,o){const e=window.G7Core,n=typeof t.content=="string"?t.content:"";let l=n.startsWith("{{")&&n.endsWith("}}")?"":n;if(!l&&o){const s=(e?.state?.getLocal?.()??{})?.form?.[o];typeof s=="string"&&s&&(l=s)}return l}function bt(t,o){const e=window.G7Core;let n={};try{typeof t.content=="string"&&t.content.startsWith("{")&&!t.content.startsWith("{{")?n=JSON.parse(t.content):typeof t.content=="object"&&t.content!==null&&(n=t.content)}catch{n={}}if(Object.keys(n).length===0&&o){const l=(e?.state?.getLocal?.()??{})?.form?.[o];if(l&&typeof l=="object"&&!Array.isArray(l)?n=l:typeof l=="string"&&l&&(n={[L()]:l}),Object.keys(n).length===0){const i=e?.state?.get?.()??{},k=(i?._global?.selectedItem??i?.selectedItem)?.[o];k&&typeof k=="object"&&!Array.isArray(k)&&(n=k)}}return n}async function mt(t,o){const e=t.params??{},r=`ckeditor5-${e.name??"content"}`;et(r);const l=E.get(r);if(l&&l.size>0){const g=[];l.forEach(h=>{h&&typeof h.destroy=="function"&&g.push(h.destroy().catch(()=>{}))}),await Promise.allSettled(g),E.delete(r)}const i=document.getElementById(r);if(!i){console.warn(`[sirsoft-ckeditor5] 컨테이너 엘리먼트를 찾을 수 없습니다: #${r}`);return}at(),Mt(),st(),Ut(),await Gt(L());const k=window.G7Core?.state?.get()||{},c=k._global?.plugins?.["sirsoft-ckeditor5"]??k.plugins?.["sirsoft-ckeditor5"]??{},a=e.name??"content",b=e.multilingual===!0||e.multilingual==="true",d=e.readOnly===!0||e.readOnly==="true",u=e.imageUpload!==void 0?e.imageUpload===!0||e.imageUpload==="true":c.imageUpload===!0||c.imageUpload==="true",f=e.placeholder??"",m=e.height!==void 0?Number(e.height)||400:Number(c.editorHeight)||400,w=(e.toolbar!==void 0?e.toolbar:c.toolbar)??"standard";let S=b?bt(e,a):{};if(!await it()){const g={container:i,name:a,height:m,readOnly:d,placeholder:f,multilingual:b,locales:b?dt():void 0,activeLocale:L(),contentMap:b?bt(e,a):void 0,initialContent:b?void 0:kt(e,a)};R(g),N(`ckeditor5-editor:${r}`,x("editor.asset.label","편집기"),()=>Q(t,o,i,g.multilingual,a),x("editor.asset.fallback_notice","편집기를 불러오지 못했습니다. 임시 입력창으로 전환했습니다. 작성한 내용은 그대로 저장됩니다."));return}const v=new Map;E.set(r,v);const yt={placeholder:f,readOnly:d,imageUpload:u,height:m,toolbar:w,containerId:r};if(b){const g=dt(),h=L(),C={current:new Map},G={current:()=>{}},M=async p=>{if(v.has(p))return;const P=C.current.get(p);if(!P)return;const Ft=S[p]??"";try{const z=await ut(P,{...yt,initialContent:Ft});v.set(p,z);let Ct=!0;const Y=ot(z,{name:a,locale:p,multilingual:!0});if(tt(r,Y),z.model.document.on("change:data",()=>{if(Ct||!Y.shouldEmit())return;const B=z.getData();Y.noteEmitted(B),ft(a,p,B,!0),G.current()}),Ct=!1,a){const B=window.G7Core;(B?.state?.getLocal?.()??{}).form?.[`${a}_mode`]!=="html"&&B.state.setLocal({[`form.${a}_mode`]:"html"})}}catch(z){console.error(`[sirsoft-ckeditor5] 에디터 초기화 오류 (locale: ${p}):`,z)}},A=p=>{const P=v.get(p);return P?P.getData?.()??null:null},{contentEls:_,updateCheckIcons:wt}=Nt(i,g,h,M,A);if(C.current=_,G.current=wt,await M(h),!v.has(h)){E.delete(r),R({container:i,name:a,height:m,readOnly:d,placeholder:f,multilingual:!0,locales:g,activeLocale:h,contentMap:S}),N(`ckeditor5-editor:${r}`,x("editor.asset.label","편집기"),()=>Q(t,o,i,!0,a),x("editor.asset.fallback_notice","편집기를 불러오지 못했습니다. 임시 입력창으로 전환했습니다. 작성한 내용은 그대로 저장됩니다."));return}setTimeout(()=>{g.filter(p=>p!==h).forEach(p=>{M(p)})},2e3)}else{const g=kt(e,a),h=document.createElement("div");i.appendChild(h);try{const C=await ut(h,{...yt,initialContent:g}),G=L();v.set(G,C);let M=!0;const A=ot(C,{name:a,locale:G,multilingual:!1});if(tt(r,A),C.model.document.on("change:data",()=>{if(M||!A.shouldEmit())return;const _=C.getData();A.noteEmitted(_),ft(a,G,_,!1)}),M=!1,g){const _=C.getData();(!_||_.trim()==="")&&C.setData(g)}if(a){const _=window.G7Core;(_?.state?.getLocal?.()??{}).form?.[`${a}_mode`]!=="html"&&_.state.setLocal({[`form.${a}_mode`]:"html"})}}catch(C){console.error("[sirsoft-ckeditor5] 에디터 초기화 오류:",C),E.delete(r),R({container:i,name:a,height:m,readOnly:d,placeholder:f,multilingual:!1,activeLocale:L(),initialContent:g}),N(`ckeditor5-editor:${r}`,x("editor.asset.label","편집기"),()=>Q(t,o,i,!1,a),x("editor.asset.fallback_notice","편집기를 불러오지 못했습니다. 임시 입력창으로 전환했습니다. 작성한 내용은 그대로 저장됩니다."))}}}async function Dt(t,o){const n=`ckeditor5-${t.params?.name??"content"}`;et(n);const r=E.get(n);if(!r||r.size===0)return;const l=[];r.forEach(s=>{s&&typeof s.destroy=="function"&&l.push(s.destroy().catch(k=>{console.warn("[sirsoft-ckeditor5] destroyEditor error:",k)}))}),await Promise.allSettled(l),E.delete(n);const i=document.getElementById(`ckeditor5-height-style-${n}`);i&&i.remove(),E.size===0&&At()}const y="sirsoft-ckeditor5",J="ckeditor5-content-styles",gt="ckeditor5-content-styles-override";function pt(){if(!document.getElementById(J)){const t=window?.G7Core?.asset;try{const o=nt();if(typeof t?.loadStylesheet=="function")t.loadStylesheet(o,{id:J},{label:"ckeditor5 content CSS"}).catch(e=>{console.warn("[ckeditor5] 본문 스타일 로드 실패:",e),window?.G7Core?.assets?.notifyFailure?.({id:"ckeditor5-content-css",label:"본문 스타일",retry:()=>pt()})});else{const e=document.createElement("link");e.id=J,e.rel="stylesheet",e.href=o,document.head.appendChild(e)}}catch(o){console.warn("[ckeditor5] 본문 스타일 URL 을 만들지 못했습니다:",o)}}if(!document.getElementById(gt)){const t=document.createElement("style");t.id=gt,t.textContent=`
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
        `,document.head.appendChild(t)}}const $={initEditor:mt,destroyEditor:Dt,injectContentCss:pt},U=window.G7Core?.createLogger?.(`Plugin:${y}`)??{log:(...t)=>console.log(`[Plugin:${y}]`,...t),warn:(...t)=>console.warn(`[Plugin:${y}]`,...t),error:(...t)=>console.error(`[Plugin:${y}]`,...t)};function ht(t=!1){const o=window.G7Core?.getActionDispatcher?.();if(o)Object.entries($).forEach(([e,n])=>{const r=`${y}.${e}`;o.registerHandler(r,n,{category:"plugin",source:y})}),U.log(`${Object.keys($).length} handler(s) registered:`,Object.keys($).map(e=>`${y}.${e}`));else if(t){let e=0;const n=50,r=()=>{const l=window.G7Core?.getActionDispatcher?.();l?(Object.entries($).forEach(([i,s])=>{const k=`${y}.${i}`;l.registerHandler(k,s,{category:"plugin",source:y})}),U.log(`${Object.keys($).length} handler(s) registered:`,Object.keys($).map(i=>`${y}.${i}`))):(e++,e<=n?(U.warn(`ActionDispatcher not found, retrying... (${e}/${n})`),setTimeout(r,100)):U.error("Failed to register handlers: ActionDispatcher not available after maximum retries"))};r()}else U.warn("ActionDispatcher not found, handlers not registered")}function X(){if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",()=>ht(!0));else{const t=!!window.G7Core?.getActionDispatcher?.();ht(!t)}}return X(),typeof window<"u"&&(window.__SirsoftCkeditor5={identifier:y,handlers:Object.keys($),initPlugin:X}),D.initPlugin=X,Object.defineProperty(D,Symbol.toStringTag,{value:"Module"}),D}({});
