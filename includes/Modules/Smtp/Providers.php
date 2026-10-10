<?php
namespace Lumia\Tools\Modules\Smtp;

defined( 'ABSPATH' ) || exit;

/**
 * SMTP presets for common providers.
 *
 * A preset only pre-fills host, port, encryption and, where relevant, the
 * username: everything stays editable, and sending goes through the same
 * generic SMTP relay. Sending through an HTTP API (SMTP ports blocked by the
 * host) is a separate piece of work.
 */
class Providers {

	/** Key of the "custom server" choice. */
	const CUSTOM = 'custom';

	/**
	 * @return array<string, array{label: string, host: string, port: int, encryption: string, username: string, hint: string}>
	 */
	public static function all(): array {
		return [
			'brevo'      => [
				'label'      => 'Brevo',
				'host'       => 'smtp-relay.brevo.com',
				'port'       => 587,
				'encryption' => 'tls',
				'username'   => '',
				'hint'       => __( 'SMTP login and key: Brevo › SMTP & API › SMTP tab. The SMTP key is not the API key.', 'lumia-tools' ),
			],
			'mailgun'    => [
				'label'      => 'Mailgun (US)',
				'host'       => 'smtp.mailgun.org',
				'port'       => 587,
				'encryption' => 'tls',
				'username'   => '',
				'hint'       => __( 'SMTP credentials of the sending domain: Mailgun › Sending › Domain settings › SMTP credentials.', 'lumia-tools' ),
			],
			'mailgun_eu' => [
				'label'      => __( 'Mailgun (EU)', 'lumia-tools' ),
				'host'       => 'smtp.eu.mailgun.org',
				'port'       => 587,
				'encryption' => 'tls',
				'username'   => '',
				'hint'       => __( 'For a domain created in Mailgun\'s EU region. SMTP credentials: Sending › Domain settings › SMTP credentials.', 'lumia-tools' ),
			],
			'sendgrid'   => [
				'label'      => 'SendGrid',
				'host'       => 'smtp.sendgrid.net',
				'port'       => 587,
				'encryption' => 'tls',
				'username'   => 'apikey',
				'hint'       => __( 'Username: "apikey", literally. Password: an API key with the Mail Send permission.', 'lumia-tools' ),
			],
			'postmark'   => [
				'label'      => 'Postmark',
				'host'       => 'smtp.postmarkapp.com',
				'port'       => 587,
				'encryption' => 'tls',
				'username'   => '',
				'hint'       => __( 'Username and password: the same Server API Token (Server › API Tokens).', 'lumia-tools' ),
			],
			'ses'        => [
				'label'      => 'Amazon SES',
				'host'       => 'email-smtp.eu-west-3.amazonaws.com',
				'port'       => 587,
				'encryption' => 'tls',
				'username'   => '',
				'hint'       => __( 'Replace eu-west-3 with the region of your SES account. SMTP credentials specific to SES (SMTP settings › Create SMTP credentials), different from IAM access keys.', 'lumia-tools' ),
			],
			'mailjet'    => [
				'label'      => 'Mailjet',
				'host'       => 'in-v3.mailjet.com',
				'port'       => 587,
				'encryption' => 'tls',
				'username'   => '',
				'hint'       => __( 'Username: API key; password: secret key (Account settings › API keys).', 'lumia-tools' ),
			],
			'ovh'        => [
				'label'      => __( 'OVHcloud (business email / MX Plan)', 'lumia-tools' ),
				'host'       => 'ssl0.ovh.net',
				'port'       => 465,
				'encryption' => 'ssl',
				'username'   => '',
				'hint'       => __( 'Username: the full email address; password: the mailbox password.', 'lumia-tools' ),
			],
			'hostinger'  => [
				'label'      => 'Hostinger',
				'host'       => 'smtp.hostinger.com',
				'port'       => 465,
				'encryption' => 'ssl',
				'username'   => '',
				'hint'       => __( 'Username: the full email address; password: the mailbox password. If the web host blocks port 465, use port 587 with STARTTLS.', 'lumia-tools' ),
			],
			'gmail'      => [
				'label'      => 'Gmail / Google Workspace',
				'host'       => 'smtp.gmail.com',
				'port'       => 587,
				'encryption' => 'tls',
				'username'   => '',
				'hint'       => __( 'Username: the Gmail address; password: an app password (2-step verification required), not the account password. Limit of about 500 emails per day.', 'lumia-tools' ),
			],
			'office365'  => [
				'label'      => 'Microsoft 365 / Outlook',
				'host'       => 'smtp.office365.com',
				'port'       => 587,
				'encryption' => 'tls',
				'username'   => '',
				'hint'       => __( 'SMTP authentication must be allowed for the mailbox in the Microsoft 365 admin center; it is often disabled there by default.', 'lumia-tools' ),
			],
		];
	}

	public static function is_valid( string $key ): bool {
		return self::CUSTOM === $key || isset( self::all()[ $key ] );
	}
}
