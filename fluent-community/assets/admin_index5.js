var E=(e,s,a)=>{if(!s.has(e))throw TypeError("Cannot "+a)};var l=(e,s,a)=>(E(e,s,"read from private field"),a?a.call(e):s.get(e)),I=(e,s,a)=>{if(s.has(e))throw TypeError("Cannot add the same private member more than once");s instanceof WeakSet?s.add(e):s.set(e,a)},T=(e,s,a,t)=>(E(e,s,"write to private field"),t?t.call(e,a):s.set(e,a),a);import{d as H}from"./admin_index4.js?ver=2.13.0";import{a as N,j as P,d as S,h as q,f as p,k as z,m as D,s as G,n as M,l as U}from"./admin_todoList.js?ver=2.13.0";import{$ as V,R as _,T as J,x as K,n as B,L as R,o as v,p as Q,t as W,z as X,q as Y,r as j}from"./admin_app.js?ver=2.13.0";import{b as A,G as Z,A as x}from"./admin_index.es.js?ver=2.13.0";import"./admin_runtime_vue.js?ver=2.13.0";import"./admin_index.js?ver=2.13.0";import"./admin_floating-ui.dom.js?ver=2.13.0";const F=({ctx:e,hide:s,show:a,config:t})=>{var h,b,y,k,w;const c=P();S(()=>{c()},[a]);const n=o=>m=>{m.preventDefault(),e&&o(e),c()},i=o=>{if(!e)return!1;const m=e.get(R),{state:{doc:u,selection:$}}=m;return u.rangeHasMark($.from,$.to,o)};return q`<host>
    <button
      class=${p("toolbar-item",e&&i(V.type(e))&&"active")}
      onmousedown=${n(o=>{o.get(v).call(Q.key)})}
    >
      ${((h=t==null?void 0:t.boldIcon)==null?void 0:h.call(t))??z}
    </button>
    <button
      class=${p("toolbar-item",e&&i(_.type(e))&&"active")}
      onmousedown=${n(o=>{o.get(v).call(W.key)})}
    >
      ${((b=t==null?void 0:t.italicIcon)==null?void 0:b.call(t))??D}
    </button>
    <button
      class=${p("toolbar-item",e&&i(J.type(e))&&"active")}
      onmousedown=${n(o=>{o.get(v).call(X.key)})}
    >
      ${((y=t==null?void 0:t.strikethroughIcon)==null?void 0:y.call(t))??G}
    </button>
    <div class="divider"></div>
    <button
      class=${p("toolbar-item",e&&i(K.type(e))&&"active")}
      onmousedown=${n(o=>{o.get(v).call(Y.key)})}
    >
      ${((k=t==null?void 0:t.codeIcon)==null?void 0:k.call(t))??M}
    </button>
    <button
      class=${p("toolbar-item",e&&i(B.type(e))&&"active")}
      onmousedown=${n(o=>{const m=o.get(R),{selection:u}=m.state;if(i(B.type(o))){o.get(A.key).removeLink(u.from,u.to);return}o.get(A.key).addLink(u.from,u.to),s==null||s()})}
    >
      ${((w=t==null?void 0:t.linkIcon)==null?void 0:w.call(t))??U}
    </button>
  </host>`};F.props={ctx:Object,hide:Function,show:Boolean,config:Object};const L=N(F),C=Z("CREPE_TOOLBAR");var d,r;class f{constructor(s,a,t){I(this,d,void 0);I(this,r,void 0);this.update=(n,i)=>{l(this,d).update(n,i)},this.destroy=()=>{l(this,d).destroy(),l(this,r).remove()},this.hide=()=>{l(this,d).hide()};const c=new L;T(this,r,c),l(this,r).ctx=s,l(this,r).hide=this.hide,l(this,r).config=t,T(this,d,new x({content:l(this,r),debounce:20,offset:10,shouldShow(n){const{doc:i,selection:h}=n.state,{empty:b,from:y,to:k}=h,w=!i.textBetween(y,k).length&&h instanceof j,o=!(h instanceof j),m=n.dom.getRootNode().activeElement,u=c.contains(m),$=!n.hasFocus()&&!u,O=!n.editable;return!($||o||b||w||O)}})),l(this,d).onShow=()=>{l(this,r).show=!0,setTimeout(()=>{let n=l(this,r).style.left;n=parseInt(n),n<0?l(this,r).style.left=0:n>350&&(l(this,r).style.left="350px")},0)},l(this,d).onHide=()=>{l(this,r).show=!1},this.update(a)}}d=new WeakMap,r=new WeakMap;H("milkdown-toolbar",L);const rt=(e,s)=>{e.config(a=>{a.set(C.key,{view:t=>new f(a,t,s)})}).use(C)};export{rt as defineFeature};
