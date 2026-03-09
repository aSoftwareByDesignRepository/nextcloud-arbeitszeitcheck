<?php
/**
 * Reusable form error display component
 * 
 * Displays helpful error messages with explanations and examples
 * 
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

/** @var array $_ */
/** @var \OCP\IL10N $l */

// Get error data
$fieldName = $fieldName ?? '';
$errorMessage = $errorMessage ?? '';
$errorId = $fieldName ? "{$fieldName}-error" : "error-" . uniqid();

// Ensure we have a valid error message
if (empty($errorMessage)) {
    return;
}

// Handle array errors (take first error if array)
if (is_array($errorMessage)) {
    $errorMessage = !empty($errorMessage) ? $errorMessage[0] : '';
}

if (empty($errorMessage)) {
    return;
}
?>

<div class="form-error-container">
    <div id="<?php p($errorId); ?>" 
         class="form-error" 
         role="alert" 
         aria-live="polite">
        <span class="form-error__icon" aria-hidden="true">⚠️</span>
        <div class="form-error__content">
            <?php 
            // Split error message into title and description if it contains a period
            $parts = explode('.', $errorMessage, 2);
            $title = trim($parts[0]);
            $description = isset($parts[1]) ? trim($parts[1]) : '';
            ?>
            <strong class="form-error__title"><?php p($title); ?></strong>
            <?php if (!empty($description)): ?>
                <p class="form-error__description"><?php p($description); ?></p>
            <?php endif; ?>
        </div>
    </div>
</div>
