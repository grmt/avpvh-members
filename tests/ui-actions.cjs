const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const events = {};
const input = {value: '', addEventListener(name, handler) { this[name] = handler; }};
const select = {
    value: '1', options: [],
    querySelectorAll() { return this.options; },
    appendChild(option) { this.options.push(option); }
};
const members = [
    {id: 1, label: 'Fixture One', first: 'Marianna', last: 'Voorbeeld'},
    {id: 2, label: 'Fixture Two', first: null, last: 'Voorbeeld'},
    {id: 3, label: '<Fixture Three>', first: 'Fixture', last: null}
];
const form = {querySelector(selector) {
    return selector.includes('related_member_id') ? select
        : selector.includes('filter') ? input : {textContent: JSON.stringify(members)};
}};
const document = {
    addEventListener(name, handler) { events[name] = handler; },
    querySelectorAll() { return [form]; },
    createElement() { return {dataset: {}, remove() { select.options = select.options.filter(o => o !== this); }}; }
};
const prompts = [];
const navigations = [];
const window = {
    confirm(message) { prompts.push(message); return false; },
    location: {origin: 'https://fixture.example.invalid', href: 'https://fixture.example.invalid/profile/', assign(url) { navigations.push(url); }}
};
vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../assets/ui-actions.js'), 'utf8'), {document, window, URL});
for (const [term, ids] of [['riann', [1]], ['beeld', [1, 2]], ['fixture', [3]], ['', [1, 2, 3]]]) {
    input.value = term;
    input.input();
    assert.deepEqual(select.options.map(o => o.value), ids);
}
assert.equal(select.value, '1');
assert.equal(select.options[2].textContent, '<Fixture Three>');
assert.equal(select.options[2].dataset.avpvhPlain, '1');
let prevented = 0;
events.submit({target: {dataset: {avpvhConfirm: 'Fixture form'}}, preventDefault() { prevented++; }});
events.click({target: {closest() { return {dataset: {avpvhConfirm: 'Fixture button'}}; }}, preventDefault() { prevented++; }});
assert.equal(prevented, 2);
assert.deepEqual(prompts, ['Fixture form', 'Fixture button']);
events.change({target: {value: '/attendance/?member_id=1', matches() { return true; }}});
events.change({target: {value: 'https://other.example.invalid/', matches() { return true; }}});
assert.deepEqual(navigations, ['https://fixture.example.invalid/attendance/?member_id=1']);
console.log('[OK] shared form, navigation and name-filter behavior');
