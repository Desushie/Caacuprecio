<?php
/** Fragmento del listado — partes: caption | results | modals */
$apListParts = $apListParts ?? ['caption', 'results', 'modals'];
$apWant = static function (string $part) use ($apListParts): bool {
    return in_array($part, $apListParts, true);
};
?>
<?php if ($apWant('caption')): ?>
<p class="dash-panel-caption" data-ap-slot="caption">
  <?php if ($totalProducts > 0): ?>
    Mostrando <?= $fmt($fromItem) ?>–<?= $fmt($toItem) ?> de <?= $fmt($totalProducts) ?> · <?= e(AP_ORDENES[$orden]) ?>
  <?php else: ?>
    Ningún producto coincide con la búsqueda
  <?php endif; ?>
</p>
<?php endif; ?>

<?php if ($apWant('results')): ?>
<div data-ap-slot="results">
  <?php if ($products): ?>
    <div class="ap-table">
      <div class="ap-cols" aria-hidden="true">
        <span></span>
        <span>Producto</span>
        <span>Precio</span>
        <span>Estado</span>
        <span>Actualizado</span>
        <span>Acciones</span>
      </div>

      <ul class="ap-rows">
        <?php foreach ($products as $product): ?>
          <?php
            $productId = (int) $product['idproductos'];
            $price = ($product['pro_precio'] !== null && (float) $product['pro_precio'] > 0) ? (float) $product['pro_precio'] : null;
            $old = $product['pro_precio_anterior'] !== null ? (float) $product['pro_precio_anterior'] : null;
            $off = ($price !== null && $old !== null && $old > $price) ? (int) round((1 - $price / $old) * 100) : 0;
            $rise = ($price !== null && $old !== null && $old > 0 && $price > $old) ? (int) round(($price / $old - 1) * 100) : 0;
            $isActive = (int) $product['pro_activo'] === 1;
            $inStock = (int) $product['pro_en_stock'] === 1;
            $brand = trim((string) ($product['pro_marca'] ?? ''));
            $category = trim((string) ($product['cat_nombre'] ?? ''));
            $externalUrl = trim((string) ($product['pro_url'] ?? ''));
            $updated = $product['pro_fecha_scraping'] ?? null;
          ?>
          <li class="ap-row <?= $isActive ? '' : 'is-hidden' ?>">
            <span class="ap-thumb">
              <img
                src="<?= e(image_url($product['pro_imagen'], $product['pro_nombre'])) ?>"
                alt=""
                class="dash-thumb ap-thumb-img"
                loading="lazy"
                decoding="async"
              >
              <?php if ($off > 0): ?>
                <span class="ap-thumb-deal">−<?= $off ?>%</span>
              <?php endif; ?>
            </span>

            <div class="ap-prod">
              <a href="producto.php?id=<?= $productId ?>" class="ap-name" title="<?= e($product['pro_nombre']) ?>"><?= e($product['pro_nombre']) ?></a>
              <div class="ap-meta">
                <span class="ap-meta-id">#<?= $productId ?></span>
                <span class="ap-meta-item" title="Marca"><?= lucide_icon('tag', '', 12) ?><span><?= e($brand !== '' ? $brand : 'Sin marca') ?></span></span>
                <?php if (!$isEmpresa): ?>
                  <span class="ap-meta-item" title="Tienda"><?= lucide_icon('store', '', 12) ?><span><?= e($product['tie_nombre']) ?></span></span>
                <?php endif; ?>
                <span class="ap-meta-item <?= $category === '' ? 'is-warn' : '' ?>" title="Categoría"><?= lucide_icon('folder', '', 12) ?><span><?= e($category !== '' ? $category : 'Sin categoría') ?></span></span>
              </div>
            </div>

            <div class="ap-price">
              <?php if ($price !== null): ?>
                <strong><?= gs($price) ?></strong>
              <?php else: ?>
                <span class="ap-pill is-alt"><?= lucide_icon('banknote-x', '', 12) ?>Sin precio</span>
              <?php endif; ?>
              <?php if ($off > 0): ?>
                <span><s><?= gs($old) ?></s><em class="dash-off">−<?= $off ?>%</em></span>
              <?php elseif ($rise > 0): ?>
                <span><s><?= gs($old) ?></s><em class="dash-off is-up">+<?= $rise ?>%</em></span>
              <?php else: ?>
                <span class="ap-price-steady">Precio estable</span>
              <?php endif; ?>
            </div>

            <div class="ap-status">
              <span class="ap-pill <?= $isActive ? 'is-good' : 'is-bad' ?>">
                <?= lucide_icon($isActive ? 'eye' : 'eye-off', '', 12) ?><?= $isActive ? 'Publicado' : 'Oculto' ?>
              </span>
              <span class="ap-pill <?= $inStock ? 'is-good' : 'is-mid' ?>">
                <?= lucide_icon($inStock ? 'package-check' : 'package-search', '', 12) ?><?= e(stock_label($product['pro_en_stock'])) ?>
              </span>
            </div>

            <div class="ap-time">
              <strong><?= e(ucfirst(ap_time_ago($updated))) ?></strong>
              <?php if (!empty($updated)): ?>
                <span><?= e(date('d/m/Y H:i', strtotime((string) $updated))) ?></span>
              <?php endif; ?>
            </div>

            <div class="ap-actions">
              <a href="producto.php?id=<?= $productId ?>" class="dash-icon-btn" title="Ver ficha pública" aria-label="Ver ficha de <?= e($product['pro_nombre']) ?>">
                <?= lucide_icon('eye', '', 15) ?>
              </a>
              <?php if ($externalUrl !== ''): ?>
                <a href="<?= e($externalUrl) ?>" class="dash-icon-btn" target="_blank" rel="noopener" title="Abrir en la web de la tienda" aria-label="Abrir <?= e($product['pro_nombre']) ?> en la web de la tienda">
                  <?= lucide_icon('external-link', '', 15) ?>
                </a>
              <?php endif; ?>
              <button
                type="button"
                class="ap-edit"
                data-bs-toggle="modal"
                data-bs-target="#productModal<?= $productId ?>"
                aria-label="Editar <?= e($product['pro_nombre']) ?>"
              >
                <?= lucide_icon('square-pen', '', 15) ?><span>Editar</span>
              </button>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>

    <?php if ($totalPages > 1): ?>
      <nav class="ap-pager" aria-label="Paginación de productos" data-ap-slot="pager">
        <span class="ap-pager-info">Página <strong><?= $fmt($page) ?></strong> de <?= $fmt($totalPages) ?></span>
        <div class="ap-pages">
          <a
            class="ap-page-btn <?= $page <= 1 ? 'is-disabled' : '' ?>"
            href="<?= e(build_admin_productos_url(['page' => max(1, $page - 1)])) ?>"
            aria-label="Página anterior"
            <?= $page <= 1 ? 'aria-disabled="true" tabindex="-1"' : '' ?>
          ><?= lucide_icon('chevron-left', '', 15) ?></a>
          <?php foreach ($pageItems as $item): ?>
            <?php if ($item === null): ?>
              <span class="ap-page-gap" aria-hidden="true">…</span>
            <?php else: ?>
              <a
                class="ap-page-btn <?= $item === $page ? 'is-current' : '' ?>"
                href="<?= e(build_admin_productos_url(['page' => $item])) ?>"
                <?= $item === $page ? 'aria-current="page"' : '' ?>
              ><?= $fmt($item) ?></a>
            <?php endif; ?>
          <?php endforeach; ?>
          <a
            class="ap-page-btn <?= $page >= $totalPages ? 'is-disabled' : '' ?>"
            href="<?= e(build_admin_productos_url(['page' => min($totalPages, $page + 1)])) ?>"
            aria-label="Página siguiente"
            <?= $page >= $totalPages ? 'aria-disabled="true" tabindex="-1"' : '' ?>
          ><?= lucide_icon('chevron-right', '', 15) ?></a>
        </div>
      </nav>
    <?php endif; ?>
  <?php else: ?>
    <div class="ap-empty" role="status">
      <span class="ap-empty-icon"><?= lucide_icon('search-x', '', 20) ?></span>
      <strong class="ap-empty-title">
        <?php if ($q !== ''): ?>
          Sin resultados para <span class="ap-empty-term">“<?= e($q) ?>”</span>
        <?php else: ?>
          No encontramos productos
        <?php endif; ?>
      </strong>
      <p class="ap-empty-text"><?= $activeFilters
        ? 'Revisa la ortografía, prueba con otras palabras o quita algunos filtros para ampliar la búsqueda.'
        : 'Revisa la ortografía o prueba con otras palabras para ampliar la búsqueda.' ?></p>
      <?php if ($activeFilters || $q !== ''): ?>
        <div class="ap-empty-actions">
          <?php if ($activeFilters): ?>
            <a href="<?= e(admin_productos_url_reset_filters($q)) ?>" class="ap-empty-btn">
              <?= lucide_icon('rotate-ccw', '', 14) ?>Limpiar filtros
            </a>
          <?php endif; ?>
          <?php if ($q !== ''): ?>
            <a href="<?= e(build_admin_productos_url(['q' => '', 'page' => 1])) ?>" class="ap-empty-btn is-ghost">
              <?= lucide_icon('x', '', 14) ?>Borrar búsqueda
            </a>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($apWant('modals')): ?>
