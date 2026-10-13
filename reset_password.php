<?php
require_once __DIR__ . '/config.php';

if (is_logged_in()) {
    header('Location: index.php');
    exit;
}

$pdo = db();
$formError = '';
$token = $_GET['token'] ?? ($_POST['token'] ?? '');
$tokenState = 'missing';
$secondsLeft = 0;
$userId = null;

if ($token) {
    $stmt = $pdo->prepare('
        SELECT idusuario,
               usu_reset_expira > NOW() AS vigente,
               GREATEST(TIMESTAMPDIFF(SECOND, NOW(), usu_reset_expira), 0) AS restante
        FROM usuario
        WHERE usu_reset_token = :token
        LIMIT 1
    ');
    $stmt->execute([':token' => $token]);
    $user = $stmt->fetch();

    if (!$user) {
        $tokenState = 'invalid';
    } elseif (!(int) $user['vigente']) {
        $tokenState = 'expired';
    } else {
        $tokenState = 'valid';
        $userId = $user['idusuario'];
        $secondsLeft = (int) $user['restante'];
    }
}

$validToken = $tokenState === 'valid';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $validToken) {
    $password = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';

    if (strlen($password) < 6) {
        $formError = 'La contraseña debe tener al menos 6 caracteres.';
    } elseif ($password !== $password_confirm) {
        $formError = 'Las contraseñas no coinciden.';
    } else {
        $newHash = password_hash($password, PASSWORD_DEFAULT);
        $update = $pdo->prepare('UPDATE usuario SET usu_contra = :hash, usu_reset_token = NULL, usu_reset_expira = NULL WHERE idusuario = :id');
        $update->execute([
            ':hash' => $newHash,
            ':id' => $userId
        ]);

        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Contraseña actualizada. Ya puedes entrar.'];
        header('Location: login.php');
        exit;
    }
}

$problems = [
    'expired' => [
        'icon' => 'timer-off',
        'title' => 'Este enlace venció',
        'desc' => 'Los enlaces de recuperación duran 60 minutos. Pide uno nuevo y úsalo apenas llegue.',
    ],
    'invalid' => [
        'icon' => 'link-2-off',
        'title' => 'El enlace no es válido',
        'desc' => 'Puede que ya lo hayas usado o que hayas pedido otro después. Solo funciona el último que te enviamos.',
    ],
    'missing' => [
        'icon' => 'unlink',
        'title' => 'Falta el enlace de recuperación',
        'desc' => 'Abre esta página desde el botón del correo que te enviamos, o solicita un enlace nuevo.',
    ],
];
$problem = $problems[$tokenState] ?? null;

$steps = [
    1 => ['icon' => 'at-sign', 'title' => 'Ingresa tu correo', 'desc' => 'El mismo con el que inicias sesión.'],
    2 => ['icon' => 'inbox', 'title' => 'Abre el mensaje', 'desc' => 'Asunto: “Recuperar contraseña”.'],
    3 => ['icon' => 'lock-keyhole', 'title' => 'Crea una nueva', 'desc' => 'Elige una contraseña y guárdala.'],
];

$requirements = [
    'length' => 'Mínimo 6 caracteres',
    'case' => 'Mayúsculas y minúsculas',
    'digit' => 'Al menos un número',
    'symbol' => 'Al menos un símbolo',
];

render_head('Restablecer contraseña');
render_navbar('login');
?>

<style>
.rp-page {
  --rp-line: var(--border-soft);
  --rp-tint: color-mix(in srgb, var(--brand) 10%, transparent);
  --rp-amber: var(--accent);
  --rp-nav: 4.35rem;
  padding: 1.15rem 0 3.5rem;
}

.rp-page svg.lucide,
body.theme-light .rp-page svg.lucide {
  color: var(--icon-c, currentColor) !important;
  stroke: currentColor !important;
}

.rp-page svg.lucide *,
body.theme-light .rp-page svg.lucide * {
  color: inherit !important;
  stroke: currentColor !important;
}

.rp-page .pd-crumb-link svg.lucide { --icon-c: var(--brand-2); }

.rp-kicker {
  margin: 0;
  color: var(--brand-2);
  text-transform: uppercase;
  letter-spacing: var(--ls-overline);
  font-size: var(--fs-overline);
  font-weight: var(--fw-semibold);
  line-height: 1.3;
}

/* ── Tablero ── */
.rp-board {
  display: grid;
  grid-template-columns: minmax(0, 1.3fr) minmax(19rem, 0.8fr);
  gap: 0.85rem;
  align-items: stretch;
}

.rp-panel {
  position: relative;
  min-width: 0;
  background: var(--bg-card);
  border: 1px solid var(--rp-line);
  border-radius: 1.15rem;
  overflow: hidden;
}

/* ── Panel principal ── */
.rp-main {
  container-type: inline-size;
  display: flex;
  flex-direction: column;
  padding: 1.45rem 1.6rem 1.15rem;
}

.rp-mark {
  --icon-c: color-mix(in srgb, var(--brand) 18%, transparent);
  position: absolute;
  top: -1.2rem;
  right: -1rem;
  pointer-events: none;
  transform: rotate(-18deg);
}

.rp-mark svg.lucide {
  width: 7rem !important;
  height: 7rem !important;
  stroke-width: 1.5;
}

.rp-head {
  position: relative;
  padding-right: 4rem;
}

.rp-head-copy { min-width: 0; }

.rp-title {
  margin: 0.3rem 0 0.5rem;
  font-size: var(--fs-h1);
  font-weight: var(--fw-bold);
  letter-spacing: var(--ls-heading);
  line-height: var(--lh-heading);
  color: var(--text-main);
}

.rp-lead {
  margin: 0;
  max-width: 36rem;
  color: var(--text-soft);
  font-size: var(--fs-small);
  text-wrap: pretty;
}

