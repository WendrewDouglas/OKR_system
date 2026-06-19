<?php
/**
 * views/partials/avatar_uploader.php
 * ------------------------------------------------------------------
 * Componente reutilizável de UPLOAD + RECORTE de avatar (Cropper.js).
 * Envia a imagem recortada (quadrada) para auth/avatar_save.php.
 *
 * Fase 3: criado e disponível, porém AINDA NÃO incluído em telas
 * (será plugado no perfil/cadastro na Fase 4, junto do render swap).
 *
 * Uso (host screen):
 *   include __DIR__ . '/partials/avatar_uploader.php';
 *   // botão de abrir: qualquer elemento com [data-avatar-upload-open]
 *   // alvo de preview (opcional): <img data-avatar-target> recebe a nova URL
 *
 * Evento: dispara CustomEvent('avatar:updated', {detail:{id,url,url_thumb}}).
 * ------------------------------------------------------------------
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$auCsrf = (string) $_SESSION['csrf_token'];

if (!defined('AVATAR_UPLOADER_EMITTED')) {
    define('AVATAR_UPLOADER_EMITTED', true);
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.2/cropper.min.css" crossorigin="anonymous">
<script defer src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.2/cropper.min.js" crossorigin="anonymous"></script>
<style>
.au-backdrop{ position:fixed; inset:0; background:rgba(0,0,0,.6); display:none; align-items:center; justify-content:center; z-index:3000; }
.au-backdrop.open{ display:flex; }
.au-modal{ background:var(--card,#0e1319); color:var(--bg1-contrast,#fff); border:1px solid #1f2a3a; border-radius:14px; width:min(440px,94vw); overflow:hidden; }
.au-head{ display:flex; align-items:center; justify-content:space-between; padding:12px 16px; border-bottom:1px solid #1f2a3a; font-weight:600; }
.au-body{ padding:14px 16px; }
.au-stage{ background:#0b0f15; border-radius:10px; max-height:60vh; }
.au-stage img{ max-width:100%; display:block; }
.au-tools{ display:flex; gap:8px; margin-top:12px; flex-wrap:wrap; }
.au-tools button{ background:#1b222b; color:inherit; border:1px solid #2a3340; border-radius:8px; padding:6px 10px; cursor:pointer; font-size:.85rem; }
.au-foot{ display:flex; gap:10px; justify-content:flex-end; padding:12px 16px; border-top:1px solid #1f2a3a; }
.au-btn{ border-radius:8px; padding:8px 16px; cursor:pointer; border:1px solid transparent; font-weight:600; }
.au-btn.primary{ background:var(--bg2,#6c5ce7); color:var(--bg2-contrast,#fff); }
.au-btn.ghost{ background:transparent; color:inherit; border-color:#2a3340; }
.au-btn[disabled]{ opacity:.6; cursor:not-allowed; }
.au-msg{ font-size:.82rem; color:#f87171; margin-top:8px; min-height:1em; }
</style>

<input type="file" id="auFile" accept="image/png,image/jpeg,image/webp" hidden>
<div class="au-backdrop" id="auBackdrop" aria-hidden="true">
  <div class="au-modal" role="dialog" aria-modal="true" aria-label="Recortar avatar">
    <div class="au-head"><span>Recortar foto</span><button type="button" class="au-btn ghost" id="auCancel">Fechar ✕</button></div>
    <div class="au-body">
      <div class="au-stage"><img id="auImg" alt=""></div>
      <div class="au-tools">
        <button type="button" id="auZoomIn" title="Aproximar"><i class="fas fa-search-plus"></i> +</button>
        <button type="button" id="auZoomOut" title="Afastar"><i class="fas fa-search-minus"></i> −</button>
        <button type="button" id="auRotL" title="Girar"><i class="fas fa-rotate-left"></i> ⟲</button>
        <button type="button" id="auRotR" title="Girar"><i class="fas fa-rotate-right"></i> ⟳</button>
        <button type="button" id="auReset" title="Resetar">Resetar</button>
      </div>
      <div class="au-msg" id="auMsg"></div>
    </div>
    <div class="au-foot">
      <button type="button" class="au-btn ghost" id="auCancel2">Cancelar</button>
      <button type="button" class="au-btn primary" id="auSave">Salvar avatar</button>
    </div>
  </div>
</div>

<script>
(function(){
  if (window.__avatarUploaderBound) return;
  window.__avatarUploaderBound = true;

  var CSRF = <?= json_encode($auCsrf) ?>;
  var ENDPOINT = '/OKR_system/auth/avatar_save.php';
  var fileInput = document.getElementById('auFile');
  var backdrop  = document.getElementById('auBackdrop');
  var imgEl     = document.getElementById('auImg');
  var msg       = document.getElementById('auMsg');
  var saveBtn   = document.getElementById('auSave');
  var cropper   = null;

  function open(){ backdrop.classList.add('open'); backdrop.setAttribute('aria-hidden','false'); }
  function close(){
    backdrop.classList.remove('open'); backdrop.setAttribute('aria-hidden','true');
    if (cropper){ cropper.destroy(); cropper = null; }
    imgEl.src = ''; msg.textContent = ''; fileInput.value = '';
    saveBtn.disabled = false; saveBtn.textContent = 'Salvar avatar';
  }

  // abre o seletor de arquivo via qualquer gatilho [data-avatar-upload-open]
  document.addEventListener('click', function(ev){
    var t = ev.target.closest('[data-avatar-upload-open]');
    if (t){ ev.preventDefault(); fileInput.click(); }
  });

  fileInput.addEventListener('change', function(){
    var f = fileInput.files && fileInput.files[0];
    if (!f) return;
    if (f.size > 5*1024*1024){ alert('Imagem muito grande (máx. 5 MB).'); fileInput.value=''; return; }
    var reader = new FileReader();
    reader.onload = function(e){
      imgEl.src = e.target.result;
      open();
      if (cropper) cropper.destroy();
      var start = function(){
        cropper = new Cropper(imgEl, { aspectRatio:1, viewMode:1, autoCropArea:1, background:false, responsive:true });
      };
      if (window.Cropper) start(); else setTimeout(start, 250);
    };
    reader.readAsDataURL(f);
  });

  document.getElementById('auZoomIn').onclick  = function(){ cropper && cropper.zoom(0.1); };
  document.getElementById('auZoomOut').onclick = function(){ cropper && cropper.zoom(-0.1); };
  document.getElementById('auRotL').onclick    = function(){ cropper && cropper.rotate(-90); };
  document.getElementById('auRotR').onclick    = function(){ cropper && cropper.rotate(90); };
  document.getElementById('auReset').onclick   = function(){ cropper && cropper.reset(); };
  document.getElementById('auCancel').onclick  = close;
  document.getElementById('auCancel2').onclick = close;
  backdrop.addEventListener('click', function(e){ if (e.target === backdrop) close(); });

  saveBtn.addEventListener('click', function(){
    if (!cropper){ return; }
    msg.textContent = '';
    saveBtn.disabled = true; saveBtn.textContent = 'Enviando…';
    var canvas = cropper.getCroppedCanvas({ width:512, height:512, imageSmoothingQuality:'high' });
    if (!canvas){ msg.textContent = 'Não foi possível recortar.'; saveBtn.disabled=false; saveBtn.textContent='Salvar avatar'; return; }
    var dataUrl = canvas.toDataURL('image/png');

    var fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('image_data', dataUrl);

    fetch(ENDPOINT, { method:'POST', credentials:'same-origin', body:fd })
      .then(function(r){ return r.json().catch(function(){ return {ok:false, error:'Resposta inválida'}; }); })
      .then(function(d){
        if (d && d.ok){
          var bust = (d.url || '') + (String(d.url).indexOf('?')<0 ? '?t='+Date.now() : '');
          document.querySelectorAll('[data-avatar-target]').forEach(function(el){ el.src = bust; });
          document.dispatchEvent(new CustomEvent('avatar:updated', {detail:{id:d.avatar_id, url:d.url, url_thumb:d.url_thumb}}));
          close();
        } else {
          msg.textContent = (d && d.error) ? d.error : 'Falha ao salvar.';
          saveBtn.disabled = false; saveBtn.textContent = 'Salvar avatar';
        }
      })
      .catch(function(){ msg.textContent = 'Erro de rede.'; saveBtn.disabled=false; saveBtn.textContent='Salvar avatar'; });
  });
})();
</script>
<?php } /* AVATAR_UPLOADER_EMITTED */ ?>
