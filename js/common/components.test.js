import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

describe('ArbeitszeitCheckComponents confirmDialog', () => {
  beforeEach(async () => {
    document.body.innerHTML = `
      <header id="header"></header>
      <nav id="app-navigation"></nav>
      <div id="app-content" data-azc-html-lang="en">
        <div id="azc-live-region" role="status" aria-live="polite"></div>
        <div id="app-content-wrapper">
          <main id="azc-main-content" tabindex="-1"></main>
        </div>
      </div>`
    vi.resetModules()
    await import('./components.js')
  })

  afterEach(() => {
    document.body.innerHTML = ''
    document.body.style.overflow = ''
    if (window.ArbeitszeitCheckComponents) {
      window.ArbeitszeitCheckComponents._modalLockDepth = 0
    }
  })

  it('alertDialog shows a single dismiss button', async () => {
    const Components = window.ArbeitszeitCheckComponents
    const promise = Components.alertDialog({
      title: 'Cannot delete time entry',
      message: 'This month is finalized.',
    })

    expect(document.querySelector('.confirm-dialog__cancel')).toBeFalsy()
    const confirm = document.querySelector('.confirm-dialog__confirm')
    expect(confirm).toBeTruthy()
    confirm.click()

    await expect(promise).resolves.toBeUndefined()
  })

  it('destructive confirm without extra acknowledgement is immediately actionable', async () => {
    const Components = window.ArbeitszeitCheckComponents
    const promise = Components.confirmDialog({
      title: 'Delete organisation default?',
      message: 'This permanently removes this rule from history.',
      variant: 'destructive',
      confirmLabel: 'Delete',
    })

    const confirm = document.querySelector('.confirm-dialog__confirm')
    expect(confirm).toBeTruthy()
    expect(confirm.disabled).toBe(false)
    confirm.click()

    await expect(promise).resolves.toEqual({ confirmed: true, reason: '' })
  })

  it('confirmDialog works when referenced without explicit receiver', async () => {
    const Components = window.ArbeitszeitCheckComponents
    const detached = Components.confirmDialog
    const promise = detached({
      title: 'Delete',
      message: 'Sure?',
      variant: 'danger',
    })

    document.querySelector('.confirm-dialog__cancel')?.click()
    await expect(promise).resolves.toBe(false)
  })

  it('resolves false when cancel is clicked', async () => {
    const Components = window.ArbeitszeitCheckComponents
    const promise = Components.confirmDialog({
      title: 'Delete entry',
      message: 'This cannot be undone.',
      variant: 'danger',
    })

    const cancel = document.querySelector('.confirm-dialog__cancel')
    expect(cancel).toBeTruthy()
    cancel.click()

    await expect(promise).resolves.toBe(false)
    expect(document.getElementById('azc-main-content').hasAttribute('inert')).toBe(false)
  })

  it('resolves with confirmed payload when confirm is clicked', async () => {
    const Components = window.ArbeitszeitCheckComponents
    const promise = Components.confirmDialog({
      title: 'Mark as fixed',
      message: 'Confirm resolution.',
      variant: 'primary',
      requireReason: true,
    })

    const reason = document.querySelector('.confirm-dialog__reason')
    const confirm = document.querySelector('.confirm-dialog__confirm')
    expect(reason).toBeTruthy()
    expect(confirm).toBeTruthy()

    reason.value = 'Reviewed with employee'
    reason.dispatchEvent(new Event('input', { bubbles: true }))
    confirm.click()

    await expect(promise).resolves.toEqual({
      confirmed: true,
      reason: 'Reviewed with employee',
    })
  })

  it('shows the requested typed-confirm phrase in the label', async () => {
    const Components = window.ArbeitszeitCheckComponents
    Components.confirmDialog({
      title: 'Remove team',
      message: 'Permanent removal.',
      variant: 'danger',
      requireTypedConfirm: true,
      typedConfirmPhrase: 'REMOVE',
    })

    const label = document.querySelector('label[for$="-typed"]')
    expect(label).toBeTruthy()
    expect(label.textContent).toContain('REMOVE')
    expect(label.textContent).not.toMatch(/Type DELETE to confirm/i)

    document.querySelector('.confirm-dialog__cancel')?.click()
  })

  it('sets inert on main and nav while open (live regions stay available)', async () => {
    const Components = window.ArbeitszeitCheckComponents
    const promise = Components.confirmDialog({
      title: 'Finalize month',
      message: 'Lock this month.',
      variant: 'danger',
      requireTypedConfirm: true,
      typedConfirmPhrase: 'DELETE',
    })

    expect(document.getElementById('header').hasAttribute('inert')).toBe(true)
    expect(document.getElementById('azc-main-content').hasAttribute('inert')).toBe(true)
    expect(document.getElementById('app-navigation').hasAttribute('inert')).toBe(true)
    expect(document.getElementById('app-content').getAttribute('aria-hidden')).toBeNull()

    document.querySelector('.confirm-dialog__cancel')?.click()
    await promise
  })

  it('confirmDialog restores focus to the trigger, or main content when it detached mid-open', async () => {
    const Components = window.ArbeitszeitCheckComponents

    // Connected trigger → focus returns to it.
    const trigger = document.createElement('button')
    trigger.type = 'button'
    document.getElementById('azc-main-content').appendChild(trigger)
    trigger.focus()
    let promise = Components.confirmDialog({ title: 'T', message: 'M', variant: 'danger' })
    document.querySelector('.confirm-dialog__cancel')?.click()
    await expect(promise).resolves.toBe(false)
    expect(document.activeElement).toBe(trigger)

    // Trigger detached while the dialog is open (list re-render) → the close
    // must not strand focus on <body> nor leave it on a dead node.
    const dead = document.createElement('button')
    dead.type = 'button'
    document.getElementById('azc-main-content').appendChild(dead)
    dead.focus()
    promise = Components.confirmDialog({ title: 'T', message: 'M', variant: 'danger' })
    dead.remove()
    document.querySelector('.confirm-dialog__cancel')?.click()
    await expect(promise).resolves.toBe(false)
    expect(document.activeElement).toBe(document.getElementById('azc-main-content'))
  })

  it('openModal locks scroll, traps focus, and closeModal restores the page', async () => {
    const Components = window.ArbeitszeitCheckComponents
    const modal = Components.createModal({
      id: 'test-open-modal',
      title: 'Test modal',
      content: '<button type="button" id="test-modal-first">First</button><button type="button" id="test-modal-last">Last</button>',
      size: 'sm',
    })

    Components.openModal(modal)
    expect(document.body.style.overflow).toBe('hidden')
    expect(document.getElementById('header').hasAttribute('inert')).toBe(true)
    expect(document.getElementById('azc-main-content').hasAttribute('inert')).toBe(true)

    await new Promise((r) => setTimeout(r, 60))
    expect(modal.contains(document.activeElement)).toBe(true)

    Components.closeModal(modal)
    await new Promise((r) => setTimeout(r, 350))

    expect(document.getElementById('test-open-modal')).toBeNull()
    expect(document.body.style.overflow).toBe('')
    expect(document.getElementById('azc-main-content').hasAttribute('inert')).toBe(false)
    expect(Components._modalLockDepth).toBe(0)
  })
})
