/**
 * DIGITAL LIBRARY SYSTEM — Dashboard JS v4.0
 * Temps réel · IA connectée BD · Recherche live · Notifications push
 */

 'use strict';

 // ═══════════════════════════════════════════════════════
 // UTILS
 // ═══════════════════════════════════════════════════════
 const escHtml = s =>
   String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;')
     .replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
 
 const $ = id => document.getElementById(id);
 const $$ = sel => document.querySelectorAll(sel);
 
 // ═══════════════════════════════════════════════════════
 // TOAST
 // ═══════════════════════════════════════════════════════
 const TOAST_ICONS   = {info:'ℹ️', success:'✅', warn:'⚠️', error:'🔴'};
 const TOAST_BORDERS = {info:'var(--cyan)', success:'var(--neon)', warn:'var(--amber)', error:'var(--rose)'};
 
 function showToast(title, sub = '', type = 'info', dur = 3500) {
   const stack = $('toast-stack');
   if (!stack) return;
   const t = document.createElement('div');
   t.className = 'toast';
   t.style.borderColor = TOAST_BORDERS[type] ?? TOAST_BORDERS.info;
   t.innerHTML = `
     <span class="t-icon">${TOAST_ICONS[type] ?? 'ℹ️'}</span>
     <div class="t-body">
       <div class="t-title">${escHtml(title)}</div>
       ${sub ? `<div class="t-sub">${escHtml(sub)}</div>` : ''}
     </div>
     <span class="t-close" onclick="this.parentElement.remove()"><i class="bi bi-x"></i></span>
   `;
   stack.appendChild(t);
   requestAnimationFrame(() => requestAnimationFrame(() => t.classList.add('show')));
   setTimeout(() => { t.classList.remove('show'); setTimeout(() => t.remove(), 380); }, dur);
 }
 
 // ═══════════════════════════════════════════════════════
 // SIDEBAR
 // ═══════════════════════════════════════════════════════
 (function initSidebar() {
   const sidebar     = $('sidebar');
   const main        = $('main-content');
   const toggleBtn   = $('sidebar-toggle');
   const mobileBtn   = $('mobile-menu-btn');
   const overlay     = $('sidebar-overlay');
 
   let collapsed = localStorage.getItem('dls_sidebar_collapsed') === '1';
 
   function applySidebar() {
     sidebar?.classList.toggle('collapsed', collapsed);
     main?.classList.toggle('collapsed', collapsed);
     localStorage.setItem('dls_sidebar_collapsed', collapsed ? '1' : '0');
   }
 
   applySidebar();
 
   toggleBtn?.addEventListener('click', () => {
     if (window.innerWidth <= 768) return;
     collapsed = !collapsed;
     applySidebar();
   });
 
   mobileBtn?.addEventListener('click', () => {
     sidebar?.classList.toggle('mobile-open');
     overlay?.classList.toggle('show');
   });
 
   overlay?.addEventListener('click', () => {
     sidebar?.classList.remove('mobile-open');
     overlay?.classList.remove('show');
   });
 })();
 
 // ═══════════════════════════════════════════════════════
 // NOTIFICATIONS PANEL
 // ═══════════════════════════════════════════════════════
 const NotifPanel = (() => {
   let panel, btn, isOpen = false;
 
   function init() {
     panel = $('notif-panel');
     btn   = $('notif-btn');
     btn?.addEventListener('click', toggle);
     document.addEventListener('click', e => {
       if (isOpen && panel && !panel.contains(e.target) && !btn?.contains(e.target)) close();
     });
     $('mark-all-read')?.addEventListener('click', async e => {
       e.preventDefault();
       await markAllRead();
     });
   }
 
   function toggle() { isOpen ? close() : open(); }
   function open()  { panel?.classList.add('open'); btn?.setAttribute('aria-expanded','true'); isOpen = true; }
   function close() { panel?.classList.remove('open'); btn?.setAttribute('aria-expanded','false'); isOpen = false; }
 
   async function markAllRead() {
     try {
       const r = await fetch(`${DLS.endpoints.notifs}?action=mark_all_read`, {
         method: 'POST',
         headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': DLS.csrf },
         body: JSON.stringify({ csrf: DLS.csrf })
       });
       const d = await r.json();
       if (d.success) {
         updateBadge(0);
         $$('.np-unread').forEach(el => el.classList.remove('np-unread'));
         showToast('Notifications', 'Toutes marquées comme lues.', 'success', 2000);
       }
     } catch (e) { console.error('[Notifs] markAllRead:', e); }
   }
 
   function updateBadge(count) {
     const badge  = $('notif-badge-count');
     const sbBadge = $('sidebar-notif-badge');
     const npCount = $('np-count');
     const n = parseInt(count) || 0;
     if (badge) { badge.textContent = Math.min(n, 9); badge.style.display = n > 0 ? '' : 'none'; }
     if (sbBadge) { sbBadge.textContent = Math.min(n, 99); sbBadge.style.display = n > 0 ? '' : 'none'; }
     if (npCount) { npCount.textContent = n; npCount.style.display = n > 0 ? '' : 'none'; }
   }
 
   function addNotif(notif) {
     const list = $('notif-list');
     if (!list) return;
     const empty = list.querySelector('.np-empty');
     if (empty) empty.remove();
 
     const el = document.createElement('div');
     el.className = 'np-item np-unread np-new-item';
     el.innerHTML = `
       <div class="np-icon" style="background:${escHtml(notif.bg ?? 'rgba(0,212,255,.08)') }">${escHtml(notif.icon ?? '🔔')}</div>
       <div>
         <div class="np-text">${escHtml(notif.titre ?? notif.message ?? '')}</div>
         <div class="np-time">À l'instant</div>
       </div>
     `;
     list.prepend(el);
   }
 
   return { init, toggle, open, close, updateBadge, addNotif };
 })();
 
 // ═══════════════════════════════════════════════════════
 // LIVE STATS (polling toutes les 8s)
 // ═══════════════════════════════════════════════════════
 const LiveStats = (() => {
   let timer = null;
   let prevStats = {};
 
   async function fetch_stats() {
     try {
       const r = await fetch(DLS.endpoints.stats + '?role=' + encodeURIComponent(DLS.role), {
         headers: { 'X-CSRF-Token': DLS.csrf, 'X-Requested-With': 'XMLHttpRequest' }
       });
       if (!r.ok) return;
       const d = await r.json();
       if (d.stats) updateDOM(d.stats);
       if (typeof d.notifCount === 'number') NotifPanel.updateBadge(d.notifCount);
     } catch (e) {
       console.warn('[Stats] Poll error:', e);
       setLiveStatus(false);
     }
   }
 
   function updateDOM(stats) {
     setLiveStatus(true);
     Object.entries(stats).forEach(([key, val]) => {
       const card = document.querySelector(`.stat-card[data-stat="${key}"]`);
       if (!card) return;
       const valueEl = card.querySelector('.stat-value');
       if (!valueEl) return;
 
       const prev = prevStats[key];
       const cur  = String(val);
       if (prev === cur) return; // pas de changement
 
       // Animation flash
       valueEl.classList.add('updating');
       setTimeout(() => valueEl.classList.remove('updating'), 300);
 
       // Mise à jour du texte
       valueEl.textContent = cur;
 
       // Toast si changement notable
       if (prev !== undefined) {
         const numPrev = parseFloat(prev.replace(/\D/g, ''));
         const numCur  = parseFloat(cur.replace(/\D/g, ''));
         if (!isNaN(numPrev) && !isNaN(numCur) && numCur > numPrev) {
           const labels = { totalSales:'Nouvelle vente !', totalUsers:'Nouvel utilisateur !', activeToday:'Utilisateur connecté' };
           if (labels[key]) showToast(labels[key], '', 'success', 2500);
         }
       }
       prevStats[key] = cur;
     });
   }
 
   function setLiveStatus(ok) {
     const dot = $('live-dot');
     if (!dot) return;
     dot.style.opacity = ok ? '1' : '.3';
     dot.title = ok ? 'Données temps réel — actif' : 'Connexion perdue…';
   }
 
   function start(interval = 8000) {
     fetch_stats();
     timer = setInterval(fetch_stats, interval);
   }
 
   function stop() { clearInterval(timer); }
 
   return { start, stop };
 })();
 
 // ═══════════════════════════════════════════════════════
 // LIVE ACTIVITY (polling toutes les 12s)
 // ═══════════════════════════════════════════════════════
 const LiveActivity = (() => {
   let timer = null;
   let lastId = null;
 
   async function poll() {
     try {
       const params = new URLSearchParams({ role: DLS.role });
       if (lastId) params.set('after_id', lastId);
       const r = await fetch(`${DLS.endpoints.activity}?${params}`, {
         headers: { 'X-Requested-With': 'XMLHttpRequest' }
       });
       if (!r.ok) return;
       const d = await r.json();
       if (d.items && d.items.length > 0) {
         d.items.forEach(item => { addActivityItem(item); lastId = item.id; });
         if (d.notif) { NotifPanel.addNotif(d.notif); NotifPanel.updateBadge(d.notifCount ?? 0); }
       }
     } catch (e) { console.warn('[Activity] Poll error:', e); }
   }
 
   function addActivityItem(item) {
     const feed = $('activity-feed');
     if (!feed) return;
 
     const emptyEl = feed.querySelector('.empty-state');
     if (emptyEl) emptyEl.remove();
 
     const el = document.createElement('div');
     el.className = 'act-item act-new';
     el.innerHTML = `
       <div class="act-dot">${escHtml(item.icon ?? '•')}</div>
       <div style="flex:1">
         <div class="act-msg">${escHtml(item.msg ?? '')}</div>
         <div class="act-time">À l'instant</div>
       </div>
     `;
     feed.prepend(el);
 
     // Garder max 8 items
     const items = feed.querySelectorAll('.act-item');
     if (items.length > 8) items[items.length - 1].remove();
   }
 
   function start(interval = 12000) {
     poll();
     timer = setInterval(poll, interval);
   }
   function stop() { clearInterval(timer); }
 
   return { start, stop };
 })();
 
 // ═══════════════════════════════════════════════════════
 // LIVE NOTIFICATIONS (polling toutes les 15s)
 // ═══════════════════════════════════════════════════════
 const LiveNotifs = (() => {
   let timer = null;
 
   async function poll() {
     try {
       const r = await fetch(`${DLS.endpoints.notifs}?action=count&userId=${DLS.userId}`, {
         headers: { 'X-Requested-With': 'XMLHttpRequest' }
       });
       if (!r.ok) return;
       const d = await r.json();
       if (typeof d.count === 'number') NotifPanel.updateBadge(d.count);
       if (d.new_notifs && d.new_notifs.length > 0) {
         d.new_notifs.forEach(n => NotifPanel.addNotif(n));
       }
     } catch (e) { console.warn('[Notifs] Poll error:', e); }
   }
 
   function start(interval = 15000) {
     poll();
     timer = setInterval(poll, interval);
   }
   function stop() { clearInterval(timer); }
 
   return { start, stop };
 })();
 
 // ═══════════════════════════════════════════════════════
 // SEARCH (debounce live + keyboard)
 // ═══════════════════════════════════════════════════════
 const Search = (() => {
   let timer = null;
   let lastQ = '';
   let idx = -1;
   let results = [];
 
   function init() {
     const input = $('search-input');
     const box   = $('search-results');
     if (!input || !box) return;
 
     input.addEventListener('input', e => debounce(e.target.value.trim()));
     input.addEventListener('keydown', handleKey);
     input.addEventListener('focus', () => { if (lastQ) box.classList.add('show'); });
 
     document.addEventListener('click', e => {
       if (!document.querySelector('.tb-search-wrap')?.contains(e.target)) hide();
     });
 
     // CMD+K shortcut
     document.addEventListener('keydown', e => {
       if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
         e.preventDefault(); input.focus(); input.select();
       }
     });
   }
 
   function debounce(q) {
     clearTimeout(timer);
     if (q.length < 2) { hide(); return; }
     timer = setTimeout(() => search(q), 280);
   }
 
   async function search(q) {
     if (q === lastQ) return;
     lastQ = q;
     const box = $('search-results');
     if (!box) return;
     box.innerHTML = '<div class="sr-msg">🔍 Recherche en cours…</div>';
     box.classList.add('show');
 
     try {
       const r = await fetch(`${DLS.endpoints.search}?q=${encodeURIComponent(q)}&role=${encodeURIComponent(DLS.role)}&csrf=${encodeURIComponent(DLS.csrf)}`, {
         headers: { 'X-Requested-With': 'XMLHttpRequest' }
       });
       if (!r.ok) throw new Error('HTTP ' + r.status);
       const d = await r.json();
 
       results = d.results ?? [];
       idx = -1;
 
       if (results.length === 0) {
         box.innerHTML = `<div class="sr-msg">Aucun résultat pour « ${escHtml(q)} »</div>`;
         return;
       }
 
       let html = '';
       const byType = {};
       results.forEach(item => {
         const t = item.type ?? 'other';
         if (!byType[t]) byType[t] = [];
         byType[t].push(item);
       });
 
       const typeLabels = { book:'Livres', user:'Utilisateurs', category:'Catégories', achat:'Achats' };
       Object.entries(byType).forEach(([type, items]) => {
         html += `<div class="sr-section-header">${typeLabels[type] ?? type}</div>`;
         items.forEach(item => {
           const icons = { book:'📚', user:'👤', category:'🗂️', achat:'💳' };
           html += `<a class="sr-item" href="${escHtml(item.url ?? '#')}">
             <span class="sr-icon">${icons[item.type] ?? '📄'}</span>
             <div>
               <div class="sr-title">${escHtml(item.titre ?? item.name ?? '')}</div>
               <div class="sr-sub">${escHtml(item.sub ?? '')}</div>
             </div>
           </a>`;
         });
       });
       box.innerHTML = html;
     } catch (e) {
       console.error('[Search]', e);
       box.innerHTML = '<div class="sr-msg">Erreur de recherche. Réessayez.</div>';
     }
   }
 
   function handleKey(e) {
     const box = $('search-results');
     const items = box?.querySelectorAll('.sr-item') ?? [];
     if (e.key === 'ArrowDown') { e.preventDefault(); idx = (idx + 1) % items.length; items[idx]?.focus(); }
     if (e.key === 'ArrowUp')   { e.preventDefault(); idx = (idx - 1 + items.length) % items.length; items[idx]?.focus(); }
     if (e.key === 'Enter' && idx < 0) {
       const q = e.target.value.trim();
       if (q) window.location.href = 'books/index.php?search=' + encodeURIComponent(q);
     }
     if (e.key === 'Escape') hide();
   }
 
   function hide() {
     const box = $('search-results');
     box?.classList.remove('show');
     lastQ = '';
     results = [];
   }
 
   return { init };
 })();
 
 // ═══════════════════════════════════════════════════════
 // AI CHATBOT — Connecté à la BD avec permissions
 // ═══════════════════════════════════════════════════════
 const AI = (() => {
   let loading = false;
   let conversationHistory = [];
 
   // Questions interdites selon le rôle
   const FORBIDDEN_PATTERNS = {
     lecteur: [
       /utilisateur[s]?\s*(connecté|inscrit|actif|total)/i,
       /liste\s*(des)?\s*utilisateur/i,
       /admin(istrateur)?/i,
       /log[s]?\s*(système|admin)/i,
       /donn[ée]{2}[s]?\s*admin/i,
       /nombre\s*(de)?\s*user/i,
       /compte[s]?\s*utilisateur/i,
     ],
     journaliste: [
       /liste\s*(des)?\s*utilisateur/i,
       /tous\s*(les)?\s*utilisateur/i,
       /log[s]?\s*système/i,
       /données\s*admin/i,
     ]
   };
 
   function isForbidden(question) {
     const patterns = FORBIDDEN_PATTERNS[DLS.role] ?? [];
     return patterns.some(p => p.test(question));
   }
 
   function init() {
     const panel    = $('ai-panel');
     const fab      = $('ai-fab');
     const closeBtn = $('ai-close-btn');
     const resetBtn = $('ai-reset-btn');
     const sendBtn  = $('ai-send-btn');
     const input    = $('ai-input');
     const navBtn   = $('nav-ai-btn');
 
     fab?.addEventListener('click', () => panel?.classList.toggle('open'));
     navBtn?.addEventListener('click', e => { e.preventDefault(); panel?.classList.toggle('open'); });
     closeBtn?.addEventListener('click', () => panel?.classList.remove('open'));
     resetBtn?.addEventListener('click', reset);
     sendBtn?.addEventListener('click', send);
     input?.addEventListener('keydown', e => {
       if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); }
     });
     input?.addEventListener('input', () => {
       input.style.height = 'auto';
       input.style.height = Math.min(input.scrollHeight, 90) + 'px';
     });
 
     $$('.ai-sugg').forEach(el => {
       el.addEventListener('click', () => {
         if (input) { input.value = el.dataset.q ?? el.textContent; }
         send();
       });
     });
   }
 
   function addMessage(text, role, isError = false) {
     const msgs = $('ai-msgs');
     if (!msgs) return;
     const t = new Date().toLocaleTimeString('fr-FR', {hour:'2-digit', minute:'2-digit'});
     const row = document.createElement('div');
     row.className = `ai-msg-row ${role}`;
     const bubClass = isError ? 'msg-bubble bot error' : `msg-bubble ${role}`;
     if (role === 'user') {
       row.innerHTML = `<div class="${bubClass}">${escHtml(text)}</div><div class="msg-time">Vous · ${escHtml(t)}</div>`;
     } else {
       // Pour les réponses bot, on sanitise le HTML côté serveur avant envoi
       // Ici on fait un simple rendu du texte brut formaté
       const safe = escHtml(text).replace(/\n/g,'<br>').replace(/\*\*(.+?)\*\*/g,'<strong>$1</strong>');
       row.innerHTML = `<div class="${bubClass}">${safe}</div><div class="msg-time">Assistant IA · ${escHtml(t)}</div>`;
     }
     msgs.appendChild(row);
     msgs.scrollTop = msgs.scrollHeight;
   }
 
   function showTyping() {
     const msgs = $('ai-msgs');
     if (!msgs) return;
     const d = document.createElement('div');
     d.id = 'ai-typing';
     d.className = 'ai-msg-row bot';
     d.innerHTML = `<div class="typing-dots"><div class="td-dot"></div><div class="td-dot"></div><div class="td-dot"></div></div>`;
     msgs.appendChild(d);
     msgs.scrollTop = msgs.scrollHeight;
   }
   function hideTyping() { $('ai-typing')?.remove(); }
 
   async function send() {
     if (loading) return;
     const input = $('ai-input');
     const q = input?.value.trim();
     if (!q) return;
 
     // Vérification des permissions
     if (isForbidden(q)) {
       addMessage(q, 'user');
       input.value = '';
       input.style.height = 'auto';
       setTimeout(() => addMessage('Désolé, vous n\'avez pas l\'autorisation d\'accéder à cette information.', 'bot'), 400);
       return;
     }
 
     addMessage(q, 'user');
     conversationHistory.push({ role: 'user', content: q });
     input.value = '';
     input.style.height = 'auto';
     loading = true;
 
     const sendBtn = $('ai-send-btn');
     if (sendBtn) sendBtn.disabled = true;
     const statusEl = $('ai-status');
     if (statusEl) statusEl.textContent = '● En train d\'écrire…';
     showTyping();
 
     try {
       const res = await fetch(DLS.endpoints.ai, {
         method: 'POST',
         headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': DLS.csrf },
         body: JSON.stringify({
           question: q,
           role:     DLS.role,
           userId:   DLS.userId,
           history:  conversationHistory.slice(-6), // contexte limité
           csrf:     DLS.csrf
         })
       });
 
       if (!res.ok) throw new Error('HTTP ' + res.status);
       const d = await res.json();
       hideTyping();
 
       const answer = d.answer ?? d.error ?? 'Je n\'ai pas pu traiter votre requête.';
       conversationHistory.push({ role: 'assistant', content: answer });
       addMessage(answer, 'bot', !!d.error);
 
     } catch (err) {
       hideTyping();
       console.error('[AI]', err);
       const isNet = err.message.includes('fetch') || err.message.includes('network');
       addMessage(
         isNet
           ? '⚠️ Impossible de joindre le serveur IA. Vérifiez que XAMPP est démarré et que api/ai_chat.php existe.'
           : `⚠️ Erreur serveur : ${err.message}`,
         'bot', true
       );
     } finally {
       loading = false;
       if (sendBtn) sendBtn.disabled = false;
       if (statusEl) statusEl.textContent = '● Connecté à la BD';
     }
   }
 
   function reset() {
     const msgs = $('ai-msgs');
     if (!msgs) return;
     msgs.innerHTML = '';
     conversationHistory = [];
     const t = new Date().toLocaleTimeString('fr-FR', {hour:'2-digit', minute:'2-digit'});
     const row = document.createElement('div');
     row.className = 'ai-msg-row bot';
     row.innerHTML = `<div class="msg-bubble bot">Nouvelle conversation. Bonjour ${escHtml(DLS.name)} ! 🤖</div><div class="msg-time">Assistant IA · ${escHtml(t)}</div>`;
     msgs.appendChild(row);
     showToast('Assistant IA', 'Conversation réinitialisée.', 'success', 2000);
   }
 
   return { init };
 })();
 
 // ═══════════════════════════════════════════════════════
 // PAYMENT MODAL
 // ═══════════════════════════════════════════════════════
 const PayModal = (() => {
   let payMethod = 'orange';
   let bookId    = null;
 
   function init() {
     $('pay-close-btn')?.addEventListener('click', close);
     $('pay-modal')?.addEventListener('click', e => { if (e.target === $('pay-modal')) close(); });
     $('pay-confirm')?.addEventListener('click', confirm);
 
     $$('.pay-method').forEach(el => {
       el.addEventListener('click', () => selectMethod(el.dataset.method, el));
     });
   }
 
   function open(id, name, price) {
     bookId = parseInt(id) || null;
     const m = $('pay-modal');
     if (!m) return;
     $('pay-book').textContent = name ?? 'Livre';
     $('pay-amt').textContent  = Number(price ?? 0).toLocaleString('fr-CM');
     $('pay-phone').value = '';
     $('pay-pin').value   = '';
     selectMethod('orange', $('pm-orange'));
     m.classList.add('open');
     setTimeout(() => $('pay-phone')?.focus(), 120);
   }
 
   function close() { $('pay-modal')?.classList.remove('open'); }
 
   function selectMethod(method, el) {
     payMethod = method;
     $$('.pay-method').forEach(x => x.classList.remove('selected'));
     el?.classList.add('selected');
   }
 
   function confirm() {
     const phone = $('pay-phone')?.value.trim() ?? '';
     const pin   = $('pay-pin')?.value.trim() ?? '';
     if (phone.length < 8) { showToast('Erreur', 'Numéro invalide (min 8 chiffres).', 'error'); return; }
     if (pin.length < 4)   { showToast('Erreur', 'PIN incomplet (4 chiffres).', 'error'); return; }
     close();
     showToast('Paiement', `Traitement via ${payMethod === 'orange' ? 'Orange Money' : 'MTN MoMo'}…`, 'info', 2500);
     setTimeout(() => {
       if (Math.random() > 0.08) {
         showToast('✅ Paiement accepté', 'Achat confirmé ! Accédez à votre livre.', 'success', 6000);
         if (bookId) setTimeout(() => { window.location.href = 'books/read.php?id=' + bookId; }, 1500);
       } else {
         showToast('❌ Paiement refusé', 'Solde insuffisant ou PIN incorrect.', 'error', 5000);
       }
     }, 2600);
   }
 
   return { init, open, close };
 })();
 
 // Exposer showPaymentModal globalement (appelé depuis PHP)
 window.showPaymentModal = (id, name, price) => PayModal.open(id, name, price);
 
 // ═══════════════════════════════════════════════════════
 // KEYBOARD SHORTCUTS (Escape)
 // ═══════════════════════════════════════════════════════
 document.addEventListener('keydown', e => {
   if (e.key !== 'Escape') return;
   if ($('pay-modal')?.classList.contains('open')) { PayModal.close(); return; }
   if ($('ai-panel')?.classList.contains('open'))  { $('ai-panel').classList.remove('open'); return; }
   if ($('notif-panel')?.classList.contains('open')) { NotifPanel.close(); }
 });
 
 // ═══════════════════════════════════════════════════════
 // INIT GLOBAL
 // ═══════════════════════════════════════════════════════
 document.addEventListener('DOMContentLoaded', () => {
   // Init composants
   NotifPanel.init();
   Search.init();
   AI.init();
   PayModal.init();
 
   // Animations barres progression
   setTimeout(() => {
     $$('.prog').forEach(b => {
       const w = b.style.width;
       b.style.width = '0%';
       requestAnimationFrame(() => requestAnimationFrame(() => { b.style.width = w; }));
     });
   }, 400);
 
   // Démarrer polling temps réel
   if (DLS.role === 'admin') {
     LiveStats.start(8000);
     LiveActivity.start(12000);
     LiveNotifs.start(15000);
   } else {
     LiveStats.start(15000);
     LiveNotifs.start(20000);
   }
 
   // URL params
   const p = new URLSearchParams(window.location.search);
   if (p.get('error') === 'access_denied')  showToast('Accès refusé', 'Droits insuffisants.', 'error');
   if (p.get('success') === 'saved')         showToast('Enregistré', 'Modifications sauvegardées.', 'success');
   if (p.get('success') === 'purchase')      showToast('Achat confirmé', 'Livre ajouté à votre bibliothèque.', 'success');
 
   // Toast bienvenue (retardé)
   setTimeout(() => showToast(`Bonjour ${DLS.name} !`, 'Tableau de bord chargé avec succès.', 'success', 4000), 800);
 });