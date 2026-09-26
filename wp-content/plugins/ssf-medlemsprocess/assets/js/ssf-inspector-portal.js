(() => {
  'use strict';
  const config = window.SSFMembershipInspection;
  if (!config) return;
  const status = document.getElementById('ssf-inspection-sync');
  const timers = new Map();
  const dbName = `ssf-membership-inspection-v1-user-${Number(config.user)}-inspection-${Number(config.inspection)}`;
  let databasePromise;
  let draining = false;

  const uuid = () => {
    if (crypto.randomUUID) return crypto.randomUUID();
    const bytes = crypto.getRandomValues(new Uint8Array(16)); bytes[6] = (bytes[6] & 15) | 64; bytes[8] = (bytes[8] & 63) | 128;
    const hex = Array.from(bytes, x => x.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
  };
  const database = () => databasePromise ||= new Promise((resolve, reject) => {
    const request = indexedDB.open(dbName, 1);
    request.onupgradeneeded = () => request.result.createObjectStore('pending', { keyPath: 'id' });
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error);
  });
  const transaction = async (mode, callback) => new Promise(async (resolve, reject) => {
    const tx = (await database()).transaction('pending', mode);
    const request = callback(tx.objectStore('pending'));
    tx.oncomplete = () => resolve(request?.result);
    tx.onerror = () => reject(tx.error);
  });
  const all = () => transaction('readonly', store => store.getAll());
  const put = entry => transaction('readwrite', store => store.put(entry));
  const remove = id => transaction('readwrite', store => store.delete(id));

  function setStatus(text, state = '') {
    if (!status) return;
    status.textContent = text;
    status.dataset.state = state;
  }
  async function refreshStatus() {
    const entries = await all();
    const failed = entries.filter(entry => entry.failed).length;
    setStatus(failed ? `⚠ ${failed} ändringar behöver försöka igen` : entries.length ? `⚠ Väntar på anslutning (${entries.length})` : draining ? '⟳ Sparar…' : '✓ Sparat', failed ? 'error' : entries.length ? 'waiting' : 'saved');
    return entries;
  }
  async function send(entry) {
    const body = new FormData();
    body.append('action', entry.action);
    body.append('nonce', config.nonce);
    body.append('inspection_id', config.inspection);
    Object.entries(entry.data || {}).forEach(([key, value]) => {
      if (key === 'details') Object.entries(value).forEach(([detailKey, detailValue]) => body.append(`details[${detailKey}]`, detailValue));
      else body.append(key, value);
    });
    if (entry.blob) body.append('photo', entry.blob, 'inspection.jpg');
    const response = await fetch(config.ajax, { method: 'POST', credentials: 'same-origin', body });
    const result = await response.json().catch(() => ({}));
    if (!response.ok || !result.success) {
      const error = new Error(result.data?.message || result.data?.[0]?.message || 'Servern kunde inte spara.');
      error.status = response.status;
      throw error;
    }
    return result.data;
  }
  async function enqueue(entry) {
    await put({ id: uuid(), created: Date.now(), ...entry });
    await refreshStatus();
    drain();
  }
  async function drain() {
    if (draining || !navigator.onLine) return;
    draining = true;
    setStatus('⟳ Sparar…', 'saving');
    try {
      const entries = (await all()).sort((a, b) => a.created - b.created);
      for (const entry of entries) {
        if (entry.failed) break;
        try {
          const result = await send(entry);
          await remove(entry.id);
          if (entry.action === 'ssf_membership_inspection_photo') finishPhoto(entry, result.photo);
        } catch (error) {
          if (error.status && error.status < 500) { entry.failed = error.message; await put(entry); }
          break;
        }
      }
    } finally { draining = false; await refreshStatus(); }
  }
  async function retry() {
    for (const entry of await all()) if (entry.failed) { delete entry.failed; await put(entry); }
    drain();
  }
  status?.addEventListener('click', retry);
  window.addEventListener('online', drain);
  window.addEventListener('beforeunload', event => { if (status?.dataset.state === 'waiting' || status?.dataset.state === 'saving') { event.preventDefault(); event.returnValue = ''; } });
  setInterval(drain, 8000);

  function revealComment(question) {
    const selected = question.querySelector('input[type="radio"]:checked')?.value || '';
    const required = JSON.parse(question.dataset.requiredComments || '[]').includes(selected);
    const label = question.querySelector('.ssf-inspector-comment');
    label?.classList.toggle('is-open', required || Boolean(label.querySelector('textarea')?.value));
    const marker = question.querySelector('[data-comment-required]');
    if (marker) marker.textContent = required ? 'Obligatorisk för detta svar' : '';
  }
  function queueAnswer(question) {
    const selected = question.querySelector('input[type="radio"]:checked')?.value;
    if (!selected) return Promise.resolve();
    updateProgress();
    return enqueue({ action: 'ssf_membership_inspection_save', data: { kind: 'answer', question_id: question.dataset.ssfQuestion, selected_option: selected, comment: question.querySelector('textarea')?.value || '' } });
  }
  document.querySelectorAll('[data-ssf-question][data-required-comments]').forEach(question => {
    revealComment(question);
    question.querySelectorAll('input[type="radio"]').forEach(input => input.addEventListener('change', () => { revealComment(question); queueAnswer(question); }));
    question.querySelector('textarea')?.addEventListener('input', () => { clearTimeout(timers.get(question)); timers.set(question, setTimeout(() => queueAnswer(question), 700)); });
    question.querySelector('.ssf-inspector-comment-toggle')?.addEventListener('click', event => {
      const label = question.querySelector('.ssf-inspector-comment');
      const open = !label.classList.contains('is-open'); label.classList.toggle('is-open', open); event.currentTarget.setAttribute('aria-expanded', String(open)); if (open) label.querySelector('textarea')?.focus();
    });
  });
  function updateProgress() {
    const questions = [...document.querySelectorAll('[data-ssf-question][data-required-comments]')];
    const complete = questions.filter(question => question.querySelector('input[type="radio"]:checked')).length;
    const label = document.querySelector('[data-ssf-progress-label]'); const progress = document.querySelector('[data-ssf-progress]');
    if (label) label.textContent = `${complete} av ${questions.length} klara`;
    if (progress) progress.value = questions.length ? Math.round(complete * 100 / questions.length) : 0;
  }

  function queueDetails() {
    const container = document.querySelector('[data-ssf-details]');
    if (!container) return;
    const details = Object.fromEntries([...container.querySelectorAll('input')].map(input => [input.name, input.value]));
    return enqueue({ action: 'ssf_membership_inspection_save', data: { kind: 'details', details, summary: document.querySelector('[data-ssf-summary]')?.value || '' } });
  }
  document.querySelectorAll('[data-ssf-details] input,[data-ssf-summary]').forEach(input => input.addEventListener('input', () => { clearTimeout(timers.get('details')); timers.set('details', setTimeout(queueDetails, 700)); }));

  async function optimize(file) {
    if (!/^image\/(jpeg|png|webp)$/.test(file.type) || file.size > 25 * 1024 * 1024) throw new Error('Välj en JPG-, PNG- eller WebP-bild under 25 MB.');
    const bitmap = typeof createImageBitmap === 'function' ? await createImageBitmap(file) : await new Promise((resolve, reject) => {
      const url = URL.createObjectURL(file); const image = new Image();
      image.onload = () => { URL.revokeObjectURL(url); resolve(image); }; image.onerror = () => { URL.revokeObjectURL(url); reject(new Error('Bilden kunde inte läsas.')); }; image.src = url;
    });
    const scale = Math.min(1, 1900 / Math.max(bitmap.width, bitmap.height));
    const canvas = document.createElement('canvas'); canvas.width = Math.round(bitmap.width * scale); canvas.height = Math.round(bitmap.height * scale); canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height); bitmap.close?.();
    return new Promise((resolve, reject) => canvas.toBlob(blob => blob ? resolve(blob) : reject(new Error('Bilden kunde inte behandlas.')), 'image/jpeg', .84));
  }
  document.querySelectorAll('.ssf-inspector-photo-input').forEach(input => input.addEventListener('change', async () => {
    const question = input.closest('[data-ssf-question]');
    for (const file of input.files || []) {
      try {
        const blob = await optimize(file); const operationId = uuid(); const figure = document.createElement('figure'); figure.dataset.pending = operationId;
        figure.innerHTML = '<img alt="Ny bild väntar på uppladdning"><figcaption><span>⟳ Väntar på uppladdning</span></figcaption>'; figure.querySelector('img').src = URL.createObjectURL(blob); question.querySelector('.ssf-inspector-photos').append(figure);
        await put({ id: uuid(), created: Date.now(), action: 'ssf_membership_inspection_photo', data: { question_id: question.dataset.ssfQuestion, caption: '', operation_id: operationId }, blob, pending: operationId }); await refreshStatus(); drain();
      } catch (error) { alert(error.message); }
    }
    input.value = '';
  }));
  function finishPhoto(entry, photo) {
    const figure = document.querySelector(`[data-pending="${entry.pending}"]`); if (!figure) return;
    figure.removeAttribute('data-pending'); figure.dataset.photoId = photo.id; figure.querySelector('img').src = photo.url;
    figure.querySelector('figcaption').innerHTML = '<input type="text" placeholder="Bildtext"><button type="button" data-delete-photo>Ta bort</button>';
  }
  document.addEventListener('change', event => {
    if (!event.target.matches('.ssf-inspector-photos input')) return;
    const figure = event.target.closest('figure'); enqueue({ action: 'ssf_membership_inspection_photo_update', data: { photo_id: figure.dataset.photoId, caption: event.target.value } });
  });
  document.addEventListener('click', event => {
    const button = event.target.closest('[data-delete-photo]'); if (!button || !confirm('Ta bort bilden från protokollet?')) return;
    const figure = button.closest('figure'); enqueue({ action: 'ssf_membership_inspection_photo_update', data: { photo_id: figure.dataset.photoId, delete: '1' } }); figure.remove();
  });

  document.querySelectorAll('[data-workflow]').forEach(button => button.addEventListener('click', async () => {
    if (button.dataset.workflow === 'confirm' && !document.querySelector('[data-co-confirm]')?.checked) { alert('Bekräfta först att du står bakom protokollet.'); return; }
    await Promise.all([...document.querySelectorAll('[data-ssf-question][data-required-comments]')].map(queueAnswer));
    await queueDetails();
    while (draining) await new Promise(resolve => setTimeout(resolve, 50));
    await drain();
    while (draining) await new Promise(resolve => setTimeout(resolve, 50));
    if ((await all()).length) { alert('Vänta tills alla ändringar och bilder har sparats.'); return; }
    button.disabled = true;
    try { await send({ action: 'ssf_membership_inspection_workflow', data: { workflow_action: button.dataset.workflow } }); location.reload(); }
    catch (error) { alert(error.message); button.disabled = false; }
  }));

  refreshStatus().then(drain).catch(() => setStatus('⚠ Lokal lagring är inte tillgänglig', 'error'));
})();
