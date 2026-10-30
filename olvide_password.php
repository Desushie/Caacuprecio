<?php
require_once __DIR__ . '/config.php';

$pdo = db();
$msg = '';
$msgType = '';
$emailPrellenado = $_GET['email'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $emailPrellenado = $email;

    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $stmt = $pdo->prepare('SELECT idusuario FROM usuario WHERE usu_email = :email LIMIT 1');
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();

        if ($user) {
            // Generar token seguro
            $token = bin2hex(random_bytes(32));

            // La expiración usa el reloj de MySQL: reset_password.php la compara con NOW()
            $update = $pdo->prepare('
                UPDATE usuario
                SET usu_reset_token = :token,
                    usu_reset_expira = DATE_ADD(NOW(), INTERVAL 1 HOUR)
                WHERE idusuario = :id
            ');

            $update->execute([
                ':token' => $token,
                ':id' => $user['idusuario']
            ]);

            // Enlace de recuperación
            $appUrl = defined('APP_URL') ? rtrim(APP_URL, '/') : 'https://caacuprecio.com';
            $resetLink = $appUrl . '/reset_password.php?token=' . urlencode($token);

            // Verificar configuración de Resend
            if (!defined('RESEND_API_KEY') || RESEND_API_KEY === '' || RESEND_API_KEY === 're_TU_API_KEY_REAL') {
                error_log('Resend no configurado: falta RESEND_API_KEY en config.php');
                $msg = 'Hubo un problema al intentar enviar el correo. Por favor, intenta más tarde.';
                $msgType = 'danger';
            } elseif (!defined('RESEND_FROM') || RESEND_FROM === '') {
                error_log('Resend no configurado: falta RESEND_FROM en config.php');
                $msg = 'Hubo un problema al intentar enviar el correo. Por favor, intenta más tarde.';
                $msgType = 'danger';
            } else {
                $safeResetLink = e($resetLink);

                $htmlContent = "
                    <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; color: #111827;'>
                        <h2 style='color: #0f172a;'>Recuperación de contraseña</h2>

                        <p>Hola,</p>

                        <p>Has solicitado restablecer tu contraseña en <strong>Caacuprecio</strong>.</p>

                        <p>Haz clic en el siguiente botón para crear una nueva contraseña. Este enlace expira en 1 hora:</p>

                        <p style='text-align: center; margin: 30px 0;'>
                            <a href='{$safeResetLink}' style='padding: 12px 24px; background-color: #0d6efd; color: white; text-decoration: none; border-radius: 6px; font-weight: bold; display: inline-block;'>
                                Restablecer contraseña
                            </a>
                        </p>

                        <p>Si el botón no funciona, copia y pega este enlace en tu navegador:</p>

                        <p style='word-break: break-all; color: #6c757d;'>
                            <a href='{$safeResetLink}'>{$safeResetLink}</a>
                        </p>

                        <hr style='border: none; border-top: 1px solid #eee; margin: 20px 0;'>

                        <p style='font-size: 12px; color: #6c757d;'>
                            Si no fuiste tú, puedes ignorar este mensaje de forma segura.
                        </p>
                    </div>
                ";

                $postData = json_encode([
                    'from' => RESEND_FROM,
                    'to' => [$email],
                    'subject' => 'Recuperar contraseña - Caacuprecio',
                    'html' => $htmlContent
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                $ch = curl_init('https://api.resend.com/emails');

                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $postData,
                    CURLOPT_HTTPHEADER => [
                        'Authorization: Bearer ' . RESEND_API_KEY,
                        'Content-Type: application/json',
                        'User-Agent: Caacuprecio/1.0'
                    ],
                    CURLOPT_TIMEOUT => 20,
                ]);

                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);

                curl_close($ch);

                if ($response !== false && $httpCode >= 200 && $httpCode < 300) {
                    $msg = 'Si el correo está registrado, recibirás un enlace para recuperar tu contraseña.';
                    $msgType = 'success';
                } else {
                    error_log("Error enviando email con Resend. HTTP {$httpCode}. Respuesta: " . ($response ?: $curlError));
                    $msg = 'Hubo un problema al intentar enviar el correo. Por favor, intenta más tarde.';
                    $msgType = 'danger';
                }
            }
        } else {
            $msg = 'Si el correo está registrado, recibirás un enlace para recuperar tu contraseña.';
            $msgType = 'success';
        }
    } else {
        $msg = 'Por favor, ingresa un correo electrónico válido.';
        $msgType = 'danger';
    }
}

