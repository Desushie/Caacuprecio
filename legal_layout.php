<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

const LEGAL_PAGES = [
    'terminos' => ['terminos.php', 'Términos y condiciones'],
    'privacidad' => ['privacidad.php', 'Política de privacidad'],
    'cookies' => ['cookies.php', 'Política de cookies'],
    'reembolsos' => ['reembolsos.php', 'Compras y reembolsos'],
    'aviso_legal' => ['aviso_legal.php', 'Aviso legal y contacto'],
];

/**
 * Datos del responsable cargados en config.php, solo los que tienen valor.
 */
function business_details(): array
{
    return array_filter([
        'Titulares' => BUSINESS_LEGAL_NAME,
        'Proyecto' => BUSINESS_PROJECT,
        'RUC' => BUSINESS_RUC,
        'Domicilio' => BUSINESS_ADDRESS,
        'Correo electrónico' => BUSINESS_EMAIL,
        'Teléfono' => BUSINESS_PHONE,
    ], static fn (string $value): bool => trim($value) !== '');
}

/**
 * Medio de contacto para ejercer derechos: correo si está configurado, si no el formulario de sugerencias.
 */
function legal_contact_html(): string
{
    if (BUSINESS_EMAIL !== '') {
        return 'escribiendo a <a href="mailto:' . e(BUSINESS_EMAIL) . '">' . e(BUSINESS_EMAIL) . '</a>';
    }

    return 'mediante el botón <a href="#" data-bs-toggle="modal" data-bs-target="#suggestionModal">Sugerencias</a> de la esquina inferior derecha';
}

function legal_page_start(string $key): void
{
    [, $title] = LEGAL_PAGES[$key];

    render_head($title);
    render_navbar('legal');

    echo '<main class="legal-page"><div class="container"><article class="legal-doc">';

    $missing = array_diff(['Titulares', 'Proyecto', 'Domicilio', 'Correo electrónico'], array_keys(business_details()));
    if ($missing && is_admin()) {
        echo '<div class="legal-admin-warning" role="note"><strong>Aviso solo para administradores:</strong> faltan datos del responsable en <code>config.php</code> ('
            . e(implode(', ', $missing)) . '). Complétalos antes de publicar el sitio.</div>';
    }

    echo '<nav class="legal-nav" aria-label="Documentos legales">';
    foreach (LEGAL_PAGES as $pageKey => [$href, $label]) {
        $current = $pageKey === $key ? ' aria-current="page"' : '';
        echo '<a href="' . e($href) . '"' . $current . '>' . e($label) . '</a>';
    }
    echo '</nav>';

    echo '<h1>' . e($title) . '</h1>';
    echo '<p class="legal-meta">Última actualización: ' . e(date('d/m/Y', strtotime(LEGAL_LAST_UPDATED))) . '</p>';
}

function legal_page_end(): void
{
    echo '</article></div></main>';
    render_footer();
}
