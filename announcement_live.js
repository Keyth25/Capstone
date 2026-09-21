/**
 * announcement_live.js
 * Polls get_announcements.php so newly published admin announcements appear
 * on the user's screen immediately (toast popup + "announcements:updated"
 * DOM event that pages can listen to for live re-rendering).
 */
(function () {
    'use strict';

    var POLL_INTERVAL = 5000;   // 5 seconds
    var TOAST_DURATION = 15000; // 15 seconds
    var MAX_TOASTS = 3;

    var prevActiveIds = null;   // Set of active announcement ids from last poll (null = not initialized)
    var lastSignature = null;   // Serialized announcement list for change detection

    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function nl2br(s) {
        return escapeHtml(s).replace(/\r?\n/g, '<br>');
    }

    function formatDate(str) {
        if (!str) return '';
        var d = new Date(String(str).replace(' ', 'T'));
        if (isNaN(d.getTime())) return escapeHtml(str);
        return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    }

    function isActiveNow(a) {
        if (!a.is_active) return false;
        var today = new Date();
        today.setHours(0, 0, 0, 0);
        if (a.start_date && new Date(a.start_date + 'T00:00:00') > today) return false;
        if (a.end_date && new Date(a.end_date + 'T00:00:00') < today) return false;
        return true;
    }

    // ---------- Toast popup ----------

    function getToastContainer() {
        var c = document.getElementById('annToastContainer');
        if (!c) {
            c = document.createElement('div');
            c.id = 'annToastContainer';
            c.className = 'fixed bottom-4 right-4 z-[110] flex flex-col gap-3 items-end pointer-events-none';
            document.body.appendChild(c);
        }
        return c;
    }

    function removeToast(el) {
        if (!el || el.dataset.closing) return;
        el.dataset.closing = '1';
        el.classList.add('opacity-0', 'translate-y-2');
        setTimeout(function () { el.remove(); }, 250);
    }

    function showToast(a) {
        var container = getToastContainer();
        while (container.children.length >= MAX_TOASTS) {
            container.removeChild(container.lastElementChild);
        }

        var toast = document.createElement('div');
        toast.className = 'ann-toast pointer-events-auto w-80 max-w-[calc(100vw-2rem)] rounded-2xl border border-cyan-500/30 bg-white/95 dark:bg-slate-900/95 shadow-2xl shadow-cyan-500/10 backdrop-blur-md p-4 transition-all duration-300 opacity-0 translate-y-2';
        toast.innerHTML =
            '<div class="flex items-start gap-3">' +
                '<div class="w-9 h-9 rounded-xl bg-gradient-to-tr from-cyan-500 to-blue-600 text-white flex items-center justify-center shrink-0 shadow-lg shadow-cyan-500/30">' +
                    '<svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m3 11 18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/></svg>' +
                '</div>' +
                '<div class="flex-1 min-w-0">' +
                    '<p class="text-[10px] font-bold uppercase tracking-wider text-cyan-600 dark:text-cyan-400 flex items-center gap-1.5">' +
                        '<span class="w-1.5 h-1.5 rounded-full bg-cyan-500 animate-pulse"></span>New Announcement' +
                    '</p>' +
                    '<h4 class="text-sm font-bold text-slate-900 dark:text-white truncate mt-0.5">' + escapeHtml(a.title) + '</h4>' +
                    '<p class="text-xs text-slate-600 dark:text-slate-300 mt-1 leading-relaxed line-clamp-3">' + nl2br(a.message) + '</p>' +
                    '<a href="user_announcements.php" class="inline-flex items-center gap-1 mt-2 text-[11px] font-bold text-cyan-600 dark:text-cyan-400 hover:underline">' +
                        'View announcements' +
                        '<svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>' +
                    '</a>' +
                '</div>' +
                '<button type="button" class="ann-toast-close p-1 rounded-lg text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 transition shrink-0" aria-label="Dismiss">' +
                    '<svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>' +
                '</button>' +
            '</div>';

        toast.querySelector('.ann-toast-close').addEventListener('click', function () {
            removeToast(toast);
        });

        container.appendChild(toast);
        requestAnimationFrame(function () {
            requestAnimationFrame(function () {
                toast.classList.remove('opacity-0', 'translate-y-2');
            });
        });

        setTimeout(function () { removeToast(toast); }, TOAST_DURATION);
    }

    // ---------- Polling ----------

    function poll() {
        fetch('get_announcements.php', { cache: 'no-store', credentials: 'same-origin' })
            .then(function (r) {
                if (!r.ok) return null;
                return r.json();
            })
            .then(function (res) {
                if (!res || res.status !== 'success' || !Array.isArray(res.data)) return;

                var all = res.data;
                var signature = JSON.stringify(all);
                if (signature === lastSignature) return; // nothing changed

                var active = all.filter(isActiveNow);
                var activeIds = new Set(active.map(function (a) { return String(a.id); }));

                var fresh = [];
                if (prevActiveIds !== null) {
                    fresh = active.filter(function (a) { return !prevActiveIds.has(String(a.id)); });
                }

                lastSignature = signature;
                prevActiveIds = activeIds;

                if (fresh.length) {
                    fresh.forEach(showToast);
                }

                document.dispatchEvent(new CustomEvent('announcements:updated', {
                    detail: { all: all, active: active, fresh: fresh }
                }));
            })
            .catch(function () { /* network/db hiccup - retry next tick */ });
    }

    // Expose helpers so pages can render announcements consistently
    window.AnnouncementLive = {
        escapeHtml: escapeHtml,
        nl2br: nl2br,
        formatDate: formatDate,
        isActiveNow: isActiveNow
    };

    document.addEventListener('DOMContentLoaded', function () {
        poll();
        setInterval(poll, POLL_INTERVAL);
    });
})();
