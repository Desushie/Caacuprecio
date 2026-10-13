<?php
require_once __DIR__ . '/config.php';

// Forzar que solo usuarios autenticados ingresen
require_login();

$pdo = db();
$userId = current_user_id();

$success = '';
$error = '';

// =========================================================================
// ACCIÓN: ELIMINAR CUENTA (PROCESAMIENTO POST)
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_account') {
    try {
        $pdo->beginTransaction();

        // Estas tablas de analítica no tienen FK a usuario: se desvinculan para no conservar datos personales.
        foreach (['productos_vistos', 'producto_clicks', 'busqueda_click_producto'] as $analyticsTable) {
            $pdo->prepare("UPDATE {$analyticsTable} SET usuario_idusuario = NULL WHERE usuario_idusuario = :id")
                ->execute([':id' => $userId]);
        }

        $stmtDelete = $pdo->prepare('DELETE FROM usuario WHERE idusuario = :id');
        $stmtDelete->execute([':id' => $userId]);

        $pdo->commit();

        // Destruir la sesión completamente
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        session_destroy();

        // Redirigir a la página de login con bandera de éxito
        header('Location: login.php?account_deleted=1');
        exit;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = 'No se pudo eliminar la cuenta. Es posible que existan dependencias en el sistema.';
    }
}

// =========================================================================
// ACCIÓN: ACTUALIZAR DATOS DE PERFIL
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action'])) {
    $nombre = trim($_POST['usu_nombre'] ?? '');
    $email = trim($_POST['usu_email'] ?? '');

    if ($nombre === '' || $email === '') {
        $error = 'Por favor, completa todos los campos requeridos.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Por favor, ingresa un correo electrónico válido.';
    } else {
        // Verificar si el correo electrónico ya está registrado por otro ID de usuario
        $stmtEmail = $pdo->prepare('SELECT COUNT(*) FROM usuario WHERE usu_email = :email AND idusuario <> :id');
        $stmtEmail->execute([':email' => $email, ':id' => $userId]);
        
        if ($stmtEmail->fetchColumn() > 0) {
            $error = 'Este correo electrónico ya está registrado por otra cuenta.';
        } else {
            // Ejecutar la actualización en la base de datos
            $update = $pdo->prepare('UPDATE usuario SET usu_nombre = :nombre, usu_email = :email WHERE idusuario = :id');
            $update->execute([
                ':nombre' => $nombre,
                ':email'  => $email,
                ':id'     => $userId
            ]);

            // Actualizar la sesión activa
            $_SESSION['user']['usu_nombre'] = $nombre;
            $_SESSION['user']['usu_email'] = $email;

            $success = 'Tus datos de perfil se actualizaron correctamente.';
        }
    }
}

// 1. Traer datos frescos del usuario desde la base de datos para la vista
$stmt = $pdo->prepare('SELECT idusuario, usu_nombre, usu_email FROM usuario WHERE idusuario = :id LIMIT 1');
$stmt->execute([':id' => $userId]);
$userDb = $stmt->fetch();

if (!$userDb) {
    header('Location: logout.php');
    exit;
}

$favCount = 0;
try {
    $stmtFav = $pdo->prepare('SELECT COUNT(*) FROM favoritos WHERE usuario_idusuario = :id');
    $stmtFav->execute([':id' => $userId]);
    $favCount = (int) $stmtFav->fetchColumn();
} catch (PDOException $e) {
    $favCount = 0;
}

$nameParts = preg_split('/\s+/u', trim((string) $userDb['usu_nombre'])) ?: [];
$initials = '';
foreach (array_slice($nameParts, 0, 2) as $part) {
    $initials .= mb_strtoupper(mb_substr($part, 0, 1));
}
if ($initials === '') {
    $initials = mb_strtoupper(mb_substr((string) $userDb['usu_email'], 0, 1));
}
$firstName = $nameParts[0] ?? '';
$memberCode = str_pad((string) $userDb['idusuario'], 5, '0', STR_PAD_LEFT);

render_head('Mi Cuenta');
render_navbar('usuario'); 
?>

<style>
.ac-page {
  --ac-accent: var(--brand);
  --ac-amber: var(--accent);
  --ac-danger: var(--danger);
  --ac-success: var(--success);
  --ac-line: var(--border-soft);
  --ac-tint: color-mix(in srgb, var(--brand) 10%, transparent);
  padding: 1.15rem 0 3.5rem;
}

.ac-page svg.lucide,
body.theme-light .ac-page svg.lucide {
  color: var(--icon-c, currentColor) !important;
  stroke: currentColor !important;
}

.ac-page svg.lucide *,
body.theme-light .ac-page svg.lucide * {
  color: inherit !important;
  stroke: currentColor !important;
}

.ac-page .pd-crumb-link svg.lucide {
  --icon-c: var(--brand-2);
}

.ac-kicker {
  margin: 0;
  color: var(--brand-2);
  text-transform: uppercase;
  letter-spacing: var(--ls-overline);
  font-size: var(--fs-overline);
  font-weight: var(--fw-semibold);
  line-height: 1.3;
}

/* ── Avisos ── */
.ac-alert {
  display: flex;
  align-items: center;
  gap: 0.7rem;
  margin-bottom: 1rem;
  padding: 0.75rem 0.9rem;
  border: 1px solid var(--ac-line);
  border-left: 3px solid var(--ac-tone);
  border-radius: 0.9rem;
  background: color-mix(in srgb, var(--ac-tone) 9%, var(--bg-card));
  color: var(--text-main);
  font-size: var(--fs-small);
}

