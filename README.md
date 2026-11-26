# WP mail through Postmark

If you have a [Postmark](https://postmarkapp.com) account, you can use this plugin to route all WordPress emails through Postmark's servers. This can help improve email deliverability and ensure that your emails reach your users' inboxes.

Fill in a Server API token and a verified sender and it works, when enabled. The sender must be a confirmed Sender Signature, or use a domain that is verified in Postmark. Mail is sent through the default transactional message stream (`outbound`).

The Server API token is stored encrypted in the database for security, so when your database is compromised, your token is not (and people can not instantly start spamming using your account).

For this reason it is **important to note** that when you change your **NONCE_KEY** in your `wp-config.php` file, you will have to re-enter your Server API token on the settings page.

For testing you can use the token `POSTMARK_API_TEST`: Postmark accepts and validates the request, but does not deliver the email.
