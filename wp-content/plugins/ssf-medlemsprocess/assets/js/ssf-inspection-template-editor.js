(function () {
    'use strict';
    var form = document.getElementById('ssf-template-editor');
    if (!form) return;

    var json = document.getElementById('ssf-template-blocks-json');
    var canvas = document.getElementById('ssf-template-canvas');
    var settings = document.getElementById('ssf-block-settings');
    var preview = document.getElementById('ssf-template-preview');
    var readonly = form.dataset.readonly === '1';
    var blocks = [];
    var selected = '';
    var dirty = false;
    var fields = (window.ssfInspectionTemplateEditor || {}).fields || {};
    try { blocks = JSON.parse(json.value || '[]'); } catch (ignore) { blocks = []; }

    function esc(value) {
        var node = document.createElement('div'); node.textContent = value == null ? '' : String(value); return node.innerHTML;
    }
    function uid(prefix) {
        var random = window.crypto && window.crypto.getRandomValues ? window.crypto.getRandomValues(new Uint32Array(1))[0].toString(36) : Math.random().toString(36).slice(2);
        return prefix + '-' + Date.now().toString(36) + '-' + random;
    }
    function label(type) {
        return {section:'Avsnitt',info:'Information',question:'Fråga',application_context:'Ansökningsdata',textarea:'Textfält',photo:'Foto',summary:'Sammanfattning',recommendation:'Rekommendation',confirmation:'Bekräftelse'}[type] || type;
    }
    function markDirty() {
        if (readonly) return;
        dirty = true; json.value = JSON.stringify(blocks);
        var state = form.querySelector('[data-save-state]'); if (state) state.textContent = 'Osparade ändringar';
    }
    function newBlock(type) {
        var block = {id:uid(type), type:type, title:label(type)};
        if (type === 'section') { block.step = blocks.filter(function (item) { return item.type === 'section'; }).length + 1; }
        if (type === 'question') { block.question_id = uid('q'); block.title = 'Ny fråga'; block.help = ''; block.required = true; block.options = [{id:uid('option'),label:'Ja'},{id:uid('option'),label:'Nej'}]; block.context_fields=[]; block.comment_rule='optional'; block.comment_option_ids=[]; block.photo_rule='allowed'; block.photo_option_ids=[]; }
        if (type === 'application_context') block.fields = ['ship_name','applicant_name','registry_number'];
        if (type === 'photo') block.photo_rule = 'allowed';
        if (['info','textarea','summary','recommendation','confirmation'].indexOf(type) !== -1) { block.content=''; block.required=false; }
        return block;
    }
    function renderCanvas() {
        if (!blocks.length) { canvas.innerHTML = '<div class="ssf-template-empty">Mallen är tom. Lägg till ett avsnitt och därefter frågor.</div>'; }
        else canvas.innerHTML = blocks.map(function (block, index) {
            var detail = block.type === 'question' ? ((block.question_id || '') + ' · ' + ((block.options || []).length) + ' svar') : (block.content || '');
            return '<div class="ssf-canvas-insert"><button type="button" data-insert="'+index+'" title="Lägg till block här" '+(readonly?'disabled':'')+'>+</button></div>'+
                '<article class="ssf-canvas-block '+(selected===block.id?'is-selected':'')+' type-'+esc(block.type)+'" data-select="'+esc(block.id)+'">'+
                '<div><span>'+esc(label(block.type))+'</span><strong>'+esc(block.title || 'Namnlöst block')+'</strong><small>'+esc(detail)+'</small></div>'+
                '<div class="ssf-canvas-actions"><button type="button" data-move="up" data-id="'+esc(block.id)+'" '+(readonly||index===0?'disabled':'')+' aria-label="Flytta upp">↑</button><button type="button" data-move="down" data-id="'+esc(block.id)+'" '+(readonly||index===blocks.length-1?'disabled':'')+' aria-label="Flytta ned">↓</button><button type="button" data-duplicate="'+esc(block.id)+'" '+(readonly?'disabled':'')+'>Duplicera</button><button type="button" data-delete="'+esc(block.id)+'" '+(readonly?'disabled':'')+'>Ta bort</button></div></article>';
        }).join('') + (blocks.length ? '<div class="ssf-canvas-insert"><button type="button" data-insert="'+blocks.length+'" title="Lägg till block sist" '+(readonly?'disabled':'')+'>+</button></div>' : '');
        renderPreview();
    }
    function renderSettings() {
        var block = blocks.find(function (item) { return item.id === selected; });
        if (!block) { settings.innerHTML='<h2>Blockinställningar</h2><p>Välj ett block.</p>'; return; }
        var disabled = readonly ? ' disabled' : '';
        var html='<h2>'+esc(label(block.type))+'</h2><p><code>'+esc(block.id)+'</code></p><label>Rubrik<input data-prop="title" value="'+esc(block.title||'')+'"'+disabled+'></label>';
        if (block.type === 'section') html += '<label>Steg<input type="number" min="1" data-prop="step" value="'+esc(block.step||1)+'"'+disabled+'></label>';
        if (['info','textarea','summary','recommendation','confirmation'].indexOf(block.type)!==-1) html += '<label>Text<textarea rows="5" data-prop="content"'+disabled+'>'+esc(block.content||'')+'</textarea></label><label><input type="checkbox" data-prop="required" '+(block.required?'checked':'')+disabled+'> Obligatoriskt</label>';
        if (block.type === 'application_context') html += '<fieldset><legend>Ansökningsfält (skrivskyddade)</legend>'+Object.keys(fields).map(function(key){return '<label><input type="checkbox" data-array="fields" value="'+esc(key)+'" '+((block.fields||[]).indexOf(key)!==-1?'checked':'')+disabled+'> '+esc(fields[key])+'</label>';}).join('')+'</fieldset>';
        if (block.type === 'photo') html += select('photo_rule','Fotoregel',block.photo_rule||'allowed',{allowed:'Tillåtet',recommended:'Rekommenderat',required:'Obligatoriskt'},disabled);
        if (block.type === 'question') {
            html += '<label>Fråge-ID<input data-prop="question_id" value="'+esc(block.question_id||'')+'"'+disabled+'></label><label>Hjälptext<textarea rows="3" data-prop="help"'+disabled+'>'+esc(block.help||'')+'</textarea></label><label><input type="checkbox" data-prop="required" '+(block.required?'checked':'')+disabled+'> Svar krävs</label>';
            html += '<label>Svarsalternativ <small>stabilt ID | etikett, en rad per svar</small><textarea rows="8" data-options'+disabled+'>'+esc((block.options||[]).map(function(o){return o.id+' | '+o.label;}).join('\n'))+'</textarea></label>';
            html += select('comment_rule','Kommentar',block.comment_rule||'optional',{none:'Ingen',optional:'Valfri',always:'Alltid obligatorisk',selected:'Obligatorisk för valda svar'},disabled);
            if (block.comment_rule === 'selected') html += optionChecks(block,'comment_option_ids','Kommentar krävs för');
            html += select('photo_rule','Foto',block.photo_rule||'allowed',{none:'Inte tillåtet',allowed:'Tillåtet',recommended:'Rekommenderat',required:'Obligatoriskt',selected:'Obligatoriskt för valda svar'},disabled);
            if (block.photo_rule === 'selected') html += optionChecks(block,'photo_option_ids','Foto krävs för');
            html += '<fieldset><legend>Ansökningskontext</legend>'+Object.keys(fields).map(function(key){return '<label><input type="checkbox" data-array="context_fields" value="'+esc(key)+'" '+((block.context_fields||[]).indexOf(key)!==-1?'checked':'')+disabled+'> '+esc(fields[key])+'</label>';}).join('')+'</fieldset>';
        }
        settings.innerHTML=html;
    }
    function select(prop, title, value, options, disabled) {
        return '<label>'+title+'<select data-prop="'+prop+'"'+disabled+'>'+Object.keys(options).map(function(key){return '<option value="'+key+'" '+(key===value?'selected':'')+'>'+esc(options[key])+'</option>';}).join('')+'</select></label>';
    }
    function optionChecks(block, prop, title) {
        return '<fieldset><legend>'+title+'</legend>'+(block.options||[]).map(function(option){return '<label><input type="checkbox" data-array="'+prop+'" value="'+esc(option.id)+'" '+((block[prop]||[]).indexOf(option.id)!==-1?'checked':'')+(readonly?' disabled':'')+'> '+esc(option.label)+'</label>';}).join('')+'</fieldset>';
    }
    function renderPreview() {
        var applicationSelect = document.getElementById('ssf-preview-application');
        var snapshot = {};
        if (applicationSelect && applicationSelect.selectedOptions[0]) { try { snapshot = JSON.parse(applicationSelect.selectedOptions[0].dataset.snapshot || '{}'); } catch (ignore) { snapshot = {}; } }
        preview.innerHTML = blocks.map(function(block){
            if (block.type==='section') return '<section class="ssf-preview-section"><h2>'+esc(block.title)+'</h2></section>';
            if (block.type==='info') return '<aside class="ssf-preview-info"><h3>'+esc(block.title)+'</h3><p>'+esc(block.content||'')+'</p></aside>';
            if (block.type==='application_context') return '<aside class="ssf-preview-context"><strong>'+esc(block.title)+'</strong><dl>'+(block.fields||[]).map(function(key){return '<div><dt>'+esc(fields[key]||key)+'</dt><dd>'+esc(snapshot[key] || 'Ej angivet')+'</dd></div>';}).join('')+'</dl></aside>';
            if (block.type==='question') return '<article class="ssf-preview-question"><small>Fråga '+esc(block.question_id||'')+'</small><h3>'+esc(block.title)+'</h3>'+(block.help?'<p>'+esc(block.help)+'</p>':'')+((block.context_fields||[]).length?'<aside class="ssf-preview-context"><strong>Sökanden uppgav</strong><dl>'+block.context_fields.map(function(key){return '<div><dt>'+esc(fields[key]||key)+'</dt><dd>'+esc(snapshot[key] || 'Ej angivet')+'</dd></div>';}).join('')+'</dl></aside>':'')+'<div>'+(block.options||[]).map(function(option){return '<label><input type="radio" disabled> '+esc(option.label)+'</label>';}).join('')+'</div>'+(block.comment_rule!=='none'?'<textarea disabled placeholder="Kommentar"></textarea>':'')+(block.photo_rule!=='none'?'<button class="button" disabled>Välj bild</button>':'')+'</article>';
            return '<section class="ssf-preview-generic"><h3>'+esc(block.title)+'</h3><p>'+esc(block.content||'')+'</p></section>';
        }).join('');
    }
    canvas.addEventListener('click', function(event){
        var selectNode=event.target.closest('[data-select]'); if(selectNode && !event.target.closest('button')) { selected=selectNode.dataset.select; renderCanvas(); renderSettings(); return; }
        var move=event.target.closest('[data-move]'); if(move){var index=blocks.findIndex(function(b){return b.id===move.dataset.id;});var target=move.dataset.move==='up'?index-1:index+1;if(target>=0&&target<blocks.length){var item=blocks.splice(index,1)[0];blocks.splice(target,0,item);markDirty();renderCanvas();}return;}
        var duplicate=event.target.closest('[data-duplicate]'); if(duplicate){var i=blocks.findIndex(function(b){return b.id===duplicate.dataset.duplicate;});var copy=JSON.parse(JSON.stringify(blocks[i]));copy.id=uid(copy.type);if(copy.type==='question'){copy.question_id=uid('q');copy.options=(copy.options||[]).map(function(o){return {id:uid('option'),label:o.label};});copy.comment_option_ids=[];copy.photo_option_ids=[];}blocks.splice(i+1,0,copy);selected=copy.id;markDirty();renderCanvas();renderSettings();return;}
        var remove=event.target.closest('[data-delete]'); if(remove && window.confirm('Ta bort blocket?')){blocks=blocks.filter(function(b){return b.id!==remove.dataset.delete;});if(selected===remove.dataset.delete)selected='';markDirty();renderCanvas();renderSettings();return;}
        var insert=event.target.closest('[data-insert]'); if(insert){var type=window.prompt('Blocktyp: section, info, question, application_context, textarea, photo, summary, recommendation eller confirmation','info');if(type&&['section','info','question','application_context','textarea','photo','summary','recommendation','confirmation'].indexOf(type)!==-1){var block=newBlock(type);blocks.splice(parseInt(insert.dataset.insert,10),0,block);selected=block.id;markDirty();renderCanvas();renderSettings();}}
    });
    document.querySelectorAll('[data-add-block]').forEach(function(button){button.addEventListener('click',function(){var block=newBlock(button.dataset.addBlock);blocks.push(block);selected=block.id;markDirty();renderCanvas();renderSettings();});});
    settings.addEventListener('input', function(event){
        var block=blocks.find(function(item){return item.id===selected;}); if(!block||readonly)return;
        if(event.target.matches('[data-prop]')){var prop=event.target.dataset.prop;block[prop]=event.target.type==='checkbox'?event.target.checked:(event.target.type==='number'?parseInt(event.target.value,10):event.target.value);}
        if(event.target.matches('[data-array]')){var arrayProp=event.target.dataset.array;block[arrayProp]=Array.from(settings.querySelectorAll('[data-array="'+arrayProp+'"]:checked')).map(function(input){return input.value;});}
        if(event.target.matches('[data-options]')){block.options=event.target.value.split(/\r?\n/).map(function(line){var parts=line.split('|');return {id:(parts.shift()||'').trim(),label:parts.join('|').trim()};}).filter(function(option){return option.id&&option.label;});}
        markDirty(); renderCanvas();
    });
    settings.addEventListener('change', function(event){ if(event.target.matches('select[data-prop]')) renderSettings(); });
    form.addEventListener('input', function(event){if(!event.target.closest('#ssf-block-settings'))markDirty();});
    form.addEventListener('submit', function(){json.value=JSON.stringify(blocks);dirty=false;});
    window.addEventListener('beforeunload', function(event){if(dirty){event.preventDefault();event.returnValue='';}});
    var viewport=document.getElementById('ssf-preview-viewport'); if(viewport) viewport.addEventListener('change',function(){preview.dataset.viewport=viewport.value;});
    var previewApplication=document.getElementById('ssf-preview-application'); if(previewApplication) previewApplication.addEventListener('change',renderPreview);
    renderCanvas(); renderSettings();
}());
