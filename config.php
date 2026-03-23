<?php
declare(strict_types=1);

const DB_HOST = 'localhost';
const DB_NAME = 'Caacuprecio';
const DB_USER = 'root';
const DB_PASS = '';
const APP_NAME = 'Caacuprecio';
const DEFAULT_THEME = 'dark';

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function gs(null|float|int|string $value): string
{
    if ($value === null || $value === '') {
        return 'Consultar';
    }

    return 'Gs. ' . number_format((float) $value, 0, ',', '.');
}

function image_url(?string $url, string $name = 'Producto'): string
{
    $url = trim((string) $url);

    if ($url !== '' && !str_starts_with($url, 'data:image')) {
        return $url;
    }

    return 'https://placehold.co/800x600/1f2937/f8fafc?text=' . rawurlencode($name);
}

function stock_label(mixed $value): string
{
    return ((int) $value) === 1 ? 'En stock' : 'Sin stock';
}

function stock_badge_class(mixed $value): string
{
    return ((int) $value) === 1 ? 'badge-stock-ok' : 'badge-stock-no';
}

function page_title(string $title = ''): string
{
    return $title !== '' ? $title . ' | ' . APP_NAME : APP_NAME;
}

function active_sort_options(): array
{
    return [
        'recientes' => 'Más recientes',
        'precio_asc' => 'Menor precio',
        'precio_desc' => 'Mayor precio',
        'nombre_asc' => 'Nombre A-Z',
    ];
}

function sort_sql(string $sort): string
{
    return match ($sort) {
        'precio_asc' => 'p.pro_precio ASC, p.idproductos DESC',
        'precio_desc' => 'p.pro_precio DESC, p.idproductos DESC',
        'nombre_asc' => 'p.pro_nombre ASC, p.idproductos DESC',
        default => 'p.pro_fecha_scraping DESC, p.idproductos DESC',
    };
}

function render_head(string $title = ''): void
{
    echo '<!DOCTYPE html>';
    echo '<html lang="es" data-bs-theme="' . DEFAULT_THEME . '">';
    echo '<head>';
    echo '<meta charset="UTF-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . e(page_title($title)) . '</title>';
    echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">';
    echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">';
    echo '<link rel="stylesheet" href="styles.css">';
    echo '</head>';
    echo '<body class="theme-dark">';
}

function render_navbar(string $current = 'home'): void
{
    $homeActive = $current === 'home' ? 'active' : '';
    $storesActive = $current === 'tienda' ? 'active' : '';
    $productActive = $current === 'producto' ? 'active' : '';

    echo '<div class="topbar py-2 d-none d-lg-block">';
    echo '  <div class="container d-flex justify-content-between align-items-center">';
    echo '    <div class="d-flex gap-3 flex-wrap">';
    echo '      <span><i class="bi bi-lightning-charge-fill me-1"></i>Compará precios entre tiendas</span>';
    echo '      <span><i class="bi bi-moon-stars-fill me-1"></i>Modo oscuro por defecto</span>';
    echo '    </div>';
    echo '    <div class="d-flex gap-3 align-items-center">';
    echo '      <a href="index.php#categorias">Categorías</a>';
    echo '      <a href="index.php#productos">Productos</a>';
    echo '      <a href="index.php#tiendas">Tiendas</a>';
    echo '      <button id="themeToggle" class="btn btn-sm btn-outline-light rounded-pill px-3" type="button">';
    echo '        <i class="bi bi-sun-fill me-2"></i><span>Modo claro</span>';
    echo '      </button>';
    echo '    </div>';
    echo '  </div>';
    echo '</div>';

    echo '<nav class="navbar navbar-expand-lg sticky-top navbar-shell">';
    echo '  <div class="container py-2">';
    echo '    <a class="navbar-brand d-flex align-items-center gap-2 fw-bold" href="index.php">';
    echo '      <span class="brand-badge">CP</span>';
    echo '      <span><span class="d-block lh-1">Caacuprecio</span><small class="text-body-secondary fw-semibold">Comparador de precios</small></span>';
    echo '    </a>';
    echo '    <div class="d-flex align-items-center gap-2 order-lg-3 ms-auto ms-lg-3">';
    echo '      <button id="themeToggleMobile" class="btn btn-outline-primary rounded-pill d-lg-none" type="button" aria-label="Cambiar tema">';
    echo '        <i class="bi bi-circle-half"></i>';
    echo '      </button>';
    echo '      <button class="navbar-toggler border-0 shadow-none" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav">';
    echo '        <span class="navbar-toggler-icon"></span>';
    echo '      </button>';
    echo '    </div>';
    echo '    <div class="collapse navbar-collapse" id="mainNav">';
    echo '      <ul class="navbar-nav ms-auto align-items-lg-center gap-lg-2">';
    echo '        <li class="nav-item"><a class="nav-link ' . $homeActive . '" href="index.php">Inicio</a></li>';
    echo '        <li class="nav-item"><a class="nav-link" href="index.php#categorias">Categorías</a></li>';
    echo '        <li class="nav-item"><a class="nav-link ' . $productActive . '" href="index.php#productos">Productos</a></li>';
    echo '        <li class="nav-item"><a class="nav-link ' . $storesActive . '" href="index.php#tiendas">Tiendas</a></li>';
    echo '      </ul>';
    echo '    </div>';
    echo '  </div>';
    echo '</nav>';
}

