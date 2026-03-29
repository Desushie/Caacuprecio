<?php
require_once __DIR__ . '/config.php';
require_admin();

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_product') {
    $id = (int) ($_POST['idproductos'] ?? 0);
    $stmt = $pdo->prepare("
        UPDATE productos
        SET
            pro_nombre = :nombre,
            pro_descripcion = :descripcion,
            pro_marca = :marca,
            pro_precio = :precio,
            pro_precio_anterior = :precio_anterior,
            pro_imagen = :imagen,
            pro_url = :url,
            pro_en_stock = :stock,
            pro_activo = :activo,
            tiendas_idtiendas = :tienda,
            categorias_idcategorias = :categoria
        WHERE idproductos = :id
    ");
    $stmt->execute([
        ':id' => $id,
        ':nombre' => trim((string) ($_POST['pro_nombre'] ?? '')),
        ':descripcion' => trim((string) ($_POST['pro_descripcion'] ?? '')),
        ':marca' => trim((string) ($_POST['pro_marca'] ?? '')),
        ':precio' => (float) ($_POST['pro_precio'] ?? 0),
        ':precio_anterior' => ($_POST['pro_precio_anterior'] ?? '') !== '' ? (float) $_POST['pro_precio_anterior'] : null,
        ':imagen' => trim((string) ($_POST['pro_imagen'] ?? '')),
        ':url' => trim((string) ($_POST['pro_url'] ?? '')),
        ':stock' => (int) ($_POST['pro_en_stock'] ?? 0),
        ':activo' => (int) ($_POST['pro_activo'] ?? 1),
        ':tienda' => (int) ($_POST['tiendas_idtiendas'] ?? 0),
        ':categoria' => ($_POST['categorias_idcategorias'] ?? '') !== '' ? (int) $_POST['categorias_idcategorias'] : null,
    ]);
    header('Location: admin_productos.php?saved=1');
    exit;
}

$q = trim($_GET['q'] ?? '');
$tiendaId = (int) ($_GET['tienda'] ?? 0);
$categoriaId = (int) ($_GET['categoria'] ?? 0);
$estado = $_GET['estado'] ?? '';

$where = ['1=1'];
$params = [];

if ($q !== '') {
    $where[] = '(p.pro_nombre LIKE :q OR p.pro_descripcion LIKE :q OR p.pro_marca LIKE :q)';
    $params[':q'] = '%' . $q . '%';
}

if ($tiendaId > 0) {
    $where[] = 'p.tiendas_idtiendas = :tienda';
    $params[':tienda'] = $tiendaId;
}

if ($categoriaId > 0) {
    $where[] = 'p.categorias_idcategorias = :categoria';
    $params[':categoria'] = $categoriaId;
}

if ($estado === 'activos') {
    $where[] = 'p.pro_activo = 1';
} elseif ($estado === 'inactivos') {
    $where[] = 'p.pro_activo = 0';
}

$whereSql = implode(' AND ', $where);

$sql = "
    SELECT
        p.*,
        t.tie_nombre,
        c.cat_nombre
    FROM productos p
    INNER JOIN tiendas t ON t.idtiendas = p.tiendas_idtiendas
    LEFT JOIN categorias c ON c.idcategorias = p.categorias_idcategorias
    WHERE {$whereSql}
    ORDER BY p.pro_fecha_scraping DESC, p.idproductos DESC
    LIMIT 120
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

$stores = $pdo->query('SELECT idtiendas, tie_nombre FROM tiendas ORDER BY tie_nombre ASC')->fetchAll();
$categories = $pdo->query('SELECT idcategorias, cat_nombre FROM categorias ORDER BY cat_nombre ASC')->fetchAll();

