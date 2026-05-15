/**
 * ╔══════════════════════════════════════════════════════════════╗
 * ║  DIGITAL LIBRARY SYSTEM — search_widget.js v3.0             ║
 * ║  Barre de recherche universelle : livres + users + nav       ║
 * ╚══════════════════════════════════════════════════════════════╝
 *
 * REMPLACE la fonction handleSearch() inline dans dashboard.php
 *
 * USAGE : inclure ce fichier avant </body>
 *   <script src="assets/js/search_widget.js"></script>
 *
 * PRÉREQUIS : api/search_v3.php déployé sous /api/search.php
 *             CSS variables DLS présentes dans la page
 */

 (function () {
    'use strict';
  
    // ── CSS du dropdown (injecté une fois) ─────────────────────
    const DROPDOWN_CSS = `
      #dls-search-wrap { position: relative; }
      #dls-search-dropdown {
        position: absolute; top: calc(100% + 6px); left: 0; right: 0;
        background: var(--bg-surface);
        border: 1px solid var(--border);
        border-radius: var(--r-md);
        box-shadow: var(--shadow-lg);
        z-index: 9999; overflow: hidden;
        max-height: 480px; overflow-y: auto;
        opacity: 0; transform: translateY(-6px) scale(.98);
        pointer-events: none;
        transition: all .22s cubic-bezier(.34,1.56,.64,1);
      }
      #dls-search-dropdown.open {
        opacity: 1; transform: translateY(0) scale(1); pointer-events: all;
      }
      .sdr-group-header {
        display: flex; align-items: center; gap: 6px;
        padding: 6px 12px 3px;
        font-family: 'Space Mono', monospace; font-size: .58rem;
        letter-spacing: .1em; text-transform: uppercase;
        color: var(--text-muted); border-top: 1px solid var(--border);
      }
      .sdr-group-header:first-child { border-top: none; }
      .sdr-item {
        display: flex; align-items: center; gap: 10px;
        padding: 9px 12px; cursor: pointer;
        transition: background .12s;
        text-decoration: none; color: inherit;
        border-bottom: 1px solid rgba(255,255,255,.03);
      }
      .sdr-item:last-child { border-bottom: none; }
      .sdr-item:hover, .sdr-item.focused { background: var(--bg-card-hov); }
      .sdr-icon {
        width: 32px; height: 32px; border-radius: 9px;
        background: var(--bg-card); border: 1px solid var(--border);
        display: flex; align-items: center; justify-content: center;
        font-size: 1rem; flex-shrink: 0;
      }
      .sdr-info { flex: 1; min-width: 0; }
      .sdr-label {
        font-size: .82rem; font-weight: 600;
        color: var(--text-primary);
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
      }
      .sdr-label mark {
        background: rgba(0,212,255,.22); color: var(--cyan);
        border-radius: 2px; padding: 0 1px;
      }
      .sdr-sub {
        font-size: .68rem; color: var(--text-secondary);
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        margin-top: 1px;
      }
      .sdr-badge {
        display: inline-flex; align-items: center;
        font-size: .58rem; font-family: 'Space Mono', monospace;
        padding: 2px 6px; border-radius: 100px; font-weight: 700;
        flex-shrink: 0;
      }
      .sdr-empty {
        padding: 1.5rem; text-align: center;
        color: var(--text-muted); font-size: .8rem;
      }
      .sdr-footer {
        padding: 7px 12px; border-top: 1px solid var(--border);
        display: flex; justify-content: space-between; align-items: center;
        font-size: .65rem; color: var(--text-muted);
        font-family: 'Space Mono', monospace;
      }
      .sdr-spinner {
        display: flex; align-items: center; justify-content: center; gap: 8px;
        padding: 1.2rem; color: var(--text-muted); font-size: .78rem;
      }
      .sdr-dot {
        width: 6px; height: 6px; border-radius: 50%;
        background: var(--cyan); animation: sdrPulse 1.2s ease-in-out infinite;
      }
      .sdr-dot:nth-child(2) { animation-delay: .18s; }
      .sdr-dot:nth-child(3) { animation-delay: .36s; }
      @keyframes sdrPulse {
        0%,60%,100% { opacity: .3; transform: scale(.8); }
        30% { opacity: 1; transform: scale(1.2); }
      }
    `;
  
    // Chip classes mapping
    const BADGE_CSS = {
      'chip-success': 'background:rgba(0,255,170,.12);color:#00ffaa;border:1px solid rgba(0,255,170,.25)',
      'chip-info':    'background:rgba(0,212,255,.12);color:#00d4ff;border:1px solid rgba(0,212,255,.25)',
      'chip-warn':    'background:rgba(245,158,11,.12);color:#f59e0b;border:1px solid rgba(245,158,11,.25)',
      'chip-danger':  'background:rgba(244,63,94,.12);color:#f43f5e;border:1px solid rgba(244,63,94,.25)',
      'chip-muted':   'background:rgba(255,255,255,.06);color:#aaa;border:1px solid rgba(255,255,255,.1)',
    };
  
    let dropdown    = null;
    let debounceTimer = null;
    let focusedIdx  = -1;
    let lastResults = [];
    let currentQuery = '';
  
    // ── Init ────────────────────────────────────────────────────
    function init() {
      // Injecter le CSS
      const style = document.createElement('style');
      style.textContent = DROPDOWN_CSS;
      document.head.appendChild(style);
  
      // Trouver la barre de recherche
      const searchWrap = document.querySelector('.tb-search');
      const searchInput = document.getElementById('search-input');
      if (!searchInput || !searchWrap) return;
  
      // Wrapper pour le dropdown
      searchWrap.id = 'dls-search-wrap';
      searchWrap.style.position = 'relative';
  
      // Créer le dropdown
      dropdown = document.createElement('div');
      dropdown.id = 'dls-search-dropdown';
      dropdown.setAttribute('role', 'listbox');
      dropdown.setAttribute('aria-label', 'Résultats de recherche');
      searchWrap.appendChild(dropdown);
  
      // Events
      searchInput.removeAttribute('onkeydown'); // retire le handler inline
      searchInput.addEventListener('input',   onInput);
      searchInput.addEventListener('keydown',  onKeydown);
      searchInput.addEventListener('focus',    onFocus);
      document.addEventListener('click', onDocClick);
    }
  
    // ── Input avec debounce ─────────────────────────────────────
    function onInput(e) {
      clearTimeout(debounceTimer);
      const q = e.target.value.trim();
      currentQuery = q;
      focusedIdx = -1;
  
      if (q.length < 2) { closeDropdown(); return; }
  
      showSpinner();
      debounceTimer = setTimeout(() => fetchResults(q), 280);
    }
  
    function onFocus(e) {
      const q = e.target.value.trim();
      if (q.length >= 2 && lastResults.length > 0) openDropdown();
    }
  
    function onKeydown(e) {
      const items = dropdown ? dropdown.querySelectorAll('.sdr-item') : [];
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        focusedIdx = Math.min(focusedIdx + 1, items.length - 1);
        updateFocus(items);
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        focusedIdx = Math.max(focusedIdx - 1, -1);
        updateFocus(items);
      } else if (e.key === 'Enter') {
        e.preventDefault();
        if (focusedIdx >= 0 && items[focusedIdx]) {
          items[focusedIdx].click();
        } else if (currentQuery.length >= 2) {
          window.location.href = 'books/index.php?search=' + encodeURIComponent(currentQuery);
        }
      } else if (e.key === 'Escape') {
        closeDropdown();
        e.target.blur();
      }
    }
  
    function updateFocus(items) {
      items.forEach((el, i) => el.classList.toggle('focused', i === focusedIdx));
      if (focusedIdx >= 0) items[focusedIdx]?.scrollIntoView({ block: 'nearest' });
    }
  
    function onDocClick(e) {
      const wrap = document.getElementById('dls-search-wrap');
      if (wrap && !wrap.contains(e.target)) closeDropdown();
    }
  
    // ── Fetch ────────────────────────────────────────────────────
    async function fetchResults(q) {
      try {
        const res  = await fetch('api/search.php?q=' + encodeURIComponent(q), {
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const data = await res.json();
  
        if (data.error) { showError(data.error); return; }
  
        lastResults = data.groups || [];
        renderResults(data.groups || [], data.count || 0, q);
      } catch (err) {
        showError('Erreur réseau. Vérifiez votre connexion.');
        console.error('[DLS Search]', err);
      }
    }
  
    // ── Render ────────────────────────────────────────────────────
    function renderResults(groups, total, q) {
      if (!dropdown) return;
  
      if (total === 0) {
        dropdown.innerHTML = `<div class="sdr-empty">
          <div style="font-size:1.6rem;margin-bottom:.4rem">🔍</div>
          Aucun résultat pour "<strong>${escHtml(q)}</strong>"
        </div>`;
        openDropdown();
        return;
      }
  
      let html = '';
      groups.forEach(group => {
        html += `<div class="sdr-group-header"><span>${escHtml(group.icon)}</span><span>${escHtml(group.group)}</span></div>`;
        group.items.forEach((item, idx) => {
          const label     = highlight(escHtml(item.label || ''), q);
          const sublabel  = escHtml(item.sublabel || '');
          const badge     = item.badge ? `<span class="sdr-badge" style="${BADGE_CSS[item.badge_class] || BADGE_CSS['chip-muted']}">${escHtml(item.badge)}</span>` : '';
          const note      = item.note ? `<span style="color:var(--amber);font-size:.65rem">⭐${item.note}</span>` : '';
  
          html += `<div class="sdr-item" role="option"
            data-action="${escHtml(item.action || 'navigate')}"
            data-url="${escHtml(item.url || '#')}"
            data-id="${escHtml(String(item.id || ''))}"
            data-titre="${escHtml(item.label || '')}"
            data-prix="${escHtml(String(item.prix || 0))}"
            tabindex="-1">
            <div class="sdr-icon">${escHtml(item.icon || '•')}</div>
            <div class="sdr-info">
              <div class="sdr-label">${label}</div>
              ${sublabel ? `<div class="sdr-sub">${sublabel}</div>` : ''}
            </div>
            ${note}${badge}
          </div>`;
        });
      });
  
      html += `<div class="sdr-footer">
        <span>${total} résultat${total > 1 ? 's' : ''} pour "${escHtml(q)}"</span>
        <span style="cursor:pointer;color:var(--cyan)" onclick="window.location.href='books/index.php?search=${encodeURIComponent(q)}'">Voir tout →</span>
      </div>`;
  
      dropdown.innerHTML = html;
  
      // Bind clicks
      dropdown.querySelectorAll('.sdr-item').forEach(el => {
        el.addEventListener('click', () => handleItemClick(el));
      });
  
      openDropdown();
    }
  
    // ── Actions des items ────────────────────────────────────────
    function handleItemClick(el) {
      const action = el.dataset.action;
      const url    = el.dataset.url;
      const id     = el.dataset.id;
      const titre  = el.dataset.titre;
      const prix   = parseFloat(el.dataset.prix || '0');
  
      closeDropdown();
  
      switch (action) {
        case 'navigate':
          window.location.href = url;
          break;
        case 'view':
          window.location.href = url;
          break;
        case 'read':
          window.location.href = url;
          break;
        case 'buy':
          // Ouvre le modal de paiement DLS
          if (typeof window.showPaymentModal === 'function') {
            window.showPaymentModal(parseInt(id, 10), titre, prix);
          } else {
            window.location.href = url;
          }
          break;
        case 'open_ai':
          const aiPanel = document.getElementById('ai-panel');
          if (aiPanel) aiPanel.classList.add('open');
          break;
        default:
          if (url && url !== '#') window.location.href = url;
      }
    }
  
    // ── Helpers UI ───────────────────────────────────────────────
    function showSpinner() {
      if (!dropdown) return;
      dropdown.innerHTML = `<div class="sdr-spinner">
        <div class="sdr-dot"></div><div class="sdr-dot"></div><div class="sdr-dot"></div>
        <span>Recherche en cours…</span>
      </div>`;
      openDropdown();
    }
  
    function showError(msg) {
      if (!dropdown) return;
      dropdown.innerHTML = `<div class="sdr-empty" style="color:var(--rose)">⚠️ ${escHtml(msg)}</div>`;
      openDropdown();
    }
  
    function openDropdown()  { dropdown?.classList.add('open'); }
    function closeDropdown() { dropdown?.classList.remove('open'); focusedIdx = -1; }
  
    function highlight(text, query) {
      if (!query) return text;
      const re = new RegExp('(' + escRegex(query) + ')', 'gi');
      return text.replace(re, '<mark>$1</mark>');
    }
  
    function escHtml(str) {
      return String(str)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }
  
    function escRegex(str) {
      return str.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }
  
    // ── Lancement ────────────────────────────────────────────────
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', init);
    } else {
      init();
    }
  })();