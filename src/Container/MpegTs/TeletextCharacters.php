<?php

declare(strict_types=1);

namespace SubtitleToolbox\Container\MpegTs;

use SubtitleToolbox\Exceptions\ParsingException;

/**
 * The G0 character sets of teletext, from ETS 300 706 tables 33, 35 and 36.
 *
 * @internal
 */
final class TeletextCharacters
{
    // The 13 positions of a national option subset in the Latin G0 set.
    private const NATIONAL_POSITIONS = [0x23, 0x24, 0x40, 0x5B, 0x5C, 0x5D, 0x5E, 0x5F, 0x60, 0x7B, 0x7C, 0x7D, 0x7E];

    private const NATIONAL_SUBSETS = [
        "english"   => "£\$@\u{2190}½\u{2192}\u{2191}#\u{2014}¼\u{2016}¾÷",
        "french"    => "éïàëêùî#èâôûç",
        "swedish"   => "#¤ÉÄÖÅÜ_éäöåü",
        "czech"     => "#ůčťžýířéáěúš",
        "german"    => "#\$§ÄÖÜ^_°äöüß",
        "spanish"   => "ç\$¡áéíóú¿üñèà",
        "italian"   => "£\$é°ç\u{2192}\u{2191}#ùàòèì",
        "polish"    => "#ńąƵŚŁćóężśłź",
        "turkish"   => "\u{20BA}ğİŞÖÇÜĞışöçü",
        "serbian"   => "#ËČĆŽĐŠëčćžđš",
        "romanian"  => "#¤ŢÂŞĂÎıţâşăî",
        "estonian"  => "#õŠÄÖŽÜÕšäöžü",
        "lithuanian" => "#\$ŠėęŽčūšąųžį",
    ];

    // The non-Latin G0 sets from 0x20 to 0x7F. Only the Cyrillic and Greek sets differ from ASCII below 0x40.
    private const SETS = [
        "cyrillic1" => " !\"#\$%&'()*+,-./0123456789:;<=>?ЧАБЦДЕФГХИЈКЛМНОПЌРСТУВЃЉЊЗЋЖЂШЏчабцдефгхијклмнопќрстувѓљњзћжђш\u{25A0}",
        "cyrillic2" => " !\"#\$%ы'()*+,-./0123456789:;<=>?ЮАБЦДЕФГХИЙКЛМНОПЯРСТУЖВЬЪЗШЭЩЧЫюабцдефгхийклмнопярстужвьъзшэщч\u{25A0}",
        "cyrillic3" => " !\"#\$%ї'()*+,-./0123456789:;<=>?ЮАБЦДЕФГХИЙКЛМНОПЯРСТУЖВЬІЗШЄЩЧЇюабцдефгхийклмнопярстужвьізшєщч\u{25A0}",
        "greek"     => " !\"#\$%&'()*+,-./0123456789:;«=»?ΐΑΒΓΔΕΖΗΘΙΚΛΜΝΞΟΠΡ\u{0374}ΣΤΥΦΧΨΩΪΫάέήίΰαβγδεζηθικλμνξοπρςστυφχψωϊϋόύώ\u{25A0}",
    ];

    // The G0 set of each national option C12 to C14, read with C12 as the lowest bit, in each group of
    // Table 33. The group comes from packet X/28 or M/29. Arabic and Hebrew are null.
    private const GROUPS = [
        0  => ["english", "french", "swedish", "czech", "german", "spanish", "italian", "english"],
        1  => ["polish", "french", "swedish", "czech", "german", "english", "italian", "english"],
        2  => ["english", "french", "swedish", "turkish", "german", "spanish", "italian", "english"],
        3  => ["english", "french", "swedish", "czech", "german", "serbian", "italian", "romanian"],
        4  => ["cyrillic1", "cyrillic2", "estonian", "czech", "german", "cyrillic3", "lithuanian", "english"],
        6  => ["english", "french", "swedish", "turkish", "german", "spanish", "italian", "greek"],
        8  => ["english", "french", "swedish", "czech", "german", "spanish", "italian", null],
        10 => ["english", "french", "swedish", "czech", "german", null, "italian", null],
    ];

    // The group a receiver in the country of the language uses when the stream sends no X/28 or M/29 packet.
    private const LANGUAGE_GROUPS = [
        "pol" => 1, "tur" => 2, "hrv" => 3, "slv" => 3, "bos" => 3, "ron" => 3, "rum" => 3,
        "rus" => 4, "ukr" => 4, "bul" => 4, "srp" => 4, "mkd" => 4, "mac" => 4, "bel" => 4, "est" => 4, "lav" => 4, "lit" => 4,
        "gre" => 6, "ell" => 6,
    ];


    public static function languageGroup(string $language): int
    {
        return self::LANGUAGE_GROUPS[$language] ?? 0;
    }


    /**
     * Returns the characters from 0x20 to 0x7F of the G0 set of a group and a national option.
     *
     * @return list<string>
     *
     * @throws ParsingException for the Arabic and Hebrew sets.
     */
    public static function g0(int $group, int $nationalOption, int $page): array
    {
        $name = (self::GROUPS[$group] ?? self::GROUPS[0])[$nationalOption]
            ?? throw new ParsingException("Teletext page $page uses the Arabic or Hebrew character set. The reader reads Latin, Cyrillic and Greek pages.");

        if (isset(self::SETS[$name])) {
            return mb_str_split(self::SETS[$name]);
        }

        $characters        = array_map(chr(...), range(0x20, 0x7E));
        $characters[]      = "\u{25A0}";
        foreach (mb_str_split(self::NATIONAL_SUBSETS[$name]) as $index => $character) {
            $characters[self::NATIONAL_POSITIONS[$index] - 0x20] = $character;
        }

        return $characters;
    }
}
