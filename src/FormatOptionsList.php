<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use SubtitleToolbox\Exceptions\InvalidArgumentException;

/**
 * Checks and searches the list of per-format option objects in ReadOptions::$formats and WriteOptions::$formats.
 *
 * @internal
 */
final class FormatOptionsList
{
    /**
     * @param array<mixed>  $formats
     * @param class-string  $interface
     */
    public static function check(array $formats, string $interface, string $field): void
    {
        $classes = [];
        foreach ($formats as $options) {
            if (!$options instanceof $interface) {
                throw new InvalidArgumentException("$field takes " . self::shortName($interface) . " objects, got " . get_debug_type($options) . ".");
            }
            if (isset($classes[$options::class])) {
                throw new InvalidArgumentException("$field holds 2 " . self::shortName($options::class) . " objects. Pass at most one per class.");
            }
            $classes[$options::class] = true;
        }
    }


    /**
     * @template T of object
     *
     * @param list<object>    $formats
     * @param class-string<T> $class
     *
     * @return T|null
     */
    public static function find(array $formats, string $class): ?object
    {
        foreach ($formats as $options) {
            if ($options instanceof $class) {
                return $options;
            }
        }

        return null;
    }


    /**
     * Returns $formats with $options in place of the object of its class, or with $options added.
     *
     * @template T of object
     *
     * @param list<T> $formats
     * @param T       $options
     *
     * @return list<T>
     */
    public static function with(array $formats, object $options): array
    {
        $others = array_filter($formats, fn (object $other): bool => !$other instanceof $options);

        return [...array_values($others), $options];
    }


    private static function shortName(string $class): string
    {
        return substr(strrchr("\\" . $class, "\\"), 1);
    }
}