.ac-alert--success { --ac-tone: var(--ac-success); }
.ac-alert--danger { --ac-tone: var(--ac-danger); }

.ac-alert > svg.lucide {
  --icon-c: var(--ac-tone);
  flex: 0 0 auto;
}

.ac-alert-text {
  flex: 1 1 auto;
  margin: 0;
}

.ac-alert-close {
  display: inline-grid;
  place-items: center;
  width: 1.9rem;
  height: 1.9rem;
  padding: 0;
  border: 0;
  border-radius: 0.55rem;
  background: transparent;
  color: var(--text-soft);
}

.ac-alert-close:hover {
  background: color-mix(in srgb, var(--text-main) 8%, transparent);
  color: var(--text-main);
}

.ac-toast-stack {
  position: fixed;
  top: 5rem;
  right: 1.25rem;
  z-index: 2100;
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 0.6rem;
  pointer-events: none;
}

.ac-toast {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  max-width: min(23rem, calc(100vw - 2.5rem));
  padding: 0.6rem 0.5rem 0.6rem 0.8rem;
  border-radius: var(--radius-control);
  background: #2e9d5b;
  color: #fff;
  font-size: var(--fs-small);
  font-weight: var(--fw-medium);
  line-height: var(--lh-small);
  box-shadow: 0 8px 24px rgba(0, 0, 0, 0.18);
  pointer-events: auto;
  opacity: 0;
  transform: translateY(-0.6rem);
  transition: opacity 0.25s ease, transform 0.25s ease;
}

.ac-toast.is-visible {
  opacity: 1;
  transform: none;
}

.ac-toast.is-leaving {
  opacity: 0;
  transform: translateX(1rem);
}

