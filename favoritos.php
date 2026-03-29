<?php
require_once __DIR__ . '/config.php';

require_login();

$pdo = db();
$user = current_user();
$userId = current_user_id();

$qtyInput = $_POST['qty'] ?? [];
$qtyMap = [];
foreach ($qtyInput as $productId => $qty) {
    $qtyMap[(int) $productId] = max(1, min(999, (int) $qty));
}

$stmt = $pdo->prepare("
    SELECT
        p.idproductos,
        p.pro_nombre,
        p.pro_descripcion,
        p.pro_precio,
        p.pro_precio_anterior,
        p.pro_imagen,
        p.pro_en_stock,
        p.pro_url,
        p.pro_fecha_scraping,
        t.idtiendas,
        t.tie_nombre,
        c.cat_nombre
    FROM favoritos f
    INNER JOIN productos p ON p.idproductos = f.productos_idproductos
    INNER JOIN tiendas t ON t.idtiendas = p.tiendas_idtiendas
    LEFT JOIN categorias c ON c.idcategorias = p.categorias_idcategorias
    WHERE f.usuario_idusuario = :uid
      AND p.pro_activo = 1
    ORDER BY p.pro_nombre ASC
");
$stmt->execute([':uid' => $userId]);
$items = $stmt->fetchAll();

$totalItems = count($items);
$subtotal = 0.0;
$totalQty = 0;

foreach ($items as &$item) {
    $itemId = (int) $item['idproductos'];
    $qty = $qtyMap[$itemId] ?? 1;
    $item['qty'] = $qty;
    $item['line_total'] = (float) $item['pro_precio'] * $qty;
    $subtotal += $item['line_total'];
    $totalQty += $qty;
}
unset($item);

render_head('Favoritos');
render_navbar('favoritos');
?>
<section class="page-section py-5">
  <div class="container">
    <nav aria-label="breadcrumb" class="mb-4 no-print">
      <ol class="breadcrumb custom-breadcrumb">
        <li class="breadcrumb-item"><a href="index.php">Inicio</a></li>
        <li class="breadcrumb-item active" aria-current="page">Favoritos</li>
      </ol>
    </nav>

    <div class="detail-card p-4 p-lg-5 mb-4">
      <div class="row g-4 align-items-center">
        <div class="col-lg-8">
          <span class="badge soft-badge mb-3">Lista personal</span>
          <h1 class="display-6 fw-bold mb-2">Favoritos y calculadora</h1>
          <p class="text-body-secondary mb-0">
            Acá podés guardar productos favoritos, calcular cantidades, ver tienda, precio y luego imprimir o guardar como PDF desde el navegador.
          </p>
        </div>
        <div class="col-lg-4">
          <div class="row g-3">
            <div class="col-6">
              <div class="stats-card p-3 h-100">
                <div class="small text-body-secondary mb-1">Productos</div>
                <div class="fw-bold fs-4"><?= number_format($totalItems, 0, ',', '.') ?></div>
              </div>
            </div>
            <div class="col-6">
              <div class="stats-card p-3 h-100">
                <div class="small text-body-secondary mb-1">Cantidades</div>
                <div class="fw-bold fs-4"><?= number_format($totalQty, 0, ',', '.') ?></div>
              </div>
            </div>
            <div class="col-12">
              <div class="stats-card p-3 h-100">
                <div class="small text-body-secondary mb-1">Usuario</div>
                <div class="fw-bold"><?= e((string) ($user['usu_nombre'] ?? 'Usuario')) ?></div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <?php if ($items): ?>
      <form method="post" action="favoritos.php">
        <div class="detail-card p-4 mb-4 no-print">
          <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
            <div>
              <h2 class="h4 fw-bold mb-1">Ajustes de cálculo</h2>
              <p class="text-body-secondary mb-0">Modificá las cantidades y recalculá el total.</p>
            </div>
            <div class="d-flex flex-wrap gap-2">
              <button type="submit" class="btn btn-primary rounded-pill px-4">
                <i class="bi bi-calculator me-2"></i>Recalcular
              </button>
              <button type="submit" class="btn btn-outline-primary rounded-pill px-4" formaction="favoritos_print.php" formtarget="_blank">
                <i class="bi bi-printer me-2"></i>Imprimir / PDF
              </button>
            </div>
          </div>
        </div>

        <div class="detail-card p-0 overflow-hidden mb-4 print-table-card">
          <div class="table-responsive print-table-wrap">
            <table class="table align-middle mb-0 custom-table fav-table">
              <thead>
                <tr>
                  <th>Producto</th>
                  <th>Tienda</th>
                  <th class="text-end">Precio</th>
                  <th class="text-center no-print">Cantidad</th>
                  <th class="text-end">Subtotal</th>
                  <th class="no-print"></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($items as $item): ?>
                  <tr>
                    <td style="min-width: 340px;">
                      <div class="d-flex gap-3 align-items-center">
                        <img src="<?= e(image_url($item['pro_imagen'], $item['pro_nombre'])) ?>" alt="<?= e($item['pro_nombre']) ?>" class="related-thumb related-thumb-lg">
                        <div>
                          <div class="fw-semibold"><?= e($item['pro_nombre']) ?></div>
                          <div class="small text-body-secondary"><?= e($item['cat_nombre'] ?? 'Sin categoría') ?></div>
                          <div class="small text-body-secondary">Actualizado: <?= e(date('d/m/Y H:i', strtotime((string) $item['pro_fecha_scraping']))) ?></div>
                        </div>
                      </div>
                    </td>
                    <td>
                      <a href="tienda.php?id=<?= (int) $item['idtiendas'] ?>" class="text-decoration-none fw-semibold"><?= e($item['tie_nombre']) ?></a>
                    </td>
                    <td class="text-end fw-semibold"><?= gs($item['pro_precio']) ?></td>
                    <td class="text-center no-print" style="min-width: 120px;">
                      <input
                        type="number"
                        min="1"
                        max="999"
                        name="qty[<?= (int) $item['idproductos'] ?>]"
                        value="<?= (int) $item['qty'] ?>"
                        class="form-control text-center"
                      >
                    </td>
                    <td class="text-end fw-bold"><?= gs($item['line_total']) ?></td>
                    <td class="text-end no-print" style="min-width: 210px;">
                      <div class="d-flex gap-2 justify-content-end">
                        <a href="producto.php?id=<?= (int) $item['idproductos'] ?>" class="btn btn-sm btn-outline-primary rounded-pill px-3">Ver</a>
                        <a href="<?= e(favorite_toggle_url((int) $item['idproductos'], 'favoritos.php')) ?>" class="btn btn-sm btn-outline-danger rounded-pill px-3">Quitar</a>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
              <tfoot>
                <tr>
                  <th colspan="3" class="text-end">Total general</th>
                  <th class="text-center no-print"><?= number_format($totalQty, 0, ',', '.') ?></th>
                  <th class="text-end"><?= gs($subtotal) ?></th>
                  <th class="no-print"></th>
                </tr>
              </tfoot>
            </table>
          </div>
        </div>
      </form>

      <div class="detail-card p-4 print-summary">
        <div class="row g-3 align-items-center">
          <div class="col-md-4">
            <div class="info-box">
              <small class="text-body-secondary d-block mb-1">Cliente</small>
              <span class="fw-semibold"><?= e((string) ($user['usu_nombre'] ?? 'Usuario')) ?></span>
            </div>
          </div>
          <div class="col-md-4">
            <div class="info-box">
              <small class="text-body-secondary d-block mb-1">Fecha</small>
              <span class="fw-semibold"><?= e(date('d/m/Y H:i')) ?></span>
            </div>
          </div>
          <div class="col-md-4">
            <div class="info-box">
              <small class="text-body-secondary d-block mb-1">Total estimado</small>
              <span class="fw-semibold fs-5"><?= gs($subtotal) ?></span>
            </div>
          </div>
        </div>
      </div>
    <?php else: ?>
      <div class="detail-card p-5 text-center">
        <div class="icon-wrap mx-auto mb-3"><i class="bi bi-heart"></i></div>
        <h2 class="h4 fw-bold mb-2">Todavía no tenés favoritos</h2>
        <p class="text-body-secondary mb-4">Podés agregarlos desde la ficha del producto o usando el enlace de guardar favorito.</p>
        <a href="index.php#productos" class="btn btn-primary rounded-pill px-4">Explorar productos</a>
      </div>
    <?php endif; ?>
  </div>
</section>
<?php render_footer(); ?>
