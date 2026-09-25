(() => {
  'use strict';
  const config = window.SSFInspection;
  if (!config) return;
  const status = document.getElementById('si-sync');
  const DB_NAME = `ssf-inspection-pending-v1-user-${Number(config.user)}`;
  let dbPromise;
  let draining = false;
  let pendingCount = 0;
  let failedCount = 0;
  const timers = new Map();

  const uuid = () => (crypto.randomUUID ? crypto.randomUUID() : Array.from(crypto.getRandomValues(new Uint8Array(16)), (x) => x.toString(16).padStart(2, '0')).join(''));
  function db() {
    if (!dbPromise) dbPromise = new Promise((resolve, reject) => {
      const request = indexedDB.open(DB_NAME, 1);
      request.onupgradeneeded = () => {
        const store = request.result.createObjectStore('pending', { keyPath: 'id' });
        store.createIndex('created', 'created');
      };
      request.onsuccess = () => resolve(request.result);
      request.onerror = () => reject(request.error);
    });
    return dbPromise;
  }
  async function transaction(mode, action) {
    const database = await db();
    return new Promise((resolve, reject) => {
      const tx = database.transaction('pending', mode);
      const result = action(tx.objectStore('pending'));
      tx.oncomplete = () => resolve(result.result);
      tx.onerror = () => reject(tx.error);
    });
  }
  const all = () => transaction('readonly', (store) => store.getAll());
  const put = (entry) => transaction('readwrite', (store) => store.put(entry));
  const remove = (id) => transaction('readwrite', (store) => store.delete(id));
  function showStatus() {
    if (!status) return;
    status.textContent = failedCount ? `⚠ ${failedCount} ändringar behöver åtgärdas · ${pendingCount} väntar` : pendingCount ? `⟳ ${pendingCount} ändringar väntar på anslutning` : draining ? '⟳ Synkar...' : '✓ Sparat';
    status.classList.toggle('si-error', failedCount > 0);
    status.classList.toggle('si-waiting', pendingCount > 0);
  }
  async function refresh() {
    const entries = await all();
    pendingCount = entries.length;
    failedCount = entries.filter((entry) => entry.failed).length;
    showStatus();
    return entries;
  }
  async function request(path, options = {}) {
    const response = await fetch(config.rest + path, {
      credentials: 'same-origin',
      ...options,
      headers: { 'X-WP-Nonce': config.nonce, ...(options.headers || {}) },
    });
    const body = await response.json().catch(() => ({}));
    if (!response.ok) {
      const error = new Error(body.message || 'Servern kunde inte spara.');
      error.status = response.status;
      throw error;
    }
    return body;
  }
  async function enqueue(entry) {
    pendingCount += 1;
    showStatus();
    try { await put({ id: uuid(), created: Date.now(), ...entry }); }
    catch (error) { pendingCount -= 1; showStatus(); throw error; }
    await refresh();
    drain();
  }
  async function transmit(entry) {
    if (entry.type === 'photo') {
      const form = new FormData();
      form.append('snapshot_id', entry.snapshot);
      form.append('operation_id', entry.id);
      form.append('photo', entry.blob, 'inspection.jpg');
      return request(`${entry.inspection}/photo`, { method: 'POST', body: form });
    }
    if (entry.type === 'answer') {
      return request(`${entry.inspection}/answer`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ ...entry.data, snapshot_id: entry.snapshot, operation_id: entry.id }) });
    }
    if (entry.type === 'delete') return request(`${entry.inspection}/photo/${entry.photo}`, { method: 'DELETE' });
    if (entry.type === 'caption') return request(`${entry.inspection}/photo/${entry.photo}`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ caption: entry.caption }) });
    throw new Error('Okänd ändring.');
  }
  async function drain() {
    if (draining || !navigator.onLine) return;
    draining = true;
    showStatus();
    try {
      const entries = (await all()).sort((a, b) => a.created - b.created);
      for (const entry of entries) {
        if (entry.failed) break;
        try {
          const result = await transmit(entry);
          await remove(entry.id);
          if (entry.type === 'photo') {
            const pending = document.querySelector(`[data-pending="${entry.id}"]`);
            if (pending) {
              pending.dataset.photo = result.id;
              pending.removeAttribute('data-pending');
              pending.querySelector('span').textContent = '✓ Uppladdad';
              pending.querySelector('img').src = result.url;
              const caption = pending.querySelector('figcaption');
              const input = document.createElement('input');
              input.className = 'si-caption'; input.placeholder = 'Bildtext'; input.setAttribute('aria-label', 'Bildtext');
              const button = document.createElement('button');
              button.type = 'button'; button.className = 'si-delete-photo'; button.dataset.photo = result.id; button.textContent = 'Ta bort';
              caption.append(input, button);
            }
          }
        } catch (error) {
          if (error.status && error.status < 500) {
            entry.failed = error.message;
            await put(entry);
            const photo = document.querySelector(`[data-pending="${entry.id}"] span`);
            if (photo) photo.textContent = `⚠ ${error.message}`;
          }
          break;
        }
      }
    } finally {
      draining = false;
      await refresh();
    }
  }
  async function retryFailures() {
    const entries = await all();
    for (const entry of entries) if (entry.failed) { delete entry.failed; await put(entry); }
    await refresh();
    drain();
  }
  status?.addEventListener('click', retryFailures);
  window.addEventListener('online', drain);
  window.addEventListener('beforeunload', (event) => { if (pendingCount) { event.preventDefault(); event.returnValue = ''; } });
  setInterval(drain, 8000);
  refresh().then(async (entries) => {
    for (const entry of entries) {
      if (entry.type !== 'photo' || document.querySelector(`[data-pending="${entry.id}"]`)) continue;
      const item = document.querySelector(`.si-item[data-inspection="${entry.inspection}"][data-snapshot="${entry.snapshot}"]`);
      if (!item) continue;
      const figure = document.createElement('figure');
      figure.className = 'si-photo'; figure.dataset.pending = entry.id;
      const img = document.createElement('img'); img.src = URL.createObjectURL(entry.blob); img.alt = 'Bild väntar på uppladdning';
      const caption = document.createElement('figcaption');
      const label = document.createElement('span'); label.textContent = entry.failed ? `⚠ ${entry.failed}` : '⟳ Väntar på uppladdning';
      caption.append(label); figure.append(img, caption); item.querySelector('.si-photos').append(figure);
    }
    drain();
  }).catch(() => { if (status) status.textContent = '⚠ Lokal lagring är inte tillgänglig'; });

  const create = document.getElementById('si-create');
  create?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const button = create.querySelector('button');
    button.disabled = true;
    try {
      const data = Object.fromEntries(new FormData(create));
      const result = await request('create', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data) });
      location.href = result.url;
    } catch (error) { alert(error.message); button.disabled = false; }
  });

  function readAnswer(item) {
    return {
      assessment: item.querySelector('input[type="radio"]:checked')?.value || '',
      comment: item.querySelector('[name="comment"]')?.value || '',
      proposed_action: item.querySelector('[name="proposed_action"]')?.value || '',
      priority: item.querySelector('[name="priority"]')?.value || '',
      follow_up_status: item.querySelector('[name="follow_up_status"]')?.value || '',
    };
  }
  function reveal(item) {
    const assessment = readAnswer(item).assessment;
    item.classList.toggle('si-has-remark', assessment === 'remark' || assessment === 'serious_remark');
  }
  async function saveAnswer(item) {
    const data = readAnswer(item);
    if (!data.assessment) return;
    await enqueue({ type: 'answer', inspection: Number(item.dataset.inspection), snapshot: Number(item.dataset.snapshot), data });
  }
  document.querySelectorAll('.si-item[data-readonly="0"]').forEach((item) => {
    reveal(item);
    item.querySelectorAll('input,textarea,select').forEach((input) => {
      if (input.classList.contains('si-photo-input') || input.classList.contains('si-caption')) return;
      input.addEventListener('change', () => {
        reveal(item);
        clearTimeout(timers.get(item));
        timers.set(item, setTimeout(() => saveAnswer(item).catch((error) => alert(error.message)), 350));
      });
      if (input.tagName === 'TEXTAREA') input.addEventListener('input', () => {
        clearTimeout(timers.get(item));
        timers.set(item, setTimeout(() => saveAnswer(item).catch((error) => alert(error.message)), 700));
      });
    });
    item.querySelector('.si-save-next')?.addEventListener('click', async () => {
      if (!readAnswer(item).assessment) { alert('Välj en bedömning först.'); return; }
      clearTimeout(timers.get(item));
      await saveAnswer(item);
      (item.nextElementSibling?.classList.contains('si-item') ? item.nextElementSibling : document.querySelector('a[href*="sammanfattning"]'))?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
    item.querySelectorAll('.si-photo-input').forEach((input) => input.addEventListener('change', async () => {
      for (const file of input.files || []) {
        try {
          const blob = await optimize(file);
          const id = uuid();
          const figure = document.createElement('figure');
          figure.className = 'si-photo';
          figure.dataset.pending = id;
          const img = document.createElement('img');
          img.alt = 'Ny inspektionsbild';
          img.src = URL.createObjectURL(blob);
          const caption = document.createElement('figcaption');
          const label = document.createElement('span');
          label.textContent = '⟳ Väntar på uppladdning';
          caption.append(label); figure.append(img, caption);
          item.querySelector('.si-photos').append(figure);
          await put({ id, created: Date.now(), type: 'photo', inspection: Number(item.dataset.inspection), snapshot: Number(item.dataset.snapshot), blob });
          await refresh(); drain();
        } catch (error) { alert('Bilden kunde inte lagras lokalt: ' + error.message); }
      }
      input.value = '';
    }));
  });
  async function optimize(file) {
    if (!/^image\/(jpeg|png|webp)$/.test(file.type)) throw new Error('Endast JPEG, PNG och WebP stöds.');
    if (file.size > 25 * 1024 * 1024) throw new Error('Bilden är för stor.');
    const bitmap = typeof createImageBitmap === 'function' ? await createImageBitmap(file) : await new Promise((resolve, reject) => {
      const url = URL.createObjectURL(file);
      const image = new Image();
      image.onload = () => { URL.revokeObjectURL(url); resolve(image); };
      image.onerror = () => { URL.revokeObjectURL(url); reject(new Error('Bilden kunde inte läsas.')); };
      image.src = url;
    });
    const scale = Math.min(1, 1900 / Math.max(bitmap.width, bitmap.height));
    const canvas = document.createElement('canvas');
    canvas.width = Math.round(bitmap.width * scale);
    canvas.height = Math.round(bitmap.height * scale);
    canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height);
    bitmap.close?.();
    return new Promise((resolve, reject) => canvas.toBlob((blob) => blob ? resolve(blob) : reject(new Error('Bildoptimering misslyckades.')), 'image/jpeg', 0.84));
  }
  document.addEventListener('click', async (event) => {
    const button = event.target.closest('.si-delete-photo');
    if (!button || !confirm('Ta bort bilden?')) return;
    const item = button.closest('.si-item');
    const figure = button.closest('.si-photo');
    figure.remove();
    await enqueue({ type: 'delete', inspection: Number(item.dataset.inspection), photo: Number(button.dataset.photo) });
  });
  document.addEventListener('change', async (event) => {
    if (!event.target.matches('.si-caption')) return;
    const item = event.target.closest('.si-item');
    const figure = event.target.closest('.si-photo');
    await enqueue({ type: 'caption', inspection: Number(item.dataset.inspection), photo: Number(figure.dataset.photo), caption: event.target.value });
  });
  document.getElementById('si-complete')?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const form = event.target;
    await refresh();
    if (pendingCount) { alert('Vänta tills alla lokala ändringar och bilder har synkats. Tryck på synkstatus för att försöka igen.'); return; }
    try {
      const data = Object.fromEntries(new FormData(form));
      const result = await request(`${form.dataset.id}/complete`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ ...data, confirmed: true, pending_count: 0 }) });
      location.href = result.url;
    } catch (error) { alert(error.message); }
  });
  document.getElementById('si-followup')?.addEventListener('click', async (event) => {
    try { const result = await request(`${event.target.dataset.id}/followup`, { method: 'POST' }); location.href = result.url; }
    catch (error) { alert(error.message); }
  });
  document.getElementById('si-reopen')?.addEventListener('submit', async (event) => {
    event.preventDefault();
    try {
      await request(`${event.target.dataset.id}/reopen`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ reason: event.target.reason.value }) });
      location.reload();
    } catch (error) { alert(error.message); }
  });
})();
