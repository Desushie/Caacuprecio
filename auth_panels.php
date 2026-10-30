<?php
/**
 * Paneles login/registro en el mismo board (cambio sin recargar).
 *
 * Espera variables opcionales:
 * - $authMode: 'login'|'register'
 * - $redirectTo, $backHref
 * - $loginEmail, $loginErrors
 * - $regNombre, $regEmail, $regErrors, $regStartStep
 */

$authMode = ($authMode ?? 'login') === 'register' ? 'register' : 'login';
$redirectTo = $redirectTo ?? 'index.php';
$loginEmail = $loginEmail ?? '';
$loginErrors = $loginErrors ?? [];
$regNombre = $regNombre ?? '';
$regEmail = $regEmail ?? '';
$regErrors = $regErrors ?? [];
$regStartStep = (int) ($regStartStep ?? 1);
if ($regStartStep !== 2) {
    $regStartStep = 1;
}

$isRegister = $authMode === 'register';

$authClassifyErrors = static function (array $errors): array {
    $bucket = [
        'email' => [],
        'nombre' => [],
        'password' => [],
        'other' => [],
    ];

    foreach ($errors as $error) {
        $lower = mb_strtolower((string) $error);
        if (str_contains($lower, 'correo') || str_contains($lower, 'email')) {
            $bucket['email'][] = $error;
        } elseif (str_contains($lower, 'nombre')) {
            $bucket['nombre'][] = $error;
        } elseif (str_contains($lower, 'contraseña') || str_contains($lower, 'coinciden')) {
            $bucket['password'][] = $error;
        } else {
            $bucket['other'][] = $error;
        }
    }

    return $bucket;
};

$loginErrorMap = $authClassifyErrors($loginErrors);
$regErrorMap = $authClassifyErrors($regErrors);
$loginEmailError = $loginErrorMap['email'][0] ?? null;
$loginPasswordError = $loginErrorMap['password'][0] ?? null;
$loginTopErrors = $loginErrorMap['other'];
$regEmailError = $regErrorMap['email'][0] ?? null;
$regNombreError = $regErrorMap['nombre'][0] ?? null;
$regPasswordError = $regErrorMap['password'][0] ?? null;
$regTopErrors = $regErrorMap['other'];

