import{b9 as q,ba as J,bb as Q,aC as X,b7 as Z,bc as ee}from"./admin_app.js?ver=2.12.0";import{j as te,v as B,n as E,m as w,s as C,x as h,k as oe,u as ne}from"./admin_todoList.js?ver=2.12.0";var re=Object.defineProperty,S=Object.getOwnPropertySymbols,ae=Object.prototype.hasOwnProperty,ie=Object.prototype.propertyIsEnumerable,M=(e,t,o)=>t in e?re(e,t,{enumerable:!0,configurable:!0,writable:!0,value:o}):e[t]=o,le=(e,t)=>{for(var o in t||(t={}))ae.call(t,o)&&M(e,o,t[o]);if(S)for(var o of S(t))ie.call(t,o)&&M(e,o,t[o]);return e};function _(e,t){return Object.assign(e,{meta:le({package:"@milkdown/components"},t)}),e}var se=Object.defineProperty,U=Object.getOwnPropertySymbols,ce=Object.prototype.hasOwnProperty,ue=Object.prototype.propertyIsEnumerable,H=(e,t,o)=>t in e?se(e,t,{enumerable:!0,configurable:!0,writable:!0,value:o}):e[t]=o,de=(e,t)=>{for(var o in t||(t={}))ce.call(t,o)&&H(e,o,t[o]);if(U)for(var o of U(t))ue.call(t,o)&&H(e,o,t[o]);return e};const O="image-block",L=q("image-block",()=>({inline:!1,group:"block",selectable:!0,draggable:!0,isolating:!0,marks:"",atom:!0,priority:100,attrs:{src:{default:""},caption:{default:""},ratio:{default:1}},parseDOM:[{tag:`img[data-type="${O}"]`,getAttrs:e=>{var t;if(!(e instanceof HTMLElement))throw J(e);return{src:e.getAttribute("src")||"",caption:e.getAttribute("caption")||"",ratio:Number((t=e.getAttribute("ratio"))!=null?t:1)}}}],toDOM:e=>["img",de({"data-type":O},e.attrs)],parseMarkdown:{match:({type:e})=>e==="image-block",runner:(e,t,o)=>{const u=t.url,s=t.title;let a=Number(t.alt||1);(Number.isNaN(a)||a===0)&&(a=1),e.addNode(o,{src:u,caption:s,ratio:a})}},toMarkdown:{match:e=>e.type.name==="image-block",runner:(e,t)=>{e.openNode("paragraph"),e.addNode("image",void 0,void 0,{title:t.attrs.caption,url:t.attrs.src,alt:`${Number.parseFloat(t.attrs.ratio).toFixed(2)}`}),e.closeNode()}}}));_(L.node,{displayName:"NodeSchema<image-block>",group:"ImageBlock"});function pe(e){return ee(e,"paragraph",(t,o,u)=>{var s,a;if(((s=t.children)==null?void 0:s.length)!==1)return;const n=(a=t.children)==null?void 0:a[0];if(!n||n.type!=="image")return;const{url:r,alt:i,title:c}=n,b={type:"image-block",url:r,alt:i,title:c};u.children.splice(o,1,b)})}const x=Q("remark-image-block",()=>()=>pe);_(x.plugin,{displayName:"Remark<remarkImageBlock>",group:"ImageBlock"});_(x.options,{displayName:"RemarkConfig<remarkImageBlock>",group:"ImageBlock"});const me={imageIcon:()=>"🌌",captionIcon:()=>"💬",uploadButton:()=>B`Upload file`,confirmButton:()=>B`Confirm ⏎`,uploadPlaceholderText:"or paste the image link ...",captionPlaceholderText:"Image caption",onUpload:e=>Promise.resolve(URL.createObjectURL(e))},R=X(me,"imageBlockConfigCtx");_(R,{displayName:"Config<image-block>",group:"ImageBlock"});function ge(e,t){const o=customElements.get(e);if(o==null){customElements.define(e,t);return}o!==t&&console.warn(`Custom element ${e} has been defined before.`)}function fe({image:e,resizeHandle:t,ratio:o,setRatio:u,src:s}){const a=oe(),n=ne(()=>a.current.getRootNode(),[a]);C(()=>{const r=e.current;r&&(delete r.dataset.origin,delete r.dataset.height,r.style.height="")},[s]),C(()=>{const r=t.current,i=e.current;if(!r||!i)return;const c=d=>{d.preventDefault();const p=i.getBoundingClientRect().top,m=d.clientY-p,v=Number(m<100?100:m).toFixed(2);i.dataset.height=v,i.style.height=`${v}px`},b=()=>{n.removeEventListener("pointermove",c),n.removeEventListener("pointerup",b);const d=Number(i.dataset.origin),p=Number(i.dataset.height),m=Number.parseFloat(Number(p/d).toFixed(2));Number.isNaN(m)||u(m)},$=d=>{d.preventDefault(),n.addEventListener("pointermove",c),n.addEventListener("pointerup",b)},N=d=>{const p=a.current.getBoundingClientRect().width;if(!p)return;const m=d.target,v=m.height,y=m.width,I=y<p?v:p*(v/y),P=(I*o).toFixed(2);i.dataset.origin=I.toFixed(2),i.dataset.height=P,i.style.height=`${P}px`};return i.addEventListener("load",N),r.addEventListener("pointerdown",$),()=>{i.removeEventListener("load",N),r.removeEventListener("pointerdown",$)}},[])}var ve=(e,t,o)=>new Promise((u,s)=>{var a=i=>{try{r(o.next(i))}catch(c){s(c)}},n=i=>{try{r(o.throw(i))}catch(c){s(c)}},r=i=>i.done?u(i.value):Promise.resolve(i.value).then(a,n);r((o=o.apply(e,t)).next())});let k=0;const j=({src:e="",caption:t="",ratio:o=1,selected:u=!1,readonly:s=!1,setAttr:a,config:n})=>{const r=E(),i=E(),c=E(),[b,$]=w(t.length>0),[N,d]=w(e.length!==0),[p]=w(crypto.randomUUID()),[m,v]=w(!1),[y,I]=w(e);fe({image:r,resizeHandle:i,ratio:o,setRatio:l=>a==null?void 0:a("ratio",l),src:e}),C(()=>{u||$(t.length>0)},[u]);const P=l=>{const f=l.target.value;k&&window.clearTimeout(k),k=window.setTimeout(()=>{a==null||a("caption",f)},1e3)},z=l=>{const f=l.target.value;k&&(window.clearTimeout(k),k=0),a==null||a("caption",f)},A=l=>{const f=l.target.value;d(f.length!==0),I(f)},Y=l=>ve(void 0,null,function*(){var g;const f=(g=l.target.files)==null?void 0:g[0];if(!f)return;const F=yield n==null?void 0:n.onUpload(f);F&&(a==null||a("src",F),d(!0))}),G=l=>{l.preventDefault(),l.stopPropagation(),!s&&$(g=>!g)},T=()=>{var l,g;a==null||a("src",(g=(l=c.current)==null?void 0:l.value)!=null?g:"")},K=l=>{l.key==="Enter"&&T()},D=l=>{l.preventDefault(),l.stopPropagation()},W=l=>{l.stopPropagation(),l.preventDefault()};return B`<host class=${h(u&&"selected")}>
    <div class=${h("image-edit",e.length>0&&"hidden")}>
      <div class="image-icon">
        ${n==null?void 0:n.imageIcon()}
      </div>
      <div class=${h("link-importer",m&&"focus")}>
        <input
          ref=${c}
          draggable="true"
          ondragstart=${D}
          disabled=${s}
          class="link-input-area"
          value=${y}
          oninput=${A}
          onkeydown=${K}
          onfocus=${()=>v(!0)}
          onblur=${()=>v(!1)}
        />
        <div class=${h("placeholder",N&&"hidden")}>
          <input disabled=${s} class="hidden" id=${p} type="file" accept="image/*" onchange=${Y} />
          <label onpointerdown=${W} class="uploader" for=${p}>
            ${n==null?void 0:n.uploadButton()}
          </label>
          <span class="text" onclick=${()=>{var l;return(l=c.current)==null?void 0:l.focus()}}>
            ${n==null?void 0:n.uploadPlaceholderText}
          </span>
        </div>
      </div>
      <div
        class=${h("confirm",y.length===0&&"hidden")}
        onclick=${()=>T()}
      >
        ${n==null?void 0:n.confirmButton()}
      </div>
    </div>
    <div class=${h("image-wrapper",e.length===0&&"hidden")}>
      <div class="operation">
        <div class="operation-item" onpointerdown=${G}>${n==null?void 0:n.captionIcon()}</div>
      </div>
      <img ref=${r} data-type=${O} src=${e} alt=${t} ratio=${o} />
      <div ref=${i} class="image-resize-handle"></div>
    </div>
    <input
      draggable="true"
      ondragstart=${D}
      class=${h("caption-input",!b&&"hidden")}
      placeholder=${n==null?void 0:n.captionPlaceholderText}
      oninput=${P}
      onblur=${z}
      value=${t}
    />
  </host>`};j.props={src:String,caption:String,ratio:Number,selected:Boolean,readonly:Boolean,setAttr:Function,config:Object};const he=te(j);ge("milkdown-image-block",he);const V=Z(L.node,e=>(t,o,u)=>{const s=document.createElement("milkdown-image-block"),a=e.get(R.key),n=r=>{s.src=r.attrs.src,s.ratio=r.attrs.ratio,s.caption=r.attrs.caption,s.readonly=!o.editable};return n(t),s.selected=!1,s.setAttr=(r,i)=>{const c=u();c!=null&&o.dispatch(o.state.tr.setNodeAttribute(c,r,i))},s.config=a,{dom:s,update:r=>r.type!==t.type?!1:(n(r),!0),stopEvent:r=>r.target instanceof HTMLInputElement,selectNode:()=>{s.selected=!0},deselectNode:()=>{s.selected=!1},destroy:()=>{s.remove()}}});_(V,{displayName:"NodeView<image-block>",group:"ImageBlock"});const $e=[x,L,V,R].flat();export{R as a,$e as b,L as i};
