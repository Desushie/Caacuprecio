<?php
require_once __DIR__ . '/config.php';
require_admin_or_empresa();

$pdo = db();
$user = current_user();
$isEmpresa = is_empresa();
$myStoreId = $isEmpresa ? (int)($user['tiendas_idtiendas'] ?? 0) : 0;

// CONTROL SEGURO DE FILTRO POR TIENDA
if ($isEmpresa) {
    $storeId = $myStoreId;
} else {
    $storeId = max(0, (int) ($_GET['tienda'] ?? 0));
}

$dateFrom = trim((string) ($_GET['desde'] ?? ''));
$dateTo = trim((string) ($_GET['hasta'] ?? ''));

function cp_table_exists(PDO $pdo, string $table): bool
{
    static $cache = [];
    $key = mb_strtolower($table, 'UTF-8');

    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name = :table
        ");
        $stmt->execute([':table' => $table]);
        $cache[$key] = ((int) $stmt->fetchColumn()) > 0;
    } catch (Throwable $e) {
        $cache[$key] = false;
    }

    return $cache[$key];
}

$hasProductClicks = cp_table_exists($pdo, 'producto_clicks');

// ==========================================
// 1. OBTENCIÓN DE ESTADÍSTICAS GENERALES
// ==========================================

// Total Productos Activos de la Tienda
$sqlProd = "SELECT COUNT(*) FROM productos WHERE pro_activo = 1";
if ($storeId > 0) {
    $sqlProd .= " AND tiendas_idtiendas = :storeId";
}
$stmtProd = $pdo->prepare($sqlProd);
if ($storeId > 0) $stmtProd->bindValue(':storeId', $storeId, PDO::PARAM_INT);
$stmtProd->execute();
$totalProductosActivos = (int) $stmtProd->fetchColumn();

// Total Clicks en Enlaces Externos
$totalClicks = 0;
if ($hasProductClicks) {
    $sqlClicks = "
        SELECT COUNT(*) 
        FROM producto_clicks pc
        INNER JOIN productos p ON p.idproductos = pc.productos_idproductos
        WHERE 1=1
    ";
    if ($storeId > 0) {
        $sqlClicks .= " AND p.tiendas_idtiendas = :storeId";
    }
    
    $stmtClicks = $pdo->prepare($sqlClicks);
    if ($storeId > 0) $stmtClicks->bindValue(':storeId', $storeId, PDO::PARAM_INT);
    $stmtClicks->execute();
    $totalClicks = (int) $stmtClicks->fetchColumn();
}

// Promedio de Precios en Catálogo
$sqlPrecio = "SELECT AVG(pro_precio) FROM productos WHERE pro_activo = 1";
if ($storeId > 0) {
    $sqlPrecio .= " AND tiendas_idtiendas = :storeId";
}
$stmtPrecio = $pdo->prepare($sqlPrecio);
if ($storeId > 0) $stmtPrecio->bindValue(':storeId', $storeId, PDO::PARAM_INT);
$stmtPrecio->execute();
$promedioPrecio = (float) $stmtPrecio->fetchColumn();


// ==========================================
// 2. DATOS PARA GRÁFICOS (TOP 10 PRODUCTOS)
// ==========================================
$topProductsLabels = [];
$topProductsData = [];

if ($hasProductClicks) {
    // 🌟 CORRECCIÓN AQUÍ: Se usa pc.productos_idproductos en vez de pc.id inexistente
    $sqlTopProd = "
        SELECT p.pro_nombre, COUNT(pc.productos_idproductos) as total_clicks
        FROM producto_clicks pc
        INNER JOIN productos p ON p.idproductos = pc.productos_idproductos
        WHERE 1=1
    ";
    if ($storeId > 0) {
        $sqlTopProd .= " AND p.tiendas_idtiendas = :storeId";
    }
    $sqlTopProd .= " GROUP BY p.idproductos ORDER BY total_clicks DESC LIMIT 10";
    
    $stmtTopProd = $pdo->prepare($sqlTopProd);
    if ($storeId > 0) $stmtTopProd->bindValue(':storeId', $storeId, PDO::PARAM_INT);
    $stmtTopProd->execute();
    $topProducts = $stmtTopProd->fetchAll();

    foreach ($topProducts as $tp) {
        $topProductsLabels[] = mb_strimwidth($tp['pro_nombre'], 0, 25, '...');
        $topProductsData[] = (int) $tp['total_clicks'];
    }
}


// ==========================================
// 3. DATOS PARA GRÁFICOS (DISTRIBUCIÓN POR CATEGORÍAS)
// ==========================================
$catLabels = [];
$catData = [];

