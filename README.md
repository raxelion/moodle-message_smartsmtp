# SmartSMTP Message Output

This plugin is the message output handler component of SmartSMTP. It connects Moodle's messaging system to the SMTP accounts and routing rules configured by **local_smartsmtp**.

## Requirement

This plugin requires **local_smartsmtp** to be installed first. It will not function on its own, as it relies on the SMTP accounts and routing rules configured through the local_smartsmtp admin interface.

Get local_smartsmtp here: https://moodle.org/plugins/local_smartsmtp

## What it does

Once installed alongside local_smartsmtp, this plugin intercepts Moodle's outgoing notification messages (password resets, forum notifications, new user registration, and other message types) and routes them through the appropriate SMTP account based on the rules you've configured.

## Installation

1. Make sure **local_smartsmtp** is already installed and configured
2. Copy this plugin's folder into your Moodle `/message/output/` directory as `smartsmtp`
3. Log in as an administrator and visit **Site administration > Notifications** to complete the installation
4. Go to **Site administration > Plugins > Message outputs > SmartSMTP** to enable the message output
5. Configure routing rules in **Site administration > Plugins > Local plugins > SmartSMTP**

## Requirements

- Moodle 4.5 - 5.2
- local_smartsmtp installed

## Support

For questions, bug reports, or feature requests:

- Email: contacto@raxelion.com
- Website: https://raxelion.com
- Issues: https://github.com/raxelion/moodle-smartsmtp/issues

## License

This plugin is licensed under the GNU GPL v3 or later. See the LICENSE file for details.

## Maintained by

Raxelion Software Strategies
https://raxelion.com