<?php
require_once __DIR__ . '/config.php';

$pdo = db();
$msg = '';
$msgType = '';
$emailPrellenado = $_GET['email'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $stmt = $pdo->prepare('SELECT idusuario FROM usuario WHERE usu_email = :email LIMIT 1');
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();

        if ($user) {
            // Generar token seguro y fecha de expiración
            $token = bin2hex(random_bytes(32));
            $expira = date('Y-m-d H:i:s', strtotime('+1 hour'));

            // Guardar el token en la BD
            $update = $pdo->prepare('
                UPDATE usuario
                SET usu_reset_token = :token,
                    usu_reset_expira = :expira
                WHERE idusuario = :id
            ');

            $update->execute([
                ':token' => $token,
                ':expira' => $expira,
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
        $msg = 'Por favor, ingresá un correo electrónico válido.';
        $msgType = 'danger';
    }
}

render_head('Recuperar contraseña');
render_navbar('login');
?>

<section class="auth-shell position-relative overflow-hidden" style="min-height: 80vh;">
  <div class="auth-orb orb-1"></div>
  <div class="auth-grid"></div>
  <div class="container py-5 auth-content d-flex justify-content-center align-items-center">

    <div class="detail-card p-4 p-lg-5 auth-card" style="max-width: 500px; width: 100%;">
      <div class="d-flex align-items-center gap-3 mb-4">
        <div class="icon-wrap"><i class="bi bi-key"></i></div>
        <div>
          <h2 class="h4 fw-bold mb-0">Recuperar contraseña</h2>
        </div>
      </div>

      <p class="text-body-secondary small mb-4">Ingresá el correo electrónico asociado a tu cuenta y te enviaremos un enlace para que puedas restablecerla.</p>

      <?php if ($msg): ?>
        <div class="alert alert-<?= e($msgType) ?> rounded-4 mb-4"><?= e($msg) ?></div>
      <?php endif; ?>

      <form method="post" action="olvide_password.php" class="row g-3">
        <div class="col-12">
          <label for="email" class="form-label">Correo electrónico</label>
          <input type="email" class="form-control rounded-4" id="email" name="email" required value="<?= e($emailPrellenado) ?>">
        </div>
        <div class="col-12 d-grid mt-4">
          <button class="btn btn-primary btn-lg rounded-4" type="submit">
            <i class="bi bi-envelope-paper me-2"></i>Enviar enlace
          </button>
        </div>
      </form>

      <div class="text-center mt-4">
        <a href="login.php" class="text-decoration-none small"><i class="bi bi-arrow-left me-1"></i> Volver al Inicio</a>
      </div>
    </div>

  </div>
</section>

<?php render_footer(); ?>
