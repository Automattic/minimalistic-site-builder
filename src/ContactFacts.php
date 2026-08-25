<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/** Canonical contact-token extraction and exact grounding comparisons. */
final class ContactFacts
{
    private const CONTACT_KEY = '/(?:^|[^a-z])(?:contact|location|offices?|branch(?:es)?|headquarters?|venues?|stores?|'
        . 'shops?|showrooms?|studios?|campus(?:es)?|facilit(?:y|ies)|e-?mails?|phones?|telephones?|mobiles?|tels?|hotlines?|whatsapp|fax|'
        . 'address(?:es)?|streets?|cities|city|towns?|villages?|states?|provinces?|regions?|counties|county|'
        . 'countries|country|districts?|localit(?:y|ies)|mailing|postal|postcode|zip|urls?|websites?|domains?|'
        . '(?:contact|support|service|callback)[^a-z]+numbers?|'
        . 'instagram|twitter|facebook|linkedin|social)'
        . '(?:$|[^a-z])/i';

    private const ADDRESS_KEY = '/(?:^|[^a-z])(?:contact|location|offices?|branch(?:es)?|headquarters?|venues?|stores?|'
        . 'shops?|showrooms?|studios?|campus(?:es)?|facilit(?:y|ies)|address(?:es)?|streets?|cities|city|towns?|'
        . 'villages?|states?|provinces?|regions?|counties|county|countries|country|districts?|localit(?:y|ies)|'
        . 'mailing|postal|postcode|zip)(?:$|[^a-z])/i';

    private const EMAIL = '/(?<![\p{L}\p{N}._%+\-])'
        . '[\p{L}\p{N}.!#$%&\'*+\/=?^_`{|}~-]+@[\p{L}\p{N}]'
        . '(?:[\p{L}\p{N}-]{0,61}[\p{L}\p{N}])?'
        . '(?:\.[\p{L}\p{N}](?:[\p{L}\p{N}-]{0,61}[\p{L}\p{N}])?)+'
        . '(?=$|[^\p{L}\p{N}.-]|\.(?=$|[\s<>"\'\)\]\}]))/u';

    private const DOMAIN = '/(?<![\p{L}\p{N}-])'
        . '(?:[\p{L}\p{N}](?:[\p{L}\p{N}-]{0,61}[\p{L}\p{N}])?\.)+'
        . '(?:[\p{L}]{2,63}|xn--[a-z0-9-]{2,59})'
        . '(?=$|[^\p{L}\p{N}.-]|\.(?=$|[\s<>"\'\)\]\}]))/iu';

    private const URL = '#(?<![\p{L}\p{M}\p{N}._%+\-:/\\\\])(?:(?:(?:https?|ftp):[\\\\/]*)|[\\\\/]{2})[^\s<>"\']+#iu';

    private const LABELED_URL = '~(?<![\p{L}\p{M}\p{N}])'
        . '(?:endpoint|link|site|url|website)\s*:\s*'
        . '((?:(?:https?|ftp):[\\\\/]*|[\\\\/]{2})[^\s<>"\']+)~iu';

    private const SCHEMELESS_URL = '~(?<![\p{L}\p{M}\p{N}@._%+\-])'
        . '(?:[\p{L}\p{N}](?:[\p{L}\p{N}-]{0,61}[\p{L}\p{N}])?\.)+'
        . '(?:[\p{L}]{2,63}|xn--[a-z0-9-]{2,59})'
        . '(?:[/\?#][^\s<>"\']+)~iu';

    private const OPAQUE_URI = '/(?<![\p{L}\p{M}\p{N}+.-])'
        . '(?:facetime(?:-audio)?|irc|signal|sip|sips|skype|webcal|whatsapp|xmpp):[^\s<>"\']+'
        . '(?=$|[\s<>"\'])/iu';

    private const PHONE = '/(?<![\p{L}\p{N}])(?:'
        . '\+\d[\d(). \/\-‐‑‒–−]{5,}\d'
        . '|\(?\d{2,4}\)?(?:[ .\/\-‐‑‒–−]\d{2,4}){2,}'
        . ')(?:\s*(?:x|ext\.?|extension)\s*\d{1,6})?(?![\p{L}\p{N}])/iu';

    /** A conservative Latin-script street shape; structured address fields cover other formats. */
    private const STREET = '/(?<![\p{L}\p{N}])\d{1,6}[a-z]?(?:[-\/]\d{1,6}[a-z]?)?\s+'
        . '(?:(?=[\p{L}\p{M}0-9.\'\-]*[\p{L}\p{M}])[\p{L}\p{M}0-9.\'\-]+\s+){0,6}'
        . '(?:street|st\.?|road|rd\.?|avenue|ave\.?|boulevard|blvd\.?|lane|ln\.?|drive|dr\.?|'
        . 'court|ct\.?|way|highway|hwy\.?|place|pl\.?|square|sq\.?|terrace|ter\.?|parkway|pkwy\.?|loop)'
        . '(?:\s+(?:n|s|e|w|ne|nw|se|sw))?(?![\p{L}\p{N}]|\s+[\p{L}\p{M}])/iu';

    /** Street designator immediately after the number, followed by a proper street name. */
    private const PREFIX_STREET = '/(?<![\p{L}\p{N}])\d{1,6}[a-z]?(?:[-\/]\d{1,6}[a-z]?)?'
        . '(?:\s*,\s*|\s+)'
        . '(?:street|road|avenue|boulevard|lane|drive|court|way|highway|place|square|terrace|parkway|'
        . 'via|rue|calle|rua|strada|ulica|avenida|strasse|straße|weg|gasse|platz|chemin)\s+'
        . '(?:[\p{L}\p{M}][\p{L}\p{M}.\'\-]*\s+){0,5}[\p{L}\p{M}][\p{L}\p{M}.\'\-]*'
        . '(?![\p{L}\p{N}]|\s+[\p{L}\p{M}])/iu';

    /** Number-last streets whose first word is an explicit international street designator. */
    private const NUMBER_LAST_STREET = '/(?<![\p{L}\p{N}])'
        . '(?:via|rue|calle|rua|strada|ulica|avenida|avenue|piazza|strasse|straße|weg|gasse|platz|chemin)\s+'
        . '(?:[\p{L}\p{M}][\p{L}\p{M}.\'\-]*(?:\s+|,\s*)){1,6}\d{1,6}[a-z]?'
        . '(?![\p{L}\p{N}])/iu';

    /** Reviewed German number-last street form; avoid arbitrary title-like connector phrases. */
    private const INTERNATIONAL_STREET = '/(?<![\p{L}\p{N}])'
        . '(?:unter|auf|an|in|am|im)\s+(?:den|der|dem)\s+'
        . '(?:[\p{L}\p{M}][\p{L}\p{M}.\'\-]*\s+){1,4}\d{1,6}[a-z]?'
        . '(?![\p{L}\p{N}])/iu';

    private const GERMAN_NUMBER_LAST_STREET = '/(?<![\p{L}\p{N}])'
        . '[\p{L}\p{M}.\'\-]{2,40}(?:stra(?:ss|ß)e|weg|gasse|platz)\s+\d{1,6}[a-z]?'
        . '(?![\p{L}\p{N}])/iu';

    private const JAPANESE_ADDRESS = '/(?<![\p{L}\p{N}])'
        . '(?:東京都|北海道|京都府|大阪府|[\p{L}]{2,4}県)'
        . '[\p{L}\p{M}0-9ー丁目番地号\-]{2,50}\d+(?:-\d+){1,3}'
        . '(?![\p{L}\p{N}])/u';

    private const ROMANIZED_JAPANESE_ADDRESS = '/(?<![\p{L}\p{N}])'
        . '\d{1,3}(?:-\d{1,4}){2,3}\s+'
        . '[\p{L}\p{M}][\p{L}\p{M}.\'\-]*(?:\s+[\p{L}\p{M}][\p{L}\p{M}.\'\-]*){0,3}'
        . ',\s*(?:Tokyo|Kyoto|Osaka|Yokohama|Nagoya|Sapporo|Kobe|Fukuoka|Kawasaki|Saitama|Hiroshima|'
        . 'Sendai|Chiba|Nara|Naha|Kanazawa|Nagasaki|Kumamoto|Kagoshima|[\p{L}\p{M}.\'\-]+-ku)'
        . '(?![\p{L}\p{N}])/u';

    private const PO_BOX = '/(?<![\p{L}\p{N}])(?:p\.?\s*o\.?|post\s+office)\s+box\s+\d+[a-z]?'
        . '(?![\p{L}\p{N}])/iu';

