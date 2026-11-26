=== WP mail through Postmark ===
Contributors: ruigehond
Tags: postmark, email, transactional
Donate link: https://www.paypal.com/cgi-bin/webscr?cmd=_donations&business=hallo@ruigehond.nl&lc=US&item_name=WP+mail+through+Postmark+plugin&no_note=0&cn=&currency_code=USD&bn=PP-DonationsBF:btn_donateCC_LG.gif:NonHosted
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv3

Have WordPress mail function use Postmark for increased deliverability.

== Description ==

If you have a Postmark account, you can use this plugin to route all WordPress emails through Postmark's servers. This can help improve email deliverability and ensure that your emails reach your users' inboxes.

Fill in a Server API token and a verified sender and it works, when enabled. The sender must be a confirmed Sender Signature, or use a domain that is verified in Postmark.

The Server API token is stored encrypted in the database for security, so when your database is compromised, your token is not (and people can not instantly start spamming using your account).

