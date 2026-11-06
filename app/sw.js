/* Through the Bible service worker: makes the app installable and keeps the app shell available offline.
   Network first, so new releases show up straight away. Only this app's own files are cached;
   account data, payments and devotionals (Supabase, /api/) always go to the network. */
const CACHE='ttb-shell-v1';
const SHELL=['./','index.html','styles.css','brand.css','config.js','content.js','state.js','bsb-books.js','scripture.js','cloud.js','membership.js','app.js','vendor/supabase.js','manifest.webmanifest','icons/icon-192.png'];
const scope=new URL(self.registration.scope).pathname;
self.addEventListener('install',e=>{e.waitUntil(caches.open(CACHE).then(c=>c.addAll(SHELL)).catch(()=>{}).then(()=>self.skipWaiting()));});
self.addEventListener('activate',e=>{e.waitUntil(caches.keys().then(keys=>Promise.all(keys.filter(k=>k!==CACHE).map(k=>caches.delete(k)))).then(()=>self.clients.claim()));});
self.addEventListener('fetch',e=>{
 const req=e.request;if(req.method!=='GET')return;
 const url=new URL(req.url);if(url.origin!==location.origin||!url.pathname.startsWith(scope))return;
 const key=url.pathname;// stored without the query string (sign-in codes never land in the cache)
 e.respondWith(fetch(req).then(res=>{if(res.ok&&res.type==='basic'){const copy=res.clone();caches.open(CACHE).then(c=>c.put(key,copy));}return res;})
  .catch(()=>caches.match(key).then(hit=>hit||(req.mode==='navigate'?caches.match(scope):undefined)).then(hit=>hit||Response.error())));
});
