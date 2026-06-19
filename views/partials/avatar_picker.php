<?php
/**
 * views/partials/avatar_picker.php
 * ------------------------------------------------------------------
 * Componente reutilizável de SELEÇÃO de avatar da galeria padrão.
 * Consome auth/avatars_gallery.php (filtros por gênero e tag).
 *
 * Fase 2: criado e disponível, porém AINDA NÃO incluído em nenhuma tela.
 * Na Fase 4 será plugado em profile_user.php e cadastro_site.php.
 *
 * Como usar (host screen):
 *   $avatarPickerInput = 'avatar_id';     // nome do <input hidden> (opcional)
 *   $avatarPickerSelected = 12;           // id pré-selecionado (opcional)
 *   include __DIR__ . '/partials/avatar_picker.php';
 *
 * Eventos:
 *   - dispara CustomEvent('avatar:selected', {detail:{id, url}}) no container.
 *   - mantém o id escolhido em <input type="hidden" name="$avatarPickerInput">.
 *
 * A persistência (salvar no usuário) é responsabilidade da tela host.
 * ------------------------------------------------------------------
 */

$apInput    = isset($avatarPickerInput) ? (string) $avatarPickerInput : 'avatar_id';
$apSelected = isset($avatarPickerSelected) ? (int) $avatarPickerSelected : 0;
$apId       = 'avatarPicker_' . substr(md5($apInput . '|' . (string) $apSelected . '|' . (string) mt_rand()), 0, 8);

