var SirsoftCkeditor5=function(G){"use strict";const E=new Map,Y="https://cdn.ckeditor.com/ckeditor5/43.3.1/ckeditor5.css",Z="https://cdn.ckeditor.com/ckeditor5/43.3.1/translations",N="ckeditor5-cdn-css",H="ckeditor5-dark-override",D="ckeditor5-tab-style",O={minimal:["bold","italic","underline","|","link","uploadImage","|","undo","redo"],standard:["heading","|","bold","italic","underline","strikethrough","|","alignment","|","link","blockQuote","|","bulletedList","numberedList","indent","outdent","|","insertTable","uploadImage","|","undo","redo"],full:["heading","|","fontSize","fontColor","fontBackgroundColor","|","bold","italic","underline","strikethrough","|","alignment","|","link","blockQuote","insertTable","uploadImage","mediaEmbed","horizontalLine","|","bulletedList","numberedList","indent","outdent","|","codeBlock","sourceEditing","|","undo","redo"]};function R(t){if(t==="en")return Promise.resolve();const o=`ckeditor5-translations-${t}`;return document.getElementById(o)?Promise.resolve():new Promise(e=>{const r=document.createElement("script");r.id=o,r.src=`${Z}/${t}.umd.js`,r.onload=()=>e(),r.onerror=()=>e(),document.head.appendChild(r)})}function K(){if(document.getElementById(N))return;const t=document.createElement("link");t.id=N,t.rel="stylesheet",t.href=Y,document.head.appendChild(t)}function tt(){if(document.getElementById(D))return;const t=document.createElement("style");t.id=D,t.textContent=`
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
    `,document.head.appendChild(t)}function et(){return document.documentElement.classList.contains("dark")}function q(){const t=document.getElementById(H);if(!et()){t&&t.remove();return}if(t)return;const o=document.createElement("style");o.id=H,o.textContent=`
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
    `,document.head.appendChild(o)}let x=null;function ot(){x||(x=new MutationObserver(()=>{q()}),x.observe(document.documentElement,{attributes:!0,attributeFilter:["class"]}))}function nt(){x&&(x.disconnect(),x=null)}const rt={ko:"한국어",en:"English",ja:"日本語",zh:"中文",fr:"Français",de:"Deutsch",es:"Español"};function ct(t){return rt[t]??t.toUpperCase()}function lt(){const t=window.G7Core;return t?.locale?.supported?t.locale.supported():[localStorage.getItem("g7_locale")||"ko"]}function I(){return window.G7Core?.locale?.current?.()??localStorage.getItem("g7_locale")??"ko"}function at(t,o,e){const r=[t.Essentials,t.Bold,t.Italic,t.Underline,t.Strikethrough,t.Link,t.Paragraph,t.Heading,t.BlockQuote,t.List,t.Alignment,t.Table,t.TableToolbar,t.TableProperties,t.TableCellProperties,t.Indent,t.IndentBlock,t.PasteFromOffice,t.GeneralHtmlSupport,t.Image,t.ImageResize,t.ImageStyle,t.ImageCaption,t.ImageToolbar];return o==="full"&&r.push(t.MediaEmbed,t.HorizontalLine,t.CodeBlock,t.SourceEditing,t.FontSize,t.FontColor,t.FontBackgroundColor),e&&r.push(t.ImageUpload,t.SimpleUploadAdapter),r}function it(t){const o="/api/plugins/sirsoft-ckeditor5/upload";return t?`${o}?permission=${encodeURIComponent(t)}`:o}function dt(){const t=localStorage.getItem("auth_token");return t?{Authorization:`Bearer ${t}`}:{}}async function F(t,o){const e=window.CKEDITOR;if(!e)throw new Error("[sirsoft-ckeditor5] CKEDITOR 전역 객체를 찾을 수 없습니다. CDN 스크립트가 로드되었는지 확인하세요.");const r=O[o.toolbar]||O.standard,c=o.imageUpload?r:r.filter(n=>n!=="uploadImage"),l={initialData:o.initialContent,plugins:at(e,o.toolbar,o.imageUpload),toolbar:{items:c},table:{contentToolbar:["tableColumn","tableRow","mergeTableCells","|","tableProperties","tableCellProperties"],tableProperties:{defaultProperties:{borderStyle:"solid",borderColor:"#d1d5db",borderWidth:"1px",alignment:"left"}},tableCellProperties:{defaultProperties:{borderStyle:"solid",borderColor:"#d1d5db",borderWidth:"1px",padding:"8px"}}},image:{toolbar:["imageStyle:inline","imageStyle:alignLeft","imageStyle:alignCenter","imageStyle:alignRight","|","toggleImageCaption","|","resizeImage"],resizeOptions:[{name:"resizeImage:original",label:"Original",value:null},{name:"resizeImage:25",label:"25%",value:"25"},{name:"resizeImage:50",label:"50%",value:"50"},{name:"resizeImage:75",label:"75%",value:"75"}],resizeUnit:"%"},indentBlock:{offset:40,unit:"px"},link:{defaultProtocol:"https://",decorators:{openInNewTab:{mode:"automatic",callback:n=>n.startsWith("http://")||n.startsWith("https://"),attributes:{target:"_blank",rel:"noopener noreferrer"}}}},htmlSupport:{allow:[{name:/.*/,attributes:!0,classes:!0,styles:!0}]},placeholder:o.placeholder,language:I()};o.imageUpload&&(l.simpleUpload={uploadUrl:it(o.uploadPermission),headers:dt()});const i=await e.ClassicEditor.create(t,l),s=`ckeditor5-height-style-${o.containerId}`,b=document.getElementById(s);if(b)b.textContent=`#${o.containerId} .ck-editor__editable { min-height: ${o.height}px !important; }`;else{const n=document.createElement("style");n.id=s,n.textContent=`#${o.containerId} .ck-editor__editable { min-height: ${o.height}px !important; }`,document.head.appendChild(n)}return o.readOnly&&i.enableReadOnlyMode("read-only"),i}function st(t,o,e,r,c){const l=document.createElement("div");l.className="ckeditor5-locale-tabs";const i=new Map;o.forEach(n=>{const a=document.createElement("div");a.dataset.locale=n,n!==e&&(a.style.cssText="display:none;");const C=document.createElement("div");a.appendChild(C),i.set(n,C)});const s=()=>{c&&l.querySelectorAll("button").forEach(n=>{const a=n.dataset.locale;if(!a||n.classList.contains("is-active")){const h=n.querySelector(".ck5-check");h&&h.remove();return}const g=!!c(a)?.trim(),p=n.querySelector(".ck5-check");if(g&&!p){const h=document.createElement("i");h.className="fas fa-check ck5-check",n.appendChild(h)}else!g&&p&&p.remove()})},b=o[0];return o.forEach(n=>{const a=n===b,C=n===e,g=document.createElement("button");g.type="button",g.dataset.locale=n,g.className=[C?"is-active":"",a?"is-default":""].filter(Boolean).join(" "),g.innerHTML=`<i class="fas fa-globe ck5-lang-icon"></i><span>${ct(n)}</span>${a?'<span class="ck5-required">*</span>':""}`,g.addEventListener("click",async()=>{l.querySelectorAll("button").forEach(p=>{const L=p.dataset.locale===n;p.classList.toggle("is-active",L)}),i.forEach((p,h)=>{const L=p.parentElement;L&&(L.style.display=h===n?"block":"none")}),r&&await r(n),s()}),l.appendChild(g)}),t.appendChild(l),i.forEach(n=>{n.parentElement&&t.appendChild(n.parentElement)}),{tabsEl:l,contentEls:i,updateCheckIcons:s}}function T(t,o,e,r){const c=window.G7Core;if(!c?.state?.setLocal)return;const l={[`form.${t}_mode`]:"html",hasChanges:!0};r?l[`form.${t}.${o}`]=e:l[`form.${t}`]=e,c.state.setLocal(l,{debounce:300,debounceKey:`ckeditor-sync-${t}`,render:!1,selfManaged:!0})}async function ut(t,o){const e=t.params??{},c=`ckeditor5-${e.name??"content"}`,l=E.get(c);if(l&&l.size>0){const y=[];l.forEach(u=>{u&&typeof u.destroy=="function"&&y.push(u.destroy().catch(()=>{}))}),await Promise.allSettled(y),E.delete(c)}const i=document.getElementById(c);if(!i){console.warn(`[sirsoft-ckeditor5] 컨테이너 엘리먼트를 찾을 수 없습니다: #${c}`);return}K(),tt(),q(),ot(),await R(I());const s=window.G7Core,b=s?.state?.get()||{},n=b._global?.plugins?.["sirsoft-ckeditor5"]??b.plugins?.["sirsoft-ckeditor5"]??{},a=e.name??"content",C=e.multilingual===!0||e.multilingual==="true",g=e.readOnly===!0||e.readOnly==="true",p=e.imageUpload!==void 0?e.imageUpload===!0||e.imageUpload==="true":n.imageUpload===!0||n.imageUpload==="true",h=e.uploadPermission??"",L=e.placeholder??"",bt=e.height!==void 0?Number(e.height)||400:Number(n.editorHeight)||400,gt=(e.toolbar!==void 0?e.toolbar:n.toolbar)??"standard";let _={};if(C){try{typeof e.content=="string"&&e.content.startsWith("{")&&!e.content.startsWith("{{")?_=JSON.parse(e.content):typeof e.content=="object"&&e.content!==null&&(_=e.content)}catch{_={}}if(Object.keys(_).length===0&&a){const u=(s?.state?.getLocal?.()??{})?.form?.[a];if(u&&typeof u=="object"&&!Array.isArray(u)?_=u:typeof u=="string"&&u&&(_={[I()]:u}),Object.keys(_).length===0){const k=s?.state?.get?.()??{},d=(k?._global?.selectedItem??k?.selectedItem)?.[a];d&&typeof d=="object"&&!Array.isArray(d)&&(_=d)}}}const P=new Map;E.set(c,P);const J={placeholder:L,readOnly:g,imageUpload:p,uploadPermission:h,height:bt,toolbar:gt,containerId:c};if(C){const y=lt(),u=I(),k={current:new Map},$={current:()=>{}},d=async f=>{if(P.has(f))return;const U=k.current.get(f);if(!U)return;const pt=_[f]??"";try{const A=await F(U,{...J,initialContent:pt});P.set(f,A);let X=!0;if(A.model.document.on("change:data",()=>{if(X)return;const M=A.getData();T(a,f,M,!0),$.current()}),X=!1,a){const M=window.G7Core;(M?.state?.getLocal?.()??{}).form?.[`${a}_mode`]!=="html"&&M.state.setLocal({[`form.${a}_mode`]:"html"})}}catch(A){console.error(`[sirsoft-ckeditor5] 에디터 초기화 오류 (locale: ${f}):`,A)}},S=f=>{const U=P.get(f);return U?U.getData?.()??null:null},{contentEls:B,updateCheckIcons:w}=st(i,y,u,d,S);k.current=B,$.current=w,await d(u),setTimeout(()=>{y.filter(f=>f!==u).forEach(f=>{d(f)})},2e3)}else{const y=typeof e.content=="string"?e.content:"";let k=y.startsWith("{{")&&y.endsWith("}}")?"":y;if(!k&&a){const S=(s?.state?.getLocal?.()??{})?.form?.[a];typeof S=="string"&&S&&(k=S)}const $=document.createElement("div");i.appendChild($);try{const d=await F($,{...J,initialContent:k}),S=I();P.set(S,d);let B=!0;if(d.model.document.on("change:data",()=>{if(B)return;const w=d.getData();T(a,S,w,!1)}),B=!1,k){const w=d.getData();(!w||w.trim()==="")&&d.setData(k)}if(a){const w=window.G7Core;(w?.state?.getLocal?.()??{}).form?.[`${a}_mode`]!=="html"&&w.state.setLocal({[`form.${a}_mode`]:"html"})}}catch(d){console.error("[sirsoft-ckeditor5] 에디터 초기화 오류:",d),E.delete(c)}}}async function ft(t,o){const r=`ckeditor5-${t.params?.name??"content"}`,c=E.get(r);if(!c||c.size===0)return;const l=[];c.forEach(s=>{s&&typeof s.destroy=="function"&&l.push(s.destroy().catch(b=>{console.warn("[sirsoft-ckeditor5] destroyEditor error:",b)}))}),await Promise.allSettled(l),E.delete(r);const i=document.getElementById(`ckeditor5-height-style-${r}`);i&&i.remove(),E.size===0&&nt()}const m="sirsoft-ckeditor5",W="ckeditor5-content-styles",V="ckeditor5-content-styles-override",kt="https://cdn.ckeditor.com/ckeditor5/43.3.1/ckeditor5.css";function mt(){if(!document.getElementById(W)){const t=document.createElement("link");t.id=W,t.rel="stylesheet",t.href=kt,document.head.appendChild(t)}if(!document.getElementById(V)){const t=document.createElement("style");t.id=V,t.textContent=`
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
        `,document.head.appendChild(t)}}const v={initEditor:ut,destroyEditor:ft,injectContentCss:mt},z=window.G7Core?.createLogger?.(`Plugin:${m}`)??{log:(...t)=>console.log(`[Plugin:${m}]`,...t),warn:(...t)=>console.warn(`[Plugin:${m}]`,...t),error:(...t)=>console.error(`[Plugin:${m}]`,...t)};function Q(t=!1){const o=window.G7Core?.getActionDispatcher?.();if(o)Object.entries(v).forEach(([e,r])=>{const c=`${m}.${e}`;o.registerHandler(c,r,{category:"plugin",source:m})}),z.log(`${Object.keys(v).length} handler(s) registered:`,Object.keys(v).map(e=>`${m}.${e}`));else if(t){let e=0;const r=50,c=()=>{const l=window.G7Core?.getActionDispatcher?.();l?(Object.entries(v).forEach(([i,s])=>{const b=`${m}.${i}`;l.registerHandler(b,s,{category:"plugin",source:m})}),z.log(`${Object.keys(v).length} handler(s) registered:`,Object.keys(v).map(i=>`${m}.${i}`))):(e++,e<=r?(z.warn(`ActionDispatcher not found, retrying... (${e}/${r})`),setTimeout(c,100)):z.error("Failed to register handlers: ActionDispatcher not available after maximum retries"))};c()}else z.warn("ActionDispatcher not found, handlers not registered")}function j(){if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",()=>Q(!0));else{const t=!!window.G7Core?.getActionDispatcher?.();Q(!t)}}return j(),typeof window<"u"&&(window.__SirsoftCkeditor5={identifier:m,handlers:Object.keys(v),initPlugin:j}),G.initPlugin=j,Object.defineProperty(G,Symbol.toStringTag,{value:"Module"}),G}({});
//# sourceMappingURL=plugin.iife.js.map