$sqlCatDistribution = "
    SELECT c.cat_nombre, COUNT(p.idproductos) as cantidad
    FROM productos p
    INNER JOIN categorias c ON c.idcategorias = p.categorias_idcategorias
    WHERE p.pro_activo = 1
";
if ($storeId > 0) {
    $sqlCatDistribution .= " AND p.tiendas_idtiendas = :storeId";
}
$sqlCatDistribution .= " GROUP BY c.idcategorias ORDER BY cantidad DESC LIMIT 10";

$stmtCatDist = $pdo->prepare($sqlCatDistribution);
if ($storeId > 0) $stmtCatDist->bindValue(':storeId', $storeId, PDO::PARAM_INT);
$stmtCatDist->execute();
$catDistribution = $stmtCatDist->fetchAll();

foreach ($catDistribution as $cd) {
    $catLabels[] = $cd['cat_nombre'];
    $catData[] = (int) $cd['cantidad'];
}


// Listado de tiendas auxiliar (solo para combo de Administradores)
$stores = [];
if (!$isEmpresa) {
    $stores = $pdo->query('SELECT idtiendas, tie_nombre FROM tiendas ORDER BY tie_nombre ASC')->fetchAll();
} else {
    $stmtMyStore = $pdo->prepare('SELECT tie_nombre FROM tiendas WHERE idtiendas = ?');
    $stmtMyStore->execute([$myStoreId]);
    $myStoreName = $stmtMyStore->fetchColumn() ?: 'Mi Tienda';
}

render_head('Métricas y Analytics');
?>
<link rel="stylesheet" href="./css/admin.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<?php render_navbar('admin'); ?>

<div class="site-bg" aria-hidden="true">
  <span class="bg-orb orb-1"></span>
  <span class="bg-orb orb-2"></span>
  <span class="bg-orb orb-3"></span>
  <span class="bg-grid"></span>
</div>

