<?php
/**
 * Payzum — Crypto & Stablecoin external payment plugin for ClientExec.
 *
 * getExternalLink() creates a Payzum hosted-checkout invoice via the official payzum/payzum-php
 * SDK (vendored under vendor/) and returns the redirect URL; ipn() verifies the signed IPN
 * through the SDK's Verifier (raw body, fixed header, constant time, replay window) and credits
 * the invoice. Non-custodial — funds settle to the merchant's own wallet.
 *
 * order_id is the bare numeric invoice number on purpose: ClientExec casts the callback
 * reference to int, so anything with a suffix becomes 0 and the payment never settles.
 *
 * Install: copy the `payzum/` folder to /plugins/payment/ and enable it under
 * Settings → Payment Gateways.
 *
 * NOTE: ClientExec's payment-plugin API is proprietary; the settings map (getVariables) follows
 * the standard pattern, but confirm the external-redirect hook and invoice-credit call
 * (updateInvoice/addTransaction) against your ClientExec plugin SDK before release.
 *
 * That uncertainty is handled, not papered over: if the credit call is not available, ipn() logs
 * an error, answers 5xx (so Payzum retries) and reports that it credited nothing. It never
 * reports success for a payment it could not book — which is what the previous version did, on
 * every IPN, without a single log line.
 */

require_once 'modules/admin/models/Plugin.php';
require_once __DIR__ . '/vendor/autoload.php';

use Payzum\Errors\PayzumException;
use Payzum\Errors\SignatureException;
use Payzum\PaymentStatus;
use Payzum\Payzum as PayzumSdk;
use Payzum\Webhooks\Verifier;

class PluginPayzum extends Plugin
{
	public $features = array(
		'TenderType'      => 'CreditCard',
		'externalPayment' => true,
	);

	// There is deliberately no signature-header variable: the SDK's Verifier owns the fixed
	// header (x-nowpayments-sig) and reads it itself. A setting is an invitation to fill it
	// with the wrong value, which is exactly how earlier releases broke.
	//
	// The two secrets are declared 'password' so the settings page does not print them in clear
	// on every visit. ClientExec's variable-type vocabulary is proprietary and unverified here
	// (no licence): a build that does not know the type falls back to a plain input, which is
	// today's behaviour — see the note in README.md.
	//
	// Currency IS declared, unlike before. getExternalLink() reads it, and a variable that is
	// never declared reads back as '' — which is how every invoice used to be created in USD no
	// matter what the client was billed in.
	public function getVariables()
	{
		return array(
			lang('Plugin Name')    => array('type' => 'hidden', 'description' => '', 'value' => 'Payzum (Crypto & Stablecoins)'),
			lang('API Key')        => array('type' => 'password', 'description' => lang('Your Payzum API key (64-hex).'), 'value' => ''),
			lang('Webhook Secret') => array('type' => 'password', 'description' => lang('IPN signing secret (verifies HMAC-SHA-512).'), 'value' => ''),
			lang('Environment')    => array('type' => 'text', 'description' => lang('production (merchant.payzum.com) or staging (staging.payzum.com — separate API keys).'), 'value' => 'production'),
			lang('IPN URL')        => array('type' => 'text', 'description' => lang('Set this as the webhook URL in your Payzum dashboard.'), 'value' => ''),
			lang('Currency')       => array('type' => 'text', 'description' => lang('Fallback ISO currency code to invoice in when the installation does not define CURRENCY_CODE (e.g. EUR). No default: a wrong guess bills the client in the wrong money.'), 'value' => ''),
			lang('Payment Type')   => array('type' => 'dropdown', 'description' => lang('Choose the payment type.'), 'value' => 'CreditCard'),
		);
	}

