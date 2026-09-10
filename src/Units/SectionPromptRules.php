<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Units;

use Automattic\SiteBuild\Motion;
use Automattic\SiteBuild\PromptRenderer;

/** Select authoring instructions from committed assignments, never prose guesses. */
final class SectionPromptRules
{
    public function __construct(private PromptRenderer $renderer) {}

    /** The selected site-wide construction stays cacheable across sections. */
    public function card(string $cardStyle): string
    {
        return $this->render('card', [
            'card_style' => $cardStyle,
            'card_recipe' => $this->render('card-' . $cardStyle),
        ]);
    }

    /** Missing/invalid commitments stay static, as the delivery motion gate does. */
    public function motion(mixed $profile): string
    {
        if (!is_string($profile) || !in_array($profile, Motion::PROFILES, true) || $profile === 'none') {
            return 'Motion: none — use NO motion classes. Never write animation CSS or `@keyframes`.';
        }
        $hover = $this->render('motion-hover');
        if ($profile === 'minimal') {
            return "Motion: minimal — hover micro-interactions only.\n" . $hover;
        }
        return $hover . "\n\n" . $this->render('motion-animated', [
            'profile_choreography' => $this->render('motion-' . $profile),
        ]);
    }

    /** @param array<string,string> $vars */
    private function render(string $name, array $vars = []): string
    {
        return rtrim($this->renderer->render('section-guidance/' . $name . '.md', $vars), "\r\n");
    }
}
