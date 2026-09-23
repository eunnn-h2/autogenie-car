const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const path = require('node:path');

function run(action, confirmResult, promptResult) {
    const calls = [];
    const handlers = {};
    class HTMLFormElement {
        constructor() {
            this.dataset = { customerName: '테스트' };
            this.fields = { action: { value: action }, delete_confirmation: { value: 'stale' } };
            this.elements = { namedItem: name => this.fields[name] };
        }
        matches(selector) { return selector === '[data-customer-action]'; }
    }
    const context = {
        HTMLFormElement,
        document: { addEventListener(type, handler) { (handlers[type] ??= []).push(handler); } },
        window: {
            confirm() { calls.push('confirm'); return confirmResult; },
            prompt() { calls.push('prompt'); return promptResult; },
            alert() { calls.push('alert'); },
        },
    };
    vm.createContext(context);
    for (const file of ['admin-delete-guard.js', 'customers.js']) {
        vm.runInContext(fs.readFileSync(path.join(__dirname, '../admin', file), 'utf8'), context);
    }
    const form = new HTMLFormElement();
    const button = { form, className: '', textContent: action === 'delete' ? '삭제' : '복원', value: '' };
    for (const handler of handlers.click) handler({ target: { closest: () => button } });
    let canceled = false;
    for (const handler of handlers.submit) handler({ target: form, preventDefault() { canceled = true; } });
    return { calls, canceled, confirmation: form.fields.delete_confirmation.value };
}
assert.deepEqual(run('delete', true, '삭제'), { calls: ['confirm', 'prompt'], canceled: false, confirmation: '삭제' });
assert.deepEqual(run('delete', false, '삭제'), { calls: ['confirm'], canceled: true, confirmation: '' });
assert.deepEqual(run('delete', true, null), { calls: ['confirm', 'prompt'], canceled: true, confirmation: '' });
assert.deepEqual(run('delete', true, 'wrong'), { calls: ['confirm', 'prompt', 'alert'], canceled: true, confirmation: '' });
assert.deepEqual(run('restore', true), { calls: ['confirm'], canceled: false, confirmation: '' });
assert.deepEqual(run('restore', false), { calls: ['confirm'], canceled: true, confirmation: '' });
console.log('Customer confirmation: two-step order, cancellation, restoration and shared guard checks passed.');
