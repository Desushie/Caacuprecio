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
        p.pro_precio,
        p.pro_imagen,
        p.pro_fecha_scraping,
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
$totalQty = 0;
$subtotal = 0.0;

foreach ($items as &$item) {
    $itemId = (int) $item['idproductos'];
    $qty = $qtyMap[$itemId] ?? 1;
    $item['qty'] = $qty;
    $item['line_total'] = (float) $item['pro_precio'] * $qty;
    $subtotal += $item['line_total'];
    $totalQty += $qty;
}
unset($item);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Favoritos para imprimir</title>
  <style>
    :root{
      --border:#d7dce5;
      --text:#111827;
      --muted:#6b7280;
      --bg:#ffffff;
      --soft:#f8fafc;
    }
    *{box-sizing:border-box}
    body{
      margin:0;
      font-family: Arial, Helvetica, sans-serif;
      color:var(--text);
      background:var(--bg);
      padding:24px;
    }
    .sheet{
      max-width:1100px;
      margin:0 auto;
    }
    .header{
      display:flex;
      justify-content:space-between;
      align-items:flex-start;
      gap:24px;
      border-bottom:2px solid var(--border);
      padding-bottom:18px;
      margin-bottom:18px;
    }
    .brand{
      font-size:28px;
      font-weight:700;
      margin:0 0 6px;
    }
    .sub{
      color:var(--muted);
      margin:0;
      line-height:1.5;
    }
    .meta{
      text-align:right;
      min-width:240px;
      font-size:14px;
    }
    .meta div{margin-bottom:6px}
    .summary{
      display:grid;
      grid-template-columns:repeat(3,1fr);
      gap:12px;
      margin:16px 0 22px;
    }
    .box{
      border:1px solid var(--border);
      background:var(--soft);
      padding:12px 14px;
      border-radius:10px;
    }
    .box small{
      display:block;
      color:var(--muted);
      margin-bottom:6px;
    }
    .box strong{
      font-size:18px;
    }
    table{
      width:100%;
      border-collapse:collapse;
    }
    thead th{
      background:#eef2f7;
      border:1px solid var(--border);
      padding:10px 12px;
      text-align:left;
      font-size:13px;
    }
    tbody td, tfoot td{
      border:1px solid var(--border);
      padding:10px 12px;
      vertical-align:top;
      font-size:14px;
    }
    .product{
      display:flex;
      gap:12px;
      align-items:flex-start;
    }
    .thumb{
      width:58px;
      height:58px;
      object-fit:cover;
      border:1px solid var(--border);
      border-radius:8px;
      background:#fff;
      flex:0 0 58px;
    }
    .name{
      font-weight:700;
      margin-bottom:4px;
    }
    .meta-line{
      color:var(--muted);
      font-size:12px;
      line-height:1.4;
    }
    .text-end{text-align:right}
    tfoot td{
      font-weight:700;
      background:#f8fafc;
    }
    .actions{
      display:flex;
      justify-content:flex-end;
      gap:10px;
      margin-bottom:18px;
    }
    .btn{
      border:1px solid #cbd5e1;
      background:#fff;
      color:#111827;
      border-radius:999px;
      padding:10px 16px;
      cursor:pointer;
      font-size:14px;
    }
    .btn-primary{
      background:#111827;
      color:#fff;
      border-color:#111827;
    }
    .empty{
      border:1px dashed var(--border);
      padding:24px;
      text-align:center;
      border-radius:12px;
      color:var(--muted);
    }
    @media print{
      body{padding:0}
      .actions{display:none}
      .sheet{max-width:none}
      @page{size:auto; margin:12mm}
    }
  </style>
</head>
<body>
  <div class="sheet">
    <div class="actions">
      <button class="btn" onclick="window.close()">Cerrar</button>
      <button class="btn btn-primary" onclick="window.print()">Imprimir / Guardar PDF</button>
    </div>

    <header class="header">
      <div>
        <h1 class="brand">Caacuprecio</h1>
        <p class="sub">Lista de favoritos para comparar y cotizar.</p>
      </div>
      <div class="meta">
        <div><strong>Cliente:</strong> <?= e((string) ($user['usu_nombre'] ?? 'Usuario')) ?></div>
        <div><strong>Fecha:</strong> <?= e(date('d/m/Y H:i')) ?></div>
        <div><strong>Total estimado:</strong> <?= gs($subtotal) ?></div>
      </div>
    </header>

    <section class="summary">
      <div class="box">
        <small>Productos</small>
        <strong><?= number_format($totalItems, 0, ',', '.') ?></strong>
      </div>
      <div class="box">
        <small>Cantidades</small>
        <strong><?= number_format($totalQty, 0, ',', '.') ?></strong>
      </div>
      <div class="box">
        <small>Total</small>
        <strong><?= gs($subtotal) ?></strong>
      </div>
    </section>

    <?php if ($items): ?>
      <table>
        <thead>
          <tr>
            <th>Producto</th>
            <th>Tienda</th>
            <th class="text-end">Precio</th>
            <th class="text-end">Cantidad</th>
            <th class="text-end">Subtotal</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($items as $item): ?>
            <tr>
              <td>
                <div class="product">
                  <img class="thumb" src="<?= e(image_url($item['pro_imagen'], $item['pro_nombre'])) ?>" alt="<?= e($item['pro_nombre']) ?>">
                  <div>
                    <div class="name"><?= e($item['pro_nombre']) ?></div>
                    <div class="meta-line"><?= e($item['cat_nombre'] ?? 'Sin categoría') ?></div>
                    <div class="meta-line">Actualizado: <?= e(date('d/m/Y H:i', strtotime((string) $item['pro_fecha_scraping']))) ?></div>
                  </div>
                </div>
              </td>
              <td><?= e($item['tie_nombre']) ?></td>
              <td class="text-end"><?= gs($item['pro_precio']) ?></td>
              <td class="text-end"><?= number_format((int) $item['qty'], 0, ',', '.') ?></td>
              <td class="text-end"><?= gs($item['line_total']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <td colspan="3" class="text-end">Total general</td>
            <td class="text-end"><?= number_format($totalQty, 0, ',', '.') ?></td>
            <td class="text-end"><?= gs($subtotal) ?></td>
          </tr>
        </tfoot>
      </table>
    <?php else: ?>
      <div class="empty">No hay productos favoritos para imprimir.</div>
    <?php endif; ?>
  </div>
</body>
</html>