// CSS do componente base de avatar (uma vez por página)
if (!defined('AVATAR_PICKER_CSS_EMITTED')) {
    define('AVATAR_PICKER_CSS_EMITTED', true);
    echo '<link rel="stylesheet" href="/OKR_system/assets/css/avatar.css">';
}
?>
<style>
.ap-wrap{ --ap-cell:72px; }
.ap-filters{ display:flex; flex-wrap:wrap; gap:8px; margin-bottom:12px; align-items:center; }
.ap-chip{
  border:1px solid var(--bg2,#6c5ce7); background:transparent; color:var(--bg1-contrast,#fff);
  border-radius:999px; padding:4px 12px; font-size:.78rem; cursor:pointer; line-height:1.4;
  transition:background .15s,color .15s;
}
.ap-chip.active{ background:var(--bg2,#6c5ce7); color:var(--bg2-contrast,#fff); font-weight:600; }
.ap-search{
  margin-left:auto; padding:5px 10px; border-radius:8px; border:1px solid rgba(255,255,255,.18);
  background:rgba(255,255,255,.04); color:inherit; font-size:.8rem; min-width:140px;
}
.ap-grid{
  display:grid; grid-template-columns:repeat(auto-fill, minmax(var(--ap-cell),1fr));
  gap:10px; max-height:340px; overflow-y:auto; padding:4px;
}
.ap-item{ background:transparent; border:2px solid transparent; border-radius:12px; padding:4px; cursor:pointer; }
.ap-item:hover{ border-color:rgba(255,255,255,.25); }
.ap-item.selected{ border-color:var(--bg2,#6c5ce7); background:rgba(108,92,231,.12); }
.ap-item .avatar{ width:100%; height:auto; aspect-ratio:1/1; }
.ap-empty,.ap-loading{ font-size:.85rem; opacity:.7; padding:16px; text-align:center; }
</style>

<div class="ap-wrap" id="<?= htmlspecialchars($apId, ENT_QUOTES, 'UTF-8') ?>" data-input="<?= htmlspecialchars($apInput, ENT_QUOTES, 'UTF-8') ?>" data-selected="<?= $apSelected ?>">
  <input type="hidden" name="<?= htmlspecialchars($apInput, ENT_QUOTES, 'UTF-8') ?>" value="<?= $apSelected ?: '' ?>">
  <div class="ap-filters">
    <button type="button" class="ap-chip active" data-gender="">Todos</button>
    <button type="button" class="ap-chip" data-gender="masculino">Masculino</button>
    <button type="button" class="ap-chip" data-gender="feminino">Feminino</button>
    <button type="button" class="ap-chip" data-gender="todos">Neutro</button>
    <input type="search" class="ap-search" placeholder="filtrar: barba, óculos, hijab…">
  </div>
  <div class="ap-tags ap-filters" style="margin-top:-4px"></div>
  <div class="ap-grid"><div class="ap-loading">Carregando avatares…</div></div>
</div>

<script>
(function(){
  var root = document.getElementById(<?= json_encode($apId) ?>);
  if (!root || root.dataset.bound) return;
  root.dataset.bound = '1';

  var ENDPOINT = '/OKR_system/auth/avatars_gallery.php';
  var grid   = root.querySelector('.ap-grid');
  var tagsEl = root.querySelector('.ap-tags');
  var hidden = root.querySelector('input[type=hidden]');
  var search = root.querySelector('.ap-search');
  var state  = { gender:'', tag:'', q:'', selected: parseInt(root.dataset.selected||'0',10)||0 };
  var debounce;

  function esc(s){ return String(s).replace(/[&<>"]/g, function(c){ return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'})[c]; }); }

  function render(data){
    if (!data.avatars || !data.avatars.length){
      grid.innerHTML = '<div class="ap-empty">Nenhum avatar encontrado.</div>';
    } else {
      grid.innerHTML = data.avatars.map(function(a){
        var sel = (a.id === state.selected) ? ' selected' : '';
        return '<div class="ap-item'+sel+'" data-id="'+a.id+'" data-url="'+esc(a.url)+'" title="'+esc(a.tags.join(', '))+'">'
             +   '<span class="avatar"><img src="'+esc(a.url)+'" alt="" loading="lazy" decoding="async"></span>'
             + '</div>';
      }).join('');
    }
    // facetas de tag (apenas quando sem filtro de tag, p/ explorar)
    if (data.facets && data.facets.tags){
      var feat = data.facets.tags.filter(function(t){ return !/^pele_/.test(t) && ['masculino','feminino','neutro'].indexOf(t)<0; });
      tagsEl.innerHTML = feat.map(function(t){
        var act = (t===state.tag)?' active':'';
        return '<button type="button" class="ap-chip'+act+'" data-tag="'+esc(t)+'">'+esc(t)+'</button>';
      }).join('');
    }
  }

  function load(){
    grid.innerHTML = '<div class="ap-loading">Carregando avatares…</div>';
    var u = new URL(ENDPOINT, location.origin);
    if (state.gender) u.searchParams.set('gender', state.gender);
    if (state.tag)    u.searchParams.set('tag', state.tag);
    if (state.q)      u.searchParams.set('q', state.q);
    fetch(u.toString(), {credentials:'same-origin'})
      .then(function(r){ return r.json(); })
      .then(function(d){ if(d && d.ok) render(d); else grid.innerHTML='<div class="ap-empty">Falha ao carregar.</div>'; })
      .catch(function(){ grid.innerHTML='<div class="ap-empty">Falha ao carregar.</div>'; });
  }

  root.addEventListener('click', function(ev){
    var g = ev.target.closest('[data-gender]');
    if (g && root.contains(g)){
      state.gender = g.getAttribute('data-gender');
      root.querySelectorAll('.ap-filters [data-gender]').forEach(function(b){ b.classList.toggle('active', b===g); });
      load(); return;
    }
    var t = ev.target.closest('[data-tag]');
    if (t && root.contains(t)){
      state.tag = (state.tag === t.getAttribute('data-tag')) ? '' : t.getAttribute('data-tag');
      load(); return;
    }
    var item = ev.target.closest('.ap-item');
    if (item && root.contains(item)){
      state.selected = parseInt(item.getAttribute('data-id'),10)||0;
      grid.querySelectorAll('.ap-item.selected').forEach(function(el){ el.classList.remove('selected'); });
      item.classList.add('selected');
      hidden.value = state.selected || '';
      root.dispatchEvent(new CustomEvent('avatar:selected', {bubbles:true, detail:{id:state.selected, url:item.getAttribute('data-url')}}));
    }
  });

  if (search){
    search.addEventListener('input', function(){
      clearTimeout(debounce);
      debounce = setTimeout(function(){ state.q = search.value.trim(); load(); }, 250);
    });
  }

  load();
})();
</script>