	/**
	 * Build the redirect to the Payzum hosted checkout for a single (offsite) payment.
	 *
	 * @param object $userPackage
	 * @param float|string $amount
	 * @param string $invoiceNumber
	 * @return string redirect URL (empty on failure)
	 */
	public function getExternalLink($userPackage, $amount, $invoiceNumber)
	{
		// The amount travels as a string end to end: the SDK writes it into the JSON as an
		// exact number. Casting to float would round it on the way out.
		$amountString = is_string($amount) ? trim($amount) : sprintf('%.4F', (float) $amount);

		// The currency of the money being asked for, never a guess.
		//
		// This used to fall back to the literal 'usd' when it could not work the currency out —
		// and it never could, because the Currency variable it read was not among the declared
		// ones and therefore always came back ''. A EUR invoice was charged in USD, silently, on
		// every install. CURRENCY_CODE (ClientExec's configured currency) first, the declared
		// setting second, and if neither is there the payment does not start: a client billed in
		// the wrong currency is worse than a checkout that says it is unavailable.
		$configured = (defined('CURRENCY_CODE') && '' !== (string) CURRENCY_CODE)
			? (string) CURRENCY_CODE
			: (string) $this->getVariable(lang('Currency'));
		$currency   = strtolower(trim($configured));
		if ('' === $currency) {
			$this->logCall('payments.create', 'no invoice currency available: CURRENCY_CODE is undefined and the Currency setting is empty — refusing to invoice in a guessed currency');
			return '';
		}

		try {
			$invoice = $this->payzumClient()->payments->create(
				priceAmount:      $amountString,
				priceCurrency:    $currency,
				// 'all' defers the coin choice to the buyer on the Payzum hosted checkout,
				// limited to the merchant's allowlist. The plugin never picks a coin.
				payCurrency:      'all',
				orderId:          (string) $invoiceNumber,
				orderDescription: 'ClientExec invoice ' . (string) $invoiceNumber,
				ipnCallbackUrl:   rtrim((string) $this->getVariable(lang('IPN URL')), '/'),
				// ClientExec offers no per-invoice storage to reuse an open invoice from, so the
				// duplicate guard on a refresh is the API's Idempotency-Key: same invoice, same
				// amount, same hour -> the same 201 is replayed instead of minting a twin. The
				// hour bucket caps how long a replay can pin the buyer to an invoice that has
				// meanwhile expired.
				idempotencyKey:   'clientexec-' . (string) $invoiceNumber . '-' . substr(hash('sha256', $amountString . '|' . $currency . '|' . date('Y-m-d-H')), 0, 32),
			);
		} catch (PayzumException $e) {
			$this->logCall('payments.create', $e->getMessage());
			return '';
		}

		if (empty($invoice['invoice_url'])) {
			$this->logCall('payments.create', 'no invoice_url in response');
			return '';
		}

		// Record what this invoice was raised for. The IPN has no other way to tell whether the
		// amount it reports is the amount the client was asked for: ClientExec gives ipn() no
		// invoice context, so without this the plugin would be crediting on trust.
		$this->rememberInvoice((int) $invoiceNumber, $amountString, $currency);

		return (string) $invoice['invoice_url'];
	}

