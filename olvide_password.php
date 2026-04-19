<?php
require_once __DIR__ . '/config.php';

// Si ya está logueado, lo mandamos al inicio
if (is_logged_in()) {
    header('Location: index.php');
    exit;
}

$pdo = db();
$msg = '';
$msgType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    if ($email !== '') {
        $stmt = $pdo->prepare('SELECT idusuario FROM usuario WHERE usu_email = :email LIMIT 1');
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();

        if ($user) {
            // Generar token seguro y fecha de expiración (1 hora)
            $token = bin2hex(random_bytes(32));
            $expira = date('Y-m-d H:i:s', strtotime('+1 hour'));

            // Guardar el token en la BD
            $update = $pdo->prepare('UPDATE usuario SET usu_reset_token = :token, usu_reset_expira = :expira WHERE idusuario = :id');
            $update->execute([
                ':token' => $token,
                ':expira' => $expira,
                ':id' => $user['idusuario']
            ]);

            // Enlace de recuperación
            // OJO: Asegúrate de que esta URL sea la correcta cuando estés en producción
            $resetLink = "https://" . $_SERVER['HTTP_HOST'] . "/reset_password.php?token=" . $token;

            // =====================================================================
            // INTEGRACIÓN CON RESEND API (vía cURL)
            // =====================================================================
            
            // 1. Reemplaza esto con tu API Key real de Resend
            $resendApiKey = '***REMOVED***'; 
            
            // 2. Reemplaza el correo con el dominio que verificaste en Resend
            $fromEmail = 'Caacuprecio <soporte@tudominio.com>'; 

            $htmlContent = "
                <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
                    <h2>Recuperación de contraseña</h2>
                    <p>Hola,</p>
                    <p>Has solicitado restablecer tu contraseña en Caacuprecio. Haz clic en el siguiente botón para crear una nueva (este enlace expira en 1 hora):</p>
                    <p style='text-align: center; margin: 30px 0;'>
                        <a href='{$resetLink}' style='padding: 12px 24px; background-color: #0d6efd; color: white; text-decoration: none; border-radius: 6px; font-weight: bold;'>Restablecer Contraseña</a>
                    </p>
                    <p>Si el botón no funciona, copia y pega este enlace en tu navegador:</p>
                    <p style='word-break: break-all; color: #6c757d;'>{$resetLink}</p>
                    <hr style='border: none; border-top: 1px solid #eee; margin: 20px 0;'>
                    <p style='font-size: 12px; color: #6c757d;'>Si no fuiste tú, puedes ignorar este mensaje de forma segura.</p>
                </div>
            ";

            // Preparamos los datos para Resend
            $postData = json_encode([
                'from' => $fromEmail,
                'to' => [$email],
                'subject' => 'Recuperar contraseña - Caacuprecio',
                'html' => $htmlContent
            ]);

            // Ejecutamos la petición POST a la API de Resend
            $ch = curl_init('https://api.resend.com/emails');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $resendApiKey,
                'Content-Type: application/json'
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            // Si el código es 200, se envió correctamente
            if ($httpCode === 200) {
                $msg = 'Si el correo está registrado, recibirás un enlace para recuperar tu contraseña.';
                $msgType = 'success';
            } else {
                // Si falla, guardamos el error en el log del servidor para poder depurarlo
                error_log("Error enviando email con Resend: " . $response);
                $msg = 'Hubo un problema al intentar enviar el correo. Por favor, intenta más tarde.';
                $msgType = 'danger';
            }
            // =====================================================================

        } else {
            $msg = 'Si el correo está registrado, recibirás un enlace para recuperar tu contraseña.';
            $msgType = 'success';
        }
    } else {
        $msg = 'Por favor, ingresá tu correo electrónico.';
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
        <div class="alert alert-<?= e($msgType) ?> rounded-4 mb-4"><?= $msg ?></div>
      <?php endif; ?>

      <form method="post" action="olvide_password.php" class="row g-3">
        <div class="col-12">
          <label for="email" class="form-label">Correo electrónico</label>
          <input type="email" class="form-control rounded-4" id="email" name="email" required>
        </div>
        <div class="col-12 d-grid mt-4">
          <button class="btn btn-primary btn-lg rounded-4" type="submit">
            <i class="bi bi-envelope-paper me-2"></i>Enviar enlace
          </button>
        </div>
      </form>
      
      <div class="text-center mt-4">
        <a href="login.php" class="text-decoration-none small"><i class="bi bi-arrow-left me-1"></i> Volver al inicio de sesión</a>
      </div>
    </div>

  </div>
</section>

<?php render_footer(); ?>