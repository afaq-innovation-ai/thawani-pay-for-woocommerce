<?php
/**
 * Dev stack only: deliver WordPress mail to Mailpit (http://localhost:8026).
 */
add_action(
	'phpmailer_init',
	static function ( $mailer ) {
		$mailer->isSMTP();
		$mailer->Host     = 'mailpit';
		$mailer->Port     = 1025;
		$mailer->SMTPAuth = false;
	}
);
