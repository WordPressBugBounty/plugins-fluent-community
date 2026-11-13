var E=t=>{throw TypeError(t)};var B=(t,s,a)=>s.has(t)||E("Cannot "+a);var l=(t,s,a)=>(B(t,s,"read from private field"),a?a.call(t):s.get(t)),I=(t,s,a)=>s.has(t)?E("Cannot add the same private member more than once"):s instanceof WeakSet?s.add(t):s.set(t,a),T=(t,s,a,e)=>(B(t,s,"write to private field"),e?e.call(t,a):s.set(t,a),a);import{d as N}from"./admin_index4.js?ver=2.14.0";import{a as P,j as S,d as q,k as z,m as D,s as G,n as M,l as U,f as p,h as V}from"./admin_todoList.js?ver=2.14.0";import{$ as _,R as J,T as K,x as Q,n as R,L as j,o as v,p as W,t as X,z as Y,q as Z,r as A}from"./admin_app.js?ver=2.14.0";import{b as C,G as x,A as f}from"./admin_index.es.js?ver=2.14.0";import"./admin_runtime_vue.js?ver=2.14.0";import"./admin_index.js?ver=2.14.0";import"./admin_floating-ui.dom.js?ver=2.14.0";const L=({ctx:t,hide:s,show:a,config:e})=>{var h,b,y,k,w;const c=S();q(()=>{c()},[a]);const n=o=>m=>{m.preventDefault(),t&&o(t),c()},i=o=>{if(!t)return!1;const m=t.get(j),{state:{doc:u,selection:$}}=m;return u.rangeHasMark($.from,$.to,o)};return V`<host>
    <button
      class=${p("toolbar-item",t&&i(_.type(t))&&"active")}
      onmousedown=${n(o=>{o.get(v).call(W.key)})}
    >
      ${((h=e==null?void 0:e.boldIcon)==null?void 0:h.call(e))??z}
    </button>
    <button
      class=${p("toolbar-item",t&&i(J.type(t))&&"active")}
      onmousedown=${n(o=>{o.get(v).call(X.key)})}
    >
      ${((b=e==null?void 0:e.italicIcon)==null?void 0:b.call(e))??D}
    </button>
    <button
      class=${p("toolbar-item",t&&i(K.type(t))&&"active")}
      onmousedown=${n(o=>{o.get(v).call(Y.key)})}
    >
      ${((y=e==null?void 0:e.strikethroughIcon)==null?void 0:y.call(e))??G}
    </button>
    <div class="divider"></div>
    <button
      class=${p("toolbar-item",t&&i(Q.type(t))&&"active")}
      onmousedown=${n(o=>{o.get(v).call(Z.key)})}
    >
      ${((k=e==null?void 0:e.codeIcon)==null?void 0:k.call(e))??M}
    </button>
    <button
      class=${p("toolbar-item",t&&i(R.type(t))&&"active")}
      onmousedown=${n(o=>{const m=o.get(j),{selection:u}=m.state;if(i(R.type(o))){o.get(C.key).removeLink(u.from,u.to);return}o.get(C.key).addLink(u.from,u.to),s==null||s()})}
    >
      ${((w=e==null?void 0:e.linkIcon)==null?void 0:w.call(e))??U}
    </button>
  </host>`};L.props={ctx:Object,hide:Function,show:Boolean,config:Object};const O=P(L),F=x("CREPE_TOOLBAR");var d,r;class g{constructor(s,a,e){I(this,d);I(this,r);this.update=(n,i)=>{l(this,d).update(n,i)},this.destroy=()=>{l(this,d).destroy(),l(this,r).remove()},this.hide=()=>{l(this,d).hide()};const c=new O;T(this,r,c),l(this,r).ctx=s,l(this,r).hide=this.hide,l(this,r).config=e,T(this,d,new f({content:l(this,r),debounce:20,offset:10,shouldShow(n){const{doc:i,selection:h}=n.state,{empty:b,from:y,to:k}=h,w=!i.textBetween(y,k).length&&h instanceof A,o=!(h instanceof A),m=n.dom.getRootNode().activeElement,u=c.contains(m),$=!n.hasFocus()&&!u,H=!n.editable;return!($||o||b||w||H)}})),l(this,d).onShow=()=>{l(this,r).show=!0,setTimeout(()=>{let n=l(this,r).style.left;n=parseInt(n),n<0?l(this,r).style.left=0:n>350&&(l(this,r).style.left="350px")},0)},l(this,d).onHide=()=>{l(this,r).show=!1},this.update(a)}}d=new WeakMap,r=new WeakMap;N("milkdown-toolbar",O);const mt=(t,s)=>{t.config(a=>{a.set(F.key,{view:e=>new g(a,e,s)})}).use(F)};export{mt as defineFeature};
