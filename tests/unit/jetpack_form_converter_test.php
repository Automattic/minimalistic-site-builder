<?php
declare(strict_types=1);

use Automattic\SiteBuild\JetpackFormConverter;

/**
 * The exact form a live production build shipped (2026-08-18):
 * generated with the FORMS prompt rule in place, still a raw mailto form
 * inside wp:html. The converter, not the prompt, is the guarantee.
 */
function converter_real_world_fixture(): string
{
    return <<<'HTML'
<!-- wp:heading {"level":2} --><h2 class="wp-block-heading">Reservá</h2><!-- /wp:heading -->

<!-- wp:html -->
<form class="tdb-form" action="mailto:tallerdebarro@tallerdebarro.com" method="post" enctype="text/plain">
  <div class="tdb-field">
    <label for="tdb-nombre">Nombre <span class="tdb-req" aria-hidden="true">*</span></label>
    <input type="text" id="tdb-nombre" name="nombre" autocomplete="name" required>
  </div>
  <div class="tdb-field">
    <label for="tdb-correo">Correo electrónico <span class="tdb-req" aria-hidden="true">*</span></label>
    <input type="email" id="tdb-correo" name="correo" autocomplete="email" required>
  </div>
  <div class="tdb-field">
    <label for="tdb-clase">Clase de interés <span class="tdb-hint">(opcional)</span></label>
    <select id="tdb-clase" name="clase">
      <option value="">Selecciona una opción</option>
      <option value="Fundamentos de alfarería">Fundamentos de alfarería</option>
      <option value="Torno de alfarero">Torno de alfarero</option>
      <option value="Modelado a mano">Modelado a mano</option>
      <option value="Taller abierto">Taller abierto</option>
    </select>
  </div>
  <div class="tdb-field">
    <label for="tdb-mensaje">Mensaje <span class="tdb-req" aria-hidden="true">*</span></label>
    <textarea id="tdb-mensaje" name="mensaje" rows="5" required></textarea>
  </div>
  <p class="tdb-hint">Los campos marcados con <span class="tdb-req">*</span> son obligatorios.</p>
  <button type="submit" class="tdb-submit">Enviar solicitud</button>
</form>
<!-- /wp:html -->

<!-- wp:paragraph --><p>Te contestamos en el día.</p><!-- /wp:paragraph -->
HTML;
}

test('converter turns the live raw mailto form into canonical Jetpack markup', function () {
    $result = JetpackFormConverter::fix(converter_real_world_fixture());
    $markup = $result['markup'];

    assert_contains('<!-- wp:jetpack/contact-form -->', $markup);
    assert_contains('<!-- wp:jetpack/field-name {"label":"Nombre","required":true} /-->', $markup);
    assert_contains('<!-- wp:jetpack/field-email {"label":"Correo electrónico","required":true} /-->', $markup);
    assert_contains(
        '<!-- wp:jetpack/field-select {"label":"Clase de interés",'
        . '"options":["Fundamentos de alfarería","Torno de alfarero","Modelado a mano","Taller abierto"]} /-->',
        $markup
    );
    assert_contains('<!-- wp:jetpack/field-textarea {"label":"Mensaje","required":true} /-->', $markup);
    // The canonical submit control, labeled from the original button.
    assert_contains('"tagName":"button","type":"submit","className":"form-button-submit is-submit"', $markup);
    assert_contains('>Enviar solicitud</button>', $markup);

    // The dead pieces are gone; the surrounding blocks are untouched.
    assert_true(!str_contains($markup, '<form'), 'raw form removed');
    assert_true(!str_contains($markup, 'mailto:'), 'invented mailto recipient dropped');
    assert_true(!str_contains($markup, 'wp:html'), 'the wp:html wrapper is replaced');
    assert_contains('Reservá</h2>', $markup);
    assert_contains('Te contestamos en el día.', $markup);

    assert_eq(1, count($result['notes']));
    assert_contains('mailto action dropped', $result['notes'][0]);

    // Content the model wrote INSIDE the form survives, in place, as blocks.
    assert_contains('<!-- wp:paragraph --><p>Los campos marcados con * son obligatorios.</p><!-- /wp:paragraph -->', $markup);

    // The invented recipient is a recorded loss, not a silent one.
    assert_eq(1, count($result['warnings']));
    assert_contains('recipient "tallerdebarro@tallerdebarro.com" removed', $result['warnings'][0]);
    assert_contains("admin email", $result['warnings'][0]);

    // Fixed point: converted markup contains no raw form to convert.
    assert_eq(['markup' => $markup, 'notes' => [], 'warnings' => []], JetpackFormConverter::fix($markup));
});

