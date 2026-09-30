import '@testing-library/jest-dom/vitest'
import { cleanup } from '@testing-library/react'
import { afterEach } from 'vitest'
import { setFixtureScope } from '../market/fixtureScope'

afterEach(() => cleanup())

// Module 18A: the suite runs on the CONTROLLED GLOBAL TEST FIXTURES by default (multi-currency / time zone / unit coverage).
// India launch-configuration tests opt in with setFixtureScope('india').
setFixtureScope('global')

// jsdom has no layout engine; pages that call these must not crash.
window.scrollTo = () => {}
Element.prototype.scrollIntoView = () => {}

// jsdom does not implement <dialog>; emulate open/close so the logout confirmation can be tested.
if (typeof HTMLDialogElement !== 'undefined' && !HTMLDialogElement.prototype.showModal) {
  HTMLDialogElement.prototype.showModal = function () { this.setAttribute('open', '') }
  HTMLDialogElement.prototype.close = function () { this.removeAttribute('open'); this.dispatchEvent(new Event('close')) }
}