    private const LOCALITY_POSTAL = '/(?<![\p{L}\p{N}])'
        . '[\p{L}\p{M}][\p{L}\p{M}.\'\- ]{1,40},\s*[A-Z]{2,3}\s+\d{4,10}(?:-\d{4})?'
        . '(?![\p{L}\p{N}])/u';

    public static function keyLooksContact(string $key): bool
    {
        return preg_match(self::CONTACT_KEY, self::normalizedKey($key)) === 1;
    }

    public static function keyLooksPhone(string $key): bool
    {
        return preg_match(
            '/(?:^|[^a-z])(?:phones?|telephones?|mobiles?|tels?|hotlines?|whatsapp|fax|'
                . '(?:contact|support|service|callback)[^a-z]+numbers?)(?:$|[^a-z])/i',
            self::normalizedKey($key),
        ) === 1;
    }

    public static function keyLooksEmail(string $key): bool
    {
        return preg_match(
            '/(?:^|[^a-z])(?:e-?mails?|mailtos?)(?:$|[^a-z])/i',
            self::normalizedKey($key),
        ) === 1;
    }

    public static function keyLooksAddress(string $key): bool
    {
        return preg_match(self::ADDRESS_KEY, self::normalizedKey($key)) === 1;
    }

    private static function keyLooksDomain(string $key): bool
    {
        return preg_match(
            '/(?:^|[^a-z])(?:domains?|email[^a-z]+domains?|sites?|urls?|websites?)(?:$|[^a-z])/i',
            self::normalizedKey($key),
        ) === 1;
    }

    /** Whether one complete fact is stated as its own token or bounded phrase. */
    public static function sourceStatesFact(string $source, string $fact): bool
    {
        $fact = trim($fact);
        if ($fact === '' || trim($source) === '') {
            return false;
        }

        $facts = self::candidates($fact);
        if ($facts !== []) {
            $available = self::candidateSet($source);
            foreach ($facts as $candidate) {
                if (!isset($available[$candidate['type']][$candidate['canonical']])) {
                    return false;
                }
            }
            return true;
        }

        $normalizedSource = self::normalizedPhrase($source);
        $normalizedFact = self::normalizedPhrase($fact);
        if ($normalizedFact === '') {
            return false;
        }
        return preg_match(
            '/(?<![\p{L}\p{N}])' . preg_quote($normalizedFact, '/') . '(?![\p{L}\p{N}])/iu',
            $normalizedSource,
        ) === 1;
    }

    /** Whether the complete normalized phrase occurs with token boundaries. */
    public static function sourceStatesExactPhrase(string $source, string $fact): bool
    {
        $normalizedSource = self::normalizedPhrase($source);
        $normalizedFact = self::normalizedPhrase($fact);
        if ($normalizedFact === '' || $normalizedSource === '') {
            return false;
        }
        return preg_match(
            '/(?<![\p{L}\p{N}])' . preg_quote($normalizedFact, '/') . '(?![\p{L}\p{N}])/iu',
            $normalizedSource,
        ) === 1;
    }

    /** A domain must equal a complete domain token, never a suffix or prefix. */
    public static function sourceStatesDomain(string $source, string $domain): bool
    {
        $domain = self::canonicalDomain($domain);
        if ($domain === '') {
            return false;
        }
        foreach (self::domains($source) as $candidate) {
            if ($candidate === $domain) {
                return true;
            }
        }
        return false;
    }

    /** Phone formatting is presentation, but the source must state phone semantics too. */
    public static function sourceStatesPhone(string $source, string $phone): bool
    {
        $destination = self::canonicalDestination('tel:' . $phone);
        if ($destination === null) {
            return false;
        }
        $canonical = substr($destination, strlen('tel:'));
        foreach (self::phoneClaims(self::normalizedInput($source)) as $candidate) {
            if ($candidate['canonical'] === $canonical) {
                return true;
            }
        }

        return false;
    }

    /** A structured URL/URI leaf must match a complete source endpoint. */
    public static function sourceStatesExactDestination(string $source, string $destination): bool
    {
        $destinations = self::exactDestinationCandidates($destination);
        if ($destinations === []) {
            return false;
        }
        $available = [];
        foreach (self::exactDestinationCandidatesInText($source) as $candidate) {
            $available[$candidate['type']][$candidate['canonical']] = true;
        }
        foreach ($destinations as $candidate) {
            if (!isset($available[$candidate['type']][$candidate['canonical']])) {
                return false;
            }
        }
        return true;
    }

    /** Whether a structured email leaf is exactly one valid email identity. */
    public static function isExactEmail(string $value): bool
    {
        return self::exactEmail($value) !== '';
    }

    /**
     * Contact-shaped tokens in $text that are not independently present in
     * $source. Longer tokens cannot ground one of their internal substrings.
     *
     * @return list<array{type:string,authored:string,canonical:string}>
     */
    public static function ungroundedInSource(string $text, string $source): array
    {
        return self::ungroundedAgainstSet(self::candidates($text), self::candidateSet($source));
    }

    /**
     * Contact facts a canonical site spec is allowed to publish.
     *
     * @param array<mixed> $spec
     * @return array<string,array<string,true>>
     */
    public static function candidateSetFromSpec(array $spec): array
    {
        $set = [];
        $walk = function (
            mixed $value,
            string $key = '',
            bool $contactContext = false,
            bool $addressContext = false,
            bool $phoneContext = false,
            bool $domainContext = false,
            bool $emailContext = false,
        ) use (&$walk, &$set): void {
            $contactContext = $contactContext || self::keyLooksContact($key);
            $addressContext = $addressContext || self::keyLooksAddress($key);
            $phoneContext = $phoneContext || self::keyLooksPhone($key);
            $domainContext = $domainContext || self::keyLooksDomain($key);
            $emailContext = $emailContext || self::keyLooksEmail($key);
            if (is_array($value)) {
                foreach ($value as $childKey => $child) {
                    $walk(
                        $child,
                        is_int($childKey) ? $key : (string) $childKey,
                        $contactContext,
                        $addressContext,
                        $phoneContext,
                        $domainContext,
                        $emailContext,
                    );
                }
                return;
            }
            if (!is_string($value) && !is_int($value) && !is_float($value)) {
                return;
            }
            $value = (string) $value;
            if (trim($value) === '') {
                return;
            }
            $exactDestinations = self::exactDestinationCandidates($value);
            if ($exactDestinations !== []) {
                $candidates = $exactDestinations;
            } elseif ($phoneContext) {
                $candidates = [];
            } elseif ($emailContext) {
                $email = self::exactEmail($value);
                $candidates = $email === '' ? [] : [[
                    'type' => 'email',
                    'authored' => trim($value),
                    'canonical' => $email,
                ]];
            } else {
                $candidates = self::candidates($domainContext ? 'website: ' . $value : $value);
            }
            foreach ($candidates as $candidate) {
                if ($candidate['type'] === 'phone' && !$phoneContext) {
                    continue;
                }
                $set[$candidate['type']][$candidate['canonical']] = true;
            }
            if ($phoneContext) {
                $destination = self::canonicalDestination(
                    str_starts_with(strtolower($value), 'tel:') ? $value : 'tel:' . $value,
                );
                if ($destination !== null) {
                    $set['phone'][substr($destination, strlen('tel:'))] = true;
                }
            }
            if ($addressContext || ($contactContext && $candidates === [])) {
                $phrase = self::canonicalLocation($value);
                if ($phrase !== '') {
                    $set['location'][$phrase] = true;
                }
            }
        };
        $walk($spec);
        return $set;
    }

    /**
     * @param list<array{type:string,authored:string,canonical:string}> $candidates
     * @param array<string,array<string,true>> $allowed
     * @return list<array{type:string,authored:string,canonical:string}>
     */
    public static function ungroundedAgainstSet(array $candidates, array $allowed): array
    {
        return array_values(array_filter(
            $candidates,
            static function (array $candidate) use ($allowed): bool {
                if (isset($allowed[$candidate['type']][$candidate['canonical']])) {
                    return false;
                }
                if ($candidate['type'] === 'location'
                    && self::locationContinuationIsAllowed($candidate, $allowed['location'] ?? [])
                ) {
                    return false;
                }
                return true;
            },
        ));
    }