<section class="admin-shell">
  <div class="container">
    
    <div class="admin-hero p-4 p-lg-5 mb-4">
      <div class="row g-4 align-items-center">
        <div class="col-lg-8 position-relative z-1">
          <div class="admin-kicker mb-2">Analytics</div>
          <h1 class="display-6 fw-bold mb-3">
            <?= $isEmpresa ? 'Rendimiento de ' . e($myStoreName) : 'Panel General de Métricas' ?>
          </h1>
          <p class="text-body-secondary mb-0">
            Monitoreá el interés de los usuarios, clicks salientes hacia tu e-commerce y la composición de categorías.
          </p>
        </div>
      </div>
    </div>

    <div class="admin-panel p-4 mb-4 admin-filter-bar">
      <form class="row g-3 align-items-end" method="get">
        <?php if (!$isEmpresa): ?>
          <div class="col-md-4">
            <label class="form-label text-body-secondary small">Filtrar por Tienda</label>
            <select name="tienda" class="form-select">
              <option value="0">Todas las tiendas activas</option>
              <?php foreach ($stores as $store): ?>
                <option value="<?= (int) $store['idtiendas'] ?>" <?= $storeId === (int) $store['idtiendas'] ? 'selected' : '' ?>>
                  <?= e($store['tie_nombre']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        <?php else: ?>
          <div class="col-md-4">
            <label class="form-label text-body-secondary small">Tienda Monitoreada</label>
            <input type="text" class="form-control" value="<?= e($myStoreName) ?>" readonly disabled>
          </div>
        <?php endif; ?>

        <div class="col-md-3">
          <label class="form-label text-body-secondary small">Desde</label>
          <input type="date" name="desde" class="form-control" value="<?= e($dateFrom) ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label text-body-secondary small">Hasta</label>
          <input type="date" name="hasta" class="form-control" value="<?= e($dateTo) ?>">
        </div>
        <div class="col-md-2 d-grid">
          <button class="btn btn-primary" type="submit">
            <i class="bi bi-filter me-2"></i>Filtrar
          </button>
        </div>
      </form>
    </div>

    <div class="row g-4 mb-5">
      <div class="col-md-4">
        <div class="admin-panel p-4 h-100 d-flex align-items-center gap-3">
          <div class="fs-1 text-primary"><i class="bi bi-box-seam"></i></div>
          <div>
            <div class="text-body-secondary small text-uppercase">Productos Activos</div>
            <h3 class="fw-bold mb-0"><?= number_format($totalProductosActivos, 0, ',', '.') ?></h3>
          </div>
        </div>
      </div>
      <div class="col-md-4">
        <div class="admin-panel p-4 h-100 d-flex align-items-center gap-3">
          <div class="fs-1 text-success"><i class="bi bi-cursor-fill"></i></div>
          <div>
            <div class="text-body-secondary small text-uppercase">Clicks Salientes</div>
            <h3 class="fw-bold mb-0"><?= number_format($totalClicks, 0, ',', '.') ?></h3>
          </div>
        </div>
      </div>
      <div class="col-md-4">
        <div class="admin-panel p-4 h-100 d-flex align-items-center gap-3">
          <div class="fs-1 text-warning"><i class="bi bi-tags"></i></div>
          <div>
            <div class="text-body-secondary small text-uppercase">Precio Promedio</div>
            <h3 class="fw-bold mb-0"><?= gs($promedioPrecio) ?></h3>
          </div>
        </div>
      </div>
    </div>

    <div class="row g-4">
      <div class="col-lg-7">
        <div class="admin-panel p-4">
          <h5 class="fw-bold mb-4"><i class="bi bi-bar-chart-line me-2 text-primary"></i>Top 10 Productos con más Clicks al Sitio</h5>
          <div style="height: 380px; position: relative;">
            <?php if (!empty($topProductsData)): ?>
              <canvas id="chartTopProducts"></canvas>
            <?php else: ?>
              <div class="admin-empty position-absolute top-50 start-50 translate-middle w-100">Aún no se registraron clicks en los productos de esta tienda.</div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="col-lg-5">
        <div class="admin-panel p-4">
          <h5 class="fw-bold mb-4"><i class="bi bi-pie-chart me-2 text-success"></i>Distribución de Stock por Categoría</h5>
          <div style="height: 380px; position: relative;">
            <?php if (!empty($catData)): ?>
              <canvas id="chartCategories"></canvas>
            <?php else: ?>
              <div class="admin-empty position-absolute top-50 start-50 translate-middle w-100">No hay datos de categorías disponibles.</div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

  </div>
</section>

<script>
  function buildPalette(total) {
    const base = [
      '#7c3aed', '#22d3ee', '#f97316', '#22c55e', '#ef4444',
      '#3b82f6', '#ec4899', '#eab308', '#a855f7', '#14b8a6'
    ];
    const colors = [];
    for (let i = 0; i < total; i++) {
      colors.push(base[i % base.length]);
    }
    return colors;
  }

  // 🌟 FUNCIÓN NATIVA RESTAURADA (Para compatibilidad con gráficos horizontales estilizados)
  function makeBarChart(canvasId, labels, data, label) {
    const el = document.getElementById(canvasId);
    if (!el || !labels.length) return;

    new Chart(el, {
      type: 'bar',
      data: {
        labels: labels,
        datasets: [{
          label: label,
          data: data,
          backgroundColor: '#7c3aed',
          borderRadius: 6
        }]
      },
      options: {
        indexAxis: 'y',
        maintainAspectRatio: false,
        responsive: true,
        plugins: {
          legend: { display: false }
        },
        disabledCanvas: true,
        scales: {
          x: { grid: { color: 'rgba(255,255,255,0.05)' }, ticks: { color: '#9fb2d1' } },
          y: { grid: { display: false }, ticks: { color: '#9fb2d1' } }
        }
      }
    });
  }

  // Renderizado del Gráfico de Productos
  <?php if (!empty($topProductsData)): ?>
    makeBarChart('chartTopProducts', <?= json_encode($topProductsLabels, JSON_UNESCAPED_SLASHES) ?>, <?= json_encode($topProductsData) ?>, 'Cantidad de Clicks');
  <?php endif; ?>

  // Gráfico de Categorías (Doughnut)
  <?php if (!empty($catData)): ?>
  const ctxCat = document.getElementById('chartCategories');
  if (ctxCat) {
    const labelsCat = <?= json_encode($catLabels, JSON_UNESCAPED_SLASHES) ?>;
    const dataCat = <?= json_encode($catData) ?>;
    const colorsCat = buildPalette(labelsCat.length);

    new Chart(ctxCat, {
      type: 'doughnut',
      data: {
        labels: labelsCat,
        datasets: [{
          data: dataCat,
          backgroundColor: colorsCat,
          borderWidth: 0
        }]
      },
      options: {
        maintainAspectRatio: false,
        responsive: true,
        plugins: {
          legend: {
            position: 'bottom',
            labels: { color: '#9fb2d1', boxWidth: 12, padding: 15 }
          }
        }
      }
    });
  }
  <?php endif; ?>
</script>

<?php render_footer(); ?>