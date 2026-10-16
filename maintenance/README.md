# Member data retention cleanup

The main administrator can mark member-linked estimates and inquiries for a legal retention basis from the customer detail page. The administrator enters the legally relevant start date; contract/payment records are retained for five years and consumer complaint/dispute records for three years.

When a member withdraws, account identifiers are replaced immediately. Unmarked member-linked estimates and inquiries, account recovery requests, estimate activity tracking, and customer archive snapshots are deleted. Marked records remain linked to an anonymous withdrawn-member record until their calculated retention date.

Schedule `purge-expired-member-data.php` to run daily using PHP CLI. For Windows Task Scheduler, use the PHP executable as the program and the full path to this script as the argument. For a Unix-like host, run `php /path/to/maintenance/purge-expired-member-data.php` from cron. The script refuses web requests.

The operator must select a retention basis only when the record actually qualifies, and enter the applicable legal start date. This implementation does not determine legal eligibility automatically.