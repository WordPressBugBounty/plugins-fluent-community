var E=(e,s,a)=>{if(!s.has(e))throw TypeError("Cannot "+a)};var l=(e,s,a)=>(E(e,s,"read from private field"),a?a.call(e):s.get(e)),I=(e,s,a)=>{if(s.has(e))throw TypeError("Cannot add the same private member more than once");s instanceof WeakSet?s.add(e):s.set(e,a)},T=(e,s,a,t)=>(E(e,s,"write to private field"),t?t.call(e,a):s.set(e,a),a);import{d as H}from"./admin_index6.js?ver=2.12.0";import{j as N,w as P,s as S,v as z,x as b,y as M,z as _,A as D,d as G,l as J}from"./admin_todoList.js?ver=2.12.0";import{aZ as U,a_ as V,a$ as Z,b0 as q,b1 as B,aJ as A,b2 as v,b3 as K,b4 as Q,b5 as W,b6 as X,aM as R}from"./admin_app.js?ver=2.12.0";import{b as j,G as Y,A as x}from"./admin_index.es.js?ver=2.12.0";import"./admin_floating-ui.dom.js?ver=2.12.0";const F=({ctx:e,hide:s,show:a,config:t})=>{var h,p,y,k,w;const c=P();S(()=>{c()},[a]);const n=o=>m=>{m.preventDefault(),e&&o(e),c()},d=o=>{if(!e)return!1;const m=e.get(A),{state:{doc:u,selection:$}}=m;return u.rangeHasMark($.from,$.to,o)};return z`<host>
    <button
      class=${b("toolbar-item",e&&d(U.type(e))&&"active")}
      onmousedown=${n(o=>{o.get(v).call(K.key)})}
    >
      ${((h=t==null?void 0:t.boldIcon)==null?void 0:h.call(t))??M}
    </button>
    <button
      class=${b("toolbar-item",e&&d(V.type(e))&&"active")}
      onmousedown=${n(o=>{o.get(v).call(Q.key)})}
    >
      ${((p=t==null?void 0:t.italicIcon)==null?void 0:p.call(t))??_}
    </button>
    <button
      class=${b("toolbar-item",e&&d(Z.type(e))&&"active")}
      onmousedown=${n(o=>{o.get(v).call(W.key)})}
    >
      ${((y=t==null?void 0:t.strikethroughIcon)==null?void 0:y.call(t))??D}
    </button>
    <div class="divider"></div>
    <button
      class=${b("toolbar-item",e&&d(q.type(e))&&"active")}
      onmousedown=${n(o=>{o.get(v).call(X.key)})}
    >
      ${((k=t==null?void 0:t.codeIcon)==null?void 0:k.call(t))??G}
    </button>
    <button
      class=${b("toolbar-item",e&&d(B.type(e))&&"active")}
      onmousedown=${n(o=>{const m=o.get(A),{selection:u}=m.state;if(d(B.type(o))){o.get(j.key).removeLink(u.from,u.to);return}o.get(j.key).addLink(u.from,u.to),s==null||s()})}
    >
      ${((w=t==null?void 0:t.linkIcon)==null?void 0:w.call(t))??J}
    </button>
  </host>`};F.props={ctx:Object,hide:Function,show:Boolean,config:Object};const L=N(F),C=Y("CREPE_TOOLBAR");var i,r;class f{constructor(s,a,t){I(this,i,void 0);I(this,r,void 0);this.update=(n,d)=>{l(this,i).update(n,d)},this.destroy=()=>{l(this,i).destroy(),l(this,r).remove()},this.hide=()=>{l(this,i).hide()};const c=new L;T(this,r,c),l(this,r).ctx=s,l(this,r).hide=this.hide,l(this,r).config=t,T(this,i,new x({content:l(this,r),debounce:20,offset:10,shouldShow(n){const{doc:d,selection:h}=n.state,{empty:p,from:y,to:k}=h,w=!d.textBetween(y,k).length&&h instanceof R,o=!(h instanceof R),m=n.dom.getRootNode().activeElement,u=c.contains(m),$=!n.hasFocus()&&!u,O=!n.editable;return!($||o||p||w||O)}})),l(this,i).onShow=()=>{l(this,r).show=!0,setTimeout(()=>{let n=l(this,r).style.left;n=parseInt(n),n<0?l(this,r).style.left=0:n>350&&(l(this,r).style.left="350px")},0)},l(this,i).onHide=()=>{l(this,r).show=!1},this.update(a)}}i=new WeakMap,r=new WeakMap;H("milkdown-toolbar",L);const nt=(e,s)=>{e.config(a=>{a.set(C.key,{view:t=>new f(a,t,s)})}).use(C)};export{nt as defineFeature};
