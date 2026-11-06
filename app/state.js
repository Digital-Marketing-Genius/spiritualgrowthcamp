/* Pure journey logic, shared by the UI and its verification script. */
(function(root){
// One chapter a day, Genesis 1 (day 1) to Revelation 22 (day 1189), in canonical order.
const CHAPTERS=[50,40,27,36,34,24,21,4,31,24,22,25,29,36,10,13,10,42,150,31,12,8,66,52,5,48,12,14,3,9,1,4,7,3,3,3,2,14,4,28,16,24,21,28,16,16,13,6,6,4,4,5,3,6,4,3,1,13,5,5,3,5,1,1,1,22];
const TOTAL=1189,FREE_DAYS=7;
const validDay = d => Number.isInteger(d) && d >= 1 && d <= TOTAL;
// Day number -> {book (0-based index), chapter}. Day 51 is Exodus 1.
function locate(day){let d=day;for(let b=0;b<CHAPTERS.length;b++){if(d<=CHAPTERS[b])return {book:b,chapter:d};d-=CHAPTERS[b];}return null;}
function dayOf(book,chapter){let d=chapter;for(let b=0;b<book;b++)d+=CHAPTERS[b];return d;}
function firstDayOfBook(book){return dayOf(book,1);}
const defaultState = () => ({version:1,completed:{},bookmarks:[],journal:{},reviews:{},settings:{font:'standard',theme:'light',mode:'read',reminder:'07:00'},welcomed:false});
function normalize(raw){
 const s=defaultState(); if(!raw || typeof raw!=='object' || Array.isArray(raw)) return s;
 if(raw.completed && typeof raw.completed==='object') for(const [k,v] of Object.entries(raw.completed)) if(validDay(+k) && typeof v==='string' && !isNaN(Date.parse(v))) s.completed[k]=v;
 if(Array.isArray(raw.bookmarks)) s.bookmarks=[...new Set(raw.bookmarks.filter(validDay))];
 if(raw.journal && typeof raw.journal==='object') for(const [k,v] of Object.entries(raw.journal)) if(validDay(+k) && v && typeof v.text==='string' && typeof v.updated==='string' && !isNaN(Date.parse(v.updated))) s.journal[k]={text:v.text.slice(0,20000),updated:v.updated};
 const fields=['title','heading','introduction','commentary','takeaway','reflection','prayer','notes'];
 if(raw.reviews && typeof raw.reviews==='object') for(const [k,v] of Object.entries(raw.reviews)) if(validDay(+k) && v && typeof v==='object') {const r={status:v.status==='reviewed'?'reviewed':'draft'};for(const f of fields) if(typeof v[f]==='string')r[f]=v[f].slice(0,30000);s.reviews[k]=r;}
 const settings=raw.settings||{};
 for(const [k,values] of Object.entries({font:['standard','large','larger'],theme:['light','dark'],mode:['read','listen','both']})) if(values.includes(settings[k])) s.settings[k]=settings[k];
 if(typeof settings.reminder==='string' && /^([01]\d|2[0-3]):[0-5]\d$/.test(settings.reminder)) s.settings.reminder=settings.reminder;
 s.welcomed=raw.welcomed===true;return s;
}
function nextDay(s){for(let i=1;i<=TOTAL;i++)if(!s.completed[i])return i;return null;}
function complete(s,day,now=new Date().toISOString()){if(!validDay(day))throw Error('Choose a chapter between day 1 and day 1189.');if(!s.completed[day])s.completed[day]=now;return nextDay(s);}
function localDateKey(date){return `${date.getFullYear()}-${String(date.getMonth()+1).padStart(2,'0')}-${String(date.getDate()).padStart(2,'0')}`;}
function streaks(s,now=new Date()){
 const days=new Set(Object.values(s.completed).map(d=>localDateKey(new Date(d))));let current=0;const cursor=new Date(now);cursor.setHours(12,0,0,0);
 if(!days.has(localDateKey(cursor)))cursor.setDate(cursor.getDate()-1);
 while(days.has(localDateKey(cursor))){current++;cursor.setDate(cursor.getDate()-1);}
 let longest=0,run=0,prev=null; for(const day of [...days].sort()){const d=new Date(day+'T12:00:00');const expected=prev?new Date(prev):null;if(expected)expected.setDate(expected.getDate()+1);run=expected && localDateKey(expected)===day?run+1:1;longest=Math.max(longest,run);prev=d;}return{current,longest};
}
root.TTB_ENGINE={validDay,defaultState,normalize,nextDay,complete,streaks,locate,dayOf,firstDayOfBook,CHAPTERS,TOTAL,FREE_DAYS};
})(typeof window!=='undefined'?window:globalThis);
