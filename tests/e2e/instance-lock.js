/**
 * Advisory lease for the shared Nextcloud E2E instance.
 *
 * Multiple farm agents run `npx playwright test` against one instance; their
 * suites mutate the same app-config keys, fixture users and shared rows, and
 * a sustained concurrent writer defeats retries entirely (observed 2026-10-17:
 * sweep N wrote a unique hr_notification_recipients and re-read a sibling
 * sweep's value — twice). STATEFUL_SPECS serializes *within* one run; this
 * lease serializes *between* runs for all suites that adopt it.
 *
 * Contract (shared with .cursor/scripts/nc-e2e-instance-lock.sh):
 *   lease file  : $NC_E2E_LOCK_FILE or /tmp/nc-e2e-instance.lock.json
 *   content     : {"v":1,"pid":N,"token":"uuid","app":"...","cmd":"...","started":"iso"}
 *   acquire     : O_EXCL create; existing lease wins while holder pid lives
 *   stale       : holder pid dead -> reclaim (unlink + retry create)
 *   release     : unlink only when token matches (never delete a foreign live lease)
 *
 * Env:
 *   NC_E2E_LOCK_WAIT_SEC  max seconds to wait for the lease (default 5400 = 90m)
 *   NC_E2E_LOCK_STRICT=1  throw instead of warn-and-proceed when wait expires
 *   NC_E2E_LOCK_FILE      override the lease path (tests, alternate checkouts)
 */

import { randomUUID } from 'node:crypto'
import fs from 'node:fs'

const LOCK_PATH = process.env.NC_E2E_LOCK_FILE || '/tmp/nc-e2e-instance.lock.json'
const WAIT_SEC = Number(process.env.NC_E2E_LOCK_WAIT_SEC || 5400)
const POLL_MS = 10_000
const LOG_EVERY_MS = 60_000

function readLease() {
	try {
		const lease = JSON.parse(fs.readFileSync(LOCK_PATH, 'utf8'))
		return lease && Number.isInteger(lease.pid) && lease.token ? lease : null
	} catch {
		return null
	}
}

function pidAlive(pid) {
	try {
		process.kill(pid, 0)
		return true
	} catch {
		return false
	}
}

function writeLeaseAtomic(lease) {
	try {
		const fd = fs.openSync(LOCK_PATH, 'wx')
		fs.writeSync(fd, JSON.stringify(lease))
		fs.closeSync(fd)
		return true
	} catch (e) {
		if (e.code === 'EEXIST') {
			return false
		}
		throw e
	}
}

function removeLeaseIf(lease) {
	const current = readLease()
	if (current && current.token === lease.token) {
		try {
			fs.unlinkSync(LOCK_PATH)
		} catch { /* best-effort — stale/torn lease artifacts must never abort */ }
		return true
	}
	return false
}

function releaseToken(lease) {
	removeLeaseIf(lease)
	process.off('exit', lease._onExit)
	for (const sig of ['SIGINT', 'SIGTERM']) {
		process.off(sig, lease._onSignal)
	}
}

let activeLease = null

/**
 * Acquire the instance lease. Returns the lease (call releaseInstanceLock
 * with it in teardown) or null when the wait expired in non-strict mode.
 */
export function acquireInstanceLock() {
	if (activeLease) {
		return activeLease
	}
	const lease = {
		v: 1,
		pid: process.pid,
		token: randomUUID(),
		app: 'arbeitszeitcheck',
		cmd: process.argv.slice(1).join(' ').slice(0, 160),
		started: new Date().toISOString(),
	}
	const deadline = Date.now() + WAIT_SEC * 1000
	let lastLog = 0
	let holder = null

	for (;;) {
		if (writeLeaseAtomic(lease)) {
			// Best-effort self-cleanup on exit/signals; stale leases are also
			// reclaimed by the next acquirer via the dead-pid check.
			lease._onExit = () => removeLeaseIf(lease)
			lease._onSignal = () => {
				removeLeaseIf(lease)
				process.exit(130)
			}
			process.on('exit', lease._onExit)
			for (const sig of ['SIGINT', 'SIGTERM']) {
				process.on(sig, lease._onSignal)
			}
			activeLease = lease
			// eslint-disable-next-line no-console
			console.warn(`[e2e instance-lock] acquired (pid ${lease.pid}, ${lease.token.slice(0, 8)})`)
			return lease
		}

		holder = readLease()
		const holderAlive = holder && pidAlive(holder.pid)
		if (!holderAlive) {
			if (holder) {
				// Stale lease (holder dead): remove only if the on-disk
				// token still matches, then retry create.
				removeLeaseIf(holder)
			} else {
				// Unreadable: could be a torn write — grace a tick and only
				// delete if it is still unreadable AND the same inode (a
				// replaced file is someone else's fresh lease).
				let ino = null
				try {
					ino = fs.statSync(LOCK_PATH).ino
				} catch { /* best-effort — stale/torn lease artifacts must never abort */ }
				Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, 0, 500)
				let sameIno = false
				try {
					sameIno = fs.statSync(LOCK_PATH).ino === ino
				} catch { /* best-effort — stale/torn lease artifacts must never abort */ }
				if (!readLease() && sameIno) {
					try {
						fs.unlinkSync(LOCK_PATH)
					} catch { /* best-effort — stale/torn lease artifacts must never abort */ }
				}
			}
			continue
		}

		if (Date.now() >= deadline) {
			const msg = `[e2e instance-lock] wait ${WAIT_SEC}s expired — lease held by pid ${holder.pid} (${holder.app ?? '?'}: ${holder.cmd ?? '?'}) since ${holder.started ?? '?'}`
			if (process.env.NC_E2E_LOCK_STRICT === '1') {
				throw new Error(msg)
			}
			// eslint-disable-next-line no-console
			console.warn(`${msg}\n[e2e instance-lock] proceeding WITHOUT the lease — concurrent mutations may race`)
			return null
		}

		if (Date.now() - lastLog >= LOG_EVERY_MS) {
			lastLog = Date.now()
			// eslint-disable-next-line no-console
			console.warn(`[e2e instance-lock] waiting for shared instance — held by pid ${holder.pid} (${holder.app ?? '?'}: ${holder.cmd ?? '?'}) since ${holder.started ?? '?'}`)
		}
		Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, 0, POLL_MS)
	}
}

/** Release only when the lease on disk is still ours (token-matched). */
export function releaseInstanceLock() {
	if (activeLease) {
		releaseToken(activeLease)
		activeLease = null
		// eslint-disable-next-line no-console
		console.warn('[e2e instance-lock] released')
	}
}
