var SirsoftCkeditor5=function(G){"use strict";var ro=Object.defineProperty;var ao=(G,w,D)=>w in G?ro(G,w,{enumerable:!0,configurable:!0,writable:!0,value:D}):G[w]=D;var Et=(G,w,D)=>ao(G,typeof w!="symbol"?w+"":w,D);const w=new Map;let D=!1;function de(){return D}function vt(t){D=t}const X=new Map;function Z(t,o){const e=X.get(t)??[];e.push(o),X.set(t,e)}function St(t){(X.get(t)??[]).forEach(o=>{try{o.stop()}catch{}}),X.delete(t)}function ue(t){const o=globalThis?.G7Core;if(typeof o?.url?.api=="function")return o.url.api(t);const e=globalThis?.G7Config?.basePath,r=typeof e=="string"&&e!==""&&e!=="/"?(e.startsWith("/")?e:"/"+e).replace(/\/+$/,""):"";return t=typeof t=="string"?t.trim():t,!r||!t.startsWith("/")||t.startsWith("//")||Lt(t,r)?t:r+t}function T(t){const o=globalThis?.G7Core;if(typeof o?.url?.route=="function")return o.url.route(t);const e=globalThis?.G7Config?.basePath,r=typeof e=="string"&&e!==""&&e!=="/"?(e.startsWith("/")?e:"/"+e).replace(/\/+$/,""):"";if(t=typeof t=="string"?t.trim():t,!r||typeof t!="string"||!Lt(t,r))return t;const n=t.slice(r.length);return n===""||n.startsWith("?")||n.startsWith("#")?"/"+n:n}function Lt(t,o){if(!t.startsWith(o))return!1;const e=t.charAt(o.length);return e===""||e==="/"||e==="?"||e==="#"}const fe=400,xt=1e4;function lt(t,o){const{name:e,locale:r,multilingual:n}=o;let a=o.seedPath??T(location.pathname),i=null,l=!1,u=null,s,c=0;const f=()=>{if(o.readExternal)return o.readExternal();const m=window.G7Core?.state?.getLocal?.()?.form;if(!m||!e)return;const p=n?m[e]?.[r]:m[e];return typeof p=="string"?p:void 0};s=a!==T(location.pathname)&&o.seedValue!==void 0?o.seedValue:f();const g=d=>{T(location.pathname)!==a&&(a=T(location.pathname),u=new Set([d]),s!==void 0&&u.add(s),i!==null&&u.add(i),i=null,l=!0,c=Date.now())},k=window.setInterval(()=>{let d;try{d=t.getData()}catch{window.clearInterval(k);return}g(d);const m=f();if(m===void 0){l&&Date.now()-c>xt&&(l=!1,u=null);return}if(l){if(u?.has(m)){Date.now()-c>xt&&(l=!1,u=null,s=m);return}if(m!==d){vt(!0);try{t.setData(m)}finally{vt(!1)}}l=!1,u=null,i=m,s=m;return}if(s=m,m===i||m===d){i=m;return}},fe);return{shouldEmit:()=>{if(!l)try{g(t.getData())}catch{}return!l},noteEmitted:d=>{i=d},stop:()=>window.clearInterval(k)}}const pe="43.3.1",ct="sirsoft-ckeditor5",st=`dist/vendor/ckeditor5/${pe}`,dt="ckeditor5-vendor-css";function ut(){const t=window?.G7Core?.asset;if(!t||typeof t.plugin!="function")throw new Error("G7Core.asset is unavailable — core 7.0.10 or later is required to resolve bundled CKEditor5 assets");return t}function me(){return ut().plugin(ct,`${st}/ckeditor5.umd.js`)}function It(){return ut().plugin(ct,`${st}/ckeditor5.css`)}function ge(t){return ut().plugin(ct,`${st}/translations/${t}.umd.js`)}const Pt="ckeditor5-editor:",K="layout-script:ckeditor5_script",Gt=500,ke=3e4;let tt=null;function At(){tt!==null&&(clearInterval(tt),tt=null)}function he(){const t=window?.G7Core?.assets;if(typeof t?.clearFailure!="function"||typeof t?.getFailures!="function")return;const o=()=>t.getFailures()??[],e=()=>{o().some(n=>n?.id===K)&&t.clearFailure(K)};e(),At();const r=Date.now();tt=setInterval(()=>{if(!o().some(a=>String(a?.id??"").startsWith(Pt))||Date.now()-r>=ke){At();return}e()},Gt)}const be=5e3;let j=null;function ye(){const t=window?.G7Core?.assets;if(typeof t?.clearFailure!="function"||typeof t?.getFailures!="function")return;const o=()=>{(t.getFailures()??[]).some(n=>n?.id===K)&&t.clearFailure(K)};o(),j!==null&&clearInterval(j);const e=Date.now();j=setInterval(()=>{if(Date.now()-e>=be){j!==null&&(clearInterval(j),j=null);return}o()},Gt)}function et(t,o,e,r){const n=window?.G7Core?.assets?.notifyFailure;if(typeof n=="function"){n({id:t,label:o,retry:e,message:r}),t.startsWith(Pt)&&he();return}console.warn(`[ckeditor5] 자산 로드 실패: ${o}`)}const we="ckeditor5-vendor-umd",Ce=15e3,_e=2500,Ee=50;let q=null;function ot(){return typeof window.CKEDITOR?.ClassicEditor=="function"}function $t(){return typeof window.CKEDITOR_VERSION=="string"}function ft(){return Array.from(document.querySelectorAll("script[src]")).filter(t=>(t.getAttribute("src")??"").toLowerCase().includes("ckeditor5.umd.js"))}function ve(t){return new Promise(o=>{let e=!1;const r=new Set,n=new Set;let a=null;const i=Date.now(),l=c=>{e||(e=!0,clearInterval(s),o(c))},u=c=>{r.has(c)||(r.add(c),c.addEventListener("load",()=>{ot()&&l("ready")}),c.addEventListener("error",()=>{n.add(c),a=Date.now()}))},s=setInterval(()=>{if(ot()){l("ready");return}ft().forEach(u);const c=ft().some(g=>!n.has(g)),f=Date.now();if(!c&&a!==null&&f-a>=_e){l("failed");return}f-i>=Ce&&l(c?"timeout":"failed")},Ee);t.forEach(u)})}async function Se(){const t=window?.G7Core?.asset?.loadScript;if(typeof t!="function")return!1;try{await t(me(),{id:we},{label:"ckeditor5 UMD"})}catch(e){return console.warn("[ckeditor5] 편집기 본체를 불러오지 못했습니다:",e),!1}const o=ot();return o&&ye(),o}function Tt(){return ot()?Promise.resolve(!0):q||(q=(async()=>{if($t())return console.warn("[ckeditor5] 편집기 본체가 두 번 실행되어 전역이 비었습니다 — 다시 싣지 않습니다."),!1;const t=ft();if(t.length>0){const o=await ve(t);if(o==="ready")return!0;if(o==="timeout"||$t())return!1}return Se()})().finally(()=>{q=null}),q)}const Le={ko:"한국어",en:"English",ja:"日本語",zh:"中文",fr:"Français",de:"Deutsch",es:"Español"};function Mt(t){const o=window,e=o.G7Core?.state?.get?.()?.appConfig?.localeNames?.[t],r=o.G7Config?.appConfig?.localeNames?.[t],n=typeof e=="string"&&e?e:r;return typeof n=="string"&&n!==""?n:Le[t]??t}function xe(t,o,e){t.replaceChildren();const r=document.createElement("i");r.className="ckeditor-icon ckeditor-icon--globe ckeditor-lang-icon",t.appendChild(r);const n=document.createElement("span");if(n.textContent=Mt(o),t.appendChild(n),e){const a=document.createElement("span");a.className="ckeditor-required",a.textContent="*",t.appendChild(a)}}const Ut="data-readonly",Dt="read-only";function Ot(t,o){const e=t.getAttribute(Ut);return e==="true"?!0:e==="false"?!1:o}function Ie(t,o){const e=t;!e||typeof e.enableReadOnlyMode!="function"||typeof e.disableReadOnlyMode!="function"||(o?e.enableReadOnlyMode(Dt):e.disableReadOnlyMode(Dt))}function Pe(t,o){t.querySelectorAll("textarea").forEach(e=>{e.readOnly=o})}function Ge(t,o,e){let r=null;const n=()=>{const i=Ot(t,e);if(i!==r){r=i;for(const l of o())Ie(l,i);Pe(t,i)}};if(n(),typeof MutationObserver>"u")return{stop:()=>{}};const a=new MutationObserver(n);return a.observe(t,{attributes:!0,attributeFilter:[Ut]}),{stop:()=>a.disconnect()}}const Ae="upload",$e=["jpeg","png","gif","webp"];function pt(t,o){const e=t?.response?.data,r=e?.error?.message??e?.message;return typeof r=="string"&&r!==""?r:o}function Ft(t){return Nt("sirsoft-ckeditor5.editor.upload_failed","Couldn't upload file: {{name}}.").replace("{{name}}",t)}function Rt(){return Nt("sirsoft-ckeditor5.editor.upload_login_required","Sign in to upload images. Your draft is kept.")}function Nt(t,o){const e=window?.G7Core?.t,r=typeof e=="function"?e(t):null;return typeof r=="string"&&r!==t?r:o}function mt(t,o,e){const r=window?.G7Core?.t,n=typeof r=="function"?r(t,o):null;return typeof n=="string"&&n!==t?n:e}class Te{constructor(o,e){Et(this,"controller",new AbortController);Et(this,"options");this.loader=o,this.options=typeof e=="string"?{legacyUrl:e}:e}async upload(){const o=this.options.target??null,e=window.G7Core?.contentMedia;return o&&e&&typeof e.upload=="function"?this.uploadByContract(o,e):this.uploadLegacy()}async uploadByContract(o,e){const r=await this.loader.file,n=Ft(r?.name??"");if(!r)throw n;const a=e.validateFile(o,r);if(!a?.ok)throw mt(a?.messageKey??"",a?.params,n);const i=this.options.getContent?.()??"";if(e.isAtLimit?.(i,o)===!0)throw mt("core.content_media.client.limit_reached",{max:o.policy?.maxPerDocument??0},n);try{const l=await e.upload(o,r,{signal:this.controller.signal,onProgress:(u,s)=>{this.loader.uploadTotal=s,this.loader.uploaded=u}});if(typeof l?.url!="string"||l.url==="")throw n;return{default:l.url}}catch(l){throw typeof l=="string"?l:window.G7Core?.api?.isAnonymousUnauthorized?.(l)===!0?Rt():pt(l,mt("core.content_media.client.upload_failed",void 0,n))}}async uploadLegacy(){const o=await this.loader.file,e=Ft(o?.name??""),r=window.G7Core?.api;if(!o||typeof r?.post!="function")throw e;const n=new FormData;n.append(Ae,o);let a;try{a=await r.post(this.options.legacyUrl,n,{headers:{"Content-Type":"multipart/form-data"},signal:this.controller.signal,onUploadProgress:l=>{typeof l.total=="number"&&(this.loader.uploadTotal=l.total),this.loader.uploaded=l.loaded}})}catch(l){throw r.isAnonymousUnauthorized?.(l)===!0?Rt():pt(l,e)}const i=a?.url??a?.urls?.default;if(typeof i!="string"||i==="")throw pt({response:{data:a}},e);return{default:i}}abort(){this.controller.abort()}}function zt(t){return function(e){const r=typeof t=="string"?{legacyUrl:t}:{...t,getContent:()=>typeof e.getData=="function"?e.getData():""};e.plugins.get("FileRepository").createUploadAdapter=n=>new Te(n,r)}}const Bt=Object.freeze({video:"g7VideoUpload",audio:"g7AudioUpload"});function Me(t){return t?.policy?["video","audio"].filter(o=>t.policy[o]?.enabled===!0):[]}function Ue(t,o){const e=o.replace(/&/g,"&amp;").replace(/"/g,"&quot;").replace(/</g,"&lt;");return t==="video"?`<video controls="controls" preload="metadata" src="${e}"></video>`:`<audio controls="controls" preload="metadata" src="${e}"></audio>`}function V(t,o,e){const r=window?.G7Core?.t,n=typeof r=="function"?r(t,o):null;return typeof n=="string"&&n!==t?n:e}async function De(t,o,e,r){const n=window?.G7Core?.contentMedia,a=window?.G7Core?.toast?.error??(()=>{});if(!n)return;const i=n.validateFile(o,r);if(!i?.ok||i.kind!==e){a(V(i?.messageKey??"core.content_media.client.kind_not_allowed",i?.params,"Not allowed."));return}const l=typeof t.getData=="function"?t.getData():"";if(n.isAtLimit?.(l,o)===!0){a(V("core.content_media.client.limit_reached",{max:o.policy?.maxPerDocument??0},"Too many media."));return}try{const u=await n.upload(o,r,{}),s=t.data.processor.toView(Ue(e,u.url)),c=t.data.toModel(s);t.model.insertContent(c)}catch(u){if(window?.G7Core?.api?.isAnonymousUnauthorized?.(u)===!0){a(V("sirsoft-ckeditor5.editor.upload_login_required",void 0,"Sign in to upload. Your draft is kept."));return}const s=u?.response?.data?.message;a(typeof s=="string"&&s!==""?s:V("core.content_media.client.upload_failed",void 0,"Upload failed."))}}function Oe(t,o){return function(r){const n=window.CKEDITOR;if(!(!n?.FileDialogButtonView||o.length===0))for(const a of o){const i=t.policy?.[a]?.extensions??[],l=t.policy?.[a]?.mimeTypes??[],u=a==="video"?V("core.content_media.client.video_button",void 0,"Insert video"):V("core.content_media.client.audio_button",void 0,"Insert audio");r.ui.componentFactory.add(Bt[a],s=>{const c=new n.FileDialogButtonView(s);return c.set({acceptedType:[...i.map(f=>`.${f}`),...l].join(","),allowMultipleFiles:!1}),c.buttonView.set({label:u,withText:!0,tooltip:!0}),c.on("done",(f,g)=>{const k=Array.from(g??[])[0];k&&De(r,t,a,k)}),c})}}}const nt="data-ckeditor5-fallback",rt=new WeakMap;function at(t,o,e,r){const n=window.G7Core;if(!n?.state?.setLocal||!t)return;e&&r&&n.state.getLocal?.()?.hasChanges!==!0&&n.state.setLocal({hasChanges:!0});const a={[`form.${t}_mode`]:"text"};if(typeof o=="string")a[`form.${t}`]=o;else for(const[i,l]of Object.entries(o))a[`form.${t}.${i}`]=l;n.state.setLocal(a,{render:!1,selfManaged:!0})}function gt(t){const{container:o,name:e,height:r,readOnly:n,placeholder:a,multilingual:i}=t;if(o.querySelector(`[${nt}]`))return;o.innerHTML="";const l=t.trackChanges!==!1,u=i?t.locales??[]:[],s=t.activeLocale??u[0]??"",c=i?{...t.contentMap??{}}:{"":t.initialContent??""},f=document.createElement("textarea");f.setAttribute(nt,"1"),f.name=e,f.readOnly=n,f.placeholder=a??"",f.style.cssText=["width:100%",`height:${r}px`,"padding:12px","border:1px solid #d1d5db","border-radius:6px","font-family:inherit","font-size:14px","line-height:1.6","resize:vertical","box-sizing:border-box"].join(";");let g=s;const k=new Map,d=()=>i?g:"";(i?u:[""]).forEach(p=>{const I=lt({getData:()=>p===d()?f.value:c[p]??"",setData:v=>{c[p]=v,p===d()&&(f.value=v)}},{name:e,locale:p,multilingual:i,seedPath:t.seedPath,seedValue:c[p]??""});k.set(p,I),o.id&&Z(o.id,I)});const m=()=>[...k.values()].every(p=>p.shouldEmit());if(i){const p=document.createElement("div");p.className="ckeditor-locale-tabs";const I=()=>{p.querySelectorAll("button").forEach(v=>{const M=v.dataset.locale??"",S=M===g;v.classList.toggle("ckeditor-locale-tab--active",S);const P=(c[M]??"").trim()!=="",R=v.querySelector("[data-check]");R&&(R.style.display=P?"":"none")})};u.forEach((v,M)=>{const S=document.createElement("button");S.type="button",S.dataset.locale=v,S.classList.add("ckeditor-locale-tab"),M===0&&S.classList.add("ckeditor-locale-tab--default"),S.textContent=Mt(v);const P=document.createElement("span");P.dataset.check="1",P.textContent="✓",P.style.display="none",S.appendChild(P),S.addEventListener("click",()=>{c[g]=f.value,g=v,f.value=c[v]??"",f.dataset.locale=v,m()&&at(e,c,!1,l),I()}),p.appendChild(S)}),o.appendChild(p),f.dataset.locale=g,f.value=c[g]??"",I(),f.addEventListener("input",()=>{c[g]=f.value,m()&&(k.get(g)?.noteEmitted(f.value),at(e,c,!0,l)),I()})}else f.value=c[""]??"",f.addEventListener("input",()=>{c[""]=f.value,m()&&(k.get("")?.noteEmitted(f.value),at(e,f.value,!0,l))});o.appendChild(f),rt.set(o,{multilingual:i,values:c,activeLocale:g,syncs:k}),at(e,i?c:c[""]??"",!1,l)}function Fe(t){const o=rt.get(t);if(!o)return null;const e=t.querySelector(`textarea[${nt}]`);return e&&(o.values[o.multilingual?e.dataset.locale??o.activeLocale:""]=e.value),o.multilingual?{...o.values}:o.values[""]??""}function Re(t){return t.querySelector(`[${nt}]`)?(rt.get(t)?.syncs.forEach(o=>o.stop()),t.innerHTML="",rt.delete(t),!0):!1}const jt="ckeditor5-dark-override",Vt="ckeditor5-tab-style",Wt={minimal:["bold","italic","underline","|","link","uploadImage","|","undo","redo"],standard:["heading","|","bold","italic","underline","strikethrough","|","alignment","|","link","blockQuote","|","bulletedList","numberedList","indent","outdent","|","insertTable","uploadImage","|","undo","redo"],full:["heading","|","fontSize","fontColor","fontBackgroundColor","|","bold","italic","underline","strikethrough","|","alignment","|","link","blockQuote","insertTable","uploadImage","mediaEmbed","horizontalLine","|","bulletedList","numberedList","indent","outdent","|","codeBlock","sourceEditing","|","undo","redo"]};function Ne(t){if(t==="en")return Promise.resolve();const o=`ckeditor5-translations-${t}`;if(document.getElementById(o))return Promise.resolve();const e=window?.G7Core?.asset?.loadScript;return new Promise(r=>{let n;try{n=ge(t)}catch(i){console.warn("[ckeditor5] 번역 URL 을 만들지 못했습니다 (영어로 동작):",i),r();return}if(typeof e=="function"){e(n,{id:o},{label:`ckeditor5 translations: ${t}`,retries:1}).catch(i=>{console.warn(`[ckeditor5] 번역 로드 실패 (${t}) — 영어로 동작합니다:`,i)}).then(()=>r());return}const a=document.createElement("script");a.id=o,a.src=n,a.onload=()=>r(),a.onerror=()=>{console.warn(`[ckeditor5] 번역 로드 실패 (${t}) — 영어로 동작합니다.`),r()},document.head.appendChild(a)})}async function qt(){if(document.getElementById(dt))return;const t=window?.G7Core?.asset?.loadStylesheet;let o;try{o=It()}catch(e){console.warn("[ckeditor5] CSS URL 을 만들지 못했습니다:",e);return}if(typeof t!="function"){const e=document.createElement("link");e.id=dt,e.rel="stylesheet",e.href=o,document.head.appendChild(e);return}try{await t(o,{id:dt},{label:"ckeditor5 CSS"})}catch(e){console.warn("[ckeditor5] CSS 로드 실패:",e),et("ckeditor5-css",O("editor.asset.style_label","편집기 스타일"),()=>qt())}}async function kt(t,o,e,r,n){const a=Fe(e);if(!await Tt())throw new Error("[sirsoft-ckeditor5] 편집기 본체를 여전히 불러올 수 없습니다.");Re(e);const i={...t,params:{...t.params??{},content:r?a??{}:a??""}};if(await te(i,o),!n)return;window.G7Core?.state?.setLocal?.({[`form.${n}_mode`]:"html"})}function O(t,o){const e=window?.G7Core?.t;if(typeof e!="function")return o;const r=`sirsoft-ckeditor5.${t}`,n=e(r);return typeof n=="string"&&n!==r?n:o}function ze(){if(document.getElementById(Vt))return;const t=document.createElement("style");t.id=Vt,t.textContent=`
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
    `,document.head.appendChild(t)}function Be(){return document.documentElement.getAttribute("data-g7-color-scheme")==="dark"}function Ht(){const t=document.getElementById(jt);if(!Be()){t&&t.remove();return}if(t)return;const o=document.createElement("style");o.id=jt,o.textContent=`
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
    `,document.head.appendChild(o)}let W=null;function je(){W||(W=new MutationObserver(()=>{Ht()}),W.observe(document.documentElement,{attributes:!0,attributeFilter:["data-g7-color-scheme"]}))}function Ve(){W&&(W.disconnect(),W=null)}function Yt(){const t=window.G7Core;return t?.locale?.supported?t.locale.supported():[localStorage.getItem("g7_locale")||"ko"]}function A(){return window.G7Core?.locale?.current?.()??localStorage.getItem("g7_locale")??"ko"}function We(t,o,e,r=!1){const n=[t.Essentials,t.Bold,t.Italic,t.Underline,t.Strikethrough,t.Link,t.Paragraph,t.Heading,t.BlockQuote,t.List,t.Alignment,t.Table,t.TableToolbar,t.TableProperties,t.TableCellProperties,t.Indent,t.IndentBlock,t.PasteFromOffice,t.GeneralHtmlSupport,t.Image,t.ImageResize,t.ImageStyle,t.ImageCaption,t.ImageToolbar];return o==="full"&&n.push(t.MediaEmbed,t.HorizontalLine,t.CodeBlock,t.SourceEditing,t.FontSize,t.FontColor,t.FontBackgroundColor),r&&o!=="full"&&n.push(t.MediaEmbed),e&&n.push(t.ImageUpload),n}function Qt(){return ue("/api/plugins/sirsoft-ckeditor5/upload")}function qe(){try{return window?.G7Core?.contentPolicy?.editorConstraints?.()??null}catch{return null}}function He(t){return(t??[]).map(o=>({name:String(o.id),url:(o.patterns??[]).map(e=>{try{return new RegExp(e,"i")}catch{return null}}).filter(e=>e!==null),html:e=>{const r=e?.groups?.id??"";return`<div style="position:relative;padding-bottom:56.25%;height:0;"><iframe src="${String(o.player_url??"").replace("{id}",encodeURIComponent(r)).replace(/"/g,"&quot;")}" style="position:absolute;inset:0;width:100%;height:100%;border:0;" allowfullscreen></iframe></div>`}})).filter(o=>o.url.length>0)}function Ye(t){let o=t;if(typeof o=="string"){const r=o.trim();if(r===""||r==="null"||r==="undefined"||r.startsWith("{{"))return null;try{o=JSON.parse(r)}catch{return null}}if(o==null||typeof o!="object")return null;const e=window?.G7Core?.contentMedia?.normalizeTarget;return typeof e=="function"?e(o)??null:null}function Qe(t){const o={jpg:"jpeg",jpeg:"jpeg",png:"png",gif:"gif",webp:"webp",avif:"avif"},e=t.map(r=>o[String(r).toLowerCase()]).filter(r=>!!r);return Array.from(new Set(e))}async function Jt(t){try{await t?.destroy?.()}catch{}}function Xt(t,o){w.get(t)===o&&w.delete(t)}function Je(t){let o=null;try{o=window?.G7Core?.AuthManager?.getInstance?.()?.getUser?.()??null}catch{o=null}return(o??t?._global?.currentUser??t?.currentUser)?.is_admin===!0}function Xe(t){const o=window?.G7Core?.toast?.error;if(typeof o!="function")return;let e;try{e=t?.plugins?.get?.("Notification")}catch{return}e?.on?.("show:warning",(r,n)=>{const a=typeof n?.message=="string"&&n.message!==""?n.message:typeof n?.title=="string"?n.title:"";a!==""&&o(a),r.stop()},{priority:"high"})}async function Zt(t,o){const e=window.CKEDITOR;if(!e)throw new Error("[sirsoft-ckeditor5] CKEDITOR 전역 객체를 찾을 수 없습니다. CDN 스크립트가 로드되었는지 확인하세요.");const r=Wt[o.toolbar]||Wt.standard,n=qe(),a=n?.allowExternalEmbed===!0;let i=o.imageUpload?r:r.filter(p=>p!=="uploadImage");if(a&&!i.includes("mediaEmbed")){const p=i.indexOf("insertTable");i=[...i.slice(0,p+1),"mediaEmbed",...i.slice(p+1)]}n&&!a&&(i=i.filter(p=>p!=="mediaEmbed"));const l=Me(o.mediaTarget??null),u=l.map(p=>Bt[p]),s=i.indexOf("uploadImage")>=0?i.indexOf("uploadImage"):i.indexOf("insertTable"),c=u.length>0?[...i.slice(0,s+1),...u,...i.slice(s+1)]:i,f={initialData:o.initialContent,plugins:We(e,o.toolbar,o.imageUpload,a),toolbar:{items:c},table:{contentToolbar:["tableColumn","tableRow","mergeTableCells","|","tableProperties","tableCellProperties"],tableProperties:{defaultProperties:{borderStyle:"solid",borderColor:"#d1d5db",borderWidth:"1px",alignment:"left"}},tableCellProperties:{defaultProperties:{borderStyle:"solid",borderColor:"#d1d5db",borderWidth:"1px",padding:"8px"}}},image:{toolbar:["imageStyle:inline","imageStyle:alignLeft","imageStyle:alignCenter","imageStyle:alignRight","|","toggleImageCaption","|","resizeImage"],resizeOptions:[{name:"resizeImage:original",label:"Original",value:null},{name:"resizeImage:25",label:"25%",value:"25"},{name:"resizeImage:50",label:"50%",value:"50"},{name:"resizeImage:75",label:"75%",value:"75"}],resizeUnit:"%",upload:{types:o.mediaTarget?Qe(o.mediaTarget.policy?.image?.extensions??[]):[...$e]}},indentBlock:{offset:40,unit:"px"},link:{defaultProtocol:"https://",decorators:{openInNewTab:{mode:"automatic",callback:p=>p.startsWith("http://")||p.startsWith("https://"),attributes:{target:"_blank",rel:"noopener noreferrer"}}}},htmlSupport:{allow:[{name:/.*/,attributes:!0,classes:!0,styles:!0}],...n&&(n.forbidTags??[]).length>0?{disallow:[{name:new RegExp(`^(${n.forbidTags.map(p=>p.replace(/[^a-z0-9-]/gi,"")).join("|")})$`)}]}:{}},placeholder:o.placeholder,language:A()};n&&(f.mediaEmbed={previewsInData:!1,providers:[],extraProviders:He(n.providers??[])});const g=[];o.imageUpload&&g.push(o.mediaTarget?zt({target:o.mediaTarget,legacyUrl:Qt()}):zt(Qt())),l.length>0&&g.push(Oe(o.mediaTarget,l)),g.length>0&&(f.extraPlugins=g);const k=await e.ClassicEditor.create(t,f);Xe(k);const d=`ckeditor5-height-style-${o.containerId}`,m=document.getElementById(d);if(m)m.textContent=`#${o.containerId} .ck-editor__editable { min-height: ${o.height}px !important; }`;else{const p=document.createElement("style");p.id=d,p.textContent=`#${o.containerId} .ck-editor__editable { min-height: ${o.height}px !important; }`,document.head.appendChild(p)}return o.readOnly&&k.enableReadOnlyMode("read-only"),k}function Ze(t,o,e,r,n){const a=document.createElement("div");a.className="ckeditor-locale-tabs";const i=new Map;o.forEach(s=>{const c=document.createElement("div");c.dataset.locale=s,s!==e&&(c.style.cssText="display:none;");const f=document.createElement("div");c.appendChild(f),i.set(s,f)});const l=()=>{n&&a.querySelectorAll("button").forEach(s=>{const c=s.dataset.locale;if(!c||s.classList.contains("ckeditor-locale-tab--active")){const d=s.querySelector(".ckeditor-check");d&&d.remove();return}const g=!!n(c)?.trim(),k=s.querySelector(".ckeditor-check");if(g&&!k){const d=document.createElement("i");d.className="ckeditor-icon ckeditor-icon--check ckeditor-check",s.appendChild(d)}else!g&&k&&k.remove()})},u=o[0];return o.forEach(s=>{const c=s===u,f=s===e,g=document.createElement("button");g.type="button",g.dataset.locale=s,g.className=["ckeditor-locale-tab",f?"ckeditor-locale-tab--active":"",c?"ckeditor-locale-tab--default":""].filter(Boolean).join(" "),xe(g,s,c),g.addEventListener("click",async()=>{a.querySelectorAll("button").forEach(k=>{const m=k.dataset.locale===s;k.classList.toggle("ckeditor-locale-tab--active",m)}),i.forEach((k,d)=>{const m=k.parentElement;m&&(m.style.display=d===s?"block":"none")}),r&&await r(s),l()}),a.appendChild(g)}),t.appendChild(a),i.forEach(s=>{s.parentElement&&t.appendChild(s.parentElement)}),{tabsEl:a,contentEls:i,updateCheckIcons:l}}function Kt(t,o,e,r,n=!0,a=null){if(de())return;const i=window.G7Core;if(!i?.state?.setLocal)return;if(n&&i.state.getLocal?.()?.hasChanges!==!0&&i.state.setLocal({hasChanges:!0}),a&&a.scope==="global"){const s=r?`${a.path}.${o}`:a.path;window.G7Core?.state?.set?.({[s]:e});return}const l=a?.path??`form.${t}`,u={[`form.${t}_mode`]:"html"};r?u[`${l}.${o}`]=e:u[l]=e,i.state.setLocal(u,{debounce:300,debounceKey:`ckeditor-sync-${t}`,render:!1,selfManaged:!0})}function ht(t,o){const e=window.G7Core,r=typeof t.content=="string"?t.content:"";let a=r.startsWith("{{")&&r.endsWith("}}")?"":r;if(!a&&o){const l=(e?.state?.getLocal?.()??{})?.form?.[o];typeof l=="string"&&l&&(a=l)}return a}function bt(t,o){const e=window.G7Core;let r={};try{typeof t.content=="string"&&t.content.startsWith("{")&&!t.content.startsWith("{{")?r=JSON.parse(t.content):typeof t.content=="object"&&t.content!==null&&(r=t.content)}catch{r={}}if(Object.keys(r).length===0&&o){const a=(e?.state?.getLocal?.()??{})?.form?.[o];if(a&&typeof a=="object"&&!Array.isArray(a)?r=a:typeof a=="string"&&a&&(r={[A()]:a}),Object.keys(r).length===0){const i=e?.state?.get?.()??{},u=(i?._global?.selectedItem??i?.selectedItem)?.[o];u&&typeof u=="object"&&!Array.isArray(u)&&(r=u)}}return r}async function te(t,o){const e=t.params??{},r=e.name??"content",n=`ckeditor5-${r}`,a=T(location.pathname),i=e.multilingual===!0||e.multilingual==="true",l=i?"":ht(e,r),u=i?bt(e,r):{};St(n);const s=w.get(n);if(s&&s.size>0){const y=[];s.forEach(b=>{b&&typeof b.destroy=="function"&&y.push(b.destroy().catch(()=>{}))}),await Promise.allSettled(y),w.delete(n)}const c=document.getElementById(n);if(!c){console.warn(`[sirsoft-ckeditor5] 컨테이너 엘리먼트를 찾을 수 없습니다: #${n}`);return}qt(),ze(),Ht(),je(),await Ne(A());const g=window.G7Core?.state?.get()||{},k=g._global?.plugins?.["sirsoft-ckeditor5"]??g.plugins?.["sirsoft-ckeditor5"]??{},d=e.name??"content",m=e.multilingual===!0||e.multilingual==="true",p=e.readOnly===!0||e.readOnly==="true",I=()=>Ot(c,p),v=e.imageUpload!==void 0?e.imageUpload===!0||e.imageUpload==="true":k.imageUpload===!0||k.imageUpload==="true",M=Ye(e.mediaUpload),S=M?M.policy?.image?.enabled===!0:v&&Je(g),P=e.placeholder??"",R=e.height!==void 0?Number(e.height)||400:Number(k.editorHeight)||400,to=(e.toolbar!==void 0?e.toolbar:k.toolbar)??"standard",Y=!(e.trackChanges===!1||e.trackChanges==="false"),eo=e.writeScope==="global"?"global":"local",re=typeof e.writePath=="string"&&e.writePath.trim()!==""&&!e.writePath.includes("{{")?e.writePath.trim():null,$=re===null?null:{scope:eo,path:re},Ct=(y,b)=>{if(!$)return;const _=window.G7Core,h=$.scope==="global"?_?.state?.get?.():_?.state?.getLocal?.(),N=(b?`${$.path}.${y}`:$.path).split(".").reduce((z,L)=>z?.[L],h);return typeof N=="string"?N:void 0},oo=(y,b)=>{if($)return Ct(y,b);const _=window.G7Core?.state?.getLocal?.()?.form,h=b?_?.[d]?.[y]:_?.[d];return typeof h=="string"?h:void 0},it=(y,b,_,h)=>{if(T(location.pathname)===a)return{value:_(),path:a};const E=oo(y,b)??"";return E!==h?{value:E,path:T(location.pathname)}:{value:E,path:a}};let ae=m?bt(e,d):{};const no=await Tt();if(!c.isConnected)return;if(Z(n,Ge(c,()=>w.get(n)?.values()??[],p)),!no){const y=m?Yt():[],b=m?y.map(h=>{const E=bt(e,d);return{locale:h,...it(h,!0,()=>E[h]??"",u[h]??"")}}):[{locale:"",...it(A(),!1,()=>ht(e,d),l)}],_={container:c,name:d,height:R,readOnly:I(),placeholder:P,trackChanges:Y,multilingual:m,locales:m?y:void 0,activeLocale:A(),contentMap:m?Object.fromEntries(b.map(h=>[h.locale,h.value])):void 0,initialContent:m?void 0:b[0].value,seedPath:b.find(h=>h.path!==T(location.pathname))?.path};gt(_),et(`ckeditor5-editor:${n}`,O("editor.asset.label","편집기"),()=>kt(t,o,c,_.multilingual,d),O("editor.asset.fallback_notice","편집기를 불러오지 못했습니다. 임시 입력창으로 전환했습니다. 작성한 내용은 그대로 저장됩니다."));return}const U=new Map;w.set(n,U);const ie={placeholder:P,get readOnly(){return I()},imageUpload:S,height:R,toolbar:to,containerId:n,mediaTarget:M};if(m){const y=Yt(),b=A(),_={current:new Map},h={current:()=>{}},E=async C=>{if(U.has(C))return;const Q=_.current.get(C);if(!Q)return;const le=it(C,!0,()=>ae[C]??"",u[C]??""),ce=le.value;try{const B=await Zt(Q,{...ie,initialContent:ce});if(!c.isConnected){await Jt(B);return}U.set(C,B);let se=!0;const _t=lt(B,{name:d,locale:C,multilingual:!0,readExternal:$?()=>Ct(C,!0):void 0,seedPath:le.path,seedValue:ce});if(Z(n,_t),B.model.document.on("change:data",()=>{if(se||!_t.shouldEmit())return;const J=B.getData();_t.noteEmitted(J),Kt(d,C,J,!0,Y,$),h.current()}),se=!1,d){const J=window.G7Core;(J?.state?.getLocal?.()??{}).form?.[`${d}_mode`]!=="html"&&J.state.setLocal({[`form.${d}_mode`]:"html"})}}catch(B){console.error(`[sirsoft-ckeditor5] 에디터 초기화 오류 (locale: ${C}):`,B)}},N=C=>{const Q=U.get(C);return Q?Q.getData?.()??null:null},{contentEls:z,updateCheckIcons:L}=Ze(c,y,b,E,N);if(_.current=z,h.current=L,await E(b),!c.isConnected){Xt(n,U);return}if(!U.has(b)){w.delete(n),gt({container:c,name:d,height:R,readOnly:I(),placeholder:P,multilingual:!0,locales:y,activeLocale:b,contentMap:ae,trackChanges:Y}),et(`ckeditor5-editor:${n}`,O("editor.asset.label","편집기"),()=>kt(t,o,c,!0,d),O("editor.asset.fallback_notice","편집기를 불러오지 못했습니다. 임시 입력창으로 전환했습니다. 작성한 내용은 그대로 저장됩니다."));return}setTimeout(()=>{y.filter(C=>C!==b).forEach(C=>{E(C)})},2e3)}else{const y=it(A(),!1,()=>ht(e,d),l),b=y.value,_=document.createElement("div");c.appendChild(_);try{const h=await Zt(_,{...ie,initialContent:b});if(!c.isConnected){await Jt(h),Xt(n,U);return}const E=A();U.set(E,h);let N=!0;const z=lt(h,{name:d,locale:E,multilingual:!1,readExternal:$?()=>Ct(E,!1):void 0,seedPath:y.path,seedValue:b});if(Z(n,z),h.model.document.on("change:data",()=>{if(N||!z.shouldEmit())return;const L=h.getData();z.noteEmitted(L),Kt(d,E,L,!1,Y,$)}),N=!1,b){const L=h.getData();(!L||L.trim()==="")&&h.setData(b)}if(d){const L=window.G7Core;(L?.state?.getLocal?.()??{}).form?.[`${d}_mode`]!=="html"&&L.state.setLocal({[`form.${d}_mode`]:"html"})}}catch(h){console.error("[sirsoft-ckeditor5] 에디터 초기화 오류:",h),w.delete(n),gt({container:c,name:d,height:R,readOnly:I(),placeholder:P,multilingual:!1,activeLocale:A(),initialContent:b,trackChanges:Y,seedPath:y.path}),et(`ckeditor5-editor:${n}`,O("editor.asset.label","편집기"),()=>kt(t,o,c,!1,d),O("editor.asset.fallback_notice","편집기를 불러오지 못했습니다. 임시 입력창으로 전환했습니다. 작성한 내용은 그대로 저장됩니다."))}}}async function Ke(t,o){const r=`ckeditor5-${t.params?.name??"content"}`;St(r);const n=w.get(r);if(!n||n.size===0)return;const a=[];n.forEach(l=>{l&&typeof l.destroy=="function"&&a.push(l.destroy().catch(u=>{console.warn("[sirsoft-ckeditor5] destroyEditor error:",u)}))}),await Promise.allSettled(a),w.delete(r);const i=document.getElementById(`ckeditor5-height-style-${r}`);i&&i.remove(),w.size===0&&Ve()}const x="sirsoft-ckeditor5",yt="ckeditor5-content-styles",ee="ckeditor5-content-styles-override";function oe(){if(!document.getElementById(yt)){const t=window?.G7Core?.asset;try{const o=It();if(typeof t?.loadStylesheet=="function")t.loadStylesheet(o,{id:yt},{label:"ckeditor5 content CSS"}).catch(e=>{console.warn("[ckeditor5] 본문 스타일 로드 실패:",e),window?.G7Core?.assets?.notifyFailure?.({id:"ckeditor5-content-css",label:"본문 스타일",retry:()=>oe()})});else{const e=document.createElement("link");e.id=yt,e.rel="stylesheet",e.href=o,document.head.appendChild(e)}}catch(o){console.warn("[ckeditor5] 본문 스타일 URL 을 만들지 못했습니다:",o)}}if(!document.getElementById(ee)){const t=document.createElement("style");t.id=ee,t.textContent=`
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
        `,document.head.appendChild(t)}}const F={initEditor:te,destroyEditor:Ke,injectContentCss:oe},H=window.G7Core?.createLogger?.(`Plugin:${x}`)??{log:(...t)=>console.log(`[Plugin:${x}]`,...t),warn:(...t)=>console.warn(`[Plugin:${x}]`,...t),error:(...t)=>console.error(`[Plugin:${x}]`,...t)};function ne(t=!1){const o=window.G7Core?.getActionDispatcher?.();if(o)Object.entries(F).forEach(([e,r])=>{const n=`${x}.${e}`;o.registerHandler(n,r,{category:"plugin",source:x})}),H.log(`${Object.keys(F).length} handler(s) registered:`,Object.keys(F).map(e=>`${x}.${e}`));else if(t){let e=0;const r=50,n=()=>{const a=window.G7Core?.getActionDispatcher?.();a?(Object.entries(F).forEach(([i,l])=>{const u=`${x}.${i}`;a.registerHandler(u,l,{category:"plugin",source:x})}),H.log(`${Object.keys(F).length} handler(s) registered:`,Object.keys(F).map(i=>`${x}.${i}`))):(e++,e<=r?(H.warn(`ActionDispatcher not found, retrying... (${e}/${r})`),setTimeout(n,100)):H.error("Failed to register handlers: ActionDispatcher not available after maximum retries"))};n()}else H.warn("ActionDispatcher not found, handlers not registered")}function wt(){if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",()=>ne(!0));else{const t=!!window.G7Core?.getActionDispatcher?.();ne(!t)}}return wt(),typeof window<"u"&&(window.__SirsoftCkeditor5={identifier:x,handlers:Object.keys(F),initPlugin:wt}),G.initPlugin=wt,Object.defineProperty(G,Symbol.toStringTag,{value:"Module"}),G}({});
