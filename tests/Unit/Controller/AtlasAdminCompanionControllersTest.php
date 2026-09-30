<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Controller;

use OCA\ArbeitszeitCheck\Controller\KioskAdminController;
use OCA\ArbeitszeitCheck\Controller\LicenseAdminController;
use OCA\ArbeitszeitCheck\Controller\OvertimePayoutController;
use OCA\ArbeitszeitCheck\Service\CSPService;
use OCA\ArbeitszeitCheck\Service\Kiosk\KioskCredentialService;
use OCA\ArbeitszeitCheck\Service\Kiosk\KioskEnrollmentService;
use OCA\ArbeitszeitCheck\Service\Kiosk\KioskErrorMessages;
use OCA\ArbeitszeitCheck\Service\Kiosk\KioskSettingsService;
use OCA\ArbeitszeitCheck\Service\Kiosk\KioskTerminalService;
use OCA\ArbeitszeitCheck\Service\LicenseEnforcementService;
use OCA\ArbeitszeitCheck\Service\LicenseService;
use OCA\ArbeitszeitCheck\Service\LocaleFormatService;
use OCA\ArbeitszeitCheck\Service\MobileSeatService;
use OCA\ArbeitszeitCheck\Service\MonthClosureService;
use OCA\ArbeitszeitCheck\Service\OvertimeBankService;
use OCA\ArbeitszeitCheck\Service\OvertimePayoutAuditService;
use OCA\ArbeitszeitCheck\Service\OvertimePayoutService;
use OCA\ArbeitszeitCheck\Service\PermissionService;
use OCA\ArbeitszeitCheck\Service\TerminalDeviceService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Thin happy/AuthZ surface coverage for kiosk/license/overtime admin controllers.
 */