	/**
	 * Handle the signed IPN. Returns the invoice id credited, or 0.
	 *
	 * The SDK's Verifier reads the correct, fixed signature header itself (case-insensitively,
	 * CGI form included), verifies HMAC-SHA-512 over the RAW bytes in constant time, and enforces
	 * the 10-minute replay window on the signed event_at.
	 *
	 * Only `finished` credits the invoice (it also covers overpayment). Everything else — the
	 * open states, and the three non-invoice event families whose payloads carry no known
	 * payment_status — is logged and left alone, never guessed at.
	 */
	public function ipn()
	{
		$raw = file_get_contents('php://input');
		if ($raw === '' || $raw === false) {
			return 0;
		}

		$secret = (string) $this->getVariable(lang('Webhook Secret'));
		if ('' === $secret) {
			$this->logCall('ipn.verify', 'no webhook secret configured');
			return 0;
		}

		$headers = function_exists('getallheaders') ? getallheaders() : false;
		if (!is_array($headers)) {
			$headers = array_filter($_SERVER, 'is_string');
		}

		try {
			$verifier = new Verifier($secret);
			$data     = $verifier->verifyPaymentIpn($raw, $headers);
		} catch (SignatureException $e) {
			$this->logCall('ipn.verify', $e->reason);
			return 0;
		} catch (PayzumException $e) {
			$this->logCall('ipn.verify', $e->getMessage());
			return 0;
		}

		// ClientExec casts the reference to int — the bare numeric invoice number is the only
		// shape that round-trips.
		$invoiceId = isset($data['order_id']) ? (int) $data['order_id'] : 0;
		$pzid      = $data['payment_id'] ?? ($data['id'] ?? '');
		$amount    = isset($data['price_amount']) ? (float) $data['price_amount'] : 0.0;

		if ($invoiceId <= 0) {
			$this->logCall('ipn.reference', 'order_id is not a usable invoice id: ' . (string) ($data['order_id'] ?? ''));
			return 0;
		}

		// The SDK's PaymentStatus models the five values the contract promises and throws on
		// anything else. An unknown value is a contract change: log it and leave the invoice
		// alone — never credit on a guess.
		try {
			$status = PaymentStatus::fromMerchant((string) ($data['payment_status'] ?? ''));
		} catch (PayzumException $e) {
			$this->logCall('ipn.status', $e->getMessage());
			return 0;
		}

		if ($status->isPaid()) {
			$paymentKey = (string) $pzid;

			// Deduplicate here rather than trusting the platform to do it. A payment id we have
			// already credited is a delivery retry, and a retry must credit nothing — returning 0
			// says exactly that: this delivery settled nothing new.
			if ('' !== $paymentKey && $this->alreadyCredited($paymentKey)) {
				$this->logCall('ipn.duplicate', 'payment ' . $paymentKey . ' already credited — ignored');
				return 0;
			}

			// `finished` only says the Payzum invoice settled — it says nothing about that
			// invoice having been raised for THIS invoice's total. Crediting on the status alone
			// marks an invoice paid off a settlement for less, or in a cheaper currency. Not
			// transient: a retry carries the same numbers, so this is logged and acknowledged.
			$mismatch = $this->settlementMismatch($invoiceId, $data);
			if (null !== $mismatch) {
				$this->logCall('ipn.amount', 'invoice ' . $invoiceId . ' NOT credited — ' . $mismatch);
				return 0;
			}

			// Credit the invoice via the ClientExec billing API.
			//
			// This used to be wrapped in `if (method_exists($this, 'addTransaction'))` while
			// returning the invoice id unconditionally: on any build without that method every
			// paid IPN was a silent no-op that reported success. The buyer's money arrived, the
			// invoice was never credited, and nothing was written anywhere. A missing credit path
			// is a broken install, so it fails loudly instead — 500 plus a log line, which is
			// also what makes Payzum retry the delivery instead of dropping it.
			if (!method_exists($this, 'addTransaction')) {
				return $this->failCredit($invoiceId, 'addTransaction() is not available on this ClientExec build — the invoice was NOT credited');
			}

			try {
				$this->addTransaction($invoiceId, $amount, $paymentKey);
			} catch (\Throwable $e) {
				return $this->failCredit($invoiceId, 'addTransaction() failed: ' . $e->getMessage());
			}

			if ('' !== $paymentKey) {
				$this->markCredited($paymentKey);
			}

			return $invoiceId;
		}

		$this->logCall('ipn.status', 'invoice ' . $invoiceId . ' status ' . $status->value);
		return 0;
	}

	/* --------------------------------------------------------------------------- internals */

	/**
	 * The SDK entry point, pointed at the configured environment. Built per call: the operator
	 * can save a new key or switch environment and the next request must honour it. Anything
	 * that is not exactly "staging" means production.
	 */
	private function payzumClient()
	{
		$apiKey = trim((string) $this->getVariable(lang('API Key')));

		return ('staging' === trim((string) $this->getVariable(lang('Environment'))))
			? PayzumSdk::sandbox($apiKey)
			: new PayzumSdk($apiKey);
	}

