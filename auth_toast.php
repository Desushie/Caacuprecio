<?php
/**
 * Aviso flotante (toast) para mensajes flash.
 *
 * Espera: $flash = ['type' => 'success'|'danger'|'warning', 'message' => string, 'title' => ?string, 'icon' => ?string] o null.
 * Alternativa: $toasts = lista de arrays con la misma forma que $flash (tiene prioridad sobre $flash y se consume al renderizar).
 */

$toastItems = isset($toasts) ? (array) $toasts : [$flash ?? null];
unset($toasts);
$toastItems = array_values(array_filter(
    $toastItems,
    static fn ($item): bool => is_array($item) && trim((string) ($item['message'] ?? '')) !== ''
));
if (!$toastItems) {
    return;
}
?>
<div class="auth-toast-stack">
  <?php foreach ($toastItems as $toastItem):
    $toastType = in_array($toastItem['type'] ?? '', ['success', 'danger', 'warning'], true) ? $toastItem['type'] : 'success';
    $toastIcon = $toastItem['icon'] ?? [
        'success' => 'circle-check',
        'danger' => 'triangle-alert',
        'warning' => 'info',
    ][$toastType];
    $toastTitle = trim((string) ($toastItem['title'] ?? ''));
    $isUrgent = $toastType === 'danger';
  ?>
  <div
    class="auth-toast auth-toast--<?= e($toastType) ?>"
    role="<?= $isUrgent ? 'alert' : 'status' ?>"
    aria-live="<?= $isUrgent ? 'assertive' : 'polite' ?>"
    aria-atomic="true"
    data-auth-toast
  >
    <span class="auth-toast-icon" aria-hidden="true"><?= lucide_icon($toastIcon, '', 18) ?></span>
    <span class="auth-toast-text"><?php if ($toastTitle !== ''): ?><strong><?= e($toastTitle) ?></strong> <?php endif; ?><?= e($toastItem['message']) ?></span>
    <button type="button" class="auth-toast-close" aria-label="Cerrar" data-auth-toast-close><?= lucide_icon('x', '', 15) ?></button>
  </div>
  <?php endforeach; ?>
</div>
<?php if (!defined('AUTH_TOAST_SCRIPT_LOADED')): define('AUTH_TOAST_SCRIPT_LOADED', true); ?>
<script src="./js/toast.js?v=<?= filemtime(__DIR__ . '/js/toast.js') ?>" defer></script>
<?php endif; ?>