class AtlasAdminCompanionControllersTest extends TestCase
{
	private function adminSession(string $uid = 'admin'): IUserSession
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		return $session;
	}

	private function l10n(): IL10N
	{
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn ($s) => $s);
		return $l;
	}

	private function locale(): LocaleFormatService
	{
		$locale = $this->createMock(LocaleFormatService::class);
		$locale->method('clientHints')->willReturn([]);
		return $locale;
	}

	private function csp(): CSPService
	{
		$csp = $this->createMock(CSPService::class);
		$csp->method('applyPolicyWithNonce')->willReturnArgument(0);
		return $csp;
	}

	private function url(): IURLGenerator
	{
		$url = $this->createMock(IURLGenerator::class);
		$url->method('linkToRoute')->willReturn('/x');
		return $url;
	}

	public function testKioskAdminSurfaces(): void
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn([]);
		$request->method('getParam')->willReturn('');
		$um = $this->createMock(IUserManager::class);
		$um->method('search')->willReturn([]);
		$um->method('searchDisplayName')->willReturn([]);
		$creds = $this->createMock(KioskCredentialService::class);
		$creds->method('listCredentials')->willReturn([]);
		$creds->method('importCsv')->willReturn(['imported' => 0]);
		$enroll = $this->createMock(KioskEnrollmentService::class);
		$enroll->method('getStatus')->willReturn(['status' => 'idle']);
		$settings = $this->createMock(KioskSettingsService::class);
		$settings->method('isKioskEnabled')->willReturn(true);
		$settings->expects($this->once())->method('setKioskEnabled')->with(false);
		$devices = $this->createMock(TerminalDeviceService::class);
		$errors = new KioskErrorMessages($this->l10n());
		$terminal = $this->createMock(KioskTerminalService::class);
		$terminal->method('listTerminals')->willReturn([]);
		$ps = $this->createMock(PermissionService::class);
		$ps->method('isAdmin')->willReturn(true);

		$c = new KioskAdminController(
			'arbeitszeitcheck',
			$request,
			$terminal,
			$creds,
			$enroll,
			$settings,
			$devices,
			$errors,
			$um,
			$ps,
			$this->adminSession(),
			$this->csp(),
			$this->url(),
			$this->locale(),
			$this->l10n(),
		);

		$kioskIndex = $c->index();
		$this->assertInstanceOf(TemplateResponse::class, $kioskIndex);
		$this->assertTrue($kioskIndex->getParams()['showAdminNav']);
		$this->assertTrue($kioskIndex->getParams()['showManagerLink']);
		$this->assertTrue($c->listCredentials()->getData()['success']);

		// credentials listing with rows exercises the enrichment loop
		$cred = new \OCA\ArbeitszeitCheck\Db\KioskCred();
		$cred->setId(3);
		$cred->setUserId('emp1');
		$cred->setType('pin');
		$cred->setLabel('front door');
		$cred->setLockedUntil(new \DateTime('2030-01-01T00:00:00Z'));
		$creds2 = $this->createMock(KioskCredentialService::class);
		$creds2->method('listCredentials')->willReturn([$cred]);
		$creds2->method('importCsv')->willReturn(['imported' => 1]);
		$settings2 = $this->createMock(KioskSettingsService::class);
		$settings2->method('isUserKioskAllowed')->willReturn(true);
		$emp = $this->createMock(IUser::class);
		$emp->method('getDisplayName')->willReturn('Emp One');
		$um2 = $this->createMock(IUserManager::class);
		$um2->method('get')->willReturn($emp);
		$c2k = new KioskAdminController(
			'arbeitszeitcheck',
			$request,
			$terminal,
			$creds2,
			$enroll,
			$settings2,
			$devices,
			$errors,
			$um2,
			$ps,
			$this->adminSession(),
			$this->csp(),
			$this->url(),
			$this->locale(),
			$this->l10n(),
		);
		$credData = $c2k->listCredentials()->getData()['data']['credentials'];
		$this->assertSame('Emp One', $credData[0]['displayName']);
		$this->assertTrue($credData[0]['hasPin']);
		$this->assertFalse($credData[0]['hasRfid']);
		$this->assertTrue($credData[0]['kioskAllowed']);
		$this->assertNotNull($credData[0]['lockedUntil']);

		// CSV import happy path + KioskException arm
		$reqProp = new \ReflectionProperty(\OCP\AppFramework\Controller::class, 'request');
		$reqProp->setAccessible(true);
		$reqProp->setValue($c2k, $this->requestWithParams(['csv' => "uid,type\nemp1,pin"]));
		$this->assertTrue($c2k->importCredentials()->getData()['success']);
		$creds2x = $this->createMock(KioskCredentialService::class);
		$creds2x->method('importCsv')->willThrowException(new \OCA\ArbeitszeitCheck\Service\Kiosk\KioskException('KIOSK_IMPORT_INVALID'));
		$c3k = new KioskAdminController(
			'arbeitszeitcheck', $request, $terminal, $creds2x, $enroll, $settings2,
			$devices, $errors, $um2, $ps, $this->adminSession(), $this->csp(),
			$this->url(), $this->locale(), $this->l10n(),
		);
		$reqProp->setValue($c3k, $this->requestWithParams(['csv' => 'x']));
		$this->assertFalse($c3k->importCredentials()->getData()['success']);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $c->createTerminal()->getStatus());

		$reqEnabled = $this->createMock(IRequest::class);
		$reqEnabled->method('getParams')->willReturn(['enabled' => false]);
		$ref = new \ReflectionClass($c);
		$prop = $ref->getProperty('request');
		$prop->setAccessible(true);
		$prop->setValue($c, $reqEnabled);
		$this->assertTrue($c->setKioskEnabled()->getData()['success']);

		$terminal->expects($this->once())->method('revoke')->with('t1');
		$this->assertTrue($c->revokeTerminal('t1')->getData()['success']);

		$reqSearch = $this->createMock(IRequest::class);
		$reqSearch->method('getParam')->willReturn('');
		$prop->setValue($c, $reqSearch);
		$search = $c->searchUsers();
		$this->assertTrue($search->getData()['success']);

		$prop->setValue($c, $this->requestWithParams(['terminalId' => 't1']));
		$this->assertTrue($c->enrollmentStatus()->getData()['success']);
		$this->assertTrue($c->importCredentials()->getData()['success']);

		$creds->method('assignRfid')->willThrowException(new \OCA\ArbeitszeitCheck\Service\Kiosk\KioskException('KIOSK_USER_NOT_FOUND'));
		$creds->method('generatePin')->willThrowException(new \OCA\ArbeitszeitCheck\Service\Kiosk\KioskException('KIOSK_USER_NOT_FOUND'));
		$creds->method('revoke')->willThrowException(new \OCA\ArbeitszeitCheck\Service\Kiosk\KioskException('KIOSK_CREDENTIAL_NOT_FOUND'));
		$enroll->method('start')->willThrowException(new \OCA\ArbeitszeitCheck\Service\Kiosk\KioskException('KIOSK_TERMINAL_NOT_FOUND'));
		$enroll->method('cancel')->willThrowException(new \OCA\ArbeitszeitCheck\Service\Kiosk\KioskException('KIOSK_TERMINAL_NOT_FOUND'));

		$prop->setValue($c, $this->requestWithParams([]));
		$this->assertInstanceOf(JSONResponse::class, $c->assignRfid());
		$this->assertInstanceOf(JSONResponse::class, $c->generatePin());
		$this->assertInstanceOf(JSONResponse::class, $c->deleteCredential(9));
		$this->assertInstanceOf(JSONResponse::class, $c->startEnrollment());
		$this->assertInstanceOf(JSONResponse::class, $c->cancelEnrollment());

		$um->method('get')->willReturn(null);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $c->setUserAllowed('missing')->getStatus());
	}

	public function testLicenseAdminSurfaces(): void
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn([]);
		$request->method('getParam')->willReturn('');
		$license = $this->createMock(LicenseService::class);
		$license->method('getLicenseSummary')->willReturn(['valid' => true]);
		$license->method('getMobileSeatLimit')->willReturn(5);
		$license->method('getTerminalDeviceLimit')->willReturn(2);
		$enforce = $this->createMock(LicenseEnforcementService::class);
		$enforce->method('clearAllCommercialState')->willReturn(['cleared' => true]);
		$seats = $this->createMock(MobileSeatService::class);
		$seats->method('listSeats')->willReturn([]);
		$seats->method('getAssignedCount')->willReturn(0);
		$seats->method('assignSeat')->willReturn(['ok' => false, 'error' => 'user_not_found']);
		$devices = $this->createMock(TerminalDeviceService::class);
		$devices->method('getActiveCount')->willReturn(0);
		$um = $this->createMock(IUserManager::class);
		$um->method('search')->willReturn([]);
		$um->method('searchDisplayName')->willReturn([]);
		$ps = $this->createMock(PermissionService::class);
		$ps->method('isAdmin')->willReturn(true);

		$c = new LicenseAdminController(
			'arbeitszeitcheck',
			$request,
			$license,
			$enforce,
			$seats,
			$devices,
			$um,
			$ps,
			$this->adminSession(),
			$this->csp(),
			$this->url(),
			$this->locale(),
			$this->l10n(),
		);

		$this->assertInstanceOf(TemplateResponse::class, $c->index());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $c->applyLicense()->getStatus());
		$this->assertTrue($c->clearLicense()->getData()['ok']);
		$this->assertFalse($c->assignSeat()->getData()['ok']);
		$this->assertTrue($c->removeSeat()->getData()['ok']);
		$this->assertTrue($c->searchUsers()->getData()['ok']);
	}

	public function testOvertimePayoutSurfacesAndAuthZ(): void
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn([]);
		$request->method('getParam')->willReturnCallback(static function (string $n, $d = null) {
			return match ($n) {
				'year' => '2026',
				'month' => '3',
				'limit' => '10',
				'offset' => '0',
				default => $d,
			};
		});
		$payout = $this->createMock(OvertimePayoutService::class);
		$payout->method('listMonthOverview')->willReturn([]);
		$payout->method('listPayoutHistoryForUser')->willReturn(['items' => [], 'total' => 0]);
		$payout->method('buildPayrollCsv')->willReturn("a,b\n");
		$audit = $this->createMock(OvertimePayoutAuditService::class);
		$audit->method('listAuditEntries')->willReturn(['items' => [], 'total' => 0]);
		$audit->method('findComplianceGaps')->willReturn([]);
		$bank = $this->createMock(OvertimeBankService::class);
		$bank->method('isEnabled')->willReturn(true);
		$bank->method('getBankMaxHours')->willReturn(40.0);
		$mcs = $this->createMock(MonthClosureService::class);
		$ps = $this->createMock(PermissionService::class);
		$ps->method('isAdmin')->willReturn(true);

		$c = new OvertimePayoutController(
			'arbeitszeitcheck',
			$request,
			$payout,
			$audit,
			$bank,
			$mcs,
			$ps,
			$this->adminSession(),
			$this->csp(),
			$this->url(),
			$this->locale(),
			$this->l10n(),
		);

		$payoutIndex = $c->index();
		$this->assertInstanceOf(TemplateResponse::class, $payoutIndex);
		$this->assertTrue($payoutIndex->getParams()['showAdminNav']);
		$this->assertTrue($payoutIndex->getParams()['showReportsLink']);
		$this->assertInstanceOf(TemplateResponse::class, $c->auditIndex());
		$this->assertTrue($c->listMonth()->getData()['success']);
		$this->assertTrue($c->listAudit()->getData()['success']);

		// error arms: InvalidArgumentException -> 400, Throwable -> 500
		$payoutErr = $this->createMock(OvertimePayoutService::class);
		$payoutErr->method('listMonthOverview')
			->willThrowException(new \InvalidArgumentException('bad month'));
		$payoutErr->method('buildPayrollCsv')
			->willThrowException(new \RuntimeException('db gone'));
		$cErr = new OvertimePayoutController(
			'arbeitszeitcheck', $request, $payoutErr, $audit, $bank, $mcs, $ps,
			$this->adminSession(), $this->csp(), $this->url(), $this->locale(), $this->l10n(),
		);
		$rMonth = $cErr->listMonth();
		$this->assertSame(Http::STATUS_BAD_REQUEST, $rMonth->getStatus());
		$this->assertSame('bad month', $rMonth->getData()['error']);
		$rCsv = $cErr->exportCsv();
		$this->assertInstanceOf(JSONResponse::class, $rCsv);
		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $rCsv->getStatus());
		$this->assertTrue($c->myHistory()->getData()['success']);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $c->processOne()->getStatus());
		$this->assertInstanceOf(\OCP\AppFramework\Http\DataDownloadResponse::class, $c->exportCsv());
		$payout->method('processBulkPayouts')->willReturn(['processed' => 0]);
		$this->assertTrue($c->processBulk()->getData()['success']);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $c->adminMonthClosurePdf()->getStatus());

		$psNo = $this->createMock(PermissionService::class);
		$psNo->method('isAdmin')->willReturn(false);
		$c2 = new OvertimePayoutController(
			'arbeitszeitcheck',
			$request,
			$payout,
			$audit,
			$bank,
			$mcs,
			$psNo,
			$this->adminSession('bob'),
			$this->csp(),
			$this->url(),
			$this->locale(),
			$this->l10n(),
		);
		$this->expectException(\OCA\ArbeitszeitCheck\Exception\NotAppAdminException::class);
		$c2->index();
	}

	private function requestWithParams(array $params): IRequest
	{
		$req = $this->createMock(IRequest::class);
		$req->method('getParams')->willReturn($params);
		$req->method('getParam')->willReturnCallback(static function (string $n, $d = null) use ($params) {
			return $params[$n] ?? $d;
		});
		return $req;
	}
}