function render_footer(): void
{
    echo '<footer class="footer pt-5 pb-4 mt-5">';
    echo '  <div class="container">';
    echo '    <div class="row g-4 pb-4">';
    echo '      <div class="col-lg-5">';
    echo '        <div class="d-flex align-items-center gap-2 fw-bold text-white mb-3"><span class="brand-badge">CP</span><span>Caacuprecio</span></div>';
    echo '        <p class="mb-0">Proyecto base en PHP + MySQL + Bootstrap, adaptado a tu esquema actual con tiendas, categorías, productos e historial de precios.</p>';
    echo '      </div>';
    echo '      <div class="col-6 col-lg-3">';
    echo '        <h6 class="text-white">Explorar</h6>';
    echo '        <ul class="list-unstyled d-grid gap-2 mt-3">';
    echo '          <li><a href="index.php#productos">Productos</a></li>';
    echo '          <li><a href="index.php#tiendas">Tiendas</a></li>';
    echo '          <li><a href="index.php#categorias">Categorías</a></li>';
    echo '        </ul>';
    echo '      </div>';
    echo '      <div class="col-lg-4">';
    echo '        <div class="footer-note">';
    echo '          <strong>Archivos incluidos:</strong> <code>index.php</code>, <code>config.php</code>, <code>producto.php</code>, <code>tienda.php</code> y <code>styles.css</code>.';
    echo '        </div>';
    echo '      </div>';
    echo '    </div>';
    echo '    <div class="border-top border-secondary-subtle pt-3 d-flex flex-column flex-md-row justify-content-between gap-2">';
    echo '      <small>© 2026 Caacuprecio</small>';
    echo '      <small>Modo oscuro por defecto</small>';
    echo '    </div>';
    echo '  </div>';
    echo '</footer>';

    echo '<script>';
    echo '(function(){';
    echo 'const root=document.documentElement;';
    echo 'const body=document.body;';
    echo 'const storageKey="caacuprecio-theme";';
    echo 'const toggleButtons=[document.getElementById("themeToggle"),document.getElementById("themeToggleMobile")].filter(Boolean);';
    echo 'function applyTheme(theme){const isDark=theme!=="light";root.setAttribute("data-bs-theme",isDark?"dark":"light");body.classList.toggle("theme-dark",isDark);body.classList.toggle("theme-light",!isDark);toggleButtons.forEach((btn)=>{const icon=btn.querySelector("i");const text=btn.querySelector("span");if(icon){icon.className=isDark?"bi bi-sun-fill me-2":"bi bi-moon-stars-fill me-2";}if(text){text.textContent=isDark?"Modo claro":"Modo oscuro";}});}';
    echo 'const savedTheme=localStorage.getItem(storageKey)||"dark";applyTheme(savedTheme);';
    echo 'toggleButtons.forEach((btn)=>btn.addEventListener("click",function(){const next=root.getAttribute("data-bs-theme")==="dark"?"light":"dark";localStorage.setItem(storageKey,next);applyTheme(next);}));';
    echo '})();';
    echo '</script>';
    echo '<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>';
    echo '</body></html>';
}
