

(function () {
    'use strict';

    // ── Helpers ──────────────────────────────────────────────────
    function esc(s) {
        return String(s ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function getUrl() {
        return (window.APP_URL || '').replace(/\/$/, '');
    }

    // ── Icon map ─────────────────────────────────────────────────
    const TYPE_MAP = {
        task:     { icon: 'fa-list-check',  cls: 'notif-task'     },
        transfer: { icon: 'fa-right-left',  cls: 'notif-transfer' },
        status:   { icon: 'fa-tag',         cls: 'notif-status'   },
        system:   { icon: 'fa-gear',        cls: 'notif-system'   },
        reminder: { icon: 'fa-clock',       cls: 'notif-reminder' },
    };

    // ── Fetch & render ───────────────────────────────────────────
    async function fetchNotifications() {
        try {
            const res = await fetch(getUrl() + '/api/notifications.php', {
                credentials: 'same-origin',
                cache: 'no-store',
            });
            if (!res.ok) throw new Error('HTTP ' + res.status);
            return await res.json();
        } catch (err) {
            console.warn('[MISPro Notif] Fetch failed:', err.message);
            return null;
        }
    }

    function updateBadge(count) {
        const badge = document.getElementById('notif-count');
        if (!badge) return;
        if (count > 0) {
            badge.textContent = count > 9 ? '9+' : count;
            badge.style.display = 'flex';
        } else {
            badge.style.display = 'none';
            badge.textContent = '';
        }
    }

    function renderList(data) {
        const list = document.getElementById('notif-list');
        if (!list) return;

        if (!data || !Array.isArray(data.notifications) || data.notifications.length === 0) {
            list.innerHTML = `
                <div style="text-align:center;padding:2rem 1rem;color:#94a3b8;">
                    <i class="fas fa-bell-slash" style="font-size:1.6rem;display:block;margin-bottom:.6rem;opacity:.5;"></i>
                    <span style="font-size:.82rem;">No notifications yet</span>
                </div>`;
            return;
        }

        list.innerHTML = data.notifications.map(n => {
            const t = TYPE_MAP[n.type] || TYPE_MAP.system;
            const unreadDot = !n.is_read
                ? `<div style="width:7px;height:7px;min-width:7px;border-radius:50%;background:#c9a84c;margin-top:4px;flex-shrink:0;"></div>`
                : '';
            return `
                <a href="${esc(n.link || '#')}"
                   class="notif-item${n.is_read ? '' : ' unread'}"
                   data-id="${esc(n.id)}"
                   onclick="MISNotif.markOne(${parseInt(n.id, 10)}, this)">
                    <div class="notif-icon ${t.cls}">
                        <i class="fas ${t.icon}"></i>
                    </div>
                    <div style="flex:1;min-width:0;">
                        <div class="notif-title">${esc(n.title)}</div>
                        <div class="notif-msg">${esc(n.message)}</div>
                        <div class="notif-time">${esc(n.time_ago)}</div>
                    </div>
                    ${unreadDot}
                </a>`;
        }).join('');
    }

    async function refresh() {
        const data = await fetchNotifications();
        if (data === null) return; // network failure — keep existing UI
        updateBadge(data.unread ?? 0);
        renderList(data);
    }

    // ── Mark one read ────────────────────────────────────────────
    async function markOne(id, el) {
        try {
            await fetch(getUrl() + '/ajax/mark_notification_read.php?id=' + id, {
                credentials: 'same-origin',
            });
        } catch (_) {}
        if (el) {
            el.classList.remove('unread');
            const dot = el.querySelector('[style*="border-radius:50%"]');
            if (dot) dot.remove();
        }
        // Decrement badge
        const badge = document.getElementById('notif-count');
        if (badge && badge.style.display !== 'none') {
            const cur = parseInt(badge.textContent, 10) || 0;
            updateBadge(Math.max(0, cur - 1));
        }
    }

    // ── Mark all read ────────────────────────────────────────────
    async function markAll(e) {
        if (e) e.preventDefault();
        try {
            await fetch(getUrl() + '/ajax/mark_notification_read.php?all=1', {
                credentials: 'same-origin',
            });
        } catch (_) {}
        document.querySelectorAll('.notif-item.unread').forEach(el => {
            el.classList.remove('unread');
            const dot = el.querySelector('[style*="border-radius:50%"]');
            if (dot) dot.remove();
        });
        updateBadge(0);
    }

    // ── Bind "Mark all read" link ────────────────────────────────
    function bindMarkAll() {
        const btn = document.getElementById('mark-all-read');
        if (btn && !btn.dataset.bound) {
            btn.dataset.bound = '1';
            btn.addEventListener('click', markAll);
        }
    }

    // ── Polling ──────────────────────────────────────────────────
    let pollTimer = null;
    function startPolling() {
        if (pollTimer) return;
        pollTimer = setInterval(refresh, 30000);
    }

    // ── Bootstrap 5 dropdown open event ─────────────────────────
    // Reload list every time the bell dropdown is opened
    function bindDropdownOpen() {
        document.addEventListener('show.bs.dropdown', function (e) {
            const toggle = e.relatedTarget || e.target;
            if (toggle && toggle.closest('.notif-dropdown')) {
                refresh();
            }
        });
    }

    // ── Expose globally for inline onclick ───────────────────────
    window.MISNotif = { markOne, markAll, refresh };

    // ── Init ─────────────────────────────────────────────────────
    function init() {
        if (!window.APP_USER_ID) return; // not logged in

        bindMarkAll();
        bindDropdownOpen();
        refresh();
        startPolling();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();