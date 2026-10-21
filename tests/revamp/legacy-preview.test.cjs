// Characterization of the OLD browser preview. This is not new/old PHP parity.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const legacy = vm.createContext({});
vm.runInContext(fs.readFileSync(path.join(__dirname, '../../assets/js/pinjaman.js'), 'utf8'), legacy);
let cases = 0;
for (const principal of [1000000, 2000000, 3000000]) {
  for (const [tenor, rate, payment] of [[1, 15, 287500], [2, 25, 156250], [3, 35, 112500]]) {
    const result = legacy.hitungPinjaman(principal, rate, tenor, 'mingguan', 'flat');
    assert.equal(result.jumlahCicilan, tenor * 4);
    assert.equal(result.angsuran, payment * principal / 1000000);
    assert.equal(result.rincian.reduce((sum, row) => sum + row.jumlah, 0), result.total);
    assert.equal(result.rincian.reduce((sum, row) => sum + row.pokok, 0), principal);
    cases++;
  }
}
const rounding = legacy.hitungPinjaman(1000000, 35, 3, 'mingguan', 'flat');
assert.equal(rounding.rincian[0].pokok, 83333);
assert.equal(rounding.rincian[11].pokok, 83337);
assert.equal(rounding.rincian[11].bunga, 29163);
cases++;
for (const [frequency, count] of [['mingguan', 24], ['dua_mingguan', 12], ['bulanan', 6]]) {
  const pawn = legacy.hitungPinjamanGadai(2000000, 15, 6, frequency);
  assert.equal(pawn.total, 2660000);
  assert.equal(pawn.jumlahCicilan, count);
  cases++;
}
assert.equal(legacy.hitungPinjamanGadai(1500000, 15, 3, 'mingguan'), null);
cases++;
const annual = legacy.hitungPinjaman(1000000, 12, 6, 'bulanan', 'tahunan');
assert.equal(annual.totalBunga, 60000);
assert.equal(annual.total, 1060000);
assert.equal(annual.angsuran, 1060000 / 6);
cases++;
console.log(`PASS: ${cases} legacy JavaScript preview cases. PHP/database/revamp parity NOT covered.`);