test('converter leaves non-form markup and unmappable forms alone', function () {
    $plain = '<!-- wp:html --><div class="widget"><p>No form here</p></div><!-- /wp:html -->';
    assert_eq(['markup' => $plain, 'notes' => [], 'warnings' => []], JetpackFormConverter::fix($plain));

    // A form with nothing but a hidden input and a submit cannot become a
    // working Jetpack form; converting would destroy content.
    $unmappable = '<!-- wp:html --><form action="/x">'
        . '<input type="hidden" name="token" value="t"><button type="submit">Go</button>'
        . '</form><!-- /wp:html -->';
    assert_eq(['markup' => $unmappable, 'notes' => [], 'warnings' => []], JetpackFormConverter::fix($unmappable));

    // A raw form OUTSIDE a wp:html block is the validator's problem, not a
    // conversion target.
    $outside = '<!-- wp:group --><div class="wp-block-group"><form action="/x">'
        . '<input type="email" name="e"></form></div><!-- /wp:group -->';
    assert_eq(['markup' => $outside, 'notes' => [], 'warnings' => []], JetpackFormConverter::fix($outside));
});

test('converter maps telephone, checkbox and radio groups', function () {
    $markup = '<!-- wp:html --><form action="/reserve">'
        . '<label for="f-tel">Teléfono</label><input type="tel" id="f-tel" name="tel">'
        . '<label for="f-ok">Acepto las condiciones</label><input type="checkbox" id="f-ok" name="ok" required>'
        . '<label for="r1">Mañana</label><input type="radio" id="r1" name="turno" value="m">'
        . '<label for="r2">Tarde</label><input type="radio" id="r2" name="turno" value="t">'
        . '<button type="submit">Reservar</button>'
        . '</form><!-- /wp:html -->';

    $result = JetpackFormConverter::fix($markup);

    assert_contains('<!-- wp:jetpack/field-telephone {"label":"Teléfono"} /-->', $result['markup']);
    assert_contains('<!-- wp:jetpack/field-checkbox {"label":"Acepto las condiciones","required":true} /-->', $result['markup']);
    assert_contains('"options":["Mañana","Tarde"]', $result['markup']);
    assert_contains('>Reservar</button>', $result['markup']);
});


test('converter preserves non-form content sharing the wp:html block', function () {
    $markup = '<!-- wp:html -->'
        . '<p class="intro">Escribinos y te contestamos en el día.</p>'
        . '<form action="mailto:x@invented.example"><label for="e">Email</label>'
        . '<input type="email" id="e" name="e" required><button type="submit">Enviar</button></form>'
        . '<p class="legal">Tus datos no se comparten.</p>'
        . '<!-- /wp:html -->';

    $result = JetpackFormConverter::fix($markup);

    assert_contains('Escribinos y te contestamos en el día.', $result['markup']);
    assert_contains('Tus datos no se comparten.', $result['markup']);
    assert_contains('<!-- wp:jetpack/contact-form -->', $result['markup']);
    assert_true(!str_contains($result['markup'], '<form'), 'form removed, siblings kept');

    // Document order survives: intro before the form, legal text after it.
    $intro = strpos($result['markup'], 'Escribinos');
    $formAt = strpos($result['markup'], 'wp:jetpack/contact-form');
    $legal = strpos($result['markup'], 'Tus datos');
    assert_true($intro < $formAt && $formAt < $legal, 'original order preserved');
});

test('converter emits comment-safe attributes for hostile label text', function () {
    $markup = '<!-- wp:html --><form action="mailto:x@x.example">'
        . '<label for="a">Edad --> 18 requerido</label><input type="text" id="a" name="edad">'
        . '<button type="submit">Ok</button></form><!-- /wp:html -->';

    $result = JetpackFormConverter::fix($markup);

    // serialize_block_attributes escaping: `--` can never terminate the
    // comment delimiter from inside a label.
    assert_contains('\u002d\u002d', $result['markup']);
    $reparsed = Automattic\SiteBuild\BlockMarkup::parse($result['markup']);
    assert_true(!$reparsed->hasMalformedDelimiters(), 'no malformed delimiters after conversion');
});

