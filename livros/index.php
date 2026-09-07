<?php
$booksPath = __DIR__ . '/../livros-extracted.json';
$coversPath = __DIR__ . '/../livros-covers.json';

$books = file_exists($booksPath) ? json_decode(file_get_contents($booksPath), true) : [];
$covers = file_exists($coversPath) ? json_decode(file_get_contents($coversPath), true) : [];

$coverMap = [];
foreach ($covers as $coverRow) {
  if (!empty($coverRow['title'])) {
    $coverMap[$coverRow['title']] = $coverRow['cover'];
  }
}

$payload = [
  'books' => $books,
  'covers' => $coverMap,
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Minha Biblioteca</title>
  <meta name="theme-color" content="#2c2218">
  <style>
    :root{
      --bg:#efe6d6;
      --panel:#fbf7ef;
      --panel-2:#f2ead9;
      --line:#d2c2a8;
      --line-strong:#ad9773;
      --text:#1f1a15;
      --muted:#6d5c49;
      --accent:#6e3b22;
      --accent-2:#9f673b;
      --gold:#b8922f;
      --shadow:0 2px 10px rgba(38,29,16,.12);
      --cover:#e6dccd;
      --cover-alt:#d7c4a7;
    }
    *{box-sizing:border-box}
    html,body{margin:0}
    body{
      background:
        radial-gradient(circle at top, rgba(255,255,255,.42), transparent 42%),
        linear-gradient(180deg, #f4ecdf 0%, #ede1cd 100%);
      color:var(--text);
      font-family: Georgia, "Times New Roman", serif;
      line-height:1.45;
    }
    header{
      padding:28px 20px 20px;
      background:linear-gradient(180deg, #3b2618, #25180f);
      color:#f3e7cb;
      border-bottom:4px double #c9aa58;
    }
    .wrap{max-width:1500px; margin:0 auto; padding:0 18px}
    h1{
      margin:0;
      font-size:2rem;
      font-weight:700;
      letter-spacing:.02em;
    }
    .subtitle{
      margin:6px 0 0;
      color:#d5c19b;
      font-size:.95rem;
    }
    .stats{
      margin-top:18px;
      display:grid;
      grid-template-columns:repeat(4, minmax(0, 1fr));
      gap:10px;
    }
    .stat{
      background:rgba(255,255,255,.07);
      border:1px solid rgba(255,255,255,.12);
      border-radius:8px;
      padding:12px 14px;
    }
    .stat b{
      display:block;
      font-size:1.35rem;
      color:#fff1c4;
    }
    .stat span{
      display:block;
      margin-top:2px;
      font-size:.75rem;
      text-transform:uppercase;
      letter-spacing:.12em;
      color:#cfbf9b;
    }
    main{padding:18px 0 40px}
    .toolbar{
      display:grid;
      gap:12px;
      margin-bottom:16px;
    }
    .search{
      width:100%;
      border:1px solid var(--line-strong);
      border-radius:10px;
      padding:14px 16px;
      font:inherit;
      color:var(--text);
      background:var(--panel);
      box-shadow:var(--shadow);
      outline:none;
    }
    .search:focus{border-color:var(--gold)}
    .chips{
      display:flex;
      flex-wrap:wrap;
      gap:8px;
    }
    .chip{
      border:1px solid var(--line-strong);
      background:var(--panel);
      color:var(--text);
      border-radius:999px;
      padding:9px 12px;
      font:inherit;
      font-size:.82rem;
      cursor:pointer;
    }
    .chip.active{
      background:var(--accent);
      color:#fff4d7;
      border-color:var(--accent);
    }
    .meta-line{
      margin:6px 0 18px;
      color:var(--muted);
      font-size:.9rem;
    }
    .section{
      margin-top:24px;
    }
    .section h2{
      margin:0 0 12px;
      font-size:1.2rem;
      color:var(--accent);
      border-bottom:1px solid var(--line);
      padding-bottom:8px;
    }
    .grid{
      display:grid;
      grid-template-columns:repeat(auto-fill, minmax(280px, 1fr));
      gap:14px;
    }
    .book{
      background:var(--panel);
      border:1px solid var(--line);
      border-radius:12px;
      box-shadow:var(--shadow);
      padding:12px;
      display:grid;
      grid-template-columns:96px 1fr;
      gap:12px;
      min-height:168px;
    }
    .cover{
      width:96px;
      aspect-ratio:2 / 3;
      border-radius:10px;
      background:linear-gradient(180deg, var(--cover), var(--cover-alt));
      border:1px solid rgba(0,0,0,.08);
      overflow:hidden;
      position:relative;
      display:flex;
      align-items:center;
      justify-content:center;
      text-align:center;
      padding:10px;
    }
    .cover img{
      width:100%;
      height:100%;
      object-fit:cover;
      display:block;
    }
    .cover-fallback{
      display:flex;
      flex-direction:column;
      gap:8px;
      align-items:center;
      justify-content:center;
      color:#432f19;
      font-size:.72rem;
      line-height:1.2;
    }
    .cover-fallback strong{
      font-size:.9rem;
      line-height:1.15;
    }
    .cover-tag{
      position:absolute;
      left:8px;
      right:8px;
      bottom:8px;
      font-size:.62rem;
      letter-spacing:.08em;
      text-transform:uppercase;
      color:#fff8e6;
      background:rgba(60,36,20,.8);
      border-radius:999px;
      padding:4px 6px;
    }
    .info{
      min-width:0;
    }
    .title{
      margin:0;
      font-size:1rem;
      line-height:1.25;
      color:var(--text);
    }
    .author{
      margin:4px 0 0;
      color:var(--muted);
      font-style:italic;
      font-size:.9rem;
    }
    .summary{
      margin:8px 0 0;
      color:#332b21;
      font-size:.88rem;
    }
    .badges{
      display:flex;
      flex-wrap:wrap;
      gap:6px;
      margin-top:10px;
    }
    .badge{
      display:inline-block;
      padding:4px 8px;
      border-radius:999px;
      background:#f1e8d9;
      border:1px solid var(--line);
      color:var(--accent);
      font-size:.68rem;
      text-transform:uppercase;
      letter-spacing:.08em;
    }
    .empty{
      display:none;
      padding:22px;
      border:1px dashed var(--line-strong);
      color:var(--muted);
      border-radius:10px;
      background:var(--panel);
    }
    footer{
      padding:24px 18px 32px;
      color:var(--muted);
      font-size:.82rem;
      text-align:center;
    }
    @media (max-width:760px){
      .stats{grid-template-columns:repeat(2, minmax(0, 1fr))}
      .book{grid-template-columns:84px 1fr}
      .cover{width:84px}
    }
  </style>
</head>
<body>
  <header>
    <div class="wrap">
      <h1>Minha Biblioteca</h1>
      <p class="subtitle">Catálogo com capas priorizando edições em português e fallback visual quando não há capa localizada.</p>
      <div class="stats" id="stats"></div>
    </div>
  </header>

  <main class="wrap">
    <div class="toolbar">
      <input id="search" class="search" type="search" placeholder="Buscar por título, autor, categoria ou tag">
      <div id="chips" class="chips"></div>
    </div>

    <div class="meta-line" id="meta"></div>
    <div id="empty" class="empty">Nenhum livro encontrado com esse filtro.</div>
    <div id="catalogo"></div>
  </main>

  <footer class="wrap">
    Imagens resolvidas a partir do acervo publicado e da busca por edições em português. Quando uma capa não foi localizada, a página exibe um fallback textual em vez de uma capa em inglês.
  </footer>

  <script>
    window.__BOOKS__ = <?= json_encode($payload['books'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    window.__COVERS__ = <?= json_encode($payload['covers'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  </script>
  <script>
    const books = window.__BOOKS__ || [];
    const coverMap = window.__COVERS__ || {};
    const categories = [...new Set(books.map((b) => b.c))];
    let activeCategory = 'Todas';

    function norm(value) {
      return (value || '')
        .toString()
        .toLowerCase()
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/[^a-z0-9]+/g, ' ')
        .trim();
    }

    function coverFor(book) {
      return coverMap[book.t] || null;
    }

    function renderStats(filtered) {
      const hasCover = filtered.filter((book) => coverFor(book) && coverFor(book).url).length;
      const byCategory = new Set(filtered.map((book) => book.c));
      document.getElementById('stats').innerHTML = [
        { value: books.length, label: 'obras' },
        { value: categories.length, label: 'categorias' },
        { value: byCategory.size, label: 'categorias visíveis' },
        { value: hasCover, label: 'com capa localizada' },
      ].map((item) => `
        <div class="stat">
          <b>${item.value}</b>
          <span>${item.label}</span>
        </div>
      `).join('');
    }

    function renderChips() {
      const root = document.getElementById('chips');
      const chips = ['Todas', ...categories];
      root.innerHTML = chips.map((cat) => `
        <button class="chip ${cat === activeCategory ? 'active' : ''}" data-cat="${cat}">${cat}</button>
      `).join('');
      root.querySelectorAll('.chip').forEach((button) => {
        button.addEventListener('click', () => {
          activeCategory = button.dataset.cat;
          render();
        });
      });
    }

    function renderBook(book, index) {
      const cover = coverFor(book);
      const badgeList = [book.c, book.y].filter(Boolean);
      const tags = Array.isArray(book.g) ? book.g : [];
      const coverHtml = cover && cover.url
        ? `<img src="${cover.url}" alt="Capa de ${book.t}" loading="lazy" referrerpolicy="no-referrer">`
        : `<div class="cover-fallback"><strong>${book.t}</strong><span>${book.a}</span></div><span class="cover-tag">capa em português não localizada</span>`;

      return `
        <article class="book">
          <div class="cover">${coverHtml}</div>
          <div class="info">
            <h3 class="title">${index + 1}. ${book.t}</h3>
            <p class="author">${book.a}</p>
            <p class="summary">${book.s || ''}</p>
            <div class="badges">
              ${badgeList.map((value) => `<span class="badge">${value}</span>`).join('')}
              ${tags.slice(0, 4).map((value) => `<span class="badge">${value}</span>`).join('')}
            </div>
          </div>
        </article>
      `;
    }

    function render() {
      const query = norm(document.getElementById('search').value);
      const filtered = books.filter((book) => {
        if (activeCategory !== 'Todas' && book.c !== activeCategory) return false;
        if (!query) return true;
        const haystack = [
          book.t,
          book.a,
          book.c,
          book.s,
          ...(Array.isArray(book.g) ? book.g : []),
        ].map(norm).join(' ');
        return haystack.includes(query);
      });

      document.getElementById('meta').textContent = `${filtered.length} obra${filtered.length === 1 ? '' : 's'} exibida${filtered.length === 1 ? '' : 's'}`;
      document.getElementById('empty').style.display = filtered.length ? 'none' : 'block';
      document.getElementById('catalogo').innerHTML = filtered
        .map((book, index) => renderBook(book, index))
        .join('');
      renderStats(filtered);
      renderChips();
    }

    document.getElementById('search').addEventListener('input', render);
    render();
  </script>
</body>
</html>
