<?php
declare(strict_types=1);

require_once __DIR__ . '/legal_layout.php';

legal_page_start('cookies');
?>
<p>Las cookies y el almacenamiento local son pequeños datos que el sitio guarda en tu navegador. Esta página explica cuáles usa Caacuprecio y por qué no te pedimos un banner de consentimiento.</p>

<h2>1. Lo que guarda Caacuprecio</h2>
<table class="legal-table">
  <thead>
    <tr><th scope="col">Nombre</th><th scope="col">Tipo</th><th scope="col">Para qué sirve</th><th scope="col">Duración</th></tr>
  </thead>
  <tbody>
    <tr>
      <td><code><?= e(session_name()) ?></code></td>
      <td>Cookie propia, técnica</td>
      <td>Mantener tu sesión iniciada, proteger los formularios contra envíos falsos y limitar abusos. Con ella también contamos, en nuestra propia base de datos, qué productos se ven y qué enlaces se usan (ver la <a href="privacidad.php">Política de privacidad</a>).</td>
      <td>Hasta que cierras el navegador</td>
    </tr>
    <tr>
      <td><code>caacuprecio-theme</code></td>
      <td>Almacenamiento local, preferencia</td>
      <td>Recordar si elegiste el tema claro u oscuro.</td>
      <td>Hasta que lo borres</td>
    </tr>
    <tr>
      <td><code>fv-checked-…</code></td>
      <td>Almacenamiento local, preferencia</td>
      <td>Recordar qué favoritos marcaste como comprados.</td>
      <td>Hasta que lo borres</td>
    </tr>
  </tbody>
</table>
<p>No usamos cookies de publicidad, de redes sociales ni herramientas de analítica de terceros (como Google Analytics o Meta Pixel).</p>

<h2>2. Servicios de terceros</h2>
<ul>
  <li><strong>Cloudflare Turnstile</strong> aparece en los formularios para comprobar que eres una persona. Es una medida de seguridad necesaria para que los formularios funcionen sin spam. Consulta la <a href="https://www.cloudflare.com/privacypolicy/" target="_blank" rel="noopener">política de privacidad de Cloudflare</a>.</li>
  <li><strong>Google Maps</strong> muestra la ubicación en la página de cada tienda. El mapa se carga automáticamente al abrir esa página; Google recibe tu dirección IP y puede instalar sus propias cookies. Consulta la <a href="https://policies.google.com/privacy" target="_blank" rel="noopener">política de privacidad de Google</a>.</li>
  <li><strong>jsDelivr</strong> y <strong>Google Fonts</strong> entregan archivos de diseño y tipografías. No instalan cookies, pero reciben tu dirección IP al cargar la página.</li>
</ul>

<h2>3. ¿Por qué no hay banner de cookies?</h2>
<p>Caacuprecio solo guarda lo estrictamente necesario para funcionar y para recordar preferencias que tú eliges. Las cookies que pueda instalar Google Maps en las páginas de tienda son de Google, no nuestras; si no las quieres, configura tu navegador para bloquear las cookies de terceros (el mapa seguirá mostrándose). Si el sitio incorpora en el futuro herramientas de analítica o publicidad de terceros, pediremos tu consentimiento antes de activarlas.</p>
<p>Si tu navegador envía la señal <em>Global Privacy Control</em> o <em>Do Not Track</em>, no registramos tus vistas ni tus clics para estadísticas.</p>

<h2>4. Cómo borrarlas o bloquearlas</h2>
<p>Puedes borrar las cookies y el almacenamiento local desde la configuración de tu navegador. Si bloqueas la cookie de sesión no podrás iniciar sesión ni enviar formularios.</p>
<?php legal_page_end(); ?>
