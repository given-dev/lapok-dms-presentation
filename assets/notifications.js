/**
 * In-app notifications — cadets receive from manager / RDC / admin.
 */
(function () {
  let notifState = { items: [], history: [], unread: 0, canSend: false, cadets: null };

  const SEVERITY_CLASS = { info: 'a-info', warning: 'a-warning', danger: 'a-danger' };
  const SEVERITY_ICON = { info: 'ℹ', warning: '⚠', danger: '⚠' };

  function esc(s) {
    return String(s || '').replace(/[&<>"']/g, (m) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
  }

  function formatWhen(iso) {
    if (!iso) return '';
    try {
      const d = new Date(iso);
      return d.toLocaleString(undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
    } catch {
      return iso;
    }
  }

  function updateNotifDot() {
    const dot = document.getElementById('notifUnreadDot');
    if (!dot) return;
    dot.style.display = notifState.unread > 0 ? 'block' : 'none';
  }

  function renderNotifItem(n) {
    const cls = SEVERITY_CLASS[n.severity] || 'a-info';
    const icon = SEVERITY_ICON[n.severity] || 'ℹ';
    const unread = !n.is_read ? ' style="border-left:3px solid var(--red)"' : '';
    const link = `<button class="btn btn-sm" type="button" style="margin-top:6px" onclick="openNotificationMessage(${n.id})">Open →</button>`;
    return `<div class="alert ${cls}"${unread} data-notif-id="${n.id}">
      <span>${icon}</span>
      <div><strong>${esc(n.from)}</strong> · <span style="font-size:11px;color:var(--gray-mid)">${formatWhen(n.created_at)}</span>
        <div style="font-weight:600;margin-top:2px">${esc(n.title)}</div>
        <div style="font-size:13px;margin-top:2px">${esc(n.body)}</div>${link}</div></div>`;
  }

  function renderNotifListHtml(items, emptyText) {
    if (!items.length) {
      return `<p style="color:var(--gray-mid);font-size:13px">${emptyText || 'No notifications yet.'}</p>`;
    }
    return items.map(renderNotifItem).join('');
  }

  async function refreshNotifications() {
    try {
      const data = await LapokAPI.get('/api/notifications/fetch.php');
      notifState.items = data.items || [];
      notifState.history = data.history || [];
      notifState.unread = data.unread_count || 0;
      notifState.canSend = !!data.can_send;
      updateNotifDot();
      const list = document.getElementById('notifList');
      if (list && document.getElementById('notifModal')?.classList.contains('open')) {
        list.innerHTML = renderNotifListHtml(notifState.items);
      }
      const dash = document.getElementById('cadetDashNotifList');
      if (dash) {
        dash.innerHTML = renderNotifListHtml(notifState.history.slice(0, 5), 'No messages from manager or RDC yet.');
      }
      const dashCard = document.getElementById('cadetDashNotifCard');
      if (dashCard) dashCard.style.display = 'block';
      return data;
    } catch (e) {
      console.warn('Notifications:', e.message);
      return null;
    }
  }

  async function loadCadetsForSend() {
    if (notifState.cadets) return notifState.cadets;
    try {
      const data = await LapokAPI.get('/api/notifications/recipients.php');
      notifState.cadets = data.recipients || [];
    } catch {
      notifState.cadets = [];
    }
    return notifState.cadets;
  }

  async function renderSendForm() {
    const box = document.getElementById('notifSendForm');
    if (!box) return;
    if (!notifState.canSend) {
      box.style.display = 'none';
      box.innerHTML = '';
      return;
    }
    const cadets = await loadCadetsForSend();
    const opts = cadets.map((c) => `<option value="${c.id}">${esc(c.full_name)} (${c.role})</option>`).join('');
    box.style.display = 'block';
    box.innerHTML = `
      <div style="border-top:1px solid var(--gray-light);margin-top:12px;padding-top:12px">
        <div style="font-size:12px;font-weight:700;margin-bottom:8px">Send to cadet</div>
        <div class="form-group"><label>Recipient</label>
          <select class="select-inp" id="notifSendRecipient">
            <option value="">— Select cadet —</option>${opts}
            <option value="all">All active cadets</option>
          </select></div>
        <div class="form-group"><label>Title</label><input class="input" id="notifSendTitle" maxlength="160" placeholder="e.g. Submit report by 7pm"></div>
        <div class="form-group"><label>Message</label><textarea class="textarea-inp" id="notifSendMessage" rows="2" placeholder="Short instruction for the field"></textarea></div>
        <button class="btn btn-red btn-full" type="button" onclick="sendStaffNotification()">Send notification</button>
      </div>`;
  }

  async function openNotifModal() {
    await refreshNotifications();
    const list = document.getElementById('notifList');
    if (list) list.innerHTML = renderNotifListHtml(notifState.items);
    await renderSendForm();
    if (typeof openModal === 'function') openModal('notifModal');
  }

  async function openMessagesModal() {
    await refreshNotifications();
    const list = document.getElementById('messagesList');
    if (list) list.innerHTML = renderNotifListHtml(notifState.history, 'No messages yet.');
    if (typeof openModal === 'function') openModal('messagesModal');
  }

  async function dismissAllNotifs() {
    try {
      await LapokAPI.post('/api/notifications/mark_read.php', { all: true });
      notifState.unread = 0;
      notifState.items = [];
      notifState.history = notifState.history.map((n) => ({ ...n, is_read: true }));
      updateNotifDot();
      const list = document.getElementById('notifList');
      if (list) list.innerHTML = renderNotifListHtml([], 'No unread notifications.');
      const dash = document.getElementById('cadetDashNotifList');
      if (dash) dash.innerHTML = renderNotifListHtml(notifState.history.slice(0, 5), 'No messages from manager or RDC yet.');
    } catch (e) {
      if (typeof adminToast === 'function') adminToast(e.message, true);
    }
    if (typeof closeModal === 'function') closeModal('notifModal');
  }

  async function openNotificationMessage(notifId) {
    const notification = notifState.history.find((n) => Number(n.id) === Number(notifId))
      || notifState.items.find((n) => Number(n.id) === Number(notifId));
    if (!notification) return;

    if (!notification.is_read) {
      try {
        await LapokAPI.post('/api/notifications/mark_read.php', { ids: [notifId] });
        notifState.items = notifState.items.filter((n) => Number(n.id) !== Number(notifId));
        notifState.history = notifState.history.map((n) => Number(n.id) === Number(notifId) ? { ...n, is_read: true } : n);
        notifState.unread = Math.max(0, notifState.unread - 1);
        updateNotifDot();
      } catch (_) {}
    }

    const title = document.getElementById('notificationDetailTitle');
    const from = document.getElementById('notificationDetailFrom');
    const when = document.getElementById('notificationDetailWhen');
    const body = document.getElementById('notificationDetailBody');
    if (title) title.textContent = notification.title || 'Message';
    if (from) from.textContent = notification.from || 'System';
    if (when) when.textContent = formatWhen(notification.created_at) || 'Not recorded';
    if (body) body.textContent = notification.body || 'No message content.';

    if (typeof closeModal === 'function') {
      closeModal('notifModal');
      closeModal('messagesModal');
    }
    if (typeof openModal === 'function') openModal('notificationDetailModal');
  }

  async function sendStaffNotification() {
    const recipient = document.getElementById('notifSendRecipient')?.value || '';
    const title = document.getElementById('notifSendTitle')?.value?.trim() || '';
    const message = document.getElementById('notifSendMessage')?.value?.trim() || '';
    if (!recipient) {
      if (typeof adminToast === 'function') adminToast('Select a cadet', true);
      return;
    }
    if (!title || !message) {
      if (typeof adminToast === 'function') adminToast('Title and message required', true);
      return;
    }
    const payload = { title, message, severity: 'info', link_page: 'cadet-dashboard' };
    if (recipient === 'all') payload.broadcast_cadets = true;
    else payload.recipient_id = Number(recipient);
    try {
      const res = await LapokAPI.post('/api/notifications/send.php', payload);
      if (typeof adminToast === 'function') adminToast(res.message || 'Sent');
      document.getElementById('notifSendTitle').value = '';
      document.getElementById('notifSendMessage').value = '';
    } catch (e) {
      if (typeof adminToast === 'function') adminToast(e.message, true);
    }
  }

  let deadlineState = null;

  function renderDeadlineBanner(status) {
    deadlineState = status || null;
    const banner = status?.banner || null;
    const roleMap = {
      cadet: 'deadlineBannerCadet',
      field_user: 'deadlineBannerCadet',
      rdc: 'deadlineBannerRdc',
      accountant: 'deadlineBannerRdc',
      manager: 'deadlineBannerManager',
    };
    // Hide all first
    ['deadlineBannerCadet', 'deadlineBannerRdc', 'deadlineBannerManager'].forEach((id) => {
      const el = document.getElementById(id);
      if (el) {
        el.style.display = 'none';
        el.innerHTML = '';
      }
    });
    if (!banner) return;

    const targetId = roleMap[banner.role] || roleMap[window.currentUser?.role] || null;
    const el = targetId ? document.getElementById(targetId) : null;
    if (!el) return;

    const phase = banner.phase || 'warn';
    const tone = phase === 'done' ? 'a-success'
      : phase === 'overdue' || phase === 'urgent' ? 'a-danger'
        : phase === 'info' ? 'a-info'
          : 'a-warning';
    const icon = phase === 'done' ? '✓' : (phase === 'info' ? 'ℹ' : '⏰');
    const link = banner.link_page
      ? `<button class="btn btn-sm" type="button" style="margin-left:8px" onclick="showPage('${esc(banner.link_page)}')">Open →</button>`
      : '';
    el.className = `alert ${tone}`;
    el.style.display = 'flex';
    el.innerHTML = `<span>${icon}</span><div><strong>${esc(banner.title)}</strong><div style="font-size:13px;margin-top:2px">${esc(banner.body)}${link}</div></div>`;
  }

  async function runDeadlineReminders() {
    try {
      const data = await LapokAPI.get('/api/ops/run_deadline_reminders.php');
      renderDeadlineBanner(data.status || null);
      // If reminders were sent, refresh bell list
      if ((data.run?.sent_total || 0) > 0) {
        await refreshNotifications();
      }
      return data;
    } catch (e) {
      console.warn('Deadline reminders:', e.message);
      return null;
    }
  }

  function initNotifications() {
    refreshNotifications();
    runDeadlineReminders();
    setInterval(refreshNotifications, 60000);
    // Deadline checks every 5 minutes while the app is open
    setInterval(runDeadlineReminders, 5 * 60 * 1000);
  }

  window.openNotifModal = openNotifModal;
  window.openMessagesModal = openMessagesModal;
  window.dismissAllNotifs = dismissAllNotifs;
  window.openNotificationMessage = openNotificationMessage;
  window.sendStaffNotification = sendStaffNotification;
  window.refreshNotifications = refreshNotifications;
  window.runDeadlineReminders = runDeadlineReminders;
  window.initNotifications = initNotifications;

  document.addEventListener('DOMContentLoaded', () => {
    if (window.currentUser) initNotifications();
  });
})();