test('converter resolves wrapping labels, so radio groups survive', function () {
    $markup = '<!-- wp:html --><form action="/x">'
        . '<label>Nombre <input type="text" name="nombre"></label>'
        . '<label>Mañana <input type="radio" name="turno" value="m"></label>'
        . '<label>Tarde <input type="radio" name="turno" value="t"></label>'
        . '<button type="submit">Reservar</button></form><!-- /wp:html -->';

    $result = JetpackFormConverter::fix($markup);

    assert_contains('"label":"Nombre"', $result['markup']);
    assert_contains('"options":["Ma\u00f1ana","Tarde"]', str_replace('"options":["Mañana","Tarde"]', '"options":["Ma\u00f1ana","Tarde"]', $result['markup']));
    assert_contains('Tarde', $result['markup']);
});

test('converter keeps every option of a select whose options omit value attributes', function () {
    $markup = '<!-- wp:html --><form action="/x"><label for="c">Color</label>'
        . '<select id="c" name="c"><option>Rojo</option><option>Verde</option></select>'
        . '<button type="submit">Ok</button></form><!-- /wp:html -->';

    $result = JetpackFormConverter::fix($markup);

    assert_contains('"options":["Rojo","Verde"]', $result['markup']);
});

test('converter refuses search and login forms', function () {
    $search = '<!-- wp:html --><form role="search" action="/s">'
        . '<input type="text" name="q"><button type="submit">Buscar</button></form><!-- /wp:html -->';
    assert_eq($search, JetpackFormConverter::fix($search)['markup']);

    $login = '<!-- wp:html --><form action="/login">'
        . '<input type="text" name="user"><input type="password" name="pass">'
        . '<button type="submit">Entrar</button></form><!-- /wp:html -->';
    assert_eq($login, JetpackFormConverter::fix($login)['markup']);
});

test('converter warns about lossy edges: file inputs and authored non-mailto actions', function () {
    $markup = '<!-- wp:html --><form action="/reservas">'
        . '<label for="e">Email</label><input type="email" id="e" name="e" required>'
        . '<label for="cv">Adjunto</label><input type="file" id="cv" name="cv">'
        . '<button type="submit">Enviar</button></form><!-- /wp:html -->';

    $result = JetpackFormConverter::fix($markup);

    assert_eq(2, count($result['warnings']));
    assert_contains('file upload input', $result['warnings'][0]);
    assert_contains('authored action "/reservas" replaced', $result['warnings'][1]);
    assert_contains('form "/reservas"', $result['warnings'][1]);
    assert_true(!str_contains($result['markup'], 'type="file"'), 'file input not carried over');
});

test('converter keeps the first submit control in document order', function () {
    $markup = '<!-- wp:html --><form action="mailto:x@x.example">'
        . '<label for="e">Email</label><input type="email" id="e" name="e">'
        . '<button type="submit">Reservar ahora</button>'
        . '<input type="submit" value="Otro texto">'
        . '</form><!-- /wp:html -->';

    $result = JetpackFormConverter::fix($markup);

    assert_contains('>Reservar ahora</button>', $result['markup']);
    assert_true(!str_contains($result['markup'], 'Otro texto'), 'later submit control does not clobber');
});

test('converter survives a wp:html nested inside another wp:html', function () {
    $markup = '<!-- wp:html --><div class="outer">'
        . '<!-- wp:html --><form action="mailto:x@x.example"><label for="e">Email</label>'
        . '<input type="email" id="e" name="e"><button type="submit">Ok</button></form><!-- /wp:html -->'
        . '</div><!-- /wp:html -->';

    $result = JetpackFormConverter::fix($markup);

    $reparsed = Automattic\SiteBuild\BlockMarkup::parse($result['markup']);
    assert_true(!$reparsed->hasMalformedDelimiters(), 'no malformed delimiters');
    assert_true(!$reparsed->hasMismatchedDelimiters(), 'no mismatched delimiters');
    assert_contains('<!-- wp:jetpack/contact-form -->', $result['markup']);
    assert_true(!str_contains($result['markup'], '<form'), 'the nested form converted exactly once');
});