    /**
     * A grounded location may lead into ordinary sentence copy. Compare the
     * complete location first so connectors inside venue names stay intact.
     *
     * @param array{type:string,authored:string,canonical:string} $candidate
     * @param array<string,true>                                $allowedLocations
     */
    private static function locationContinuationIsAllowed(array $candidate, array $allowedLocations): bool
    {
        $authored = $candidate['authored'];
        foreach (array_keys($allowedLocations) as $allowed) {
            if ($allowed === '') {
                continue;
            }
            if (preg_match(
                '/^' . preg_quote($allowed, '/')
                    . '(?:(?:\s+(?<join>for|with|and|during)\s+)|(?:\.)?,\s*'
                    . '(?<narrative>where|open|serving|offering|featuring)\s+)(?<prose>.+)$/iu',
                $authored,
                $match,
            ) !== 1) {
                continue;
            }
            $prose = (string) ($match['prose'] ?? '');
            $ordinaryContinuation = (string) ($match['narrative'] ?? '') !== '' || preg_match(
                '/\b(?:appointments?|available|breakfast|bring|brunch|classes?|consultations?|daily|enjoy|events?|'
                    . 'fall|free|'
                    . 'friend|growth|hello|help|hospitality|'
                    . 'hours?|open|parking|pastries|pay|say|seasonal?|services?|specials?|spring|summer|support|'
                    . 'training|autumn|view|winter|welcome)\b/iu',
                $prose,
            ) === 1 || preg_match('/^(?:مرحب|أهل|اهل|يقدم|نقدم)/u', $prose) === 1;
            if (!$ordinaryContinuation) {
                continue;
            }
            if (preg_match(
                '/\b(?:support|services?)\b[^.!?\r\n]*\b(?:outside|inside|from)\s+'
                    . '(?!(?:all|here|home|local|our|the|there|us|you)\b)'
                    . '[\p{L}\p{M}\'\-]+/iu',
                $prose,
            ) === 1) {
                continue;
            }
            if (self::looksLikePlaceBeforeServiceNoun($prose) || preg_match(
                '/\b(?:offices?|locations?|addresses?|branch(?:es)?|sites?|stores?|shops?|showrooms?|'
                    . 'studios?|venues?|campus(?:es)?|headquarters|outposts?|facilit(?:y|ies))\b/iu',
                $prose,
            ) === 1 || preg_match(
                '/\b(?:across|beyond|throughout)\s+(?!(?:business\s+hours?|expectations?|the\s+day)\b)\p{L}'
                    . '|\b(?:in|at|near|outside|inside|from|to)\s+'
                    . '(?!(?:January|February|March|April|May|June|July|August|September|October|November|'
                    . 'December|Spring|Summer|Autumn|Fall|Winter)\b)'
                    . '\p{Lu}[\p{L}\p{M}\'\-]*/u',
                $prose,
            ) === 1 || preg_match(
                '/\b(?:delivery|parking|pickup)\b[^.!?\r\n]*\b(?:in|at|near|outside|inside|from|to)\s+'
                    . '(?!(?:all|here|home|local|our|the|there|us|you)\b)'
                    . '\p{L}[\p{L}\p{M}\'\-]*/iu',
                $prose,
            ) === 1 || preg_match(
                '/\b(?:appointments?|classes?|consultations?|deliver(?:y|ies)|events?|help|repairs?|seminars?|'
                    . 'services?|support|training|workshops?)\b[^.!?\r\n]*'
                    . '\b(?:at|in|into|through|to|via)\s+'
                    . '(?!(?:all|business|corporate|email|local|nearby|our|phone|referrals?|telephone|the|their|'
                    . 'visiting|discord|facetime|no|online|person|slack|teams|webex|whatsapp|zoom)\b|'
                    . 'google\s+meet\b)'
                    . '\p{L}[\p{L}\p{M}\'\-]*/iu',
                $prose,
            ) === 1 || preg_match(
                '/\b(?:appointments?|consultations?|deliver(?:y|ies)|repairs?|services?|support)\b[^.!?\r\n]*'
                    . '\bfor\s+(?!(?:all|business|corporate|local|nearby|our|the|their|visiting)\b)'
                    . '\p{L}[\p{L}\p{M}\'\-]*\s+(?:clients?|community|customers?|residents?)\b/iu',
                $prose,
            ) === 1 || self::serviceRelationTargetsUnknownPlace($prose) || preg_match(
                '/\b(?:(?:deliver(?:y|ies|ed|ing)?|shipping|shipments?|travel(?:s|ed|ing)?|routes?|transit)\b'
                    . '[^.!?\r\n]*\b(?:into|through|via)|services?\b[^.!?\r\n]*\b(?:into|through))\s+'
                    . '(?!(?:email|phone|referrals?|telephone|the)\b)'
                    . '\p{L}[\p{L}\p{M}\'\-]*/iu',
                $prose,
            ) === 1 || preg_match(
                '/\b(?:around|beside|beyond|near|towards?|within)\s+'
                    . '(?!(?:all|business|here|home|instant|local|our|reach|the|there|us|walking|you)\b)'
                    . '\p{L}[\p{L}\p{M}\'\-]*/iu',
                $prose,
            ) === 1 || preg_match(
                '/\b(?i:within)\s+(?:(?i:walking)\s+(?i:distance)|(?:(?i:easy)\s+)?(?i:reach))\s+'
                    . '(?i:of|from|to)\s+\p{Lu}[\p{L}\p{M}\'\-]*/u',
                $prose,
            ) === 1 || preg_match(
                '/\b(?i:for)\s+(?!(?i:all|local|nearby|our|the|their|visiting)\b)'
                    . '\p{Lu}[\p{L}\p{M}\'\-]*\s+'
                    . '(?i:customers?|clients?|residents?|visitors?|tourists?|community|families)\b/u',
                $prose,
            ) === 1) {
                continue;
            }
            if ((string) ($match['narrative'] ?? '') !== ''
                && (preg_match('/^\p{Lu}[\p{L}\p{M}\'\-]*/u', $prose) === 1
                    || preg_match(
                        '/^(?!(?:local|our|nearby|all|visiting)\b)\p{L}[\p{L}\p{M}\'\-]*\s+'
                            . '(?:customers?|clients?|residents?|visitors?|tourists?|community|families)\b/iu',
                        $prose,
                    ) === 1)
            ) {
                continue;
            }
            foreach (self::candidates($prose) as $nested) {
                if (in_array($nested['type'], ['address', 'location', 'domain', 'email', 'phone', 'url'], true)) {
                    continue 2;
                }
            }
            return true;
        }
        return false;
    }

    private static function serviceRelationTargetsUnknownPlace(string $prose): bool
    {
        preg_match_all(
            '/(?<![\p{L}\p{N}-])(?:appointments?|consultations?|deliver(?:y|ies)|repairs?|services?|support)\b'
                . '[^.!?\r\n]*?\b(?:(?<modal>can|could|may|might|must|should|will|would)\s+)?'
                . '(?<verb>[\p{L}\p{M}\'\-]+)\s+(?<object>[^.!?\r\n]+)/iu',
            $prose,
            $matches,
            PREG_SET_ORDER,
        );
        foreach ($matches as $match) {
            $object = trim((string) ($match['object'] ?? ''));
            $verb = mb_strtolower((string) ($match['verb'] ?? ''));
            $modal = (string) ($match['modal'] ?? '');
            if ($object !== ''
                && !($modal === '' && in_array(
                    $verb,
                    ['at', 'by', 'for', 'in', 'into', 'through', 'to', 'via', 'with', 'without'],
                    true,
                ))
                && self::serviceObjectTargetsUnknownPlace($object, $modal !== '', $verb)
            ) {
                return true;
            }
        }
        return false;
    }

