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
 * SmartSMTP Message Output — message_output_smartsmtp.php
 *
 * Intercepts messages from Moodle's notification system and routes them
 * to the correct SMTP mailbox using the rules from local/smartsmtp.
 *
 * @copyright  2026 Raxelion Software Strategies <contacto@raxelion.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @package message_smartsmtp
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/message/output/lib.php');

/**
 * Message output processor that routes notifications through SmartSMTP accounts.
 */
class message_output_smartsmtp extends message_output {
    /** @var bool|null $pluginavailable Cached availability flag for local_smartsmtp. */
    private static ?bool $pluginavailable = null;

        /**
         * Sends a notification using the SmartSMTP account selected by routing rules.
         */
    public function send_message($message): bool {
        global $CFG;

        if (!$this->is_local_plugin_available()) {
            debugging('SmartSMTP: local/smartsmtp not available.', DEBUG_DEVELOPER);
            return false;
        }

        $this->require_local_classes();

        $account = null;
        $touser  = null;

        try {
            $touser = $this->resolve_touser($message);
            if (!$touser) {
                debugging('SmartSMTP: recipient not found.', DEBUG_DEVELOPER);
                return false;
            }

            $manager = new \local_smartsmtp\smtp_manager();
            $account = $manager->select_account_for($message);

            if (!$account) {
                debugging(
                    'SmartSMTP: no mailbox available for ' .
                    ($message->component ?? 'unknown') . '/' . ($message->name ?? 'unknown'),
                    DEBUG_DEVELOPER
                );
                return false;
            }

            $original = $this->backup_smtp_config();
            $this->apply_smtp_config($account);

            $fromuser = $this->resolve_fromuser_for_account($message, $account);

            $result = email_to_user(
                $touser,
                $fromuser,
                $message->subject ?? '',
                $message->fullmessage ?? '',
                $message->fullmessagehtml ?? ''
            );

            $this->restore_smtp_config($original);

            mtrace('SmartSMTP: ' . ($result ? 'SENT' : 'FAILED') .
                ' | mailbox: ' . $account->name .
                ' | account: ' . $account->username .
                ' | to: ' . $touser->email .
                ' | subject: ' . ($message->subject ?? ''));

            \local_smartsmtp\smtp_logger::queue(
                $account->id,
                $touser->email,
                $message->subject ?? '',
                $result ? 'sent' : 'failed',
                $result ? null : 'email_to_user() returned false'
            );

            if (!defined('MESSAGE_SMARTSMTP_FLUSH_REGISTERED')) {
                define('MESSAGE_SMARTSMTP_FLUSH_REGISTERED', true);
                register_shutdown_function(['\local_smartsmtp\smtp_logger', 'flush_pending']);
            }

            return (bool)$result;
        } catch (\Throwable $e) {
            if (isset($original)) {
                $this->restore_smtp_config($original);
            }

            debugging('SmartSMTP error: ' . $e->getMessage(), DEBUG_DEVELOPER);

            if ($account && $touser) {
                \local_smartsmtp\smtp_logger::queue(
                    $account->id,
                    $touser->email ?? '',
                    $message->subject ?? '',
                    'failed',
                    $e->getMessage()
                );
            }

            return false;
        }
    }

        /**
         * Returns the default messaging settings for this processor.
         */
    public function get_default_messaging_settings(): int {
        return MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED;
    }

        /**
         * Returns true as this output supports all users.
         */
    public function is_user_capable($userid): bool {
        return true;
    }

        /**
         * Resolves the recipient user object from the message.
         */
    private function resolve_touser(object $message): ?object {
        global $DB;

        if (
            !empty($message->userto) && is_object($message->userto)
            && !empty($message->userto->id)
        ) {
            if (empty($message->userto->deleted)) {
                return $message->userto;
            }
            $user = $DB->get_record(
                'user',
                ['id' => (int)$message->userto->id, 'deleted' => 0],
                '*',
                IGNORE_MISSING
            );
            if ($user) {
                return $user;
            }
        }

        if (!empty($message->useridto)) {
            $user = $DB->get_record(
                'user',
                ['id' => (int)$message->useridto, 'deleted' => 0],
                '*',
                IGNORE_MISSING
            );
            if ($user) {
                return $user;
            }
        }

        if (!empty($message->touser) && is_object($message->touser)) {
            return $message->touser;
        }

        return null;
    }

