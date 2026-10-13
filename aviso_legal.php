<?php
declare(strict_types=1);

require_once __DIR__ . '/legal_layout.php';

legal_page_start('aviso_legal');
$details = business_details();
?>
<h2>1. Responsables del sitio</h2>
<p>Datos de identificación de los responsables de Caacuprecio, conforme a la Ley N° 4868/2013 de Comercio Electrónico:</p>
<?php if ($details): ?>
  <table class="legal-table">
    <tbody>
      <tr><th scope="row">Sitio web</th><td><?= e(APP_NAME) ?> (<?= e(APP_URL) ?>)</td></tr>
      <?php foreach ($details as $label => $value): ?>
        <tr>
          <th scope="row"><?= e($label) ?></th>
          <td>
            <?php if ($label === 'Correo electrónico'): ?>
              <a href="mailto:<?= e($value) ?>"><?= e($value) ?></a>
            <?php elseif ($label === 'Teléfono'): ?>
              <a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $value)) ?>"><?= e($value) ?></a>
            <?php else: ?>
              <?= e($value) ?>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <tr><th scope="row">Actividad</th><td>Comparador de precios gratuito, sin fines comerciales, desarrollado como trabajo de investigación de grado. No vende productos ni procesa pagos.</td></tr>
    </tbody>
  </table>
<?php else: ?>
  <p><?= e(APP_NAME) ?> (<?= e(APP_URL) ?>), comparador de precios gratuito y sin fines comerciales, desarrollado en Caacupé, Paraguay, como trabajo de investigación de grado. Puedes contactarnos <?= legal_contact_html() ?>.</p>
<?php endif; ?>

<h2>2. Origen de la información</h2>
<p>Los precios, el stock, las descripciones, las imágenes y los logos de productos y tiendas se obtienen de los sitios web públicos de cada tienda. Las tiendas incluidas autorizaron su participación en Caacuprecio. Que una tienda aparezca en Caacuprecio no significa que la recomendemos ni que el orden de los resultados dependa de un pago. La información puede estar desactualizada: confírmala siempre con la tienda.</p>

<h2>3. Marcas, imágenes y derechos de autor</h2>
<p>Las marcas, logos, nombres comerciales y fotografías de productos pertenecen a sus respectivos titulares. Se muestran con autorización de las tiendas, para identificar los productos y su procedencia, con un enlace a la tienda de origen.</p>
<p>Si eres titular de una marca, imagen o contenido y quieres que lo modifiquemos o retiremos, o si eres una tienda y no quieres aparecer en Caacuprecio, contáctanos <?= legal_contact_html() ?> indicando:</p>
<ul>
  <li>Quién eres y qué derecho tienes sobre el contenido.</li>
  <li>El enlace exacto de la página de Caacuprecio donde aparece.</li>
  <li>Qué quieres que hagamos (corregir, retirar o dejar de incluir la tienda).</li>
</ul>
<p>Revisaremos el pedido y, si corresponde, retiraremos el contenido a la brevedad.</p>

<h2>4. Más información</h2>
<ul>
  <li><a href="terminos.php">Términos y condiciones</a></li>
  <li><a href="privacidad.php">Política de privacidad</a></li>
  <li><a href="cookies.php">Política de cookies</a></li>
  <li><a href="reembolsos.php">Compras y reembolsos</a></li>
</ul>
<?php legal_page_end(); ?>