test('converter handles each form independently and leaves refused ones in place', function () {
    $markup = '<!-- wp:html -->'
        . '<form role="search" action="/s"><input type="text" name="q"><button type="submit">Buscar</button></form>'
        . '<p class="between">Entre los dos formularios.</p>'
        . '<form action="mailto:x@x.example"><label for="e">Email</label>'
        . '<input type="email" id="e" name="e"><button type="submit">Enviar</button></form>'
        . '<!-- /wp:html -->';

    $result = JetpackFormConverter::fix($markup);

    // The search form survives untouched, in its original position, before
    // the interstitial text; the mailto form converts after it.
    $search = strpos($result['markup'], 'role="search"');
    $between = strpos($result['markup'], 'Entre los dos');
    $jetpack = strpos($result['markup'], 'wp:jetpack/contact-form');
    assert_true($search !== false, 'refused search form kept');
    assert_true($jetpack !== false, 'convertible form converted');
    assert_true($search < $between && $between < $jetpack, 'document order preserved');
});

test('converter keeps privacy text and links written inside the form', function () {
    $markup = '<!-- wp:html --><form action="mailto:x@x.example" aria-label="Reservas">'
        . '<h3>Reservá tu lugar</h3>'
        . '<label for="e">Email</label><input type="email" id="e" name="e" required>'
        . '<p>Al enviar aceptás nuestra <a href="/privacidad">política de privacidad</a>.</p>'
        . '<button type="submit">Enviar</button></form><!-- /wp:html -->';

    $result = JetpackFormConverter::fix($markup);

    assert_contains('<!-- wp:heading {"level":3} --><h3 class="wp-block-heading">Reservá tu lugar</h3><!-- /wp:heading -->', $result['markup']);
    assert_contains('<a href="/privacidad">política de privacidad</a>', $result['markup']);
    // And it lives INSIDE the contact form, between the field and the submit.
    $field = strpos($result['markup'], 'jetpack/field-email');
    $privacy = strpos($result['markup'], 'política de privacidad');
    $submit = strpos($result['markup'], 'form-button-submit');
    assert_true($field < $privacy && $privacy < $submit, 'in-form content stays in place');
});

test('converter reports constraints it cannot carry: limits, patterns, prefills, preselections', function () {
    $markup = '<!-- wp:html --><form action="mailto:x@x.example" id="reserva">'
        . '<label for="p">Personas</label><input type="number" id="p" name="p" min="1" max="8">'
        . '<label for="n">Nombre</label><input type="text" id="n" name="n" value="Juan">'
        . '<label for="c">Clase</label><select id="c" name="c">'
        . '<option>Torno</option><option selected>Modelado</option></select>'
        . '<label for="ok">Suscribirme</label><input type="checkbox" id="ok" name="ok" checked>'
        . '<button type="submit">Enviar</button></form><!-- /wp:html -->';

    $result = JetpackFormConverter::fix($markup);

    assert_contains('<!-- wp:jetpack/field-number {"label":"Personas"} /-->', $result['markup']);
    $all = implode("\n", $result['warnings']);
    assert_contains('form "reserva": min="1"', $all);
    assert_contains('form "reserva": max="8"', $all);
    assert_contains('pre-filled value "Juan"', $all);
    assert_contains('preselected choice "Modelado"', $all);
    assert_contains('"Suscribirme" was pre-checked', $all);
});

test('converter refuses the WordPress search-field shape', function () {
    // No role attribute, no type=search: just the `s` query field. Converting
    // it would email visitors\' search words to the owner.
    $markup = '<!-- wp:html --><form action="/" method="get">'
        . '<label for="s">Buscar</label><input type="text" id="s" name="s">'
        . '<button type="submit">Ir</button></form><!-- /wp:html -->';

    $result = JetpackFormConverter::fix($markup);

    assert_eq($markup, $result['markup']);
});

test('converter reports dropped hidden values with their form and value', function () {
    $markup = '<!-- wp:html --><form action="mailto:owner@invented.example" id="contacto">'
        . '<input type="hidden" name="campaign" value="verano-2026">'
        . '<label for="e">Email</label><input type="email" id="e" name="e">'
        . '<button type="submit">Enviar</button></form><!-- /wp:html -->';

    $result = JetpackFormConverter::fix($markup);

    $all = implode("\n", $result['warnings']);
    assert_contains('form "contacto": hidden field "campaign" (value "verano-2026") removed', $all);
    assert_contains('recipient "owner@invented.example" removed', $all);
    assert_contains("admin email", $all);
});
