<?php

/**
 * Common Footer Template for ArbeitszeitCheck App
 * 
 * This template provides the footer section with links, copyright information,
 * and additional navigation options.
 */

// Ensure this file is being included within Nextcloud
if (!defined('OCP\AppFramework\App::class')) {
    die('Direct access not allowed');
}

// Get the current user and app context
$user = \OCP\Server::get(\OCP\IUserSession::class)->getUser();
$appName = 'arbeitszeitcheck';
$appVersion = \OCP\Server::get(\OCP\App\IAppManager::class)->getAppVersion($appName);
$currentYear = date('Y');
?>
<footer class="footer" role="contentinfo">
    <div class="footer__content">
        <div class="container">
            <div class="footer__grid">
                <!-- App Information -->
                <div class="footer__section">
                    <div class="footer__logo">
                        <img src="<?php print_unescaped(image_path($appName, 'app.svg')); ?>"
                            alt="<?php p($l->t('ArbeitszeitCheck')); ?>"
                            class="footer__logo-image">
                        <span class="footer__logo-text"><?php p($l->t('ArbeitszeitCheck')); ?></span>
                    </div>
                    <p class="footer__description">
                        <?php p($l->t('Professional time tracking and compliance management for German labor law.')); ?>
                        <?php p($l->t('Track work hours, manage absences, and ensure legal compliance.')); ?>
                    </p>
                    <div class="footer__version">
                        <span class="footer__version-label"><?php p($l->t('Version:')); ?></span>
                        <span class="footer__version-number"><?php p($appVersion); ?></span>
                    </div>
                </div>

                <!-- Quick Links -->
                <div class="footer__section">
                    <h3 class="footer__section-title"><?php p($l->t('Quick Links')); ?></h3>
                    <ul class="footer__link-list">
                        <li class="footer__link-item">
                            <a href="<?php print_unescaped(\OCP\Server::get(\OCP\IURLGenerator::class)->linkToRoute('arbeitszeitcheck.page.dashboard')); ?>"
                                class="footer__link">
                                <?php p($l->t('Dashboard')); ?>
                            </a>
                        </li>
                        <li class="footer__link-item">
                            <a href="<?php print_unescaped(\OCP\Server::get(\OCP\IURLGenerator::class)->linkToRoute('arbeitszeitcheck.page.timeEntries')); ?>"
                                class="footer__link">
                                <?php p($l->t('Time Entries')); ?>
                            </a>
                        </li>
                        <li class="footer__link-item">
                            <a href="<?php print_unescaped(\OCP\Server::get(\OCP\IURLGenerator::class)->linkToRoute('arbeitszeitcheck.page.absences')); ?>"
                                class="footer__link">
                                <?php p($l->t('Absences')); ?>
                            </a>
                        </li>
                        <li class="footer__link-item">
                            <a href="<?php print_unescaped(\OCP\Server::get(\OCP\IURLGenerator::class)->linkToRoute('arbeitszeitcheck.page.reports')); ?>"
                                class="footer__link">
                                <?php p($l->t('Reports')); ?>
                            </a>
                        </li>
                        <li class="footer__link-item">
                            <a href="<?php print_unescaped(\OCP\Server::get(\OCP\IURLGenerator::class)->linkToRoute('arbeitszeitcheck.page.settings')); ?>"
                                class="footer__link">
                                <?php p($l->t('Settings')); ?>
                            </a>
                        </li>
                    </ul>
                </div>

                <!-- Features -->
                <div class="footer__section">
                    <h3 class="footer__section-title"><?php p($l->t('Features')); ?></h3>
                    <ul class="footer__link-list">
                        <li class="footer__link-item">
                            <a href="<?php print_unescaped(\OCP\Server::get(\OCP\IURLGenerator::class)->linkToRoute('arbeitszeitcheck.compliance.dashboard')); ?>"
                                class="footer__link">
                                <?php p($l->t('Compliance Dashboard')); ?>
                            </a>
                        </li>
                        <li class="footer__link-item">
                            <a href="<?php print_unescaped(\OCP\Server::get(\OCP\IURLGenerator::class)->linkToRoute('arbeitszeitcheck.page.calendar')); ?>"
                                class="footer__link">
                                <?php p($l->t('Calendar View')); ?>
                            </a>
                        </li>
                        <?php if ($user && \OCP\Server::get(\OCP\IGroupManager::class)->isAdmin($user->getUID())): ?>
                            <li class="footer__link-item">
                                <a href="<?php print_unescaped(\OCP\Server::get(\OCP\IURLGenerator::class)->linkToRoute('arbeitszeitcheck.admin.dashboard')); ?>"
                                    class="footer__link">
                                    <?php p($l->t('Admin Dashboard')); ?>
                                </a>
                            </li>
                            <li class="footer__link-item">
                                <a href="<?php print_unescaped(\OCP\Server::get(\OCP\IURLGenerator::class)->linkToRoute('arbeitszeitcheck.admin.auditLog')); ?>"
                                    class="footer__link">
                                    <?php p($l->t('Audit Log')); ?>
                                </a>
                            </li>
                            <li class="footer__link-item">
                                <a href="<?php print_unescaped(\OCP\Server::get(\OCP\IURLGenerator::class)->linkToRoute('arbeitszeitcheck.admin.teams')); ?>"
                                    class="footer__link">
                                    <?php p($l->t('Organization')); ?>
                                </a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </div>

                <!-- Legal & Compliance -->
                <div class="footer__section">
                    <h3 class="footer__section-title"><?php p($l->t('Legal & Compliance')); ?></h3>
                    <ul class="footer__link-list">
                        <li class="footer__link-item">
                            <span class="footer__link footer__link--info">
                                <?php p($l->t('German Labor Law Compliant')); ?>
                            </span>
                        </li>
                        <li class="footer__link-item">
                            <span class="footer__link footer__link--info">
                                <?php p($l->t('GDPR / DSGVO Ready')); ?>
                            </span>
                        </li>
                        <li class="footer__link-item">
                            <span class="footer__link footer__link--info">
                                <?php p($l->t('Arbeitsschutzgesetz (ArbSchG)')); ?>
                            </span>
                        </li>
                        <li class="footer__link-item">
                            <span class="footer__link footer__link--info">
                                <?php p($l->t('Arbeitszeitgesetz (ArbZG)')); ?>
                            </span>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Footer Bottom -->
            <div class="footer__bottom">
                <div class="footer__bottom-content">
                    <div class="footer__copyright">
                        <p class="footer__copyright-text">
                            &copy; <?php p($currentYear); ?> <?php p($l->t('ArbeitszeitCheck')); ?>.
                            <?php p($l->t('Built for Nextcloud.')); ?>
                        </p>
                        <p class="footer__compliance-notice">
                            <?php p($l->t('Compliant with German labor law (ArbZG, ArbSchG) and GDPR (DSGVO).')); ?>
                        </p>
                    </div>

                    <div class="footer__bottom-links">
                        <a href="https://github.com/aSoftwareByDesignRepository/ArbeitszeitCheck"
                            class="footer__bottom-link"
                            target="_blank"
                            rel="noopener noreferrer">
                            <?php p($l->t('Source Code')); ?>
                        </a>
                        <span class="footer__bottom-separator">•</span>
                        <a href="https://github.com/aSoftwareByDesignRepository/ArbeitszeitCheck/blob/main/docs/Developer-Documentation.en.md"
                            class="footer__bottom-link"
                            target="_blank"
                            rel="noopener noreferrer">
                            <?php p($l->t('Documentation')); ?>
                        </a>
                        <span class="footer__bottom-separator">•</span>
                        <a href="https://nextcloud.com"
                            class="footer__bottom-link"
                            target="_blank"
                            rel="noopener noreferrer">
                            <?php p($l->t('Nextcloud')); ?>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Back to Top Button -->
    <button type="button"
        class="footer__back-to-top"
        aria-label="<?php p($l->t('Back to top')); ?>"
        title="<?php p($l->t('Back to top')); ?>">
        <span class="footer__back-to-top-icon" aria-hidden="true">↑</span>
    </button>
