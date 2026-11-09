var SirsoftCkeditor5=function(G){"use strict";var Ze=Object.defineProperty;var Ke=(G,w,U)=>w in G?Ze(G,w,{enumerable:!0,configurable:!0,writable:!0,value:U}):G[w]=U;var wt=(G,w,U)=>Ke(G,typeof w!="symbol"?w+"":w,U);const w=new Map;let U=!1;function le(){return U}function Ct(t){U=t}const Q=new Map;function J(t,e){const o=Q.get(t)??[];o.push(e),Q.set(t,o)}function vt(t){(Q.get(t)??[]).forEach(e=>{try{e.stop()}catch{}}),Q.delete(t)}const ie=400,_t=1e4;function rt(t,e){const{name:o,locale:r,multilingual:n}=e;let a=e.seedPath??location.pathname,l=null,i=!1,u=null,s,c=0;const f=()=>{if(e.readExternal)return e.readExternal();const m=window.G7Core?.state?.getLocal?.()?.form;if(!m||!o)return;const p=n?m[o]?.[r]:m[o];return typeof p=="string"?p:void 0};s=a!==location.pathname&&e.seedValue!==void 0?e.seedValue:f();const g=d=>{location.pathname!==a&&(a=location.pathname,u=new Set([d]),s!==void 0&&u.add(s),l!==null&&u.add(l),l=null,i=!0,c=Date.now())},k=window.setInterval(()=>{let d;try{d=t.getData()}catch{window.clearInterval(k);return}g(d);const m=f();if(m===void 0){i&&Date.now()-c>_t&&(i=!1,u=null);return}if(i){if(u?.has(m)){Date.now()-c>_t&&(i=!1,u=null,s=m);return}if(m!==d){Ct(!0);try{t.setData(m)}finally{Ct(!1)}}i=!1,u=null,l=m,s=m;return}if(s=m,m===l||m===d){l=m;return}},ie);return{shouldEmit:()=>{if(!i)try{g(t.getData())}catch{}return!i},noteEmitted:d=>{l=d},stop:()=>window.clearInterval(k)}}const ce="43.3.1",at="sirsoft-ckeditor5",lt=`dist/vendor/ckeditor5/${ce}`,it="ckeditor5-vendor-css";function ct(){const t=window?.G7Core?.asset;if(!t||typeof t.plugin!="function")throw new Error("G7Core.asset is unavailable — core 7.0.10 or later is required to resolve bundled CKEditor5 assets");return t}function se(){return ct().plugin(at,`${lt}/ckeditor5.umd.js`)}function Et(){return ct().plugin(at,`${lt}/ckeditor5.css`)}function de(t){return ct().plugin(at,`${lt}/translations/${t}.umd.js`)}const ue="ckeditor5-vendor-umd",fe=15e3,pe=2500,me=50;let V=null;function X(){return typeof window.CKEDITOR?.ClassicEditor=="function"}function St(){return typeof window.CKEDITOR_VERSION=="string"}function st(){return Array.from(document.querySelectorAll("script[src]")).filter(t=>(t.getAttribute("src")??"").toLowerCase().includes("ckeditor5.umd.js"))}function ge(t){return new Promise(e=>{let o=!1;const r=new Set,n=new Set;let a=null;const l=Date.now(),i=c=>{o||(o=!0,clearInterval(s),e(c))},u=c=>{r.has(c)||(r.add(c),c.addEventListener("load",()=>{X()&&i("ready")}),c.addEventListener("error",()=>{n.add(c),a=Date.now()}))},s=setInterval(()=>{if(X()){i("ready");return}st().forEach(u);const c=st().some(g=>!n.has(g)),f=Date.now();if(!c&&a!==null&&f-a>=pe){i("failed");return}f-l>=fe&&i(c?"timeout":"failed")},me);t.forEach(u)})}async function ke(){const t=window?.G7Core?.asset?.loadScript;if(typeof t!="function")return!1;try{await t(se(),{id:ue},{label:"ckeditor5 UMD"})}catch(e){return console.warn("[ckeditor5] 편집기 본체를 불러오지 못했습니다:",e),!1}return X()}function xt(){return X()?Promise.resolve(!0):V||(V=(async()=>{if(St())return console.warn("[ckeditor5] 편집기 본체가 두 번 실행되어 전역이 비었습니다 — 다시 싣지 않습니다."),!1;const t=st();if(t.length>0){const e=await ge(t);if(e==="ready")return!0;if(e==="timeout"||St())return!1}return ke()})().finally(()=>{V=null}),V)}const be={ko:"한국어",en:"English",ja:"日本語",zh:"中文",fr:"Français",de:"Deutsch",es:"Español"};function Lt(t){const e=window,o=e.G7Core?.state?.get?.()?.appConfig?.localeNames?.[t],r=e.G7Config?.appConfig?.localeNames?.[t],n=typeof o=="string"&&o?o:r;return typeof n=="string"&&n!==""?n:be[t]??t}function he(t,e,o){t.replaceChildren();const r=document.createElement("i");r.className="fas fa-globe ck5-lang-icon",t.appendChild(r);const n=document.createElement("span");if(n.textContent=Lt(e),t.appendChild(n),o){const a=document.createElement("span");a.className="ck5-required",a.textContent="*",t.appendChild(a)}}const It="ckeditor5-editor:",Pt="layout-script:ckeditor5_script",ye=500,we=3e4;let Z=null;function Gt(){Z!==null&&(clearInterval(Z),Z=null)}function Ce(){const t=window?.G7Core?.assets;if(typeof t?.clearFailure!="function"||typeof t?.getFailures!="function")return;const e=()=>t.getFailures()??[],o=()=>{e().some(n=>n?.id===Pt)&&t.clearFailure(Pt)};o(),Gt();const r=Date.now();Z=setInterval(()=>{if(!e().some(a=>String(a?.id??"").startsWith(It))||Date.now()-r>=we){Gt();return}o()},ye)}function K(t,e,o,r){const n=window?.G7Core?.assets?.notifyFailure;if(typeof n=="function"){n({id:t,label:e,retry:o,message:r}),t.startsWith(It)&&Ce();return}console.warn(`[ckeditor5] 자산 로드 실패: ${e}`)}const $t="data-readonly",At="read-only";function Mt(t,e){const o=t.getAttribute($t);return o==="true"?!0:o==="false"?!1:e}function ve(t,e){const o=t;!o||typeof o.enableReadOnlyMode!="function"||typeof o.disableReadOnlyMode!="function"||(e?o.enableReadOnlyMode(At):o.disableReadOnlyMode(At))}function _e(t,e){t.querySelectorAll("textarea").forEach(o=>{o.readOnly=e})}function Ee(t,e,o){let r=null;const n=()=>{const l=Mt(t,o);if(l!==r){r=l;for(const i of e())ve(i,l);_e(t,l)}};if(n(),typeof MutationObserver>"u")return{stop:()=>{}};const a=new MutationObserver(n);return a.observe(t,{attributes:!0,attributeFilter:[$t]}),{stop:()=>a.disconnect()}}const Se="upload",xe=["jpeg","png","gif","webp"];function dt(t,e){const o=t?.response?.data,r=o?.error?.message??o?.message;return typeof r=="string"&&r!==""?r:e}function Tt(t){return Dt("sirsoft-ckeditor5.editor.upload_failed","Couldn't upload file: {{name}}.").replace("{{name}}",t)}function Ut(){return Dt("sirsoft-ckeditor5.editor.upload_login_required","Sign in to upload images. Your draft is kept.")}function Dt(t,e){const o=window?.G7Core?.t,r=typeof o=="function"?o(t):null;return typeof r=="string"&&r!==t?r:e}function ut(t,e,o){const r=window?.G7Core?.t,n=typeof r=="function"?r(t,e):null;return typeof n=="string"&&n!==t?n:o}class Le{constructor(e,o){wt(this,"controller",new AbortController);wt(this,"options");this.loader=e,this.options=typeof o=="string"?{legacyUrl:o}:o}async upload(){const e=this.options.target??null,o=window.G7Core?.contentMedia;return e&&o&&typeof o.upload=="function"?this.uploadByContract(e,o):this.uploadLegacy()}async uploadByContract(e,o){const r=await this.loader.file,n=Tt(r?.name??"");if(!r)throw n;const a=o.validateFile(e,r);if(!a?.ok)throw ut(a?.messageKey??"",a?.params,n);const l=this.options.getContent?.()??"";if(o.isAtLimit?.(l,e)===!0)throw ut("core.content_media.client.limit_reached",{max:e.policy?.maxPerDocument??0},n);try{const i=await o.upload(e,r,{signal:this.controller.signal,onProgress:(u,s)=>{this.loader.uploadTotal=s,this.loader.uploaded=u}});if(typeof i?.url!="string"||i.url==="")throw n;return{default:i.url}}catch(i){throw typeof i=="string"?i:window.G7Core?.api?.isAnonymousUnauthorized?.(i)===!0?Ut():dt(i,ut("core.content_media.client.upload_failed",void 0,n))}}async uploadLegacy(){const e=await this.loader.file,o=Tt(e?.name??""),r=window.G7Core?.api;if(!e||typeof r?.post!="function")throw o;const n=new FormData;n.append(Se,e);let a;try{a=await r.post(this.options.legacyUrl,n,{headers:{"Content-Type":"multipart/form-data"},signal:this.controller.signal,onUploadProgress:i=>{typeof i.total=="number"&&(this.loader.uploadTotal=i.total),this.loader.uploaded=i.loaded}})}catch(i){throw r.isAnonymousUnauthorized?.(i)===!0?Ut():dt(i,o)}const l=a?.url??a?.urls?.default;if(typeof l!="string"||l==="")throw dt({response:{data:a}},o);return{default:l}}abort(){this.controller.abort()}}function Ot(t){return function(o){const r=typeof t=="string"?{legacyUrl:t}:{...t,getContent:()=>typeof o.getData=="function"?o.getData():""};o.plugins.get("FileRepository").createUploadAdapter=n=>new Le(n,r)}}const Ft=Object.freeze({video:"g7VideoUpload",audio:"g7AudioUpload"});function Ie(t){return t?.policy?["video","audio"].filter(e=>t.policy[e]?.enabled===!0):[]}function Pe(t,e){const o=e.replace(/&/g,"&amp;").replace(/"/g,"&quot;").replace(/</g,"&lt;");return t==="video"?`<video controls="controls" preload="metadata" src="${o}"></video>`:`<audio controls="controls" preload="metadata" src="${o}"></audio>`}function j(t,e,o){const r=window?.G7Core?.t,n=typeof r=="function"?r(t,e):null;return typeof n=="string"&&n!==t?n:o}async function Ge(t,e,o,r){const n=window?.G7Core?.contentMedia,a=window?.G7Core?.toast?.error??(()=>{});if(!n)return;const l=n.validateFile(e,r);if(!l?.ok||l.kind!==o){a(j(l?.messageKey??"core.content_media.client.kind_not_allowed",l?.params,"Not allowed."));return}const i=typeof t.getData=="function"?t.getData():"";if(n.isAtLimit?.(i,e)===!0){a(j("core.content_media.client.limit_reached",{max:e.policy?.maxPerDocument??0},"Too many media."));return}try{const u=await n.upload(e,r,{}),s=t.data.processor.toView(Pe(o,u.url)),c=t.data.toModel(s);t.model.insertContent(c)}catch(u){if(window?.G7Core?.api?.isAnonymousUnauthorized?.(u)===!0){a(j("sirsoft-ckeditor5.editor.upload_login_required",void 0,"Sign in to upload. Your draft is kept."));return}const s=u?.response?.data?.message;a(typeof s=="string"&&s!==""?s:j("core.content_media.client.upload_failed",void 0,"Upload failed."))}}function $e(t,e){return function(r){const n=window.CKEDITOR;if(!(!n?.FileDialogButtonView||e.length===0))for(const a of e){const l=t.policy?.[a]?.extensions??[],i=t.policy?.[a]?.mimeTypes??[],u=a==="video"?j("core.content_media.client.video_button",void 0,"Insert video"):j("core.content_media.client.audio_button",void 0,"Insert audio");r.ui.componentFactory.add(Ft[a],s=>{const c=new n.FileDialogButtonView(s);return c.set({acceptedType:[...l.map(f=>`.${f}`),...i].join(","),allowMultipleFiles:!1}),c.buttonView.set({label:u,withText:!0,tooltip:!0}),c.on("done",(f,g)=>{const k=Array.from(g??[])[0];k&&Ge(r,t,a,k)}),c})}}}const tt="data-ckeditor5-fallback",et=new WeakMap;function ot(t,e,o,r){const n=window.G7Core;if(!n?.state?.setLocal||!t)return;o&&r&&n.state.getLocal?.()?.hasChanges!==!0&&n.state.setLocal({hasChanges:!0});const a={[`form.${t}_mode`]:"text"};if(typeof e=="string")a[`form.${t}`]=e;else for(const[l,i]of Object.entries(e))a[`form.${t}.${l}`]=i;n.state.setLocal(a,{render:!1,selfManaged:!0})}function ft(t){const{container:e,name:o,height:r,readOnly:n,placeholder:a,multilingual:l}=t;if(e.querySelector(`[${tt}]`))return;e.innerHTML="";const i=t.trackChanges!==!1,u=l?t.locales??[]:[],s=t.activeLocale??u[0]??"",c=l?{...t.contentMap??{}}:{"":t.initialContent??""},f=document.createElement("textarea");f.setAttribute(tt,"1"),f.name=o,f.readOnly=n,f.placeholder=a??"",f.style.cssText=["width:100%",`height:${r}px`,"padding:12px","border:1px solid #d1d5db","border-radius:6px","font-family:inherit","font-size:14px","line-height:1.6","resize:vertical","box-sizing:border-box"].join(";");let g=s;const k=new Map,d=()=>l?g:"";(l?u:[""]).forEach(p=>{const L=rt({getData:()=>p===d()?f.value:c[p]??"",setData:E=>{c[p]=E,p===d()&&(f.value=E)}},{name:o,locale:p,multilingual:l,seedPath:t.seedPath,seedValue:c[p]??""});k.set(p,L),e.id&&J(e.id,L)});const m=()=>[...k.values()].every(p=>p.shouldEmit());if(l){const p=document.createElement("div");p.className="ckeditor5-locale-tabs";const L=()=>{p.querySelectorAll("button").forEach(E=>{const M=E.dataset.locale??"",I=M===g;E.classList.toggle("is-active",I);const P=(c[M]??"").trim()!=="",F=E.querySelector("[data-check]");F&&(F.style.display=P?"":"none")})};u.forEach((E,M)=>{const I=document.createElement("button");I.type="button",I.dataset.locale=E,M===0&&I.classList.add("is-default"),I.textContent=Lt(E);const P=document.createElement("span");P.dataset.check="1",P.textContent="✓",P.style.display="none",I.appendChild(P),I.addEventListener("click",()=>{c[g]=f.value,g=E,f.value=c[E]??"",f.dataset.locale=E,m()&&ot(o,c,!1,i),L()}),p.appendChild(I)}),e.appendChild(p),f.dataset.locale=g,f.value=c[g]??"",L(),f.addEventListener("input",()=>{c[g]=f.value,m()&&(k.get(g)?.noteEmitted(f.value),ot(o,c,!0,i)),L()})}else f.value=c[""]??"",f.addEventListener("input",()=>{c[""]=f.value,m()&&(k.get("")?.noteEmitted(f.value),ot(o,f.value,!0,i))});e.appendChild(f),et.set(e,{multilingual:l,values:c,activeLocale:g,syncs:k}),ot(o,l?c:c[""]??"",!1,i)}function Ae(t){const e=et.get(t);if(!e)return null;const o=t.querySelector(`textarea[${tt}]`);return o&&(e.values[e.multilingual?o.dataset.locale??e.activeLocale:""]=o.value),e.multilingual?{...e.values}:e.values[""]??""}function Me(t){return t.querySelector(`[${tt}]`)?(et.get(t)?.syncs.forEach(e=>e.stop()),t.innerHTML="",et.delete(t),!0):!1}const Nt="ckeditor5-dark-override",zt="ckeditor5-tab-style",Rt={minimal:["bold","italic","underline","|","link","uploadImage","|","undo","redo"],standard:["heading","|","bold","italic","underline","strikethrough","|","alignment","|","link","blockQuote","|","bulletedList","numberedList","indent","outdent","|","insertTable","uploadImage","|","undo","redo"],full:["heading","|","fontSize","fontColor","fontBackgroundColor","|","bold","italic","underline","strikethrough","|","alignment","|","link","blockQuote","insertTable","uploadImage","mediaEmbed","horizontalLine","|","bulletedList","numberedList","indent","outdent","|","codeBlock","sourceEditing","|","undo","redo"]};function Te(t){if(t==="en")return Promise.resolve();const e=`ckeditor5-translations-${t}`;if(document.getElementById(e))return Promise.resolve();const o=window?.G7Core?.asset?.loadScript;return new Promise(r=>{let n;try{n=de(t)}catch(l){console.warn("[ckeditor5] 번역 URL 을 만들지 못했습니다 (영어로 동작):",l),r();return}if(typeof o=="function"){o(n,{id:e},{label:`ckeditor5 translations: ${t}`,retries:1}).catch(l=>{console.warn(`[ckeditor5] 번역 로드 실패 (${t}) — 영어로 동작합니다:`,l)}).then(()=>r());return}const a=document.createElement("script");a.id=e,a.src=n,a.onload=()=>r(),a.onerror=()=>{console.warn(`[ckeditor5] 번역 로드 실패 (${t}) — 영어로 동작합니다.`),r()},document.head.appendChild(a)})}async function jt(){if(document.getElementById(it))return;const t=window?.G7Core?.asset?.loadStylesheet;let e;try{e=Et()}catch(o){console.warn("[ckeditor5] CSS URL 을 만들지 못했습니다:",o);return}if(typeof t!="function"){const o=document.createElement("link");o.id=it,o.rel="stylesheet",o.href=e,document.head.appendChild(o);return}try{await t(e,{id:it},{label:"ckeditor5 CSS"})}catch(o){console.warn("[ckeditor5] CSS 로드 실패:",o),K("ckeditor5-css",D("editor.asset.style_label","편집기 스타일"),()=>jt())}}async function pt(t,e,o,r,n){const a=Ae(o);if(!await xt())throw new Error("[sirsoft-ckeditor5] 편집기 본체를 여전히 불러올 수 없습니다.");Me(o);const l={...t,params:{...t.params??{},content:r?a??{}:a??""}};if(await Jt(l,e),!n)return;window.G7Core?.state?.setLocal?.({[`form.${n}_mode`]:"html"})}function D(t,e){const o=window?.G7Core?.t;if(typeof o!="function")return e;const r=`sirsoft-ckeditor5.${t}`,n=o(r);return typeof n=="string"&&n!==r?n:e}function Ue(){if(document.getElementById(zt))return;const t=document.createElement("style");t.id=zt,t.textContent=`
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
        /* 비활성 - 기본언어 (blue) — 글자는 hover 바탕(#dbeafe)에서도 4.5 이상인 단계 */
        .ckeditor5-locale-tabs button.is-default {
            background: #eff6ff;
            color: #1d4ed8;
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
        /* 활성 - 기본언어 — 흰 글자가 4.5 이상인 바탕(종전 #3b82f6 은 3.68) */
        .ckeditor5-locale-tabs button.is-active.is-default {
            background: #2563eb;
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
        /* 필수 표시 — 비활성(연한 바탕)은 진한 빨강, 활성(진한 바탕)은 탭 글자색(흰색)을 따른다 */
        .ckeditor5-locale-tabs button .ck5-required {
            color: #b91c1c;
        }
        .ckeditor5-locale-tabs button.is-active .ck5-required {
            color: currentColor;
        }
        /* 입력 완료 표시(아이콘 3:1) — 회색 hover 바탕(#e5e7eb)에서도 3 이상 */
        .ckeditor5-locale-tabs button .ck5-check {
            color: #15803d;
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
        html.dark .ckeditor5-locale-tabs button .ck5-required {
            color: #fca5a5;
        }
        html.dark .ckeditor5-locale-tabs button.is-active .ck5-required {
            color: currentColor;
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
    `,document.head.appendChild(t)}function De(){return document.documentElement.classList.contains("dark")}function Bt(){const t=document.getElementById(Nt);if(!De()){t&&t.remove();return}if(t)return;const e=document.createElement("style");e.id=Nt,e.textContent=`
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
    `,document.head.appendChild(e)}let B=null;function Oe(){B||(B=new MutationObserver(()=>{Bt()}),B.observe(document.documentElement,{attributes:!0,attributeFilter:["class"]}))}function Fe(){B&&(B.disconnect(),B=null)}function Vt(){const t=window.G7Core;return t?.locale?.supported?t.locale.supported():[localStorage.getItem("g7_locale")||"ko"]}function $(){return window.G7Core?.locale?.current?.()??localStorage.getItem("g7_locale")??"ko"}function Ne(t,e,o,r=!1){const n=[t.Essentials,t.Bold,t.Italic,t.Underline,t.Strikethrough,t.Link,t.Paragraph,t.Heading,t.BlockQuote,t.List,t.Alignment,t.Table,t.TableToolbar,t.TableProperties,t.TableCellProperties,t.Indent,t.IndentBlock,t.PasteFromOffice,t.GeneralHtmlSupport,t.Image,t.ImageResize,t.ImageStyle,t.ImageCaption,t.ImageToolbar];return e==="full"&&n.push(t.MediaEmbed,t.HorizontalLine,t.CodeBlock,t.SourceEditing,t.FontSize,t.FontColor,t.FontBackgroundColor),r&&e!=="full"&&n.push(t.MediaEmbed),o&&n.push(t.ImageUpload),n}function qt(){return"/api/plugins/sirsoft-ckeditor5/upload"}function ze(){try{return window?.G7Core?.contentPolicy?.editorConstraints?.()??null}catch{return null}}function Re(t){return(t??[]).map(e=>({name:String(e.id),url:(e.patterns??[]).map(o=>{try{return new RegExp(o,"i")}catch{return null}}).filter(o=>o!==null),html:o=>{const r=o?.groups?.id??"";return`<div style="position:relative;padding-bottom:56.25%;height:0;"><iframe src="${String(e.player_url??"").replace("{id}",encodeURIComponent(r)).replace(/"/g,"&quot;")}" style="position:absolute;inset:0;width:100%;height:100%;border:0;" allowfullscreen></iframe></div>`}})).filter(e=>e.url.length>0)}function je(t){let e=t;if(typeof e=="string"){const r=e.trim();if(r===""||r==="null"||r==="undefined"||r.startsWith("{{"))return null;try{e=JSON.parse(r)}catch{return null}}if(e==null||typeof e!="object")return null;const o=window?.G7Core?.contentMedia?.normalizeTarget;return typeof o=="function"?o(e)??null:null}function Be(t){const e={jpg:"jpeg",jpeg:"jpeg",png:"png",gif:"gif",webp:"webp",avif:"avif"},o=t.map(r=>e[String(r).toLowerCase()]).filter(r=>!!r);return Array.from(new Set(o))}async function Wt(t){try{await t?.destroy?.()}catch{}}function Ht(t,e){w.get(t)===e&&w.delete(t)}function Ve(t){let e=null;try{e=window?.G7Core?.AuthManager?.getInstance?.()?.getUser?.()??null}catch{e=null}return(e??t?._global?.currentUser??t?.currentUser)?.is_admin===!0}function qe(t){const e=window?.G7Core?.toast?.error;if(typeof e!="function")return;let o;try{o=t?.plugins?.get?.("Notification")}catch{return}o?.on?.("show:warning",(r,n)=>{const a=typeof n?.message=="string"&&n.message!==""?n.message:typeof n?.title=="string"?n.title:"";a!==""&&e(a),r.stop()},{priority:"high"})}async function Yt(t,e){const o=window.CKEDITOR;if(!o)throw new Error("[sirsoft-ckeditor5] CKEDITOR 전역 객체를 찾을 수 없습니다. CDN 스크립트가 로드되었는지 확인하세요.");const r=Rt[e.toolbar]||Rt.standard,n=ze(),a=n?.allowExternalEmbed===!0;let l=e.imageUpload?r:r.filter(p=>p!=="uploadImage");if(a&&!l.includes("mediaEmbed")){const p=l.indexOf("insertTable");l=[...l.slice(0,p+1),"mediaEmbed",...l.slice(p+1)]}n&&!a&&(l=l.filter(p=>p!=="mediaEmbed"));const i=Ie(e.mediaTarget??null),u=i.map(p=>Ft[p]),s=l.indexOf("uploadImage")>=0?l.indexOf("uploadImage"):l.indexOf("insertTable"),c=u.length>0?[...l.slice(0,s+1),...u,...l.slice(s+1)]:l,f={initialData:e.initialContent,plugins:Ne(o,e.toolbar,e.imageUpload,a),toolbar:{items:c},table:{contentToolbar:["tableColumn","tableRow","mergeTableCells","|","tableProperties","tableCellProperties"],tableProperties:{defaultProperties:{borderStyle:"solid",borderColor:"#d1d5db",borderWidth:"1px",alignment:"left"}},tableCellProperties:{defaultProperties:{borderStyle:"solid",borderColor:"#d1d5db",borderWidth:"1px",padding:"8px"}}},image:{toolbar:["imageStyle:inline","imageStyle:alignLeft","imageStyle:alignCenter","imageStyle:alignRight","|","toggleImageCaption","|","resizeImage"],resizeOptions:[{name:"resizeImage:original",label:"Original",value:null},{name:"resizeImage:25",label:"25%",value:"25"},{name:"resizeImage:50",label:"50%",value:"50"},{name:"resizeImage:75",label:"75%",value:"75"}],resizeUnit:"%",upload:{types:e.mediaTarget?Be(e.mediaTarget.policy?.image?.extensions??[]):[...xe]}},indentBlock:{offset:40,unit:"px"},link:{defaultProtocol:"https://",decorators:{openInNewTab:{mode:"automatic",callback:p=>p.startsWith("http://")||p.startsWith("https://"),attributes:{target:"_blank",rel:"noopener noreferrer"}}}},htmlSupport:{allow:[{name:/.*/,attributes:!0,classes:!0,styles:!0}],...n&&(n.forbidTags??[]).length>0?{disallow:[{name:new RegExp(`^(${n.forbidTags.map(p=>p.replace(/[^a-z0-9-]/gi,"")).join("|")})$`)}]}:{}},placeholder:e.placeholder,language:$()};n&&(f.mediaEmbed={previewsInData:!1,providers:[],extraProviders:Re(n.providers??[])});const g=[];e.imageUpload&&g.push(e.mediaTarget?Ot({target:e.mediaTarget,legacyUrl:qt()}):Ot(qt())),i.length>0&&g.push($e(e.mediaTarget,i)),g.length>0&&(f.extraPlugins=g);const k=await o.ClassicEditor.create(t,f);qe(k);const d=`ckeditor5-height-style-${e.containerId}`,m=document.getElementById(d);if(m)m.textContent=`#${e.containerId} .ck-editor__editable { min-height: ${e.height}px !important; }`;else{const p=document.createElement("style");p.id=d,p.textContent=`#${e.containerId} .ck-editor__editable { min-height: ${e.height}px !important; }`,document.head.appendChild(p)}return e.readOnly&&k.enableReadOnlyMode("read-only"),k}function We(t,e,o,r,n){const a=document.createElement("div");a.className="ckeditor5-locale-tabs";const l=new Map;e.forEach(s=>{const c=document.createElement("div");c.dataset.locale=s,s!==o&&(c.style.cssText="display:none;");const f=document.createElement("div");c.appendChild(f),l.set(s,f)});const i=()=>{n&&a.querySelectorAll("button").forEach(s=>{const c=s.dataset.locale;if(!c||s.classList.contains("is-active")){const d=s.querySelector(".ck5-check");d&&d.remove();return}const g=!!n(c)?.trim(),k=s.querySelector(".ck5-check");if(g&&!k){const d=document.createElement("i");d.className="fas fa-check ck5-check",s.appendChild(d)}else!g&&k&&k.remove()})},u=e[0];return e.forEach(s=>{const c=s===u,f=s===o,g=document.createElement("button");g.type="button",g.dataset.locale=s,g.className=[f?"is-active":"",c?"is-default":""].filter(Boolean).join(" "),he(g,s,c),g.addEventListener("click",async()=>{a.querySelectorAll("button").forEach(k=>{const m=k.dataset.locale===s;k.classList.toggle("is-active",m)}),l.forEach((k,d)=>{const m=k.parentElement;m&&(m.style.display=d===s?"block":"none")}),r&&await r(s),i()}),a.appendChild(g)}),t.appendChild(a),l.forEach(s=>{s.parentElement&&t.appendChild(s.parentElement)}),{tabsEl:a,contentEls:l,updateCheckIcons:i}}function Qt(t,e,o,r,n=!0,a=null){if(le())return;const l=window.G7Core;if(!l?.state?.setLocal)return;if(n&&l.state.getLocal?.()?.hasChanges!==!0&&l.state.setLocal({hasChanges:!0}),a&&a.scope==="global"){const s=r?`${a.path}.${e}`:a.path;window.G7Core?.state?.set?.({[s]:o});return}const i=a?.path??`form.${t}`,u={[`form.${t}_mode`]:"html"};r?u[`${i}.${e}`]=o:u[i]=o,l.state.setLocal(u,{debounce:300,debounceKey:`ckeditor-sync-${t}`,render:!1,selfManaged:!0})}function mt(t,e){const o=window.G7Core,r=typeof t.content=="string"?t.content:"";let a=r.startsWith("{{")&&r.endsWith("}}")?"":r;if(!a&&e){const i=(o?.state?.getLocal?.()??{})?.form?.[e];typeof i=="string"&&i&&(a=i)}return a}function gt(t,e){const o=window.G7Core;let r={};try{typeof t.content=="string"&&t.content.startsWith("{")&&!t.content.startsWith("{{")?r=JSON.parse(t.content):typeof t.content=="object"&&t.content!==null&&(r=t.content)}catch{r={}}if(Object.keys(r).length===0&&e){const a=(o?.state?.getLocal?.()??{})?.form?.[e];if(a&&typeof a=="object"&&!Array.isArray(a)?r=a:typeof a=="string"&&a&&(r={[$()]:a}),Object.keys(r).length===0){const l=o?.state?.get?.()??{},u=(l?._global?.selectedItem??l?.selectedItem)?.[e];u&&typeof u=="object"&&!Array.isArray(u)&&(r=u)}}return r}async function Jt(t,e){const o=t.params??{},r=o.name??"content",n=`ckeditor5-${r}`,a=location.pathname,l=o.multilingual===!0||o.multilingual==="true",i=l?"":mt(o,r),u=l?gt(o,r):{};vt(n);const s=w.get(n);if(s&&s.size>0){const y=[];s.forEach(h=>{h&&typeof h.destroy=="function"&&y.push(h.destroy().catch(()=>{}))}),await Promise.allSettled(y),w.delete(n)}const c=document.getElementById(n);if(!c){console.warn(`[sirsoft-ckeditor5] 컨테이너 엘리먼트를 찾을 수 없습니다: #${n}`);return}jt(),Ue(),Bt(),Oe(),await Te($());const g=window.G7Core?.state?.get()||{},k=g._global?.plugins?.["sirsoft-ckeditor5"]??g.plugins?.["sirsoft-ckeditor5"]??{},d=o.name??"content",m=o.multilingual===!0||o.multilingual==="true",p=o.readOnly===!0||o.readOnly==="true",L=()=>Mt(c,p),E=o.imageUpload!==void 0?o.imageUpload===!0||o.imageUpload==="true":k.imageUpload===!0||k.imageUpload==="true",M=je(o.mediaUpload),I=M?M.policy?.image?.enabled===!0:E&&Ve(g),P=o.placeholder??"",F=o.height!==void 0?Number(o.height)||400:Number(k.editorHeight)||400,Ye=(o.toolbar!==void 0?o.toolbar:k.toolbar)??"standard",W=!(o.trackChanges===!1||o.trackChanges==="false"),Qe=o.writeScope==="global"?"global":"local",te=typeof o.writePath=="string"&&o.writePath.trim()!==""&&!o.writePath.includes("{{")?o.writePath.trim():null,A=te===null?null:{scope:Qe,path:te},ht=(y,h)=>{if(!A)return;const v=window.G7Core,b=A.scope==="global"?v?.state?.get?.():v?.state?.getLocal?.(),N=(h?`${A.path}.${y}`:A.path).split(".").reduce((z,S)=>z?.[S],b);return typeof N=="string"?N:void 0},Je=(y,h)=>{if(A)return ht(y,h);const v=window.G7Core?.state?.getLocal?.()?.form,b=h?v?.[d]?.[y]:v?.[d];return typeof b=="string"?b:void 0},nt=(y,h,v,b)=>{if(location.pathname===a)return{value:v(),path:a};const _=Je(y,h)??"";return _!==b?{value:_,path:location.pathname}:{value:_,path:a}};let ee=m?gt(o,d):{};const Xe=await xt();if(!c.isConnected)return;if(J(n,Ee(c,()=>w.get(n)?.values()??[],p)),!Xe){const y=m?Vt():[],h=m?y.map(b=>{const _=gt(o,d);return{locale:b,...nt(b,!0,()=>_[b]??"",u[b]??"")}}):[{locale:"",...nt($(),!1,()=>mt(o,d),i)}],v={container:c,name:d,height:F,readOnly:L(),placeholder:P,trackChanges:W,multilingual:m,locales:m?y:void 0,activeLocale:$(),contentMap:m?Object.fromEntries(h.map(b=>[b.locale,b.value])):void 0,initialContent:m?void 0:h[0].value,seedPath:h.find(b=>b.path!==location.pathname)?.path};ft(v),K(`ckeditor5-editor:${n}`,D("editor.asset.label","편집기"),()=>pt(t,e,c,v.multilingual,d),D("editor.asset.fallback_notice","편집기를 불러오지 못했습니다. 임시 입력창으로 전환했습니다. 작성한 내용은 그대로 저장됩니다."));return}const T=new Map;w.set(n,T);const oe={placeholder:P,get readOnly(){return L()},imageUpload:I,height:F,toolbar:Ye,containerId:n,mediaTarget:M};if(m){const y=Vt(),h=$(),v={current:new Map},b={current:()=>{}},_=async C=>{if(T.has(C))return;const H=v.current.get(C);if(!H)return;const ne=nt(C,!0,()=>ee[C]??"",u[C]??""),re=ne.value;try{const R=await Yt(H,{...oe,initialContent:re});if(!c.isConnected){await Wt(R);return}T.set(C,R);let ae=!0;const yt=rt(R,{name:d,locale:C,multilingual:!0,readExternal:A?()=>ht(C,!0):void 0,seedPath:ne.path,seedValue:re});if(J(n,yt),R.model.document.on("change:data",()=>{if(ae||!yt.shouldEmit())return;const Y=R.getData();yt.noteEmitted(Y),Qt(d,C,Y,!0,W,A),b.current()}),ae=!1,d){const Y=window.G7Core;(Y?.state?.getLocal?.()??{}).form?.[`${d}_mode`]!=="html"&&Y.state.setLocal({[`form.${d}_mode`]:"html"})}}catch(R){console.error(`[sirsoft-ckeditor5] 에디터 초기화 오류 (locale: ${C}):`,R)}},N=C=>{const H=T.get(C);return H?H.getData?.()??null:null},{contentEls:z,updateCheckIcons:S}=We(c,y,h,_,N);if(v.current=z,b.current=S,await _(h),!c.isConnected){Ht(n,T);return}if(!T.has(h)){w.delete(n),ft({container:c,name:d,height:F,readOnly:L(),placeholder:P,multilingual:!0,locales:y,activeLocale:h,contentMap:ee,trackChanges:W}),K(`ckeditor5-editor:${n}`,D("editor.asset.label","편집기"),()=>pt(t,e,c,!0,d),D("editor.asset.fallback_notice","편집기를 불러오지 못했습니다. 임시 입력창으로 전환했습니다. 작성한 내용은 그대로 저장됩니다."));return}setTimeout(()=>{y.filter(C=>C!==h).forEach(C=>{_(C)})},2e3)}else{const y=nt($(),!1,()=>mt(o,d),i),h=y.value,v=document.createElement("div");c.appendChild(v);try{const b=await Yt(v,{...oe,initialContent:h});if(!c.isConnected){await Wt(b),Ht(n,T);return}const _=$();T.set(_,b);let N=!0;const z=rt(b,{name:d,locale:_,multilingual:!1,readExternal:A?()=>ht(_,!1):void 0,seedPath:y.path,seedValue:h});if(J(n,z),b.model.document.on("change:data",()=>{if(N||!z.shouldEmit())return;const S=b.getData();z.noteEmitted(S),Qt(d,_,S,!1,W,A)}),N=!1,h){const S=b.getData();(!S||S.trim()==="")&&b.setData(h)}if(d){const S=window.G7Core;(S?.state?.getLocal?.()??{}).form?.[`${d}_mode`]!=="html"&&S.state.setLocal({[`form.${d}_mode`]:"html"})}}catch(b){console.error("[sirsoft-ckeditor5] 에디터 초기화 오류:",b),w.delete(n),ft({container:c,name:d,height:F,readOnly:L(),placeholder:P,multilingual:!1,activeLocale:$(),initialContent:h,trackChanges:W,seedPath:y.path}),K(`ckeditor5-editor:${n}`,D("editor.asset.label","편집기"),()=>pt(t,e,c,!1,d),D("editor.asset.fallback_notice","편집기를 불러오지 못했습니다. 임시 입력창으로 전환했습니다. 작성한 내용은 그대로 저장됩니다."))}}}async function He(t,e){const r=`ckeditor5-${t.params?.name??"content"}`;vt(r);const n=w.get(r);if(!n||n.size===0)return;const a=[];n.forEach(i=>{i&&typeof i.destroy=="function"&&a.push(i.destroy().catch(u=>{console.warn("[sirsoft-ckeditor5] destroyEditor error:",u)}))}),await Promise.allSettled(a),w.delete(r);const l=document.getElementById(`ckeditor5-height-style-${r}`);l&&l.remove(),w.size===0&&Fe()}const x="sirsoft-ckeditor5",kt="ckeditor5-content-styles",Xt="ckeditor5-content-styles-override";function Zt(){if(!document.getElementById(kt)){const t=window?.G7Core?.asset;try{const e=Et();if(typeof t?.loadStylesheet=="function")t.loadStylesheet(e,{id:kt},{label:"ckeditor5 content CSS"}).catch(o=>{console.warn("[ckeditor5] 본문 스타일 로드 실패:",o),window?.G7Core?.assets?.notifyFailure?.({id:"ckeditor5-content-css",label:"본문 스타일",retry:()=>Zt()})});else{const o=document.createElement("link");o.id=kt,o.rel="stylesheet",o.href=e,document.head.appendChild(o)}}catch(e){console.warn("[ckeditor5] 본문 스타일 URL 을 만들지 못했습니다:",e)}}if(!document.getElementById(Xt)){const t=document.createElement("style");t.id=Xt,t.textContent=`
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
        `,document.head.appendChild(t)}}const O={initEditor:Jt,destroyEditor:He,injectContentCss:Zt},q=window.G7Core?.createLogger?.(`Plugin:${x}`)??{log:(...t)=>console.log(`[Plugin:${x}]`,...t),warn:(...t)=>console.warn(`[Plugin:${x}]`,...t),error:(...t)=>console.error(`[Plugin:${x}]`,...t)};function Kt(t=!1){const e=window.G7Core?.getActionDispatcher?.();if(e)Object.entries(O).forEach(([o,r])=>{const n=`${x}.${o}`;e.registerHandler(n,r,{category:"plugin",source:x})}),q.log(`${Object.keys(O).length} handler(s) registered:`,Object.keys(O).map(o=>`${x}.${o}`));else if(t){let o=0;const r=50,n=()=>{const a=window.G7Core?.getActionDispatcher?.();a?(Object.entries(O).forEach(([l,i])=>{const u=`${x}.${l}`;a.registerHandler(u,i,{category:"plugin",source:x})}),q.log(`${Object.keys(O).length} handler(s) registered:`,Object.keys(O).map(l=>`${x}.${l}`))):(o++,o<=r?(q.warn(`ActionDispatcher not found, retrying... (${o}/${r})`),setTimeout(n,100)):q.error("Failed to register handlers: ActionDispatcher not available after maximum retries"))};n()}else q.warn("ActionDispatcher not found, handlers not registered")}function bt(){if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",()=>Kt(!0));else{const t=!!window.G7Core?.getActionDispatcher?.();Kt(!t)}}return bt(),typeof window<"u"&&(window.__SirsoftCkeditor5={identifier:x,handlers:Object.keys(O),initPlugin:bt}),G.initPlugin=bt,Object.defineProperty(G,Symbol.toStringTag,{value:"Module"}),G}({});