    private static function serviceObjectTargetsUnknownPlace(
        string $object,
        bool $modalRelation,
        string $verb,
    ): bool
    {
        if (preg_match('/^microsoft\s+teams\b/iu', trim($object)) === 1) {
            return false;
        }
        if (preg_match(
            '/^(?<prefix>.*?)\b(?:businesses|clients?|community|customers?|families|people|residents?|teams?|'
                . 'users?|visitors?)\b(?<suffix>.*)$/iu',
            $object,
            $audience,
        ) === 1) {
            $safeModifiers = [
                'all', 'business', 'busy', 'by', 'can', 'corporate', 'could', 'creative', 'current', 'dedicated',
                'diverse', 'engineering', 'enterprise', 'every', 'everyone', 'existing', 'for', 'global', 'growing', 'happy',
                'international', 'local', 'may', 'might', 'must', 'nearby', 'new', 'nonprofit', 'our', 'potential',
                'product', 'prospective', 'regional', 'remote', 'returning', 'should', 'small', 'technical', 'the', 'their', 'to',
                'underserved', 'visiting', 'will', 'with', 'without', 'wordpress', 'would', 'you', 'young',
            ];
            $prefix = mb_strtolower(trim((string) ($audience['prefix'] ?? '')));
            $modifiers = preg_split('/[^\p{L}\p{M}\'\-]+/u', $prefix, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            foreach ($modifiers as $modifier) {
                if (!in_array($modifier, $safeModifiers, true)) {
                    return true;
                }
            }
            $suffix = trim((string) ($audience['suffix'] ?? ''));
            if (preg_match(
                '/\b(?:from|in|near|of|outside)\s+(?<place>[\p{L}\p{M}\'\-]+)/iu',
                $suffix,
                $place,
            ) === 1 && !in_array(mb_strtolower((string) $place['place']), [
                'all', 'home', 'microsoft', 'our', 'the', 'there', 'wordpress', 'you',
            ], true)) {
                return true;
            }
            return false;
        }

        $stripped = preg_replace('/^(?:(?:our|the|their)\s+)/iu', '', trim($object)) ?? trim($object);
        if (preg_match_all(
            '/\b\p{Lu}[\p{L}\p{M}\'\-]*(?:\s+\p{Lu}[\p{L}\p{M}\'\-]*){0,5}\b/u',
            $stripped,
            $properNames,
        ) > 0) {
            foreach ($properNames[0] as $properName) {
                if (!in_array(mb_strtolower($properName), [
                    'discord', 'facetime', 'google meet', 'slack', 'webex', 'wordpress', 'zoom',
                ], true)) {
                    return true;
                }
            }
        }
        $placeVerb = self::serviceVerbTargetsPlace($verb);
        if (preg_match(
            '/^(?:central|downtown|east|eastern|greater|lower|metro|metropolitan|north|northern|south|southern|upper|west|western)\s+'
                . '[\p{L}\p{M}\'\-]+(?:\s+[\p{L}\p{M}\'\-]+){0,2}$/iu',
            $stripped,
        ) === 1) {
            return $placeVerb;
        }
        if (preg_match('/^[\p{L}\p{M}\'\-]+$/u', $stripped) !== 1) {
            return false;
        }
        if (!$placeVerb && !$modalRelation) {
            return false;
        }
        if (!$placeVerb) {
            return false;
        }
        return !in_array(mb_strtolower($stripped), [
            'account', 'appointments', 'available', 'breakfast', 'brunch', 'chat', 'classes', 'consultations',
            'cost', 'costs', 'email', 'events',
            'everyone', 'friends', 'growth', 'guidance', 'help', 'hospitality', 'hours', 'issues', 'less', 'online',
            'accessibility', 'helpful', 'parking', 'pastries', 'people', 'questions', 'recovery', 'referrals', 'remote', 'remotely',
            'services', 'setup', 'specials', 'support', 'training', 'uptime', 'vary', 'view', 'welcome', 'well', 'you',
        ], true);
    }

    private static function serviceVerbTargetsPlace(string $verb): bool
    {
        return in_array(mb_strtolower($verb), [
            'cover', 'covered', 'covering', 'covers',
            'reach', 'reached', 'reaches', 'reaching',
            'serve', 'served', 'serves', 'serving',
            'support', 'supported', 'supporting', 'supports',
            'welcome', 'welcomed', 'welcomes', 'welcoming',
        ], true);
    }

    private static function looksLikePlaceBeforeServiceNoun(string $prose): bool
    {
        if (preg_match(
            '/^(.+?)\s+(appointments?|classes?|consultations?|delivery|events?|help|parking|pickup|repairs?|'
                . 'seminars?|services?|support|training|workshops?)\b/iu',
            $prose,
            $match,
        ) !== 1) {
            return false;
        }
        $modifierPhrase = mb_strtolower(trim((string) $match[1]));
        $modifierPhrase = preg_replace('/\bsame[\s-]+day\b/u', 'same-day', $modifierPhrase) ?? $modifierPhrase;
        $modifierPhrase = preg_replace('/\bwalk[\s-]+in\b/u', 'walk-in', $modifierPhrase) ?? $modifierPhrase;
        $modifiers = preg_split('/\s+/u', $modifierPhrase, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($modifiers === []) {
            return false;
        }
        $allowed = match (mb_strtolower((string) $match[2])) {
            'parking' => [
                'accessible', 'ample', 'bicycle', 'complimentary', 'covered', 'free', 'garage', 'local', 'nearby',
                'on-site', 'onsite', 'our', 'overnight', 'paid', 'public', 'secure', 'staff', 'street',
                'valet', 'visitor',
            ],
            'pickup' => ['contactless', 'curbside', 'free', 'local', 'order', 'our', 'same-day', 'scheduled'],
            'delivery' => ['contactless', 'free', 'local', 'our', 'same-day', 'scheduled'],
            'appointment', 'appointments' => [
                'available', 'evening', 'flexible', 'free', 'local', 'online', 'our', 'same-day', 'scheduled', 'virtual',
                'walk-in', 'weekend',
            ],
            'consultation', 'consultations' => [
                'available', 'expert', 'free', 'local', 'online', 'our', 'virtual',
            ],
            'class', 'classes' => [
                'available', 'community', 'free', 'group', 'local', 'online', 'our', 'private', 'public',
                'remote', 'virtual', 'weekly',
            ],
            'event', 'events' => [
                'annual', 'available', 'community', 'free', 'local', 'online', 'our', 'private', 'public',
                'day', "father's", 'fathers', 'holiday', "mother's", 'mothers', 'seasonal', 'special',
                'virtual', 'weekly',
            ],
            'help' => [
                'available', 'customer', 'enterprise', 'free', 'local', 'online', 'our', 'technical', 'virtual',
            ],
            'seminar', 'seminars', 'workshop', 'workshops' => [
                'available', 'community', 'free', 'group', 'local', 'online', 'our', 'private', 'public',
                'remote', 'technical', 'virtual', 'weekly',
            ],
            'repair', 'repairs' => [
                'available', 'emergency', 'evening', 'free', 'local', 'mobile', 'our', 'scheduled', 'urgent', 'warranty',
            ],
            'service', 'services' => [
                'available', 'customer', 'free', 'full', 'full-service', 'local', 'online', 'our', 'premium',
                'technical',
            ],
            'support' => [
                '24/7', 'available', 'customer', 'dedicated', 'enterprise', 'free', 'full-service', 'local', 'online',
                'our', 'premium', 'technical',
            ],
            'training' => [
                'available', 'customer', 'employee', 'enterprise', 'free', 'local', 'online', 'our', 'remote',
                'staff', 'technical', 'virtual',
            ],
            default => [],
        };
        foreach ($modifiers as $modifier) {
            if (!in_array($modifier, $allowed, true)
            ) {
                return true;
            }
        }
        return false;
    }

    /** Canonical key for exact contact-destination comparison. */
    public static function canonicalDestination(string $destination): ?string
    {
        $destination = trim($destination);
        if (preg_match('/^mailto:(.+)$/i', $destination, $match) === 1) {
            $email = self::canonicalEmail($match[1]);
            return $email === '' ? null : 'mailto:' . $email;
        }
        if (preg_match('/^tel:(.+)$/i', $destination, $match) === 1) {
            $phone = self::canonicalPhone($match[1]);
            return $phone === '' ? null : 'tel:' . $phone;
        }
        if (preg_match('#^https?://#i', $destination) === 1) {
            $url = self::canonicalUrlValue($destination, false);
            return $url === '' ? null : $url;
        }
        return null;
    }

    /** @return list<array{type:string,authored:string,canonical:string}> */
    public static function candidates(string $text): array
    {
        $text = self::normalizedInput($text);
        $found = [];
        $occupied = [];
        self::collect(self::URL, 'url', $text, $found, $occupied, self::canonicalUrl(...));
        self::collect(self::OPAQUE_URI, 'uri', $text, $found, $occupied, self::canonicalOpaqueUri(...));
        self::collect(
            self::SCHEMELESS_URL,
            'url',
            $text,
            $found,
            $occupied,
            self::canonicalSchemelessUrl(...),
        );
        self::collect(self::EMAIL, 'email', $text, $found, $occupied, self::canonicalEmail(...));
        self::collect(self::PHONE, 'phone', $text, $found, $occupied, self::canonicalVisiblePhone(...));
        self::collect(self::STREET, 'address', $text, $found, $occupied, self::canonicalVisibleStreet(...));
        self::collect(self::PREFIX_STREET, 'address', $text, $found, $occupied, self::canonicalAddress(...));
        self::collect(self::NUMBER_LAST_STREET, 'address', $text, $found, $occupied, self::canonicalAddress(...));
        self::collect(
            self::INTERNATIONAL_STREET,
            'address',
            $text,
            $found,
            $occupied,
            self::canonicalAddress(...),
        );
        self::collect(
            self::GERMAN_NUMBER_LAST_STREET,
            'address',
            $text,
            $found,
            $occupied,
            self::canonicalAddress(...),
        );
        self::collect(
            self::JAPANESE_ADDRESS,
            'address',
            $text,
            $found,
            $occupied,
            self::canonicalAddress(...),
        );
        self::collect(
            self::ROMANIZED_JAPANESE_ADDRESS,
            'address',
            $text,
            $found,
            $occupied,
            self::canonicalAddress(...),
        );
        self::collect(self::PO_BOX, 'address', $text, $found, $occupied, self::canonicalAddress(...));
        self::collect(self::LOCALITY_POSTAL, 'address', $text, $found, $occupied, self::canonicalAddress(...));
        self::collect(self::DOMAIN, 'domain', $text, $found, $occupied, self::canonicalDomain(...));
        array_push($found, ...self::phoneClaims($text));
        array_push($found, ...self::locationClaims($text));
        usort($found, static fn (array $left, array $right): int => $left['offset'] <=> $right['offset']);
        return array_map(
            static fn (array $candidate): array => [
                'type' => $candidate['type'],
                'authored' => $candidate['authored'],
                'canonical' => $candidate['canonical'],
            ],
            $found,
        );
    }

    /**
     * URL-shaped values in active HTML/CSS sinks are endpoints, not prose.
     * Preserve endpoint punctuation and identifier spelling exactly.
     *
     * @return list<array{type:string,authored:string,canonical:string}>
     */
    public static function exactDestinationCandidates(string $text): array
    {
        $authored = mb_scrub($text, 'UTF-8');
        $authored = preg_replace('/[\x09\x0A\x0D]/', '', $authored) ?? $authored;
        $authored = preg_replace('/^[\x00-\x20]+|[\x00-\x20]+$/', '', $authored) ?? $authored;
        $type = null;
        $canonical = '';
        if (preg_match('#^(?:(?:https?|ftp):[\\/]*|[\\/]{2})#iu', $authored) === 1) {
            $type = 'url';
            $canonical = self::canonicalUrlValue($authored, false);
        } elseif (preg_match(
            '/^(?:facetime(?:-audio)?|irc|signal|sip|sips|skype|webcal|whatsapp|xmpp):/iu',
            $authored,
        ) === 1) {
            $type = 'uri';
            $canonical = self::canonicalOpaqueUriValue($authored, false);
        }
        if ($type === null) {
            return [];
        }
        if ($canonical === '') {
            $canonical = 'invalid:' . mb_strtolower($authored);
        }
        return [[
            'type' => $type,
            'authored' => $authored,
            'canonical' => $canonical,
        ]];
    }

    /** @return list<array{type:string,authored:string,canonical:string}> */
    private static function exactDestinationCandidatesInText(string $text): array
    {
        $text = mb_scrub($text, 'UTF-8');
        $found = [];
        foreach ([
            ['pattern' => self::URL, 'type' => 'url', 'group' => 0],
            ['pattern' => self::LABELED_URL, 'type' => 'url', 'group' => 1],
            ['pattern' => self::OPAQUE_URI, 'type' => 'uri', 'group' => 0],
        ] as $shape) {
            $count = preg_match_all($shape['pattern'], $text, $matches);
            if ($count === false) {
                continue;
            }
            foreach ($matches[$shape['group']] ?? [] as $authored) {
                $authored = (string) $authored;
                $canonical = $shape['type'] === 'url'
                    ? self::canonicalUrlValue($authored, false)
                    : self::canonicalOpaqueUriValue($authored, false);
                if ($canonical !== '') {
                    $found[$shape['type'] . "\0" . $canonical] = [
                        'type' => $shape['type'],
                        'authored' => $authored,
                        'canonical' => $canonical,
                    ];
                }
                // A final full stop is ordinary sentence punctuation in
                // source prose. Retain the exact endpoint above as well, so a
                // model-authored endpoint that really includes it still has
                // to match byte-for-byte. Do not run the generic prose
                // canonicalizer here: it erases browser-distinct code points.
                if (str_ends_with($authored, '.')) {
                    $sentenceEndpoint = substr($authored, 0, -1);
                    $sentenceCanonical = $shape['type'] === 'url'
                        ? self::canonicalUrlValue($sentenceEndpoint, false)
                        : self::canonicalOpaqueUriValue($sentenceEndpoint, false);
                    if ($sentenceCanonical !== '') {
                        $found[$shape['type'] . "\0" . $sentenceCanonical] = [
                            'type' => $shape['type'],
                            'authored' => $sentenceEndpoint,
                            'canonical' => $sentenceCanonical,
                        ];
                    }
                }
            }
        }
        return array_values($found);
    }

    /**
     * Visitor-visible copy may be treated more strictly than block metadata:
     * a whole-value compact number is a phone, while an ISBN/order id embedded
     * in ordinary copy is not. Phone-language claims are handled separately.
     *
     * @return list<array{type:string,authored:string,canonical:string}>
     */
    public static function visibleCandidates(string $text): array
    {
        $normalized = self::normalizedInput($text);
        $candidates = self::candidates($normalized);
        $trimmed = trim($normalized);
        if (preg_match('/^(\+?\d{10,15})[.!?]?$/u', $trimmed, $phone) === 1) {
            $authored = (string) $phone[1];
            $canonical = self::canonicalPhone($authored);
            if ($canonical !== '') {
                $candidates[] = ['type' => 'phone', 'authored' => $authored, 'canonical' => $canonical];
            }
        }
        $deduped = [];
        foreach ($candidates as $candidate) {
            $deduped[$candidate['type'] . "\0" . $candidate['canonical']] = $candidate;
        }
        return array_values($deduped);
    }

    /** @return array<string,array<string,true>> */
    private static function candidateSet(string $text): array
    {
        $set = [];
        foreach (self::candidates($text) as $candidate) {
            $set[$candidate['type']][$candidate['canonical']] = true;
        }
        return $set;
    }

    /**
     * @param list<array{type:string,authored:string,canonical:string,offset:int,end:int}> $found
     * @param list<array{0:int,1:int}> $occupied
     * @param callable(string):string $canonicalize
     */
    private static function collect(
        string $pattern,
        string $type,
        string $text,
        array &$found,
        array &$occupied,
        callable $canonicalize,
    ): void {
        $count = preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE);
        if ($count === false || $count === 0) {
            return;
        }
        foreach ($matches[0] ?? [] as [$authored, $offset]) {
            $authored = self::trimTrailingPunctuation((string) $authored);
            if ($authored === '') {
                continue;
            }
            $start = (int) $offset;
            if ($type === 'phone' && self::looksLikeStructuredNonPhone($text, $authored, $start)) {
                continue;
            }
            if ($type === 'domain' && self::looksLikeDottedNonDomain($text, $authored, $start)) {
                continue;
            }
            $end = $start + strlen($authored);
            $overlaps = false;
            foreach ($occupied as [$occupiedStart, $occupiedEnd]) {
                if ($start < $occupiedEnd && $end > $occupiedStart) {
                    $overlaps = true;
                    break;
                }
            }
            if ($overlaps) {
                continue;
            }
            $canonical = $canonicalize($authored);
            if ($canonical === '') {
                if ($type === 'url') {
                    $canonical = 'invalid:' . mb_strtolower($authored);
                } else {
                    continue;
                }
            }
            $found[] = compact('type', 'authored', 'canonical', 'offset') + ['end' => $end];
            $occupied[] = [$start, $end];
        }
    }

    /** @return list<string> */
    private static function domains(string $text): array
    {
        $text = self::normalizedInput($text);
        preg_match_all(self::DOMAIN, $text, $matches, PREG_OFFSET_CAPTURE);
        $domains = [];
        foreach ($matches[0] ?? [] as [$authored, $offset]) {
            $authored = self::trimTrailingPunctuation((string) $authored);
            if (self::looksLikeDottedNonDomain($text, $authored, (int) $offset)) {
                continue;
            }
            $canonical = self::canonicalDomain($authored);
            if ($canonical !== '') {
                $domains[$canonical] = true;
            }
        }
        return array_keys($domains);
    }

    private static function canonicalEmail(string $email): string
    {
        $email = trim($email);
        if (preg_match(self::EMAIL, $email, $match) !== 1 || ($match[0] ?? '') !== $email) {
            return '';
        }
        $at = strrpos($email, '@');
        if ($at === false) {
            return '';
        }
        return substr($email, 0, $at) . '@' . mb_strtolower(substr($email, $at + 1));
    }

    private static function exactEmail(string $value): string
    {
        $value = trim($value);
        if (preg_match('/^mailto:(.+)$/iu', $value, $match) === 1) {
            $value = (string) $match[1];
        }
        return self::canonicalEmail($value);
    }

    private static function canonicalDomain(string $domain): string
    {
        return mb_strtolower(rtrim(trim($domain), '.'));
    }

    private static function canonicalSchemelessUrl(string $url): string
    {
        $url = self::trimTrailingPunctuation(trim($url));
        if (preg_match('/^([^\/\?#]+)(.*)$/us', $url, $parts) !== 1) {
            return '';
        }
        $host = self::canonicalDomain((string) $parts[1]);
        $suffix = (string) ($parts[2] ?? '');
        return $host === '' || $suffix === '' ? '' : $host . $suffix;
    }

    private static function canonicalUrl(string $url): string
    {
        return self::canonicalUrlValue($url, true);
    }

    private static function canonicalUrlValue(string $url, bool $trimPunctuation): string
    {
        $url = trim($url);
        if ($trimPunctuation) {
            $url = self::trimTrailingPunctuation($url);
        }
        $suffixStart = strcspn($url, '?#');
        $url = str_replace('\\', '/', substr($url, 0, $suffixStart)) . substr($url, $suffixStart);
        $url = preg_replace('#^((?:https?|ftp):)/+#i', '$1//', $url) ?? $url;
        $url = preg_replace('#^/{2,}#', '//', $url) ?? $url;
        $protocolRelative = str_starts_with($url, '//');
        if (preg_match(
            '#^(?:(?<scheme>https?|ftp):)?//(?<authority>[^/?\#]*)(?<suffix>[/?\#].*)?$#isu',
            $url,
            $urlParts,
        ) !== 1) {
            return '';
        }
        $scheme = $protocolRelative ? 'https' : strtolower((string) ($urlParts['scheme'] ?? ''));
        $authority = (string) ($urlParts['authority'] ?? '');
        $suffix = (string) ($urlParts['suffix'] ?? '');
        if (!in_array($scheme, ['ftp', 'http', 'https'], true)) {
            return '';
        }
        $canonicalAuthority = self::canonicalUrlAuthority($authority, $scheme);
        if ($canonicalAuthority === null) {
            return '';
        }
        $canonical = ($protocolRelative ? '//' : $scheme . '://') . $canonicalAuthority;
        // PHP's URL parser replaces several browser-distinct non-ASCII bytes
        // in both userinfo and path with the same underscore. The authority
        // above and suffix below therefore retain their exact authored bytes.
        if ($suffix !== '/') {
            $canonical .= str_starts_with($suffix, '/?') || str_starts_with($suffix, '/#')
                ? substr($suffix, 1)
                : $suffix;
        }
        return $canonical;
    }

    private static function canonicalUrlAuthority(string $authority, string $scheme): ?string
    {
        if ($authority === '' || preg_match('/[\s\x00-\x1F\x7F]/u', $authority) === 1) {
            return null;
        }
        $userinfo = '';
        $at = strrpos($authority, '@');
        if ($at !== false) {
            $canonicalUserinfo = self::canonicalUrlUserinfo(substr($authority, 0, $at));
            $userinfo = in_array($canonicalUserinfo, ['', ':'], true) ? '' : $canonicalUserinfo . '@';
            $authority = substr($authority, $at + 1);
        }
        if ($authority === '') {
            return null;
        }

        $host = $authority;
        $port = '';
        if (str_starts_with($authority, '[')) {
            $close = strpos($authority, ']');
            if ($close === false) {
                return null;
            }
            $host = substr($authority, 0, $close + 1);
            $remainder = substr($authority, $close + 1);
            if ($remainder !== '') {
                if (!str_starts_with($remainder, ':')) {
                    return null;
                }
                $port = self::canonicalUrlPort(substr($remainder, 1));
                if ($port === null) {
                    return null;
                }
            }
        } else {
            $colon = strrpos($authority, ':');
            if ($colon !== false) {
                if (str_contains(substr($authority, 0, $colon), ':')) {
                    return null;
                }
                $candidatePort = substr($authority, $colon + 1);
                $port = self::canonicalUrlPort($candidatePort);
                if ($port === null) {
                    return null;
                }
                $host = substr($authority, 0, $colon);
            }
        }
        if ($host === '') {
            return null;
        }
        if (str_starts_with($host, '[')) {
            $packed = inet_pton(substr($host, 1, -1));
            if (!is_string($packed)) {
                return null;
            }
            $printed = inet_ntop($packed);
            if (!is_string($printed)) {
                return null;
            }
            $host = '[' . $printed . ']';
        } else {
            $host = rawurldecode($host);
            if ($host === '' || str_contains($host, '..')
                || preg_match('/[\s\x00-\x1F\x7F\/@:\?#]/u', $host) === 1
                || !function_exists('idn_to_ascii')
            ) {
                return null;
            }
            if (self::looksLikeIpv4NumberHost($host)) {
                $ipv4 = self::canonicalIpv4NumberHost($host);
                if ($ipv4 === null) {
                    return null;
                }
                $host = $ipv4;
            } else {
                $asciiHost = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
                if (!is_string($asciiHost) || $asciiHost === '') {
                    return null;
                }
                $host = $asciiHost;
            }
        }
        if (($scheme === 'https' && $port === ':443')
            || ($scheme === 'http' && $port === ':80')
            || ($scheme === 'ftp' && $port === ':21')
        ) {
            $port = '';
        }
        return $userinfo . mb_strtolower($host) . $port;
    }

    private static function canonicalUrlUserinfo(string $userinfo): string
    {
        $colon = strpos($userinfo, ':');
        if ($colon === false) {
            return rawurlencode(rawurldecode($userinfo));
        }
        $username = rawurlencode(rawurldecode(substr($userinfo, 0, $colon)));
        $password = rawurlencode(rawurldecode(substr($userinfo, $colon + 1)));
        return $password === '' ? $username : $username . ':' . $password;
    }

    private static function canonicalUrlPort(string $port): ?string
    {
        if ($port === '') {
            return '';
        }
        if (preg_match('/^\d+$/', $port) !== 1) {
            return null;
        }
        $port = ltrim($port, '0');
        if ($port === '') {
            return ':0';
        }
        if (strlen($port) > 5 || (strlen($port) === 5 && strcmp($port, '65535') > 0)) {
            return null;
        }
        return ':' . (string) (int) $port;
    }

    private static function looksLikeIpv4NumberHost(string $host): bool
    {
        $host = str_ends_with($host, '.') ? substr($host, 0, -1) : $host;
        if ($host === '') {
            return false;
        }
        foreach (explode('.', $host) as $part) {
            if (preg_match('/^(?:0[xX][0-9a-fA-F]+|0[0-7]*|[1-9][0-9]*|0)$/', $part) !== 1) {
                return false;
            }
        }
        return true;
    }

    private static function canonicalIpv4NumberHost(string $host): ?string
    {
        $host = str_ends_with($host, '.') ? substr($host, 0, -1) : $host;
        $parts = explode('.', $host);
        if ($parts === [] || count($parts) > 4) {
            return null;
        }
        $numbers = [];
        foreach ($parts as $part) {
            $base = 10;
            $digits = $part;
            if (preg_match('/^0[xX]/', $part) === 1) {
                [$base, $digits] = [16, substr($part, 2)];
            } elseif (strlen($part) > 1 && $part[0] === '0') {
                [$base, $digits] = [8, substr($part, 1)];
            }
            $numbers[] = $digits === '' ? 0 : intval($digits, $base);
        }
        $last = array_pop($numbers);
        if (!is_int($last)) {
            return null;
        }
        foreach ($numbers as $number) {
            if ($number > 255) {
                return null;
            }
        }
        $remainingBytes = 4 - count($numbers);
        $lastLimit = (256 ** $remainingBytes) - 1;
        if ($last > $lastLimit) {
            return null;
        }
        $value = $last;
        foreach ($numbers as $index => $number) {
            $value += $number * (256 ** (3 - $index));
        }
        return implode('.', [
            (string) (($value >> 24) & 255),
            (string) (($value >> 16) & 255),
            (string) (($value >> 8) & 255),
            (string) ($value & 255),
        ]);
    }

    private static function canonicalOpaqueUri(string $uri): string
    {
        return self::canonicalOpaqueUriValue($uri, true);
    }

    private static function canonicalOpaqueUriValue(string $uri, bool $trimPunctuation): string
    {
        $uri = trim($uri);
        if ($trimPunctuation) {
            $uri = self::trimTrailingPunctuation($uri);
        }
        $colon = strpos($uri, ':');
        if ($colon === false || $colon === strlen($uri) - 1) {
            return '';
        }
        return strtolower(substr($uri, 0, $colon)) . substr($uri, $colon);
    }

    private static function canonicalPhone(string $phone): string
    {
        $phone = trim(self::normalizedPhoneInput($phone));
        if (preg_match(
            '/^(\+?[\d(). \/\-]*\d)'
                . '(?:(?:\s*(?:x|ext\.?|extension)\s*|;ext=)(\d{1,6}))?$/iu',
            $phone,
            $match,
        ) !== 1) {
            return '';
        }
        $main = (string) $match[1];
        $leadingPlus = str_starts_with($main, '+');
        $digits = preg_replace('/\D+/', '', $main) ?? '';
        if (strlen($digits) < 7 || strlen($digits) > 15) {
            return '';
        }
        $canonical = ($leadingPlus ? '+' : '') . $digits;
        $extension = (string) ($match[2] ?? '');
        return $extension === '' ? $canonical : $canonical . ';ext=' . $extension;
    }

    private static function canonicalVisiblePhone(string $phone): string
    {
        $trimmed = trim($phone);
        if (preg_match('/^(?:\d{4}[-\/.]\d{1,2}[-\/.]\d{1,2}|\d{1,2}[-\/.]\d{1,2}[-\/.]\d{2,4})$/', $trimmed) === 1) {
            return '';
        }
        return self::canonicalPhone($trimmed);
    }

    private static function canonicalAddress(string $address): string
    {
        return self::normalizedPhrase(rtrim($address, ",.;:!?"));
    }

    private static function canonicalVisibleStreet(string $address): string
    {
        if (preg_match(
            '/^\d{1,6}[a-z]?(?:[-\/]\d{1,6}[a-z]?)?\s+'
                . '(?:hours?|days?|weeks?|months?|years?|ways?|reasons?|tips?|steps?)\b/iu',
            $address,
        ) === 1 || preg_match('/\b(?:to|on|the|of|for|with)\b/iu', $address) === 1) {
            return '';
        }
        return self::canonicalAddress($address);
    }

    private static function normalizedPhrase(string $text): string
    {
        $text = mb_scrub($text, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);
        return mb_strtolower($text);
    }

    private static function canonicalLocation(string $text): string
    {
        $text = preg_replace('/[\s,.;:!?。！？]+$/u', '', $text) ?? $text;
        return self::normalizedPhrase($text);
    }

    private static function isExplicitLocationLead(string $lead): bool
    {
        return preg_match(
            '/(?:address|location|(?:会社(?:の)?|支店)所在地|所在地|住所|地址|'
                . 'direcci[oó]n(?:\s+de\s+la\s+oficina)?|adresse(?:\s+des\s+b[üu]ros)?|'
                . 'adresse(?:\s+(?:du|des)\s+bureau[xs]?)?|'
                . 'endere[cç]o(?:\s+do\s+escrit[oó]rio)?|indirizzo|anschrift|'
                . 'адрес|주소|العنوان|पता)\s*(?:is|:|：)\s*$/iu',
            $lead,
        ) === 1;
    }

    private static function locationClaimTail(string $tail): string
    {
        $length = strcspn($tail, "!?\r\n<");
        $candidate = substr($tail, 0, $length);
        $unicodeBoundary = mb_strpos($candidate, '。');
        foreach (['！', '？'] as $punctuation) {
            $position = mb_strpos($candidate, $punctuation);
            if ($position !== false && ($unicodeBoundary === false || $position < $unicodeBoundary)) {
                $unicodeBoundary = $position;
            }
        }
        if ($unicodeBoundary !== false) {
            $candidate = mb_substr($candidate, 0, $unicodeBoundary);
        }

        $offset = 0;
        while (($period = strpos($candidate, '.', $offset)) !== false) {
            $before = substr($candidate, 0, $period);
            $after = substr($candidate, $period + 1);
            if (preg_match('/^\s*,/u', $after) === 1) {
                $offset = $period + 1;
                continue;
            }
            preg_match('/([\p{L}]{1,12})$/u', $before, $word);
            $abbreviation = mb_strtolower((string) ($word[1] ?? ''));
            $isInitial = preg_match('/^\p{L}$/u', (string) ($word[1] ?? '')) === 1;
            if ($isInitial
                && (preg_match('/^\s*\p{Lu}\./u', $after) === 1
                    || (preg_match('/\b(?:\p{Lu}\.)+\p{Lu}$/u', $before) === 1
                        && preg_match('/^\s+\p{Lu}[\p{L}\p{M}\'\-]*/u', $after) === 1))
            ) {
                $offset = $period + 1;
                continue;
            }
            if (in_array($abbreviation, [
                'ave', 'blvd', 'ct', 'dr', 'ft', 'hwy', 'ln', 'mt', 'pl', 'pkwy', 'rd', 'sq', 'st', 'ter',
            ], true)) {
                if (trim($after) !== ''
                    && preg_match('/\d[^.!?]*\b' . preg_quote($abbreviation, '/') . '$/iu', $before) === 1
                ) {
                    $candidate = substr($candidate, 0, $period);
                    break;
                }
                $offset = $period + 1;
                continue;
            }
            $candidate = substr($candidate, 0, $period);
            break;
        }
        return trim($candidate, " \t\n\r\0\x0B,;:");
    }

    private static function isVenueDescriptor(string $phrase): bool
    {
        $words = preg_split('/[\s-]+/u', $phrase, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($words === []) {
            return false;
        }
        foreach ($words as $word) {
            if (!in_array($word, [
                'award', 'boutique', 'brand', 'community', 'concept', 'flagship', 'gift', 'local', 'main',
                'nearest', 'new', 'online', 'physical', 'pop', 'retail', 'seasonal', 'temporary', 'up',
                'family', 'newly', 'owned', 'renovated', 'virtual', 'winning',
            ], true)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Contact-language makes an otherwise arbitrary place phrase observable
     * without pretending every capitalized word is a city.
     *
     * @return list<array{type:string,authored:string,canonical:string,offset:int,end:int}>
     */
    private static function locationClaims(string $text): array
    {
        $count = preg_match_all(
            '/\b(?:visit|find|meet)\s+us\s+(?:in|at)|\b(?:located|based|headquartered)\s+(?:in|at)|'
                . '(?<![\p{L}\p{N}])(?:our\s+)?(?:address|location|(?:会社(?:の)?|支店)所在地|所在地|'
                . '住所|地址|direcci[oó]n(?:\s+de\s+la\s+oficina)?|'
                . 'adresse(?:\s+(?:du|des)\s+bureau[xs]?)?|adresse(?:\s+des\s+b[üu]ros)?|'
                . 'endere[cç]o(?:\s+do\s+escrit[oó]rio)?|indirizzo|anschrift|'
                . 'адрес|주소|العنوان|पता)\s*(?:is|:|：)\s*|'
                . '\b(?:our\s+)?(?:offices?|branch(?:es)?|headquarters?|venues?|stores?|shops?|showrooms?|'
                . 'studios?|campus(?:es)?|facilit(?:y|ies))\s+'
                . '(?:(?:is|are)\s+)?(?:located\s+)?(?:in|at)\s+|'
                . '\b(?:our\s+)?(?:office|branch|headquarters)\s*:\s*/iu',
            $text,
            $matches,
            PREG_OFFSET_CAPTURE,
        );
        if ($count === false) {
            return [];
        }
        $claims = [];
        foreach ($matches[0] as [$lead, $offset]) {
            $start = (int) $offset + strlen((string) $lead);
            $tail = substr($text, $start);
            if (!is_string($tail)) {
                continue;
            }
            $authored = self::locationClaimTail($tail);
            $explicitLocationLabel = self::isExplicitLocationLead((string) $lead);
            if ((str_contains((string) $lead, ':') || str_contains((string) $lead, '：'))
                && !$explicitLocationLabel
                && !self::looksLikeColonLocation($authored)
            ) {
                continue;
            }
            $canonical = self::canonicalLocation($authored);
            if ($canonical === '') {
                continue;
            }
            $claims[] = [
                'type' => 'location',
                'authored' => $authored,
                'canonical' => $canonical,
                'offset' => $start,
                'end' => $start + strlen($authored),
            ];
        }

        $placeFirstCount = preg_match_all(
            '/\b(?i:visit|find|meet)\s+(?i:our|the)\s+'
                . '([\p{Lu}][\p{L}\p{M}\'\-]*(?:\s+[\p{Lu}][\p{L}\p{M}\'\-]*){0,5})\s+'
                . '(?i:offices?|branch(?:es)?|headquarters?|venues?|stores?|shops?|showrooms?|studios?|'
                . 'campus(?:es)?|facilit(?:y|ies))\b/u',
            $text,
            $placeFirstMatches,
            PREG_OFFSET_CAPTURE,
        );
        if ($placeFirstCount !== false) {
            foreach ($placeFirstMatches[1] ?? [] as [$authored, $offset]) {
                $canonical = self::canonicalLocation((string) $authored);
                if ($canonical === '') {
                    continue;
                }
                if (self::isVenueDescriptor($canonical)) {
                    continue;
                }
                $claims[] = [
                    'type' => 'location',
                    'authored' => (string) $authored,
                    'canonical' => $canonical,
                    'offset' => (int) $offset,
                    'end' => (int) $offset + strlen((string) $authored),
                ];
            }
        }
        return $claims;
    }

    private static function looksLikeColonLocation(string $value): bool
    {
        if (preg_match(
            '/^(?:closed|open|opening|closing|hours?|remote|online|temporarily|today|tomorrow|'
                . '(?:business|holiday|regular|seasonal|special)\s+hours?|'
                . '(?:staff|team)\s+(?:meeting|training|lunch|retreat|offsite|only)|'
                . '(?:private\s+event|public\s+holiday)|maintenance|renovations?|'
                . '(?:spring|summer|autumn|fall|winter|holiday)\s+break|appointments?\s+required|'
                . 'by\s+appointment|appointments?\s+only)\b/iu',
            $value,
        ) === 1) {
            return false;
        }
        return preg_match(
            '/^[\p{Lu}][\p{L}\p{M}\'\-]*(?:\s+[\p{Lu}][\p{L}\p{M}\'\-]*){0,5}'
                . '(?:,\s*[\p{Lu}]{2,3}(?:\s+\d{4,10}(?:-\d{4})?)?)?$/u',
            $value,
        ) === 1;
    }

    /**
     * A phone-language cue makes a separator-free visible number observable
     * without classifying block IDs, dates, and order numbers as phones.
     *
     * @return list<array{type:string,authored:string,canonical:string,offset:int,end:int}>
     */
    private static function phoneClaims(string $text): array
    {
        $count = preg_match_all(
            '/(?<![\p{L}\p{N}])(?:☎|📞|📱|call|contact|dial|ring|reach|support|reservations?|appointments?|'
                . 'phone|telephone|tel\.?|mobile|text|sms|hotline|whatsapp|fax|tel[eé]fono|téléphone|'
                . 'telefon(?:nummer|\s*-\s*nr\.?)?|telefone\s+(?:de|para)\s+contato|telefone|telefono|'
                . 'whatsapp\s+de\s+ventas|n[uú]mero\s+de\s+contacto|telefonische\s+auskunft|'
                . 'телефон|電話番号|電話|电话|'
                . '전화|الهاتف|هاتف|फ़ोन|फोन|ফোন)'
                . '(?![\p{L}\p{N}])'
                . '(?:\s+(?:number|line|us|me|at|on|only|enquir(?:y|ies)|inquir(?:y|ies))){0,4}'
                . '\s*(?:[,;:：—–-]\s*)?'
                . '(\+?\d[\d(). \/\-‐‑‒–−]{5,}\d(?:\s*(?:x|ext\.?|extension)\s*\d{1,6})?)/iu',
            $text,
            $matches,
            PREG_OFFSET_CAPTURE,
        );
        if ($count === false || $count === 0) {
            return [];
        }
        $claims = [];
        foreach ($matches[1] as [$authored, $offset]) {
            $canonical = self::canonicalVisiblePhone((string) $authored);
            if ($canonical === '') {
                continue;
            }
            $claims[] = [
                'type' => 'phone',
                'authored' => (string) $authored,
                'canonical' => $canonical,
                'offset' => (int) $offset,
                'end' => (int) $offset + strlen((string) $authored),
            ];
        }
        return $claims;
    }

    /** Suppress formatted identifiers unless explicit phone language re-adds them. */
    private static function looksLikeStructuredNonPhone(string $text, string $candidate, int $offset): bool
    {
        if (str_starts_with($candidate, '+')) {
            return false;
        }
        if (preg_match('/^\d{1,3}(?:\.\d{1,3}){3}$/', $candidate) === 1) {
            $octets = array_map('intval', explode('.', $candidate));
            if (max($octets) <= 255) {
                return true;
            }
        }
        $prefix = substr($text, max(0, $offset - 32), min(32, $offset));
        return preg_match(
            '/(?:account|invoice|isbn|member|order|pedido|product|ref(?:erence)?|resolution|sku|ssn|ticket|'
                . 'tracking|version|ip|id)\s*(?:number|no\.?|#|:)?\s*$/iu',
            $prefix,
        ) === 1;
    }

    /** Avoid treating obvious file/version tokens as visitor-facing domains. */
    private static function looksLikeDottedNonDomain(string $text, string $candidate, int $offset): bool
    {
        $prefix = substr($text, 0, $offset);
        $suffix = substr($text, $offset + strlen($candidate));
        if (preg_match('/(?:(?:&#(?:x[0-9a-f]+|[0-9]+);?)|(?:&[a-z][a-z0-9]+;)){1,4}$/iu', $prefix) === 1) {
            return true;
        }
        $immediateFileCue = preg_match(
            '/\b(?:downloads?|file(?:name)?|image|photo|portrait|document|menu|release|version|asset|icon|font)'
                . '(?:\s+(?:named|called))?\s*$/iu',
            $prefix,
        ) === 1;
        $immediateDownloadTail = preg_match('/^\s+(?:(?:for|to)\s+)?downloads?\b/iu', $suffix) === 1;
        $extension = strtolower((string) pathinfo($candidate, PATHINFO_EXTENSION));
        if (in_array($extension, [
            'avif', 'bmp', 'css', 'csv', 'doc', 'docx', 'eot', 'epub', 'gif', 'heic', 'heif',
            'htm', 'html', 'ico', 'jpeg', 'jpg', 'js', 'json', 'm4a', 'md', 'mov', 'mp3', 'mp4',
            'odf', 'ods', 'odt', 'ogg', 'otf', 'pdf', 'php', 'png', 'ppt', 'pptx', 'rtf', 'svg',
            'tif', 'tiff', 'tsv', 'ttf', 'txt', 'wav', 'webm', 'webp', 'woff', 'woff2', 'xls',
            'xlsx', 'xml', 'yaml', 'yml', 'zip',
        ], true)) {
            if ($immediateFileCue || $immediateDownloadTail) {
                return true;
            }
            return preg_match(
                '/(?:domain|site|url|website|browser)\b|\b(?:click|visit)\b|'
                    . '\b(?:go|browse|navigate)\s+to\b/iu',
                $text,
            ) !== 1;
        }
        return $immediateFileCue || $immediateDownloadTail;
    }

    private static function normalizedKey(string $key): string
    {
        return preg_replace('/(?<=[a-z])(?=[A-Z])/', '_', $key) ?? $key;
    }

    private static function normalizedInput(string $text): string
    {
        $text = mb_scrub($text, 'UTF-8');
        $text = preg_replace('/\p{Default_Ignorable_Code_Point}/u', '', $text) ?? $text;
        $text = str_replace(["\t", "\n", "\r", "\f", "\v"], ' ', $text);
        return preg_replace('/\p{Z}+/u', ' ', $text) ?? $text;
    }

    private static function normalizedPhoneInput(string $text): string
    {
        $text = self::normalizedInput($text);
        $text = preg_replace_callback('/\p{Nd}/u', static function (array $match): string {
            $codepoint = mb_ord($match[0]);
            foreach ([
                0x0030, 0x0660, 0x06F0, 0x07C0, 0x0966, 0x09E6, 0x0A66, 0x0AE6,
                0x0B66, 0x0BE6, 0x0C66, 0x0CE6, 0x0D66, 0x0DE6, 0x0E50, 0x0ED0,
                0x0F20, 0x1040, 0x1090, 0x17E0, 0x1810, 0x1946, 0x19D0, 0x1A80,
                0x1A90, 0x1B50, 0x1BB0, 0x1C40, 0x1C50, 0xA620, 0xA8D0, 0xA900,
                0xA9D0, 0xA9F0, 0xAA50, 0xABF0, 0xFF10,
            ] as $zero) {
                if ($codepoint >= $zero && $codepoint <= $zero + 9) {
                    return (string) ($codepoint - $zero);
                }
            }
            return $match[0];
        }, $text) ?? $text;
        return str_replace(['‐', '‑', '‒', '–', '−'], '-', $text);
    }

    private static function trimTrailingPunctuation(string $value): string
    {
        return rtrim($value, ",.;:!?)]}");
    }
}