	/**
	 * A credit that could not be made: log it as an error, answer 5xx so the delivery is retried
	 * rather than dropped, and report "nothing credited" to the caller.
	 *
	 * The one thing this must never do is look like success. A payment that arrived and was not
	 * booked has to be visible somewhere, and the only places available are the platform log and
	 * the HTTP status Payzum sees.
	 *
	 * @return int always 0
	 */
	private function failCredit($invoiceId, $reason)
	{
		$this->logCall('ipn.credit', 'ERROR: invoice ' . $invoiceId . ' — ' . $reason);
		if (!headers_sent()) {
			http_response_code(500);
		}
		return 0;
	}

	/**
	 * How the settled amount/currency differ from what the invoice was raised for, as a human
	 * phrase, or null when they match (or cannot be checked).
	 *
	 * Without a recorded expectation (an invoice created before this store existed, or a temp
	 * directory that was cleaned) there is nothing to compare against: unverifiable, not wrong.
	 * Refusing the payment there would lose money that really did arrive, so it is logged and let
	 * through.
	 *
	 * Half a cent of tolerance and never `==` on floats: the stored amount is a decimal string
	 * and price_amount arrives as a JSON number, so the two round differently. Only a SHORTFALL
	 * counts — an overpayment still pays the invoice.
	 *
	 * An amount that cannot be read as a number is NOT a pass. price_amount is `required` in the
	 * contract, so null, "", an array or an object means either a contract break or a forged
	 * payload — and in both cases the one number that proves the invoice was raised for this
	 * total is missing. Skipping the comparison there would settle the invoice unverified, which
	 * is exactly how an attacker turns a 1-cent invoice into a paid one. Unverifiable is treated
	 * as mismatched.
	 *
	 * @param int   $invoiceId
	 * @param array $data verified IPN payload
	 * @return string|null
	 */
	private function settlementMismatch($invoiceId, array $data)
	{
		$expected = $this->recallInvoice($invoiceId);
		if (array() === $expected) {
			$this->logCall('ipn.verify_amount', 'no recorded amount for invoice ' . $invoiceId . ' — settlement could not be verified');
			return null;
		}

		$hasAmount    = isset($data['price_amount']) && is_numeric($data['price_amount']);
		$paidAmount   = $hasAmount ? (float) $data['price_amount'] : 0.0;
		$paidCurrency = isset($data['price_currency']) ? strtolower(trim((string) $data['price_currency'])) : '';

		$expectedAmount   = (float) $expected['amount'];
		$expectedCurrency = strtolower((string) $expected['currency']);

		if (!$hasAmount) {
			$this->logCall('ipn.verify_amount', 'no usable price_amount for invoice ' . $invoiceId . ' — settlement could not be verified, not crediting');
			return sprintf(
				'it reported no readable settled amount, so it cannot be shown to cover the %s %s the invoice was raised for',
				number_format($expectedAmount, 2, '.', ''),
				strtoupper($expectedCurrency)
			);
		}

		if (($expectedAmount - $paidAmount) > 0.005) {
			return sprintf(
				'it settled %s %s while the invoice was raised for %s %s',
				number_format($paidAmount, 2, '.', ''),
				strtoupper('' !== $paidCurrency ? $paidCurrency : $expectedCurrency),
				number_format($expectedAmount, 2, '.', ''),
				strtoupper($expectedCurrency)
			);
		}

		// Case-insensitive: the API echoes the currency in whatever case it stored it.
		if ('' !== $paidCurrency && '' !== $expectedCurrency && $paidCurrency !== $expectedCurrency) {
			return sprintf(
				'it settled in %s while the invoice was raised in %s',
				strtoupper($paidCurrency),
				strtoupper($expectedCurrency)
			);
		}

		return null;
	}

