import{j as D,v as c,n as F,m as v,x as I,i as P,c as N,B as R}from"./admin_todoList.js?ver=2.12.0";import{aC as V,b7 as H,b8 as M}from"./admin_app.js?ver=2.12.0";import{a as A,b as K}from"./admin_index.es2.js?ver=2.12.0";var W=Object.defineProperty,U=Object.getOwnPropertySymbols,q=Object.prototype.hasOwnProperty,z=Object.prototype.propertyIsEnumerable,C=(t,e,l)=>e in t?W(t,e,{enumerable:!0,configurable:!0,writable:!0,value:l}):t[e]=l,G=(t,e)=>{for(var l in e||(e={}))q.call(e,l)&&C(t,l,e[l]);if(U)for(var l of U(e))z.call(e,l)&&C(t,l,e[l]);return t};function _(t,e){return Object.assign(t,{meta:G({package:"@milkdown/components"},e)}),t}const J={imageIcon:()=>"🌌",uploadButton:()=>c`Upload`,confirmButton:()=>c`⏎`,uploadPlaceholderText:"/Paste",onUpload:t=>Promise.resolve(URL.createObjectURL(t))},h=V(J,"inlineImageConfigCtx");_(h,{displayName:"Config<image-inline>",group:"ImageInline"});function Q(t,e){const l=customElements.get(t);if(l==null){customElements.define(t,e);return}l!==e&&console.warn(`Custom element ${t} has been defined before.`)}var X=(t,e,l)=>new Promise((u,a)=>{var o=s=>{try{n(l.next(s))}catch(d){a(d)}},i=s=>{try{n(l.throw(s))}catch(d){a(d)}},n=s=>s.done?u(s.value):Promise.resolve(s.value).then(o,i);n((l=l.apply(t,e)).next())});const w=({src:t="",selected:e=!1,alt:l,title:u,setAttr:a,config:o})=>{const i=F(),[n]=v(crypto.randomUUID()),[s,d]=v(!1),[x,k]=v(t.length!==0),[y,O]=v(t),E=r=>{const m=r.target.value;k(m.length!==0),O(m)},L=r=>X(void 0,null,function*(){var p;const m=(p=r.target.files)==null?void 0:p[0];if(!m)return;const b=yield o==null?void 0:o.onUpload(m);b&&(a==null||a("src",b),k(!0))}),$=()=>{var r,p;a==null||a("src",(p=(r=i.current)==null?void 0:r.value)!=null?p:"")},T=r=>{r.key==="Enter"&&$()},j=r=>{r.preventDefault(),r.stopPropagation()},S=r=>{r.stopPropagation(),r.preventDefault()};return c`<host class=${I(e&&"selected",!t&&"empty")}>
    ${t?c`<img class="image-inline" src=${t} alt=${l} title=${u} />`:c`<div class="empty-image-inline">
        <div class="image-icon">
          ${o==null?void 0:o.imageIcon()}
        </div>
        <div class=${I("link-importer",s&&"focus")}>
          <input
            draggable="true"
            ref=${i}
            ondragstart=${j}
            class="link-input-area"
            value=${y}
            oninput=${E}
            onkeydown=${T}
            onfocus=${()=>d(!0)}
            onblur=${()=>d(!1)}
          />
          <div class=${I("placeholder",x&&"hidden")}>
            <input class="hidden" id=${n} type="file" accept="image/*" onchange=${L} />
            <label onpointerdown=${S} class="uploader" for=${n}>
              ${o==null?void 0:o.uploadButton()}
            </label>
            <span class="text" onclick=${()=>{var r;return(r=i.current)==null?void 0:r.focus()}}>
              ${o==null?void 0:o.uploadPlaceholderText}
            </span>
          </div>
        </div>
        <div
          class=${I("confirm",y.length===0&&"hidden")}
          onclick=${()=>$()}
        >
          ${o==null?void 0:o.confirmButton()}
        </div>
      </div>`}
  </host>`};w.props={src:String,alt:String,title:String,selected:Boolean,setAttr:Function,config:Object};const Y=D(w);Q("milkdown-image-inline",Y);const B=H(M.node,t=>(e,l,u)=>{const a=document.createElement("milkdown-image-inline"),o=t.get(h.key),i=n=>{a.src=n.attrs.src,a.alt=n.attrs.alt,a.title=n.attrs.title};return i(e),a.selected=!1,a.setAttr=(n,s)=>{const d=u();d!=null&&l.dispatch(l.state.tr.setNodeAttribute(d,n,s))},a.config=o,{dom:a,update:n=>n.type!==e.type?!1:(i(n),!0),stopEvent:n=>!!(a.selected&&n.target instanceof HTMLInputElement),selectNode:()=>{a.selected=!0},deselectNode:()=>{a.selected=!1},destroy:()=>{a.remove()}}});_(B,{displayName:"NodeView<image-inline>",group:"ImageInline"});const Z=[h,B],te=(t,e)=>{t.config(l=>{l.update(h.key,u=>({uploadButton:(e==null?void 0:e.inlineUploadButton)??(()=>"Upload"),imageIcon:(e==null?void 0:e.inlineImageIcon)??(()=>P),confirmButton:(e==null?void 0:e.inlineConfirmButton)??(()=>N),uploadPlaceholderText:(e==null?void 0:e.inlineUploadPlaceholderText)??"or paste link",onUpload:(e==null?void 0:e.inlineOnUpload)??(e==null?void 0:e.onUpload)??u.onUpload})),l.update(A.key,u=>({uploadButton:(e==null?void 0:e.blockUploadButton)??(()=>"Upload file"),imageIcon:(e==null?void 0:e.blockImageIcon)??(()=>P),captionIcon:(e==null?void 0:e.blockCaptionIcon)??(()=>R),confirmButton:(e==null?void 0:e.blockConfirmButton)??(()=>"Confirm"),captionPlaceholderText:(e==null?void 0:e.blockCaptionPlaceholderText)??"Write Image Caption",uploadPlaceholderText:(e==null?void 0:e.blockUploadPlaceholderText)??"or paste link",onUpload:(e==null?void 0:e.blockOnUpload)??(e==null?void 0:e.onUpload)??u.onUpload}))}).use(K).use(Z)};export{te as defineFeature};