/* Avisos */
.rp-toast-stack {
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

.rp-toast {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  max-width: min(23rem, calc(100vw - 2.5rem));
  padding: 0.6rem 0.5rem 0.6rem 0.8rem;
  border-radius: var(--radius-control);
  background: #d14f4f;
  color: #fff;
  font-size: var(--fs-small);
  font-weight: var(--fw-medium);
  line-height: var(--lh-small);
  pointer-events: auto;
  opacity: 0;
  transform: translateY(-0.6rem);
  transition: opacity 0.25s ease, transform 0.25s ease;
}

.rp-toast.is-visible { opacity: 1; transform: none; }
.rp-toast.is-leaving { opacity: 0; transform: translateX(1rem); }

.rp-toast-icon,
.rp-toast-close {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.rp-toast-text { flex: 1; min-width: 0; }

.rp-toast-close {
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

.rp-toast-close:hover,
.rp-toast-close:focus-visible {
  opacity: 1;
  background: rgba(255, 255, 255, 0.18);
  outline: none;
}

.rp-toast svg.lucide,
.rp-toast svg.lucide *,
body.theme-light .rp-toast svg.lucide,
body.theme-light .rp-toast svg.lucide * {
  color: #fff !important;
  stroke: #fff !important;
}

/* Zona central */
.rp-body {
  display: flex;
  flex: 1 0 auto;
  flex-direction: column;
  justify-content: center;
  gap: 1.25rem;
  padding: 1.5rem 0 0.75rem;
}

.rp-form {
  display: grid;
  gap: 1.4rem;
}

.rp-fields {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 1.1rem 1.25rem;
}

.rp-field {
  display: grid;
  gap: 0.5rem;
  align-content: start;
  min-width: 0;
}

.rp-label-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
  min-height: 1.6rem;
}

.rp-label {
  margin: 0;
  color: var(--text-main);
  font-size: var(--fs-caption);
  font-weight: var(--fw-semibold);
}

.rp-suggest {
  --icon-c: var(--rp-amber);
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  padding: 0.15rem 0.5rem;
  border: 1px dashed color-mix(in srgb, var(--rp-amber) 45%, var(--rp-line));
  border-radius: 999px;
  background: transparent;
  color: var(--rp-amber);
  font-size: var(--fs-caption);
  font-weight: var(--fw-medium);
  cursor: pointer;
  transition: border-color 0.2s ease, background-color 0.2s ease;
}

.rp-suggest:hover,
.rp-suggest:focus-visible {
  border-style: solid;
  border-color: var(--rp-amber);
  background: color-mix(in srgb, var(--rp-amber) 10%, transparent);
  outline: none;
}

.rp-suggest:hover svg.lucide { transform: rotate(-12deg); }
.rp-suggest svg.lucide { transition: transform 0.2s ease; }

.rp-input-wrap {
  position: relative;
  min-width: 0;
}

.rp-input-wrap > svg.lucide {
  --icon-c: var(--text-soft);
  position: absolute;
  top: 50%;
  left: 0.9rem;
  transform: translateY(-50%);
  pointer-events: none;
}

.rp-input-wrap:focus-within > svg.lucide { --icon-c: var(--brand-2); }

.rp-page .rp-input {
  width: 100%;
  min-height: 3.1rem;
  padding: 0.6rem 3.1rem 0.6rem 2.7rem;
  border: 1px solid var(--rp-line) !important;
  border-radius: var(--radius-control);
  background-color: color-mix(in srgb, var(--bg-elevated) 70%, transparent) !important;
  color: var(--text-main) !important;
  font-size: var(--fs-body, 0.95rem);
  box-shadow: none !important;
  transition: border-color 0.2s ease;
}

body.theme-light .rp-page .rp-input {
  border-color: #dde3ec !important;
  background-color: #f4f6fa !important;
}

body .rp-page .rp-input:hover { border-color: var(--border-strong) !important; }
body .rp-page .rp-input:focus { border-color: var(--brand) !important; outline: none; }
body .rp-page .rp-input.is-valid { border-color: color-mix(in srgb, var(--success) 70%, transparent) !important; }
body .rp-page .rp-input.is-invalid {
  border-color: #d14f4f !important;
  background-image: none !important;
}
body .rp-page .rp-input.is-invalid:focus {
  box-shadow: 0 0 0 3px color-mix(in srgb, #d14f4f 22%, transparent) !important;
}
.rp-input-wrap:has(.rp-input.is-invalid) > svg.lucide { --icon-c: #d14f4f; }

.rp-eye {
  --icon-c: var(--text-soft);
  position: absolute;
  top: 50%;
  right: 0.4rem;
  display: grid;
  place-items: center;
  width: 2.3rem;
  height: 2.3rem;
  padding: 0;
  border: 0;
  border-radius: 0.6rem;
  background: transparent;
  transform: translateY(-50%);
  cursor: pointer;
  transition: background-color 0.2s ease;
}

.rp-eye svg.lucide { width: 17px !important; height: 17px !important; }

.rp-eye:hover,
.rp-eye:focus-visible {
  --icon-c: var(--brand-2);
  background: var(--rp-tint);
  outline: none;
}

.rp-eye[aria-pressed="true"] { --icon-c: var(--brand-2); }

/* Medidor de seguridad */
.rp-meter {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr)) auto;
  align-items: center;
  gap: 0.25rem;
  margin-top: 0.15rem;
  padding: 0 0.15rem;
}

.rp-meter-bar {
  height: 0.18rem;
  border-radius: 999px;
  background: var(--rp-line);
  transition: background-color 0.25s ease;
}

.rp-meter-label {
  min-width: 4.5rem;
  margin-left: 0.3rem;
  color: color-mix(in srgb, var(--text-soft) 80%, transparent);
  font-size: 0.7rem;
  font-weight: var(--fw-regular);
  line-height: 1;
  text-align: right;
}

.rp-meter[data-level="1"] .rp-meter-bar:nth-child(-n+1) { background: color-mix(in srgb, var(--danger) 70%, transparent); }
.rp-meter[data-level="2"] .rp-meter-bar:nth-child(-n+2) { background: color-mix(in srgb, var(--rp-amber) 70%, transparent); }
.rp-meter[data-level="3"] .rp-meter-bar:nth-child(-n+3) { background: color-mix(in srgb, var(--brand) 70%, transparent); }
.rp-meter[data-level="4"] .rp-meter-bar:nth-child(-n+4) { background: color-mix(in srgb, var(--success) 70%, transparent); }
.rp-meter[data-level="1"] .rp-meter-label { color: color-mix(in srgb, var(--danger) 65%, var(--text-soft)); }
.rp-meter[data-level="2"] .rp-meter-label { color: color-mix(in srgb, var(--rp-amber) 65%, var(--text-soft)); }
.rp-meter[data-level="3"] .rp-meter-label { color: color-mix(in srgb, var(--brand-2) 65%, var(--text-soft)); }
.rp-meter[data-level="4"] .rp-meter-label { color: color-mix(in srgb, var(--success) 65%, var(--text-soft)); }

/* Recomendaciones */
.rp-help {
  position: relative;
  margin-top: 0.5rem;
  border: 1px solid var(--rp-line);
  border-radius: 0.95rem;
}

.rp-help-title {
  position: absolute;
  top: 0;
  left: 0.75rem;
  margin: 0;
  padding: 0 0.45rem;
  background: var(--bg-card);
  color: var(--text-soft);
  font-size: 0.6875rem;
  font-weight: var(--fw-semibold);
  letter-spacing: 0.12em;
  line-height: 1;
  text-transform: uppercase;
  transform: translateY(-50%);
}

.rp-help-list {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 0.4rem;
  margin: 0;
  padding: 0.8rem 0.55rem 0.55rem;
  list-style: none;
}

.rp-help--3 .rp-help-list { grid-template-columns: repeat(3, minmax(0, 1fr)); }

.rp-help-list li {
  display: grid;
  grid-template-columns: 1.6rem minmax(0, 1fr);
  align-items: center;
  column-gap: 0.5rem;
  row-gap: 0;
  min-width: 0;
  padding: 0.4rem 0.55rem;
  border: 1px solid var(--rp-line);
  border-radius: 0.6rem;
  background: color-mix(in srgb, var(--brand) 3%, transparent);
  transition: border-color 0.25s ease, background-color 0.25s ease;
}

.rp-help-icon {
  --icon-c: color-mix(in srgb, var(--brand-2) 75%, var(--text-soft));
  position: relative;
  display: grid;
  grid-row: span 2;
  place-items: center;
  width: 1.6rem;
  height: 1.6rem;
  border-radius: 0.45rem;
  background: color-mix(in srgb, var(--brand) 9%, transparent);
  transition: background-color 0.25s ease;
}

.rp-help-icon svg.lucide { width: 14px !important; height: 14px !important; }

.rp-help-icon .rp-help-done { display: none; }

.rp-help-list strong,
.rp-help-list li > span:last-child {
  grid-column: 2;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.rp-help-list strong {
  color: var(--text-main);
  font-size: 0.78rem;
  font-weight: var(--fw-bold);
  line-height: 1.2;
}

.rp-help-list li > span:last-child {
  color: var(--text-soft);
  font-size: 0.7rem;
  line-height: 1.35;
}

.rp-help-list li.is-met {
  border-color: color-mix(in srgb, var(--success) 40%, transparent);
  background: color-mix(in srgb, var(--success) 7%, transparent);
}

.rp-help-list li.is-met .rp-help-icon {
  --icon-c: #fff;
  background: var(--success);
}

.rp-help-list li.is-met .rp-help-base { display: none; }
.rp-help-list li.is-met .rp-help-done { display: block; }
.rp-help-list li.is-met > span:last-child { color: var(--success); }

.rp-checklist {
  position: relative;
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.65rem 2.5rem;
  margin: 0;
  padding: 1rem 1.15rem;
  list-style: none;
  background: color-mix(in srgb, var(--text-main) 2%, var(--bg-elevated));
  border: 1px solid var(--border-soft);
  border-radius: 14px;
}

.rp-checklist::before {
  content: "";
  position: absolute;
  top: 0.75rem;
  bottom: 0.75rem;
  left: 50%;
  width: 1px;
  background: var(--border-soft);
  pointer-events: none;
}

.rp-checklist li {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  min-width: 0;
  color: color-mix(in srgb, var(--text-soft) 75%, transparent);
  font-size: 0.78rem;
  line-height: 1.3;
  transition: color 0.2s ease;
}

.rp-check-icon {
  --icon-c: currentColor;
  display: inline-grid;
  flex: 0 0 auto;
  place-items: center;
}

.rp-check-icon svg.lucide { width: 15px !important; height: 15px !important; }

.rp-check-yes { display: none; }

.rp-checklist li.is-met { color: var(--success); }
.rp-checklist li.is-met .rp-check-no { display: none; }
.rp-checklist li.is-met .rp-check-yes { display: inline-grid; }

/* Envío */
.rp-submit-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 1rem;
  padding-top: 0.25rem;
}

.rp-submit-row .rp-timer { align-self: center; }

.rp-timer {
  --rp-ring-color: var(--brand);
  display: inline-flex;
  flex: 0 0 auto;
  align-items: center;
  align-self: flex-start;
  --rp-timer-h: 3.1rem;
  --rp-timer-pad: 0.35rem;
  box-sizing: border-box;
  gap: 0.65rem;
  width: fit-content;
  height: var(--rp-timer-h);
  padding: 0 0.85rem 0 var(--rp-timer-pad);
  line-height: 1;
  background: linear-gradient(
    135deg,
    color-mix(in srgb, var(--rp-ring-color) 7%, var(--bg-elevated)),
    color-mix(in srgb, var(--rp-ring-color) 2%, var(--bg-elevated))
  );
  border: 1px solid color-mix(in srgb, var(--rp-ring-color) 30%, var(--border-soft));
  border-radius: 0.85rem;
  transition: border-color 0.25s ease, background-color 0.25s ease;
}

.rp-timer-ring {
  position: relative;
  display: grid;
  flex: 0 0 auto;
  place-items: center;
  width: calc(var(--rp-timer-h) - 2 * var(--rp-timer-pad) - 2px);
  height: calc(var(--rp-timer-h) - 2 * var(--rp-timer-pad) - 2px);
}

.rp-ring {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  transform: rotate(-90deg);
}

.rp-ring circle {
  fill: none;
  stroke-width: 3.5;
}

.rp-ring-track {
  stroke: color-mix(in srgb, var(--rp-ring-color) 16%, transparent);
}

.rp-ring-fill {
  stroke: var(--rp-ring-color);
  stroke-linecap: round;
  stroke-dasharray: 100;
  stroke-dashoffset: calc(100 - var(--rp-pct, 0));
  transition: stroke-dashoffset 0.5s cubic-bezier(0.33, 1, 0.32, 1), stroke 0.25s ease;
}

.rp-timer-icon {
  --icon-c: #fff;
  display: grid;
  place-items: center;
  width: 60%;
  height: 60%;
  border-radius: 50%;
  background: var(--rp-ring-color);
  color: #fff;
  transition: background-color 0.25s ease;
}

.rp-timer-icon svg.lucide {
  width: 0.95rem !important;
  height: 0.95rem !important;
  color: inherit;
  stroke: currentColor;
}

.rp-timer-copy {
  display: grid;
  gap: 0.35rem;
  min-width: 0;
}

.rp-timer-label,
.rp-timer-value {
  text-box: trim-both cap alphabetic;
}

.rp-timer-label {
  color: color-mix(in srgb, var(--rp-ring-color) 75%, var(--text-main));
  font-size: 0.62rem;
  font-weight: var(--fw-semibold);
  letter-spacing: var(--ls-overline);
  text-transform: uppercase;
  white-space: nowrap;
}

.rp-timer-value {
  color: var(--text-main);
  font-size: 0.95rem;
  font-weight: var(--fw-bold);
  letter-spacing: var(--ls-heading);
  line-height: 1;
  font-variant-numeric: tabular-nums;
}

.rp-timer.is-warning {
  --rp-ring-color: var(--rp-amber);
}

.rp-timer.is-over {
  --rp-ring-color: var(--danger);
}

.rp-timer a {
  color: var(--danger);
  font-size: 0.8rem;
  font-weight: var(--fw-semibold);
  letter-spacing: normal;
}

@media (prefers-reduced-motion: reduce) {
  .rp-timer,
  .rp-ring-fill,
  .rp-timer-icon {
    transition: none;
  }
}

.rp-page .rp-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
  min-height: 2.6rem;
  padding: 0.5rem 1.05rem;
  font-size: var(--fs-small);
  font-weight: var(--fw-medium);
  box-shadow: none;
  white-space: nowrap;
}

.rp-page .rp-btn--lg {
  min-height: 3.1rem;
  padding-inline: 1.4rem;
  font-weight: var(--fw-semibold);
}

.rp-page .btn-primary.rp-btn svg.lucide { --icon-c: #fff; }
.rp-page .rp-btn:disabled { opacity: 0.55; }

.rp-btn-idle { display: inline-flex; align-items: center; gap: 0.5rem; }
.rp-btn-loading { display: none; }

.rp-btn.is-loading { pointer-events: none; opacity: 0.8; }
.rp-btn.is-loading .rp-btn-idle { display: none; }
.rp-btn.is-loading .rp-btn-loading { display: inline-flex; align-items: center; gap: 0.5rem; }
.rp-btn.is-loading .rp-btn-loading svg.lucide { animation: rp-spin 0.9s linear infinite; }

@keyframes rp-spin { to { transform: rotate(360deg); } }

.rp-page .rp-btn--ghost {
  --icon-c: var(--brand-2);
  border: 1px solid var(--rp-line);
  background: color-mix(in srgb, var(--brand) 4%, transparent);
  color: var(--text-main);
  transition: border-color 0.2s ease, background-color 0.2s ease, color 0.2s ease;
}

body.theme-light .rp-page .rp-btn--ghost { border-color: #dde3ec; }

.rp-page .rp-btn--ghost:hover,
.rp-page .rp-btn--ghost:focus-visible {
  border-color: var(--brand);
  background: var(--rp-tint);
  color: var(--brand-2);
}

/* Estado: enlace no válido */
.rp-problem {
  display: flex;
  align-items: flex-start;
  gap: 0.9rem;
  padding: 0.95rem 1rem;
  border: 1px dashed color-mix(in srgb, var(--danger) 45%, var(--rp-line));
  border-radius: 0.95rem;
  background: color-mix(in srgb, var(--danger) 6%, transparent);
}

.rp-problem-icon {
  --icon-c: var(--danger);
  display: grid;
  flex: 0 0 auto;
  place-items: center;
  width: 2.6rem;
  height: 2.6rem;
  border-radius: 0.85rem;
  background: color-mix(in srgb, var(--danger) 14%, transparent);
}

.rp-problem-title {
  margin: 0 0 0.15rem;
  color: var(--text-main);
  font-size: var(--fs-body, 0.95rem);
  font-weight: var(--fw-bold);
}

.rp-problem-desc {
  margin: 0;
  color: var(--text-soft);
  font-size: var(--fs-small);
  line-height: var(--lh-small);
  text-wrap: pretty;
}

.rp-problem-actions {
  display: grid;
  grid-template-columns: minmax(0, 1.3fr) minmax(0, 1fr);
  gap: 0.5rem;
}

/* Pie del panel */
.rp-foot {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 0.6rem 1rem;
  margin-top: 1.25rem;
  padding-top: 1rem;
  border-top: 1px dashed var(--rp-line);
}

.rp-back {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  padding: 0.35rem 0.6rem 0.35rem 0.45rem;
  border-radius: 0.65rem;
  color: var(--text-main);
  font-size: var(--fs-small);
  font-weight: var(--fw-medium);
  text-decoration: none;
  transition: background-color 0.2s ease;
}

.rp-back svg.lucide { --icon-c: var(--brand-2); transition: transform 0.2s ease; }

.rp-back:hover,
.rp-back:focus-visible {
  background: var(--rp-tint);
  color: var(--text-main);
}

.rp-back:hover svg.lucide { transform: translateX(-3px); }

.rp-alt {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  margin: 0;
  color: var(--text-soft);
  font-size: var(--fs-small);
}

.rp-alt a {
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  color: var(--brand-2);
  font-weight: var(--fw-semibold);
  text-decoration: none;
}

.rp-alt a svg.lucide { --icon-c: currentColor; transition: transform 0.2s ease; }

.rp-alt a:hover,
.rp-alt a:focus-visible { text-decoration: underline; }

.rp-alt a:hover svg.lucide { transform: translateX(3px); }

/* ── Guía lateral ── */
.rp-guide {
  display: flex;
  flex-direction: column;
}

.rp-guide-head {
  position: relative;
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 1.1rem 1.35rem 1rem;
  border-bottom: 1px solid var(--rp-line);
  background: color-mix(in srgb, var(--brand) 6%, var(--bg-card));
  overflow: hidden;
}

.rp-guide-head > :not(.rp-guide-deco) { position: relative; }

.rp-guide-deco span {
  position: absolute;
  border-radius: 999px;
  border: 1px solid color-mix(in srgb, var(--brand) 28%, transparent);
  pointer-events: none;
}

.rp-guide-deco span:nth-child(1) { width: 9rem; height: 9rem; right: -2.5rem; top: -4.5rem; }
.rp-guide-deco span:nth-child(2) { width: 5rem; height: 5rem; right: 3.4rem; top: 1.6rem; border-color: color-mix(in srgb, var(--rp-amber) 45%, transparent); }
.rp-guide-deco span:nth-child(3) { width: 0.55rem; height: 0.55rem; right: 2.2rem; top: 1.2rem; background: var(--rp-amber); border: 0; }

.rp-guide-icon {
  --icon-c: var(--rp-amber);
  display: grid;
  flex: 0 0 auto;
  place-items: center;
  width: 2.75rem;
  height: 2.75rem;
  border: 1.5px solid var(--rp-amber);
  border-radius: 0.9rem;
  background: color-mix(in srgb, var(--rp-amber) 8%, var(--bg-card));
}

.rp-guide-icon svg.lucide {
  width: 1.5rem !important;
  height: 1.5rem !important;
  stroke-width: 2;
}

.rp-guide-title {
  margin: 0;
  font-size: var(--fs-h4);
  font-weight: var(--fw-semibold);
  line-height: var(--lh-heading);
  color: var(--text-main);
}

.rp-guide-sub {
  margin: 0.1rem 0 0;
  color: var(--text-soft);
  font-size: var(--fs-caption);
  line-height: var(--lh-small);
}

.rp-steps {
  display: flex;
  flex: 1 1 auto;
  flex-direction: column;
  justify-content: center;
  margin: 0;
  padding: 1.35rem 1.35rem 1.1rem;
  list-style: none;
}

.rp-step {
  position: relative;
  display: grid;
  grid-template-columns: 2.3rem minmax(0, 1fr);
  align-content: start;
  gap: 0.8rem;
}

.rp-step:not(:last-child) {
  flex: 1 1 auto;
  max-height: 6.25rem;
  padding-bottom: 0.9rem;
}

.rp-step:not(:last-child)::before {
  content: "";
  position: absolute;
  top: 2.45rem;
  bottom: 0.15rem;
  left: 1.15rem;
  border-left: 2px dashed var(--rp-line);
}

.rp-step.is-done:not(:last-child)::before {
  border-left-style: solid;
  border-left-color: color-mix(in srgb, var(--success) 55%, transparent);
}

.rp-step-badge {
  --icon-c: var(--text-soft);
  display: grid;
  place-items: center;
  width: 2.3rem;
  height: 2.3rem;
  border: 1px solid var(--rp-line);
  border-radius: 0.8rem;
  background: var(--bg-card);
}

.rp-step.is-current .rp-step-badge {
  --icon-c: #fff;
  border-color: var(--brand);
  background: var(--brand);
}

.rp-step.is-done .rp-step-badge {
  --icon-c: var(--success);
  border-color: color-mix(in srgb, var(--success) 35%, transparent);
  background: color-mix(in srgb, var(--success) 12%, transparent);
}

.rp-step.is-error .rp-step-badge {
  --icon-c: var(--danger);
  border-color: color-mix(in srgb, var(--danger) 40%, transparent);
  background: color-mix(in srgb, var(--danger) 12%, transparent);
}

.rp-step-num {
  display: block;
  color: var(--text-soft);
  font-size: var(--fs-overline);
  font-weight: var(--fw-semibold);
  letter-spacing: var(--ls-overline);
  text-transform: uppercase;
}

.rp-step.is-current .rp-step-num { color: var(--brand-2); }
.rp-step.is-done .rp-step-num { color: var(--success); }
.rp-step.is-error .rp-step-num { color: var(--danger); }

.rp-step-title {
  display: block;
  margin-top: 0.05rem;
  color: var(--text-main);
  font-size: var(--fs-small);
  font-weight: var(--fw-semibold);
}

.rp-step:not(.is-current):not(.is-done):not(.is-error) .rp-step-title { color: var(--text-soft); }

.rp-step-desc {
  display: block;
  margin-top: 0.1rem;
  color: var(--text-soft);
  font-size: var(--fs-caption);
  line-height: var(--lh-small);
}

.rp-facts {
  display: grid;
  gap: 0.35rem;
  margin: 0 1.35rem;
  padding: 0.75rem 0.95rem;
  border: 1px solid color-mix(in srgb, var(--rp-amber) 28%, transparent);
  border-left: 3px solid var(--rp-amber);
  border-radius: 0.6rem;
  background: color-mix(in srgb, var(--rp-amber) 9%, var(--bg-card));
  list-style: none;
}

.rp-fact {
  display: flex;
  align-items: flex-start;
  gap: 0.45rem;
  color: var(--text-soft);
  font-size: var(--fs-caption);
  line-height: var(--lh-small);
}

.rp-fact svg.lucide {
  --icon-c: var(--rp-amber);
  display: block;
  flex: 0 0 auto;
  margin-top: calc((var(--lh-small) * 1em - 13px) / 2);
  stroke-width: 2.5;
}

.rp-fact strong {
  color: var(--text-main);
  font-weight: var(--fw-semibold);
}

.rp-note {
  display: flex;
  align-items: flex-start;
  gap: 0.3rem;
  margin: 0;
  padding: 1.15rem 1.35rem;
  color: var(--text-soft);
  font-size: var(--fs-caption);
  line-height: var(--lh-small);
}

.rp-note svg.lucide {
  --icon-c: var(--text-soft);
  display: block;
  flex: 0 0 auto;
  margin-top: calc((var(--lh-small) * 1em - 15px) / 2);
}

@media (min-width: 992px) and (min-height: 620px) {
  .rp-page {
    display: flex;
    flex-direction: column;
    min-height: calc(100vh - var(--rp-nav));
    min-height: calc(100dvh - var(--rp-nav));
    padding-bottom: 1.25rem;
  }

  .rp-page > .container {
    display: flex;
    flex: 1 1 auto;
    flex-direction: column;
  }

  .rp-board {
    flex: 1 1 auto;
    max-height: 38rem;
  }
}

@container (max-width: 760px) {
  .rp-help-list { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}

@container (max-width: 520px) {
  .rp-fields { grid-template-columns: minmax(0, 1fr); }
  .rp-help--3 .rp-help-list { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}

@media (max-width: 991.98px) {
  .rp-board { grid-template-columns: minmax(0, 1fr); }
}

@media (max-width: 575.98px) {
  .rp-main { padding: 1.2rem 1rem 1.1rem; }
  .rp-head { padding-right: 0; }
  .rp-mark { display: none; }
  .rp-submit-row { flex-direction: column; align-items: stretch; }
  .rp-submit-row .rp-timer { align-self: flex-start; }
  .rp-checklist { grid-template-columns: minmax(0, 1fr); }
  .rp-checklist::before { display: none; }
  .rp-page .rp-btn--lg { width: 100%; }
  .rp-problem-actions,
  .rp-help--3 .rp-help-list { grid-template-columns: minmax(0, 1fr); }
  .rp-guide-head,
  .rp-steps,
  .rp-note { padding-left: 1rem; padding-right: 1rem; }
  .rp-facts { margin: 0 1rem; }
  .rp-foot { flex-direction: column; align-items: flex-start; }
  .rp-toast-stack { top: 4.5rem; right: 0.75rem; left: 0.75rem; align-items: stretch; }
  .rp-toast { max-width: none; }
}

@media (prefers-reduced-motion: reduce) {
  .rp-back svg.lucide,
  .rp-alt a svg.lucide,
  .rp-suggest svg.lucide,
  .rp-btn.is-loading .rp-btn-loading svg.lucide { transition: none; animation: none; }
  .rp-toast,
  .rp-toast.is-leaving { transform: none; transition: none; }
}
</style>

<div class="site-bg" aria-hidden="true">
  <span class="bg-orb orb-1"></span>
  <span class="bg-orb orb-2"></span>
  <span class="bg-orb orb-3"></span>
  <span class="bg-grid"></span>
</div>

<div class="rp-toast-stack" data-rp-toast-stack>
  <?php if ($formError !== ''): ?>
    <div class="rp-toast" role="alert" aria-live="assertive" aria-atomic="true" data-rp-toast>
      <span class="rp-toast-icon" aria-hidden="true"><?= lucide_icon('triangle-alert', '', 18) ?></span>
      <span class="rp-toast-text"><?= e($formError) ?></span>
      <button type="button" class="rp-toast-close" aria-label="Cerrar" data-rp-toast-close><?= lucide_icon('x', '', 15) ?></button>
    </div>
  <?php endif; ?>
</div>

<section class="rp-page" aria-labelledby="rp-title">
  <div class="container position-relative z-1">

    <nav aria-label="breadcrumb" class="pd-crumb">
      <ol class="breadcrumb custom-breadcrumb pd-crumb-list mb-0">
        <li class="breadcrumb-item">
          <a href="index.php" class="pd-crumb-link">
            <?= lucide_icon('home', '', 14) ?>
            <span>Inicio</span>
          </a>
        </li>
        <li class="breadcrumb-item">
          <a href="olvide_password.php" class="pd-crumb-link">Recuperar contraseña</a>
        </li>
        <li class="breadcrumb-item active" aria-current="page">
          <span class="pd-crumb-current">Nueva contraseña</span>
        </li>
      </ol>
    </nav>

    <div class="rp-board">

      <div class="rp-panel rp-main animate-reveal-up">
        <span class="rp-mark" aria-hidden="true"><?= lucide_icon($validToken ? 'lock-keyhole' : 'shield-alert', '', 110) ?></span>

        <header class="rp-head">
          <div class="rp-head-copy">
            <p class="rp-kicker"><?= $validToken ? 'Paso 3 de 3' : 'Enlace de recuperación' ?></p>
            <h1 id="rp-title" class="rp-title"><?= $validToken ? 'Crea tu nueva contraseña' : 'No pudimos abrir el enlace' ?></h1>
            <p class="rp-lead">
              <?php if ($validToken): ?>
                Elige una contraseña que no uses en otros sitios. Al guardarla, entrarás con ella desde cualquier dispositivo.
              <?php else: ?>
                No te preocupes: tu cuenta está a salvo. Solo necesitas un enlace nuevo para continuar.
              <?php endif; ?>
            </p>
          </div>
        </header>

        <div class="rp-body">
          <?php if ($validToken): ?>
            <form method="post" action="reset_password.php" class="rp-form" data-rp-form novalidate>
              <input type="hidden" name="token" value="<?= e($token) ?>">

              <div class="rp-fields">
                <div class="rp-field">
                  <div class="rp-label-row">
                    <label class="rp-label" for="password">Nueva contraseña</label>
                    <button type="button" class="rp-suggest" data-rp-suggest>
                      <?= lucide_icon('wand-sparkles', '', 13) ?>Sugerir una
                    </button>
                  </div>
                  <div class="rp-input-wrap">
                    <?= lucide_icon('lock-keyhole', '', 18) ?>
                    <input type="password" class="form-control rp-input" id="password" name="password" minlength="6" maxlength="72" autocomplete="new-password" aria-describedby="rp-pass-meter" required autofocus>
                    <button type="button" class="rp-eye" data-rp-toggle="#password" aria-label="Mostrar contraseña" aria-pressed="false">
                      <?= lucide_icon('eye', '', 17) ?>
                    </button>
                  </div>
                  <div class="rp-meter" id="rp-pass-meter" data-rp-meter data-level="0" aria-live="polite">
                    <span class="rp-meter-bar"></span>
                    <span class="rp-meter-bar"></span>
                    <span class="rp-meter-bar"></span>
                    <span class="rp-meter-bar"></span>
                    <span class="rp-meter-label" data-rp-meter-label>Sin escribir</span>
                  </div>
                </div>

                <div class="rp-field">
                  <div class="rp-label-row">
                    <label class="rp-label" for="password_confirm">Confirmar contraseña</label>
                  </div>
                  <div class="rp-input-wrap">
                    <?= lucide_icon('shield-check', '', 18) ?>
                    <input type="password" class="form-control rp-input" id="password_confirm" name="password_confirm" minlength="6" maxlength="72" autocomplete="new-password" required>
                    <button type="button" class="rp-eye" data-rp-toggle="#password_confirm" aria-label="Mostrar contraseña" aria-pressed="false">
                      <?= lucide_icon('eye', '', 17) ?>
                    </button>
                  </div>
                </div>
              </div>

              <ul class="rp-checklist" aria-label="Requisitos de la contraseña">
                <?php foreach ($requirements as $key => $label): ?>
                  <li data-rp-req="<?= $key ?>">
                    <span class="rp-check-icon" aria-hidden="true">
                      <span class="rp-check-no"><?= lucide_icon('x', '', 15) ?></span>
                      <span class="rp-check-yes"><?= lucide_icon('check', '', 15) ?></span>
                    </span>
                    <span><?= e($label) ?></span>
                  </li>
                <?php endforeach; ?>
              </ul>

              <div class="rp-submit-row">
                <div class="rp-timer" data-rp-timer data-seconds="<?= $secondsLeft ?>" data-total="3600" role="timer" aria-live="off" style="--rp-pct: <?= max(0, min(100, (int) round((3600 - $secondsLeft) / 36))) ?>">
                  <span class="rp-timer-ring" aria-hidden="true">
                    <svg class="rp-ring" viewBox="0 0 48 48" focusable="false">
                      <circle class="rp-ring-track" cx="24" cy="24" r="21" pathLength="100" />
                      <circle class="rp-ring-fill" cx="24" cy="24" r="21" pathLength="100" />
                    </svg>
                    <span class="rp-timer-icon"><?= lucide_icon('hourglass', '', 18) ?></span>
                  </span>
                  <span class="rp-timer-copy">
                    <span class="rp-timer-label" data-rp-timer-label>El enlace vence en</span>
                    <strong class="rp-timer-value" data-rp-timer-value><?= sprintf('%02d:%02d', intdiv($secondsLeft, 60), $secondsLeft % 60) ?></strong>
                  </span>
                </div>
                <button type="submit" class="btn btn-primary rp-btn rp-btn--lg" data-rp-submit>
                  <span class="rp-btn-idle"><?= lucide_icon('circle-check', '', 17) ?>Guardar contraseña</span>
                  <span class="rp-btn-loading"><?= lucide_icon('loader-circle', '', 17) ?>Guardando…</span>
                </button>
              </div>
            </form>
          <?php else: ?>
            <div class="rp-problem" role="alert">
              <span class="rp-problem-icon" aria-hidden="true"><?= lucide_icon($problem['icon'], '', 20) ?></span>
              <div>
                <p class="rp-problem-title"><?= e($problem['title']) ?></p>
                <p class="rp-problem-desc"><?= e($problem['desc']) ?></p>
              </div>
            </div>

            <div class="rp-problem-actions">
              <a href="olvide_password.php" class="btn btn-primary rp-btn rp-btn--lg">
                <?= lucide_icon('send', '', 17) ?>Solicitar un enlace nuevo
              </a>
              <a href="login.php" class="btn rp-btn rp-btn--lg rp-btn--ghost">
                <?= lucide_icon('log-in', '', 17) ?>Iniciar sesión
              </a>
            </div>

            <section class="rp-help rp-help--3" aria-labelledby="rp-help-title">
              <h2 id="rp-help-title" class="rp-help-title">¿Por qué pasa esto?</h2>
              <ul class="rp-help-list">
                <li>
                  <span class="rp-help-icon" aria-hidden="true"><?= lucide_icon('clock', '', 16) ?></span>
                  <strong>Pasó la hora</strong>
                  <span>Dura 60 minutos.</span>
                </li>
                <li>
                  <span class="rp-help-icon" aria-hidden="true"><?= lucide_icon('copy-check', '', 16) ?></span>
                  <strong>Ya se usó</strong>
                  <span>Sirve una sola vez.</span>
                </li>
                <li>
                  <span class="rp-help-icon" aria-hidden="true"><?= lucide_icon('mails', '', 16) ?></span>
                  <strong>Pediste otro</strong>
                  <span>Vale solo el último.</span>
                </li>
              </ul>
            </section>
          <?php endif; ?>
        </div>

        <footer class="rp-foot">
          <a href="login.php" class="rp-back">
            <?= lucide_icon('arrow-left', '', 16) ?>Volver a iniciar sesión
          </a>
          <p class="rp-alt">
            <span>¿No tienes cuenta?</span>
            <a href="registro.php">Crear una gratis<?= lucide_icon('arrow-right', '', 13) ?></a>
          </p>
        </footer>
      </div>

      <aside class="rp-panel rp-guide animate-reveal-up" aria-labelledby="rp-guide-title">
        <header class="rp-guide-head">
          <span class="rp-guide-deco" aria-hidden="true"><span></span><span></span><span></span></span>
          <span class="rp-guide-icon" aria-hidden="true"><?= lucide_icon($validToken ? 'key-round' : 'route', '', 24) ?></span>
          <div>
            <h2 id="rp-guide-title" class="rp-guide-title"><?= $validToken ? 'Ya casi terminas' : 'Retoma el proceso' ?></h2>
            <p class="rp-guide-sub"><?= $validToken ? 'Solo falta elegir tu nueva clave.' : 'Pide otro enlace y vuelve aquí.' ?></p>
          </div>
        </header>

        <ol class="rp-steps">
          <?php foreach ($steps as $n => $s): ?>
            <?php
              if ($validToken) {
                  $state = $n < 3 ? 'is-done' : 'is-current';
                  $icon = $n < 3 ? 'check' : $s['icon'];
                  $label = $n < 3 ? ' · Listo' : '';
              } else {
                  $state = $n === 1 ? 'is-current' : ($n === 2 ? 'is-error' : '');
                  $icon = $n === 2 ? 'link-2-off' : $s['icon'];
                  $label = $n === 1 ? ' · Repetir' : ($n === 2 ? ' · Enlace no válido' : '');
              }
              $isCurrent = $state === 'is-current';
            ?>
            <li class="rp-step <?= $state ?>"<?= $isCurrent ? ' aria-current="step"' : '' ?>>
              <span class="rp-step-badge" aria-hidden="true"><?= lucide_icon($icon, '', 17) ?></span>
              <span>
                <span class="rp-step-num">Paso 0<?= $n ?><?= $label ?></span>
                <span class="rp-step-title"><?= e($s['title']) ?></span>
                <span class="rp-step-desc"><?= e($s['desc']) ?></span>
              </span>
            </li>
          <?php endforeach; ?>
        </ol>

        <ul class="rp-facts">
          <?php if ($validToken): ?>
            <li class="rp-fact">
              <?= lucide_icon('check', '', 13) ?>
              <span><strong>Usa una frase:</strong> 3 o 4 palabras con un número son fáciles de recordar.</span>
            </li>
            <li class="rp-fact">
              <?= lucide_icon('check', '', 13) ?>
              <span><strong>Una por sitio:</strong> no repitas la de tu correo o redes.</span>
            </li>
          <?php else: ?>
            <li class="rp-fact">
              <?= lucide_icon('check', '', 13) ?>
              <span><strong>60 minutos</strong> de vigencia por cada enlace.</span>
            </li>
            <li class="rp-fact">
              <?= lucide_icon('check', '', 13) ?>
              <span><strong>Sin pérdidas:</strong> tus favoritos quedan intactos.</span>
            </li>
          <?php endif; ?>
        </ul>

        <p class="rp-note">
          <?= lucide_icon('shield-check', '', 15) ?>
          <span>
            <?php if ($validToken): ?>
              Al guardar, este enlace deja de funcionar y te llevamos a iniciar sesión.
            <?php else: ?>
              Nunca te pediremos tu contraseña por correo. Si no pediste el cambio, ignora el mensaje.
            <?php endif; ?>
          </span>
        </p>
      </aside>

    </div>
  </div>
</section>

<script>
(() => {
  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const setIcon = (container, name) => {
    if (container && window.setLucideIcon) window.setLucideIcon(container, name);
  };

  const toastStack = document.querySelector('[data-rp-toast-stack]');

  const initToast = (toast) => {
    let timer = null;
    const remove = () => toast.remove();
    const dismiss = () => {
      window.clearTimeout(timer);
      if (toast.classList.contains('is-leaving')) return;
      toast.classList.add('is-leaving');
      if (reducedMotion) remove();
      else toast.addEventListener('transitionend', remove, { once: true });
    };
    const schedule = () => {
      window.clearTimeout(timer);
      timer = window.setTimeout(dismiss, 7000);
    };
    toast.querySelector('[data-rp-toast-close]')?.addEventListener('click', dismiss);
    toast.addEventListener('mouseenter', () => window.clearTimeout(timer));
    toast.addEventListener('mouseleave', schedule);
    requestAnimationFrame(() => requestAnimationFrame(() => toast.classList.add('is-visible')));
    schedule();
  };

  const showToast = (message) => {
    if (!toastStack) return;
    toastStack.querySelectorAll('[data-rp-toast]').forEach((old) => old.remove());

    const toast = document.createElement('div');
    toast.className = 'rp-toast';
    toast.setAttribute('role', 'alert');
    toast.setAttribute('aria-live', 'assertive');
    toast.setAttribute('aria-atomic', 'true');
    toast.setAttribute('data-rp-toast', '');
    toast.innerHTML = `
      <span class="rp-toast-icon" aria-hidden="true"><i data-lucide="triangle-alert" width="18" height="18" aria-hidden="true"></i></span>
      <span class="rp-toast-text"></span>
      <button type="button" class="rp-toast-close" aria-label="Cerrar" data-rp-toast-close><i data-lucide="x" width="15" height="15" aria-hidden="true"></i></button>`;
    toast.querySelector('.rp-toast-text').textContent = message;
    toastStack.appendChild(toast);
    window.refreshLucideIcons?.();
    initToast(toast);
  };

  document.querySelectorAll('[data-rp-toast]').forEach(initToast);

  const form = document.querySelector('[data-rp-form]');
  if (!form) return;

  const pass = form.querySelector('#password');
  const confirm = form.querySelector('#password_confirm');
  const submit = form.querySelector('[data-rp-submit]');
  const meter = form.querySelector('[data-rp-meter]');
  const meterLabel = form.querySelector('[data-rp-meter-label]');
  const reqItems = form.querySelectorAll('[data-rp-req]');
  const levels = ['Sin escribir', 'Débil', 'Aceptable', 'Buena', 'Excelente'];

  const checks = (value) => ({
    length: value.length >= 6,
    case: /[a-záéíóúñ]/.test(value) && /[A-ZÁÉÍÓÚÑ]/.test(value),
    digit: /\d/.test(value),
    symbol: /[^A-Za-z0-9áéíóúñÁÉÍÓÚÑ\s]/.test(value),
  });

  const updateStrength = () => {
    const value = pass.value;
    const met = checks(value);
    const total = Object.values(met).filter(Boolean).length;

    reqItems.forEach((item) => item.classList.toggle('is-met', met[item.dataset.rpReq]));
    let level = 0;
    if (value.length > 0) {
      level = met.length ? Math.min(4, total - 1 + (value.length >= 12 ? 1 : 0)) : 1;
      level = Math.max(1, level);
    }
    meter.dataset.level = String(level);
    meterLabel.textContent = value.length > 0 && !met.length ? 'Muy corta' : levels[level];
  };

  const updatePass = (strict) => {
    if (pass.value.length < 6) {
      pass.classList.toggle('is-invalid', strict);
      return false;
    }
    pass.classList.remove('is-invalid');
    return true;
  };

  const updateMatch = (strict) => {
    confirm.classList.remove('is-valid');
    if (confirm.value === '') {
      confirm.classList.toggle('is-invalid', strict);
      return false;
    }
    if (confirm.value !== pass.value) {
      const partial = pass.value.startsWith(confirm.value) && !strict;
      confirm.classList.toggle('is-invalid', !partial);
      return false;
    }
    confirm.classList.remove('is-invalid');
    confirm.classList.add('is-valid');
    return true;
  };

  pass.addEventListener('input', () => {
    updateStrength();
    updatePass(false);
    if (confirm.value !== '') updateMatch(false);
  });
  confirm.addEventListener('input', () => updateMatch(false));

  const setVisible = (button, visible) => {
    const target = form.querySelector(button.dataset.rpToggle);
    target.type = visible ? 'text' : 'password';
    button.setAttribute('aria-pressed', visible ? 'true' : 'false');
    button.setAttribute('aria-label', visible ? 'Ocultar contraseña' : 'Mostrar contraseña');
    setIcon(button, visible ? 'eye-off' : 'eye');
  };

  const toggles = form.querySelectorAll('[data-rp-toggle]');
  toggles.forEach((button) => {
    button.addEventListener('click', () => setVisible(button, button.getAttribute('aria-pressed') !== 'true'));
  });

  const randomInt = (max) => {
    const buf = new Uint32Array(1);
    window.crypto.getRandomValues(buf);
    return buf[0] % max;
  };

  const generate = () => {
    const sets = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnpqrstuvwxyz', '23456789', '!@#$%&*?-_'];
    const all = sets.join('');
    const chars = sets.map((set) => set[randomInt(set.length)]);
    while (chars.length < 14) chars.push(all[randomInt(all.length)]);
    for (let i = chars.length - 1; i > 0; i -= 1) {
      const j = randomInt(i + 1);
      [chars[i], chars[j]] = [chars[j], chars[i]];
    }
    return chars.join('');
  };

  form.querySelector('[data-rp-suggest]')?.addEventListener('click', async () => {
    const value = generate();
    pass.value = value;
    confirm.value = value;
    toggles.forEach((button) => setVisible(button, true));
    updateStrength();
    updateMatch(false);

    pass.classList.remove('is-invalid');
    try {
      await navigator.clipboard.writeText(value);
    } catch (e) {}
  });

  form.addEventListener('submit', (event) => {
    const passOk = updatePass(true);
    const matchOk = passOk && updateMatch(true);
    if (!passOk || !matchOk) {
      event.preventDefault();
      if (!passOk) {
        showToast(pass.value === '' ? 'Escribe una nueva contraseña.' : 'La contraseña debe tener al menos 6 caracteres.');
      } else {
        showToast(confirm.value === '' ? 'Confirma tu nueva contraseña.' : 'Las contraseñas no coinciden.');
      }
      (passOk ? confirm : pass).focus();
      return;
    }
    submit.classList.add('is-loading');
    submit.setAttribute('aria-busy', 'true');
  });

  const timer = document.querySelector('[data-rp-timer]');
  if (timer) {
    const value = timer.querySelector('[data-rp-timer-value]');
    const label = timer.querySelector('[data-rp-timer-label]');
    const deadline = Date.now() + (parseInt(timer.dataset.seconds, 10) || 0) * 1000;
    const total = parseInt(timer.dataset.total, 10) || 3600;

    const tick = () => {
      const left = Math.max(0, Math.round((deadline - Date.now()) / 1000));
      timer.style.setProperty('--rp-pct', String(Math.max(0, Math.min(100, ((total - left) / total) * 100))));
      if (left === 0) {
        timer.classList.remove('is-warning');
        timer.classList.add('is-over');
        setIcon(timer.querySelector('.rp-timer-icon'), 'timer-off');
        label.textContent = 'El enlace venció';
        value.innerHTML = '<a href="olvide_password.php">Pedir uno nuevo</a>';
        submit.disabled = true;
        return;
      }
      const m = Math.floor(left / 60);
      const s = left % 60;
      value.textContent = `${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
      timer.classList.toggle('is-warning', left <= 300);
      window.setTimeout(tick, 1000);
    };
    tick();
  }
})();
</script>

<?php render_footer(); ?>