$sent = $msgType === 'success';
$loggedIn = is_logged_in();
$backHref = $loggedIn ? 'usuario.php' : 'login.php';
$backLabel = $loggedIn ? 'Mi cuenta' : 'Iniciar sesión';
$step = $sent ? 2 : 1;
$steps = [
    1 => ['icon' => 'at-sign', 'title' => 'Ingresa tu correo', 'desc' => 'El mismo con el que inicias sesión.'],
    2 => ['icon' => 'inbox', 'title' => 'Abre el mensaje', 'desc' => 'Asunto: “Recuperar contraseña”.'],
    3 => ['icon' => 'lock-keyhole', 'title' => 'Crea una nueva', 'desc' => 'Toca el botón y elige tu contraseña.'],
];

render_head('Recuperar contraseña');
render_navbar('login');
?>

<style>
.rp-page {
  --rp-line: var(--border-soft);
  --rp-tint: color-mix(in srgb, var(--brand) 10%, transparent);
  --rp-amber: var(--accent);
  --rp-nav: 4.35rem;
  --rp-pad-x: clamp(0.85rem, 2.4vw, 1.6rem);
  --rp-pad-y: clamp(1rem, 2vw, 1.45rem);
  padding: 1.15rem 0 max(3.5rem, env(safe-area-inset-bottom, 0px) + 2rem);
  overflow-x: clip;
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
  grid-template-columns: minmax(0, 1.25fr) minmax(17.5rem, 0.85fr);
  gap: clamp(0.7rem, 1.5vw, 0.95rem);
  align-items: stretch;
  width: 100%;
}

.rp-panel {
  position: relative;
  min-width: 0;
  width: 100%;
  background: var(--bg-card);
  border: 1px solid var(--rp-line);
  border-radius: clamp(0.95rem, 2.2vw, 1.15rem);
  overflow: hidden;
  scrollbar-width: thin;
}

/* ── Panel principal ── */
.rp-main {
  display: flex;
  flex-direction: column;
  padding: var(--rp-pad-y) var(--rp-pad-x) 1.15rem;
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
  display: flex;
  align-items: flex-start;
  gap: 0.95rem;
  padding-right: 4rem;
}

.rp-title {
  margin: 0.15rem 0 0.3rem;
  font-size: clamp(1.35rem, 1.05rem + 1.4vw, var(--fs-h1));
  font-weight: var(--fw-bold);
  letter-spacing: var(--ls-heading);
  line-height: var(--lh-heading);
  color: var(--text-main);
  text-wrap: balance;
}