</footer>

<script nonce="<?php p($_['cspNonce'] ?? '') ?>">
    // Footer functionality
    document.addEventListener('DOMContentLoaded', function() {
        const backToTopBtn = document.querySelector('.footer__back-to-top');

        if (backToTopBtn) {
            // Show/hide back to top button based on scroll position
            window.addEventListener('scroll', function() {
                if (window.pageYOffset > 300) {
                    backToTopBtn.classList.add('footer__back-to-top--visible');
                } else {
                    backToTopBtn.classList.remove('footer__back-to-top--visible');
                }
            });

            // Smooth scroll to top when back to top button is clicked
            backToTopBtn.addEventListener('click', function() {
                window.scrollTo({
                    top: 0,
                    behavior: 'smooth'
                });
            });
        }

        // Add smooth scrolling to all footer links that point to same page
        const footerLinks = document.querySelectorAll('.footer__link');
        footerLinks.forEach(link => {
            const href = link.getAttribute('href');
            if (href && href.startsWith('#')) {
                link.addEventListener('click', function(event) {
                    event.preventDefault();
                    const targetId = href.substring(1);
                    const targetElement = document.getElementById(targetId);

                    if (targetElement) {
                        targetElement.scrollIntoView({
                            behavior: 'smooth',
                            block: 'start'
                        });
                    }
                });
            }
        });
    });
</script>