<div data-ap-slot="modals">
<?php if ($modalProducts): ?>
  <?php foreach ($modalProducts as $product): ?>
    <?php
      $mId = (int) $product['idproductos'];
      $mName = (string) $product['pro_nombre'];
      $mBrand = trim((string) ($product['pro_marca'] ?? ''));
      $mCategory = trim((string) ($product['cat_nombre'] ?? ''));
      $mImage = image_url($product['pro_imagen'], $mName);
      $mUrl = trim((string) ($product['pro_url'] ?? ''));
      $mPrice = ($product['pro_precio'] ?? '') !== '' && $product['pro_precio'] !== null ? (string) round((float) $product['pro_precio']) : '';
      $mOld = ($product['pro_precio_anterior'] ?? '') !== '' && $product['pro_precio_anterior'] !== null ? (string) round((float) $product['pro_precio_anterior']) : '';
      $mOff = ($mPrice !== '' && $mOld !== '' && (float) $mOld > (float) $mPrice && (float) $mPrice > 0) ? (int) round((1 - (float) $mPrice / (float) $mOld) * 100) : 0;
      $mActive = (int) $product['pro_activo'] === 1;
      $mStock = (int) $product['pro_en_stock'] === 1;
      $fid = 'pe' . $mId;
    ?>
    <div class="modal fade admin-modal pe-modal" id="productModal<?= $mId ?>" tabindex="-1" aria-labelledby="<?= $fid ?>-title" aria-hidden="true">
      <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <form method="post" class="modal-content pe-content" data-pe-form>
          <input type="hidden" name="action" value="save_product">
          <input type="hidden" name="idproductos" value="<?= $mId ?>">
          <input type="hidden" name="return_query" value="<?= e($returnQuery) ?>">

          <div class="modal-header pe-header">
            <div class="pe-heading">
              <span class="pe-heading-icon" aria-hidden="true"><?= lucide_icon('square-pen', '', 18) ?></span>
              <div class="pe-heading-text">
                <span class="pe-kicker">Editar producto <span class="pe-kicker-id">#<?= $mId ?></span></span>
                <h2 class="modal-title pe-title" id="<?= $fid ?>-title" title="<?= e($mName) ?>"><?= e($mName) ?></h2>
              </div>
            </div>
            <button type="button" class="pe-close" data-bs-dismiss="modal" aria-label="Cerrar"><?= lucide_icon('x', '', 18) ?></button>
          </div>

          <div class="modal-body pe-body">
            <div class="pe-layout">
              <div class="pe-main">
                <section class="pe-group is-info">
                  <header class="pe-group-head">
                    <span class="pe-group-icon" aria-hidden="true"><?= lucide_icon('file-text', '', 14) ?></span>
                    <div class="pe-group-copy">
                      <h3 class="pe-group-title">Información básica</h3>
                      <p class="pe-group-hint">Lo que ve el usuario en el buscador.</p>
                    </div>
                  </header>
                  <div class="pe-field">
                    <label class="form-label" for="<?= $fid ?>-nombre">Nombre <span class="pe-req" aria-hidden="true">*</span></label>
                    <input type="text" id="<?= $fid ?>-nombre" name="pro_nombre" class="form-control" value="<?= e($mName) ?>" required data-pe-sync="name">
                  </div>
                  <div class="pe-row">
                    <div class="pe-field">
                      <label class="form-label" for="<?= $fid ?>-marca">Marca</label>
                      <input type="text" id="<?= $fid ?>-marca" name="pro_marca" class="form-control" value="<?= e($mBrand) ?>" data-pe-sync="brand">
                    </div>
                    <div class="pe-field">
                      <label class="form-label" for="<?= $fid ?>-cat">Categoría</label>
                      <select id="<?= $fid ?>-cat" name="categorias_idcategorias" class="form-select" data-pe-sync="cat">
                        <option value="">Sin categoría</option>
                        <?php foreach ($categories as $cat): ?>
                          <option value="<?= (int) $cat['idcategorias'] ?>" <?= (int) $product['categorias_idcategorias'] === (int) $cat['idcategorias'] ? 'selected' : '' ?>><?= e($cat['cat_nombre']) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                  </div>
                  <div class="pe-field">
                    <label class="form-label" for="<?= $fid ?>-tienda">Tienda<?php if (!$isEmpresa): ?> <span class="pe-req" aria-hidden="true">*</span><?php endif; ?></label>
                    <?php if ($isEmpresa): ?>
                      <input type="text" id="<?= $fid ?>-tienda" class="form-control" value="<?= e($product['tie_nombre']) ?>" readonly>
                      <input type="hidden" name="tiendas_idtiendas" value="<?= (int) $myStoreId ?>">
                    <?php else: ?>
                      <select id="<?= $fid ?>-tienda" name="tiendas_idtiendas" class="form-select" required>
                        <?php foreach ($stores as $store): ?>
                          <option value="<?= (int) $store['idtiendas'] ?>" <?= (int) $product['tiendas_idtiendas'] === (int) $store['idtiendas'] ? 'selected' : '' ?>><?= e($store['tie_nombre']) ?></option>
                        <?php endforeach; ?>
                      </select>
                    <?php endif; ?>
                  </div>
                  <div class="pe-field is-grow">
                    <label class="form-label" for="<?= $fid ?>-desc">Descripción</label>
                    <textarea id="<?= $fid ?>-desc" name="pro_descripcion" class="form-control" rows="3" placeholder="Características principales del producto"><?= e($product['pro_descripcion']) ?></textarea>
                  </div>
                </section>

                <div class="pe-stack">
                  <section class="pe-group is-price">
                    <header class="pe-group-head">
                      <span class="pe-group-icon" aria-hidden="true"><?= lucide_icon('banknote', '', 14) ?></span>
                      <div class="pe-group-copy">
                        <h3 class="pe-group-title">Precio</h3>
                        <p class="pe-group-hint">Si el anterior es mayor, es oferta.</p>
                      </div>
                    </header>
                    <div class="pe-row">
                      <div class="pe-field">
                        <label class="form-label" for="<?= $fid ?>-precio">Precio actual <span class="pe-req" aria-hidden="true">*</span></label>
                        <div class="pe-affix">
                          <span class="pe-affix-tag" aria-hidden="true">Gs.</span>
                          <input type="number" id="<?= $fid ?>-precio" name="pro_precio" class="form-control" value="<?= e($mPrice) ?>" min="0" step="1" inputmode="numeric" placeholder="0" required data-pe-sync="price">
                        </div>
                      </div>
                      <div class="pe-field">
                        <label class="form-label" for="<?= $fid ?>-old">Precio anterior</label>
                        <div class="pe-affix">
                          <span class="pe-affix-tag" aria-hidden="true">Gs.</span>
                          <input type="number" id="<?= $fid ?>-old" name="pro_precio_anterior" class="form-control" value="<?= e($mOld) ?>" min="0" step="1" inputmode="numeric" placeholder="Opcional" title="Si es mayor al precio actual, se muestra como oferta" data-pe-sync="old">
                        </div>
                      </div>
                    </div>
                    <p class="pe-price-note" data-pe-note aria-live="polite" hidden>
                      <?= lucide_icon('info', 'pe-price-note-icon', 15) ?>
                      <span data-pe-note-text></span>
                    </p>
                  </section>

                  <section class="pe-group is-links">
                    <header class="pe-group-head">
                      <span class="pe-group-icon" aria-hidden="true"><?= lucide_icon('link', '', 14) ?></span>
                      <div class="pe-group-copy">
                        <h3 class="pe-group-title">Enlaces</h3>
                        <p class="pe-group-hint">Página del producto e imagen.</p>
                      </div>
                    </header>
                    <div class="pe-field">
                      <label class="form-label" for="<?= $fid ?>-url">URL del producto en la tienda</label>
                      <div class="pe-affix">
                        <span class="pe-affix-tag" aria-hidden="true"><?= lucide_icon('globe', '', 15) ?></span>
                        <input type="url" id="<?= $fid ?>-url" name="pro_url" class="form-control" value="<?= e($mUrl) ?>" placeholder="https://" data-pe-link>
                        <a href="<?= e($mUrl !== '' ? $mUrl : '#') ?>" class="pe-affix-btn" target="_blank" rel="noopener" title="Abrir enlace" aria-label="Abrir enlace del producto en una pestaña nueva" data-pe-open <?= $mUrl === '' ? 'hidden' : '' ?>><?= lucide_icon('external-link', '', 15) ?></a>
                      </div>
                    </div>
                    <div class="pe-field">
                      <label class="form-label" for="<?= $fid ?>-img">URL de la imagen</label>
                      <div class="pe-affix">
                        <span class="pe-affix-tag" aria-hidden="true"><?= lucide_icon('image', '', 15) ?></span>
                        <input type="text" id="<?= $fid ?>-img" name="pro_imagen" class="form-control" value="<?= e($product['pro_imagen']) ?>" placeholder="https://…/imagen.jpg" data-pe-sync="img" data-pe-link>
                        <a href="<?= e($mImage !== '' ? $mImage : '#') ?>" class="pe-affix-btn" target="_blank" rel="noopener" title="Abrir imagen" aria-label="Abrir imagen en una pestaña nueva" data-pe-open <?= $mImage === '' ? 'hidden' : '' ?>><?= lucide_icon('external-link', '', 15) ?></a>
                      </div>
                    </div>
                  </section>
                </div>
              </div>

              <aside class="pe-aside">
                <span class="pe-aside-label">Vista previa</span>
                  <div class="pe-preview-media <?= $mImage === '' ? 'is-empty' : '' ?>" data-pe-media>
                    <img src="<?= e($mImage) ?>" alt="" data-pe-img>
                    <span class="pe-preview-empty" aria-hidden="true"><?= lucide_icon('image-off', '', 28) ?><span>Sin imagen</span></span>
                    <span class="pe-preview-flag" data-pe-flag <?= $mOff > 0 ? '' : 'hidden' ?>>−<?= $mOff ?>%</span>
                  </div>
                  <div class="pe-preview-body">
                    <span class="pe-preview-tags">
                      <span class="pe-preview-cat" data-pe-out="cat"><?= e($mCategory !== '' ? $mCategory : 'Sin categoría') ?></span>
                      <span class="pe-preview-brand" data-pe-out="brand" <?= $mBrand === '' ? 'hidden' : '' ?>><?= e($mBrand) ?></span>
                    </span>
                    <strong class="pe-preview-name" data-pe-out="name"><?= e($mName) ?></strong>
                    <span class="pe-preview-price">
                      <span data-pe-out="price"><?= $mPrice !== '' && (float) $mPrice > 0 ? gs($mPrice) : 'Sin precio' ?></span>
                      <s data-pe-out="old" <?= $mOff > 0 ? '' : 'hidden' ?>><?= $mOld !== '' ? gs($mOld) : '' ?></s>
                    </span>
                  </div>

                <div class="pe-switches">
                  <label class="pe-switch" for="<?= $fid ?>-activo">
                    <span class="pe-switch-copy">
                      <strong>Publicado</strong>
                      <small>Visible en el buscador</small>
                    </span>
                    <input type="hidden" name="pro_activo" value="0">
                    <input type="checkbox" id="<?= $fid ?>-activo" class="pe-switch-input" name="pro_activo" value="1" role="switch" <?= $mActive ? 'checked' : '' ?>>
                    <span class="pe-switch-track" aria-hidden="true"></span>
                  </label>
                  <label class="pe-switch" for="<?= $fid ?>-stock">
                    <span class="pe-switch-copy">
                      <strong>En stock</strong>
                      <small>Apagado: “Consultar stock”</small>
                    </span>
                    <input type="hidden" name="pro_en_stock" value="0">
                    <input type="checkbox" id="<?= $fid ?>-stock" class="pe-switch-input" name="pro_en_stock" value="1" role="switch" <?= $mStock ? 'checked' : '' ?>>
                    <span class="pe-switch-track" aria-hidden="true"></span>
                  </label>
                </div>

                <a href="producto.php?id=<?= $mId ?>" class="pe-aside-link" target="_blank" rel="noopener">
                  <?= lucide_icon('eye', '', 15) ?><span>Ver ficha pública</span><?= lucide_icon('arrow-up-right', 'pe-aside-link-arrow', 14) ?>
                </a>
              </aside>
            </div>
          </div>

          <div class="modal-footer pe-footer">
            <span class="pe-footer-hint"><span class="pe-req" aria-hidden="true">*</span> Campos obligatorios</span>
            <div class="pe-footer-actions">
              <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
              <button type="submit" class="btn btn-primary"><?= lucide_icon('check', '', 16) ?>Guardar cambios</button>
            </div>
          </div>
        </form>
      </div>
    </div>
  <?php endforeach; ?>
<?php endif; ?>
</div>
<?php endif; ?>
