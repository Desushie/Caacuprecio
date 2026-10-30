<?php
/** Fragmento del panel Historial (página completa o partial AJAX). */
?>
<section class="dash-panel sx-history animate-reveal-up" data-sx-history aria-labelledby="sx-history-title" style="--lane-accent: var(--brand)">
  <header class="dash-panel-head">
    <span class="dash-panel-icon" aria-hidden="true"><?= lucide_icon('history', '', 18) ?></span>
    <div>
      <h2 id="sx-history-title" class="dash-panel-title">Historial</h2>
      <p class="dash-panel-caption">
        <?php if ($historyTotal > 0): ?>
          <span class="sx-history-range"><?= $fmt($fromItem) ?>–<?= $fmt($toItem) ?> de <?= $fmt($historyTotal) ?> tareas</span>
          <span class="sx-history-hint"> · abre el registro para ver qué devolvió cada una</span>
        <?php else: ?>
          <span>Abre el registro de una tarea para ver qué devolvió</span>
        <?php endif; ?>
      </p>
    </div>
    <?php if ($tableExists): ?>
      <form method="get" class="sx-rows-form" data-sx-rows-form action="admin_scraper.php">
        <?php if ($estado !== 'todas'): ?>
          <input type="hidden" name="estado" value="<?= e($estado) ?>">
        <?php endif; ?>
        <label class="ap-rows-select <?= $filas !== SX_FILAS_DEFAULT ? 'is-set' : '' ?>" title="Filas por página">
          <?= lucide_icon('rows-3', 'ap-rows-select-icon', 15) ?>
          <span class="ap-rows-select-label">Filas</span>
          <span class="ap-rows-select-value" aria-hidden="true"><?= $filas ?></span>
          <select name="filas" class="ap-rows-select-input" aria-label="Filas por página" data-sx-rows>
            <?php foreach (SX_FILAS as $option): ?>
              <option value="<?= $option ?>" <?= $option === $filas ? 'selected' : '' ?>><?= $option ?></option>
            <?php endforeach; ?>
          </select>
          <?= lucide_icon('chevron-down', 'ap-rows-select-caret', 13) ?>
        </label>
        <noscript><button type="submit" class="sx-btn is-sm">Aplicar</button></noscript>
      </form>
    <?php endif; ?>
  </header>

  <?php if ($tableExists): ?>
    <div class="sx-history-bar">
      <nav class="sx-chips" aria-label="Filtrar historial">
        <?php foreach ($historyFilters as $fKey => $filter): ?>
          <a
            href="<?= e(sx_url($fKey, $filas)) ?>"
            class="sx-filter <?= $estado === $fKey ? 'is-active' : '' ?>"
            <?= $estado === $fKey ? 'aria-current="true"' : '' ?>
          >
            <?= e($filter['label']) ?>
            <span class="sx-filter-count"><?= $fmt($filterCounts[$fKey]) ?></span>
          </a>
        <?php endforeach; ?>
      </nav>
    </div>
  <?php endif; ?>

  <?php if ($jobHistory): ?>
    <div class="sx-table">
      <div class="sx-cols" aria-hidden="true">
        <span>Tarea</span>
        <span>Estado</span>
        <span>Espera</span>
        <span>Duración</span>
        <span>Finalizó</span>
        <span></span>
      </div>

      <ul class="sx-rows">
        <?php foreach ($jobHistory as $job): ?>
          <?php
            $st = sx_status((string) $job['status']);
            $created = sx_ts($job['created_at']);
            $started = sx_ts($job['started_at']);
            $finished = sx_ts($job['finished_at']);
            $isActive = in_array($job['status'], ['pending', 'running'], true);
            $waitEnd = $started ?? ($job['status'] === 'pending' ? $now : null);
            $wait = $created !== null && $waitEnd !== null ? $waitEnd - $created : null;
            $runEnd = $finished ?? ($job['status'] === 'running' ? $now : null);
            $duration = $started !== null && $runEnd !== null ? $runEnd - $started : null;
            $hasLog = (int) $job['output_len'] > 0;
            $logId = 'sx-log-' . (int) $job['id'];
          ?>
          <li class="sx-item">
            <div class="sx-row <?= $st['tone'] ?>">
              <div class="sx-task">
                <span class="sx-task-icon <?= $job['status'] === 'running' ? 'sx-spin' : '' ?>" aria-hidden="true"><?= lucide_icon($st['icon'], '', 16) ?></span>
                <div class="sx-task-copy">
                  <span class="sx-task-name"><?= e($jobName($job)) ?></span>
                  <span class="sx-task-meta">
                    <span class="sx-task-id">#<?= (int) $job['id'] ?></span>
                    <span title="<?= e((string) $job['created_at']) ?>">Creada <?= e(sx_ago($created, $now)) ?></span>
                  </span>
                </div>
              </div>
              <span class="sx-cell-status"><span class="sx-chip <?= $st['tone'] ?>"><?= e($st['label']) ?></span></span>
              <span class="sx-cell" data-label="Espera"><?= e(sx_duration($wait)) ?></span>
              <span class="sx-cell" data-label="Duración">
                <?= e(sx_duration($duration)) ?><?php if ($job['status'] === 'running'): ?><small> y sigue</small><?php endif; ?>
              </span>
              <span class="sx-cell" data-label="Finalizó" title="<?= e((string) ($job['finished_at'] ?? '')) ?>">
                <?= $finished !== null ? e(date('d/m H:i', $finished)) : '—' ?>
              </span>
              <div class="sx-actions">
                <?php if ($hasLog): ?>
                  <button
                    type="button"
                    class="sx-log-toggle"
                    aria-expanded="false"
                    aria-controls="<?= $logId ?>"
                    data-sx-log
                  >
                    <?= lucide_icon('terminal', '', 14) ?><span>Registro</span><?= lucide_icon('chevron-down', 'sx-log-caret', 14) ?>
                  </button>
                <?php endif; ?>
                <?php if ($isActive): ?>
                  <form method="post" class="m-0">
                    <input type="hidden" name="action" value="cancel_job">
                    <input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>">
                    <?= $returnFields ?>
                    <button type="submit" class="sx-btn is-stop is-sm"><?= lucide_icon('square', '', 13) ?>Detener</button>
                  </form>
                <?php endif; ?>
              </div>
            </div>

            <?php if ($hasLog): ?>
              <div class="sx-log" id="<?= $logId ?>" hidden>
                <div class="sx-log-bar">
                  <span><?= lucide_icon('file-code-2', '', 13) ?><?= e(basename((string) $job['command_path'])) ?></span>
                  <?php if ((int) $job['output_len'] > SX_LOG_TAIL): ?>
                    <span>Últimos <?= $fmt(SX_LOG_TAIL) ?> de <?= $fmt((int) $job['output_len']) ?> caracteres</span>
                  <?php endif; ?>
                </div>
                <pre class="sx-log-output"><?= e((string) $job['output_tail']) ?></pre>
              </div>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>

    <?php if ($totalPages > 1): ?>
      <nav class="ap-pager" aria-label="Paginación del historial">
        <span class="ap-pager-info">Página <strong><?= $fmt($page) ?></strong> de <?= $fmt($totalPages) ?></span>
        <div class="ap-pages">
          <a
            class="ap-page-btn <?= $page <= 1 ? 'is-disabled' : '' ?>"
            href="<?= e(sx_url($estado, $filas, max(1, $page - 1))) ?>"
            aria-label="Página anterior"
            <?= $page <= 1 ? 'aria-disabled="true" tabindex="-1"' : '' ?>
          ><?= lucide_icon('chevron-left', '', 15) ?></a>
          <?php foreach ($pageItems as $item): ?>
            <?php if ($item === null): ?>
              <span class="ap-page-gap" aria-hidden="true">…</span>
            <?php else: ?>
              <a
                class="ap-page-btn <?= $item === $page ? 'is-current' : '' ?>"
                href="<?= e(sx_url($estado, $filas, $item)) ?>"
                <?= $item === $page ? 'aria-current="page"' : '' ?>
              ><?= $fmt($item) ?></a>
            <?php endif; ?>
          <?php endforeach; ?>
          <a
            class="ap-page-btn <?= $page >= $totalPages ? 'is-disabled' : '' ?>"
            href="<?= e(sx_url($estado, $filas, min($totalPages, $page + 1))) ?>"
            aria-label="Página siguiente"
            <?= $page >= $totalPages ? 'aria-disabled="true" tabindex="-1"' : '' ?>
          ><?= lucide_icon('chevron-right', '', 15) ?></a>
        </div>
      </nav>
    <?php endif; ?>
  <?php elseif ($tableExists): ?>
    <div class="dash-empty">
      <?= lucide_icon($estado === 'todas' ? 'inbox' : 'filter-x', '', 28) ?>
      <strong><?= $estado === 'todas' ? 'Todavía no hay tareas' : 'Nada con este filtro' ?></strong>
      <span>
        <?php if ($estado === 'todas'): ?>
          Inicia una actualización y su avance aparecerá aquí.
        <?php else: ?>
          <a href="<?= e(sx_url('todas', $filas)) ?>">Ver todas las tareas</a>
        <?php endif; ?>
      </span>
    </div>
  <?php else: ?>
    <div class="dash-empty">
      <?= lucide_icon('database-zap', '', 28) ?>
      <strong>Historial no disponible</strong>
      <span>Falta la tabla de tareas en la base de datos.</span>
    </div>
  <?php endif; ?>
</section>
