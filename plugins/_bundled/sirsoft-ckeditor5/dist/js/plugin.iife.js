var SirsoftCkeditor5=function(P){"use strict";var Re=Object.defineProperty;var Ne=(P,w,T)=>w in P?Re(P,w,{enumerable:!0,configurable:!0,writable:!0,value:T}):P[w]=T;var Zt=(P,w,T)=>Ne(P,typeof w!="symbol"?w+"":w,T);const w=new Map;let T=!1;function Kt(){return T}function yt(t){T=t}const Q=new Map;function J(t,o){const e=Q.get(t)??[];e.push(o),Q.set(t,e)}function wt(t){(Q.get(t)??[]).forEach(o=>{try{o.stop()}catch{}}),Q.delete(t)}function te(t){const o=globalThis?.G7Core;if(typeof o?.url?.api=="function")return o.url.api(t);const e=globalThis?.G7Config?.basePath,n=typeof e=="string"&&e!==""&&e!=="/"?(e.startsWith("/")?e:"/"+e).replace(/\/+$/,""):"";return t=typeof t=="string"?t.trim():t,!n||!t.startsWith("/")||t.startsWith("//")||Ct(t,n)?t:n+t}function M(t){const o=globalThis?.G7Core;if(typeof o?.url?.route=="function")return o.url.route(t);const e=globalThis?.G7Config?.basePath,n=typeof e=="string"&&e!==""&&e!=="/"?(e.startsWith("/")?e:"/"+e).replace(/\/+$/,""):"";if(t=typeof t=="string"?t.trim():t,!n||typeof t!="string"||!Ct(t,n))return t;const r=t.slice(n.length);return r===""||r.startsWith("?")||r.startsWith("#")?"/"+r:r}function Ct(t,o){if(!t.startsWith(o))return!1;const e=t.charAt(o.length);return e===""||e==="/"||e==="?"||e==="#"}const ee=400,Et=1e4;function at(t,o){const{name:e,locale:n,multilingual:r}=o;let a=o.seedPath??M(location.pathname),l=null,i=!1,u=null,s,c=0;const f=()=>{if(o.readExternal)return o.readExternal();const g=window.G7Core?.state?.getLocal?.()?.form;if(!g||!e)return;const h=r?g[e]?.[n]:g[e];return typeof h=="string"?h:void 0};s=a!==M(location.pathname)&&o.seedValue!==void 0?o.seedValue:f();const p=d=>{M(location.pathname)!==a&&(a=M(location.pathname),u=new Set([d]),s!==void 0&&u.add(s),l!==null&&u.add(l),l=null,i=!0,c=Date.now())},b=window.setInterval(()=>{let d;try{d=t.getData()}catch{window.clearInterval(b);return}p(d);const g=f();if(g===void 0){i&&Date.now()-c>Et&&(i=!1,u=null);return}if(i){if(u?.has(g)){Date.now()-c>Et&&(i=!1,u=null,s=g);return}if(g!==d){yt(!0);try{t.setData(g)}finally{yt(!1)}}i=!1,u=null,l=g,s=g;return}if(s=g,g===l||g===d){l=g;return}},ee);return{shouldEmit:()=>{if(!i)try{p(t.getData())}catch{}return!i},noteEmitted:d=>{l=d},stop:()=>window.clearInterval(b)}}const oe="43.3.1",lt="sirsoft-ckeditor5",ct=`dist/vendor/ckeditor5/${oe}`,it="ckeditor5-vendor-css";function st(){const t=window?.G7Core?.asset;if(!t||typeof t.plugin!="function")throw new Error("G7Core.asset is unavailable — core 7.0.10 or later is required to resolve bundled CKEditor5 assets");return t}function ne(){return st().plugin(lt,`${ct}/ckeditor5.umd.js`)}function _t(){return st().plugin(lt,`${ct}/ckeditor5.css`)}function re(t){return st().plugin(lt,`${ct}/translations/${t}.umd.js`)}const St="ckeditor5-editor:",X="layout-script:ckeditor5_script",vt=500,ae=3e4;let Z=null;function Lt(){Z!==null&&(clearInterval(Z),Z=null)}function le(){const t=window?.G7Core?.assets;if(typeof t?.clearFailure!="function"||typeof t?.getFailures!="function")return;const o=()=>t.getFailures()??[],e=()=>{o().some(r=>r?.id===X)&&t.clearFailure(X)};e(),Lt();const n=Date.now();Z=setInterval(()=>{if(!o().some(a=>String(a?.id??"").startsWith(St))||Date.now()-n>=ae){Lt();return}e()},vt)}const ce=5e3;let z=null;function ie(){const t=window?.G7Core?.assets;if(typeof t?.clearFailure!="function"||typeof t?.getFailures!="function")return;const o=()=>{(t.getFailures()??[]).some(r=>r?.id===X)&&t.clearFailure(X)};o(),z!==null&&clearInterval(z);const e=Date.now();z=setInterval(()=>{if(Date.now()-e>=ce){z!==null&&(clearInterval(z),z=null);return}o()},vt)}function K(t,o,e,n){const r=window?.G7Core?.assets?.notifyFailure;if(typeof r=="function"){r({id:t,label:o,retry:e,message:n}),t.startsWith(St)&&le();return}console.warn(`[ckeditor5] 자산 로드 실패: ${o}`)}const se="ckeditor5-vendor-umd",de=15e3,ue=2500,fe=50;let W=null;function tt(){return typeof window.CKEDITOR?.ClassicEditor=="function"}function It(){return typeof window.CKEDITOR_VERSION=="string"}function dt(){return Array.from(document.querySelectorAll("script[src]")).filter(t=>(t.getAttribute("src")??"").toLowerCase().includes("ckeditor5.umd.js"))}function ge(t){return new Promise(o=>{let e=!1;const n=new Set,r=new Set;let a=null;const l=Date.now(),i=c=>{e||(e=!0,clearInterval(s),o(c))},u=c=>{n.has(c)||(n.add(c),c.addEventListener("load",()=>{tt()&&i("ready")}),c.addEventListener("error",()=>{r.add(c),a=Date.now()}))},s=setInterval(()=>{if(tt()){i("ready");return}dt().forEach(u);const c=dt().some(p=>!r.has(p)),f=Date.now();if(!c&&a!==null&&f-a>=ue){i("failed");return}f-l>=de&&i(c?"timeout":"failed")},fe);t.forEach(u)})}async function me(){const t=window?.G7Core?.asset?.loadScript;if(typeof t!="function")return!1;try{await t(ne(),{id:se},{label:"ckeditor5 UMD"})}catch(e){return console.warn("[ckeditor5] 편집기 본체를 불러오지 못했습니다:",e),!1}const o=tt();return o&&ie(),o}function xt(){return tt()?Promise.resolve(!0):W||(W=(async()=>{if(It())return console.warn("[ckeditor5] 편집기 본체가 두 번 실행되어 전역이 비었습니다 — 다시 싣지 않습니다."),!1;const t=dt();if(t.length>0){const o=await ge(t);if(o==="ready")return!0;if(o==="timeout"||It())return!1}return me()})().finally(()=>{W=null}),W)}const pe={ko:"한국어",en:"English",ja:"日本語",zh:"中文",fr:"Français",de:"Deutsch",es:"Español"};function Pt(t){const o=window,e=o.G7Core?.state?.get?.()?.appConfig?.localeNames?.[t],n=o.G7Config?.appConfig?.localeNames?.[t],r=typeof e=="string"&&e?e:n;return typeof r=="string"&&r!==""?r:pe[t]??t}function ke(t,o,e){t.replaceChildren();const n=document.createElement("i");n.className="ckeditor-icon ckeditor-icon--globe ckeditor-lang-icon",t.appendChild(n);const r=document.createElement("span");if(r.textContent=Pt(o),t.appendChild(r),e){const a=document.createElement("span");a.className="ckeditor-required",a.textContent="*",t.appendChild(a)}}const $t="data-readonly",Gt="read-only";function At(t,o){const e=t.getAttribute($t);return e==="true"?!0:e==="false"?!1:o}function be(t,o){const e=t;!e||typeof e.enableReadOnlyMode!="function"||typeof e.disableReadOnlyMode!="function"||(o?e.enableReadOnlyMode(Gt):e.disableReadOnlyMode(Gt))}function he(t,o){t.querySelectorAll("textarea").forEach(e=>{e.readOnly=o})}function ye(t,o,e){let n=null;const r=()=>{const l=At(t,e);if(l!==n){n=l;for(const i of o())be(i,l);he(t,l)}};if(r(),typeof MutationObserver>"u")return{stop:()=>{}};const a=new MutationObserver(r);return a.observe(t,{attributes:!0,attributeFilter:[$t]}),{stop:()=>a.disconnect()}}const we="upload",Ce=["jpeg","png","gif","webp"];function Mt(t,o){const e=t?.response?.data,n=e?.error?.message??e?.message;return typeof n=="string"&&n!==""?n:o}function Ee(t){const o="sirsoft-ckeditor5.editor.upload_failed",e=window?.G7Core?.t,n=typeof e=="function"?e(o):null;return(typeof n=="string"&&n!==o?n:"Couldn't upload file: {{name}}.").replace("{{name}}",t)}class _e{constructor(o,e){Zt(this,"controller",new AbortController);this.loader=o,this.uploadUrl=e}async upload(){const o=await this.loader.file,e=Ee(o?.name??""),n=window.G7Core?.api;if(!o||typeof n?.post!="function")throw e;const r=new FormData;r.append(we,o);let a;try{a=await n.post(this.uploadUrl,r,{headers:{"Content-Type":"multipart/form-data"},signal:this.controller.signal,onUploadProgress:i=>{typeof i.total=="number"&&(this.loader.uploadTotal=i.total),this.loader.uploaded=i.loaded}})}catch(i){throw Mt(i,e)}const l=a?.url??a?.urls?.default;if(typeof l!="string"||l==="")throw Mt({response:{data:a}},e);return{default:l}}abort(){this.controller.abort()}}function Se(t){return function(e){e.plugins.get("FileRepository").createUploadAdapter=n=>new _e(n,t)}}const et="data-ckeditor5-fallback",ot=new WeakMap;function nt(t,o,e,n){const r=window.G7Core;if(!r?.state?.setLocal||!t)return;e&&n&&r.state.getLocal?.()?.hasChanges!==!0&&r.state.setLocal({hasChanges:!0});const a={[`form.${t}_mode`]:"text"};if(typeof o=="string")a[`form.${t}`]=o;else for(const[l,i]of Object.entries(o))a[`form.${t}.${l}`]=i;r.state.setLocal(a,{render:!1,selfManaged:!0})}function ut(t){const{container:o,name:e,height:n,readOnly:r,placeholder:a,multilingual:l}=t;if(o.querySelector(`[${et}]`))return;o.innerHTML="";const i=t.trackChanges!==!1,u=l?t.locales??[]:[],s=t.activeLocale??u[0]??"",c=l?{...t.contentMap??{}}:{"":t.initialContent??""},f=document.createElement("textarea");f.setAttribute(et,"1"),f.name=e,f.readOnly=r,f.placeholder=a??"",f.style.cssText=["width:100%",`height:${n}px`,"padding:12px","border:1px solid #d1d5db","border-radius:6px","font-family:inherit","font-size:14px","line-height:1.6","resize:vertical","box-sizing:border-box"].join(";");let p=s;const b=new Map,d=()=>l?p:"";(l?u:[""]).forEach(h=>{const x=at({getData:()=>h===d()?f.value:c[h]??"",setData:v=>{c[h]=v,h===d()&&(f.value=v)}},{name:e,locale:h,multilingual:l,seedPath:t.seedPath,seedValue:c[h]??""});b.set(h,x),o.id&&J(o.id,x)});const g=()=>[...b.values()].every(h=>h.shouldEmit());if(l){const h=document.createElement("div");h.className="ckeditor-locale-tabs";const x=()=>{h.querySelectorAll("button").forEach(v=>{const G=v.dataset.locale??"",_=G===p;v.classList.toggle("ckeditor-locale-tab--active",_);const O=(c[G]??"").trim()!=="",D=v.querySelector("[data-check]");D&&(D.style.display=O?"":"none")})};u.forEach((v,G)=>{const _=document.createElement("button");_.type="button",_.dataset.locale=v,_.classList.add("ckeditor-locale-tab"),G===0&&_.classList.add("ckeditor-locale-tab--default"),_.textContent=Pt(v);const O=document.createElement("span");O.dataset.check="1",O.textContent="✓",O.style.display="none",_.appendChild(O),_.addEventListener("click",()=>{c[p]=f.value,p=v,f.value=c[v]??"",f.dataset.locale=v,g()&&nt(e,c,!1,i),x()}),h.appendChild(_)}),o.appendChild(h),f.dataset.locale=p,f.value=c[p]??"",x(),f.addEventListener("input",()=>{c[p]=f.value,g()&&(b.get(p)?.noteEmitted(f.value),nt(e,c,!0,i)),x()})}else f.value=c[""]??"",f.addEventListener("input",()=>{c[""]=f.value,g()&&(b.get("")?.noteEmitted(f.value),nt(e,f.value,!0,i))});o.appendChild(f),ot.set(o,{multilingual:l,values:c,activeLocale:p,syncs:b}),nt(e,l?c:c[""]??"",!1,i)}function ve(t){const o=ot.get(t);if(!o)return null;const e=t.querySelector(`textarea[${et}]`);return e&&(o.values[o.multilingual?e.dataset.locale??o.activeLocale:""]=e.value),o.multilingual?{...o.values}:o.values[""]??""}function Le(t){return t.querySelector(`[${et}]`)?(ot.get(t)?.syncs.forEach(o=>o.stop()),t.innerHTML="",ot.delete(t),!0):!1}const Ot="ckeditor5-dark-override",Dt="ckeditor5-tab-style",Tt={minimal:["bold","italic","underline","|","link","uploadImage","|","undo","redo"],standard:["heading","|","bold","italic","underline","strikethrough","|","alignment","|","link","blockQuote","|","bulletedList","numberedList","indent","outdent","|","insertTable","uploadImage","|","undo","redo"],full:["heading","|","fontSize","fontColor","fontBackgroundColor","|","bold","italic","underline","strikethrough","|","alignment","|","link","blockQuote","insertTable","uploadImage","mediaEmbed","horizontalLine","|","bulletedList","numberedList","indent","outdent","|","codeBlock","sourceEditing","|","undo","redo"]};function Ie(t){if(t==="en")return Promise.resolve();const o=`ckeditor5-translations-${t}`;if(document.getElementById(o))return Promise.resolve();const e=window?.G7Core?.asset?.loadScript;return new Promise(n=>{let r;try{r=re(t)}catch(l){console.warn("[ckeditor5] 번역 URL 을 만들지 못했습니다 (영어로 동작):",l),n();return}if(typeof e=="function"){e(r,{id:o},{label:`ckeditor5 translations: ${t}`,retries:1}).catch(l=>{console.warn(`[ckeditor5] 번역 로드 실패 (${t}) — 영어로 동작합니다:`,l)}).then(()=>n());return}const a=document.createElement("script");a.id=o,a.src=r,a.onload=()=>n(),a.onerror=()=>{console.warn(`[ckeditor5] 번역 로드 실패 (${t}) — 영어로 동작합니다.`),n()},document.head.appendChild(a)})}async function Ut(){if(document.getElementById(it))return;const t=window?.G7Core?.asset?.loadStylesheet;let o;try{o=_t()}catch(e){console.warn("[ckeditor5] CSS URL 을 만들지 못했습니다:",e);return}if(typeof t!="function"){const e=document.createElement("link");e.id=it,e.rel="stylesheet",e.href=o,document.head.appendChild(e);return}try{await t(o,{id:it},{label:"ckeditor5 CSS"})}catch(e){console.warn("[ckeditor5] CSS 로드 실패:",e),K("ckeditor5-css",U("editor.asset.style_label","편집기 스타일"),()=>Ut())}}async function ft(t,o,e,n,r){const a=ve(e);if(!await xt())throw new Error("[sirsoft-ckeditor5] 편집기 본체를 여전히 불러올 수 없습니다.");Le(e);const l={...t,params:{...t.params??{},content:n?a??{}:a??""}};if(await jt(l,o),!r)return;window.G7Core?.state?.setLocal?.({[`form.${r}_mode`]:"html"})}function U(t,o){const e=window?.G7Core?.t;if(typeof e!="function")return o;const n=`sirsoft-ckeditor5.${t}`,r=e(n);return typeof r=="string"&&r!==n?r:o}function xe(){if(document.getElementById(Dt))return;const t=document.createElement("style");t.id=Dt,t.textContent=`
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
    `,document.head.appendChild(t)}function Pe(){return document.documentElement.getAttribute("data-g7-color-scheme")==="dark"}function Ft(){const t=document.getElementById(Ot);if(!Pe()){t&&t.remove();return}if(t)return;const o=document.createElement("style");o.id=Ot,o.textContent=`
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
    `,document.head.appendChild(o)}let j=null;function $e(){j||(j=new MutationObserver(()=>{Ft()}),j.observe(document.documentElement,{attributes:!0,attributeFilter:["data-g7-color-scheme"]}))}function Ge(){j&&(j.disconnect(),j=null)}function Rt(){const t=window.G7Core;return t?.locale?.supported?t.locale.supported():[localStorage.getItem("g7_locale")||"ko"]}function $(){return window.G7Core?.locale?.current?.()??localStorage.getItem("g7_locale")??"ko"}function Ae(t,o,e){const n=[t.Essentials,t.Bold,t.Italic,t.Underline,t.Strikethrough,t.Link,t.Paragraph,t.Heading,t.BlockQuote,t.List,t.Alignment,t.Table,t.TableToolbar,t.TableProperties,t.TableCellProperties,t.Indent,t.IndentBlock,t.PasteFromOffice,t.GeneralHtmlSupport,t.Image,t.ImageResize,t.ImageStyle,t.ImageCaption,t.ImageToolbar];return o==="full"&&n.push(t.MediaEmbed,t.HorizontalLine,t.CodeBlock,t.SourceEditing,t.FontSize,t.FontColor,t.FontBackgroundColor),e&&n.push(t.ImageUpload),n}function Me(){return te("/api/plugins/sirsoft-ckeditor5/upload")}async function Nt(t,o){const e=window.CKEDITOR;if(!e)throw new Error("[sirsoft-ckeditor5] CKEDITOR 전역 객체를 찾을 수 없습니다. CDN 스크립트가 로드되었는지 확인하세요.");const n=Tt[o.toolbar]||Tt.standard,r=o.imageUpload?n:n.filter(s=>s!=="uploadImage"),a={initialData:o.initialContent,plugins:Ae(e,o.toolbar,o.imageUpload),toolbar:{items:r},table:{contentToolbar:["tableColumn","tableRow","mergeTableCells","|","tableProperties","tableCellProperties"],tableProperties:{defaultProperties:{borderStyle:"solid",borderColor:"#d1d5db",borderWidth:"1px",alignment:"left"}},tableCellProperties:{defaultProperties:{borderStyle:"solid",borderColor:"#d1d5db",borderWidth:"1px",padding:"8px"}}},image:{toolbar:["imageStyle:inline","imageStyle:alignLeft","imageStyle:alignCenter","imageStyle:alignRight","|","toggleImageCaption","|","resizeImage"],resizeOptions:[{name:"resizeImage:original",label:"Original",value:null},{name:"resizeImage:25",label:"25%",value:"25"},{name:"resizeImage:50",label:"50%",value:"50"},{name:"resizeImage:75",label:"75%",value:"75"}],resizeUnit:"%",upload:{types:[...Ce]}},indentBlock:{offset:40,unit:"px"},link:{defaultProtocol:"https://",decorators:{openInNewTab:{mode:"automatic",callback:s=>s.startsWith("http://")||s.startsWith("https://"),attributes:{target:"_blank",rel:"noopener noreferrer"}}}},htmlSupport:{allow:[{name:/.*/,attributes:!0,classes:!0,styles:!0}]},placeholder:o.placeholder,language:$()};o.imageUpload&&(a.extraPlugins=[Se(Me())]);const l=await e.ClassicEditor.create(t,a),i=`ckeditor5-height-style-${o.containerId}`,u=document.getElementById(i);if(u)u.textContent=`#${o.containerId} .ck-editor__editable { min-height: ${o.height}px !important; }`;else{const s=document.createElement("style");s.id=i,s.textContent=`#${o.containerId} .ck-editor__editable { min-height: ${o.height}px !important; }`,document.head.appendChild(s)}return o.readOnly&&l.enableReadOnlyMode("read-only"),l}function Oe(t,o,e,n,r){const a=document.createElement("div");a.className="ckeditor-locale-tabs";const l=new Map;o.forEach(s=>{const c=document.createElement("div");c.dataset.locale=s,s!==e&&(c.style.cssText="display:none;");const f=document.createElement("div");c.appendChild(f),l.set(s,f)});const i=()=>{r&&a.querySelectorAll("button").forEach(s=>{const c=s.dataset.locale;if(!c||s.classList.contains("ckeditor-locale-tab--active")){const d=s.querySelector(".ckeditor-check");d&&d.remove();return}const p=!!r(c)?.trim(),b=s.querySelector(".ckeditor-check");if(p&&!b){const d=document.createElement("i");d.className="ckeditor-icon ckeditor-icon--check ckeditor-check",s.appendChild(d)}else!p&&b&&b.remove()})},u=o[0];return o.forEach(s=>{const c=s===u,f=s===e,p=document.createElement("button");p.type="button",p.dataset.locale=s,p.className=["ckeditor-locale-tab",f?"ckeditor-locale-tab--active":"",c?"ckeditor-locale-tab--default":""].filter(Boolean).join(" "),ke(p,s,c),p.addEventListener("click",async()=>{a.querySelectorAll("button").forEach(b=>{const g=b.dataset.locale===s;b.classList.toggle("ckeditor-locale-tab--active",g)}),l.forEach((b,d)=>{const g=b.parentElement;g&&(g.style.display=d===s?"block":"none")}),n&&await n(s),i()}),a.appendChild(p)}),t.appendChild(a),l.forEach(s=>{s.parentElement&&t.appendChild(s.parentElement)}),{tabsEl:a,contentEls:l,updateCheckIcons:i}}function zt(t,o,e,n,r=!0,a=null){if(Kt())return;const l=window.G7Core;if(!l?.state?.setLocal)return;if(r&&l.state.getLocal?.()?.hasChanges!==!0&&l.state.setLocal({hasChanges:!0}),a&&a.scope==="global"){const s=n?`${a.path}.${o}`:a.path;window.G7Core?.state?.set?.({[s]:e});return}const i=a?.path??`form.${t}`,u={[`form.${t}_mode`]:"html"};n?u[`${i}.${o}`]=e:u[i]=e,l.state.setLocal(u,{debounce:300,debounceKey:`ckeditor-sync-${t}`,render:!1,selfManaged:!0})}function gt(t,o){const e=window.G7Core,n=typeof t.content=="string"?t.content:"";let a=n.startsWith("{{")&&n.endsWith("}}")?"":n;if(!a&&o){const i=(e?.state?.getLocal?.()??{})?.form?.[o];typeof i=="string"&&i&&(a=i)}return a}function mt(t,o){const e=window.G7Core;let n={};try{typeof t.content=="string"&&t.content.startsWith("{")&&!t.content.startsWith("{{")?n=JSON.parse(t.content):typeof t.content=="object"&&t.content!==null&&(n=t.content)}catch{n={}}if(Object.keys(n).length===0&&o){const a=(e?.state?.getLocal?.()??{})?.form?.[o];if(a&&typeof a=="object"&&!Array.isArray(a)?n=a:typeof a=="string"&&a&&(n={[$()]:a}),Object.keys(n).length===0){const l=e?.state?.get?.()??{},u=(l?._global?.selectedItem??l?.selectedItem)?.[o];u&&typeof u=="object"&&!Array.isArray(u)&&(n=u)}}return n}async function jt(t,o){const e=t.params??{},n=e.name??"content",r=`ckeditor5-${n}`,a=M(location.pathname),l=e.multilingual===!0||e.multilingual==="true",i=l?"":gt(e,n),u=l?mt(e,n):{};wt(r);const s=w.get(r);if(s&&s.size>0){const y=[];s.forEach(k=>{k&&typeof k.destroy=="function"&&y.push(k.destroy().catch(()=>{}))}),await Promise.allSettled(y),w.delete(r)}const c=document.getElementById(r);if(!c){console.warn(`[sirsoft-ckeditor5] 컨테이너 엘리먼트를 찾을 수 없습니다: #${r}`);return}Ut(),xe(),Ft(),$e(),await Ie($());const p=window.G7Core?.state?.get()||{},b=p._global?.plugins?.["sirsoft-ckeditor5"]??p.plugins?.["sirsoft-ckeditor5"]??{},d=e.name??"content",g=e.multilingual===!0||e.multilingual==="true",h=e.readOnly===!0||e.readOnly==="true",x=()=>At(c,h),v=e.imageUpload!==void 0?e.imageUpload===!0||e.imageUpload==="true":b.imageUpload===!0||b.imageUpload==="true",G=e.placeholder??"",_=e.height!==void 0?Number(e.height)||400:Number(b.editorHeight)||400,O=(e.toolbar!==void 0?e.toolbar:b.toolbar)??"standard",D=!(e.trackChanges===!1||e.trackChanges==="false"),Te=e.writeScope==="global"?"global":"local",qt=typeof e.writePath=="string"&&e.writePath.trim()!==""&&!e.writePath.includes("{{")?e.writePath.trim():null,A=qt===null?null:{scope:Te,path:qt},bt=(y,k)=>{if(!A)return;const E=window.G7Core,m=A.scope==="global"?E?.state?.get?.():E?.state?.getLocal?.(),R=(k?`${A.path}.${y}`:A.path).split(".").reduce((N,L)=>N?.[L],m);return typeof R=="string"?R:void 0},Ue=(y,k)=>{if(A)return bt(y,k);const E=window.G7Core?.state?.getLocal?.()?.form,m=k?E?.[d]?.[y]:E?.[d];return typeof m=="string"?m:void 0},rt=(y,k,E,m)=>{if(M(location.pathname)===a)return{value:E(),path:a};const S=Ue(y,k)??"";return S!==m?{value:S,path:M(location.pathname)}:{value:S,path:a}};let Ht=g?mt(e,d):{};const Fe=await xt();if(!c.isConnected)return;if(J(r,ye(c,()=>w.get(r)?.values()??[],h)),!Fe){const y=g?Rt():[],k=g?y.map(m=>{const S=mt(e,d);return{locale:m,...rt(m,!0,()=>S[m]??"",u[m]??"")}}):[{locale:"",...rt($(),!1,()=>gt(e,d),i)}],E={container:c,name:d,height:_,readOnly:x(),placeholder:G,trackChanges:D,multilingual:g,locales:g?y:void 0,activeLocale:$(),contentMap:g?Object.fromEntries(k.map(m=>[m.locale,m.value])):void 0,initialContent:g?void 0:k[0].value,seedPath:k.find(m=>m.path!==M(location.pathname))?.path};ut(E),K(`ckeditor5-editor:${r}`,U("editor.asset.label","편집기"),()=>ft(t,o,c,E.multilingual,d),U("editor.asset.fallback_notice","편집기를 불러오지 못했습니다. 임시 입력창으로 전환했습니다. 작성한 내용은 그대로 저장됩니다."));return}const B=new Map;w.set(r,B);const Yt={placeholder:G,get readOnly(){return x()},imageUpload:v,height:_,toolbar:O,containerId:r};if(g){const y=Rt(),k=$(),E={current:new Map},m={current:()=>{}},S=async C=>{if(B.has(C))return;const H=E.current.get(C);if(!H)return;const Qt=rt(C,!0,()=>Ht[C]??"",u[C]??""),Jt=Qt.value;try{const V=await Nt(H,{...Yt,initialContent:Jt});B.set(C,V);let Xt=!0;const ht=at(V,{name:d,locale:C,multilingual:!0,readExternal:A?()=>bt(C,!0):void 0,seedPath:Qt.path,seedValue:Jt});if(J(r,ht),V.model.document.on("change:data",()=>{if(Xt||!ht.shouldEmit())return;const Y=V.getData();ht.noteEmitted(Y),zt(d,C,Y,!0,D,A),m.current()}),Xt=!1,d){const Y=window.G7Core;(Y?.state?.getLocal?.()??{}).form?.[`${d}_mode`]!=="html"&&Y.state.setLocal({[`form.${d}_mode`]:"html"})}}catch(V){console.error(`[sirsoft-ckeditor5] 에디터 초기화 오류 (locale: ${C}):`,V)}},R=C=>{const H=B.get(C);return H?H.getData?.()??null:null},{contentEls:N,updateCheckIcons:L}=Oe(c,y,k,S,R);if(E.current=N,m.current=L,await S(k),!B.has(k)){w.delete(r),ut({container:c,name:d,height:_,readOnly:x(),placeholder:G,multilingual:!0,locales:y,activeLocale:k,contentMap:Ht,trackChanges:D}),K(`ckeditor5-editor:${r}`,U("editor.asset.label","편집기"),()=>ft(t,o,c,!0,d),U("editor.asset.fallback_notice","편집기를 불러오지 못했습니다. 임시 입력창으로 전환했습니다. 작성한 내용은 그대로 저장됩니다."));return}setTimeout(()=>{y.filter(C=>C!==k).forEach(C=>{S(C)})},2e3)}else{const y=rt($(),!1,()=>gt(e,d),i),k=y.value,E=document.createElement("div");c.appendChild(E);try{const m=await Nt(E,{...Yt,initialContent:k}),S=$();B.set(S,m);let R=!0;const N=at(m,{name:d,locale:S,multilingual:!1,readExternal:A?()=>bt(S,!1):void 0,seedPath:y.path,seedValue:k});if(J(r,N),m.model.document.on("change:data",()=>{if(R||!N.shouldEmit())return;const L=m.getData();N.noteEmitted(L),zt(d,S,L,!1,D,A)}),R=!1,k){const L=m.getData();(!L||L.trim()==="")&&m.setData(k)}if(d){const L=window.G7Core;(L?.state?.getLocal?.()??{}).form?.[`${d}_mode`]!=="html"&&L.state.setLocal({[`form.${d}_mode`]:"html"})}}catch(m){console.error("[sirsoft-ckeditor5] 에디터 초기화 오류:",m),w.delete(r),ut({container:c,name:d,height:_,readOnly:x(),placeholder:G,multilingual:!1,activeLocale:$(),initialContent:k,trackChanges:D,seedPath:y.path}),K(`ckeditor5-editor:${r}`,U("editor.asset.label","편집기"),()=>ft(t,o,c,!1,d),U("editor.asset.fallback_notice","편집기를 불러오지 못했습니다. 임시 입력창으로 전환했습니다. 작성한 내용은 그대로 저장됩니다."))}}}async function De(t,o){const n=`ckeditor5-${t.params?.name??"content"}`;wt(n);const r=w.get(n);if(!r||r.size===0)return;const a=[];r.forEach(i=>{i&&typeof i.destroy=="function"&&a.push(i.destroy().catch(u=>{console.warn("[sirsoft-ckeditor5] destroyEditor error:",u)}))}),await Promise.allSettled(a),w.delete(n);const l=document.getElementById(`ckeditor5-height-style-${n}`);l&&l.remove(),w.size===0&&Ge()}const I="sirsoft-ckeditor5",pt="ckeditor5-content-styles",Bt="ckeditor5-content-styles-override";function Vt(){if(!document.getElementById(pt)){const t=window?.G7Core?.asset;try{const o=_t();if(typeof t?.loadStylesheet=="function")t.loadStylesheet(o,{id:pt},{label:"ckeditor5 content CSS"}).catch(e=>{console.warn("[ckeditor5] 본문 스타일 로드 실패:",e),window?.G7Core?.assets?.notifyFailure?.({id:"ckeditor5-content-css",label:"본문 스타일",retry:()=>Vt()})});else{const e=document.createElement("link");e.id=pt,e.rel="stylesheet",e.href=o,document.head.appendChild(e)}}catch(o){console.warn("[ckeditor5] 본문 스타일 URL 을 만들지 못했습니다:",o)}}if(!document.getElementById(Bt)){const t=document.createElement("style");t.id=Bt,t.textContent=`
            /* 이미지 캡션 - 다크모드 */
            html[data-g7-color-scheme="dark"] .ck-content .image > figcaption { color: hsl(0, 0%, 80%); background-color: hsl(0, 0%, 15%); }

            /* CKEditor5 표 스타일 - 다크모드 */
            html[data-g7-color-scheme="dark"] .ck-content figure.table table { border-color: hsl(0, 0%, 35%); }
            html[data-g7-color-scheme="dark"] .ck-content figure.table table tr { background-color: unset; }
            html[data-g7-color-scheme="dark"] .ck-content figure.table table td, html[data-g7-color-scheme="dark"] .ck-content figure.table table th { border-color: hsl(0, 0%, 30%); color: hsl(0, 0%, 90%); background-color: unset; }
            html[data-g7-color-scheme="dark"] .ck-content figure.table table th { background-color: hsl(215, 20%, 25%); }

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
        `,document.head.appendChild(t)}}const F={initEditor:jt,destroyEditor:De,injectContentCss:Vt},q=window.G7Core?.createLogger?.(`Plugin:${I}`)??{log:(...t)=>console.log(`[Plugin:${I}]`,...t),warn:(...t)=>console.warn(`[Plugin:${I}]`,...t),error:(...t)=>console.error(`[Plugin:${I}]`,...t)};function Wt(t=!1){const o=window.G7Core?.getActionDispatcher?.();if(o)Object.entries(F).forEach(([e,n])=>{const r=`${I}.${e}`;o.registerHandler(r,n,{category:"plugin",source:I})}),q.log(`${Object.keys(F).length} handler(s) registered:`,Object.keys(F).map(e=>`${I}.${e}`));else if(t){let e=0;const n=50,r=()=>{const a=window.G7Core?.getActionDispatcher?.();a?(Object.entries(F).forEach(([l,i])=>{const u=`${I}.${l}`;a.registerHandler(u,i,{category:"plugin",source:I})}),q.log(`${Object.keys(F).length} handler(s) registered:`,Object.keys(F).map(l=>`${I}.${l}`))):(e++,e<=n?(q.warn(`ActionDispatcher not found, retrying... (${e}/${n})`),setTimeout(r,100)):q.error("Failed to register handlers: ActionDispatcher not available after maximum retries"))};r()}else q.warn("ActionDispatcher not found, handlers not registered")}function kt(){if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",()=>Wt(!0));else{const t=!!window.G7Core?.getActionDispatcher?.();Wt(!t)}}return kt(),typeof window<"u"&&(window.__SirsoftCkeditor5={identifier:I,handlers:Object.keys(F),initPlugin:kt}),P.initPlugin=kt,Object.defineProperty(P,Symbol.toStringTag,{value:"Module"}),P}({});