.ac-toast-icon,
.ac-toast-close {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.ac-toast-text {
  flex: 1;
  min-width: 0;
}

.ac-toast-close {
  width: 1.65rem;
  height: 1.65rem;
  padding: 0;
  border: 0;
  border-radius: 0.45rem;
  background: transparent;
  color: inherit;
  opacity: 0.75;
  cursor: pointer;
  transition: opacity 0.2s ease, background-color 0.2s ease;
}

.ac-toast-close:hover,
.ac-toast-close:focus-visible {
  opacity: 1;
  background: rgba(255, 255, 255, 0.18);
  outline: none;
}

.ac-toast svg.lucide,
.ac-toast svg.lucide *,
body.theme-light .ac-toast svg.lucide,
body.theme-light .ac-toast svg.lucide * {
  color: #fff !important;
  stroke: #fff !important;
}

@media (max-width: 575.98px) {
  .ac-toast-stack {
    top: 4.5rem;
    right: 0.75rem;
    left: 0.75rem;
    align-items: stretch;
  }

  .ac-toast {
    max-width: none;
  }
}

@media (prefers-reduced-motion: reduce) {
  .ac-toast,
  .ac-toast.is-leaving {
    transform: none;
    transition: none;
  }
}

/* ── Tablero ── */
.ac-board {
  display: grid;
  grid-template-columns: minmax(16.5rem, 0.78fr) minmax(0, 1.5fr);
  gap: 0.85rem;
  align-items: stretch;
}

.ac-panel {
  position: relative;
  min-width: 0;
  background: var(--bg-card);
  border: 1px solid var(--ac-line);
  border-radius: 1.15rem;
  overflow: hidden;
}

.ac-profile-band {
  position: relative;
  height: 5.25rem;
  background: var(--ac-tint);
  border-bottom: 1px solid var(--ac-line);
  overflow: hidden;
}

.ac-profile-band span {
  position: absolute;
  border-radius: 999px;
  border: 1px solid color-mix(in srgb, var(--brand) 28%, transparent);
}

.ac-profile-band span:nth-child(1) { width: 9rem; height: 9rem; right: -2.5rem; top: -4.5rem; }
.ac-profile-band span:nth-child(2) { width: 5rem; height: 5rem; right: 3.4rem; top: 1.6rem; border-color: color-mix(in srgb, var(--ac-amber) 45%, transparent); }
.ac-profile-band span:nth-child(3) { width: 0.55rem; height: 0.55rem; right: 2.2rem; top: 1.2rem; background: var(--ac-amber); border: 0; }

.ac-profile-body {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  padding: 0 1.15rem 1.15rem;
}

.ac-avatar {
  position: relative;
  display: grid;
  place-items: center;
  width: 4.75rem;
  height: 4.75rem;
  margin-top: -2.4rem;
  border-radius: 1.3rem;
  background: var(--brand);
  color: #fff;
  font-size: 1.6rem;
  font-weight: var(--fw-bold);
  letter-spacing: -0.02em;
  outline: 4px solid var(--bg-elevated);
}

.ac-avatar-dot {
  position: absolute;
  right: -0.2rem;
  bottom: -0.2rem;
  width: 1rem;
  height: 1rem;
  border-radius: 999px;
  background: var(--ac-success);
  outline: 3px solid var(--bg-elevated);
}

.ac-identity {
  display: grid;
  gap: 0.2rem;
  min-width: 0;
}

.ac-name {
  margin: 0;
  font-size: var(--fs-h2);
  font-weight: var(--fw-bold);
  letter-spacing: var(--ls-heading);
  line-height: 1.18;
  color: var(--text-main);
  overflow-wrap: anywhere;
}

.ac-email {
  margin: 0;
  color: var(--text-soft);
  font-size: var(--fs-small);
  overflow-wrap: anywhere;
}

.ac-tags {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
}

.ac-tag {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  padding: 0.22rem 0.6rem;
  border: 1px solid var(--ac-line);
  border-radius: 999px;
  color: var(--text-soft);
  font-size: var(--fs-caption);
  font-weight: var(--fw-medium);
  line-height: 1.4;
}

.ac-tag--live {
  color: var(--ac-success);
  border-color: color-mix(in srgb, var(--ac-success) 35%, transparent);
}

.ac-tag--live::before {
  content: "";
  width: 0.4rem;
  height: 0.4rem;
  border-radius: 999px;
  background: currentColor;
}

.ac-tag code {
  color: var(--text-main);
  font-family: inherit;
  font-size: inherit;
  font-weight: var(--fw-semibold);
}

.ac-stat {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 0.85rem 0.95rem;
  border: 1px solid var(--ac-line);
  border-radius: 0.9rem;
  color: inherit;
  text-decoration: none;
  transition: border-color 0.2s ease, background-color 0.2s ease, transform 0.2s ease;
}

.ac-stat:hover,
.ac-stat:focus-visible {
  border-color: color-mix(in srgb, var(--ac-amber) 45%, var(--ac-line));
  background: color-mix(in srgb, var(--ac-amber) 6%, transparent);
  color: inherit;
  transform: translateY(-1px);
}

.ac-stat:focus-visible {
  outline: 2px solid color-mix(in srgb, var(--ac-amber) 55%, transparent);
  outline-offset: 2px;
}

.ac-stat:hover .ac-stat-icon,
.ac-stat:focus-visible .ac-stat-icon {
  background: color-mix(in srgb, var(--ac-amber) 22%, transparent);
  transform: scale(1.05);
}

.ac-stat-value {
  display: block;
  font-size: var(--fs-stat);
  font-weight: var(--fw-bold);
  line-height: 1.1;
  letter-spacing: var(--ls-heading);
  color: var(--text-main);
}

.ac-stat-label {
  display: block;
  margin-top: 0.15rem;
  color: var(--text-soft);
  font-size: var(--fs-caption);
}

.ac-stat-icon {
  --icon-c: var(--ac-amber);
  display: grid;
  place-items: center;
  width: 2.6rem;
  height: 2.6rem;
  border-radius: 0.8rem;
  background: color-mix(in srgb, var(--ac-amber) 13%, transparent);
  transition: background-color 0.2s ease, transform 0.2s ease;
}

@media (prefers-reduced-motion: reduce) {
  .ac-stat,
  .ac-stat-icon {
    transition: none;
  }

  .ac-stat:hover,
  .ac-stat:focus-visible,
  .ac-stat:hover .ac-stat-icon,
  .ac-stat:focus-visible .ac-stat-icon {
    transform: none;
  }
}

.ac-nav {
  display: grid;
  gap: 0.15rem;
  margin: 0;
  padding: 0.8rem 0 0;
  border-top: 1px solid var(--ac-line);
  list-style: none;
}

.ac-nav a {
  display: flex;
  align-items: center;
  gap: 0.65rem;
  padding: 0.55rem 0.6rem;
  border-radius: 0.7rem;
  color: var(--text-main);
  font-size: var(--fs-small);
  font-weight: var(--fw-regular);
  text-decoration: none;
  transition: background-color 0.2s ease, color 0.2s ease;
}

.ac-nav a svg.lucide { --icon-c: var(--text-soft); }

.ac-nav a .ac-nav-arrow {
  margin-left: auto;
  opacity: 0;
  transform: translateX(-4px);
  transition: opacity 0.2s ease, transform 0.2s ease;
}

.ac-nav a:hover,
.ac-nav a:focus-visible {
  background: var(--ac-tint);
}

.ac-nav a:hover svg.lucide,
.ac-nav a:focus-visible svg.lucide { --icon-c: var(--brand-2); }

.ac-nav a:hover .ac-nav-arrow,
.ac-nav a:focus-visible .ac-nav-arrow {
  opacity: 1;
  transform: none;
}

.ac-nav a.is-danger:hover,
.ac-nav a.is-danger:focus-visible {
  background: color-mix(in srgb, var(--ac-danger) 10%, transparent);
  color: var(--ac-danger);
}

.ac-nav a.is-danger:hover svg.lucide,
.ac-nav a.is-danger:focus-visible svg.lucide { --icon-c: var(--ac-danger); }

/* ── Ajustes (columna derecha) ── */
.ac-settings {
  display: flex;
  flex-direction: column;
  height: 0;
  min-height: 100%;
}

.ac-settings-head {
  flex: 0 0 auto;
  padding: 1.3rem 1.4rem 1rem;
}

.ac-title {
  margin: 0.2rem 0 0.25rem;
  font-size: var(--fs-h1);
  font-weight: var(--fw-bold);
  letter-spacing: var(--ls-heading);
  line-height: var(--lh-heading);
  color: var(--text-main);
}

.ac-lead {
  margin: 0;
  max-width: 34rem;
  color: var(--text-soft);
  font-size: var(--fs-small);
  text-wrap: pretty;
}

/* Pestañas */
.ac-tabs {
  display: flex;
  flex: 0 0 auto;
  gap: 1.35rem;
  margin: 0;
  padding: 0 1.4rem;
  border-bottom: 1px solid var(--ac-line);
  list-style: none;
  overflow-x: auto;
  scrollbar-width: none;
}

.ac-tabs::-webkit-scrollbar {
  display: none;
}

.ac-tab {
  position: relative;
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.75rem 0.1rem 0.85rem;
  border: 0;
  background: none;
  color: var(--text-soft);
  font-size: var(--fs-small);
  font-weight: var(--fw-medium);
  white-space: nowrap;
  transition: color 0.2s ease;
}

.ac-tab svg.lucide {
  --icon-c: currentColor;
}

.ac-tab::after {
  content: "";
  position: absolute;
  left: 0;
  right: 0;
  bottom: -1px;
  height: 2px;
  border-radius: 2px;
  background: transparent;
  transition: background-color 0.2s ease;
}

.ac-tab:hover {
  color: var(--text-main);
}

.ac-tab:focus-visible {
  outline: 2px solid var(--brand);
  outline-offset: 2px;
  border-radius: 0.4rem;
}

.ac-tab.active {
  color: var(--text-main);
  font-weight: var(--fw-semibold);
}

.ac-tab.active svg.lucide {
  --icon-c: var(--brand-2);
}

.ac-tab.active::after {
  background: var(--brand);
}

.ac-tab--danger:hover,
.ac-tab--danger.active {
  color: var(--ac-danger);
}

.ac-tab--danger.active svg.lucide {
  --icon-c: var(--ac-danger);
}

.ac-tab--danger.active::after {
  background: var(--ac-danger);
}

.ac-tab-dot {
  width: 0.4rem;
  height: 0.4rem;
  border-radius: 999px;
  background: var(--ac-amber);
}

.ac-tab-body {
  flex: 1 1 auto;
  min-height: 0;
  padding: 1.35rem 1.4rem 1.4rem;
  overflow-y: auto;
}

.ac-tab-body > .ac-pane.active {
  display: flex;
  flex-direction: column;
  min-height: 100%;
}

.ac-pane-form {
  display: flex;
  flex: 1 1 auto;
  flex-direction: column;
}

.ac-pane-note {
  display: flex;
  align-items: center;
  gap: 0.55rem;
  margin: auto 0 0;
  padding-top: 1rem;
  color: var(--text-soft);
  font-size: var(--fs-caption);
}

.ac-pane-note svg.lucide {
  --icon-c: var(--text-soft);
  display: block;
  flex: 0 0 auto;
}

.ac-section-head {
  display: grid;
  gap: 0.15rem;
  margin-bottom: 1rem;
}

.ac-section-title {
  margin: 0;
  font-size: var(--fs-h4);
  font-weight: var(--fw-semibold);
  line-height: var(--lh-heading);
  color: var(--text-main);
}

.ac-section-desc {
  margin: 0;
  color: var(--text-soft);
  font-size: var(--fs-small);
}

/* Formulario */
.ac-fields {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 1rem 1.5rem;
}

.ac-field {
  display: grid;
  gap: 0.4rem;
  min-width: 0;
}

.ac-label-row {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 0.5rem;
}

.ac-label {
  margin: 0;
  color: var(--text-main);
  font-size: var(--fs-caption);
  font-weight: var(--fw-semibold);
}

.ac-hint-row {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 0.75rem;
  color: var(--text-soft);
  font-size: var(--fs-caption);
  line-height: var(--lh-small);
}

.ac-counter {
  flex: 0 0 auto;
  font-variant-numeric: tabular-nums;
  transition: color 0.2s ease;
}

.ac-counter.is-near {
  color: var(--accent);
}

.ac-input-wrap {
  position: relative;
}

.ac-input-wrap > svg.lucide {
  --icon-c: var(--text-soft);
  position: absolute;
  top: 50%;
  left: 0.85rem;
  transform: translateY(-50%);
  pointer-events: none;
  transition: color 0.2s ease;
}

.ac-page .ac-input {
  width: 100%;
  min-height: 2.85rem;
  padding: 0.55rem 0.85rem 0.55rem 2.55rem;
  border: 1px solid var(--ac-line) !important;
  border-radius: var(--radius-control);
  background-color: color-mix(in srgb, var(--bg-elevated) 70%, transparent) !important;
  color: var(--text-main) !important;
  font-size: var(--fs-small);
  box-shadow: none !important;
  transition: border-color 0.2s ease, background-color 0.2s ease;
}

body.theme-light .ac-page .ac-input {
  border-color: #dde3ec !important;
  background-color: #f4f6fa !important;
}

.ac-page .ac-input:hover {
  border-color: var(--border-strong) !important;
}

.ac-page .ac-input:focus {
  border-color: var(--brand) !important;
  outline: none;
}

.ac-input-wrap:focus-within > svg.lucide {
  --icon-c: var(--brand-2);
}

.ac-form-foot {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 0.75rem;
  margin-top: auto;
  padding-top: 1rem;
  border-top: 1px dashed var(--ac-line);
}

.ac-fields {
  margin-bottom: 1.25rem;
}

.ac-dirty {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
  margin: 0;
  color: var(--text-soft);
  font-size: var(--fs-caption);
}

.ac-dirty::before {
  content: "";
  width: 0.45rem;
  height: 0.45rem;
  border-radius: 999px;
  background: color-mix(in srgb, var(--text-soft) 55%, transparent);
  transition: background-color 0.2s ease;
}

.ac-dirty.is-dirty {
  color: var(--text-main);
}

.ac-dirty.is-dirty::before {
  background: var(--ac-amber);
}

.ac-actions {
  display: flex;
  gap: 0.5rem;
}

.ac-page .ac-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.45rem;
  min-height: 2.5rem;
  padding: 0.45rem 1rem;
  font-size: var(--fs-small);
  font-weight: var(--fw-medium);
  box-shadow: none;
  white-space: nowrap;
}

