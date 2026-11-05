<?php

use App\Http\Controllers\Account\ProfileController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Portal\AuditLogController;
use App\Http\Controllers\Portal\BackupController;
use App\Http\Controllers\Portal\CashLedgerController;
use App\Http\Controllers\Portal\CashReconciliationController;
use App\Http\Controllers\Portal\CooperativeSettingsController;
use App\Http\Controllers\Portal\DashboardController;
use App\Http\Controllers\Portal\DashboardReportController;
use App\Http\Controllers\Portal\DocumentPdfController;
use App\Http\Controllers\Portal\FinancialParityController;
use App\Http\Controllers\Portal\InstallmentClosingController;
use App\Http\Controllers\Portal\LayananController;
use App\Http\Controllers\Portal\LoanApplicationController;
use App\Http\Controllers\Portal\LoanCartController;
use App\Http\Controllers\Portal\LoanCollateralController;
use App\Http\Controllers\Portal\LoanController;
use App\Http\Controllers\Portal\LoanInstallmentController;
use App\Http\Controllers\Portal\LoanSettlementController;
use App\Http\Controllers\Portal\MasterDataController;
use App\Http\Controllers\Portal\MemberController;
use App\Http\Controllers\Portal\MigrationController;
use App\Http\Controllers\Portal\ModulePlaceholderController;
use App\Http\Controllers\Portal\ReportController;
use App\Http\Controllers\Portal\SavingsController;
use App\Http\Controllers\Portal\SavingsCorrectionController;
use App\Http\Controllers\Portal\ShuController;
use App\Http\Controllers\Portal\TellerController;
use App\Http\Controllers\Portal\WaserdaController;
use App\Http\Controllers\Portal\WhatsAppReminderController;
use App\Http\Controllers\Public\BrandLogoController;
use App\Http\Controllers\Public\LandingController;
use Illuminate\Support\Facades\Route;

