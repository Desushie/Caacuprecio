<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

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

function is_admin(): bool
{
    $user = current_user();
    return isset($user['usu_tipo']) && (int)$user['usu_tipo'] === 1;
}

function require_admin(): void
{
    if (!is_admin()) {
        header('Location: index.php');
        exit;
    }
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
    return ((int) $value) === 1 ? 'En stock' : 'Consultar stock';
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

function current_user(): ?array
{
    $user = $_SESSION['user'] ?? null;
    return is_array($user) ? $user : null;
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

function require_login(): void
{
    if (!is_logged_in()) {
        $next = $_SERVER['REQUEST_URI'] ?? 'favoritos.php';
        header('Location: login.php?next=' . rawurlencode($next));
        exit;
    }
}

function current_user_id(): int
{
    $user = current_user();
    return (int) ($user['idusuario'] ?? 0);
}

function normalize_search_term(string $term): string
{
    $term = trim(mb_strtolower($term, 'UTF-8'));
    $term = preg_replace('/\s+/', ' ', $term);
    return $term ?? '';
}

function save_search_term(string $term, ?int $userId = null): void
{
    $term = trim($term);
    if ($term === '' || mb_strlen($term) < 2) {
        return;
    }

    $normalized = normalize_search_term($term);
    if ($normalized === '') {
        return;
    }

    $pdo = db();

    if ($userId !== null && $userId > 0) {
        $select = $pdo->prepare('
            SELECT idbusqueda
            FROM busquedas
            WHERE bus_normalizado = :normalizado
              AND bus_usuario_id = :usuario
            LIMIT 1
        ');
        $select->execute([
            ':normalizado' => $normalized,
            ':usuario' => $userId,
        ]);

        $existingId = $select->fetchColumn();

        if ($existingId) {
            $update = $pdo->prepare('
                UPDATE busquedas
                SET bus_total = bus_total + 1,
                    bus_termino = :termino,
                    bus_ultima_fecha = NOW()
                WHERE idbusqueda = :id
            ');
            $update->execute([
                ':termino' => $term,
                ':id' => $existingId,
            ]);
            return;
        }

        $insert = $pdo->prepare('
            INSERT INTO busquedas (bus_termino, bus_normalizado, bus_total, bus_usuario_id, bus_ultima_fecha)
            VALUES (:termino, :normalizado, 1, :usuario, NOW())
        ');
        $insert->execute([
            ':termino' => $term,
            ':normalizado' => $normalized,
            ':usuario' => $userId,
        ]);
        return;
    }

    $select = $pdo->prepare('
        SELECT idbusqueda
        FROM busquedas
        WHERE bus_normalizado = :normalizado
          AND bus_usuario_id IS NULL
        LIMIT 1
    ');
    $select->execute([
        ':normalizado' => $normalized,
    ]);

    $existingId = $select->fetchColumn();

    if ($existingId) {
        $update = $pdo->prepare('
            UPDATE busquedas
            SET bus_total = bus_total + 1,
                bus_termino = :termino,
                bus_ultima_fecha = NOW()
            WHERE idbusqueda = :id
        ');
        $update->execute([
            ':termino' => $term,
            ':id' => $existingId,
        ]);
        return;
    }

    $insert = $pdo->prepare('
        INSERT INTO busquedas (bus_termino, bus_normalizado, bus_total, bus_usuario_id, bus_ultima_fecha)
        VALUES (:termino, :normalizado, 1, NULL, NOW())
    ');
    $insert->execute([
        ':termino' => $term,
        ':normalizado' => $normalized,
    ]);
}

function get_trending_searches(int $limit = 8): array
{
    $stmt = db()->prepare('
        SELECT bus_termino, SUM(bus_total) AS total
        FROM busquedas
        GROUP BY bus_normalizado, bus_termino
        ORDER BY total DESC, MAX(bus_ultima_fecha) DESC
        LIMIT :limit
    ');
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function get_user_search_history(int $userId, int $limit = 6): array
{
    if ($userId <= 0) {
        return [];
    }

    $stmt = db()->prepare('
        SELECT bus_termino, bus_ultima_fecha, bus_total
        FROM busquedas
        WHERE bus_usuario_id = :usuario
        ORDER BY bus_ultima_fecha DESC
        LIMIT :limit
    ');
    $stmt->bindValue(':usuario', $userId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function is_favorite_product(int $productId, ?int $userId = null): bool
{
    $uid = $userId ?: current_user_id();
    if ($uid <= 0 || $productId <= 0) {
        return false;
    }

    $stmt = db()->prepare('SELECT 1 FROM favoritos WHERE usuario_idusuario = :u AND productos_idproductos = :p LIMIT 1');
    $stmt->execute([
        ':u' => $uid,
        ':p' => $productId,
    ]);

    return (bool) $stmt->fetchColumn();
}

function favorite_count(?int $userId = null): int
{
    $uid = $userId ?: current_user_id();
    if ($uid <= 0) {
        return 0;
    }

    $stmt = db()->prepare('SELECT COUNT(*) FROM favoritos WHERE usuario_idusuario = :u');
    $stmt->execute([':u' => $uid]);
    return (int) $stmt->fetchColumn();
}

function favorite_toggle_url(int $productId, string $redirect = ''): string
{
    $query = ['product_id' => $productId];
    if ($redirect !== '') {
        $query['redirect'] = $redirect;
    }

    return 'favorito_toggle.php?' . http_build_query($query);
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
    echo '<link rel="stylesheet" href="./css/styles.css">';
    echo '<link rel="stylesheet" href="./css/auth.css">';
    echo '<link rel="stylesheet" href="./css/favoritos_extra.css">';

    echo '<style>';
    echo '.navbar-apple{background:rgba(15,23,42,.72);backdrop-filter:blur(18px);-webkit-backdrop-filter:blur(18px);border-bottom:1px solid rgba(148,163,184,.16);box-shadow:0 10px 30px rgba(2,6,23,.18);}';
    echo '.navbar-apple .navbar-brand{letter-spacing:-.02em;}';
    echo '.navbar-apple .navbar-nav .nav-link{position:relative;padding:.6rem .9rem;border-radius:999px;color:rgba(226,232,240,.86);transition:all .22s ease;}';
    echo '.navbar-apple .navbar-nav .nav-link:hover{color:#fff;background:rgba(255,255,255,.08);}';
    echo '.navbar-apple .navbar-nav .nav-link.active{color:#fff;background:rgba(255,255,255,.12);box-shadow:inset 0 0 0 1px rgba(255,255,255,.08);}';
    echo '.navbar-apple .navbar-nav .nav-link.disabled{color:rgba(226,232,240,.7)!important;opacity:1;}';
    echo '.navbar-apple .navbar-toggler{border-radius:999px;padding:.55rem .8rem;background:rgba(255,255,255,.06);}';
    echo '.navbar-apple .btn-outline-primary{border-color:rgba(148,163,184,.35);background:rgba(255,255,255,.04);color:#e5eefc;}';
    echo '.navbar-apple .btn-outline-primary:hover{background:rgba(255,255,255,.12);border-color:rgba(191,219,254,.45);color:#fff;}';
    echo '.navbar-apple .badge.text-bg-primary{background:linear-gradient(135deg,#60a5fa,#3b82f6)!important;color:#fff;}';
    echo '@media (max-width:991.98px){.navbar-apple .navbar-collapse{margin-top:.9rem;padding:1rem;border-radius:1.25rem;background:rgba(15,23,42,.92);border:1px solid rgba(148,163,184,.14);box-shadow:0 18px 40px rgba(2,6,23,.28);} .navbar-apple .navbar-nav{gap:.4rem!important;} .navbar-apple .nav-link,.navbar-apple .btn{width:100%;justify-content:flex-start;}}';
    echo 'html[data-bs-theme="light"] .navbar-apple,body.theme-light .navbar-apple{background:rgba(255,255,255,.72);border-bottom:1px solid rgba(148,163,184,.22);box-shadow:0 10px 30px rgba(15,23,42,.08);}';
    echo 'html[data-bs-theme="light"] .navbar-apple .navbar-nav .nav-link,body.theme-light .navbar-apple .navbar-nav .nav-link{color:#334155;}';
    echo 'html[data-bs-theme="light"] .navbar-apple .navbar-nav .nav-link:hover,body.theme-light .navbar-apple .navbar-nav .nav-link:hover{color:#0f172a;background:rgba(15,23,42,.05);}';
    echo 'html[data-bs-theme="light"] .navbar-apple .navbar-nav .nav-link.active,body.theme-light .navbar-apple .navbar-nav .nav-link.active{color:#0f172a;background:rgba(15,23,42,.07);box-shadow:inset 0 0 0 1px rgba(15,23,42,.06);}';
    echo 'html[data-bs-theme="light"] .navbar-apple .navbar-nav .nav-link.disabled,body.theme-light .navbar-apple .navbar-nav .nav-link.disabled{color:#475569!important;}';
    echo 'html[data-bs-theme="light"] .navbar-apple .navbar-brand .text-body-secondary,body.theme-light .navbar-apple .navbar-brand .text-body-secondary{color:#475569!important;}';
    echo 'html[data-bs-theme="light"] .navbar-apple .btn-outline-primary,body.theme-light .navbar-apple .btn-outline-primary{border-color:rgba(148,163,184,.4);background:rgba(255,255,255,.65);color:#0f172a;}';
    echo 'html[data-bs-theme="light"] .navbar-apple .btn-outline-primary:hover,body.theme-light .navbar-apple .btn-outline-primary:hover{background:rgba(59,130,246,.08);border-color:rgba(59,130,246,.25);color:#0f172a;}';
    echo '@media (max-width:991.98px){html[data-bs-theme="light"] .navbar-apple .navbar-collapse,body.theme-light .navbar-apple .navbar-collapse{background:rgba(255,255,255,.94);border:1px solid rgba(148,163,184,.18);box-shadow:0 18px 40px rgba(15,23,42,.12);}}';
    echo '</style>';
    echo '</head>';
    echo '<body class="theme-dark">';
}

function render_navbar(string $current = 'home'): void
{
    $homeActive = $current === 'home' ? 'active' : '';
    $storesActive = $current === 'tienda' ? 'active' : '';
    $productActive = $current === 'producto' ? 'active' : '';
    $favActive = $current === 'favoritos' ? 'active' : '';
    $user = current_user();
    $favCount = favorite_count();

    echo '<nav class="navbar navbar-expand-lg sticky-top navbar-shell navbar-apple">';
    echo '  <div class="container py-2">';
    echo '    <a class="navbar-brand d-flex align-items-center gap-2 fw-bold" href="index.php">';
    echo '      <span class="brand-badge">CP</span>';
    echo '      <span><span class="d-block lh-1">Caacuprecio</span><small class="text-body-secondary fw-semibold">Comparador de precios</small></span>';
    echo '    </a>';

    echo '    <div class="d-flex align-items-center gap-2 order-lg-3 ms-auto ms-lg-3">';
    echo '      <button id="themeToggle" class="btn btn-outline-primary rounded-pill px-3 d-none d-lg-inline-flex align-items-center" type="button">';
    echo '        <i class="bi bi-sun-fill me-2"></i><span class="d-none d-xl-inline">Modo claro</span>';
    echo '      </button>';
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
    echo '        <li class="nav-item"><a class="nav-link ' . $favActive . '" href="favoritos.php">Favoritos';
    if ($user) {
        echo ' <span class="badge rounded-pill text-bg-primary ms-1">' . $favCount . '</span>';
    }
    echo '</a></li>';
    if ($user && is_admin()) {
        echo '        <li class="nav-item"><a class="nav-link ' . ($current === 'admin' ? 'active' : '') . '" href="./admin.php"><i class="bi bi-gear me-1"></i>Admin</a></li>';
    }
    if ($user) {
        echo '        <li class="nav-item"><span class="nav-link disabled"><i class="bi bi-person-circle me-1"></i>' . e((string) ($user['usu_nombre'] ?? $user['nombre'] ?? 'Mi cuenta')) . '</span></li>';
        echo '        <li class="nav-item"><a class="btn btn-sm btn-outline-primary rounded-pill px-3" href="logout.php">Salir</a></li>';
    } else {
        echo '        <li class="nav-item"><a class="btn btn-sm btn-outline-primary rounded-pill px-3" href="login.php">Ingresar</a></li>';
    }

    echo '      </ul>';
    echo '    </div>';
    echo '  </div>';
    echo '</nav>';
}

function render_footer(): void
{
    $suggestionSaved = isset($_GET['suggestion_saved']) && $_GET['suggestion_saved'] === '1';
    $suggestionError = isset($_GET['suggestion_error']) ? trim((string) $_GET['suggestion_error']) : '';

    echo '<footer class="footer pt-5 pb-4 mt-5">';
    echo '  <div class="container">';
    echo '    <div class="row g-4 pb-4 align-items-stretch">';
    echo '      <div class="col-lg-5">';
    echo '        <div class="d-flex align-items-center gap-2 fw-bold text-white mb-3"><span class="brand-badge">CP</span><span>Caacuprecio</span></div>';
    echo '        <p class="mb-0">Compará precios de distintas tiendas en un solo lugar y encontrá rápidamente la mejor opción disponible.</p>';
    echo '      </div>';

    echo '      <div class="col-6 col-lg-3">';
    echo '        <h6 class="text-white">Explorar</h6>';
    echo '        <ul class="list-unstyled d-grid gap-2 mt-3">';
    echo '          <li><a href="index.php#productos">Productos</a></li>';
    echo '          <li><a href="index.php#tiendas">Tiendas</a></li>';
    echo '          <li><a href="index.php#categorias">Categorías</a></li>';
    echo '          <li><a href="favoritos.php">Favoritos</a></li>';
    echo '        </ul>';
    echo '      </div>';

    echo '      <div class="col-lg-4">';
    echo '        <div class="footer-note footer-suggestion-card h-100">';
    echo '          <div class="d-flex flex-column h-100 justify-content-between gap-3">';
    echo '            <div>';
    echo '              <strong class="d-block mb-2">¿Tienes alguna sugerencia?</strong>';
    echo '              <div class="small">Contanos ideas, mejoras, errores o funciones que te gustaría ver en Caacuprecio.</div>';
    echo '            </div>';
    echo '            <div>';
    echo '              <button type="button" class="btn btn-primary rounded-pill px-4" data-bs-toggle="modal" data-bs-target="#suggestionModal">';
    echo '                <i class="bi bi-chat-left-text me-2"></i>Enviar sugerencia';
    echo '              </button>';
    echo '            </div>';
    echo '          </div>';
    echo '        </div>';
    echo '      </div>';
    echo '    </div>';

    echo '    <div class="border-top border-secondary-subtle pt-3 d-flex flex-column flex-md-row justify-content-between gap-2">';
    echo '      <small>© 2026 Caacuprecio</small>';
    echo '    </div>';
    echo '  </div>';
    echo '</footer>';

    if ($suggestionSaved) {
        echo '<div class="toast-container position-fixed bottom-0 end-0 p-3" style="z-index: 1080;">';
        echo '  <div class="toast show align-items-center text-bg-success border-0" role="alert" aria-live="assertive" aria-atomic="true">';
        echo '    <div class="d-flex">';
        echo '      <div class="toast-body">Tu sugerencia fue enviada correctamente.</div>';
        echo '      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Cerrar"></button>';
        echo '    </div>';
        echo '  </div>';
        echo '</div>';
    }

    echo '<div class="modal fade" id="suggestionModal" tabindex="-1" aria-labelledby="suggestionModalLabel" aria-hidden="true">';
    echo '  <div class="modal-dialog modal-dialog-centered">';
    echo '    <div class="modal-content suggestion-modal">';
    echo '      <form method="post" action="guardar_sugerencia.php">';
    echo '        <div class="modal-header">';
    echo '          <h5 class="modal-title" id="suggestionModalLabel">Enviar sugerencia</h5>';
    echo '          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>';
    echo '        </div>';
    echo '        <div class="modal-body">';

    if ($suggestionError !== '') {
        echo '          <div class="alert alert-danger">' . e($suggestionError) . '</div>';
    }

    echo '          <input type="hidden" name="redirect" value="' . e($_SERVER['REQUEST_URI'] ?? 'index.php') . '">';

    echo '          <div class="mb-3">';
    echo '            <label class="form-label">Tu nombre</label>';
    echo '            <input type="text" name="sug_nombre" class="form-control" maxlength="120" placeholder="Opcional">';
    echo '          </div>';

    echo '          <div class="mb-3">';
    echo '            <label class="form-label">Email</label>';
    echo '            <input type="email" name="sug_email" class="form-control" maxlength="150" placeholder="Opcional">';
    echo '          </div>';

    echo '          <div class="mb-3">';
    echo '            <label class="form-label">Asunto</label>';
    echo '            <input type="text" name="sug_asunto" class="form-control" maxlength="150" placeholder="Ej: Mejorar filtros, nueva tienda, error visual">';
    echo '          </div>';

    echo '          <div class="mb-0">';
    echo '            <label class="form-label">Sugerencia</label>';
    echo '            <textarea name="sug_detalle" class="form-control" rows="5" maxlength="2000" required placeholder="Escribí tu sugerencia aquí..."></textarea>';
    echo '          </div>';
    echo '        </div>';

    echo '        <div class="modal-footer">';
    echo '          <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancelar</button>';
    echo '          <button type="submit" class="btn btn-primary rounded-pill px-4">Enviar</button>';
    echo '        </div>';
    echo '      </form>';
    echo '    </div>';
    echo '  </div>';
    echo '</div>';

    echo '<script>';
    echo '(function(){';
    echo 'const root=document.documentElement;';
    echo 'const body=document.body;';
    echo 'const storageKey="caacuprecio-theme";';
    echo 'const toggleButtons=[document.getElementById("themeToggle"),document.getElementById("themeToggleMobile")].filter(Boolean);';
    echo 'function applyTheme(theme){const isDark=theme!=="light";root.setAttribute("data-bs-theme",isDark?"dark":"light");body.classList.toggle("theme-dark",isDark);body.classList.toggle("theme-light",!isDark);toggleButtons.forEach((btn)=>{const icon=btn.querySelector("i");const text=btn.querySelector("span");if(icon){icon.className=isDark?"bi bi-sun-fill me-2":"bi bi-moon-stars-fill me-2";}if(text){text.textContent=isDark?"Modo claro":"Modo oscuro";}});}';
    echo 'const savedTheme=localStorage.getItem(storageKey)||"dark";applyTheme(savedTheme);';
    echo 'toggleButtons.forEach((btn)=>btn.addEventListener("click",function(){const next=root.getAttribute("data-bs-theme")==="dark"?"light":"dark";localStorage.setItem(storageKey,next);applyTheme(next);}));';

    if ($suggestionError !== '') {
        echo 'const modalEl=document.getElementById("suggestionModal"); if(modalEl && window.bootstrap){ new bootstrap.Modal(modalEl).show(); }';
    }

    echo '})();';
    echo '</script>';
    echo '<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>';
    echo '</body></html>';
}