.rp-lead {
  margin: 0;
  max-width: 34rem;
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
  --rp-toast-bg: #2e9d5b;
  display: flex;
  align-items: center;
  gap: 0.6rem;
  max-width: min(23rem, calc(100vw - 2.5rem));
  padding: 0.6rem 0.5rem 0.6rem 0.8rem;
  border-radius: var(--radius-control);
  background: var(--rp-toast-bg);
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

.rp-toast--danger { --rp-toast-bg: #d14f4f; }

.rp-toast.is-visible {
  opacity: 1;
  transform: none;
}

.rp-toast.is-leaving {
  opacity: 0;
  transform: translateX(1rem);
}

.rp-toast-icon,
.rp-toast-close {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.rp-toast-text {
  flex: 1;
  min-width: 0;
}

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

/* Zona central: formulario o confirmación */
.rp-body {
  display: flex;
  flex: 1 0 auto;
  flex-direction: column;
  justify-content: center;
  padding: 1.15rem 0;
}

.rp-form {
  display: grid;
  gap: 0.45rem;
}

.rp-label {
  margin: 0;
  color: var(--text-main);
  font-size: var(--fs-caption);
  font-weight: var(--fw-semibold);
}

.rp-combo {
  display: flex;
  gap: 0.5rem;
}

.rp-input-wrap {
  position: relative;
  flex: 1 1 auto;
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
  padding: 0.6rem 0.9rem 0.6rem 2.7rem;
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

.rp-page .rp-input:hover { border-color: var(--border-strong) !important; }

.rp-page .rp-input:focus {
  border-color: var(--brand) !important;
  outline: none;
}

body .rp-page .rp-input.is-invalid,
body.theme-light .rp-page .rp-input.is-invalid,
body:not(.theme-light) .rp-page .rp-input.is-invalid {
  border-color: var(--danger) !important;
  background-color: color-mix(in srgb, var(--danger) 8%, transparent) !important;
}

body .rp-page .rp-input.is-invalid:focus,
body.theme-light .rp-page .rp-input.is-invalid:focus,
body:not(.theme-light) .rp-page .rp-input.is-invalid:focus {
  border-color: var(--danger) !important;
  background-color: color-mix(in srgb, var(--danger) 8%, transparent) !important;
  box-shadow: 0 0 0 3px color-mix(in srgb, var(--danger) 22%, transparent) !important;
}

.rp-input-wrap:has(.rp-input.is-invalid) > svg.lucide { --icon-c: var(--danger); }

.rp-hint {
  display: flex;
  align-items: flex-start;
  gap: 0.4rem;
  margin: 0.1rem 0 0;
  color: var(--text-soft);
  font-size: var(--fs-caption);
  line-height: var(--lh-small);
}

.rp-hint svg.lucide {
  --icon-c: currentColor;
  display: block;
  flex: 0 0 auto;
  margin-top: calc((var(--lh-small) * 1em - 14px) / 2);
}

.rp-hint.is-error { color: var(--danger); }

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
  flex: 0 0 auto;
  min-height: 3.1rem;
  padding-inline: 1.3rem;
  font-weight: var(--fw-semibold);
}

.rp-page .btn-primary.rp-btn svg.lucide { --icon-c: #fff; }

.rp-btn-idle { display: inline-flex; align-items: center; gap: 0.5rem; }
.rp-btn-loading { display: none; }

.rp-btn.is-loading { pointer-events: none; opacity: 0.8; }
.rp-btn.is-loading .rp-btn-idle { display: none; }
.rp-btn.is-loading .rp-btn-loading { display: inline-flex; align-items: center; gap: 0.5rem; }
.rp-btn.is-loading .rp-btn-loading svg.lucide { animation: rp-spin 0.9s linear infinite; }

@keyframes rp-spin { to { transform: rotate(360deg); } }

/* Estado: correo enviado */
.rp-sent-card {
  display: flex;
  align-items: center;
  gap: 0.8rem;
  padding: 0.8rem 0.95rem;
  border: 1px dashed color-mix(in srgb, var(--success) 40%, var(--rp-line));
  border-radius: 0.95rem;
  background: color-mix(in srgb, var(--success) 6%, transparent);
}

.rp-sent-icon {
  --icon-c: var(--success);
  display: grid;
  flex: 0 0 auto;
  place-items: center;
  width: 2.4rem;
  height: 2.4rem;
  border-radius: 999px;
  background: color-mix(in srgb, var(--success) 14%, transparent);
}

.rp-sent-copy {
  display: grid;
  flex: 1 1 auto;
  min-width: 0;
}

.rp-sent-card .rp-chip { flex: 0 0 auto; }

.rp-sent-label {
  color: var(--text-soft);
  font-size: var(--fs-caption);
}

.rp-sent-email {
  color: var(--text-main);
  font-weight: var(--fw-semibold);
  overflow-wrap: anywhere;
}

.rp-chip {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  padding: 0.22rem 0.65rem;
  border: 1px solid var(--rp-line);
  border-radius: 999px;
  color: var(--text-soft);
  font-size: var(--fs-caption);
  font-weight: var(--fw-medium);
}

.rp-chip svg.lucide { --icon-c: var(--rp-amber); }

.rp-sent-actions {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.5rem;
  margin-top: 0.75rem;
}

.rp-sent-actions form { display: contents; }

.rp-page .rp-btn:disabled { opacity: 0.5; }

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

.rp-page .rp-btn--ghost:disabled {
  --icon-c: var(--text-soft);
  border-color: var(--rp-line);
  background: transparent;
  color: var(--text-soft);
  opacity: 0.75;
}

/* Ayuda */
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
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 0.5rem;
  margin: 0;
  padding: 0.95rem 0.7rem 0.7rem;
  list-style: none;
}

.rp-help-list li {
  display: grid;
  grid-template-columns: 2rem minmax(0, 1fr);
  align-content: center;
  align-items: center;
  column-gap: 0.6rem;
  row-gap: 0.1rem;
  min-width: 0;
  padding: 0.6rem 0.75rem 0.65rem;
  border: 1px solid var(--rp-line);
  border-radius: 0.75rem;
  background: color-mix(in srgb, var(--brand) 3%, transparent);
}

.rp-help-icon {
  --icon-c: color-mix(in srgb, var(--brand-2) 75%, var(--text-soft));
  display: grid;
  grid-row: span 2;
  place-items: center;
  width: 2rem;
  height: 2rem;
  border-radius: 0.55rem;
  background: color-mix(in srgb, var(--brand) 9%, transparent);
}

.rp-help-list strong,
.rp-help-list li > span:last-child {
  grid-column: 2;
  min-width: 0;
}

.rp-help-list strong {
  color: var(--text-main);
  font-size: var(--fs-small);
  font-weight: var(--fw-bold);
  line-height: 1.25;
}

.rp-help-list li > span:last-child {
  color: var(--text-soft);
  font-size: var(--fs-caption);
  line-height: 1.45;
  text-wrap: pretty;
  overflow-wrap: anywhere;
}

/* Pie del panel */
.rp-foot {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 0.6rem 1rem;
  margin-top: 1rem;
  padding-top: 0.9rem;
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
  transition: background-color 0.2s ease, color 0.2s ease;
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

.rp-foot svg.lucide {
  flex: 0 0 auto;
  width: 1.15em !important;
  height: 1.15em !important;
}

.rp-alt a:hover,
.rp-alt a:focus-visible { text-decoration: underline; }

.rp-alt a:hover svg.lucide,
.rp-alt a:focus-visible svg.lucide { transform: translateX(3px); }

/* ── Guía lateral ── */
.rp-guide {
  display: flex;
  flex-direction: column;
}

.rp-guide-top {
  display: flex;
  flex: 1 1 auto;
  flex-direction: column;
  min-height: 0;
}

.rp-guide-head {
  position: relative;
  display: flex;
  flex-shrink: 0;
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

/* Los pasos crecen con el alto libre del panel, con tope para no separarse de más */
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

.rp-step-title {
  display: block;
  margin-top: 0.05rem;
  color: var(--text-main);
  font-size: var(--fs-small);
  font-weight: var(--fw-semibold);
}

.rp-step:not(.is-current):not(.is-done) .rp-step-title { color: var(--text-soft); }

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
  padding: 1.15rem 1.35rem 1.15rem;
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

/* Escritorio: el tablero ocupa el alto disponible de la pantalla */
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
    flex: 1 1 0;
    grid-template-rows: minmax(0, 1fr);
    min-height: 0;
    max-height: min(35rem, calc(100dvh - var(--rp-nav) - 5.5rem));
  }

  .rp-main,
  .rp-guide { overflow-y: auto; }
}

/* Ambos paneles comparten filas: el bloque gris queda a la altura de las tarjetas de ayuda */
@media (min-width: 992px) {
  .rp-board {
    grid-template-columns: minmax(0, 1.35fr) minmax(17.5rem, 0.8fr);
    grid-template-rows: auto auto 1fr auto auto;
    row-gap: 0;
  }

  .rp-main,
  .rp-guide {
    display: grid;
    grid-row: 1 / -1;
    grid-template-rows: subgrid;
    overflow: hidden;
  }

  .rp-head { grid-row: 1; }
  .rp-body { grid-row: 3; }
  .rp-help { grid-row: 4; }
  .rp-foot { grid-row: 5; }

  .rp-guide-top { grid-row: 1 / 4; }
  .rp-facts {
    grid-row: 4;
    align-content: center;
    margin-top: calc(0.5rem + 1px + 0.95rem);
    margin-bottom: calc(0.7rem + 1px);
  }
  .rp-note { grid-row: 5; }

  .rp-help {
    display: flex;
    flex-direction: column;
  }

  .rp-help-list { flex: 1 1 auto; }
}

/* Tablet y móvil: una columna fluida */
@media (max-width: 991.98px) {
  .rp-page {
    --rp-pad-x: clamp(0.95rem, 3.2vw, 1.35rem);
    padding-top: 0.95rem;
    padding-bottom: max(2.75rem, env(safe-area-inset-bottom, 0px) + 1.5rem);
  }

  .rp-board {
    grid-template-columns: minmax(0, 1fr);
    gap: 0.75rem;
    max-width: 42rem;
    margin-inline: auto;
  }

  .rp-body {
    padding: 0.95rem 0;
  }

  .rp-help-list {
    grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr));
  }

  .rp-guide-head {
    padding: 1rem var(--rp-pad-x) 0.9rem;
  }

  .rp-steps {
    padding: 1.1rem var(--rp-pad-x) 0.95rem;
  }

  .rp-step:not(:last-child) {
    max-height: none;
    padding-bottom: 1rem;
  }

  .rp-facts {
    margin: 0 var(--rp-pad-x);
  }

  .rp-note {
    padding: 1rem var(--rp-pad-x) 1.05rem;
  }

  .rp-page .pd-crumb-current {
    max-width: min(18rem, 52vw);
  }
}

/* Móvil */
@media (max-width: 575.98px) {
  .rp-page {
    --rp-pad-x: 1rem;
    --rp-pad-y: 1.15rem;
    padding-top: 0.75rem;
  }

  .rp-board {
    gap: 0.7rem;
    max-width: none;
  }

  .rp-main {
    padding-bottom: 1rem;
  }

  .rp-head { padding-right: 0; }
  .rp-mark { display: none; }

  .rp-combo {
    flex-direction: column;
    gap: 0.65rem;
  }

  .rp-combo + .rp-hint {
    margin-top: 0.75rem;
  }

  .rp-page .rp-input {
    min-height: 3rem;
    font-size: 1rem; /* evita zoom iOS */
  }

  .rp-page .rp-btn--lg {
    width: 100%;
    min-height: 3rem;
  }

  .rp-sent-card {
    flex-wrap: wrap;
    gap: 0.65rem;
  }

  .rp-sent-actions,
  .rp-help-list {
    grid-template-columns: minmax(0, 1fr);
  }

  .rp-help-list {
    padding: 0.9rem 0.65rem 0.65rem;
    gap: 0.45rem;
  }

  .rp-help-list li {
    padding: 0.55rem 0.7rem 0.6rem;
  }

  .rp-foot {
    flex-direction: column;
    align-items: flex-start;
    gap: 0.55rem;
  }

  .rp-alt {
    flex-wrap: wrap;
  }

  .rp-guide-deco span:nth-child(1) {
    width: 7rem;
    height: 7rem;
    right: -3rem;
    top: -3.5rem;
  }

  .rp-guide-deco span:nth-child(2) {
    width: 3.6rem;
    height: 3.6rem;
    right: 2.2rem;
    top: 1.35rem;
  }

  .rp-guide-icon {
    width: 2.45rem;
    height: 2.45rem;
    border-radius: 0.75rem;
  }

  .rp-guide-icon svg.lucide {
    width: 1.3rem !important;
    height: 1.3rem !important;
  }

  .rp-steps {
    padding-top: 1rem;
    padding-bottom: 0.85rem;
  }

  .rp-step {
    gap: 0.65rem;
  }

  .rp-step:not(:last-child) {
    padding-bottom: 0.85rem;
  }

  .rp-toast-stack {
    top: max(4.25rem, env(safe-area-inset-top, 0px) + 3.5rem);
    right: 0.75rem;
    left: 0.75rem;
    align-items: stretch;
  }

  .rp-toast { max-width: none; }

  .rp-page .pd-crumb {
    margin-bottom: 0.65rem;
  }

  .rp-page .pd-crumb-current {
    max-width: min(11.5rem, 48vw);
  }
}

/* Móviles muy angostos */
@media (max-width: 379.98px) {
  .rp-page {
    --rp-pad-x: 0.85rem;
    --rp-pad-y: 1rem;
  }

  .rp-kicker {
    font-size: 0.65rem;
  }

  .rp-title {
    font-size: clamp(1.2rem, 6.5vw, 1.4rem);
  }

  .rp-lead {
    font-size: var(--fs-caption);
  }

  .rp-page .pd-crumb-list .breadcrumb-item:nth-child(2) {
    display: none;
  }

  .rp-page .pd-crumb-current {
    max-width: min(10rem, 55vw);
  }

  .rp-guide-title {
    font-size: var(--fs-small);
  }
}

/* Landscape bajo: compactar verticalmente */
@media (max-height: 560px) and (orientation: landscape) {
  .rp-page {
    padding-top: 0.55rem;
    padding-bottom: 1.25rem;
  }

  .rp-body {
    padding: 0.55rem 0;
    justify-content: flex-start;
  }

  .rp-help {
    margin-top: 0.35rem;
  }

  .rp-steps {
    padding-top: 0.75rem;
    padding-bottom: 0.65rem;
  }

  .rp-step:not(:last-child) {
    max-height: none;
    padding-bottom: 0.65rem;
  }
}

@media (prefers-reduced-motion: reduce) {
  .rp-back svg.lucide,
  .rp-alt a svg.lucide,
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

<?php if ($msg !== '' && !$sent): ?>
  <div class="rp-toast-stack">
    <div class="rp-toast rp-toast--danger" role="alert" aria-live="assertive" aria-atomic="true" data-rp-toast>
      <span class="rp-toast-icon" aria-hidden="true"><?= lucide_icon('triangle-alert', '', 18) ?></span>
      <span class="rp-toast-text"><?= e($msg) ?></span>
      <button type="button" class="rp-toast-close" aria-label="Cerrar" data-rp-toast-close><?= lucide_icon('x', '', 15) ?></button>
    </div>
  </div>
<?php endif; ?>

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
          <a href="<?= $backHref ?>" class="pd-crumb-link"><?= e($backLabel) ?></a>
        </li>
        <li class="breadcrumb-item active" aria-current="page">
          <span class="pd-crumb-current" title="Recuperar contraseña">Recuperar contraseña</span>
        </li>
      </ol>
    </nav>

    <div class="rp-board">

      <div class="rp-panel rp-main animate-reveal-up">
        <span class="rp-mark" aria-hidden="true"><?= lucide_icon($sent ? 'mail-check' : 'key-round', '', 110) ?></span>

        <header class="rp-head">
          <div>
            <p class="rp-kicker"><?= $sent ? 'Paso 2 de 3' : 'Acceso a tu cuenta' ?></p>
            <h1 id="rp-title" class="rp-title"><?= $sent ? 'Revisa tu correo' : 'Recuperar contraseña' ?></h1>
            <p class="rp-lead">
              <?php if ($sent): ?>
                Si la dirección está registrada, ya tienes un enlace para crear una contraseña nueva.
              <?php else: ?>
                Te enviamos un enlace seguro para crear una contraseña nueva. Tus favoritos y datos se mantienen.
              <?php endif; ?>
            </p>
          </div>
        </header>

        <div class="rp-body">
          <?php if ($sent): ?>
            <div class="rp-sent-card" role="status">
              <span class="rp-sent-icon" aria-hidden="true"><?= lucide_icon('circle-check', '', 19) ?></span>
              <span class="rp-sent-copy">
                <span class="rp-sent-label">Enviado a</span>
                <span class="rp-sent-email"><?= e($emailPrellenado) ?></span>
              </span>
              <span class="rp-chip"><?= lucide_icon('link', '', 14) ?>Un solo uso</span>
            </div>

            <div class="rp-sent-actions">
              <form method="post" action="olvide_password.php" data-rp-resend>
                <input type="hidden" name="email" value="<?= e($emailPrellenado) ?>">
                <button type="submit" class="btn rp-btn rp-btn--ghost" data-rp-resend-btn data-cooldown="30" disabled>
                  <?= lucide_icon('refresh-cw', '', 16) ?>
                  <span data-rp-resend-label>Reenviar en 30 s</span>
                </button>
              </form>
              <a href="olvide_password.php" class="btn rp-btn rp-btn--ghost">
                <?= lucide_icon('pencil', '', 16) ?>Usar otro correo
              </a>
            </div>
          <?php else: ?>
            <form method="post" action="olvide_password.php" class="rp-form" data-rp-form novalidate>
              <div class="rp-field">
                <label class="rp-label" for="email">Correo electrónico</label>
                <div class="rp-combo">
                  <div class="rp-input-wrap">
                    <?= lucide_icon('mail', '', 18) ?>
                    <input
                      type="email"
                      class="form-control rp-input"
                      id="email"
                      name="email"
                      maxlength="120"
                      autocomplete="email"
                      inputmode="email"
                      placeholder="tucorreo@ejemplo.com"
                      aria-describedby="rp-email-hint"
                      required
                      <?= $emailPrellenado === '' ? 'autofocus' : '' ?>
                      value="<?= e($emailPrellenado) ?>"
                    >
                  </div>
                  <button type="submit" class="btn btn-primary rp-btn rp-btn--lg" data-rp-submit>
                    <span class="rp-btn-idle"><?= lucide_icon('send', '', 17) ?>Enviar enlace</span>
                    <span class="rp-btn-loading"><?= lucide_icon('loader-circle', '', 17) ?>Enviando…</span>
                  </button>
                </div>
                <p class="rp-hint" id="rp-email-hint" data-rp-hint>
                  <?= lucide_icon('info', '', 14) ?>
                  <span data-rp-hint-text>Usa el correo con el que te registraste.</span>
                </p>
              </div>
            </form>
          <?php endif; ?>
        </div>

        <section class="rp-help" aria-labelledby="rp-help-title">
          <h2 id="rp-help-title" class="rp-help-title">¿No te llega el correo?</h2>
          <ul class="rp-help-list">
            <li>
              <span class="rp-help-icon" aria-hidden="true"><?= lucide_icon('folder-search', '', 16) ?></span>
              <strong>Revisa spam</strong>
              <span>Y en Promociones.</span>
            </li>
            <li>
              <span class="rp-help-icon" aria-hidden="true"><?= lucide_icon('spell-check', '', 16) ?></span>
              <strong>Verifica errores</strong>
              <span>Revisa cada letra.</span>
            </li>
            <li>
              <span class="rp-help-icon" aria-hidden="true"><?= lucide_icon('clock', '', 16) ?></span>
              <strong>Espera un poco</strong>
              <span>Llega en 1-2 min.</span>
            </li>
          </ul>
        </section>

        <footer class="rp-foot">
          <a href="<?= $backHref ?>" class="rp-back">
            <?= lucide_icon('arrow-left', '', 16) ?>Volver a <?= e(mb_strtolower($backLabel)) ?>
          </a>
          <?php if (!$loggedIn): ?>
            <p class="rp-alt">
              <span>¿No tienes cuenta?</span>
              <a href="registro.php">Crear una gratis<?= lucide_icon('arrow-right', '', 13) ?></a>
            </p>
          <?php endif; ?>
        </footer>
      </div>

      <aside class="rp-panel rp-guide animate-reveal-up" aria-labelledby="rp-guide-title">
        <div class="rp-guide-top">
          <header class="rp-guide-head">
            <span class="rp-guide-deco" aria-hidden="true"><span></span><span></span><span></span></span>
            <span class="rp-guide-icon" aria-hidden="true"><?= lucide_icon('wand-sparkles', '', 24) ?></span>
            <div>
              <h2 id="rp-guide-title" class="rp-guide-title">Cómo funciona</h2>
              <p class="rp-guide-sub">Tres pasos, todo desde tu correo.</p>
            </div>
          </header>

          <ol class="rp-steps">
            <?php foreach ($steps as $n => $s): ?>
              <?php $state = $n < $step ? 'is-done' : ($n === $step ? 'is-current' : ''); ?>
              <li class="rp-step <?= $state ?>"<?= $n === $step ? ' aria-current="step"' : '' ?>>
                <span class="rp-step-badge" aria-hidden="true">
                  <?= lucide_icon($n < $step ? 'check' : $s['icon'], '', 17) ?>
                </span>
                <span>
                  <span class="rp-step-num">Paso 0<?= $n ?><?= $n < $step ? ' · Listo' : '' ?></span>
                  <span class="rp-step-title"><?= e($s['title']) ?></span>
                  <span class="rp-step-desc"><?= e($s['desc']) ?></span>
                </span>
              </li>
            <?php endforeach; ?>
          </ol>
        </div>

        <ul class="rp-facts">
          <li class="rp-fact">
            <?= lucide_icon('check', '', 13) ?>
            <span><strong>60 minutos</strong> de vigencia del enlace.</span>
          </li>
          <li class="rp-fact">
            <?= lucide_icon('check', '', 13) ?>
            <span><strong>Sin pérdidas:</strong> tus favoritos quedan intactos.</span>
          </li>
        </ul>

        <p class="rp-note">
          <?= lucide_icon('shield-check', '', 15) ?>
          <span>Nunca te pediremos tu contraseña por correo. Si no pediste el cambio, ignora el mensaje.</span>
        </p>
      </aside>

    </div>
  </div>
</section>

<script>
(() => {
  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  document.querySelectorAll('[data-rp-toast]').forEach((toast) => {
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
      timer = window.setTimeout(dismiss, 7000);
    };

    toast.querySelector('[data-rp-toast-close]')?.addEventListener('click', dismiss);
    toast.addEventListener('mouseenter', () => window.clearTimeout(timer));
    toast.addEventListener('mouseleave', schedule);

    requestAnimationFrame(() => requestAnimationFrame(() => toast.classList.add('is-visible')));
    schedule();
  });

  const form = document.querySelector('[data-rp-form]');
  if (form) {
    const input = form.querySelector('#email');
    const hint = form.querySelector('[data-rp-hint]');
    const hintText = form.querySelector('[data-rp-hint-text]');
    const submit = form.querySelector('[data-rp-submit]');
    const defaultHint = hintText.textContent;

    const setError = (message) => {
      input.classList.toggle('is-invalid', Boolean(message));
      input.setAttribute('aria-invalid', message ? 'true' : 'false');
      hint.classList.toggle('is-error', Boolean(message));
      hintText.textContent = message || defaultHint;
    };

    input.addEventListener('input', () => {
      if (input.classList.contains('is-invalid') && input.validity.valid) setError('');
    });

    form.addEventListener('submit', (event) => {
      input.value = input.value.trim();
      if (input.value === '') {
        event.preventDefault();
        setError('Escribe tu correo electrónico.');
        input.focus();
        return;
      }
      if (!input.validity.valid) {
        event.preventDefault();
        setError('Revisa el formato, por ejemplo: nombre@correo.com');
        input.focus();
        return;
      }
      submit.classList.add('is-loading');
      submit.setAttribute('aria-busy', 'true');
    });
  }

  const resendBtn = document.querySelector('[data-rp-resend-btn]');
  if (resendBtn) {
    const label = resendBtn.querySelector('[data-rp-resend-label]');
    let left = parseInt(resendBtn.dataset.cooldown, 10) || 30;
    const tick = () => {
      if (left <= 0) {
        resendBtn.disabled = false;
        label.textContent = 'Reenviar enlace';
        return;
      }
      label.textContent = `Reenviar en ${left} s`;
      left -= 1;
      window.setTimeout(tick, 1000);
    };
    tick();
    resendBtn.closest('form').addEventListener('submit', () => {
      resendBtn.disabled = true;
      label.textContent = 'Enviando…';
    });
  }
})();
</script>

<?php render_footer(); ?>