	/**
	 * Where the plugin's own little ledger lives: what each invoice was raised for, and which
	 * Payzum payment ids have already been credited.
	 *
	 * Deliberately NOT under the plugin directory: that sits inside ClientExec's docroot, and a
	 * JSON file with invoice totals does not belong where a web server can serve it. The system
	 * temp directory is the one place a payment plugin can be sure it may write; it outlives the
	 * minutes a delivery-retry storm lasts, which is all the dedup window has to cover.
	 */
	private function ledgerPath()
	{
		// Per-install name, and never follow a symlink into it.
		//
		// On shared hosting /tmp belongs to everyone: a neighbour who creates this path first
		// — as a symlink to a directory of their own, or with the file itself symlinked to
		// something the webserver can write — reads every invoice total, overwrites arbitrary
		// files, or seeds the credited map so real payments are never credited again. The name
		// is derived from the install path so it differs per tenant, and anything that is not
		// a plain directory owned by this process is refused (the caller logs the degradation).
		$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'payzum-clientexec-' . substr(hash('sha256', __DIR__), 0, 16);
		if (is_link($dir)) {
			return '';
		}
		if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
			return '';
		}
		if (function_exists('getmyuid') && fileowner($dir) !== getmyuid()) {
			return '';
		}
		$file = $dir . DIRECTORY_SEPARATOR . 'ledger.json';
		if (is_link($file)) {
			return '';
		}
		return $file;
	}

	/** @return array{invoices: array<string, array>, credited: array<string, int>} */
	private function readLedger()
	{
		$path = $this->ledgerPath();
		$raw  = ('' !== $path && is_file($path)) ? @file_get_contents($path) : '';
		$data = ('' === $raw || false === $raw) ? null : json_decode($raw, true);

		return array(
			'invoices' => isset($data['invoices']) && is_array($data['invoices']) ? $data['invoices'] : array(),
			'credited' => isset($data['credited']) && is_array($data['credited']) ? $data['credited'] : array(),
		);
	}

	private function writeLedger(array $ledger)
	{
		$path = $this->ledgerPath();
		if ('' === $path) {
			$this->logCall('ledger.write', 'no writable temp directory — duplicate and amount checks are degraded');
			return;
		}
		// Keep both maps bounded: this is a rolling window, not an archive.
		if (count($ledger['invoices']) > 500) {
			$ledger['invoices'] = array_slice($ledger['invoices'], -500, null, true);
		}
		if (count($ledger['credited']) > 500) {
			$ledger['credited'] = array_slice($ledger['credited'], -500, null, true);
		}
		@file_put_contents($path, json_encode($ledger), LOCK_EX);
		@chmod($path, 0600);
	}

	/** Record what an invoice was raised for, so the IPN can check the settlement against it. */
	private function rememberInvoice($invoiceId, $amount, $currency)
	{
		if ($invoiceId <= 0) {
			return;
		}
		$ledger = $this->readLedger();
		$ledger['invoices'][(string) $invoiceId] = array(
			'amount'   => (string) $amount,
			'currency' => (string) $currency,
			'at'       => time(),
		);
		$this->writeLedger($ledger);
	}

	/** @return array{amount: string, currency: string}|array{} */
	private function recallInvoice($invoiceId)
	{
		$ledger = $this->readLedger();
		$row    = $ledger['invoices'][(string) $invoiceId] ?? null;

		return (is_array($row) && isset($row['amount'])) ? $row : array();
	}

	/** Whether this Payzum payment id was already credited by an earlier delivery. */
	private function alreadyCredited($paymentId)
	{
		$ledger = $this->readLedger();
		return isset($ledger['credited'][$paymentId]);
	}

	/** Mark a Payzum payment id as credited, so a delivery retry becomes a no-op. */
	private function markCredited($paymentId)
	{
		$ledger = $this->readLedger();
		$ledger['credited'][$paymentId] = time();
		$this->writeLedger($ledger);
	}

	/** Plugin log entry via CE_Lib when the platform provides it (the harness does not). */
	private function logCall($action, $detail)
	{
		if (class_exists('CE_Lib') && method_exists('CE_Lib', 'log')) {
			CE_Lib::log(4, 'Payzum ' . $action . ': ' . $detail);
		}
	}
}
