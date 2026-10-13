<?php
declare(strict_types=1);

require_once __DIR__ . '/legal_layout.php';

legal_page_start('privacidad');
$owner = BUSINESS_LEGAL_NAME !== '' ? e(BUSINESS_LEGAL_NAME) : 'los titulares indicados en el <a href="aviso_legal.php">Aviso legal</a>';
?>
<p>Esta política explica qué datos personales trata Caacuprecio, para qué, con quién se comparten y cómo puedes ejercer tus derechos. Se aplica al sitio web Caacuprecio (<?= e(APP_URL) ?>).</p>

<h2>1. Responsable</h2>
<p>Los responsables del tratamiento son <?= $owner ?>. Puedes contactarnos <?= legal_contact_html() ?>.</p>

<h2>2. Qué datos tratamos y para qué</h2>
<table class="legal-table">
  <thead>
    <tr><th scope="col">Situación</th><th scope="col">Datos</th><th scope="col">Finalidad</th></tr>
  </thead>
  <tbody>
    <tr>
      <td>Crear y usar una cuenta</td>
      <td>Nombre, correo electrónico y contraseña (guardada cifrada con un algoritmo de hash, nunca en texto plano). Tus favoritos y tu historial de búsquedas.</td>
      <td>Identificarte, guardar tus favoritos y mostrarte tus búsquedas recientes.</td>
    </tr>
    <tr>
      <td>Recuperar la contraseña</td>
      <td>Correo electrónico y un código temporal que vence a la hora.</td>
      <td>Enviarte el enlace para crear una contraseña nueva.</td>
    </tr>
    <tr>
      <td>Navegar por el sitio</td>
      <td>Identificador de sesión, productos que ves, enlaces a tiendas en los que haces clic, términos de búsqueda y fecha. Si iniciaste sesión, se asocian a tu cuenta.</td>
      <td>Estadísticas internas (productos más vistos, búsquedas frecuentes) y mejorar el sitio. No se usan para publicidad ni se venden.</td>
    </tr>
    <tr>
      <td>Enviar una sugerencia o un reporte</td>
      <td>Texto del mensaje, motivo, y de forma opcional tu nombre y correo. Dirección IP e identificador de sesión.</td>
      <td>Atender el mensaje, responderte si dejaste tu correo, y prevenir abusos y envíos repetidos.</td>
    </tr>
    <tr>
      <td>Publicar una reseña de una tienda</td>
      <td>Nombre que elijas, puntuación, comentario y fecha. Son <strong>públicos</strong>.</td>
      <td>Mostrar opiniones de usuarios sobre las tiendas.</td>
    </tr>
    <tr>
      <td>Formularios con verificación de seguridad</td>
      <td>Datos técnicos del navegador y dirección IP, procesados por Cloudflare Turnstile.</td>
      <td>Distinguir personas de programas automáticos y evitar spam.</td>
    </tr>
  </tbody>
</table>
<p>Solo pedimos los datos necesarios para cada función. Los campos marcados como opcionales pueden quedar vacíos.</p>

<h2>2.1. Uso para investigación académica</h2>
<p>Caacuprecio se desarrolla como trabajo de investigación de grado (tesis). Los datos de uso del sitio (productos vistos, clics, búsquedas, reseñas y reportes) pueden analizarse para esa investigación, siempre de forma <strong>agregada y anónima</strong>: en la tesis y en sus presentaciones solo se publican totales, porcentajes y tendencias, nunca nombres, correos, direcciones IP ni datos que permitan identificar a una persona. Si no quieres que tu navegación se incluya, activa la señal <em>Global Privacy Control</em> o <em>Do Not Track</em> de tu navegador.</p>
<p>Si tu navegador envía la señal de privacidad <em>Global Privacy Control</em> o <em>Do Not Track</em>, no registramos tus vistas ni tus clics para estadísticas.</p>

<h2>3. Base del tratamiento</h2>
<p>Tratamos tus datos con tu consentimiento (que das al crear la cuenta o al marcar la casilla de cada formulario) y en la medida necesaria para prestarte el servicio que solicitas. Puedes retirar tu consentimiento en cualquier momento; eso no afecta a lo tratado antes.</p>

<h2>4. Con quién compartimos datos</h2>
<p>No vendemos ni alquilamos datos personales. Usamos estos proveedores, que pueden procesar datos en servidores fuera de Paraguay:</p>
<ul>
  <li><strong>Proveedor de alojamiento</strong> del sitio y la base de datos.</li>
  <li><strong>Resend</strong>: envío del correo de recuperación de contraseña.</li>
  <li><strong>Cloudflare Turnstile</strong>: verificación anti-spam en formularios.</li>
  <li><strong>jsDelivr</strong> y <strong>Google Fonts</strong>: entregan librerías de interfaz y tipografías; reciben tu dirección IP al cargar la página.</li>
  <li><strong>Google Maps</strong>: muestra el mapa en la página de cada tienda; recibe tu dirección IP al abrir esa página y puede instalar sus propias cookies.</li>
</ul>
<p>Al hacer clic en "Ir a la tienda" sales de Caacuprecio; desde ese momento rige la política de privacidad de la tienda. También podemos revelar datos si una autoridad competente lo exige conforme a la ley.</p>

<h2>5. Cuánto tiempo conservamos los datos</h2>
<ul>
  <li>Datos de la cuenta, favoritos e historial de búsquedas: mientras la cuenta exista.</li>
  <li>Al eliminar tu cuenta desde "Mi cuenta" se borran tu perfil y tus favoritos, y tus registros de navegación quedan desvinculados de ti.</li>
  <li>Sugerencias y reportes: el tiempo necesario para atenderlos y para prevenir abusos.</li>
  <li>Reseñas: mientras estén publicadas, o hasta que pidas su eliminación.</li>
  <li>Si el sitio deja de funcionar al terminar el proyecto, eliminaremos los datos personales y conservaremos solo estadísticas anónimas para la investigación.</li>
</ul>

<h2>6. Tus derechos</h2>
<p>La Constitución Nacional (art. 33 y art. 135, hábeas data) y la Ley N° 1682/2001, modificada por la Ley N° 1969/2002, te reconocen el derecho a conocer qué datos tenemos sobre ti, para qué los usamos, y a pedir que se corrijan, actualicen o eliminen. Puedes:</p>
<ul>
  <li>Ver y corregir tu nombre y correo, o eliminar tu cuenta, desde <a href="usuario.php">Mi cuenta</a>.</li>
  <li>Pedir acceso, corrección o eliminación de cualquier otro dato (por ejemplo, una reseña o una sugerencia) <?= legal_contact_html() ?>.</li>
</ul>
<p>Responderemos en un plazo razonable. Podemos pedirte que confirmes tu identidad antes de entregar o borrar datos.</p>

<h2>7. Seguridad</h2>
<p>Las contraseñas se guardan cifradas con hash, la sesión usa cookies protegidas, y los formularios tienen verificación anti-spam. Ningún sistema es infalible: si detectamos un incidente que afecte tus datos, te lo comunicaremos.</p>

<h2>8. Menores de edad</h2>
<p>El sitio no está dirigido a menores de 18 años. Si eres menor, usa Caacuprecio con autorización de tus padres o tutores.</p>

<h2>9. Cambios</h2>
<p>Si cambiamos esta política, actualizaremos la fecha de arriba. Si el cambio es importante, lo avisaremos en el sitio.</p>
<?php legal_page_end(); ?>
