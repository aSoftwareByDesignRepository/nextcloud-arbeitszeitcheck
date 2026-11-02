<?php

declare(strict_types=1);

/**
 * Section sentence inside the card. The page header lead stays empty so the
 * topic row does not jump when the sentence wraps to a different height.
 *
 * @var bool $showChrome
 * @var string $settingsSectionHelp
 */

if (!empty($showChrome) || trim((string)($settingsSectionHelp ?? '')) === '') {
	return;
}
?>
<p class="settings-section__intro"><?php p((string)$settingsSectionHelp); ?></p>