$toasts = array_map(
    static fn ($error): array => ['type' => 'danger', 'message' => (string) $error],
    array_merge($loginTopErrors, $regTopErrors)
);
require __DIR__ . '/auth_toast.php';
?>
<div class="auth-board-form">
  <div
    class="auth-panel<?= $isRegister ? '' : ' is-active' ?>"
    data-auth-panel="login"
    data-auth-doc-title="Iniciar sesión"
    <?= $isRegister ? 'hidden' : '' ?>
  >
    <header class="auth-board-intro">
      <div class="auth-intro-top">
        <p class="auth-kicker">Caacuprecio</p>
      </div>
      <h1 class="section-title" id="auth-title-login">Iniciar sesión</h1>
      <p class="section-subtitle mb-0">Entra para guardar favoritos y retomar lo que ya comparaste.</p>
    </header>

    <form method="post" action="login.php" class="auth-form">
      <input type="hidden" name="redirect" value="<?= e($redirectTo) ?>">

      <div class="auth-form-field">
        <label for="login-email" class="form-label">Correo electrónico</label>
        <input
          type="email"
          class="form-control"
          id="login-email"
          name="email"
          value="<?= e($loginEmail) ?>"
          placeholder="Tu correo"
          autocomplete="email"
          required
          <?= $loginEmailError ? 'aria-invalid="true" aria-describedby="login-email-error"' : '' ?>
          <?= !$isRegister && ($loginEmailError || $loginEmail === '') ? 'autofocus' : '' ?>
        >
        <?php if ($loginEmailError): ?>
          <p class="auth-field-error" id="login-email-error">
            <?= lucide_icon('info', 'auth-field-error-icon', 14) ?>
            <span><?= e($loginEmailError) ?></span>
          </p>
        <?php endif; ?>
      </div>

      <div class="auth-form-field">
        <div class="auth-label-row">
          <label for="login-password" class="form-label mb-0">Contraseña</label>
          <a href="olvide_password.php" class="auth-forgot">¿Olvidaste tu contraseña?</a>
        </div>
        <div class="auth-field">
          <input
            type="password"
            class="form-control"
            id="login-password"
            name="password"
            placeholder="Tu contraseña"
            autocomplete="current-password"
            required
            <?= $loginPasswordError ? 'aria-invalid="true" aria-describedby="login-password-error"' : '' ?>
            <?= !$isRegister && !$loginEmailError && ($loginPasswordError || $loginEmail !== '') ? 'autofocus' : '' ?>
          >
          <button
            class="auth-field-toggle"
            type="button"
            data-toggle-password="#login-password"
            aria-label="Mostrar contraseña"
            aria-pressed="false"
          >
            <?= lucide_icon('eye', '', 18) ?>
          </button>
        </div>
        <?php if ($loginPasswordError): ?>
          <p class="auth-field-error" id="login-password-error">
            <?= lucide_icon('info', 'auth-field-error-icon', 14) ?>
            <span><?= e($loginPasswordError) ?></span>
          </p>
        <?php endif; ?>
      </div>

      <button class="btn btn-primary auth-submit" type="submit">Entrar</button>
    </form>

    <p class="auth-switch">
      ¿Todavía no tienes cuenta?
      <button type="button" class="auth-switch-btn" data-auth-swap="register">Crear cuenta</button>
    </p>
  </div>

  <div
    class="auth-panel<?= $isRegister ? ' is-active' : '' ?>"
    data-auth-panel="register"
    data-auth-doc-title="Crear cuenta"
    <?= $isRegister ? '' : 'hidden' ?>
  >
    <header class="auth-board-intro">
      <div class="auth-intro-top">
        <p class="auth-kicker">Caacuprecio</p>
        <div class="auth-step-progress" aria-hidden="true">
          <div class="auth-step-segments" data-auth-step-segments>
            <span class="auth-step-segment<?= $regStartStep >= 1 ? ' is-active' : '' ?>" data-auth-step-segment="1"></span>
            <span class="auth-step-segment<?= $regStartStep >= 2 ? ' is-active' : '' ?>" data-auth-step-segment="2"></span>
          </div>
        </div>
      </div>
      <h1 class="section-title" id="auth-title-register">Crear cuenta</h1>
      <p class="section-subtitle mb-0">Guarda favoritos y compara precios al instante.</p>
    </header>

    <form
      method="post"
      action="registro.php"
      class="auth-form auth-form--steps"
      data-auth-steps
      data-auth-step="<?= $regStartStep ?>"
    >

      <div
        class="auth-step<?= $regStartStep === 1 ? ' is-active' : '' ?>"
        data-auth-step-panel="1"
        <?= $regStartStep === 1 ? '' : 'hidden' ?>
      >
        <div class="auth-form-field">
          <label for="reg-nombre" class="form-label">Nombre</label>
          <input
            type="text"
            class="form-control"
            id="reg-nombre"
            name="nombre"
            maxlength="45"
            value="<?= e($regNombre) ?>"
            placeholder="Tu nombre"
            autocomplete="name"
            required
            <?= $regNombreError ? 'aria-invalid="true" aria-describedby="reg-nombre-error"' : '' ?>
            <?= $isRegister && $regStartStep === 1 && ($regNombreError || (!$regEmailError && $regNombre === '')) ? 'autofocus' : '' ?>
          >
          <?php if ($regNombreError): ?>
            <p class="auth-field-error" id="reg-nombre-error">
              <?= lucide_icon('info', 'auth-field-error-icon', 14) ?>
              <span><?= e($regNombreError) ?></span>
            </p>
          <?php endif; ?>
        </div>

        <div class="auth-form-field">
          <label for="reg-email" class="form-label">Correo electrónico</label>
          <input
            type="email"
            class="form-control"
            id="reg-email"
            name="email"
            maxlength="120"
            value="<?= e($regEmail) ?>"
            placeholder="Tu correo"
            autocomplete="email"
            required
            <?= $regEmailError ? 'aria-invalid="true" aria-describedby="reg-email-error"' : '' ?>
            <?= $isRegister && $regStartStep === 1 && ($regEmailError || (!$regNombreError && $regNombre !== '')) ? 'autofocus' : '' ?>
          >
          <?php if ($regEmailError): ?>
            <p class="auth-field-error" id="reg-email-error">
              <?= lucide_icon('info', 'auth-field-error-icon', 14) ?>
              <span><?= e($regEmailError) ?></span>
            </p>
          <?php endif; ?>
        </div>

        <button class="btn btn-primary auth-submit" type="button" data-auth-step-next>
          Continuar
        </button>
      </div>

      <div
        class="auth-step<?= $regStartStep === 2 ? ' is-active' : '' ?>"
        data-auth-step-panel="2"
        <?= $regStartStep === 2 ? '' : 'hidden' ?>
      >
        <div class="auth-form-field">
          <label for="reg-password" class="form-label">Contraseña</label>
          <div class="auth-field">
            <input
              type="password"
              class="form-control"
              id="reg-password"
              name="password"
              placeholder="Tu contraseña"
              autocomplete="new-password"
              required
              <?= $regPasswordError ? 'aria-invalid="true" aria-describedby="reg-password-error reg-password-hint"' : 'aria-describedby="reg-password-hint"' ?>
              <?= $isRegister && $regStartStep === 2 ? 'autofocus' : '' ?>
            >
            <button
              class="auth-field-toggle"
              type="button"
              data-toggle-password="#reg-password"
              aria-label="Mostrar contraseña"
              aria-pressed="false"
            >
              <?= lucide_icon('eye', '', 18) ?>
            </button>
          </div>
          <?php if ($regPasswordError): ?>
            <p class="auth-field-error" id="reg-password-error">
              <?= lucide_icon('info', 'auth-field-error-icon', 14) ?>
              <span><?= e($regPasswordError) ?></span>
            </p>
          <?php else: ?>
            <p class="auth-form-hint" id="reg-password-hint">Mínimo 8 caracteres.</p>
          <?php endif; ?>
        </div>

        <div class="auth-form-field">
          <label for="reg-password-confirm" class="form-label">Confirmar contraseña</label>
          <div class="auth-field">
            <input
              type="password"
              class="form-control"
              id="reg-password-confirm"
              name="password_confirm"
              placeholder="Repite tu contraseña"
              autocomplete="new-password"
              required
              <?= $regPasswordError && str_contains(mb_strtolower((string) $regPasswordError), 'coinciden') ? 'aria-invalid="true"' : '' ?>
            >
            <button
              class="auth-field-toggle"
              type="button"
              data-toggle-password="#reg-password-confirm"
              aria-label="Mostrar contraseña"
              aria-pressed="false"
            >
              <?= lucide_icon('eye', '', 18) ?>
            </button>
          </div>
        </div>

        <?= consent_field('reg-consent', true) ?>

        <?= turnstile_widget() ?>

        <div class="auth-step-actions">
          <button class="btn btn-outline-primary auth-step-back" type="button" data-auth-step-back>
            Atrás
          </button>
          <button class="btn btn-primary auth-submit" type="submit">
            Crear cuenta
          </button>
        </div>
      </div>
    </form>

    <p class="auth-switch">
      ¿Ya tienes cuenta?
      <button type="button" class="auth-switch-btn" data-auth-swap="login">Iniciar sesión</button>
    </p>
  </div>
</div>
