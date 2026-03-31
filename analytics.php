<?php
require_once __DIR__ . '/config.php';
require_admin();

$pdo = db();

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

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$hasProductClicks = cp_table_exists($pdo, 'producto_clicks');
$hasProductViews = cp_table_exists($pdo, 'productos_vistos');

$storeId = max(0, (int) ($_GET['tienda'] ?? 0));
$dateFrom = trim((string) ($_GET['desde'] ?? ''));
$dateTo = trim((string) ($_GET['hasta'] ?? ''));

$stores = $pdo->query("
    SELECT idtiendas, tie_nombre
    FROM tiendas
    ORDER BY tie_nombre ASC
")->fetchAll();

$stats = [
    'total_clicks_oferta' => 0,
    'total_clicks_mejor_oferta' => 0,
    'total_vistas' => 0,
    'total_tiendas_con_datos' => 0,
];

$clicksPorTienda = [];
$mejorOfertaPorTienda = [];
$productosMasVistos = [];
$productosMasClickeadosSalida = [];
$topVistasGlobal = [];

$clickFilters = [];
$clickParams = [];
$viewFilters = [];
$viewParams = [];

if ($storeId > 0) {
    $clickFilters[] = 'p.tiendas_idtiendas = :tienda';
    $clickParams[':tienda'] = $storeId;

    $viewFilters[] = 'p.tiendas_idtiendas = :tienda';
    $viewParams[':tienda'] = $storeId;
}

if ($dateFrom !== '') {
    $clickFilters[] = 'DATE(pc.click_fecha) >= :desde';
    $clickParams[':desde'] = $dateFrom;

    $viewFilters[] = 'DATE(pv.visto_en) >= :desde';
    $viewParams[':desde'] = $dateFrom;
}

if ($dateTo !== '') {
    $clickFilters[] = 'DATE(pc.click_fecha) <= :hasta';
    $clickParams[':hasta'] = $dateTo;

    $viewFilters[] = 'DATE(pv.visto_en) <= :hasta';
    $viewParams[':hasta'] = $dateTo;
}

$clickWhere = $clickFilters ? (' AND ' . implode(' AND ', $clickFilters)) : '';
$viewWhere = $viewFilters ? (' AND ' . implode(' AND ', $viewFilters)) : '';

if ($hasProductClicks) {
    $sql = "
        SELECT COUNT(*)
        FROM producto_clicks pc
        INNER JOIN productos p ON p.idproductos = pc.productos_idproductos
        WHERE pc.click_tipo = 'ir_oferta'
        {$clickWhere}
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($clickParams);
    $stats['total_clicks_oferta'] = (int) $stmt->fetchColumn();

    $sql = "
        SELECT COUNT(*)
        FROM producto_clicks pc
        INNER JOIN productos p ON p.idproductos = pc.productos_idproductos
        WHERE pc.click_tipo = 'ir_mejor_oferta'
        {$clickWhere}
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($clickParams);
    $stats['total_clicks_mejor_oferta'] = (int) $stmt->fetchColumn();

    $sql = "
        SELECT COUNT(DISTINCT p.tiendas_idtiendas)
        FROM producto_clicks pc
        INNER JOIN productos p ON p.idproductos = pc.productos_idproductos
        WHERE pc.click_tipo IN ('ir_oferta', 'ir_mejor_oferta')
        {$clickWhere}
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($clickParams);
    $stats['total_tiendas_con_datos'] = (int) $stmt->fetchColumn();

    $sql = "
        SELECT
            t.idtiendas,
            t.tie_nombre,
            COUNT(*) AS total_clicks
        FROM producto_clicks pc
        INNER JOIN productos p ON p.idproductos = pc.productos_idproductos
        INNER JOIN tiendas t ON t.idtiendas = p.tiendas_idtiendas
        WHERE pc.click_tipo = 'ir_oferta'
        {$clickWhere}
        GROUP BY t.idtiendas, t.tie_nombre
        ORDER BY total_clicks DESC, t.tie_nombre ASC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($clickParams);
    $clicksPorTienda = $stmt->fetchAll();

    $sql = "
        SELECT
            t.idtiendas,
            t.tie_nombre,
            COUNT(*) AS total_clicks
        FROM producto_clicks pc
        INNER JOIN productos p ON p.idproductos = pc.productos_idproductos
        INNER JOIN tiendas t ON t.idtiendas = p.tiendas_idtiendas
        WHERE pc.click_tipo = 'ir_mejor_oferta'
        {$clickWhere}
        GROUP BY t.idtiendas, t.tie_nombre
        ORDER BY total_clicks DESC, t.tie_nombre ASC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($clickParams);
    $mejorOfertaPorTienda = $stmt->fetchAll();

    $sql = "
        SELECT
            t.idtiendas,
            t.tie_nombre,
            p.idproductos,
            p.pro_nombre,
            p.pro_imagen,
            COUNT(*) AS total_clicks
        FROM producto_clicks pc
        INNER JOIN productos p ON p.idproductos = pc.productos_idproductos
        INNER JOIN tiendas t ON t.idtiendas = p.tiendas_idtiendas
        WHERE pc.click_tipo IN ('ir_oferta', 'ir_mejor_oferta')
        {$clickWhere}
        GROUP BY t.idtiendas, t.tie_nombre, p.idproductos, p.pro_nombre, p.pro_imagen
        ORDER BY t.tie_nombre ASC, total_clicks DESC, p.pro_nombre ASC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($clickParams);
    $productosMasClickeadosSalida = $stmt->fetchAll();
}

if ($hasProductViews) {
    $sql = "
        SELECT COUNT(*)
        FROM productos_vistos pv
        INNER JOIN productos p ON p.idproductos = pv.productos_idproductos
        WHERE 1=1
        {$viewWhere}
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($viewParams);
    $stats['total_vistas'] = (int) $stmt->fetchColumn();

    $sql = "
        SELECT
            t.idtiendas,
            t.tie_nombre,
            p.idproductos,
            p.pro_nombre,
            p.pro_imagen,
            COUNT(*) AS total_vistas
        FROM productos_vistos pv
        INNER JOIN productos p ON p.idproductos = pv.productos_idproductos
        INNER JOIN tiendas t ON t.idtiendas = p.tiendas_idtiendas
        WHERE 1=1
        {$viewWhere}
        GROUP BY t.idtiendas, t.tie_nombre, p.idproductos, p.pro_nombre, p.pro_imagen
        ORDER BY t.tie_nombre ASC, total_vistas DESC, p.pro_nombre ASC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($viewParams);
    $productosMasVistos = $stmt->fetchAll();

    $sql = "
        SELECT
            p.idproductos,
            p.pro_nombre,
            t.tie_nombre,
            COUNT(*) AS total_vistas
        FROM productos_vistos pv
        INNER JOIN productos p ON p.idproductos = pv.productos_idproductos
        INNER JOIN tiendas t ON t.idtiendas = p.tiendas_idtiendas
        WHERE 1=1
        {$viewWhere}
        GROUP BY p.idproductos, p.pro_nombre, t.tie_nombre
        ORDER BY total_vistas DESC, p.pro_nombre ASC
        LIMIT 10
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($viewParams);
    $topVistasGlobal = $stmt->fetchAll();
}

function agrupar_por_tienda(array $rows): array
{
    $out = [];

    foreach ($rows as $row) {
        $storeId = (int) ($row['idtiendas'] ?? 0);

        if (!isset($out[$storeId])) {
            $out[$storeId] = [
                'idtiendas' => $storeId,
                'tie_nombre' => $row['tie_nombre'] ?? 'Tienda',
                'items' => [],
            ];
        }

        $out[$storeId]['items'][] = $row;
    }

    return $out;
}

$productosMasVistosPorTienda = agrupar_por_tienda($productosMasVistos);
$productosMasClickeadosSalidaPorTienda = agrupar_por_tienda($productosMasClickeadosSalida);

$chartLabelsOfertas = array_map(static fn($x) => $x['tie_nombre'], $clicksPorTienda);
$chartDataOfertas = array_map(static fn($x) => (int) $x['total_clicks'], $clicksPorTienda);

$chartLabelsMejorOferta = array_map(static fn($x) => $x['tie_nombre'], $mejorOfertaPorTienda);
$chartDataMejorOferta = array_map(static fn($x) => (int) $x['total_clicks'], $mejorOfertaPorTienda);

$chartLabelsTopVistas = array_map(
    static fn($x) => $x['pro_nombre'] . ' (' . $x['tie_nombre'] . ')',
    $topVistasGlobal
);
$chartDataTopVistas = array_map(static fn($x) => (int) $x['total_vistas'], $topVistasGlobal);

render_head('Analíticas');
?>
<link rel="stylesheet" href="./css/admin.css">

<style>
.analytics-grid {
    display: grid;
    grid-template-columns: repeat(12, minmax(0, 1fr));
    gap: 1.5rem;
}
.analytics-span-6 { grid-column: span 6; }
.analytics-span-12 { grid-column: span 12; }

.analytics-chart-card,
.analytics-table-card {
    background: rgba(255,255,255,.78);
    backdrop-filter: blur(12px);
    border: 1px solid rgba(148,163,184,.16);
    border-radius: 1.25rem;
    box-shadow: 0 16px 45px rgba(15,23,42,.08);
}

.analytics-chart-wrap {
    position: relative;
    min-height: 340px;
}

.analytics-store-block {
    border: 1px solid rgba(148,163,184,.16);
    border-radius: 1rem;
    padding: 1rem;
    background: rgba(255,255,255,.55);
}

.analytics-thumb {
    width: 56px;
    height: 56px;
    object-fit: cover;
    border-radius: 12px;
    background: rgba(148,163,184,.12);
}

.analytics-filters {
    background: rgba(255,255,255,.72);
    border: 1px solid rgba(148,163,184,.16);
    border-radius: 1rem;
}

@media (max-width: 991.98px) {
    .analytics-span-6,
    .analytics-span-12 {
        grid-column: span 12;
    }
}
</style>

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
          <div class="admin-kicker mb-2">Panel</div>
          <h1 class="display-6 fw-bold mb-3">Analíticas</h1>
          <p class="text-body-secondary mb-4">
            Filtrá por tienda y fechas para revisar clicks y vistas.
          </p>
          <div class="d-flex flex-wrap gap-3">
            <a href="admin.php" class="btn btn-outline-primary rounded-pill px-4">
              <i class="bi bi-arrow-left me-2"></i>Volver al panel
            </a>
          </div>
        </div>
      </div>
    </div>

    <div class="analytics-filters p-4 mb-4">
      <form method="get" action="analytics.php" class="row g-3 align-items-end">
        <div class="col-md-4">
          <label class="form-label fw-semibold">Tienda</label>
          <select name="tienda" class="form-select">
            <option value="0">Todas las tiendas</option>
            <?php foreach ($stores as $store): ?>
              <option value="<?= (int) $store['idtiendas'] ?>" <?= $storeId === (int) $store['idtiendas'] ? 'selected' : '' ?>>
                <?= h($store['tie_nombre']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-3">
          <label class="form-label fw-semibold">Desde</label>
          <input type="date" name="desde" class="form-control" value="<?= h($dateFrom) ?>">
        </div>

        <div class="col-md-3">
          <label class="form-label fw-semibold">Hasta</label>
          <input type="date" name="hasta" class="form-control" value="<?= h($dateTo) ?>">
        </div>

        <div class="col-md-2 d-grid">
          <button type="submit" class="btn btn-primary rounded-pill">
            <i class="bi bi-funnel me-2"></i>Filtrar
          </button>
        </div>

        <div class="col-12">
          <a href="analytics.php" class="btn btn-sm btn-outline-secondary rounded-pill px-3">
            Limpiar filtros
          </a>
        </div>
      </form>
    </div>

    <?php if (!$hasProductClicks && !$hasProductViews): ?>
      <div class="analytics-table-card p-4">
        <div class="admin-empty">Todavía no existen tablas de tracking con datos disponibles.</div>
      </div>
    <?php else: ?>

      <div class="row g-4 mb-4">
        <div class="col-sm-6 col-xl-3">
          <div class="admin-panel admin-stat p-4 h-100">
            <div class="admin-stat-label">Clicks en Ver oferta</div>
            <div class="admin-stat-value"><?= number_format($stats['total_clicks_oferta'], 0, ',', '.') ?></div>
          </div>
        </div>
        <div class="col-sm-6 col-xl-3">
          <div class="admin-panel admin-stat p-4 h-100">
            <div class="admin-stat-label">Clicks en Mejor oferta</div>
            <div class="admin-stat-value"><?= number_format($stats['total_clicks_mejor_oferta'], 0, ',', '.') ?></div>
          </div>
        </div>
        <div class="col-sm-6 col-xl-3">
          <div class="admin-panel admin-stat p-4 h-100">
            <div class="admin-stat-label">Vistas de productos</div>
            <div class="admin-stat-value"><?= number_format($stats['total_vistas'], 0, ',', '.') ?></div>
          </div>
        </div>
        <div class="col-sm-6 col-xl-3">
          <div class="admin-panel admin-stat p-4 h-100">
            <div class="admin-stat-label">Tiendas con actividad</div>
            <div class="admin-stat-value"><?= number_format($stats['total_tiendas_con_datos'], 0, ',', '.') ?></div>
          </div>
        </div>
      </div>

      <div class="analytics-grid mb-4">
        <div class="analytics-span-6">
          <div class="analytics-chart-card p-4 h-100">
            <div class="admin-toolbar mb-3">
              <div>
                <div class="admin-kicker">Gráfico</div>
                <h2 class="h4 fw-bold mb-0">Clicks en Ver oferta por tienda</h2>
              </div>
            </div>
            <div class="analytics-chart-wrap">
              <canvas id="chartOfertas"></canvas>
            </div>
          </div>
        </div>

        <div class="analytics-span-6">
          <div class="analytics-chart-card p-4 h-100">
            <div class="admin-toolbar mb-3">
              <div>
                <div class="admin-kicker">Gráfico</div>
                <h2 class="h4 fw-bold mb-0">Clicks en Mejor oferta por tienda</h2>
              </div>
            </div>
            <div class="analytics-chart-wrap">
              <canvas id="chartMejorOferta"></canvas>
            </div>
          </div>
        </div>

        <div class="analytics-span-12">
          <div class="analytics-chart-card p-4 h-100">
            <div class="admin-toolbar mb-3">
              <div>
                <div class="admin-kicker">Top global</div>
                <h2 class="h4 fw-bold mb-0">Productos más vistos</h2>
              </div>
            </div>
            <div class="analytics-chart-wrap">
              <canvas id="chartTopVistas"></canvas>
            </div>
          </div>
        </div>
      </div>

      <div class="row g-4 mb-4">
        <div class="col-lg-6">
          <div class="analytics-table-card p-4 h-100">
            <div class="admin-toolbar mb-3">
              <div>
                <div class="admin-kicker">Tabla</div>
                <h2 class="h4 fw-bold mb-0">Clicks en Ver oferta por tienda</h2>
              </div>
            </div>

            <?php if ($clicksPorTienda): ?>
              <div class="table-responsive">
                <table class="table align-middle">
                  <thead>
                    <tr>
                      <th>#</th>
                      <th>Tienda</th>
                      <th class="text-end">Clicks</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($clicksPorTienda as $i => $item): ?>
                      <tr>
                        <td><?= $i + 1 ?></td>
                        <td><?= h($item['tie_nombre']) ?></td>
                        <td class="text-end fw-semibold"><?= number_format((int) $item['total_clicks'], 0, ',', '.') ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php else: ?>
              <div class="admin-empty">Todavía no hay clicks en Ver oferta.</div>
            <?php endif; ?>
          </div>
        </div>

        <div class="col-lg-6">
          <div class="analytics-table-card p-4 h-100">
            <div class="admin-toolbar mb-3">
              <div>
                <div class="admin-kicker">Tabla</div>
                <h2 class="h4 fw-bold mb-0">Clicks en Mejor oferta por tienda</h2>
              </div>
            </div>

            <?php if ($mejorOfertaPorTienda): ?>
              <div class="table-responsive">
                <table class="table align-middle">
                  <thead>
                    <tr>
                      <th>#</th>
                      <th>Tienda</th>
                      <th class="text-end">Clicks</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($mejorOfertaPorTienda as $i => $item): ?>
                      <tr>
                        <td><?= $i + 1 ?></td>
                        <td><?= h($item['tie_nombre']) ?></td>
                        <td class="text-end fw-semibold"><?= number_format((int) $item['total_clicks'], 0, ',', '.') ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php else: ?>
              <div class="admin-empty">Todavía no hay clicks en Mejor oferta.</div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="analytics-table-card p-4 mb-4">
        <div class="admin-toolbar mb-3">
          <div>
            <div class="admin-kicker">Detalle</div>
            <h2 class="h4 fw-bold mb-0">Productos más vistos por tienda</h2>
          </div>
        </div>

        <?php if ($productosMasVistosPorTienda): ?>
          <div class="row g-4">
            <?php foreach ($productosMasVistosPorTienda as $store): ?>
              <div class="col-12">
                <div class="analytics-store-block">
                  <h3 class="h5 fw-bold mb-3"><?= h($store['tie_nombre']) ?></h3>

                  <div class="table-responsive">
                    <table class="table align-middle">
                      <thead>
                        <tr>
                          <th>Producto</th>
                          <th class="text-end">Vistas</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach (array_slice($store['items'], 0, 10) as $item): ?>
                          <tr>
                            <td>
                              <div class="d-flex align-items-center gap-3">
                                <img
                                  src="<?= h(image_url($item['pro_imagen'], $item['pro_nombre'])) ?>"
                                  alt="<?= h($item['pro_nombre']) ?>"
                                  class="analytics-thumb"
                                >
                                <div>
                                  <div class="fw-semibold"><?= h($item['pro_nombre']) ?></div>
                                  <div class="small text-body-secondary">ID <?= (int) $item['idproductos'] ?></div>
                                </div>
                              </div>
                            </td>
                            <td class="text-end fw-semibold"><?= number_format((int) $item['total_vistas'], 0, ',', '.') ?></td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="admin-empty">Todavía no hay vistas registradas.</div>
        <?php endif; ?>
      </div>

      <div class="analytics-table-card p-4">
        <div class="admin-toolbar mb-3">
          <div>
            <div class="admin-kicker">Detalle</div>
            <h2 class="h4 fw-bold mb-0">Productos con más clicks de salida por tienda</h2>
          </div>
        </div>

        <?php if ($productosMasClickeadosSalidaPorTienda): ?>
          <div class="row g-4">
            <?php foreach ($productosMasClickeadosSalidaPorTienda as $store): ?>
              <div class="col-12">
                <div class="analytics-store-block">
                  <h3 class="h5 fw-bold mb-3"><?= h($store['tie_nombre']) ?></h3>

                  <div class="table-responsive">
                    <table class="table align-middle">
                      <thead>
                        <tr>
                          <th>Producto</th>
                          <th class="text-end">Clicks</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach (array_slice($store['items'], 0, 10) as $item): ?>
                          <tr>
                            <td>
                              <div class="d-flex align-items-center gap-3">
                                <img
                                  src="<?= h(image_url($item['pro_imagen'], $item['pro_nombre'])) ?>"
                                  alt="<?= h($item['pro_nombre']) ?>"
                                  class="analytics-thumb"
                                >
                                <div>
                                  <div class="fw-semibold"><?= h($item['pro_nombre']) ?></div>
                                  <div class="small text-body-secondary">ID <?= (int) $item['idproductos'] ?></div>
                                </div>
                              </div>
                            </td>
                            <td class="text-end fw-semibold"><?= number_format((int) $item['total_clicks'], 0, ',', '.') ?></td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="admin-empty">Todavía no hay clicks de salida registrados.</div>
        <?php endif; ?>
      </div>

    <?php endif; ?>
  </div>
</section>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
(function () {
  const labelsOfertas = <?= json_encode($chartLabelsOfertas, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const dataOfertas = <?= json_encode($chartDataOfertas, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

  const labelsMejorOferta = <?= json_encode($chartLabelsMejorOferta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const dataMejorOferta = <?= json_encode($chartDataMejorOferta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

  const labelsTopVistas = <?= json_encode($chartLabelsTopVistas, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const dataTopVistas = <?= json_encode($chartDataTopVistas, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

  function buildPalette(total) {
    const base = [
      '#2563eb', '#dc2626', '#16a34a', '#d97706', '#7c3aed',
      '#0891b2', '#db2777', '#65a30d', '#ea580c', '#4f46e5',
      '#0f766e', '#be123c', '#4338ca', '#059669', '#ca8a04'
    ];

    const colors = [];
    for (let i = 0; i < total; i++) {
      colors.push(base[i % base.length]);
    }
    return colors;
  }

  function makeBarChart(canvasId, labels, data, label) {
    const el = document.getElementById(canvasId);
    if (!el || !labels.length) return;

    const colors = buildPalette(labels.length);

    new Chart(el, {
      type: 'bar',
      data: {
        labels,
        datasets: [{
          label,
          data,
          backgroundColor: colors,
          borderColor: colors,
          borderWidth: 1,
          borderRadius: 8
        }]
      },
      options: {
        maintainAspectRatio: false,
        responsive: true,
        plugins: {
          legend: { display: false }
        },
        scales: {
          y: {
            beginAtZero: true,
            ticks: {
              precision: 0
            }
          }
        }
      }
    });
  }

  makeBarChart('chartOfertas', labelsOfertas, dataOfertas, 'Clicks');
  makeBarChart('chartMejorOferta', labelsMejorOferta, dataMejorOferta, 'Clicks');
  makeBarChart('chartTopVistas', labelsTopVistas, dataTopVistas, 'Vistas');
})();
</script>

<?php render_footer(); ?>