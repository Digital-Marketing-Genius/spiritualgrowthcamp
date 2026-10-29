/* Through the Bible membership: $12 USD a year (shown as $1 a month), billed by Stripe.
   Days 1-7 are free. The database decides access (see is_member / published_devotionals);
   this file only shows membership status and opens Stripe Checkout or the billing portal. */
(function(){
 const FREE_DAYS=7,ACTIVE=['active','trialing','past_due'];
 let uid=null,row=null,loaded=false,busy=false,problem='';
 const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
 const active=()=>!!row&&ACTIVE.includes(row.status)&&(!row.current_period_end||new Date(row.current_period_end).getTime()>Date.now()-3*86400000);
 const fmt=d=>d?new Date(d).toLocaleDateString(undefined,{year:'numeric',month:'long',day:'numeric'}):'';
 const rerender=()=>{try{window.TTB_APP&&TTB_APP.render();}catch{}};
 const say=m=>{const t=document.querySelector('#toast');if(!t)return;t.textContent=m;t.classList.add('visible');setTimeout(()=>t.classList.remove('visible'),5000);};

 async function refresh(){
  const client=TTB_CLOUD.client,session=TTB_CLOUD.session;
  if(!client||!session){row=null;loaded=true;rerender();return;}
  const {data,error}=await client.from('memberships').select('status,current_period_end,cancel_at_period_end').eq('user_id',session.user.id).maybeSingle();
  if(error){problem='Your membership status could not be loaded.';}else{row=data;problem='';}
  loaded=true;rerender();
 }

 async function open(kind,button){
  const session=TTB_CLOUD.session;
  if(!session){say('Create a free account (or sign in) first, so your membership is linked to your journey.');document.querySelector('#signin-email')?.focus();return;}
  busy=true;if(button)button.disabled=true;problem='';
  try{
   const res=await fetch(((window.TTB_CONFIG||{}).apiBase||'/api/app/')+kind+'.php',{method:'POST',headers:{Authorization:'Bearer '+session.access_token}});
   const data=await res.json().catch(()=>({}));
   if(res.ok&&data.url){location.href=data.url;return;}
   if(data.active)await refresh();
   problem=data.error||'Something went wrong. Please try again.';
  }catch{problem='We could not reach the server. Check your connection and try again.';}
  busy=false;if(button)button.disabled=false;rerender();
 }

 function panel(){
  if(!TTB_CLOUD.enabled)return '';
  const head='<div class="section-label">MEMBERSHIP</div>';
  if(TTB_CLOUD.session&&!loaded)return `<section class="card membership-card">${head}<p class="muted">Checking your membership…</p></section>`;
  if(active()){
   const renew=row.cancel_at_period_end?`Your membership ends on ${fmt(row.current_period_end)}.`:`Renews on ${fmt(row.current_period_end)} for $12 USD.`;
   return `<section class="card membership-card is-member">${head}<h3>You are a member.</h3><p class="muted">Every chapter of the Bible is open to you, with commentary and a daily keynote. ${esc(renew)}${row.status==='past_due'?' Your last payment did not go through; please update your card.':''}</p>${problem?`<p class="error-note">${esc(problem)}</p>`:''}<button class="secondary" data-membership="portal">Manage or cancel membership</button></section>`;
  }
  return `<section class="card membership-card">${head}<h3>Continue the whole journey.</h3><p class="muted">Your first ${FREE_DAYS} days are free. Membership opens every chapter from Genesis to Revelation, with audio, commentary, a daily keynote and reminders.</p><p class="price-line"><strong>$1 a month</strong> <span>billed $12 USD a year · cancel any time</span></p>${problem?`<p class="error-note">${esc(problem)}</p>`:''}<button class="primary" data-membership="checkout">${TTB_CLOUD.session?'Become a member · $12 a year':'Sign in above to become a member'}</button><p class="notice">Payments are processed securely by Stripe. Less than a cup of coffee for a whole year in the Word.</p></section>`;
 }

 // Shown under the "seven chapters complete" banner.
 function prompt(){
  if(!TTB_CLOUD.enabled||active())return '';
  return `<div class="membership-prompt"><p><strong>Keep going with Genesis 8.</strong> Continue your journey through the whole Bible for $1 a month, billed $12 a year.</p><button class="primary" data-membership="checkout">Continue my journey</button></div>`;
 }

 document.addEventListener('click',e=>{const el=e.target.closest('[data-membership]');if(!el||busy)return;e.preventDefault();open(el.dataset.membership,el);});

 // Returning from Stripe Checkout: the webhook may take a few seconds to record the membership.
 const params=new URLSearchParams(location.search);const ret=params.get('membership');
 if(ret){history.replaceState(null,'',location.pathname+location.hash);
  if(ret==='success'){let tries=0;const poll=async()=>{await refresh();if(active()){say('Welcome! Your membership is active.');return;}if(++tries<8)setTimeout(poll,2500);else say('Payment received. Your membership will appear in a moment; refresh if it does not.');};setTimeout(poll,1200);}
  else if(ret==='canceled')setTimeout(()=>say('Checkout was cancelled. You have not been charged.'),800);}

 function watch(){TTB_CLOUD.subscribe(()=>{const id=TTB_CLOUD.session?.user?.id||null;if(id!==uid){uid=id;loaded=false;row=null;refresh();}});}
 if(window.TTB_CLOUD)watch();
 window.TTB_MEMBERSHIP={panel,prompt,refresh,get active(){return active();},get loaded(){return loaded;}};
})();
