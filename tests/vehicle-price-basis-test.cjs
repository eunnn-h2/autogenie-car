const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');

const source = fs.readFileSync(path.join(__dirname, '../site.js'), 'utf8');
const context = vm.createContext({ vehicleProductFilter: 'ALL' });
vm.runInContext(source.slice(source.indexOf('function getMinMonthlyPrice('), source.indexOf('function getVehicleFuelText(')), context);

const rent = { product_type: 'RENT', monthly_payment: 450000, contract_months: 48, prepayment_rate: 0, annual_mileage: 20000 };
const cheaperRent = { ...rent, monthly_payment: 420000, contract_months: 60 };
const lease = { ...rent, product_type: 'LEASE', monthly_payment: 390000 };
const vehicle = { trims: [{ id: 1, prices: [lease, rent] }, { id: 2, prices: [cheaperRent] }] };

// Default rent wins even when lease is cheaper and appears first.
assert.equal(context.getMinMonthlyPrice(vehicle), 420000);
assert.equal(context.getListProductLabel(vehicle), '장기렌트 기준가');
assert.equal(context.getMinMonthlyQuote(vehicle).trim.id, 2);
assert.equal(context.getMinMonthlyQuote(vehicle).price, cheaperRent);

vehicle.price_basis_product = 'LEASE';
assert.equal(context.getMinMonthlyPrice(vehicle), 390000);
assert.equal(context.getListProductLabel(vehicle), '리스 기준가');
assert.equal(context.getMinMonthlyQuote(vehicle).price, lease);

context.vehicleProductFilter = 'RENT';
assert.equal(context.getMinMonthlyQuote(vehicle).price, cheaperRent);
assert.equal(context.getListProductLabel(vehicle), '장기렌트 기준가');
context.vehicleProductFilter = 'LEASE';
vehicle.price_basis_product = 'RENT';
assert.equal(context.getMinMonthlyQuote(vehicle).price, lease);

context.vehicleProductFilter = 'ALL';
assert.equal(context.getMinMonthlyQuote({ trims: [{ id: 3, prices: [lease] }] }).price, lease);
assert.equal(context.getMinMonthlyQuote({ trims: [] }), null);
assert.equal(context.getMinMonthlyPrice({ trims: [] }), 0);
assert.equal(context.getMinMonthlyQuote({ trims: [{ prices: [0, -1, Infinity].map(monthly_payment => ({ product_type: 'RENT', monthly_payment })) }] }), null);
assert.equal(context.getMinMonthlyQuote({ trims: [{ prices: [lease] }] }, 'RENT'), null);
console.log('PASS: default/custom basis, labels, filters, fallback, trim and quote conditions');
