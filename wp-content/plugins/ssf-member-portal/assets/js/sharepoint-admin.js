(function () {
  'use strict';

  var root = document.querySelector('[data-ssf-sharepoint-admin]');
  if (!root || typeof ssfSharePointAdmin === 'undefined') return;
  var form = root.matches('.ssf-sp-wizard') ? root : root.querySelector('.ssf-sp-wizard');
  if (!form) return;

  function escapeHtml(value) {
    var node = document.createElement('div');
    node.textContent = value == null ? '' : String(value);
    return node.innerHTML;
  }

  function field(name) {
    return form.querySelector('[data-sp-field="' + name + '"]');
  }

  function setField(name, value) {
    var input = field(name);
    if (input && value != null) input.value = value;
  }

  function profile() {
    var values = { metadata: {} };
    form.querySelectorAll('[data-sp-field]').forEach(function (input) { values[input.dataset.spField] = input.value.trim(); });
    form.querySelectorAll('[data-sp-metadata]').forEach(function (select) { values.metadata[select.dataset.spMetadata] = select.value; });
    return values;
  }

  function resultBox(name) {
    return root.querySelector('[data-sp-result="' + name + '"]');
  }

  function show(name, html, state) {
    var box = resultBox(name);
    if (!box) return;
    box.className = 'ssf-sp-result is-visible is-' + (state || 'info');
    box.innerHTML = html;
  }

  function request(operation, extras, button) {
    var data = new FormData();
    data.append('action', 'ssf_sharepoint_admin');
    data.append('nonce', ssfSharePointAdmin.nonce);
    data.append('operation', operation);
    data.append('destination', root.dataset.destination);
    data.append('environment', root.dataset.environment);
    data.append('profile', JSON.stringify(profile()));
    Object.keys(extras || {}).forEach(function (key) { data.append(key, extras[key]); });
    if (button) button.disabled = true;
    return fetch(ssfSharePointAdmin.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data })
      .then(function (response) { return response.json(); })
      .then(function (response) {
        if (!response.success) throw response.data || { message: 'Åtgärden misslyckades.' };
        return response.data;
      })
      .finally(function () { if (button) button.disabled = false; });
  }

  function siteResult(data) {
    setField('site_id', data.id || '');
    setField('site_name', data.displayName || data.name || '');
    setField('site_url', data.webUrl || field('site_url').value);
    if (data.webUrl) {
      try { var url = new URL(data.webUrl); setField('hostname', url.hostname); setField('site_path', url.pathname); } catch (ignore) {}
    }
    show('site', '<strong>SharePoint-site hittad</strong><dl><div><dt>Namn</dt><dd>' + escapeHtml(data.displayName || data.name) + '</dd></div><div><dt>Site ID</dt><dd><code>' + escapeHtml(data.id) + '</code></dd></div></dl>', 'success');
  }

  function drivesResult(drives) {
    if (!drives.length) { show('drives', 'Inga dokumentbibliotek hittades.', 'warning'); return; }
    var options = drives.map(function (drive) { return '<option value="' + escapeHtml(drive.id) + '" data-name="' + escapeHtml(drive.name) + '" data-url="' + escapeHtml(drive.webUrl || '') + '">' + escapeHtml(drive.name) + ' (' + escapeHtml(drive.driveType) + ')</option>'; }).join('');
    show('drives', '<label>Välj bibliotek<select data-sp-drive-select>' + options + '</select></label> <button type="button" class="button button-primary" data-sp-use-drive>Använd bibliotek</button>', 'success');
  }

  function driveResult(data) {
    setField('drive_id', data.id || ''); setField('drive_name', data.name || ''); setField('drive_web_url', data.webUrl || '');
    if (data.list_id) setField('list_id', data.list_id);
    setField('drive_root_id', data.root_id || '');
    setField('folder_id', ''); setField('folder_name', ''); setField('folder_path', ''); setField('folder_web_url', ''); setField('is_drive_root', '0'); setField('direct_to_root', '0');
    var isMigration = root.dataset.destination === 'folder_migration';
    var rootLabel = root.dataset.locationKind === 'target' ? 'Använd bibliotekets rot som slutmål' : 'Använd bibliotekets rot som källa';
    var rootHelp = root.dataset.locationKind === 'target' ? 'Innehållet kopieras direkt till biblioteket utan en extra mapp med samma namn.' : 'Hela dokumentbibliotekets innehåll blir källa.';
    var rootAction = data.root_id && isMigration ? '<p><button type="button" class="button button-primary" data-sp-use-drive-root data-id="' + escapeHtml(data.root_id) + '" data-name="' + escapeHtml(data.root_name || data.name) + '" data-url="' + escapeHtml(data.root_web_url || data.webUrl || '') + '">' + rootLabel + '</button></p><p class="description">' + rootHelp + '</p>' : '';
    show('drives', '<strong>Dokumentbibliotek valt</strong><p>' + escapeHtml(data.name) + '</p><p>List ID: <code>' + escapeHtml(data.list_id || 'kunde inte identifieras') + '</code></p>' + rootAction, data.list_id && data.root_id ? 'success' : 'warning');
  }

  function foldersResult(folders, parentPath) {
    if (!folders.length) { show('folders', '<p>Inga undermappar hittades här.</p>' + useCurrentFolder(parentPath), 'warning'); return; }
    var rows = folders.map(function (folder) {
      return '<li><span><strong>' + escapeHtml(folder.name) + '</strong><small>' + escapeHtml(folder.path) + '</small></span><span><button type="button" class="button" data-sp-open-folder data-id="' + escapeHtml(folder.id) + '" data-path="' + escapeHtml(folder.path) + '">Öppna</button> <button type="button" class="button" data-sp-use-folder data-id="' + escapeHtml(folder.id) + '" data-name="' + escapeHtml(folder.name) + '" data-path="' + escapeHtml(folder.path) + '" data-url="' + escapeHtml(folder.web_url || '') + '">Använd</button></span></li>';
    }).join('');
    show('folders', '<p><strong>Aktuell mapp:</strong> ' + escapeHtml(parentPath || 'Dokumentbibliotekets rot') + '</p><ul class="ssf-sp-folder-list">' + rows + '</ul>', 'success');
  }

  function useCurrentFolder(path) {
    return path ? '<p>Använd sökvägsfältet ovan och välj <strong>Hitta mapp</strong> för att välja denna mapp.</p>' : '';
  }

  function folderResult(data) {
    setField('folder_id', data.id || ''); setField('folder_name', data.name || ''); setField('folder_path', data.path || ''); setField('folder_web_url', data.web_url || '');
    setField('is_drive_root', data.is_drive_root ? '1' : '0'); setField('direct_to_root', data.direct_to_root ? '1' : '0');
    show('folders', '<strong>Mapp vald</strong><p>' + escapeHtml(data.path || data.name) + '</p><p>Folder ID: <code>' + escapeHtml(data.id) + '</code></p>', 'success');
  }

  function columnsResult(columns) {
    form.querySelectorAll('[data-sp-metadata]').forEach(function (select) {
      var current = select.value;
      var known = columns.some(function (column) { return column.name === current; });
      var preserved = current && !known ? '<option value="' + escapeHtml(current) + '">' + escapeHtml(current + ' (hittades inte)') + '</option>' : '';
      select.innerHTML = '<option value="">Ej valt</option>' + preserved + columns.map(function (column) { return '<option value="' + escapeHtml(column.name) + '">' + escapeHtml(column.display_name + ' (' + column.name + ', ' + column.type + ')') + '</option>'; }).join('');
      select.value = current;
    });
    var rows = columns.map(function (column) { return '<tr><td>' + escapeHtml(column.display_name) + '</td><td><code>' + escapeHtml(column.name) + '</code></td><td>' + escapeHtml(column.type) + '</td><td>' + escapeHtml((column.choices || []).join(', ')) + '</td></tr>'; }).join('');
    var statusSelect = form.querySelector('[data-sp-metadata="status"]');
    var statusColumn = statusSelect ? columns.find(function (column) { return column.name === statusSelect.value; }) : null;
    var statusCheck = statusColumn ? '<p><strong>Status:</strong> hittad, typ ' + escapeHtml(statusColumn.type) + (statusColumn.choices.length ? ', val: ' + escapeHtml(statusColumn.choices.join(', ')) : '') + '</p>' : '<p><strong>Status:</strong> den valda kolumnen hittades inte.</p>';
    show('columns', statusCheck + '<table class="widefat striped"><thead><tr><th>Visningsnamn</th><th>Internt namn</th><th>Typ</th><th>Val</th></tr></thead><tbody>' + rows + '</tbody></table>', statusColumn ? 'success' : 'warning');
  }

  function testResult(data) {
    if (data.write !== undefined) {
      show('test', '<dl><div><dt>Skrivning</dt><dd>' + (data.write ? 'OK' : 'Fel') + '</dd></div><div><dt>Städning</dt><dd>' + (data.cleanup ? 'OK' : 'Fel') + '</dd></div></dl>' + (data.message ? '<p>' + escapeHtml(data.message) + '</p>' : ''), data.ok ? 'success' : 'warning');
      return;
    }
    var rows = Object.keys(data.steps || {}).map(function (key) { var step = data.steps[key]; return '<div><dt>' + escapeHtml(step.label || key) + '</dt><dd>' + (step.ok ? 'OK' : escapeHtml(step.message || 'Fel')) + '</dd></div>'; }).join('');
    show('test', '<dl>' + rows + '</dl>' + permissionInstructions(data.error), data.ok ? 'success' : 'warning');
    if (data.list_id) setField('list_id', data.list_id);
  }

  function permissionInstructions(error) {
    var guide = error && error.sites_selected;
    if (!guide) return '';
    var lookup = guide.site_lookup_endpoint ? '<p><strong>Hitta först Site ID</strong><br><code>' + escapeHtml(guide.site_lookup_endpoint) + '</code></p>' : '';
    return '<details class="ssf-sp-permission"><summary>Visa Graph Explorer-instruktion</summary><p>' + escapeHtml(guide.message) + '</p>' + lookup + '<p><strong>GET kontroll</strong><br><code>' + escapeHtml(guide.get_endpoint) + '</code></p><p><strong>POST grant</strong><br><code>' + escapeHtml(guide.post_endpoint) + '</code></p><pre>' + escapeHtml(guide.request_body) + '</pre><button type="button" class="button" data-sp-copy="' + escapeHtml(guide.post_endpoint) + '">Kopiera endpoint</button> <button type="button" class="button" data-sp-copy="' + escapeHtml(guide.request_body) + '">Kopiera request body</button></details>';
  }

  function showError(target, error) {
    show(target, '<strong>Kontrollen misslyckades</strong><p>' + escapeHtml(error.message || 'Okänt fel.') + '</p>' + permissionInstructions(error) + '<details><summary>Teknisk information</summary><pre>' + escapeHtml(JSON.stringify({ http_status: error.http_status || 0, graph_code: error.graph_code || '', message: error.technical_message || '' }, null, 2)) + '</pre></details>', 'error');
  }

  root.addEventListener('click', function (event) {
    var operationButton = event.target.closest('[data-sp-operation]');
    if (operationButton) {
      event.preventDefault();
      var operation = operationButton.dataset.spOperation;
      var target = ['site'].indexOf(operation) >= 0 ? 'site' : (['drives', 'drive'].indexOf(operation) >= 0 ? 'drives' : (['folders', 'folder_path'].indexOf(operation) >= 0 ? 'folders' : (operation === 'columns' ? 'columns' : 'test')));
      show(target, 'Kontrollerar Microsoft Graph...', 'info');
      request(operation, { parent_id: operationButton.dataset.parentId || '', parent_path: operationButton.dataset.parentPath || '' }, operationButton).then(function (data) {
        if (operation === 'site') siteResult(data);
        if (operation === 'drives') drivesResult(data);
        if (operation === 'folders') foldersResult(data, operationButton.dataset.parentPath || '');
        if (operation === 'folder_path') folderResult(data);
        if (operation === 'columns') columnsResult(data);
        if (operation === 'diagnostics' || operation === 'write_test') testResult(data);
      }).catch(function (error) { showError(target, error); });
      return;
    }
    var useDrive = event.target.closest('[data-sp-use-drive]');
    if (useDrive) {
      var select = root.querySelector('[data-sp-drive-select]');
      var option = select && select.options[select.selectedIndex];
      if (!option) return;
      setField('drive_id', option.value); setField('drive_name', option.dataset.name || ''); setField('drive_web_url', option.dataset.url || '');
      request('drive', {}, useDrive).then(driveResult).catch(function (error) { showError('drives', error); });
      return;
    }
    var openFolder = event.target.closest('[data-sp-open-folder]');
    if (openFolder) {
      request('folders', { parent_id: openFolder.dataset.id, parent_path: openFolder.dataset.path }, openFolder).then(function (data) { foldersResult(data, openFolder.dataset.path); }).catch(function (error) { showError('folders', error); });
      return;
    }
    var useDriveRoot = event.target.closest('[data-sp-use-drive-root]');
    if (useDriveRoot) {
      folderResult({ id: useDriveRoot.dataset.id, name: useDriveRoot.dataset.name, path: '', web_url: useDriveRoot.dataset.url, is_drive_root: true, direct_to_root: root.dataset.locationKind === 'target' });
      return;
    }
    var useFolder = event.target.closest('[data-sp-use-folder]');
    if (useFolder) {
      folderResult({ id: useFolder.dataset.id, name: useFolder.dataset.name, path: useFolder.dataset.path, web_url: useFolder.dataset.url });
      return;
    }
    var copy = event.target.closest('[data-sp-copy]');
    if (copy && navigator.clipboard) navigator.clipboard.writeText(copy.dataset.spCopy);
    var copyField = event.target.closest('[data-sp-copy-field]');
    if (copyField && navigator.clipboard) navigator.clipboard.writeText(field(copyField.dataset.spCopyField).value);
  });
}());
