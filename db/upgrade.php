<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Upgrade steps for message_smartsmtp.
 *
 * @package    message_smartsmtp
 * @copyright  2026 Raxelion Software Strategies <contacto@raxelion.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade steps for message_smartsmtp.
 *
 * @param int $oldversion Previous plugin version.
 * @return bool
 * @package message_smartsmtp
 */
function xmldb_message_smartsmtp_upgrade($oldversion): bool {
    global $DB;

    if ($oldversion < 2026050400) {
        $DB->set_field('message_processors', 'available', 1, ['name' => 'smartsmtp']);

        $providers = $DB->get_records('message_providers');
        foreach ($providers as $provider) {
            $base = 'smartsmtp_provider_' . $provider->component . '_' . $provider->name;
            foreach (['_locked' => '0', '_enabled' => '1'] as $suffix => $default) {
                $key = $base . $suffix;
                if (get_config('message', $key) === false) {
                    set_config($key, $default, 'message');
                }
            }
        }

        $messageconfig = get_config('message');
        foreach ($messageconfig as $key => $value) {
            if (str_ends_with($key, '_enabled') && is_string($value) && str_contains($value, 'email')) {
                $newvalue = str_replace('email', 'smartsmtp', $value);
                if ($newvalue !== $value) {
                    set_config($key, $newvalue, 'message');
                }
            }
        }

        upgrade_plugin_savepoint(true, 2026050400, 'message', 'smartsmtp');
    }

    return true;
}