render_head('Administrar productos');
?>
<link rel="stylesheet" href="./css/admin.css">
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
          <div class="admin-kicker mb-2">Productos</div>
          <h1 class="display-6 fw-bold mb-3">Gestión visual de productos</h1>
          <p class="text-body-secondary mb-0">
            Buscá, filtrá y editá productos en modales cómodos, con preview de imagen y campos ordenados.
          </p>
        </div>
        <div class="col-lg-4 position-relative z-1 text-lg-end">
          <span class="admin-badge admin-badge-soft"><?= number_format(count($products), 0, ',', '.') ?> resultado(s)</span>
        </div>
      </div>
    </div>

    <div class="admin-panel p-4 mb-4 admin-filter-bar">
      <form class="row g-3" method="get">
        <div class="col-lg-4">
          <input type="text" name="q" class="form-control" placeholder="Buscar por nombre, descripción o marca" value="<?= e($q) ?>">
        </div>
        <div class="col-sm-6 col-lg-3">
          <select name="tienda" class="form-select">
            <option value="0">Todas las tiendas</option>
            <?php foreach ($stores as $store): ?>
              <option value="<?= (int) $store['idtiendas'] ?>" <?= $tiendaId === (int) $store['idtiendas'] ? 'selected' : '' ?>>
                <?= e($store['tie_nombre']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-sm-6 col-lg-3">
          <select name="categoria" class="form-select">
            <option value="0">Todas las categorías</option>
            <?php foreach ($categories as $cat): ?>
              <option value="<?= (int) $cat['idcategorias'] ?>" <?= $categoriaId === (int) $cat['idcategorias'] ? 'selected' : '' ?>>
                <?= e($cat['cat_nombre']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-sm-6 col-lg-1">
          <select name="estado" class="form-select">
            <option value="">Todos</option>
            <option value="activos" <?= $estado === 'activos' ? 'selected' : '' ?>>Act.</option>
            <option value="inactivos" <?= $estado === 'inactivos' ? 'selected' : '' ?>>Inact.</option>
          </select>
        </div>
        <div class="col-sm-6 col-lg-1 d-grid">
          <button class="btn btn-primary" type="submit"><i class="bi bi-search"></i></button>
        </div>
      </form>
    </div>

    <div class="admin-table-card overflow-hidden">
      <div class="table-responsive">
        <table class="table admin-table align-middle mb-0">
          <thead>
            <tr>
              <th>Producto</th>
              <th>Tienda</th>
              <th>Categoría</th>
              <th>Precio</th>
              <th>Estado</th>
              <th>Stock</th>
              <th>Actualizado</th>
              <th class="text-end">Acciones</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($products): ?>
              <?php foreach ($products as $product): ?>
                <tr>
                  <td style="min-width:320px;">
                    <div class="d-flex gap-3 align-items-center">
                      <img class="thumb" src="<?= e(image_url($product['pro_imagen'], $product['pro_nombre'])) ?>" alt="<?= e($product['pro_nombre']) ?>">
                      <div>
                        <div class="title"><?= e($product['pro_nombre']) ?></div>
                        <div class="subtitle"><?= e($product['pro_marca'] ?: 'Sin marca') ?></div>
                      </div>
                    </div>
                  </td>
                  <td><?= e($product['tie_nombre']) ?></td>
                  <td><?= e($product['cat_nombre'] ?? 'Sin categoría') ?></td>
                  <td><?= gs($product['pro_precio']) ?></td>
                  <td>
                    <span class="mini-badge <?= (int) $product['pro_activo'] === 1 ? 'badge-stock-ok' : 'badge-stock-no' ?>">
                      <?= (int) $product['pro_activo'] === 1 ? 'Activo' : 'Inactivo' ?>
                    </span>
                  </td>
                  <td>
                    <span class="mini-badge <?= e(stock_badge_class($product['pro_en_stock'])) ?>">
                      <?= e(stock_label($product['pro_en_stock'])) ?>
                    </span>
                  </td>
                  <td><?= e(date('d/m/Y H:i', strtotime((string) $product['pro_fecha_scraping']))) ?></td>
                  <td class="text-end">
                    <button
                      type="button"
                      class="btn btn-sm btn-outline-primary rounded-pill"
                      data-bs-toggle="modal"
                      data-bs-target="#productModal<?= (int) $product['idproductos'] ?>"
                    >
                      <i class="bi bi-pencil-square me-1"></i>Editar
                    </button>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr>
                <td colspan="8">
                  <div class="admin-empty">No se encontraron productos con esos filtros.</div>
                </td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</section>

<?php if ($products): ?>
  <?php foreach ($products as $product): ?>
    <div class="modal fade admin-modal" id="productModal<?= (int) $product['idproductos'] ?>" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
          <form method="post">
            <input type="hidden" name="action" value="save_product">
            <input type="hidden" name="idproductos" value="<?= (int) $product['idproductos'] ?>">

            <div class="modal-header">
              <div>
                <div class="admin-kicker mb-1">Editar producto</div>
                <h5 class="modal-title mb-0"><?= e($product['pro_nombre']) ?></h5>
              </div>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>

            <div class="modal-body">
              <div class="row g-4">
                <div class="col-lg-8">
                  <div class="row g-3">
                    <div class="col-12">
                      <label class="form-label">Nombre</label>
                      <input type="text" name="pro_nombre" class="form-control" value="<?= e($product['pro_nombre']) ?>" required>
                    </div>
                    <div class="col-md-6">
                      <label class="form-label">Marca</label>
                      <input type="text" name="pro_marca" class="form-control" value="<?= e($product['pro_marca']) ?>">
                    </div>
                    <div class="col-md-6">
                      <label class="form-label">Imagen (URL)</label>
                      <input type="text" name="pro_imagen" class="form-control js-image-input" value="<?= e($product['pro_imagen']) ?>">
                    </div>
                    <div class="col-12">
                      <label class="form-label">Descripción</label>
                      <textarea name="pro_descripcion" class="form-control" rows="5"><?= e($product['pro_descripcion']) ?></textarea>
                    </div>
                    <div class="col-md-4">
                      <label class="form-label">Precio</label>
                      <input type="number" step="0.01" name="pro_precio" class="form-control" value="<?= e((string) $product['pro_precio']) ?>" required>
                    </div>
                    <div class="col-md-4">
                      <label class="form-label">Precio anterior</label>
                      <input type="number" step="0.01" name="pro_precio_anterior" class="form-control" value="<?= e((string) $product['pro_precio_anterior']) ?>">
                    </div>
                    <div class="col-md-4">
                      <label class="form-label">URL del producto</label>
                      <input type="text" name="pro_url" class="form-control" value="<?= e($product['pro_url']) ?>">
                    </div>
                    <div class="col-md-4">
                      <label class="form-label">Tienda</label>
                      <select name="tiendas_idtiendas" class="form-select" required>
                        <?php foreach ($stores as $store): ?>
                          <option value="<?= (int) $store['idtiendas'] ?>" <?= (int) $product['tiendas_idtiendas'] === (int) $store['idtiendas'] ? 'selected' : '' ?>>
                            <?= e($store['tie_nombre']) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="col-md-4">
                      <label class="form-label">Categoría</label>
                      <select name="categorias_idcategorias" class="form-select">
                        <option value="">Sin categoría</option>
                        <?php foreach ($categories as $cat): ?>
                          <option value="<?= (int) $cat['idcategorias'] ?>" <?= (int) $product['categorias_idcategorias'] === (int) $cat['idcategorias'] ? 'selected' : '' ?>>
                            <?= e($cat['cat_nombre']) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="col-md-2">
                      <label class="form-label">Stock</label>
                      <select name="pro_en_stock" class="form-select">
                        <option value="1" <?= (int) $product['pro_en_stock'] === 1 ? 'selected' : '' ?>>Sí</option>
                        <option value="0" <?= (int) $product['pro_en_stock'] === 0 ? 'selected' : '' ?>>No</option>
                      </select>
                    </div>
                    <div class="col-md-2">
                      <label class="form-label">Activo</label>
                      <select name="pro_activo" class="form-select">
                        <option value="1" <?= (int) $product['pro_activo'] === 1 ? 'selected' : '' ?>>Sí</option>
                        <option value="0" <?= (int) $product['pro_activo'] === 0 ? 'selected' : '' ?>>No</option>
                      </select>
                    </div>
                  </div>
                </div>

                <div class="col-lg-4">
                  <div class="preview-box mb-3">
                    <img
                      src="<?= e(image_url($product['pro_imagen'], $product['pro_nombre'])) ?>"
                      alt="<?= e($product['pro_nombre']) ?>"
                      class="js-image-preview"
                    >
                  </div>
                  <div class="small text-body-secondary">
                    Usá una URL de imagen válida para previsualizar cómo quedará la ficha del producto.
                  </div>
                </div>
              </div>
            </div>

            <div class="modal-footer">
              <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancelar</button>
              <button type="submit" class="btn btn-primary rounded-pill px-4">Guardar cambios</button>
            </div>
          </form>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<script>
document.querySelectorAll('.admin-modal').forEach(function(modal) {
  const input = modal.querySelector('.js-image-input');
  const preview = modal.querySelector('.js-image-preview');
  if (!input || !preview) return;

  input.addEventListener('input', function() {
    const value = input.value.trim();
    if (value) {
      preview.src = value;
    }
  });
});
</script>

<?php render_footer(); ?>
