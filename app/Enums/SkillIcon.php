<?php

namespace App\Enums;

enum SkillIcon: string
{
    case hexagone_creux = '⬡';
    case hexagone_plein = '⬢';
    case carre_pointe = '◈';
    case cible = '◉';
    case double_cercle = '◎';
    case cercle_croix = '⊕';
    case cercle_x = '⊗';
    case cercle_point = '⊙';
    case losange_plein = '◆';
    case losange_creux = '◇';
    case petit_losange = '⋄';
    case triangle_plein = '▲';
    case triangle_creux = '△';
    case delta = '⌬';
    case etoile_4 = '✦';
    case etoile_creuse = '✧';
    case etoile_apl = '⍟';
    case etoile_operateur = '⋆';
    case hexagone_apl = '⎔';
    case anneau_benzene = '⏣';
    case marqueur_terminal = '∎';
    case therefore = '∴';
    case reference = '※';
    case trigramme = '☰';
    case engrenage = '⚙';
    case eclair = '⚡';
    case chevron = '›';
    case double_chevron = '»';

    /** @return list<string> */
    public static function glyphs(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** @return list<array{glyph: string, key: string}> */
    public static function forJs(): array
    {
        return array_map(
            fn (self $icon) => ['glyph' => $icon->value, 'key' => $icon->name],
            self::cases(),
        );
    }
}
