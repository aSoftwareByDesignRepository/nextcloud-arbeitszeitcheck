<?php

declare(strict_types=1);

/**
 * Shared passphrase resolution for the config-transfer occ commands:
 * --passphrase option → TRANSFER_PASSPHRASE env → interactive hidden prompt
 * (hidden only when the terminal supports it; a non-TTY run without either
 * fails with a clear message, never a silent empty passphrase).
 *
 * Synced from apps/_shared/config-transfer — do not edit app-side.
 */

namespace OCA\ArbeitszeitCheck\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

trait TransferCommandPassphrase
{
	private function resolvePassphrase(InputInterface $input, SymfonyStyle $io, bool $enforceStrength): ?string
	{
		$pass = (string)($input->getOption('passphrase') ?? '');
		if ($pass === '') {
			$env = getenv('TRANSFER_PASSPHRASE');
			$pass = is_string($env) ? $env : '';
		}
		if ($pass === '') {
			if (!$input->isInteractive()) {
				$io->error('Passphrase required: use --passphrase or TRANSFER_PASSPHRASE in non-interactive mode.');
				return null;
			}
			$q = new Question('Transfer passphrase (input hidden): ');
			$q->setHidden(true);
			$q->setHiddenFallback(false);
			$answer = $io->askQuestion($q);
			$pass = is_string($answer) ? $answer : '';
		}
		if ($pass === '') {
			$io->error('Empty passphrase.');
			return null;
		}
		if ($enforceStrength && mb_strlen($pass) < \OCA\ArbeitszeitCheck\ConfigTransfer\TransferCrypto::MIN_PASSPHRASE_LENGTH) {
			$io->error('Passphrase too short — at least ' . \OCA\ArbeitszeitCheck\ConfigTransfer\TransferCrypto::MIN_PASSPHRASE_LENGTH . ' characters required.');
			return null;
		}
		return $pass;
	}
}
