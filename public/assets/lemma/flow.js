/* Optional local conveniences. Every server mutation still uses the existing form. */
(() => {
 'use strict';
 const user=document.body.dataset.lemmaUser;if(!user)return;
 const $=(s,r=document)=>r.querySelector(s),$$=(s,r=document)=>[...r.querySelectorAll(s)];
 const announce=text=>{const el=$('.lemma-announcer');if(!el)return;el.textContent=text;clearTimeout(announce.timer);announce.timer=setTimeout(()=>el.textContent='',4500)};
 const storage={read(key,fallback=null){try{return JSON.parse(localStorage.getItem(key))??fallback}catch{return fallback}},write(key,value){try{localStorage.setItem(key,JSON.stringify(value));return true}catch{return false}},remove(key){try{localStorage.removeItem(key)}catch{}}};
 const command=$('#lemma-command'),search=$('#lemma-command-query');
 const searchAction=$('.lemma-command-search')?.action;
 const base=searchAction?new URL(searchAction).pathname.replace(/\/search$/,''):'';
 const route=location.pathname.slice(base.length)||'/';
 const key=name=>`lemma:${base}:${user}:${name}`;
 const safeUrl=value=>{try{const u=new URL(value,location.origin);return u.origin===location.origin&&u.pathname.startsWith(base+'/')&&!u.username&&!u.password?u:null}catch{return null}};
 let opener;
 function openCommand(){if(!command||command.open)return;opener=document.activeElement;command.showModal();search.value='';filterCommands();search.focus()}
 function closeCommand(){command.close();opener?.focus()}
 $$('[data-lemma-launch]').forEach(el=>el.addEventListener('click',openCommand));
 $('[data-lemma-command-close]')?.addEventListener('click',closeCommand);
 command?.addEventListener('cancel',()=>{requestAnimationFrame(()=>opener?.focus())});
 command?.addEventListener('click',e=>{if(e.target===command){const r=command.getBoundingClientRect();if(e.clientX<r.left||e.clientX>r.right||e.clientY<r.top||e.clientY>r.bottom)closeCommand()}});
 function filterCommands(){if(!search)return;const q=search.value.trim().toLocaleLowerCase('ru');let visible=0;$$('.lemma-command-links a').forEach(a=>{a.hidden=!a.textContent.toLocaleLowerCase('ru').includes(q);if(!a.hidden)visible++});$('.lemma-command-empty').hidden=visible>0}
 search?.addEventListener('input',filterCommands);
 document.addEventListener('keydown',e=>{const editing=e.target.closest('input,textarea,select,[contenteditable=true]');if((e.metaKey||e.ctrlKey)&&e.key.toLowerCase()==='k'){e.preventDefault();command.open?closeCommand():openCommand()}else if(e.key==='/'&&!editing&&!$('dialog[open]')){e.preventDefault();openCommand()}});
 $$('[data-lemma-menu]').forEach(button=>button.addEventListener('click',()=>{const sidebar=$('.lemma-sidebar');const open=sidebar.classList.toggle('is-open');button.setAttribute('aria-expanded',String(open));if(open)$('.lemma-nav a',sidebar)?.focus()}));
 window.addEventListener('hashchange',()=>{$$('[data-lemma-menu]').forEach(b=>b.setAttribute('aria-expanded','false'))});
 document.addEventListener('keydown',e=>{if(e.key==='Escape'&&$('.lemma-sidebar.is-open')){$('.lemma-sidebar').classList.remove('is-open');$$('[data-lemma-menu]').forEach(b=>b.setAttribute('aria-expanded','false'));$('[data-lemma-menu]')?.focus()}});
 // Recent destinations contain a title and route, never response data or form content.
 let recent=storage.read(key('recent'),[]);if(!Array.isArray(recent))recent=[];
 recent=recent.filter(x=>x&&typeof x.title==='string'&&safeUrl(x.href)).slice(0,6);
 const recentRoot=$('[data-lemma-recent]');recent.forEach(item=>{const a=document.createElement('a');a.href=safeUrl(item.href).href;const strong=document.createElement('strong');strong.textContent=item.title;a.append(strong);recentRoot?.append(a)});
 if(/^\/(projects|tasks)\/\d+(?:\/|$)/.test(route)&&!route.endsWith('/edit')){
  const title=($('main h1')?.textContent||document.title).trim().slice(0,120);
  if(title){const href=location.pathname+location.hash;storage.write(key('recent'),[{title,href},...recent.filter(x=>x.href!==href)].slice(0,6))}
 }
 // Save a view explicitly. Applying one is an ordinary GET; it cannot mutate records.
 const filterForm=$('form[data-task-filters]')||$('form.filterbar[method="get"]');
 if(filterForm){
  const bar=document.createElement('div');bar.className='lemma-saved-views';bar.setAttribute('aria-label','Сохранённые фильтры');
  const caption=document.createElement('span');caption.textContent='Мои подборки';bar.append(caption);
  const save=document.createElement('button');save.type='button';save.className='btn text';save.textContent='Сохранить текущий вид';bar.append(save);
  const list=document.createElement('span');list.style.display='contents';bar.append(list);
  const savedKey=key('views:'+route);let views=storage.read(savedKey,[]);if(!Array.isArray(views))views=[];views=views.filter(v=>v&&typeof v.label==='string'&&safeUrl(v.href)?.pathname===location.pathname).slice(0,5);
  function renderViews(){list.replaceChildren();views.slice(0,5).forEach((view,i)=>{const url=safeUrl(view.href);if(!url||url.pathname!==location.pathname)return;const item=document.createElement('span');item.className='lemma-saved-item';const link=document.createElement('a');link.href=url.href;link.textContent=String(view.label).slice(0,50);const remove=document.createElement('button');remove.type='button';remove.textContent='×';remove.setAttribute('aria-label','Убрать подборку '+link.textContent);remove.addEventListener('click',()=>{views.splice(i,1);storage.write(savedKey,views);renderViews();announce('Подборка убрана')});item.append(link,remove);list.append(item)})}
  save.addEventListener('click',()=>{
   if($('.lemma-view-name',bar))return;
   const form=document.createElement('form');form.className='lemma-view-name';const label=document.createElement('label');label.textContent='Название подборки';const input=document.createElement('input');input.required=true;input.maxLength=50;input.placeholder='Например: ближайшие выдачи';label.append(input);const button=document.createElement('button');button.type='submit';button.className='btn primary';button.textContent='Сохранить';const cancel=document.createElement('button');cancel.type='button';cancel.className='btn';cancel.textContent='Отмена';cancel.onclick=()=>form.remove();form.append(label,button,cancel);bar.append(form);input.focus();
   form.addEventListener('submit',e=>{e.preventDefault();const name=input.value.trim();if(!name)return;const href=location.pathname+location.search;const next=[{label:name,href},...views.filter(v=>v.label!==name)].slice(0,5);if(!storage.write(savedKey,next)){announce('Браузер не разрешает сохранить подборку');return}views=next;renderViews();form.remove();announce('Подборка сохранена в этом браузере')})
  });
  const target=$('.task-commandbar')||filterForm;target.before(bar);renderViews();
 }
 // Recover narrative fields only, separately for each author/project/intent/section.
 const taskForm=$('#task-form[data-edit-mode="0"]');
 if(taskForm){
  const params=new URLSearchParams(location.search);const context=['project_id','workspace_section_id','task_intent'].map(k=>[k,params.get(k)||'']);const draftKey=key('draft:'+JSON.stringify(context));
  const allowed=['title','what','why','date_end','when_due','planned_hours','priority','urgency'];const fields=allowed.map(name=>taskForm.elements.namedItem(name)).filter(el=>el instanceof HTMLInputElement||el instanceof HTMLTextAreaElement||el instanceof HTMLSelectElement);
  const status=document.createElement('small');status.className='lemma-draft-status';status.setAttribute('role','status');status.textContent='Текст задачи сохраняется в этом браузере на 7 дней. Вложения нужно выбрать заново.';taskForm.before(status);
  let draft=storage.read(draftKey);if(draft&&(!draft.at||Date.now()-draft.at>7*86400000)){storage.remove(draftKey);draft=null}
  function values(){return Object.fromEntries(fields.map(el=>[el.name,el.value]))}
  function storeDraft(){const value=values();if(!['title','what','why'].some(k=>value[k]?.trim())){storage.remove(draftKey);return}const ok=storage.write(draftKey,{at:Date.now(),values:value});status.textContent=ok?'Черновик сохранён · только в этом браузере · вложения не сохраняются':'Черновик не сохранён: браузер ограничил локальное хранилище'}
  if(draft?.values&&typeof draft.values==='object'){
   const banner=document.createElement('div');banner.className='lemma-draft';const copy=document.createElement('div');const heading=document.createElement('strong');heading.textContent='Есть незавершённый черновик';const p=document.createElement('p');p.textContent='От '+new Date(draft.at).toLocaleString('ru-RU')+'. Восстановление заменит текст, срок и трудоёмкость в этой форме.';copy.append(heading,p);
   const restore=document.createElement('button');restore.type='button';restore.className='btn primary';restore.textContent='Восстановить';restore.onclick=()=>{fields.forEach(el=>{if(typeof draft.values[el.name]==='string'){if(el instanceof HTMLSelectElement&&![...el.options].some(o=>o.value===draft.values[el.name]))return;el.value=draft.values[el.name];el.dispatchEvent(new Event('change',{bubbles:true}))}});banner.remove();status.textContent='Черновик восстановлен. Проверьте поля перед созданием задачи.';announce('Черновик восстановлен')};const discard=document.createElement('button');discard.type='button';discard.className='btn';discard.textContent='Убрать черновик';discard.onclick=()=>{storage.remove(draftKey);banner.remove();announce('Сохранённый черновик убран')};banner.append(copy,restore,discard);taskForm.before(banner);
  }
  let timer;taskForm.addEventListener('input',()=>{clearTimeout(timer);timer=setTimeout(storeDraft,400)});taskForm.addEventListener('change',storeDraft);
  taskForm.addEventListener('submit',()=>{storeDraft();try{sessionStorage.setItem(key('pending-draft'),draftKey)}catch{}});
  window.addEventListener('pagehide',()=>{if(timer){clearTimeout(timer);storeDraft()}});
 }else if(/^\/tasks\/\d+$/.test(route)&&$('.alert--success,.workspace-alert.success')){try{const pending=sessionStorage.getItem(key('pending-draft'));if(pending?.startsWith(key('draft:'))){storage.remove(pending);sessionStorage.removeItem(key('pending-draft'))}}catch{}}
 document.addEventListener('invalid',e=>{let parent=e.target.parentElement;while(parent){if(parent instanceof HTMLDetailsElement)parent.open=true;parent=parent.parentElement}},true);
 $$('[data-task-kind-control]').forEach(el=>el.addEventListener('change',()=>{const label=$('[data-lemma-intent-label]');if(label)label.textContent=el.closest('label')?.querySelector('strong')?.textContent||el.dataset.meta;const details=el.closest('details');if(details)details.open=false}));
 const projectSearch=$('[data-lemma-project-search]');if(projectSearch){const state=$('[data-lemma-project-state]'),cards=$$('[data-lemma-project-card]');const apply=()=>{const q=projectSearch.value.toLocaleLowerCase('ru').trim();let count=0;cards.forEach(card=>{const match=card.textContent.toLocaleLowerCase('ru').includes(q)&&(state.value!=='attention'||card.dataset.attention==='1');card.hidden=!match;if(match)count++});$('[data-lemma-project-count]').textContent=`Показано ${count} из ${cards.length}`;$('[data-lemma-project-empty]').hidden=count>0};projectSearch.addEventListener('input',apply);state.addEventListener('change',apply);apply()}

 // Long team lists filter in place; hidden checkboxes retain their selected state.
 $$('.people-options').forEach((list,index)=>{
  const rows=$$('label.check-line',list);if(rows.length<8)return;
  const label=document.createElement('label');label.className='lemma-people-search';label.textContent='Найти сотрудника';const input=document.createElement('input');input.type='search';input.placeholder='Имя или отдел';label.append(input);const status=document.createElement('small');status.setAttribute('role','status');const empty=document.createElement('p');empty.textContent='Никого не найдено. Измените поиск.';empty.hidden=true;list.before(label,status);list.append(empty);
  const apply=()=>{const q=input.value.trim().toLocaleLowerCase('ru');let visible=0;rows.forEach(row=>{row.hidden=!row.textContent.toLocaleLowerCase('ru').includes(q);if(!row.hidden)visible++});status.textContent=`Найдено ${visible} · выбрано ${rows.filter(row=>$('input',row).checked).length}`;empty.hidden=visible>0};input.addEventListener('input',apply);list.addEventListener('change',apply);apply();
 });
})();