Route::get('/', LandingController::class)->name('landing');
Route::get('/brand/logo', BrandLogoController::class)->name('brand.logo');
Route::view('/login', 'auth.login')->name('login');
Route::post('/login', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:login')->name('login.store');
Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->middleware('auth')->name('logout');

Route::middleware('auth')->group(function (): void {
    Route::get('/akun', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/akun', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('/akun/kata-sandi', [ProfileController::class, 'updatePassword'])->name('profile.password.update');
    Route::get('/akun/foto', [ProfileController::class, 'photo'])->name('profile.photo');
});

Route::middleware(['auth', 'role'])->prefix('portal')->name('portal.')->group(function (): void {
    Route::get('/{role}/dashboard', DashboardController::class)
        ->whereIn('role', ['admin', 'teller', 'anggota'])
        ->name('dashboard');

    Route::get('/{role}/dashboard/detail/{report}', DashboardReportController::class)
        ->whereIn('role', ['admin', 'teller', 'anggota'])
        ->name('dashboard.drilldown');

    Route::view('/{role}/anggota', 'portal.members.index')
        ->whereIn('role', ['admin', 'teller'])
        ->name('members.index');

    Route::get('/{role}/anggota/tambah', [MemberController::class, 'create'])
        ->whereIn('role', ['admin', 'teller'])
        ->name('members.create');
    Route::post('/{role}/anggota', [MemberController::class, 'store'])
        ->whereIn('role', ['admin', 'teller'])
        ->name('members.store');

    Route::get('/{role}/anggota/{member}/edit', [MemberController::class, 'edit'])
        ->whereIn('role', ['admin', 'teller'])
        ->name('members.edit');
    Route::put('/{role}/anggota/{member}', [MemberController::class, 'update'])
        ->whereIn('role', ['admin', 'teller'])
        ->name('members.update');
    Route::post('/{role}/anggota/{member}/reset-password', [MemberController::class, 'resetPassword'])
        ->whereIn('role', ['admin', 'teller'])
        ->name('members.password.reset');
    Route::delete('/{role}/anggota/{member}', [MemberController::class, 'destroy'])
        ->whereIn('role', ['admin', 'teller'])
        ->name('members.destroy');
    Route::get('/{role}/anggota/{member}/dokumen/{document}', [MemberController::class, 'document'])
        ->whereIn('role', ['admin', 'teller'])
        ->name('members.documents.show');
    Route::get('/{role}/anggota/{member}/avatar', [MemberController::class, 'avatar'])
        ->whereIn('role', ['admin', 'teller'])
        ->name('members.avatar');
    Route::get('/{role}/anggota-ekspor', [MemberController::class, 'export'])
        ->whereIn('role', ['admin', 'teller'])
        ->name('members.export');

    Route::get('/{role}/anggota/{member}', [MemberController::class, 'show'])
        ->whereIn('role', ['admin', 'teller'])
        ->name('members.show');

    Route::get('/{role}/teller', [TellerController::class, 'index'])->where('role', 'admin')->name('tellers.index');
    Route::get('/{role}/teller/tambah', [TellerController::class, 'create'])->where('role', 'admin')->name('tellers.create');
    Route::post('/{role}/teller', [TellerController::class, 'store'])->where('role', 'admin')->name('tellers.store');
    Route::get('/{role}/teller/{teller}/edit', [TellerController::class, 'edit'])->where('role', 'admin')->name('tellers.edit');
    Route::put('/{role}/teller/{teller}', [TellerController::class, 'update'])->where('role', 'admin')->name('tellers.update');
    Route::post('/{role}/teller/{teller}/reset-password', [TellerController::class, 'resetPassword'])->where('role', 'admin')->name('tellers.password.reset');
    Route::delete('/{role}/teller/{teller}', [TellerController::class, 'destroy'])->where('role', 'admin')->name('tellers.destroy');

    Route::get('/{role}/simpanan-kas', [SavingsController::class, 'index'])->whereIn('role', ['admin', 'teller'])->name('savings.index');
    Route::post('/{role}/simpanan-kas/setoran', [SavingsController::class, 'deposit'])->whereIn('role', ['admin', 'teller'])->name('savings.deposit');
    Route::post('/{role}/simpanan-kas/penarikan', [SavingsController::class, 'withdraw'])->whereIn('role', ['admin', 'teller'])->name('savings.withdraw');
    Route::get('/{role}/simpanan-kas/anggota/{member}/buku', [SavingsController::class, 'memberBook'])->whereIn('role', ['admin', 'teller'])->name('savings.book');
    Route::get('/{role}/simpanan-kas/{transaction}', [SavingsController::class, 'show'])->whereIn('role', ['admin', 'teller'])->name('savings.show');
    Route::get('/{role}/simpanan-saya', [SavingsController::class, 'mySavings'])->where('role', 'anggota')->name('savings.mine');

    Route::get('/{role}/waserda', [WaserdaController::class, 'index'])->whereIn('role', ['admin', 'teller'])->name('waserda.index');
    Route::get('/{role}/waserda/saya', [WaserdaController::class, 'mine'])->where('role', 'anggota')->name('waserda.mine');
    Route::post('/{role}/waserda', [WaserdaController::class, 'store'])->whereIn('role', ['admin', 'teller'])->name('waserda.store');
    Route::get('/{role}/waserda/{waserda}', [WaserdaController::class, 'show'])->whereIn('role', ['admin', 'teller'])->name('waserda.show');
    Route::delete('/{role}/waserda/{waserda}', [WaserdaController::class, 'destroy'])->whereIn('role', ['admin', 'teller'])->name('waserda.destroy');

    Route::get('/{role}/layanan', [LayananController::class, 'index'])->whereIn('role', ['admin', 'teller'])->name('layanan.index');
    Route::post('/{role}/layanan/tunda-cair/ajukan', [LayananController::class, 'requestDelay'])->whereIn('role', ['admin', 'teller'])->name('layanan.tunda-cair.request');
    Route::post('/{role}/layanan/tunda-cair/putusan', [LayananController::class, 'decideDelay'])->where('role', 'admin')->name('layanan.tunda-cair.decide');
    Route::get('/{role}/layanan/{loan}', [LayananController::class, 'show'])->whereIn('role', ['admin', 'teller'])->whereNumber('loan')->name('layanan.show');
    Route::post('/{role}/layanan/{loan}/proses', [LayananController::class, 'process'])->whereIn('role', ['admin', 'teller'])->whereNumber('loan')->name('layanan.process');

    Route::get('/{role}/pinjaman/pengajuan', [LoanApplicationController::class, 'index'])->whereIn('role', ['admin', 'teller'])->name('loans.applications.index');
    Route::get('/{role}/pinjaman/pengajuan/{application}', [LoanApplicationController::class, 'show'])->whereIn('role', ['admin', 'teller'])->whereNumber('application')->name('loans.applications.show');
    Route::get('/{role}/pinjaman/pengajuan/tambah', [LoanApplicationController::class, 'create'])->whereIn('role', ['admin', 'teller'])->name('loans.applications.create');
    Route::post('/{role}/pinjaman/pengajuan', [LoanApplicationController::class, 'store'])->whereIn('role', ['admin', 'teller'])->name('loans.applications.store');
    Route::post('/{role}/pinjaman/pengajuan/{application}/review', [LoanApplicationController::class, 'review'])->where('role', 'admin')->name('loans.applications.review');
    Route::post('/{role}/pinjaman/pengajuan/{application}/cairkan', [LoanApplicationController::class, 'disburse'])->whereIn('role', ['admin', 'teller'])->name('loans.applications.disburse');
    Route::get('/{role}/pinjaman/pengajuan/{application}/cairkan', [LoanApplicationController::class, 'disbursementForm'])->whereIn('role', ['admin', 'teller'])->name('loans.applications.disburse.form');
    Route::post('/{role}/pinjaman/pengajuan/{application}/batalkan', [LoanApplicationController::class, 'cancel'])->where('role', 'admin')->name('loans.applications.cancel');
    Route::delete('/{role}/pinjaman/pengajuan/{application}', [LoanApplicationController::class, 'destroy'])->where('role', 'admin')->whereNumber('application')->name('loans.applications.destroy');
    Route::get('/{role}/pinjaman/denda', [LoanController::class, 'penalties'])->whereIn('role', ['admin', 'teller'])->name('loans.penalties');
    Route::get('/{role}/pinjaman/pelunasan', [LoanController::class, 'settlements'])->whereIn('role', ['admin', 'teller'])->name('loans.settlements');
    Route::get('/{role}/pinjaman/closing', [InstallmentClosingController::class, 'index'])->whereIn('role', ['admin', 'teller'])->name('loans.closing.index');
    Route::get('/{role}/pinjaman/closing/{closing}', [InstallmentClosingController::class, 'show'])->whereIn('role', ['admin', 'teller'])->whereNumber('closing')->name('loans.closing.show');
    Route::post('/{role}/pinjaman/closing', [InstallmentClosingController::class, 'close'])->where('role', 'admin')->name('loans.closing.close');
    Route::post('/{role}/pinjaman/closing/buka', [InstallmentClosingController::class, 'reopen'])->where('role', 'admin')->name('loans.closing.reopen');
    Route::get('/{role}/pinjaman', [LoanController::class, 'index'])->whereIn('role', ['admin', 'teller', 'anggota'])->name('loans.index');
    Route::get('/{role}/pinjaman/keranjang', [LoanCartController::class, 'cart'])->whereIn('role', ['admin', 'teller'])->name('loans.cart');
    Route::post('/{role}/pinjaman/keranjang/simpan', [LoanCartController::class, 'updateCart'])->whereIn('role', ['admin', 'teller'])->name('loans.cart.update');
    Route::post('/{role}/pinjaman/keranjang/checkout', [LoanCartController::class, 'checkoutCart'])->whereIn('role', ['admin', 'teller'])->name('loans.cart.checkout');
    Route::delete('/{role}/pinjaman/keranjang', [LoanCartController::class, 'clearCart'])->whereIn('role', ['admin', 'teller'])->name('loans.cart.clear');
    Route::delete('/{role}/pinjaman/keranjang/{installment}', [LoanCartController::class, 'removeFromCart'])->whereIn('role', ['admin', 'teller'])->whereNumber('installment')->name('loans.cart.remove');
    Route::get('/{role}/pinjaman/{loan}', [LoanController::class, 'show'])->whereIn('role', ['admin', 'teller', 'anggota'])->name('loans.show');
    Route::get('/{role}/pinjaman/{loan}/perjanjian', [LoanController::class, 'agreement'])->whereIn('role', ['admin', 'teller', 'anggota'])->name('loans.agreement');
    Route::post('/{role}/pinjaman/{loan}/foto-pencairan', [LoanController::class, 'completeDisbursementPhotos'])->whereIn('role', ['admin', 'teller'])->name('loans.disbursement.photos');
    Route::post('/{role}/pinjaman/{loan}/angsuran/{installment}/bayar', [LoanInstallmentController::class, 'pay'])->whereIn('role', ['admin', 'teller'])->name('loans.installments.pay');
    Route::post('/{role}/pinjaman/{loan}/angsuran/{installment}/keranjang', [LoanCartController::class, 'addToCart'])->whereIn('role', ['admin', 'teller'])->name('loans.installments.cart.add');
    Route::post('/{role}/pinjaman/{loan}/keranjang/bulk', [LoanCartController::class, 'bulkAddToCart'])->whereIn('role', ['admin', 'teller'])->name('loans.installments.cart.bulk');
    Route::post('/{role}/pinjaman/{loan}/angsuran/{installment}/batalkan', [LoanInstallmentController::class, 'reverseInstallment'])->where('role', 'admin')->name('loans.installments.reverse');
    Route::post('/{role}/pinjaman/{loan}/angsuran/{installment}/pembatalan/ajukan', [LoanInstallmentController::class, 'requestInstallmentReversal'])->where('role', 'teller')->name('loans.installments.reversal.request');
    Route::post('/{role}/pinjaman/{loan}/pembatalan/{reversalRequest}/review', [LoanInstallmentController::class, 'reviewInstallmentReversalRequest'])->where('role', 'admin')->name('loans.installments.reversal.review');
    Route::post('/{role}/pinjaman/{loan}/angsuran/{installment}/denda', [LoanInstallmentController::class, 'payPenalty'])->whereIn('role', ['admin', 'teller'])->name('loans.installments.penalty.pay');
    Route::post('/{role}/pinjaman/{loan}/lunaskan', [LoanSettlementController::class, 'settle'])->whereIn('role', ['admin', 'teller'])->name('loans.settle');
    Route::post('/{role}/pinjaman/{loan}/pelunasan/batalkan', [LoanSettlementController::class, 'reverseSettlement'])->where('role', 'admin')->name('loans.settlement.reverse');
    Route::post('/{role}/pinjaman/{loan}/pelunasan/ajukan', [LoanSettlementController::class, 'requestSettlement'])->whereIn('role', ['admin', 'teller'])->name('loans.settlement.request');
    Route::post('/{role}/pinjaman/{loan}/pelunasan/review', [LoanSettlementController::class, 'reviewSettlement'])->where('role', 'admin')->name('loans.settlement.review');
    Route::post('/{role}/pinjaman/{loan}/jaminan/ajukan', [LoanCollateralController::class, 'requestCollateralReturn'])->whereIn('role', ['admin', 'teller'])->name('loans.collateral.request');
    Route::post('/{role}/pinjaman/{loan}/jaminan/review', [LoanCollateralController::class, 'reviewCollateralReturn'])->where('role', 'admin')->name('loans.collateral.review');
    Route::post('/{role}/pinjaman/{loan}/jaminan/kembalikan', [LoanCollateralController::class, 'returnCollateral'])->whereIn('role', ['admin', 'teller'])->name('loans.collateral.return');
    Route::get('/{role}/pinjaman/{loan}/angsuran/{installment}/bukti', [LoanController::class, 'receipt'])->whereIn('role', ['admin', 'teller', 'anggota'])->name('loans.receipt');
    Route::get('/{role}/pinjaman/{loan}/pelunasan/bukti', [LoanController::class, 'settlementReceipt'])->whereIn('role', ['admin', 'teller', 'anggota'])->name('loans.settlement.receipt');

    Route::get('/{role}/kas', [CashLedgerController::class, 'index'])->where('role', 'admin')->name('cash.index');
    Route::get('/{role}/rekonsiliasi-kas', [CashReconciliationController::class, 'index'])->where('role', 'admin')->name('cash.reconciliation.index');
    Route::post('/{role}/rekonsiliasi-kas/sinkronkan', [CashReconciliationController::class, 'rebuild'])->where('role', 'admin')->name('cash.reconciliation.rebuild');
    Route::get('/{role}/laporan', [ReportController::class, 'index'])->whereIn('role', ['admin', 'teller'])->name('reports.index');
    Route::get('/{role}/laporan/ekspor', [ReportController::class, 'export'])->whereIn('role', ['admin', 'teller'])->name('reports.export');
    Route::get('/{role}/laporan/ekspor-excel', [ReportController::class, 'exportExcel'])->whereIn('role', ['admin', 'teller'])->name('reports.export-excel');
    Route::get('/{role}/laporan/validasi-data', FinancialParityController::class)->where('role', 'admin')->name('reports.parity');

    Route::get('/{role}/shu', [ShuController::class, 'index'])->where('role', 'admin')->name('shu.index');
    Route::get('/{role}/shu/saya', [ShuController::class, 'mine'])->whereIn('role', ['admin', 'anggota'])->name('shu.mine');
    Route::post('/{role}/shu/proses', [ShuController::class, 'process'])->where('role', 'admin')->name('shu.process');
    Route::post('/{role}/shu/sinkron-pinjaman', [ShuController::class, 'syncLoan'])->where('role', 'admin')->name('shu.sync-loan');
    Route::post('/{role}/shu/bayar-pinjaman', [ShuController::class, 'payLoan'])->where('role', 'admin')->name('shu.pay-loan');
    Route::get('/{role}/shu/{period}', [ShuController::class, 'show'])->where('role', 'admin')->whereNumber('period')->name('shu.show');

    Route::get('/{role}/dokumen/pinjaman/{loan}/perjanjian.pdf', [DocumentPdfController::class, 'agreement'])->whereIn('role', ['admin', 'teller', 'anggota'])->whereNumber('loan')->name('documents.agreement');
    Route::get('/{role}/dokumen/pengajuan/{application}/draft-perjanjian.pdf', [DocumentPdfController::class, 'draftAgreement'])->whereIn('role', ['admin', 'teller'])->whereNumber('application')->name('documents.draft-agreement');
    Route::get('/{role}/dokumen/pinjaman/{loan}/slip-pencairan.pdf', [DocumentPdfController::class, 'disbursementSlip'])->whereIn('role', ['admin', 'teller', 'anggota'])->whereNumber('loan')->name('documents.disbursement');
    Route::get('/{role}/dokumen/pinjaman/{loan}/buku-angsuran.pdf', [DocumentPdfController::class, 'installmentBook'])->whereIn('role', ['admin', 'teller', 'anggota'])->whereNumber('loan')->name('documents.installment-book');
    Route::get('/{role}/dokumen/pinjaman/{loan}/angsuran/{installment}/bukti.pdf', [DocumentPdfController::class, 'paymentReceipt'])->whereIn('role', ['admin', 'teller', 'anggota'])->whereNumber('loan')->whereNumber('installment')->name('documents.payment-receipt');
    Route::get('/{role}/dokumen/pinjaman/{loan}/pelunasan.pdf', [DocumentPdfController::class, 'settlementReceipt'])->whereIn('role', ['admin', 'teller', 'anggota'])->whereNumber('loan')->name('documents.settlement-receipt');
    Route::get('/{role}/dokumen/pinjaman/{loan}/bast.pdf', [DocumentPdfController::class, 'bast'])->whereIn('role', ['admin', 'teller', 'anggota'])->whereNumber('loan')->name('documents.bast');
    Route::get('/{role}/dokumen/layanan/{jenis}/{id}.pdf', [DocumentPdfController::class, 'layananReceipt'])->whereIn('role', ['admin', 'teller'])->whereIn('jenis', ['denda', 'bayar', 'lelang', 'bonus'])->whereNumber('id')->name('documents.layanan-receipt');
    Route::get('/{role}/dokumen/shu/{entitlement}/kwitansi.pdf', [DocumentPdfController::class, 'shuReceipt'])->whereIn('role', ['admin', 'anggota'])->whereNumber('entitlement')->name('documents.shu-receipt');
    Route::get('/{role}/dokumen/shu/periode/{period}/laporan.pdf', [DocumentPdfController::class, 'shuPeriodReport'])->where('role', 'admin')->whereNumber('period')->name('documents.shu-period');
    Route::get('/{role}/dokumen/laporan.pdf', [DocumentPdfController::class, 'report'])->whereIn('role', ['admin', 'teller'])->name('documents.report');

    Route::get('/{role}/migrasi', [MigrationController::class, 'index'])->where('role', 'admin')->name('migration.index');
    Route::get('/{role}/migrasi/template', [MigrationController::class, 'template'])->where('role', 'admin')->name('migration.template');
    Route::get('/{role}/migrasi/ekspor', [MigrationController::class, 'export'])->where('role', 'admin')->name('migration.export');
    Route::post('/{role}/migrasi/impor', [MigrationController::class, 'import'])->where('role', 'admin')->name('migration.import');

    Route::get('/{role}/backup', [BackupController::class, 'index'])->where('role', 'admin')->name('backup.index');
    Route::post('/{role}/backup', [BackupController::class, 'store'])->where('role', 'admin')->name('backup.store');
    Route::get('/{role}/backup/{token}/unduh', [BackupController::class, 'download'])->where('role', 'admin')->name('backup.download');
    Route::get('/{role}/koreksi-simpanan', [SavingsCorrectionController::class, 'index'])->where('role', 'admin')->name('savings.corrections.index');
    Route::post('/{role}/koreksi-simpanan/{transaction}', [SavingsCorrectionController::class, 'store'])->where('role', 'admin')->name('savings.corrections.store');

    Route::get('/{role}/master', [MasterDataController::class, 'index'])->where('role', 'admin')->name('master.index');
    Route::get('/{role}/master/simpanan/tambah', [MasterDataController::class, 'createSaving'])->where('role', 'admin')->name('master.savings.create');
    Route::post('/{role}/master/simpanan', [MasterDataController::class, 'storeSaving'])->where('role', 'admin')->name('master.savings.store');
    Route::get('/{role}/master/simpanan/{saving}/edit', [MasterDataController::class, 'editSaving'])->where('role', 'admin')->name('master.savings.edit');
    Route::put('/{role}/master/simpanan/{saving}', [MasterDataController::class, 'updateSaving'])->where('role', 'admin')->name('master.savings.update');
    Route::delete('/{role}/master/simpanan/{saving}', [MasterDataController::class, 'destroySaving'])->where('role', 'admin')->name('master.savings.destroy');
    Route::get('/{role}/master/pinjaman/tambah', [MasterDataController::class, 'createLoan'])->where('role', 'admin')->name('master.loans.create');
    Route::post('/{role}/master/pinjaman', [MasterDataController::class, 'storeLoan'])->where('role', 'admin')->name('master.loans.store');
    Route::get('/{role}/master/pinjaman/{loan}/edit', [MasterDataController::class, 'editLoan'])->where('role', 'admin')->name('master.loans.edit');
    Route::put('/{role}/master/pinjaman/{loan}', [MasterDataController::class, 'updateLoan'])->where('role', 'admin')->name('master.loans.update');
    Route::delete('/{role}/master/pinjaman/{loan}', [MasterDataController::class, 'destroyLoan'])->where('role', 'admin')->name('master.loans.destroy');
    Route::get('/{role}/master/tarif-pinjaman/tambah', [MasterDataController::class, 'createLoanRate'])->where('role', 'admin')->name('master.loan-rates.create');
    Route::post('/{role}/master/tarif-pinjaman', [MasterDataController::class, 'storeLoanRate'])->where('role', 'admin')->name('master.loan-rates.store');
    Route::get('/{role}/master/tarif-pinjaman/{rate}/edit', [MasterDataController::class, 'editLoanRate'])->where('role', 'admin')->name('master.loan-rates.edit');
    Route::put('/{role}/master/tarif-pinjaman/{rate}', [MasterDataController::class, 'updateLoanRate'])->where('role', 'admin')->name('master.loan-rates.update');
    Route::delete('/{role}/master/tarif-pinjaman/{rate}', [MasterDataController::class, 'destroyLoanRate'])->where('role', 'admin')->name('master.loan-rates.destroy');
    Route::get('/{role}/master/tarif-denda/tambah', [MasterDataController::class, 'createPenaltyRate'])->where('role', 'admin')->name('master.penalty-rates.create');
    Route::post('/{role}/master/tarif-denda', [MasterDataController::class, 'storePenaltyRate'])->where('role', 'admin')->name('master.penalty-rates.store');
    Route::get('/{role}/master/tarif-denda/{rate}/edit', [MasterDataController::class, 'editPenaltyRate'])->where('role', 'admin')->name('master.penalty-rates.edit');
    Route::put('/{role}/master/tarif-denda/{rate}', [MasterDataController::class, 'updatePenaltyRate'])->where('role', 'admin')->name('master.penalty-rates.update');
    Route::delete('/{role}/master/tarif-denda/{rate}', [MasterDataController::class, 'destroyPenaltyRate'])->where('role', 'admin')->name('master.penalty-rates.destroy');

    Route::get('/{role}/pengaturan-koperasi', [CooperativeSettingsController::class, 'edit'])->where('role', 'admin')->name('settings.cooperative.edit');
    Route::get('/{role}/pengaturan-koperasi/logo', [CooperativeSettingsController::class, 'logo'])->whereIn('role', ['admin', 'teller', 'anggota'])->name('settings.cooperative.logo');
    Route::put('/{role}/pengaturan-koperasi', [CooperativeSettingsController::class, 'update'])->where('role', 'admin')->name('settings.cooperative.update');
    Route::get('/{role}/pengaturan-perjanjian', [CooperativeSettingsController::class, 'agreement'])->where('role', 'admin')->name('settings.agreement.edit');
    Route::get('/{role}/pengaturan-perjanjian/tanda-tangan', [CooperativeSettingsController::class, 'signature'])->where('role', 'admin')->name('settings.agreement.signature');
    Route::put('/{role}/pengaturan-perjanjian', [CooperativeSettingsController::class, 'updateAgreement'])->where('role', 'admin')->name('settings.agreement.update');
    Route::get('/{role}/audit-aktivitas', AuditLogController::class)->where('role', 'admin')->name('audit.index');
    Route::get('/{role}/pengingat-whatsapp', [WhatsAppReminderController::class, 'index'])->where('role', 'admin')->name('whatsapp.index');

    Route::get('/{role}/modul/{module}', ModulePlaceholderController::class)
        ->whereIn('role', ['admin', 'teller', 'anggota'])
        ->name('modules.placeholder');
});
