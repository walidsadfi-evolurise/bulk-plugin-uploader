jQuery(function($){
    const dropzone    = $('#dropzone'),
          fileInput   = $('#plugin_zips'),
          results     = $('#upload-results'),
          btnActivate = $('#btn-install-activate'),
          btnInstall  = $('#btn-install-only'),
          btnActivateAll = $('#btn-activate-only');
  
    // Toaster CSS injected
    const toastCSS = `
      .toast { position:fixed; top:24px; right:24px; background:white; border-left:4px solid #43a047; padding:12px 16px;
        box-shadow:0 2px 12px rgba(0,0,0,0.1); border-radius:8px; opacity:0; transform:translateY(-20px);
        transition:opacity .3s, transform .3s; z-index:9999; }
      .toast.error { border-color:#e53935; }
      .toast.show { opacity:1; transform:translateY(0); }
    `;
    $('<style>').text(toastCSS).appendTo('head');
  
    function showToast(msg, type='success'){
      const t=$('<div>').addClass('toast').addClass(type).text(msg).appendTo('body');
      setTimeout(()=>t.addClass('show'),10);
      setTimeout(()=>{ t.removeClass('show'); setTimeout(()=>t.remove(),300); },3000);
    }

    function updateStatus(row, message, isSuccess) {
        const statusCell = row.find('.status-text');
        statusCell
            .removeClass('status-success status-error')
            .addClass(isSuccess ? 'status-success' : 'status-error')
            .text(message);
    }
  
    // drag & drop + click
    dropzone.on('click',()=>fileInput.get(0).click())
            .on('dragover',e=>{e.preventDefault();dropzone.addClass('dragover');})
            .on('dragleave',e=>{e.preventDefault();dropzone.removeClass('dragover');})
            .on('drop',e=>{e.preventDefault(); dropzone.removeClass('dragover');
              fileInput.get(0).files = e.originalEvent.dataTransfer.files; fileInput.trigger('change');
            });
  
    // build table
    fileInput.on('change',function(){
      const files=this.files; results.empty();
      if(!files.length) return;
      const table=$(`<table class="upload-table"><thead><tr>
        <th>Fichier</th><th>Upload</th><th>Installation</th><th>Statut</th>
      </tr></thead><tbody></tbody></table>`);
      $.each(files,(i,f)=>{
        const row=$(
          `<tr data-index="${i}">`+
          `<td>${f.name}</td>`+
          `<td><progress class="upload-bar" max="100" value="0"></progress><span class="upload-text">0%</span></td>`+
          `<td><progress class="install-bar" max="100" value="0" style="display:none;"></progress><span class="install-text" style="display:none;">0%</span></td>`+
          `<td class="status-text">Prêt</td>`+
          `</tr>`
        ); table.find('tbody').append(row);
      }); results.append(table);
    });
  
    // sequential processing
    function processFiles(installOnly){
      const files=fileInput.get(0).files; if(!files.length) return alert('Sélectionnez un ZIP');
      let seq=Promise.resolve();
      $.each(files,(i,f)=> seq=seq.then(()=>uploadOne(f,installOnly,i)));
    }
  
    function uploadOne(file, installOnly, idx) {
        return new Promise(resolve => {
            const row = results.find(`tr[data-index="${idx}"]`);
            const status = row.find('.status-text');
            const uploadBar = row.find('.upload-bar');
            const uploadText = row.find('.upload-text');
            const installBar = row.find('.install-bar');
            const installText = row.find('.install-text');
            const data = new FormData();
            
            data.append('plugin_zips[]', file);
            data.append('action', 'bulk_plugin_upload');
            data.append('security', BulkPluginUpload.nonce);
            data.append('install_only', installOnly ? '1' : '0');

            status.text('Téléversement…').removeClass('status-success status-error');
            installBar.show();
            installText.show();
            
            $.ajax({
                url: BulkPluginUpload.ajax_url,
                type: 'POST',
                dataType: 'json',
                data: data,
                processData: false,
                contentType: false,
                timeout: 300000, // 5 minutes timeout
                xhr() {
                    const xhr = $.ajaxSettings.xhr();
                    if (xhr.upload) {
                        xhr.upload.addEventListener('progress', e => {
                            if (e.lengthComputable) {
                                const p = Math.round(e.loaded / e.total * 100);
                                uploadBar.val(p);
                                uploadText.text(p + '%');
                            }
                        });
                    }
                    return xhr;
                },
                success(resp) {
                    clearInterval(row.data('iv'));
                    installBar.val(100);
                    installText.text('100%');
                    if (!resp.success) {
                        updateStatus(row, 'Erreur : ' + resp.data, false);
                        row.addClass('error').removeClass('success');
                        showToast(resp.data, 'error');
                    } else {
                        const it = resp.data[0];
                        updateStatus(row, it.message, true);
                        row.addClass('success').removeClass('error');
                        showToast(it.message, 'success');
                    }
                    resolve();
                },
                error(xhr, s, e) {
                    clearInterval(row.data('iv'));
                    status.addClass('status-error').text('Erreur : ' + (e || 'Erreur réseau'));
                    row.addClass('error').removeClass('success');
                    showToast('Erreur Ajax', 'error');
                    resolve();
                }
            });
        });
    }
  
    btnActivate.on('click',()=>processFiles(false));
    btnInstall .on('click',()=>processFiles(true));
    btnActivateAll.on('click',function(){
      showToast('Activation des modules…','success');
      $.post(BulkPluginUpload.ajax_url,{ action:'bulk_plugin_activate', security:BulkPluginUpload.nonce })
       .done(resp=>{
         if(!resp.success){ showToast(resp.data,'error'); return; }
         $.each(resp.data,(slug,info)=>{
           const msg=`${slug} → ${info.msg}`;
           showToast(msg, info.status==='OK'?'success':'error');
         });
         setTimeout(()=>location.reload(),1200);
       }).fail(xhr=>showToast('error activation','error'));
    });
  });