        /**
         * Resolves the sender user object adjusted to the selected account.
         */
    private function resolve_fromuser_for_account(
        object $message,
        \local_smartsmtp\smtp_account $account
    ): object {
        global $DB;

        $fromuser = null;
        if (
            !empty($message->userfrom) && is_object($message->userfrom)
            && !empty($message->userfrom->id)
        ) {
            $fromuser = $message->userfrom;
        } else if (!empty($message->useridfrom) && (int)$message->useridfrom > 0) {
            $fromuser = $DB->get_record(
                'user',
                ['id' => (int)$message->useridfrom, 'deleted' => 0],
                '*',
                IGNORE_MISSING
            ) ?: null;
        }

        if (!$fromuser) {
            $fromuser = \core_user::get_noreply_user();
        }

        $fromuser = clone $fromuser;
        $fromuser->email = !empty($account->noreplyemail)
            ? $account->noreplyemail
            : $account->username;
        $fromuser->maildisplay = 0;

        return $fromuser;
    }

        /**
         * Returns the sender user object.
         *
         * @deprecated Use resolve_fromuser_for_account() instead.
         */
    private function resolve_fromuser(object $message): object {
        global $DB;

        if (!empty($message->useridfrom) && (int)$message->useridfrom > 0) {
            $from = $DB->get_record(
                'user',
                ['id' => (int)$message->useridfrom, 'deleted' => 0],
                '*',
                IGNORE_MISSING
            );
            if ($from) {
                return $from;
            }
        }

        return \core_user::get_noreply_user();
    }

        /**
         * Backs up the current SMTP configuration values.
         */
    private function backup_smtp_config(): array {
        global $CFG;
        return [
            'smtphosts'                 => $CFG->smtphosts ?? '',
            'smtpuser'                  => $CFG->smtpuser ?? '',
            'smtppass'                  => $CFG->smtppass ?? '',
            'smtpsecure'                => $CFG->smtpsecure ?? '',
            'noreplyaddress'            => $CFG->noreplyaddress ?? '',
            'smartsmtp_current_account' => $CFG->smartsmtp_current_account ?? null,
        ];
    }

        /**
         * Applies the selected account SMTP settings to $CFG.
         */
    private function apply_smtp_config(\local_smartsmtp\smtp_account $account): void {
        global $CFG;
        $CFG->smtphosts                 = $account->host . ':' . $account->port;
        $CFG->smtpuser                  = $account->username;
        $CFG->smtppass                  = $account->get_decrypted_password();
        $CFG->smtpsecure                = $account->security;
        $CFG->smartsmtp_current_account = $account->id;

        $CFG->noreplyaddress = !empty($account->noreplyemail)
            ? $account->noreplyemail
            : $account->username;
    }

        /**
         * Restores the SMTP configuration backed up before sending.
         */
    private function restore_smtp_config(array $original): void {
        global $CFG;
        $CFG->smtphosts                 = $original['smtphosts'];
        $CFG->smtpuser                  = $original['smtpuser'];
        $CFG->smtppass                  = $original['smtppass'];
        $CFG->smtpsecure                = $original['smtpsecure'];
        $CFG->noreplyaddress            = $original['noreplyaddress'];
        $CFG->smartsmtp_current_account = $original['smartsmtp_current_account'];
    }

        /**
         * Returns true if local_smartsmtp is installed and available.
         */
    private function is_local_plugin_available(): bool {
        if (self::$pluginavailable !== null) {
            return self::$pluginavailable;
        }

        global $CFG;
        $managerpath = $CFG->dirroot . '/local/smartsmtp/classes/smtp_manager.php';
        self::$pluginavailable = file_exists($managerpath);
        return self::$pluginavailable;
    }

        /**
         * Loads the local_smartsmtp class files explicitly.
         */
    private function require_local_classes(): void {
        global $CFG;
        $base = $CFG->dirroot . '/local/smartsmtp/';

        $files = [
            'classes/license.php',
            'classes/smtp_account.php',
            'classes/smtp_logger.php',
            'classes/smtp_selector.php',
            'classes/smtp_manager.php',
        ];

        foreach ($files as $relative) {
            $path = $base . $relative;
            if (file_exists($path)) {
                require_once($path);
            }
        }

        $pro = $base . 'classes/premium/smtp_selector_pro.php';
        if (file_exists($pro)) {
            require_once($pro);
        }
    }

        /**
         * Loads processor preferences for a user.
         */
    public function load_data(&$preferences, $userid): void {
    }

        /**
         * Returns the HTML configuration form for this processor.
         */
    public function config_form($preferences): string {
        return '';
    }

        /**
         * Processes the configuration form submission.
         */
    public function process_form($form, &$preferences): void {
    }
}
