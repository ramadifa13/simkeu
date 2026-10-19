<?php
require __DIR__.'/../includes/denda_helper.php';
$count=0;
function expectDenda($actual,$expected,$label) {
    global $count;if($actual!==$expected)throw new Exception($label);$count++;
}
function rejectDenda($input,$auto,$stored,$manual,$admin) {
    global $count;
    try {dendaUntukPembayaran($input,$auto,$stored,$manual,$admin);}
    catch(Exception $e){$count++;return;}
    throw new Exception('Nominal tidak sah seharusnya ditolak');
}
expectDenda(dendaUntukPembayaran('90000',90000,90000,false,false),['denda'=>90000.0,'manual'=>0],'Teller membayar ketetapan sistem');
expectDenda(dendaUntukPembayaran('',90000,50000,true,false),['denda'=>50000.0,'manual'=>1],'Teller mengikuti keputusan Admin meski form kosong');
expectDenda(dendaUntukPembayaran('0',90000,0,true,false),['denda'=>0.0,'manual'=>1],'Pemutihan Admin dihormati');
rejectDenda('0',90000,90000,false,false);
rejectDenda('100000',90000,90000,false,false);
rejectDenda('90000',90000,50000,true,false);
rejectDenda('1e999',90000,90000,false,false);
rejectDenda([],90000,90000,false,false);
expectDenda(dendaUntukPembayaran('50000',90000,90000,false,true),['denda'=>50000.0,'manual'=>1],'Admin boleh menetapkan denda');
expectDenda(dendaUntukPembayaran('',90000,50000,true,true),['denda'=>90000.0,'manual'=>0],'Admin boleh kembali ke otomatis');
rejectDenda('-1',90000,90000,false,true);
rejectDenda('1e999',90000,90000,false,true);
echo "Semua $count pengujian hak nominal denda lulus.\n";