.ac-page .btn-primary.ac-btn svg.lucide { --icon-c: #fff; }

.ac-page .ac-btn:disabled {
  opacity: 0.45;
  pointer-events: none;
}

/* Filas de acción (seguridad / zona sensible) */
.ac-row {
  display: flex;
  align-items: center;
  gap: 0.9rem;
  padding: 0.9rem 1rem;
  border: 1px solid var(--ac-line);
  border-radius: 0.9rem;
}

.ac-row-icon {
  --icon-c: var(--brand-2);
  display: grid;
  flex: 0 0 auto;
  place-items: center;
  width: 2.5rem;
  height: 2.5rem;
  border-radius: 0.75rem;
  background: var(--ac-tint);
}

.ac-row-copy {
  flex: 1 1 auto;
  min-width: 0;
}

.ac-row-title {
  margin: 0;
  color: var(--text-main);
  font-size: var(--fs-small);
  font-weight: var(--fw-semibold);
}

.ac-row-desc {
  margin: 0.1rem 0 0;
  color: var(--text-soft);
  font-size: var(--fs-caption);
  overflow-wrap: anywhere;
}

.ac-row-desc strong {
  color: var(--text-main);
  font-weight: var(--fw-medium);
}

.ac-row--danger {
  border-style: dashed;
  border-color: color-mix(in srgb, var(--ac-danger) 35%, transparent);
  background: color-mix(in srgb, var(--ac-danger) 5%, transparent);
}

.ac-row--danger .ac-row-icon {
  --icon-c: var(--ac-danger);
  background: color-mix(in srgb, var(--ac-danger) 12%, transparent);
}

.ac-page .btn-outline-danger.ac-btn svg.lucide { --icon-c: currentColor; }

/* ── Modal eliminar cuenta ── */
.ac-delete-modal,
body.theme-light .ac-delete-modal {
  color: var(--text-main);
  box-shadow: none;
}

.ac-delete-modal .modal-header {
  border-bottom: 1px solid var(--border-soft);
}

.ac-delete-modal .btn-close {
  flex: 0 0 auto;
  margin: 0;
  filter: invert(1) grayscale(1) brightness(2);
}

body.theme-light .ac-delete-modal .btn-close {
  filter: none;
}

.ac-delete-modal .modal-body {
  padding-top: 1.1rem;
}

.ac-delete-modal .modal-footer {
  border-top: 1px solid var(--border-soft);
}

.ac-kicker--danger {
  margin-bottom: 0.15rem;
  color: var(--danger);
}

.ac-loss-list {
  display: grid;
  gap: 0.15rem;
  margin: 0;
  padding: 0.35rem;
  border: 1px solid var(--border-soft);
  border-radius: 0.9rem;
  list-style: none;
}

.ac-loss-list li {
  display: flex;
  align-items: flex-start;
  gap: 0.65rem;
  padding: 0.55rem 0.6rem;
  color: var(--text-soft);
  font-size: var(--fs-small);
  line-height: var(--lh-small);
}

.ac-loss-list li + li {
  border-top: 1px dashed var(--border-soft);
}

.ac-loss-list strong {
  color: var(--text-main);
  font-weight: var(--fw-semibold);
  overflow-wrap: anywhere;
}

.ac-loss-list svg.lucide,
body.theme-light .ac-loss-list svg.lucide {
  flex: 0 0 auto;
  margin-top: 0.15rem;
  color: var(--danger) !important;
  stroke: var(--danger) !important;
}

.ac-confirm {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  padding: 0.7rem 0.8rem;
  border: 1px solid color-mix(in srgb, var(--danger) 30%, var(--border-soft));
  border-radius: 0.8rem;
  background: color-mix(in srgb, var(--danger) 6%, transparent);
  color: var(--text-main);
  font-size: var(--fs-small);
  font-weight: var(--fw-medium);
  cursor: pointer;
}

.ac-confirm .form-check-input {
  flex: 0 0 auto;
  margin: 0;
  border-color: color-mix(in srgb, var(--danger) 45%, var(--border-soft));
  background-color: transparent;
  box-shadow: none;
  cursor: pointer;
}

.ac-confirm .form-check-input:checked {
  border-color: var(--danger);
  background-color: var(--danger);
}

.ac-confirm .form-check-input:focus-visible {
  outline: 2px solid var(--danger);
  outline-offset: 2px;
}

.ac-delete-modal .btn-danger svg.lucide,
body.theme-light .ac-delete-modal .btn-danger svg.lucide {
  color: #fff !important;
  stroke: #fff !important;
}

.ac-delete-modal .btn-danger:disabled {
  opacity: 0.45;
}

@media (max-width: 991.98px) {
  .ac-board {
    grid-template-columns: minmax(0, 1fr);
  }

  .ac-settings {
    height: auto;
    min-height: 0;
  }

  .ac-tab-body {
    overflow: visible;
  }
}

@media (max-width: 575.98px) {
  .ac-settings-head,
  .ac-tabs,
  .ac-tab-body {
    padding-left: 1rem;
    padding-right: 1rem;
  }

  .ac-tabs {
    gap: 1.1rem;
  }

  .ac-fields {
    grid-template-columns: minmax(0, 1fr);
  }

  .ac-row {
    flex-wrap: wrap;
  }

  .ac-row .ac-btn,
  .ac-actions,
  .ac-actions .ac-btn {
    width: 100%;
  }
}
</style>

<div class="site-bg" aria-hidden="true">
  <span class="bg-orb orb-1"></span>
  <span class="bg-orb orb-2"></span>
  <span class="bg-orb orb-3"></span>
  <span class="bg-grid"></span>
</div>

<?php if ($success !== ''): ?>
  <div class="ac-toast-stack">
    <div class="ac-toast" role="status" aria-live="polite" aria-atomic="true" data-ac-toast>
      <span class="ac-toast-icon" aria-hidden="true"><?= lucide_icon('circle-check', '', 18) ?></span>
      <span class="ac-toast-text"><?= e($success) ?></span>
      <button type="button" class="ac-toast-close" aria-label="Cerrar" data-ac-toast-close><?= lucide_icon('x', '', 15) ?></button>
    </div>
  </div>
<?php endif; ?>

<section class="ac-page" aria-labelledby="ac-title">
  <div class="container position-relative z-1">

    <nav aria-label="breadcrumb" class="pd-crumb">
      <ol class="breadcrumb custom-breadcrumb pd-crumb-list mb-0">
        <li class="breadcrumb-item">
          <a href="index.php" class="pd-crumb-link">
            <?= lucide_icon('home', '', 14) ?>
            <span>Inicio</span>
          </a>
        </li>
        <li class="breadcrumb-item active" aria-current="page">
          <span class="pd-crumb-current">Mi cuenta</span>
        </li>
      </ol>
    </nav>

    <?php if ($error !== ''): ?>
      <div class="ac-alert ac-alert--danger alert fade show" role="alert">
        <?= lucide_icon('triangle-alert', '', 18) ?>
        <p class="ac-alert-text"><?= e($error) ?></p>
        <button type="button" class="ac-alert-close" data-bs-dismiss="alert" aria-label="Cerrar"><?= lucide_icon('x', '', 16) ?></button>
      </div>
    <?php endif; ?>

    <div class="ac-board">

      <aside class="ac-panel ac-profile animate-reveal-up" aria-label="Resumen de tu perfil">
        <div class="ac-profile-band" aria-hidden="true"><span></span><span></span><span></span></div>

        <div class="ac-profile-body">
          <div class="ac-avatar" aria-hidden="true">
            <?= e($initials) ?>
            <span class="ac-avatar-dot"></span>
          </div>

          <div class="ac-identity">
            <p class="ac-kicker">Hola<?= $firstName !== '' ? ', ' . e($firstName) : '' ?></p>
            <h2 class="ac-name"><?= e($userDb['usu_nombre']) ?></h2>
            <p class="ac-email"><?= e($userDb['usu_email']) ?></p>
          </div>

          <div class="ac-tags">
            <span class="ac-tag ac-tag--live">Sesión activa</span>
            <span class="ac-tag">Miembro <code>#<?= e($memberCode) ?></code></span>
          </div>

          <a href="favoritos.php" class="ac-stat">
            <span>
              <span class="ac-stat-value"><?= number_format($favCount, 0, ',', '.') ?></span>
              <span class="ac-stat-label"><?= $favCount === 1 ? 'Producto guardado' : 'Productos guardados' ?></span>
            </span>
            <span class="ac-stat-icon"><?= lucide_icon('heart', '', 20) ?></span>
          </a>

          <ul class="ac-nav">
            <li>
              <a href="favoritos.php">
                <?= lucide_icon('list-checks', '', 17) ?>Planificador de compra
                <?= lucide_icon('arrow-right', 'ac-nav-arrow', 15) ?>
              </a>
            </li>
            <li>
              <a href="buscar.php">
                <?= lucide_icon('search', '', 17) ?>Comparar precios
                <?= lucide_icon('arrow-right', 'ac-nav-arrow', 15) ?>
              </a>
            </li>
            <li>
              <a href="logout.php" class="is-danger">
                <?= lucide_icon('log-out', '', 17) ?>Cerrar sesión
                <?= lucide_icon('arrow-right', 'ac-nav-arrow', 15) ?>
              </a>
            </li>
          </ul>
        </div>
      </aside>

      <div class="ac-panel ac-settings animate-reveal-up">
        <header class="ac-settings-head">
          <div>
            <p class="ac-kicker">Configuración</p>
            <h1 id="ac-title" class="ac-title">Mi cuenta</h1>
            <p class="ac-lead">Mantén tus datos al día para recibir avisos de precios y recuperar el acceso cuando lo necesites.</p>
          </div>
        </header>

        <div class="ac-tabs" role="tablist" aria-label="Opciones de la cuenta">
          <button type="button" class="ac-tab active" id="ac-tab-profile" data-bs-toggle="tab" data-bs-target="#ac-pane-profile" role="tab" aria-controls="ac-pane-profile" aria-selected="true">
            <?= lucide_icon('user-round', '', 16) ?>Datos personales
            <span class="ac-tab-dot" id="ac-tab-dirty" hidden></span>
          </button>
          <button type="button" class="ac-tab" id="ac-tab-security" data-bs-toggle="tab" data-bs-target="#ac-pane-security" role="tab" aria-controls="ac-pane-security" aria-selected="false" tabindex="-1">
            <?= lucide_icon('shield-check', '', 16) ?>Seguridad
          </button>
          <button type="button" class="ac-tab ac-tab--danger" id="ac-tab-danger" data-bs-toggle="tab" data-bs-target="#ac-pane-danger" role="tab" aria-controls="ac-pane-danger" aria-selected="false" tabindex="-1">
            <?= lucide_icon('triangle-alert', '', 16) ?>Zona sensible
          </button>
        </div>

        <div class="tab-content ac-tab-body">
          <section class="tab-pane fade show active ac-pane" id="ac-pane-profile" role="tabpanel" aria-labelledby="ac-tab-profile" tabindex="0">
            <div class="ac-section-head">
              <h3 class="ac-section-title">Información personal</h3>
              <p class="ac-section-desc">Así te identificamos dentro de Caacuprecio.</p>
            </div>

            <form method="post" action="usuario.php" id="ac-profile-form" class="ac-pane-form" novalidate>
              <div class="ac-fields">
                <div class="ac-field">
                  <div class="ac-label-row">
                    <label class="ac-label" for="ac-nombre">Nombre completo</label>
                  </div>
                  <div class="ac-input-wrap">
                    <?= lucide_icon('user', '', 17) ?>
                    <input type="text" id="ac-nombre" name="usu_nombre" class="form-control ac-input" value="<?= e($userDb['usu_nombre']) ?>" maxlength="45" autocomplete="name" aria-describedby="ac-nombre-hint ac-nombre-count" required>
                  </div>
                  <div class="ac-hint-row">
                    <span id="ac-nombre-hint">Así aparecerá en tu perfil.</span>
                    <span class="ac-counter" id="ac-nombre-count" data-counter-for="ac-nombre" aria-live="polite"></span>
                  </div>
                </div>

                <div class="ac-field">
                  <div class="ac-label-row">
                    <label class="ac-label" for="ac-email">Correo electrónico</label>
                  </div>
                  <div class="ac-input-wrap">
                    <?= lucide_icon('mail', '', 17) ?>
                    <input type="email" id="ac-email" name="usu_email" class="form-control ac-input" value="<?= e($userDb['usu_email']) ?>" maxlength="120" autocomplete="email" aria-describedby="ac-email-hint" required>
                  </div>
                  <div class="ac-hint-row">
                    <span id="ac-email-hint">Lo usas para iniciar sesión.</span>
                  </div>
                </div>
              </div>

              <div class="ac-form-foot">
                <p class="ac-dirty" id="ac-dirty" aria-live="polite">Todo está al día</p>
                <div class="ac-actions">
                  <button type="reset" class="btn btn-outline-secondary ac-btn" id="ac-reset" disabled>Descartar</button>
                  <button type="submit" class="btn btn-primary ac-btn" id="ac-save" disabled>
                    <?= lucide_icon('check', '', 16) ?>Guardar cambios
                  </button>
                </div>
              </div>
            </form>
          </section>

          <section class="tab-pane fade ac-pane" id="ac-pane-security" role="tabpanel" aria-labelledby="ac-tab-security" tabindex="0">
            <div class="ac-section-head">
              <h3 class="ac-section-title">Seguridad</h3>
              <p class="ac-section-desc">Protege el acceso a tu cuenta.</p>
            </div>

            <div class="ac-row">
              <span class="ac-row-icon"><?= lucide_icon('key-round', '', 18) ?></span>
              <div class="ac-row-copy">
                <p class="ac-row-title">Contraseña</p>
                <p class="ac-row-desc">Te enviaremos un enlace a <strong><?= e($userDb['usu_email']) ?></strong> para crear una nueva.</p>
              </div>
              <a href="olvide_password.php?email=<?= urlencode($userDb['usu_email']) ?>" class="btn btn-outline-primary ac-btn">
                <?= lucide_icon('rotate-ccw-key', '', 16) ?>Restablecer
              </a>
            </div>

            <p class="ac-pane-note">
              <?= lucide_icon('info', '', 15) ?>
              <span>El enlace es válido por 1 hora. Si no lo ves, revisa la carpeta de spam.</span>
            </p>
          </section>

          <section class="tab-pane fade ac-pane" id="ac-pane-danger" role="tabpanel" aria-labelledby="ac-tab-danger" tabindex="0">
            <div class="ac-section-head">
              <h3 class="ac-section-title">Zona sensible</h3>
              <p class="ac-section-desc">Acciones que no se pueden deshacer.</p>
            </div>

            <div class="ac-row ac-row--danger">
              <span class="ac-row-icon"><?= lucide_icon('trash-2', '', 18) ?></span>
              <div class="ac-row-copy">
                <p class="ac-row-title">Eliminar cuenta</p>
                <p class="ac-row-desc">Se borran tu perfil y tus favoritos, y se cierra tu sesión.</p>
              </div>
              <button type="button" class="btn btn-outline-danger ac-btn" data-bs-toggle="modal" data-bs-target="#deleteAccountModal">
                <?= lucide_icon('trash-2', '', 16) ?>Eliminar
              </button>
            </div>

            <p class="ac-pane-note">
              <?= lucide_icon('info', '', 15) ?>
              <span>Antes de confirmar te pediremos una última verificación.</span>
            </p>
          </section>
        </div>
      </div>

    </div>
  </div>
</section>

<div class="modal fade" id="deleteAccountModal" tabindex="-1" aria-labelledby="deleteAccountModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form method="post" action="usuario.php" class="modal-content suggestion-modal report-product-modal ac-delete-modal" id="ac-delete-form">
      <input type="hidden" name="action" value="delete_account">

      <div class="modal-header">
        <div class="report-product-heading">
          <span class="report-product-icon" aria-hidden="true"><?= lucide_icon('octagon-alert', '', 18) ?></span>
          <div class="report-product-heading-text">
            <p class="ac-kicker ac-kicker--danger">Zona sensible</p>
            <h5 class="modal-title" id="deleteAccountModalLabel">¿Eliminar tu cuenta?</h5>
            <p class="report-product-lead">Esta acción es definitiva y no se puede deshacer.</p>
          </div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>

      <div class="modal-body">
        <ul class="ac-loss-list">
          <li>
            <?= lucide_icon('user-x', '', 16) ?>
            <span>Tu perfil <strong><?= e($userDb['usu_email']) ?></strong> se borra de Caacuprecio.</span>
          </li>
          <li>
            <?= lucide_icon('heart-off', '', 16) ?>
            <span><?= $favCount > 0 ? 'Pierdes tus <strong>' . number_format($favCount, 0, ',', '.') . '</strong> ' . ($favCount === 1 ? 'producto guardado' : 'productos guardados') . '.' : 'Se pierde tu lista de favoritos.' ?></span>
          </li>
          <li>
            <?= lucide_icon('log-out', '', 16) ?>
            <span>Tu sesión se cierra de inmediato.</span>
          </li>
        </ul>

        <label class="ac-confirm" for="ac-delete-confirm">
          <input type="checkbox" class="form-check-input" id="ac-delete-confirm">
          <span>Entiendo que no podré recuperar mi cuenta.</span>
        </label>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn btn-danger" id="ac-delete-submit" disabled>
          <?= lucide_icon('trash-2', '', 16) ?>Eliminar cuenta
        </button>
      </div>
    </form>
  </div>
</div>

<script>
document.querySelectorAll('[data-ac-toast]').forEach((toast) => {
  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  let timer = null;

  const remove = () => {
    const stack = toast.parentElement;
    toast.remove();
    if (stack && !stack.children.length) stack.remove();
  };
  const dismiss = () => {
    window.clearTimeout(timer);
    if (toast.classList.contains('is-leaving')) return;
    toast.classList.add('is-leaving');
    if (reducedMotion) remove();
    else toast.addEventListener('transitionend', remove, { once: true });
  };
  const schedule = () => {
    window.clearTimeout(timer);
    timer = window.setTimeout(dismiss, 4500);
  };

  toast.querySelector('[data-ac-toast-close]')?.addEventListener('click', dismiss);
  toast.addEventListener('mouseenter', () => window.clearTimeout(timer));
  toast.addEventListener('mouseleave', schedule);

  requestAnimationFrame(() => requestAnimationFrame(() => toast.classList.add('is-visible')));
  schedule();
});

(() => {
  const form = document.getElementById('ac-profile-form');
  if (!form) return;

  const inputs = Array.from(form.querySelectorAll('.ac-input'));
  const save = document.getElementById('ac-save');
  const reset = document.getElementById('ac-reset');
  const status = document.getElementById('ac-dirty');
  const tabDot = document.getElementById('ac-tab-dirty');

  const sync = () => {
    const dirty = inputs.some((input) => input.value.trim() !== input.defaultValue.trim());
    save.disabled = !dirty;
    reset.disabled = !dirty;
    status.classList.toggle('is-dirty', dirty);
    status.textContent = dirty ? 'Tienes cambios sin guardar' : 'Todo está al día';
    if (tabDot) tabDot.hidden = !dirty;

    form.querySelectorAll('[data-counter-for]').forEach((counter) => {
      const input = document.getElementById(counter.dataset.counterFor);
      counter.textContent = `${input.value.length}/${input.maxLength}`;
      counter.classList.toggle('is-near', input.value.length >= input.maxLength * 0.85);
    });
  };

  const deleteModal = document.getElementById('deleteAccountModal');
  const deleteCheck = document.getElementById('ac-delete-confirm');
  const deleteSubmit = document.getElementById('ac-delete-submit');
  if (deleteModal && deleteCheck && deleteSubmit) {
    deleteCheck.addEventListener('change', () => { deleteSubmit.disabled = !deleteCheck.checked; });
    deleteModal.addEventListener('hidden.bs.modal', () => {
      deleteCheck.checked = false;
      deleteSubmit.disabled = true;
    });
  }

  inputs.forEach((input) => input.addEventListener('input', sync));
  form.addEventListener('reset', () => requestAnimationFrame(sync));
  sync();
})();
</script>

<?php 
render_footer(); 
?>
