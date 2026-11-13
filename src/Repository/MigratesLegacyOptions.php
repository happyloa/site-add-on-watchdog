<?php

namespace Watchdog\Repository;

/**
 * Reads an option and, when it is still unset, moves over the value saved
 * under the option name used before the plugin was renamed.
 */
trait MigratesLegacyOptions
{
    private function getOptionWithLegacy(string $option, string $legacyOption, mixed $default): mixed
    {
        $value = get_option($option, $default);
        if ($value !== $default) {
            return $value;
        }

        $legacyValue = get_option($legacyOption, $default);
        if ($legacyValue !== $default) {
            update_option($option, $legacyValue, false);

            return $legacyValue;
        }

        return $value;
    }
}
