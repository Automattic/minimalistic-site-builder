<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Patterns;

/**
 * A WordPress locale code, spelled out for the model.
 *
 * "Write in pt_BR" is a code the model may or may not read; "Write in
 * Portuguese (Brazil) (pt_BR)" is a language. The map covers the locales a
 * network is likely to build in; an unknown code is passed through as it is,
 * which still tells the model something.
 */
final class LocaleLabel
{
    private const LABELS = [
        'en' => 'English',
        'en_us' => 'English (US)',
        'en_gb' => 'English (UK)',
        'en_au' => 'English (Australia)',
        'en_ca' => 'English (Canada)',
        'es' => 'Spanish',
        'es_es' => 'Spanish (Spain)',
        'es_mx' => 'Spanish (Mexico)',
        'es_ar' => 'Spanish (Argentina)',
        'es_co' => 'Spanish (Colombia)',
        'es_cl' => 'Spanish (Chile)',
        'pt_br' => 'Portuguese (Brazil)',
        'pt_pt' => 'Portuguese (Portugal)',
        'fr' => 'French',
        'fr_fr' => 'French (France)',
        'fr_ca' => 'French (Canada)',
        'de' => 'German',
        'de_de' => 'German (Germany)',
        'de_ch' => 'German (Switzerland)',
        'de_at' => 'German (Austria)',
        'it' => 'Italian',
        'it_it' => 'Italian',
        'nl' => 'Dutch',
        'nl_nl' => 'Dutch',
        'nl_be' => 'Dutch (Belgium)',
        'ja' => 'Japanese',
        'zh_cn' => 'Chinese (Simplified)',
        'zh_tw' => 'Chinese (Traditional)',
        'ko_kr' => 'Korean',
        'ru_ru' => 'Russian',
        'pl_pl' => 'Polish',
        'sv_se' => 'Swedish',
        'da_dk' => 'Danish',
        'nb_no' => 'Norwegian (Bokmål)',
        'fi' => 'Finnish',
        'tr_tr' => 'Turkish',
        'ar' => 'Arabic',
        'he_il' => 'Hebrew',
        'cs_cz' => 'Czech',
        'hu_hu' => 'Hungarian',
        'ro_ro' => 'Romanian',
        'uk' => 'Ukrainian',
        'id_id' => 'Indonesian',
        'vi' => 'Vietnamese',
        'th' => 'Thai',
        'ca' => 'Catalan',
        'eu' => 'Basque',
        'gl_es' => 'Galician',
    ];

    /** "Portuguese (Brazil) (pt_BR)" for a known code, the code itself otherwise. */
    public static function describe(string $locale): string
    {
        $locale = trim($locale);
        if ($locale === '') {
            return 'English (en)';
        }

        $label = self::LABELS[strtolower($locale)] ?? null;

        return $label === null ? $locale : sprintf('%s (%s)', $label, $locale);
    }
}
