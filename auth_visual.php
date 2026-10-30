<?php
/**
 * Carrusel visual del board de auth (login / registro).
 * Espera: $visualSlides (array), $visualTagIcons (array).
 */
$visualSlides = $visualSlides ?? [];
$visualTagIcons = $visualTagIcons ?? [];
?>
<div
  class="auth-visual-shell"
  data-auth-visual
  aria-label="Novedades de Caacuprecio"
  aria-roledescription="carrusel"
>
  <aside class="auth-board-visual">
    <div class="auth-visual-wash" aria-hidden="true">
      <span class="auth-shape auth-shape-blob-a"></span>
      <span class="auth-shape auth-shape-blob-b"></span>
      <span class="auth-shape auth-shape-ring"></span>
      <span class="auth-shape auth-shape-pill"></span>
      <span class="auth-shape auth-shape-dot-a"></span>
      <span class="auth-shape auth-shape-dot-b"></span>
      <span class="auth-shape auth-shape-dot-c"></span>
      <span class="auth-shape auth-shape-arc"></span>
      <span class="auth-shape auth-shape-square"></span>
      <span class="auth-shape auth-shape-bar"></span>
      <span class="auth-shape auth-shape-petal"></span>
    </div>

    <?php if (count($visualSlides) > 1): ?>
      <div class="auth-visual-dots" role="tablist" aria-label="Indicadores de novedades">
        <?php foreach ($visualSlides as $index => $slide): ?>
          <button
            type="button"
            class="auth-visual-dot"
            data-auth-dot="<?= (int) $index ?>"
            role="tab"
            aria-label="Novedad <?= (int) ($index + 1) ?> de <?= count($visualSlides) ?>"
            aria-current="<?= $index === 0 ? 'true' : 'false' ?>"
          ></button>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div class="auth-visual-stage" aria-live="polite">
      <?php foreach ($visualSlides as $index => $slide): ?>
        <article
          class="auth-visual-slide<?= $index === 0 ? ' is-active' : '' ?>"
          data-auth-slide
          <?= $index === 0 ? '' : 'hidden' ?>
        >
          <div class="auth-visual-tags">
            <?php foreach ($slide['tags'] as $tag): ?>
              <span><?= lucide_icon($visualTagIcons[$tag] ?? 'tag', 'auth-visual-tag-icon', 14) ?><?= e($tag) ?></span>
            <?php endforeach; ?>
          </div>
          <div class="auth-visual-card">
            <p class="auth-visual-quote"><?= e($slide['quote']) ?></p>
            <p class="auth-visual-name"><?= e($slide['name']) ?></p>
            <p class="auth-visual-role"><?= e($slide['role']) ?></p>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  </aside>

  <?php if (count($visualSlides) > 1): ?>
    <div class="auth-visual-nav">
      <button type="button" class="auth-visual-nav-btn" data-auth-prev aria-label="Novedad anterior">
        <?= lucide_icon('arrow-left', '', 18) ?>
      </button>
      <button type="button" class="auth-visual-nav-btn" data-auth-next aria-label="Novedad siguiente">
        <?= lucide_icon('arrow-right', '', 18) ?>
      </button>
    </div>
  <?php endif; ?>
</div>
